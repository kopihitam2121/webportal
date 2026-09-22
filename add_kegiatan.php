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
| Menggunakan db.php kalau tersedia, fallback ke koneksi lama.
|--------------------------------------------------------------------------
*/
if (file_exists(__DIR__ . '/db.php')) {
    require_once __DIR__ . '/db.php';
}

$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Jakarta');

$pageTitle = 'DAFTAR KEGIATAN';

/* ================= AJAX DELETE BARIS ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_delete'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');

    $currentUser = strtolower(trim((string)($_SESSION['username'] ?? '')));
    $allowedDeleteUsers = ['wira', 'admin'];

    if (!in_array($currentUser, $allowedDeleteUsers, true)) {
        echo json_encode(['ok' => false, 'message' => 'Anda tidak diizinkan menghapus data ini.']);
        exit;
    }

    $source = trim((string)($_POST['source'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);
    $date = trim((string)($_POST['date'] ?? ''));
    $nama = trim((string)($_POST['nama'] ?? ''));
    $kegiatan = trim((string)($_POST['kegiatan'] ?? ''));

    $ok = false;
    $msg = '';

    if ($source === 'VISIT_KEGIATAN') {
        if ($id <= 0) {
            $msg = 'ID data visit_kegiatan tidak valid.';
        } else {
            $stmt = $conn->prepare('DELETE FROM visit_kegiatan WHERE id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $id);
                $ok = $stmt->execute();
                $msg = $ok ? 'OK' : $stmt->error;
                $stmt->close();
            } else {
                $msg = $conn->error;
            }
        }
    } elseif ($source === 'ADD_KEGIATAN') {
        if ($date === '' || $nama === '' || $kegiatan === '') {
            $msg = 'Data lama tidak lengkap untuk dihapus.';
        } else {
            $stmt = $conn->prepare('DELETE FROM add_kegiatan WHERE `Date` = ? AND `Nama` = ? AND `Add Kegiatan` = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('sss', $date, $nama, $kegiatan);
                $ok = $stmt->execute();
                $msg = $ok ? 'OK' : $stmt->error;
                $stmt->close();
            } else {
                $msg = $conn->error;
            }
        }
    } else {
        $msg = 'Sumber data tidak valid.';
    }

    echo json_encode(['ok' => (bool)$ok, 'message' => $msg]);
    exit;
}

$filterNama = isset($_GET['nama']) ? trim((string)$_GET['nama']) : "";

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tableExists($conn, $tableName) {
    $tableName = preg_replace('/[^A-Za-z0-9_]+/', '', (string)$tableName);
    if ($tableName === '') return false;

    $res = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($tableName) . "'");
    return $res && $res->num_rows > 0;
}

function normalizePhotoLink($link) {
    $link = trim((string)$link);
    if ($link === '') return '';
    return str_replace(' ', '%20', $link);
}

function isImageLink($link) {
    $link = normalizePhotoLink($link);
    if ($link === '') return false;

    return (bool)(
        preg_match('/\.(jpg|jpeg|png|webp|gif)(\?.*)?$/i', $link)
        || strpos($link, 'uploads/') === 0
        || strpos($link, './uploads/') === 0
    );
}

function photoBox($label, $link) {
    $link = normalizePhotoLink($link);
    $labelSafe = e($label);

    if ($link === '') {
        return "
            <div class='photo-box photo-empty-box'>
                <div class='photo-empty'><i class='fa fa-image'></i></div>
                <div class='photo-caption'>{$labelSafe}<br><span>Tidak ada foto</span></div>
            </div>
        ";
    }

    $safe = e($link);

    if (isImageLink($link)) {
        return "
            <div class='photo-box'>
                <a href='{$safe}' class='detail-photo-link'>
                    <img src='{$safe}' alt='{$labelSafe}'>
                </a>
                <div class='photo-caption'>{$labelSafe}</div>
            </div>
        ";
    }

    return "
        <div class='photo-box photo-empty-box'>
            <div class='photo-empty'><i class='fa fa-link'></i></div>
            <div class='photo-caption'>{$labelSafe}<br><a href='{$safe}' target='_blank'>Buka Foto</a></div>
        </div>
    ";
}

function firstPhoto($row) {
    foreach (['foto1', 'foto2', 'foto3', 'foto4'] as $key) {
        $link = normalizePhotoLink($row[$key] ?? '');
        if ($link !== '' && isImageLink($link)) {
            return $link;
        }
    }
    return '';
}

function cleanText($value, $fallback = '-') {
    $value = trim((string)$value);
    return $value === '' ? $fallback : $value;
}

function publicSourceLabel($source) {
    $source = strtoupper(trim((string)$source));
    if ($source === 'VISIT_KEGIATAN') return 'Visit Outlet';
    if ($source === 'ADD_KEGIATAN') return 'Kegiatan Harian';
    return 'Kegiatan';
}

function publicKeterangan($source, $keterangan = '') {
    $source = strtoupper(trim((string)$source));
    $keterangan = trim((string)$keterangan);

    if ($source === 'VISIT_KEGIATAN') return 'Laporan kegiatan outlet';
    if ($source === 'ADD_KEGIATAN') return $keterangan !== '' ? $keterangan : 'Laporan kegiatan harian';
    return $keterangan !== '' ? $keterangan : 'Laporan kegiatan';
}

function formatDateHuman($date) {
    $ts = strtotime((string)$date);
    if (!$ts) return cleanText($date);
    return date('d M Y', $ts);
}

function formatTimeHuman($date) {
    $ts = strtotime((string)$date);
    if (!$ts) return '';
    return date('H:i', $ts);
}

/* ================= AMBIL DATA ================= */
$dataRows = [];

if (tableExists($conn, 'add_kegiatan')) {
    $sqlOld = "
    SELECT 0 AS row_id, a.`Date`, a.`Nama`, a.`Add Kegiatan`, a.`Keterangan`,
           MAX(f1.`Link View`) AS foto1,
           MAX(f2.`Link View`) AS foto2,
           MAX(f3.`Link View`) AS foto3,
           MAX(f4.`Link View`) AS foto4,
           'ADD_KEGIATAN' AS sumber_data
    FROM add_kegiatan a
    LEFT JOIN foto_inspeksi f1 ON a.`Image 1` = CONCAT(f1.`Folder Name`,'/',f1.`File Name`)
    LEFT JOIN foto_inspeksi f2 ON a.`Image 2` = CONCAT(f2.`Folder Name`,'/',f2.`File Name`)
    LEFT JOIN foto_inspeksi f3 ON a.`Image 3` = CONCAT(f3.`Folder Name`,'/',f3.`File Name`)
    LEFT JOIN foto_inspeksi f4 ON a.`Image 4` = CONCAT(f4.`Folder Name`,'/',f4.`File Name`)
    WHERE DATE(a.`Date`) = CURDATE()
    ";

    if ($filterNama !== "") {
        $filterNamaEscaped = $conn->real_escape_string($filterNama);
        $sqlOld .= " AND a.Nama = '$filterNamaEscaped' ";
    }

    $sqlOld .= " GROUP BY a.`Date`, a.`Nama`, a.`Add Kegiatan`, a.`Keterangan` ORDER BY a.`Date` DESC";

    $resultOld = $conn->query($sqlOld);
    if ($resultOld) {
        while ($row = $resultOld->fetch_assoc()) {
            $dataRows[] = $row;
        }
    }
}

if (tableExists($conn, 'visit_kegiatan')) {
    $sqlVisit = "
    SELECT
        v.id AS row_id,
        CONCAT(v.tanggal, ' ', v.jam) AS `Date`,
        v.nama_user AS `Nama`,
        v.kegiatan AS `Add Kegiatan`,
        'Laporan kegiatan outlet' AS `Keterangan`,
        v.foto_1 AS foto1,
        v.foto_2 AS foto2,
        v.foto_3 AS foto3,
        v.foto_4 AS foto4,
        'VISIT_KEGIATAN' AS sumber_data
    FROM visit_kegiatan v
    WHERE DATE(v.tanggal) = CURDATE()
    ";

    if ($filterNama !== "") {
        $filterNamaEscaped = $conn->real_escape_string($filterNama);
        $sqlVisit .= " AND v.nama_user = '$filterNamaEscaped' ";
    }

    $sqlVisit .= " ORDER BY v.created_at DESC";

    $resultVisit = $conn->query($sqlVisit);
    if ($resultVisit) {
        while ($row = $resultVisit->fetch_assoc()) {
            $dataRows[] = $row;
        }
    }
}

usort($dataRows, function($a, $b) {
    $ta = strtotime((string)($a['Date'] ?? '')) ?: 0;
    $tb = strtotime((string)($b['Date'] ?? '')) ?: 0;
    return $tb <=> $ta;
});

/* ================= OPTION NAMA ================= */
$namaOptions = [];

if (tableExists($conn, 'add_kegiatan')) {
    $resNamaOld = $conn->query("SELECT DISTINCT Nama FROM add_kegiatan WHERE Nama IS NOT NULL AND Nama <> '' ORDER BY Nama ASC");
    if ($resNamaOld) {
        while ($r = $resNamaOld->fetch_assoc()) {
            $namaOptions[] = $r['Nama'];
        }
    }
}

if (tableExists($conn, 'visit_kegiatan')) {
    $resNamaVisit = $conn->query("SELECT DISTINCT nama_user AS Nama FROM visit_kegiatan WHERE nama_user IS NOT NULL AND nama_user <> '' ORDER BY nama_user ASC");
    if ($resNamaVisit) {
        while ($r = $resNamaVisit->fetch_assoc()) {
            $namaOptions[] = $r['Nama'];
        }
    }
}

$namaOptions = array_values(array_unique(array_filter($namaOptions, fn($v) => trim((string)$v) !== '')));
sort($namaOptions, SORT_NATURAL | SORT_FLAG_CASE);

/* ================= SUMMARY ================= */
$totalData = count($dataRows);
$totalVisitKegiatan = 0;
$totalAddKegiatan = 0;
$totalFoto = 0;

foreach ($dataRows as $row) {
    $src = strtoupper(trim((string)($row['sumber_data'] ?? 'ADD_KEGIATAN')));
    if ($src === 'VISIT_KEGIATAN') $totalVisitKegiatan++;
    else $totalAddKegiatan++;

    foreach (['foto1', 'foto2', 'foto3', 'foto4'] as $fk) {
        if (trim((string)($row[$fk] ?? '')) !== '') $totalFoto++;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle); ?></title>

<link rel="icon" type="image/png" href="img/srt2.png" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
:root {
    --bg: #f4f7fb;
    --surface: #ffffff;
    --text: #0f172a;
    --muted: #64748b;
    --border: #e2e8f0;
    --primary: #2563eb;
    --sky: #0ea5e9;
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

/* ===== HEADER BIRU MOTIF SEPERTI DATA AKTIVITAS ===== */
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
    gap: 12px;
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
    grid-template-columns: repeat(4, minmax(0, 1fr));
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
    overflow-wrap: anywhere;
}

.toolbar {
    display: grid;
    grid-template-columns: minmax(260px, 1fr) 220px;
    gap: 12px;
    margin-bottom: 18px;
}

.search-wrap { position: relative; }

.search-wrap i {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
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

.search-box { padding-left: 42px; }

.search-box:focus,
.filter-select:focus {
    border-color: rgba(37, 99, 235, .55);
    box-shadow: 0 0 0 4px rgba(37, 99, 235, .12), var(--shadow-sm);
}

.card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(315px, 1fr));
    gap: 18px;
    align-items: stretch;
}

.kegiatan-card {
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

.kegiatan-card:hover {
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
    font-size: 44px;
}

.cover-gradient {
    position: absolute;
    inset: auto 0 0 0;
    height: 50%;
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

.badge-source {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: 999px;
    padding: 7px 10px;
    font-size: 11px;
    font-weight: 900;
    white-space: nowrap;
    background: rgba(255,255,255,.92);
    color: #1d4ed8;
}

.badge-source.old {
    color: #7c2d12;
}

.card-body-pro {
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 13px;
    flex: 1;
    min-width: 0;
}

.card-title-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}

.kegiatan-title {
    min-width: 0;
}

.kegiatan-title h2 {
    margin: 0;
    font-size: 19px;
    line-height: 1.25;
    font-weight: 900;
    letter-spacing: -.03em;
    overflow-wrap: anywhere;
}

.kegiatan-title p {
    margin: 5px 0 0;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.4;
    overflow-wrap: anywhere;
}

.photo-count {
    flex: 0 0 auto;
    border-radius: 16px;
    padding: 9px 10px;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 12px;
    font-weight: 900;
    white-space: nowrap;
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

.info-item span {
    overflow-wrap: anywhere;
}

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

.empty-state i {
    font-size: 40px;
    color: #94a3b8;
    margin-bottom: 12px;
}

.empty-state h3 {
    margin: 0 0 6px;
    color: var(--text);
    font-weight: 900;
}

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

.modal-title {
    font-weight: 900;
}

.modal-header .btn-close {
    filter: invert(1);
    opacity: .9;
}

.modal-body {
    background: #f8fafc;
    padding: 18px;
}

.modal-footer {
    background: #fff;
    border-color: #e2e8f0;
}

.detail-layout {
    display: grid;
    grid-template-columns: 350px minmax(0, 1fr);
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
    grid-template-columns: repeat(2, minmax(0, 1fr));
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

.photo-empty {
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

.photo-caption span {
    color: #64748b;
}

.photo-box a {
    color: #1d4ed8;
    text-decoration: none;
}

.btn-danger-soft {
    border: 0;
    border-radius: 14px;
    padding: 10px 14px;
    background: #fee2e2;
    color: #991b1b;
    font-weight: 900;
}

.swal2-popup {
    border-radius: 24px !important;
}

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
    .photo-count { width: 100%; text-align: left; }

    .detail-grid,
    .photo-grid { grid-template-columns: 1fr; }

    .modal-dialog { margin: 10px; }
    .modal-body { padding: 12px; }
}
</style>
</head>

<body>
<div class="page-shell">
    <header class="app-header">
        <div class="header-left">
            <div class="header-icon"><i class="fa fa-list-check"></i></div>
            <div class="header-title">
                <h1>DAFTAR KEGIATAN</h1>
                <p>Data kegiatan hari ini periode <?= e(date('d M Y')); ?></p>
            </div>
        </div>

        <div class="header-actions">
            <button type="button" class="btn-soft" id="resetFilterBtn"><i class="fa fa-rotate-left"></i> Reset Filter</button>
            <a href="riwayat_add_kegiatan.php" class="btn-soft"><i class="fa fa-box-archive"></i> Riwayat</a>
            <a href="dashboard.php" class="btn-dashboard"><i class="fa fa-arrow-left"></i> Dashboard</a>
        </div>
    </header>

    <section class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Kegiatan</div>
            <div class="stat-value"><?= number_format($totalData, 0, ',', '.'); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Visit Kegiatan</div>
            <div class="stat-value"><?= number_format($totalVisitKegiatan, 0, ',', '.'); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Add Kegiatan</div>
            <div class="stat-value"><?= number_format($totalAddKegiatan, 0, ',', '.'); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Foto</div>
            <div class="stat-value"><?= number_format($totalFoto, 0, ',', '.'); ?></div>
        </div>
    </section>

    <section class="toolbar" aria-label="Filter kegiatan">
        <div class="search-wrap">
            <i class="fa fa-search"></i>
            <input type="search" class="search-box" id="searchInput" placeholder="Cari nama, kegiatan, keterangan, tanggal...">
        </div>

        <select class="filter-select" id="filterNama">
            <option value="">Semua Nama</option>
            <?php foreach($namaOptions as $nama): ?>
                <option value="<?= e($nama) ?>" <?= ($nama == $filterNama ? 'selected' : '') ?>><?= e($nama) ?></option>
            <?php endforeach; ?>
        </select>
    </section>

    <main class="card-grid" id="cardGrid">
        <?php foreach($dataRows as $idx => $row):
            $dateRaw = cleanText($row['Date'] ?? '');
            $dateHuman = formatDateHuman($dateRaw);
            $timeHuman = formatTimeHuman($dateRaw);
            $nama = cleanText($row['Nama'] ?? '');
            $kegiatan = cleanText($row['Add Kegiatan'] ?? '');
            $keterangan = cleanText($row['Keterangan'] ?? '');
            $source = strtoupper(trim((string)($row['sumber_data'] ?? 'ADD_KEGIATAN')));
            $sourceLabel = $source === 'VISIT_KEGIATAN' ? 'VISIT' : 'ADD';
            $sourcePublicLabel = publicSourceLabel($source);
            $keteranganPublic = publicKeterangan($source, $keterangan);
            $sourceClass = $source === 'VISIT_KEGIATAN' ? '' : 'old';
            $cover = firstPhoto($row);

            $fotoCount = 0;
            foreach (['foto1', 'foto2', 'foto3', 'foto4'] as $fk) {
                if (trim((string)($row[$fk] ?? '')) !== '') $fotoCount++;
            }

            $searchText = strtolower(implode(' ', [
                $dateRaw,
                $dateHuman,
                $timeHuman,
                $nama,
                $kegiatan,
                $keteranganPublic,
                $sourcePublicLabel,
                $sourceLabel
            ]));
        ?>
        <article
            class="kegiatan-card"
            tabindex="0"
            role="button"
            data-search="<?= e($searchText); ?>"
            data-nama="<?= e($nama); ?>"
            data-id="<?= e($row['row_id'] ?? 0); ?>"
            data-source="<?= e($source); ?>"
            data-date="<?= e($dateRaw); ?>"
            data-kegiatan="<?= e($kegiatan); ?>"
        >
            <div class="cover-wrap">
                <?php if ($cover !== ''): ?>
                    <img class="cover-img" src="<?= e($cover); ?>" alt="Foto kegiatan">
                <?php else: ?>
                    <div class="cover-placeholder"><i class="fa fa-list-check"></i></div>
                <?php endif; ?>

                <div class="cover-gradient"></div>

                <div class="cover-date">
                    <div class="date-block">
                        <strong><?= e($dateHuman); ?></strong>
                        <span><?= e($timeHuman !== '' ? $timeHuman : 'Hari ini'); ?></span>
                    </div>
                    <span class="badge-source <?= e($sourceClass); ?>">
                        <i class="fa <?= $source === 'VISIT_KEGIATAN' ? 'fa-store' : 'fa-plus'; ?>"></i>
                        <?= e($sourceLabel); ?>
                    </span>
                </div>
            </div>

            <div class="card-body-pro">
                <div class="card-title-row">
                    <div class="kegiatan-title">
                        <h2><?= nl2br(e($kegiatan)); ?></h2>
                        <p><?= e($nama); ?></p>
                    </div>
                    <div class="photo-count"><i class="fa fa-images"></i> <?= e($fotoCount); ?> Foto</div>
                </div>

                <div class="info-list">
                    <div class="info-item"><i class="fa fa-user"></i><span><strong>Nama:</strong> <?= e($nama); ?></span></div>
                    <div class="info-item"><i class="fa fa-circle-info"></i><span><strong>Keterangan:</strong> <?= e($keteranganPublic); ?></span></div>
                    <div class="info-item"><i class="fa fa-database"></i><span><strong>Sumber:</strong> <?= e($sourcePublicLabel); ?></span></div>
                </div>

                <div class="card-footer-pro">
                    <span class="detail-hint"><i class="fa fa-hand-pointer"></i> Klik card untuk detail</span>
                    <button type="button" class="btn-open-detail">Detail</button>
                </div>
            </div>

            <template class="detail-template">
                <div class="detail-layout">
                    <aside class="detail-cover">
                        <?php if ($cover !== ''): ?>
                            <a href="<?= e($cover); ?>" class="detail-photo-link"><img src="<?= e($cover); ?>" alt="Foto kegiatan"></a>
                        <?php else: ?>
                            <div class="detail-cover-placeholder"><i class="fa fa-list-check"></i></div>
                        <?php endif; ?>

                        <div class="detail-cover-caption">
                            <h3><?= nl2br(e($kegiatan)); ?></h3>
                            <p><?= e($nama); ?></p>
                            <div class="mt-3 d-flex flex-wrap gap-2">
                                <span class="badge-source <?= e($sourceClass); ?>">
                                    <i class="fa <?= $source === 'VISIT_KEGIATAN' ? 'fa-store' : 'fa-plus'; ?>"></i>
                                    <?= e($sourcePublicLabel); ?>
                                </span>
                            </div>
                        </div>
                    </aside>

                    <section class="detail-panel">
                        <h4 class="detail-section-title"><i class="fa fa-clipboard-list"></i> Detail Kegiatan</h4>

                        <div class="detail-grid">
                            <div class="detail-item"><span class="detail-label">Tanggal</span><div class="detail-value"><?= e($dateRaw); ?></div></div>
                            <div class="detail-item"><span class="detail-label">Nama</span><div class="detail-value"><?= e($nama); ?></div></div>
                            <div class="detail-item full"><span class="detail-label">Add Kegiatan</span><div class="detail-value"><?= nl2br(e($kegiatan)); ?></div></div>
                            <div class="detail-item full"><span class="detail-label">Keterangan</span><div class="detail-value"><?= e($keteranganPublic); ?></div></div>
                            <div class="detail-item"><span class="detail-label">Sumber Data</span><div class="detail-value"><?= e($sourcePublicLabel); ?></div></div>
                            <div class="detail-item"><span class="detail-label">Jumlah Foto</span><div class="detail-value"><?= e($fotoCount); ?> foto</div></div>
                        </div>

                        <h4 class="detail-section-title mt-4"><i class="fa fa-images"></i> Dokumentasi Foto</h4>

                        <div class="photo-grid">
                            <?= photoBox('Image 1', $row['foto1'] ?? ''); ?>
                            <?= photoBox('Image 2', $row['foto2'] ?? ''); ?>
                            <?= photoBox('Image 3', $row['foto3'] ?? ''); ?>
                            <?= photoBox('Image 4', $row['foto4'] ?? ''); ?>
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
            <h3>Belum ada data kegiatan</h3>
            <p>Belum ada kegiatan untuk periode hari ini.</p>
        </section>
    <?php endif; ?>
</div>

<div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa fa-list-check me-2"></i>Detail Kegiatan</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body" id="detailContent"></div>
      <div class="modal-footer d-flex justify-content-between gap-2">
        <button type="button" class="btn-danger-soft d-none" id="deleteCurrentBtn"><i class="fa fa-trash me-1"></i> Hapus Data</button>
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
    const cards = Array.from(document.querySelectorAll('.kegiatan-card'));
    const searchInput = document.getElementById('searchInput');
    const filterNama = document.getElementById('filterNama');
    const resetFilterBtn = document.getElementById('resetFilterBtn');
    const emptyState = document.getElementById('emptyState');
    const detailContent = document.getElementById('detailContent');
    const deleteBtn = document.getElementById('deleteCurrentBtn');
    const detailModal = new bootstrap.Modal(document.getElementById('detailModal'));
    let currentCard = null;

    function applyFilters(){
        const q = (searchInput.value || '').trim().toLowerCase();
        const nama = filterNama.value || '';
        let visible = 0;

        cards.forEach(card => {
            const matchSearch = !q || (card.dataset.search || '').includes(q);
            const matchNama = !nama || card.dataset.nama === nama;
            const show = matchSearch && matchNama;

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

    [searchInput, filterNama].forEach(el => el.addEventListener('input', applyFilters));
    filterNama.addEventListener('change', applyFilters);

    resetFilterBtn.addEventListener('click', function(){
        searchInput.value = '';
        filterNama.value = '';
        applyFilters();
    });

    $(document).on('click', '.detail-photo-link', function(e){
        e.preventDefault();

        Swal.fire({
            imageUrl: $(this).attr('href'),
            imageAlt: 'Foto Kegiatan',
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
        const source = currentCard.dataset.source || '';
        const date = currentCard.dataset.date || '';
        const nama = currentCard.dataset.nama || '';
        const kegiatan = currentCard.dataset.kegiatan || '';

        Swal.fire({
            icon: 'warning',
            title: 'Hapus data ini?',
            html: 'Data kegiatan <b>' + nama + '</b><br>tanggal <b>' + date + '</b> akan dihapus permanen.',
            showCancelButton: true,
            confirmButtonText: 'Delete Permanen',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#dc2626'
        }).then(function(result){
            if (!result.isConfirmed) return;

            $.post(window.location.href, {
                ajax_delete: '1',
                id: id,
                source: source,
                date: date,
                nama: nama,
                kegiatan: kegiatan
            }, function(res){
                if (res && res.ok) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil dihapus permanen',
                        text: 'Baris kegiatan sudah dihapus.'
                    }).then(function(){ location.reload(); });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal hapus',
                        text: (res && res.message) ? res.message : 'Terjadi kesalahan saat menghapus data.'
                    });
                }
            }, 'json').fail(function(xhr){
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal hapus',
                    text: xhr.responseText || 'Server tidak merespon dengan benar.'
                });
            });
        });
    });

    applyFilters();
})();
</script>
</body>
</html>
