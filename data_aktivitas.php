<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['username'];

/*
|--------------------------------------------------------------------------
| KONEKSI DATABASE
|--------------------------------------------------------------------------
| Mengambil koneksi dari db.php.
| Pastikan db.php membuat variable mysqli bernama $conn.
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Koneksi database tidak ditemukan. Pastikan db.php memiliki variable \$conn.");
}

$conn->set_charset('utf8');

$today = date('Y-m-d');
$pageTitle = 'DAFTAR AKTIVITAS';

/* ================= AJAX DELETE DATA VISIT ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'delete_activity') {
    header('Content-Type: text/plain; charset=utf-8');

    $currentUser = strtolower(trim((string)($_SESSION['username'] ?? '')));
    $allowedDeleteUsers = ['wira', 'admin'];

    if (!in_array($currentUser, $allowedDeleteUsers, true)) {
        echo 'Anda tidak diizinkan menghapus data ini';
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        echo 'ID data visit tidak valid';
        exit;
    }

    $stmt = $conn->prepare('DELETE FROM visit_activities WHERE id = ? LIMIT 1');
    if (!$stmt) {
        echo 'Prepare delete visit gagal: ' . $conn->error;
        exit;
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();

    $affected = $stmt->affected_rows;
    $err = $stmt->error;
    $stmt->close();

    echo ($affected > 0) ? 'OK' : ($err !== '' ? $err : 'Data visit tidak ditemukan');
    exit;
}

/* ================= HELPER ================= */
function tableExists($conn, $tableName) {
    $tableName = preg_replace('/[^A-Za-z0-9_]+/', '', (string)$tableName);
    if ($tableName === '') return false;

    $res = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($tableName) . "'");
    return $res && $res->num_rows > 0;
}

function columnExists($conn, $tableName, $columnName) {
    $tableName = preg_replace('/[^A-Za-z0-9_]+/', '', (string)$tableName);
    $columnName = preg_replace('/[^A-Za-z0-9_]+/', '', (string)$columnName);

    if ($tableName === '' || $columnName === '') return false;

    $stmt = $conn->prepare("SHOW COLUMNS FROM `{$tableName}` LIKE ?");
    if (!$stmt) return false;

    $stmt->bind_param('s', $columnName);
    $stmt->execute();

    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;

    $stmt->close();
    return $ok;
}

function buildFirstExistingColumnSql($conn, $tableName, $alias, array $columns, $default = "''") {
    $parts = [];

    foreach ($columns as $column) {
        if (columnExists($conn, $tableName, $column)) {
            $safeColumn = preg_replace('/[^A-Za-z0-9_]+/', '', (string)$column);
            $parts[] = "NULLIF({$alias}.`{$safeColumn}`, '')";
        }
    }

    if (empty($parts)) return $default;

    $parts[] = $default;
    return 'COALESCE(' . implode(', ', $parts) . ')';
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function parseOmsetValue($value) {
    if ($value === null) return 0;

    $value = trim((string)$value);
    if ($value === '') return 0;

    $value = str_replace(['Rp', 'rp', 'RP', ' '], '', $value);

    if (preg_match('/^-?\d+(\.\d+)?$/', $value)) {
        return (float)$value;
    }

    if (strpos($value, ',') !== false) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
        return is_numeric($value) ? (float)$value : 0;
    }

    if (strpos($value, '.') !== false) {
        $value = str_replace('.', '', $value);
        return is_numeric($value) ? (float)$value : 0;
    }

    $clean = preg_replace('/[^0-9]/', '', $value);
    return $clean === '' ? 0 : (float)$clean;
}

function formatRupiah($value) {
    $angka = parseOmsetValue($value);
    if ($angka <= 0 && trim((string)$value) === '') return '';

    return "Rp " . number_format($angka, 0, ',', '.');
}

function isImageLink($link) {
    $link = trim((string)$link);
    if ($link === '') return false;

    return (bool)(preg_match('/\.(jpg|jpeg|png|webp|gif)(\?.*)?$/i', $link) || strpos($link, 'uploads/') === 0);
}

function cleanTextValue($value, $fallback = '-') {
    $value = trim((string)$value);
    return $value === '' ? $fallback : $value;
}

function findVisitUangModalPhoto($visitUsername, $createdAt = '') {
    $visitUsername = strtolower(trim((string)$visitUsername));
    $visitUsername = preg_replace('/[^a-z0-9]+/', '-', $visitUsername);
    $visitUsername = trim($visitUsername, '-');

    if ($visitUsername === '') return '';

    $dir = __DIR__ . '/uploads/visit_activities';
    $url = 'uploads/visit_activities';

    if (!is_dir($dir)) return '';

    $patterns = [
        $dir . '/' . $visitUsername . '-uang-modal-*',
        $dir . '/' . $visitUsername . '-foto-uang-modal-*',
        $dir . '/' . $visitUsername . '-modal-*',
    ];

    $files = [];

    foreach ($patterns as $pattern) {
        foreach (glob($pattern) ?: [] as $file) {
            if (is_file($file) && preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $file)) {
                $files[$file] = filemtime($file) ?: 0;
            }
        }
    }

    if (empty($files)) return '';

    $target = strtotime((string)$createdAt) ?: 0;

    if ($target > 0) {
        uasort($files, function($a, $b) use ($target) {
            return abs($a - $target) <=> abs($b - $target);
        });
    } else {
        arsort($files);
    }

    $best = array_key_first($files);
    return $best ? ($url . '/' . basename($best)) : '';
}

function detailPhotoBox($label, $link) {
    $link = trim((string)$link);
    $labelSafe = e($label);

    if ($link === '') {
        return "<div class='photo-box'><div class='photo-empty'><i class='fa fa-image'></i></div><div class='photo-caption'>{$labelSafe}<br><span class='text-muted'>Tidak ada foto</span></div></div>";
    }

    $safe = e($link);

    if (isImageLink($link)) {
        return "<div class='photo-box'><a href='{$safe}' class='detail-photo-link'><img src='{$safe}' alt='{$labelSafe}'></a><div class='photo-caption'>{$labelSafe}</div></div>";
    }

    return "<div class='photo-box'><div class='photo-empty'><i class='fa fa-link'></i></div><div class='photo-caption'>{$labelSafe}<br><a href='{$safe}' target='_blank'>Buka Foto</a></div></div>";
}

/* ================= GET LOKASI OUTLET DARI titik_koordinat =================
   Struktur tabel:
   - koordinat
   - nama_outlet
   - lokasi
*/
$lokasiOutletList = [];

if (tableExists($conn, 'titik_koordinat')) {
    $sqlLokasiOutlet = "
        SELECT
            koordinat,
            nama_outlet,
            lokasi
        FROM titik_koordinat
        WHERE nama_outlet IS NOT NULL
          AND TRIM(nama_outlet) <> ''
        ORDER BY nama_outlet ASC
    ";

    $resultLokasiOutlet = $conn->query($sqlLokasiOutlet);

    if ($resultLokasiOutlet) {
        while ($lok = $resultLokasiOutlet->fetch_assoc()) {
            $namaOutlet = trim((string)($lok['nama_outlet'] ?? ''));
            $lokasiText = trim((string)($lok['lokasi'] ?? ''));
            $koordinat  = trim((string)($lok['koordinat'] ?? ''));

            if ($namaOutlet !== '') {
                $lokasiOutletList[] = [
                    'nama' => $namaOutlet,
                    'lokasi' => $lokasiText,
                    'koordinat' => $koordinat
                ];
            }
        }
    }
}

$totalLokasiOutlet = count($lokasiOutletList);

/* ================= GET DATA VISIT HARI INI SAJA ================= */
$dataRows = [];

if (tableExists($conn, 'visit_activities')) {
    if (!columnExists($conn, 'visit_activities', 'foto_uang_modal')) {
        @$conn->query("ALTER TABLE visit_activities ADD foto_uang_modal VARCHAR(255) DEFAULT NULL AFTER foto_depan_store");
    }

    $visitFotoUangModalSelect = buildFirstExistingColumnSql($conn, 'visit_activities', 'v', [
        'foto_uang_modal',
        'foto_uang_receh_modal',
        'foto_uang_receh',
        'foto_modal',
        'foto_modal_outlet',
        'foto_uang_modal_path',
        'uang_modal',
        'modal'
    ], "''");

    $sqlVisit = "
    SELECT
        v.id AS data_id,
        v.username AS visit_username,
        v.created_at AS visit_created_at,
        v.tanggal AS TANGGAL,
        v.jam AS JAM,
        v.hari AS HARI,
        v.nama_user AS NAMA,
        v.area_terminal AS `AREA TERMINAL`,
        v.store AS OUTLET,
        v.nama_crew AS crew_yg_berjaga,
        v.omset_kemarin AS `INPUT OMSET KEMARIN`,
        v.ada_temuan AS TEMUAN,
        CONCAT_WS('<br>',
            NULLIF(v.foto_temuan_1,''),
            NULLIF(v.foto_temuan_2,''),
            NULLIF(v.foto_temuan_3,''),
            NULLIF(v.foto_temuan_4,'')
        ) AS `POINT TEMUAN`,
        CONCAT(v.latitude, ',', v.longitude) AS LOKASI,
        {$visitFotoUangModalSelect} AS foto_uang_modal,
        v.foto_pemakaian_barang AS foto_pemakaian_barang,
        v.foto_hasil_input_barang AS foto_pembelian,
        v.foto_kwh_meter AS foto_kwh_meter,
        v.foto_exp_apar AS foto_apar,
        v.foto_depan_store AS foto_depan_outlet,
        v.foto_display_produk_1 AS foto_display_produk_1,
        v.foto_display_produk_2 AS foto_display_produk_2,
        v.foto_display_produk_3 AS foto_display_produk_3,
        v.foto_display_produk_4 AS foto_display_produk_4,
        v.foto_display_produk_5 AS foto_display_produk_5,
        t.koordinat AS outlet_koordinat_master,
        t.lokasi AS outlet_lokasi_master,
        'VISIT' AS sumber_data
    FROM visit_activities v
    LEFT JOIN titik_koordinat t
        ON TRIM(LOWER(v.store)) = TRIM(LOWER(t.nama_outlet))
    WHERE DATE(v.created_at) = CURDATE()
       OR LEFT(TRIM(v.tanggal), 10) = CURDATE()
    ORDER BY v.created_at DESC, v.tanggal DESC, v.jam DESC
    ";

    $resultVisit = $conn->query($sqlVisit);

    if (!$resultVisit) {
        die("Query visit_activities error: " . $conn->error);
    }

    while ($row = $resultVisit->fetch_assoc()) {
        if (trim((string)($row['foto_uang_modal'] ?? '')) === '') {
            $fallbackModal = findVisitUangModalPhoto($row['visit_username'] ?? '', $row['visit_created_at'] ?? '');
            if ($fallbackModal !== '') $row['foto_uang_modal'] = $fallbackModal;
        }

        $dataRows[] = $row;
    }
}

$totalData = count($dataRows);
$totalTemuan = 0;

foreach ($dataRows as $r) {
    $temuan = strtolower(trim((string)($r['TEMUAN'] ?? '')));

    if ($temuan === 'ada' || (strpos($temuan, 'ada') !== false && strpos($temuan, 'tidak') === false)) {
        $totalTemuan++;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
<title><?= e($pageTitle); ?></title>

<link rel="icon" type="image/png" href="img/srt2.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

<style>
:root {
    --bg: #f4f7fb;
    --surface: #ffffff;
    --surface-soft: #f8fafc;
    --text: #0f172a;
    --muted: #64748b;
    --border: #e2e8f0;
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --sky: #0ea5e9;
    --success: #16a34a;
    --warning: #f59e0b;
    --danger: #dc2626;
    --shadow-sm: 0 8px 22px rgba(15, 23, 42, .08);
    --shadow-md: 0 18px 45px rgba(15, 23, 42, .14);
}

* { box-sizing: border-box; }

html, body {
    min-height: 100%;
    margin: 0;
    font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    color: var(--text);
    background:
        radial-gradient(circle at top left, rgba(37, 99, 235, .16), transparent 34%),
        radial-gradient(circle at top right, rgba(14, 165, 233, .14), transparent 30%),
        linear-gradient(180deg, #f8fbff 0%, var(--bg) 48%, #eef4ff 100%);
}

body { overflow-x: hidden; }

.page-shell {
    width: 100%;
    min-height: 100vh;
    padding: 18px 20px 22px;
}

.app-header {
    width: 100%;
    background:
        radial-gradient(circle at 16% 20%, rgba(255,255,255,.22), transparent 18%),
        radial-gradient(circle at 78% 28%, rgba(255,255,255,.16), transparent 20%),
        repeating-linear-gradient(
            135deg,
            rgba(255,255,255,.055) 0px,
            rgba(255,255,255,.055) 2px,
            transparent 2px,
            transparent 11px
        ),
        linear-gradient(135deg, #2563eb 0%, #0ea5e9 100%);
    border: 1px solid rgba(255, 255, 255, .24);
    border-radius: 22px;
    box-shadow: 0 14px 30px rgba(37, 99, 235, .20);
    padding: 14px 18px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    position: sticky;
    top: 10px;
    z-index: 20;
    overflow: hidden;
}

.app-header::after {
    content: "";
    position: absolute;
    right: -60px;
    bottom: -88px;
    width: 210px;
    height: 210px;
    border-radius: 999px;
    background:
        radial-gradient(circle, rgba(255,255,255,.22), rgba(255,255,255,.05) 62%, transparent 64%);
    pointer-events: none;
}

.app-header::before {
    content: "";
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(rgba(255,255,255,.10) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.10) 1px, transparent 1px);
    background-size: 34px 34px;
    opacity: .10;
    pointer-events: none;
}

.header-left,
.header-actions {
    position: relative;
    z-index: 1;
}

.header-left {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
}

.header-icon {
    width: 46px;
    height: 46px;
    flex: 0 0 46px;
    border-radius: 15px;
    display: grid;
    place-items: center;
    color: #2563eb;
    background: rgba(255,255,255,.94);
    box-shadow: 0 10px 22px rgba(15, 23, 42, .16);
    font-size: 18px;
}

.header-title { min-width: 0; }

.header-title h1 {
    margin: 0;
    font-size: clamp(20px, 2.5vw, 28px);
    line-height: 1.1;
    font-weight: 800;
    letter-spacing: -.02em;
    text-shadow: 0 2px 6px rgba(0,0,0,.15);
}

.header-title p {
    margin: 4px 0 0;
    color: rgba(255,255,255,.90);
    font-size: 12.5px;
    line-height: 1.45;
    font-weight: 500;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 9px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.btn-dashboard,
.btn-soft {
    border: 0;
    border-radius: 13px;
    padding: 9px 13px;
    font-weight: 800;
    font-size: 12.5px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    white-space: nowrap;
    transition: transform .18s ease, box-shadow .18s ease, background .18s ease;
}

.btn-dashboard {
    background: #fff;
    color: #1d4ed8;
    box-shadow: 0 12px 24px rgba(15, 23, 42, .15);
}

.btn-dashboard:hover { color: #1d4ed8; transform: translateY(-1px); }

.btn-soft {
    background: rgba(255,255,255,.18);
    color: #fff;
    border: 1px solid rgba(255,255,255,.24);
}

.btn-soft:hover { background: rgba(255,255,255,.24); color: #fff; }

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
    margin: 16px 0;
}

.stat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 15px;
    box-shadow: var(--shadow-sm);
    min-width: 0;
}

.stat-card-click {
    cursor: pointer;
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}

.stat-card-click:hover,
.stat-card-click:focus {
    transform: translateY(-2px);
    border-color: rgba(37, 99, 235, .35);
    box-shadow: var(--shadow-md);
    outline: none;
}

.stat-label {
    color: var(--muted);
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .08em;
}

.stat-value {
    margin-top: 7px;
    font-size: 25px;
    font-weight: 900;
    letter-spacing: -.04em;
}

.toolbar {
    display: grid;
    grid-template-columns: minmax(260px, 1fr) 190px;
    gap: 12px;
    margin-bottom: 18px;
}

.search-box,
.filter-select {
    width: 100%;
    border: 1px solid var(--border);
    background: #fff;
    color: var(--text);
    border-radius: 16px;
    padding: 12px 14px;
    font-size: 14px;
    outline: none;
    box-shadow: var(--shadow-sm);
}

.search-wrap { position: relative; }

.search-wrap i {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
}

.search-box { padding-left: 42px; }

.search-box:focus,
.filter-select:focus {
    border-color: rgba(37, 99, 235, .55);
    box-shadow: 0 0 0 4px rgba(37, 99, 235, .12), var(--shadow-sm);
}

.card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 18px;
    align-items: stretch;
}

.visit-card {
    border: 1px solid var(--border);
    border-radius: 26px;
    background: var(--surface);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    cursor: pointer;
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    min-width: 0;
    height: 100%;
    display: flex;
    flex-direction: column;
}

.visit-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-md);
    border-color: rgba(37, 99, 235, .32);
}

.cover-wrap {
    position: relative;
    width: 100%;
    aspect-ratio: 16 / 10;
    background: linear-gradient(135deg, #dbeafe, #eff6ff);
    overflow: hidden;
}

.cover-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.cover-placeholder {
    width: 100%;
    height: 100%;
    display: grid;
    place-items: center;
    color: #2563eb;
    font-size: 46px;
}

.cover-gradient {
    position: absolute;
    inset: auto 0 0 0;
    height: 48%;
    background: linear-gradient(180deg, transparent, rgba(15, 23, 42, .78));
}

.cover-date {
    position: absolute;
    left: 14px;
    bottom: 14px;
    right: 14px;
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 10px;
    color: #fff;
}

.date-block strong {
    display: block;
    font-size: 16px;
    font-weight: 900;
    line-height: 1.15;
}

.date-block span {
    display: block;
    margin-top: 3px;
    font-size: 12px;
    font-weight: 700;
    opacity: .92;
}

.badge-source,
.badge-temuan {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: 999px;
    padding: 7px 10px;
    font-size: 11px;
    font-weight: 900;
    white-space: nowrap;
}

.badge-source { background: rgba(255,255,255,.92); color: #1d4ed8; }
.badge-temuan.ada { background: #fee2e2; color: #991b1b; }
.badge-temuan.tidak { background: #dcfce7; color: #166534; }

.card-body-pro {
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    flex: 1;
    min-width: 0;
}

.card-title-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}

.outlet-title {
    min-width: 0;
}

.outlet-title h2 {
    margin: 0;
    font-size: 20px;
    line-height: 1.2;
    font-weight: 900;
    letter-spacing: -.03em;
    overflow-wrap: anywhere;
}

.outlet-title p {
    margin: 5px 0 0;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.4;
    overflow-wrap: anywhere;
}

.omset-pill {
    flex: 0 0 auto;
    border-radius: 16px;
    padding: 9px 10px;
    background: #ecfdf5;
    color: #166534;
    font-size: 12px;
    font-weight: 900;
    max-width: 145px;
    overflow-wrap: anywhere;
    text-align: right;
}

.info-list {
    display: grid;
    gap: 9px;
}

.info-item {
    display: grid;
    grid-template-columns: 28px minmax(0, 1fr);
    gap: 10px;
    align-items: start;
    color: #334155;
    font-size: 13px;
    line-height: 1.45;
    min-width: 0;
}

.info-item i {
    width: 28px;
    height: 28px;
    display: grid;
    place-items: center;
    border-radius: 10px;
    color: #2563eb;
    background: #eff6ff;
}

.info-item span { overflow-wrap: anywhere; }

.card-footer-pro {
    margin-top: auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding-top: 12px;
    border-top: 1px solid #f1f5f9;
}

.detail-hint {
    color: var(--muted);
    font-size: 12px;
    font-weight: 800;
}

.btn-open-detail {
    border: 0;
    border-radius: 14px;
    padding: 10px 12px;
    background: #2563eb;
    color: #fff;
    font-weight: 900;
    font-size: 12px;
    white-space: nowrap;
}

.empty-state {
    display: none;
    text-align: center;
    padding: 54px 20px;
    background: #fff;
    border: 1px dashed #cbd5e1;
    border-radius: 26px;
    color: var(--muted);
}

.empty-state i { font-size: 40px; color: #94a3b8; margin-bottom: 12px; }
.empty-state h3 { margin: 0 0 6px; color: var(--text); font-weight: 900; }

.modal-content {
    border: 0;
    border-radius: 28px;
    overflow: hidden;
    box-shadow: 0 30px 80px rgba(15, 23, 42, .28);
}

.modal-header {
    background: linear-gradient(135deg, #2563eb, #0ea5e9);
    color: #fff;
    border: 0;
    padding: 18px 20px;
}

.modal-title { font-weight: 900; }
.modal-header .btn-close { filter: invert(1); opacity: .9; }
.modal-body { background: #f8fafc; padding: 18px; }
.modal-footer { background: #fff; border-color: #e2e8f0; }

.detail-layout {
    display: grid;
    grid-template-columns: 360px minmax(0, 1fr);
    gap: 18px;
}

.detail-cover {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 22px;
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    align-self: start;
}

.detail-cover img,
.detail-cover-placeholder {
    width: 100%;
    aspect-ratio: 4 / 3;
    object-fit: cover;
    display: grid;
    place-items: center;
    color: #2563eb;
    background: linear-gradient(135deg, #dbeafe, #eff6ff);
    font-size: 50px;
}

.detail-cover-caption {
    padding: 14px;
}

.detail-cover-caption h3 {
    margin: 0;
    font-size: 20px;
    font-weight: 900;
    overflow-wrap: anywhere;
}

.detail-cover-caption p {
    margin: 6px 0 0;
    color: var(--muted);
    font-size: 13px;
    overflow-wrap: anywhere;
}

.detail-panel {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 22px;
    box-shadow: var(--shadow-sm);
    padding: 16px;
    min-width: 0;
}

.detail-section-title {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: 0 0 12px;
    font-size: 15px;
    font-weight: 900;
    color: #1e293b;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.detail-item {
    border: 1px solid #e2e8f0;
    background: #fff;
    border-radius: 16px;
    padding: 12px;
    min-width: 0;
}

.detail-item.full { grid-column: 1 / -1; }

.detail-label {
    display: block;
    color: #64748b;
    font-size: 11px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .08em;
    margin-bottom: 6px;
}

.detail-value {
    color: #0f172a;
    font-size: 14px;
    font-weight: 700;
    line-height: 1.55;
    overflow-wrap: anywhere;
}

.photo-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-top: 14px;
}

.photo-box {
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    overflow: hidden;
    background: #fff;
    min-width: 0;
}

.photo-box img {
    width: 100%;
    aspect-ratio: 1 / 1;
    object-fit: cover;
    display: block;
}

.photo-box .photo-empty {
    width: 100%;
    aspect-ratio: 1 / 1;
    display: grid;
    place-items: center;
    color: #94a3b8;
    background: #f8fafc;
    font-size: 24px;
}

.photo-caption {
    padding: 9px;
    font-size: 11px;
    font-weight: 900;
    color: #334155;
    line-height: 1.35;
    overflow-wrap: anywhere;
}

.photo-box a { color: #1d4ed8; text-decoration: none; }

.action-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    border-radius: 14px;
    padding: 9px 11px;
    font-size: 12px;
    font-weight: 900;
    background: #eff6ff;
    color: #1d4ed8;
}

.btn-danger-soft {
    border: 0;
    border-radius: 14px;
    padding: 10px 14px;
    background: #fee2e2;
    color: #991b1b;
    font-weight: 900;
}

.location-list {
    display: grid;
    gap: 10px;
}

.location-row {
    display: grid;
    grid-template-columns: 38px minmax(0, 1fr);
    gap: 11px;
    align-items: start;
    padding: 12px;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    background: #ffffff;
}

.location-row i {
    width: 38px;
    height: 38px;
    border-radius: 14px;
    display: grid;
    place-items: center;
    color: #2563eb;
    background: #eff6ff;
}

.location-row strong {
    display: block;
    color: #0f172a;
    font-size: 14px;
    font-weight: 900;
    overflow-wrap: anywhere;
}

.location-row span {
    display: block;
    margin-top: 3px;
    color: #64748b;
    font-size: 12px;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.location-map-link {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    margin-top: 8px;
    padding: 7px 10px;
    border-radius: 12px;
    background: #eff6ff;
    color: #1d4ed8;
    text-decoration: none;
    font-size: 11px;
    font-weight: 900;
}

.location-map-link i {
    width: auto;
    height: auto;
    border-radius: 0;
    background: transparent;
    color: inherit;
}

.swal2-popup { border-radius: 24px !important; }

@media (max-width: 1100px) {
    .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .detail-layout { grid-template-columns: 1fr; }
}

@media (max-width: 760px) {
    .page-shell { padding: 12px; }

    .app-header {
        position: static;
        border-radius: 24px;
        align-items: stretch;
        flex-direction: column;
    }

    .header-left { align-items: flex-start; }
    .header-actions, .btn-dashboard, .btn-soft { width: 100%; }

    .stats-grid { grid-template-columns: 1fr; }
    .toolbar { grid-template-columns: 1fr; }
    .card-grid { grid-template-columns: 1fr; gap: 14px; }

    .card-title-row { flex-direction: column; }
    .omset-pill { max-width: none; width: 100%; text-align: left; }

    .photo-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .detail-grid { grid-template-columns: 1fr; }

    .modal-dialog { margin: 10px; }
    .modal-body { padding: 12px; }
}
</style>
</head>

<body>
<div class="page-shell">
    <header class="app-header">
        <div class="header-left">
            <div class="header-icon"><i class="fa fa-clipboard-list"></i></div>
            <div class="header-title">
                <h1>DAFTAR AKTIVITAS VISIT</h1>
                <p>Hasil visit outlet periode <?= e(date('d M Y')); ?></p>
            </div>
        </div>

        <div class="header-actions">
            <button type="button" class="btn-soft" id="resetFilterBtn"><i class="fa fa-rotate-left"></i> Reset Filter</button>
            <a href="log_riwayat_aktivitas.php" class="btn-soft"><i class="fa fa-box-archive"></i> Riwayat</a>
            <a href="dashboard.php" class="btn-dashboard"><i class="fa fa-arrow-left"></i> Dashboard</a>
        </div>
    </header>

    <section class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Visit Hari Ini</div>
            <div class="stat-value"><?= number_format($totalData, 0, ',', '.'); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Ada Temuan</div>
            <div class="stat-value"><?= number_format($totalTemuan, 0, ',', '.'); ?></div>
        </div>
        <div class="stat-card stat-card-click" id="lokasiOutletStat" role="button" tabindex="0">
            <div class="stat-label">Lokasi Outlet</div>
            <div class="stat-value"><?= number_format($totalLokasiOutlet, 0, ',', '.'); ?></div>
        </div>
    </section>

    <section class="toolbar" aria-label="Filter visit outlet">
        <div class="search-wrap">
            <i class="fa fa-search"></i>
            <input type="search" class="search-box" id="searchInput" placeholder="Cari nama, outlet, area, crew, tanggal...">
        </div>

        <select class="filter-select" id="temuanFilter">
            <option value="">Semua Temuan</option>
            <option value="ADA">Ada Temuan</option>
            <option value="TIDAK">Tidak Ada Temuan</option>
        </select>
    </section>

    <main class="card-grid" id="cardGrid">
        <?php foreach($dataRows as $idx => $row):
            $temuanRaw = cleanTextValue($row['TEMUAN'] ?? '', 'Tidak Ada');
            $temuanLower = strtolower($temuanRaw);
            $temuanClass = ($temuanLower === 'ada' || (strpos($temuanLower, 'ada') !== false && strpos($temuanLower, 'tidak') === false)) ? 'ada' : 'tidak';
            $hasTemuan = ($temuanClass === 'ada');
            $temuanDisplay = $hasTemuan ? 'Ada Temuan' : '';

            $cover = trim((string)($row['foto_depan_outlet'] ?? ''));
            $hasCover = isImageLink($cover);

            $outlet = cleanTextValue($row['OUTLET'] ?? '', 'Outlet belum diisi');
            $area = cleanTextValue($row['AREA TERMINAL'] ?? '', 'Area belum diisi');
            $nama = cleanTextValue($row['NAMA'] ?? '', '-');
            $crew = cleanTextValue($row['crew_yg_berjaga'] ?? '', '-');
            $tanggal = cleanTextValue($row['TANGGAL'] ?? '', '-');
            $jam = cleanTextValue($row['JAM'] ?? '', '-');
            $hari = cleanTextValue($row['HARI'] ?? '', '-');
            $omset = formatRupiah($row['INPUT OMSET KEMARIN'] ?? '');
            $omset = $omset === '' ? '-' : $omset;

            $lokasi = cleanTextValue($row['LOKASI'] ?? '', '');
            $mapsUrl = $lokasi !== '' && $lokasi !== ',' ? 'https://www.google.com/maps?q=' . urlencode($lokasi) : '';

            $pointTemuanRaw = trim((string)($row['POINT TEMUAN'] ?? ''));
            $searchText = strtolower(implode(' ', ['VISIT',$temuanRaw,$outlet,$area,$nama,$crew,$tanggal,$jam,$hari,$omset,strip_tags($pointTemuanRaw)]));
        ?>
        <article class="visit-card"
            tabindex="0"
            role="button"
            data-search="<?= e($searchText); ?>"
            data-temuan="<?= $temuanClass === 'ada' ? 'ADA' : 'TIDAK'; ?>"
            data-id="<?= e($row['data_id'] ?? 0); ?>"
            data-tanggal="<?= e($row['TANGGAL'] ?? ''); ?>"
            data-jam="<?= e($row['JAM'] ?? ''); ?>"
            data-nama="<?= e($row['NAMA'] ?? ''); ?>">
            <div class="cover-wrap">
                <?php if ($hasCover): ?>
                    <img class="cover-img" src="<?= e($cover); ?>" alt="Foto depan outlet <?= e($outlet); ?>">
                <?php else: ?>
                    <div class="cover-placeholder"><i class="fa fa-store"></i></div>
                <?php endif; ?>

                <div class="cover-gradient"></div>

                <div class="cover-date">
                    <div class="date-block">
                        <strong><?= e($tanggal); ?></strong>
                        <span><?= e($hari); ?> - <?= e($jam); ?></span>
                    </div>
                    <span class="badge-source"><i class="fa fa-store"></i>VISIT</span>
                </div>
            </div>

            <div class="card-body-pro">
                <div class="card-title-row">
                    <div class="outlet-title">
                        <h2><?= e($outlet); ?></h2>
                        <p><?= e($area); ?></p>
                    </div>
                    <div class="omset-pill"><?= e($omset); ?></div>
                </div>

                <div class="info-list">
                    <div class="info-item"><i class="fa fa-user"></i><span><strong>Petugas:</strong> <?= e($nama); ?></span></div>
                    <div class="info-item"><i class="fa fa-users"></i><span><strong>Crew:</strong> <?= e($crew); ?></span></div>
                    <?php if ($hasTemuan): ?>
                    <div class="info-item"><i class="fa fa-triangle-exclamation"></i><span><strong>Temuan:</strong> <span class="badge-temuan ada"><?= e($temuanDisplay); ?></span></span></div>
                    <?php endif; ?>
                </div>

                <div class="card-footer-pro">
                    <span class="detail-hint"><i class="fa fa-hand-pointer"></i> Klik card untuk detail</span>
                    <button type="button" class="btn-open-detail">Lihat Detail</button>
                </div>
            </div>

            <template class="detail-template">
                <div class="detail-layout">
                    <aside class="detail-cover">
                        <?php if ($hasCover): ?>
                            <a href="<?= e($cover); ?>" class="detail-photo-link"><img src="<?= e($cover); ?>" alt="Foto depan outlet"></a>
                        <?php else: ?>
                            <div class="detail-cover-placeholder"><i class="fa fa-store"></i></div>
                        <?php endif; ?>

                        <div class="detail-cover-caption">
                            <h3><?= e($outlet); ?></h3>
                            <p><?= e($area); ?></p>
                            <div class="mt-3 d-flex flex-wrap gap-2">
                                <span class="badge-source"><i class="fa fa-store"></i>VISIT</span>
                                <?php if ($hasTemuan): ?><span class="badge-temuan ada"><?= e($temuanDisplay); ?></span><?php endif; ?>
                            </div>
                        </div>
                    </aside>

                    <section class="detail-panel">
                        <h4 class="detail-section-title"><i class="fa fa-clipboard-list"></i> Detail Visit Outlet</h4>

                        <div class="detail-grid">
                            <div class="detail-item"><span class="detail-label">Tanggal</span><div class="detail-value"><?= e($tanggal); ?></div></div>
                            <div class="detail-item"><span class="detail-label">Jam</span><div class="detail-value"><?= e($jam); ?></div></div>
                            <div class="detail-item"><span class="detail-label">Hari</span><div class="detail-value"><?= e($hari); ?></div></div>
                            <div class="detail-item"><span class="detail-label">Nama</span><div class="detail-value"><?= e($nama); ?></div></div>
                            <div class="detail-item"><span class="detail-label">Area Terminal</span><div class="detail-value"><?= e($area); ?></div></div>
                            <div class="detail-item"><span class="detail-label">Outlet</span><div class="detail-value"><?= e($outlet); ?></div></div>
                            <div class="detail-item"><span class="detail-label">Lokasi Outlet Master</span><div class="detail-value"><?= e($row['outlet_lokasi_master'] ?? '-'); ?></div></div>
                            <div class="detail-item"><span class="detail-label">Crew Yang Berjaga</span><div class="detail-value"><?= e($crew); ?></div></div>
                            <div class="detail-item"><span class="detail-label">Input Omset Kemarin</span><div class="detail-value"><?= e($omset); ?></div></div>
                            <?php if ($hasTemuan): ?>
                            <div class="detail-item"><span class="detail-label">Temuan</span><div class="detail-value"><?= e($temuanDisplay); ?></div></div>
                            <?php endif; ?>
                            <div class="detail-item"><span class="detail-label">Lokasi</span><div class="detail-value"><?php if ($mapsUrl): ?><a class="action-link" href="<?= e($mapsUrl); ?>" target="_blank"><i class="fa fa-map-location-dot"></i> Buka Maps</a><?php else: ?>-<?php endif; ?></div></div>
                            <?php if ($hasTemuan && $pointTemuanRaw !== ''): ?>
                            <div class="detail-item full"><span class="detail-label">Point Temuan</span><div class="detail-value"><?= $pointTemuanRaw; ?></div></div>
                            <?php endif; ?>
                        </div>

                        <h4 class="detail-section-title mt-4"><i class="fa fa-images"></i> Dokumentasi Foto</h4>

                        <div class="photo-grid">
                            <?= detailPhotoBox('Foto Depan Outlet', $row['foto_depan_outlet'] ?? ''); ?>

                            <?= detailPhotoBox('Display Produk 1', $row['foto_display_produk_1'] ?? ''); ?>
                            <?= detailPhotoBox('Display Produk 2', $row['foto_display_produk_2'] ?? ''); ?>
                            <?= detailPhotoBox('Display Produk 3', $row['foto_display_produk_3'] ?? ''); ?>
                            <?= detailPhotoBox('Display Produk 4', $row['foto_display_produk_4'] ?? ''); ?>
                            <?= detailPhotoBox('Display Produk 5', $row['foto_display_produk_5'] ?? ''); ?>

                            <?= detailPhotoBox('Foto Uang Modal', $row['foto_uang_modal'] ?? ''); ?>
                            <?= detailPhotoBox('Foto Pemakaian Barang', $row['foto_pemakaian_barang'] ?? ''); ?>
                            <?= detailPhotoBox('Foto Pembelian', $row['foto_pembelian'] ?? ''); ?>
                            <?= detailPhotoBox('Foto KWH Meter', $row['foto_kwh_meter'] ?? ''); ?>
                            <?= detailPhotoBox('Foto APAR', $row['foto_apar'] ?? ''); ?>
                        </div>
                    </section>
                </div>
            </template>
        </article>
        <?php endforeach; ?>
    </main>

    <section class="empty-state" id="emptyState">
        <i class="fa fa-magnifying-glass"></i>
        <h3>Data tidak ditemukan</h3>
        <p>Coba ubah kata pencarian atau reset filter.</p>
    </section>

    <?php if (empty($dataRows)): ?>
        <section class="empty-state" style="display:block; margin-top: 18px;">
            <i class="fa fa-folder-open"></i>
            <h3>Belum ada data visit</h3>
            <p>Belum ada hasil visit outlet untuk periode hari ini.</p>
        </section>
    <?php endif; ?>
</div>

<div class="modal fade" id="lokasiOutletModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa fa-location-dot me-2"></i>Lokasi Outlet Bandara Soetta</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <?php if (empty($lokasiOutletList)): ?>
            <section class="empty-state" style="display:block;">
                <i class="fa fa-map-location-dot"></i>
                <h3>Data lokasi belum tersedia</h3>
                <p>Tabel titik_koordinat belum berisi nama outlet yang bisa ditampilkan.</p>
            </section>
        <?php else: ?>
            <div class="location-list">
                <?php foreach ($lokasiOutletList as $lok): ?>
                    <div class="location-row">
                        <i class="fa fa-store"></i>
                        <div>
                            <strong><?= e($lok['nama']); ?></strong>
                            <span><?= e($lok['lokasi'] !== '' ? $lok['lokasi'] : 'Lokasi belum tersedia'); ?></span>
                            <?php if (($lok['koordinat'] ?? '') !== ''): ?>
                                <a class="location-map-link" href="https://www.google.com/maps?q=<?= e(urlencode($lok['koordinat'])); ?>" target="_blank">
                                    <i class="fa fa-map-location-dot"></i> Buka Maps
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary rounded-3 fw-bold" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa fa-store me-2"></i>Detail Hasil Visit</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body" id="detailContent"></div>
      <div class="modal-footer d-flex justify-content-between gap-2">
        <button type="button" class="btn-danger-soft d-none" id="deleteCurrentBtn"><i class="bi bi-trash-fill me-1"></i> Hapus Data</button>
        <button type="button" class="btn btn-secondary rounded-3 fw-bold" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
(function(){
    const username = <?= json_encode(strtolower(trim((string)$username))) ?>;
    const allowedUsers = ['wira', 'admin'];
    const cards = Array.from(document.querySelectorAll('.visit-card'));
    const searchInput = document.getElementById('searchInput');
    const temuanFilter = document.getElementById('temuanFilter');
    const resetFilterBtn = document.getElementById('resetFilterBtn');
    const emptyState = document.getElementById('emptyState');
    const detailContent = document.getElementById('detailContent');
    const deleteBtn = document.getElementById('deleteCurrentBtn');
    const modalEl = document.getElementById('detailModal');
    const detailModal = new bootstrap.Modal(modalEl);
    const lokasiOutletStat = document.getElementById('lokasiOutletStat');
    const lokasiOutletModalEl = document.getElementById('lokasiOutletModal');
    const lokasiOutletModal = lokasiOutletModalEl ? new bootstrap.Modal(lokasiOutletModalEl) : null;
    let currentCard = null;

    if (lokasiOutletStat && lokasiOutletModal) {
        lokasiOutletStat.addEventListener('click', function(){
            lokasiOutletModal.show();
        });

        lokasiOutletStat.addEventListener('keydown', function(e){
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                lokasiOutletModal.show();
            }
        });
    }

    function applyFilters(){
        const q = (searchInput.value || '').trim().toLowerCase();
        const temuan = temuanFilter.value;
        let visible = 0;

        cards.forEach(card => {
            const matchSearch = !q || (card.dataset.search || '').includes(q);
            const matchTemuan = !temuan || card.dataset.temuan === temuan;
            const show = matchSearch && matchTemuan;

            card.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        emptyState.style.display = visible === 0 && cards.length > 0 ? 'block' : 'none';
    }

    function openDetail(card){
        currentCard = card;
        const template = card.querySelector('.detail-template');
        if (!template) return;

        detailContent.innerHTML = template.innerHTML;

        if (allowedUsers.includes(username)) deleteBtn.classList.remove('d-none');
        else deleteBtn.classList.add('d-none');

        detailModal.show();
    }

    cards.forEach(card => {
        card.addEventListener('click', function(e){
            if (e.target.closest('a')) return;
            openDetail(card);
        });

        card.addEventListener('keydown', function(e){
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openDetail(card);
            }
        });
    });

    [searchInput, temuanFilter].forEach(el => el.addEventListener('input', applyFilters));

    resetFilterBtn.addEventListener('click', function(){
        searchInput.value = '';
        temuanFilter.value = '';
        applyFilters();
    });

    $(document).on('click', '.detail-photo-link', function(e){
        e.preventDefault();

        Swal.fire({
            imageUrl: $(this).attr('href'),
            imageAlt: 'Foto Visit',
            showConfirmButton: false,
            showCloseButton: true,
            width: 'min(96vw, 920px)',
            background: '#ffffff'
        });
    });

    deleteBtn.addEventListener('click', function(){
        if (!currentCard) return;

        if (!allowedUsers.includes(username)) {
            Swal.fire('Oops', 'Anda tidak diizinkan menghapus data ini', 'error');
            return;
        }

        const id = currentCard.dataset.id || 0;
        const tanggal = currentCard.dataset.tanggal || '';
        const jam = currentCard.dataset.jam || '';
        const nama = currentCard.dataset.nama || '';

        Swal.fire({
            title: 'Yakin ingin menghapus?',
            text: 'VISIT - ' + nama + ' (' + tanggal + ' ' + jam + ') akan dihapus permanen',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Ya, hapus!',
            cancelButtonText: 'Batal'
        }).then(function(res){
            if (!res.isConfirmed) return;

            $.post("<?= e(basename($_SERVER['PHP_SELF'])) ?>", {
                ajax_action: 'delete_activity',
                id: id
            }, function(r){
                r = (typeof r === 'string') ? r.trim() : r;

                if (r === 'OK') {
                    Swal.fire('Sukses', 'Data berhasil dihapus', 'success').then(function(){ location.reload(); });
                } else {
                    Swal.fire('Error', r, 'error');
                }
            }).fail(function(xhr, status, err){
                Swal.fire('Error', 'Gagal koneksi: ' + err, 'error');
            });
        });
    });

    applyFilters();
})();
</script>
</body>
</html>
