<?php
/* BUILD: SALES-SHIFT-JOIN-SALESPAYMENTSCOPY-20260807 */
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

date_default_timezone_set('Asia/Jakarta');

@ini_set('max_execution_time', '30');
@ini_set('default_socket_timeout', '5');
@ini_set('mysqli.connect_timeout', '3');
@set_time_limit(30);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/map.php';

$username = strtolower(trim((string)($_SESSION['username'] ?? '')));
$role     = strtoupper(trim((string)($_SESSION['role'] ?? '')));

$full_access_users = ['wira','wahid','prengkuh','yan','putri','azik','zahra','alfia','whina','ratna','admin1'];

$user_access = [
    'ujang' => [
        "Papi Mart T2 E3","Papi Mart T2 E4","Papi Mart T2 E5","Papi Mart T2 E5 NEW DB",
        "Ambil Bekal Yuk D2","Ambil Bekal Yuk D6",
        "Point One D1","Point One D3","Point One D5","Point One D7",
        "Papi Mart Gate 18","M Mart"
    ],
    'admin2' => [
        "Papi Mart T2 E3","Papi Mart T2 E4","Papi Mart T2 E5",
        "Ambil Bekal Yuk D2","Ambil Bekal Yuk D6",
        "Point One D1","Point One D3","Point One D5","Point One D7"
    ],
    'mustaqim' => [
        "URBAN B4","Papi Coffee T1B","URBAN B6","URBAN B7",
        "Latte Story T1C","Latte Story T2E","Latte story T2F"
    ],
    'umam' => ["Latte Story T1C","Latte Story T2E","Latte story T2F"]
];

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function valid_date($date, $fallback) {
    $date = trim((string)$date);
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) return $fallback;
    return $date;
}

function rupiah($value) {
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function connect_store_db($cfg, &$error = '') {
    mysqli_report(MYSQLI_REPORT_OFF);

    $conn = mysqli_init();
    if (!$conn) {
        $error = 'Gagal inisialisasi koneksi.';
        return false;
    }

    @mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 3);
    if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
        @mysqli_options($conn, MYSQLI_OPT_READ_TIMEOUT, 10);
    }

    $host = trim((string)($cfg['ip'] ?? ''));
    $user = (string)($cfg['user'] ?? '');
    $pass = (string)($cfg['pass'] ?? '');
    $db   = (string)($cfg['db'] ?? '');

    if ($host === '' || $user === '' || $db === '') {
        $error = 'Config database toko tidak lengkap di map.php.';
        return false;
    }

    $ok = @mysqli_real_connect($conn, $host, $user, $pass, $db, 3306, null, MYSQLI_CLIENT_COMPRESS);
    if (!$ok) {
        $error = mysqli_connect_error() ?: 'Toko sedang offline / database tidak merespons.';
        @mysqli_close($conn);
        return false;
    }

    @mysqli_set_charset($conn, 'utf8mb4');
    @mysqli_query($conn, "SET SESSION MAX_EXECUTION_TIME=15000");
    @mysqli_query($conn, "SET SESSION max_statement_time=15");

    return $conn;
}


/**
 * Cek tabel/kolom secara aman agar laporan tetap bisa berjalan pada outlet
 * yang struktur databasenya belum seragam.
 */
function store_table_exists($conn, $table) {
    if (!($conn instanceof mysqli) || !preg_match('/^[A-Za-z0-9_]+$/', (string)$table)) {
        return false;
    }

    $sql = "SELECT 1 FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;

    mysqli_stmt_bind_param($stmt, 's', $table);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $exists = $result && mysqli_num_rows($result) > 0;
    mysqli_stmt_close($stmt);

    return $exists;
}

function store_column_exists($conn, $table, $column) {
    if (!($conn instanceof mysqli)
        || !preg_match('/^[A-Za-z0-9_]+$/', (string)$table)
        || !preg_match('/^[A-Za-z0-9_]+$/', (string)$column)) {
        return false;
    }

    $sql = "SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
            LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;

    mysqli_stmt_bind_param($stmt, 'ss', $table, $column);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $exists = $result && mysqli_num_rows($result) > 0;
    mysqli_stmt_close($stmt);

    return $exists;
}

/**
 * Bind parameter dinamis, kompatibel dengan PHP 7.4.
 */
function stmt_bind_dynamic($stmt, $types, array &$values) {
    if ($types === '') return true;

    $params = [];
    $params[] = &$types;
    foreach ($values as $key => &$value) {
        $params[] = &$value;
    }
    unset($value);

    return call_user_func_array([$stmt, 'bind_param'], $params);
}

if (!isset($map) || !is_array($map)) {
    die('File map.php tidak valid atau variabel $map tidak ditemukan.');
}

if ($role === 'SUPER ADMIN' || in_array($username, $full_access_users, true)) {
    $allowed_toko_names = array_keys($map);
} else {
    $allowed_toko_names = $user_access[$username] ?? [];
}

$filtered_map = [];
foreach ($allowed_toko_names as $namaToko) {
    if (isset($map[$namaToko])) {
        $filtered_map[$namaToko] = $map[$namaToko];
    }
}

$hasAccess = !empty($filtered_map);

$toko = trim((string)($_GET['toko'] ?? ''));
$from = valid_date($_GET['from'] ?? '', date('Y-m-01'));
$to   = valid_date($_GET['to'] ?? '', date('Y-m-d'));

if ($hasAccess && $toko !== '' && !isset($filtered_map[$toko])) {
    $toko = '';
}

$fromObj = new DateTime($from);
$toObj = new DateTime($to);

if ($fromObj > $toObj) {
    $to = $from;
    $toObj = new DateTime($to);
}

$maxRangeDays = 62;
$rangeTooLong = false;
$diffDays = (int)$fromObj->diff($toObj)->format('%a');

if ($diffDays > $maxRangeDays) {
    $toObj = clone $fromObj;
    $toObj->modify('+' . $maxRangeDays . ' days');
    $to = $toObj->format('Y-m-d');
    $rangeTooLong = true;
}

$data = [];
$offline = false;
$db_error = '';

$total_shift_1 = 0;
$total_shift_2 = 0;
$total_shift_3 = 0;
$total_all = 0;

$trx_shift_1 = 0;
$trx_shift_2 = 0;
$trx_shift_3 = 0;
$trx_all = 0;

$avg_shift_1 = 0;
$avg_shift_2 = 0;
$avg_shift_3 = 0;
$avg_total = 0;
$prev_total_sales = null;
$sales_source_note = '';

if ($hasAccess && $toko !== '' && isset($filtered_map[$toko])) {
    $storeConn = connect_store_db($filtered_map[$toko], $db_error);

    if (!$storeConn) {
        $offline = true;
    } else {
        $startDateTime = $from . ' 00:00:00';
        $endExclusive = (new DateTime($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

        $hasSalesPayments = store_table_exists($storeConn, 'salespayments')
            && store_column_exists($storeConn, 'salespayments', 'salesid')
            && store_column_exists($storeConn, 'salespayments', 'transdate')
            && store_column_exists($storeConn, 'salespayments', 'debit');

        $hasSalesPaymentsCopy = store_table_exists($storeConn, 'salespaymentscopy')
            && store_column_exists($storeConn, 'salespaymentscopy', 'salesid')
            && store_column_exists($storeConn, 'salespaymentscopy', 'transdate')
            && store_column_exists($storeConn, 'salespaymentscopy', 'debit');

        $sourceParts = [];
        $bindTypes = '';
        $bindValues = [];

        if ($hasSalesPayments) {
            $sourceParts[] = "
                SELECT
                    salesid,
                    transdate AS payment_transdate,
                    NULL AS payment_copy_transdate,
                    COALESCE(debit, 0) AS payment_value,
                    0 AS payment_copy_value,
                    1 AS payment_rows,
                    0 AS payment_copy_rows
                FROM salespayments
                WHERE transdate >= ?
                  AND transdate < ?
            ";
            $bindTypes .= 'ss';
            $bindValues[] = $startDateTime;
            $bindValues[] = $endExclusive;
        }

        if ($hasSalesPaymentsCopy) {
            $sourceParts[] = "
                SELECT
                    salesid,
                    NULL AS payment_transdate,
                    transdate AS payment_copy_transdate,
                    0 AS payment_value,
                    COALESCE(debit, 0) AS payment_copy_value,
                    0 AS payment_rows,
                    1 AS payment_copy_rows
                FROM salespaymentscopy
                WHERE transdate >= ?
                  AND transdate < ?
            ";
            $bindTypes .= 'ss';
            $bindValues[] = $startDateTime;
            $bindValues[] = $endExclusive;
        }

        if (!$sourceParts) {
            $db_error = 'Tabel salespayments dan salespaymentscopy tidak tersedia atau struktur kolom salesid, transdate, dan debit tidak sesuai.';
        } else {
            /*
             * Gabungkan kedua sumber per salesid terlebih dahulu.
             * - salespaymentscopy.debit menjadi sumber utama apabila faktur tersedia di tabel copy.
             * - salespayments.debit menjadi cadangan apabila data copy tidak tersedia/bernilai nol.
             * - Faktur yang hanya ada di salah satu tabel tetap ditampilkan.
             * - Setiap salesid hanya dihitung satu kali agar nilai tidak terduplikasi.
             */
            $unionSql = implode("\nUNION ALL\n", $sourceParts);

            $sql = "
                SELECT
                    DATE(invoice_data.transdate) AS tanggal,

                    SUM(CASE
                        WHEN TIME(invoice_data.transdate) >= '00:00:00'
                         AND TIME(invoice_data.transdate) < '08:00:00'
                        THEN invoice_data.nilai_sales ELSE 0 END
                    ) AS sales_shift_1,

                    SUM(CASE
                        WHEN TIME(invoice_data.transdate) >= '08:00:00'
                         AND TIME(invoice_data.transdate) < '16:00:00'
                        THEN invoice_data.nilai_sales ELSE 0 END
                    ) AS sales_shift_2,

                    SUM(CASE
                        WHEN TIME(invoice_data.transdate) >= '16:00:00'
                         AND TIME(invoice_data.transdate) < '24:00:00'
                        THEN invoice_data.nilai_sales ELSE 0 END
                    ) AS sales_shift_3,

                    COUNT(CASE
                        WHEN TIME(invoice_data.transdate) >= '00:00:00'
                         AND TIME(invoice_data.transdate) < '08:00:00'
                        THEN 1 ELSE NULL END
                    ) AS trx_shift_1,

                    COUNT(CASE
                        WHEN TIME(invoice_data.transdate) >= '08:00:00'
                         AND TIME(invoice_data.transdate) < '16:00:00'
                        THEN 1 ELSE NULL END
                    ) AS trx_shift_2,

                    COUNT(CASE
                        WHEN TIME(invoice_data.transdate) >= '16:00:00'
                         AND TIME(invoice_data.transdate) < '24:00:00'
                        THEN 1 ELSE NULL END
                    ) AS trx_shift_3,

                    SUM(invoice_data.nilai_sales) AS total_sales,
                    COUNT(*) AS total_trx

                FROM (
                    SELECT
                        source_rows.salesid,
                        COALESCE(
                            MIN(source_rows.payment_copy_transdate),
                            MIN(source_rows.payment_transdate)
                        ) AS transdate,
                        CASE
                            WHEN SUM(source_rows.payment_copy_rows) > 0
                             AND ABS(SUM(source_rows.payment_copy_value)) > 0.0001
                            THEN SUM(source_rows.payment_copy_value)
                            ELSE SUM(source_rows.payment_value)
                        END AS nilai_sales
                    FROM (
                        {$unionSql}
                    ) AS source_rows
                    WHERE source_rows.salesid IS NOT NULL
                      AND TRIM(CAST(source_rows.salesid AS CHAR)) <> ''
                    GROUP BY source_rows.salesid
                ) AS invoice_data
                WHERE invoice_data.transdate IS NOT NULL
                GROUP BY DATE(invoice_data.transdate)
                ORDER BY tanggal
            ";

            $stmt = mysqli_prepare($storeConn, $sql);

            if ($stmt) {
                if (!stmt_bind_dynamic($stmt, $bindTypes, $bindValues)) {
                    $db_error = 'Gagal mengikat parameter laporan: ' . mysqli_stmt_error($stmt);
                } elseif (!mysqli_stmt_execute($stmt)) {
                    $db_error = 'Query sales per shift gagal: ' . mysqli_stmt_error($stmt);
                } else {
                    $res = mysqli_stmt_get_result($stmt);

                    while ($res && ($row = mysqli_fetch_assoc($res))) {
                        $row['sales_shift_1'] = (float)($row['sales_shift_1'] ?? 0);
                        $row['sales_shift_2'] = (float)($row['sales_shift_2'] ?? 0);
                        $row['sales_shift_3'] = (float)($row['sales_shift_3'] ?? 0);
                        $row['trx_shift_1'] = (int)($row['trx_shift_1'] ?? 0);
                        $row['trx_shift_2'] = (int)($row['trx_shift_2'] ?? 0);
                        $row['trx_shift_3'] = (int)($row['trx_shift_3'] ?? 0);
                        $row['total_sales'] = (float)($row['total_sales'] ?? 0);
                        $row['total_trx'] = (int)($row['total_trx'] ?? 0);

                        if ($prev_total_sales === null) {
                            $row['trend_class'] = 'trend-neutral';
                            $row['trend_text'] = 'Awal';
                            $row['trend_percent'] = 0;
                        } else {
                            $diff = $row['total_sales'] - $prev_total_sales;
                            $row['trend_percent'] = $prev_total_sales > 0
                                ? (($diff / $prev_total_sales) * 100)
                                : 0;

                            if ($diff > 0) {
                                $row['trend_class'] = 'trend-up';
                                $row['trend_text'] = 'Naik';
                            } elseif ($diff < 0) {
                                $row['trend_class'] = 'trend-down';
                                $row['trend_text'] = 'Turun';
                            } else {
                                $row['trend_class'] = 'trend-neutral';
                                $row['trend_text'] = 'Stabil';
                            }
                        }
                        $prev_total_sales = $row['total_sales'];

                        $data[] = $row;

                        $total_shift_1 += $row['sales_shift_1'];
                        $total_shift_2 += $row['sales_shift_2'];
                        $total_shift_3 += $row['sales_shift_3'];
                        $total_all += $row['total_sales'];

                        $trx_shift_1 += $row['trx_shift_1'];
                        $trx_shift_2 += $row['trx_shift_2'];
                        $trx_shift_3 += $row['trx_shift_3'];
                        $trx_all += $row['total_trx'];
                    }
                }

                mysqli_stmt_close($stmt);
            } else {
                $db_error = 'Query tidak dapat disiapkan: ' . mysqli_error($storeConn);
            }
        }

        if ($hasSalesPaymentsCopy && $hasSalesPayments) {
            $sales_source_note = 'Nilai sales membaca salespaymentscopy.debit sebagai sumber utama dan salespayments.debit sebagai cadangan. Data digabung berdasarkan salesid agar faktur tidak terhitung ganda.';
        } elseif ($hasSalesPaymentsCopy) {
            $sales_source_note = 'Outlet ini hanya memiliki struktur salespaymentscopy yang sesuai. Nilai diambil dari salespaymentscopy.debit.';
        } elseif ($hasSalesPayments) {
            $sales_source_note = 'Outlet ini hanya memiliki struktur salespayments yang sesuai. Nilai diambil dari salespayments.debit.';
        }

        mysqli_close($storeConn);
    }
}

$jumlah_hari = count($data);
if ($jumlah_hari > 0) {
    $avg_shift_1 = $total_shift_1 / $jumlah_hari;
    $avg_shift_2 = $total_shift_2 / $jumlah_hari;
    $avg_shift_3 = $total_shift_3 / $jumlah_hari;
    $avg_total   = $total_all / $jumlah_hari;
}

$avg_values = [
    'shift1' => $avg_shift_1,
    'shift2' => $avg_shift_2,
    'shift3' => $avg_shift_3
];

$max_avg = max($avg_values);
$min_avg = min($avg_values);

function avg_color_class($value, $max, $min) {
    if ($value == $max && $value != $min) {
        return 'avg-high';
    }

    if ($value == $min && $value != $max) {
        return 'avg-low';
    }

    return 'avg-mid';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Sales Per Shift</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="icon" type="image/png" href="img/srt2.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/exceljs/dist/exceljs.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/file-saver/dist/FileSaver.min.js"></script>

<style>
*{box-sizing:border-box}
body{
    margin:0;
    min-height:100vh;
    font-family:'Segoe UI',Arial,sans-serif;
    background:
        radial-gradient(circle at top left, rgba(37,99,235,.18), transparent 28%),
        radial-gradient(circle at top right, rgba(14,165,233,.14), transparent 24%),
        linear-gradient(180deg,#f8fafc 0%,#eef2f7 100%);
    color:#0f172a;
}
.header{
    position:sticky;
    top:0;
    z-index:20;
    background:linear-gradient(135deg,#0f172a,#1e3a8a);
    color:#fff;
    padding:18px 26px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    box-shadow:0 14px 40px rgba(15,23,42,.22);
}
.header-title{display:flex;align-items:center;gap:12px;min-width:0}
.header-icon{
    width:44px;height:44px;border-radius:16px;
    display:flex;align-items:center;justify-content:center;
    background:rgba(255,255,255,.14);
    border:1px solid rgba(255,255,255,.22);
    color:#38bdf8;font-size:20px;
}
.header h1{margin:0;font-size:21px;font-weight:900}
.header small{display:block;margin-top:3px;color:#cbd5e1;font-weight:600}
.main{width:min(1280px,96vw);margin:24px auto}
.cardx{
    background:rgba(255,255,255,.95);
    border:1px solid #e2e8f0;
    border-radius:22px;
    padding:20px;
    box-shadow:0 18px 45px rgba(15,23,42,.08);
    margin-bottom:20px;
}
.filter-grid{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:12px;align-items:end}
label{
    display:block;margin-bottom:7px;color:#475569;
    font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;
}
.form-control,.form-select{border-radius:14px;min-height:46px;font-weight:700}
.btn-main{
    min-height:46px;border:0;border-radius:14px;padding:0 18px;
    background:linear-gradient(135deg,#2563eb,#1d4ed8);
    color:#fff;font-weight:900;box-shadow:0 10px 24px rgba(37,99,235,.22);
}
.btn-excel{
    min-height:42px;border:0;border-radius:14px;padding:0 16px;
    background:linear-gradient(135deg,#16a34a,#15803d);
    color:#fff;font-weight:900;
}
.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.summary{border-radius:18px;padding:16px;background:#f8fafc;border:1px solid #e2e8f0}
.summary span{display:block;color:#64748b;font-size:12px;font-weight:900;text-transform:uppercase}
.summary strong{display:block;margin-top:7px;color:#0f172a;font-size:20px;font-weight:950}
.table-wrap{width:100%;overflow:auto;border-radius:18px;border:1px solid #e2e8f0}
table{width:100%;min-width:980px;border-collapse:collapse;background:#fff}
th,td{
    padding:12px 11px;border:1px solid #cbd5e1;
    text-align:center;vertical-align:middle;white-space:nowrap;
}
th{
    background:#1e3a8a;color:#fff;font-size:12px;
    text-transform:uppercase;letter-spacing:.25px;
}
td{font-size:13px;font-weight:700}
.money-big{font-weight:950;font-size:15px}

.avg-high{
    color:#16a34a!important;
    font-weight:950!important;
}

.avg-mid{
    color:#111827!important;
    font-weight:950!important;
}

.avg-low{
    color:#dc2626!important;
    font-weight:950!important;
}




.sales-green{
    color:#16a34a;
    font-weight:900;
    font-size:15px;
}
tbody tr:hover{background:#f8fafc}
tfoot th{background:#eff6ff;color:#0f172a;font-size:13px;border:1px solid #93c5fd}tfoot tr.grand-total th{background:#1e3a8a;color:#fff}tfoot tr.avg-row th{background:#dbeafe;color:#0f172a}
.empty-state{text-align:center;padding:40px 20px;color:#64748b}
.empty-state i{font-size:42px;color:#94a3b8;margin-bottom:12px}
.info-note{color:#475569;font-size:13px;line-height:1.5}
@media(max-width:840px){
    .header{padding:16px;align-items:flex-start;flex-direction:column}
    .filter-grid{grid-template-columns:1fr}
    .summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:520px){
    .summary-grid{grid-template-columns:1fr}
    .main{width:94vw;margin:16px auto}
    .cardx{padding:15px;border-radius:18px}
}
</style>
</head>
<body>

<?php if (!$hasAccess): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
    Swal.fire({
        icon:'error',
        title:'Akses Ditolak',
        text:'Anda tidak memiliki hak akses ke halaman Sales Per Shift.',
        confirmButtonText:'Kembali',
        allowOutsideClick:false,
        allowEscapeKey:false
    }).then(() => window.location.href = 'dashboard.php');
});
</script>
<?php endif; ?>

<div class="header">
    <div class="header-title">
        <div class="header-icon"><i class="fa-solid fa-chart-column"></i></div>
        <div>
            <h1>Sales Per Shift</h1>
            <small>Shift 1: 00:00 - 08:00 | Shift 2: 08:00 - 16:00 | Shift 3: 16:00 - 00:00 WIB</small>
        </div>
    </div>
    <a href="dashboard.php" class="btn btn-light fw-bold rounded-4">
        <i class="fa-solid fa-arrow-left"></i> Kembali
    </a>
</div>

<div class="main">
    <div class="cardx">
        <form method="GET" class="filter-grid" id="filterForm">
            <div>
                <label>Nama Toko</label>
                <select name="toko" class="form-select" required>
                    <option value="">Pilih Toko Terlebih Dahulu</option>
                    <?php foreach($filtered_map as $namaToko => $cfg): ?>
                        <option value="<?= h($namaToko) ?>" <?= $namaToko === $toko ? 'selected' : '' ?>>
                            <?= h($namaToko) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>Dari Tanggal</label>
                <input type="date" name="from" class="form-control" value="<?= h($from) ?>">
            </div>

            <div>
                <label>Sampai Tanggal</label>
                <input type="date" name="to" class="form-control" value="<?= h($to) ?>">
            </div>

            <button type="submit" class="btn-main">
                <i class="fa-solid fa-magnifying-glass-chart"></i> Tampilkan
            </button>
        </form>

        <div class="info-note mt-3">
            <i class="fa-solid fa-circle-info"></i>
            Nilai laporan dibaca dari <b>salespayments</b> dan <b>salespaymentscopy</b>, lalu digabung per nomor faktur agar transaksi tidak terhitung dua kali.
            <?php if ($sales_source_note !== ''): ?>
                <div class="mt-1"><?= h($sales_source_note) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($rangeTooLong)): ?>
    <div class="cardx">
        <div class="alert alert-warning mb-0">
            Periode terlalu panjang. Untuk mencegah timeout, sistem membatasi maksimal <?= (int)$maxRangeDays ?> hari sampai tanggal <b><?= h($to) ?></b>.
        </div>
    </div>
    <?php endif; ?>

    <?php if ($toko === ''): ?>
    <div class="cardx">
        <div class="empty-state">
            <i class="fa-solid fa-store"></i>
            <h4 class="fw-bold">Pilih Toko</h4>
            <p class="mb-0">Silakan pilih nama toko terlebih dahulu untuk menampilkan sales per shift.</p>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($toko !== '' && $offline): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
        Swal.fire({
            icon:'warning',
            title:'Toko Sedang Offline',
            html:'Toko <b><?= h($toko) ?></b> sedang offline.<br>Memerlukan koneksi internet untuk mengambil data.',
            confirmButtonText:'Mengerti',
            confirmButtonColor:'#2563eb'
        });
    });
    </script>
    <div class="cardx">
        <div class="empty-state">
            <i class="fa-solid fa-wifi"></i>
            <h4 class="fw-bold">Toko Sedang Offline</h4>
            <p class="mb-0">Memerlukan koneksi internet untuk mengambil data dari toko <b><?= h($toko) ?></b>.</p>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($toko !== '' && !$offline && $db_error !== ''): ?>
    <div class="cardx">
        <div class="alert alert-danger mb-0">
            <b>Data gagal diproses:</b> <?= h($db_error) ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($toko !== '' && !$offline && $db_error === '' && empty($data)): ?>
    <div class="cardx">
        <div class="empty-state">
            <i class="fa-solid fa-circle-info"></i>
            <h4 class="fw-bold">Data Tidak Ditemukan</h4>
            <p class="mb-0">Tidak ada sales pada periode <b><?= h($from) ?></b> s/d <b><?= h($to) ?></b>.</p>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($data)): ?>
    <div class="cardx mt-3">
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
            <div>
                <h4 class="m-0 fw-bold">Tabel Sales Per Shift</h4>
                <div class="text-muted small">
                    Toko: <b><?= h($toko) ?></b> | Periode: <b><?= h($from) ?></b> s/d <b><?= h($to) ?></b>
                </div>
            </div>
            <button type="button" class="btn-excel" onclick="downloadExcel()">
                <i class="fa-solid fa-file-excel"></i> Download Excel
            </button>
        </div>

        <div class="table-wrap">
            <table id="salesShiftTable">
                <thead>
                    <tr>
                        <th rowspan="2">Tanggal</th>
                        <th colspan="2">Shift 1</th>
                        <th colspan="2">Shift 2</th>
                        <th colspan="2">Shift 3</th>
                        <th colspan="2">Total Harian</th>
                        
                    </tr>
                    <tr>
                        <th>Sales</th><th>Trx</th>
                        <th>Sales</th><th>Trx</th>
                        <th>Sales</th><th>Trx</th>
                        <th>Sales</th><th>Trx</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($data as $row): ?>
                    <tr>
                        <td><?= h($row['tanggal']) ?></td>
                        <td class="money-big <?= h($row['trend_class']) ?>"><?= rupiah($row['sales_shift_1']) ?></td>
                        <td><?= number_format($row['trx_shift_1'], 0, ',', '.') ?></td>
                        <td class="money-big <?= h($row['trend_class']) ?>"><?= rupiah($row['sales_shift_2']) ?></td>
                        <td><?= number_format($row['trx_shift_2'], 0, ',', '.') ?></td>
                        <td class="money-big <?= h($row['trend_class']) ?>"><?= rupiah($row['sales_shift_3']) ?></td>
                        <td><?= number_format($row['trx_shift_3'], 0, ',', '.') ?></td>
                        <td><b><?= rupiah($row['total_sales']) ?></b></td>
                        <td><b><?= number_format($row['total_trx'], 0, ',', '.') ?></b></td>
                        
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="grand-total">
                        <th>Grand Total</th>
                        <th><?= rupiah($total_shift_1) ?></th>
                        <th><?= number_format($trx_shift_1, 0, ',', '.') ?></th>
                        <th><?= rupiah($total_shift_2) ?></th>
                        <th><?= number_format($trx_shift_2, 0, ',', '.') ?></th>
                        <th><?= rupiah($total_shift_3) ?></th>
                        <th><?= number_format($trx_shift_3, 0, ',', '.') ?></th>
                        <th><?= rupiah($total_all) ?></th>
                        <th><?= number_format($trx_all, 0, ',', '.') ?></th>
                        </tr>
                    <tr class="avg-row">
                        <th>Rata-Rata</th>
                        <th class="<?= avg_color_class($avg_shift_1, $max_avg, $min_avg) ?>"><?= rupiah($avg_shift_1) ?></th>
                        <th>-</th>
                        <th class="<?= avg_color_class($avg_shift_2, $max_avg, $min_avg) ?>"><?= rupiah($avg_shift_2) ?></th>
                        <th>-</th>
                        <th class="<?= avg_color_class($avg_shift_3, $max_avg, $min_avg) ?>"><?= rupiah($avg_shift_3) ?></th>
                        <th>-</th>
                        <th><?= rupiah($avg_total) ?></th>
                        <th>-</th>
                        </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const form = document.getElementById('filterForm');
    if (form) {
        form.addEventListener('submit', function(){
            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Memuat...';
            }
        });
    }
});

const salesShiftData = <?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>;
const selectedToko = <?= json_encode($toko, JSON_UNESCAPED_UNICODE) ?>;
const selectedFrom = <?= json_encode($from, JSON_UNESCAPED_UNICODE) ?>;
const selectedTo = <?= json_encode($to, JSON_UNESCAPED_UNICODE) ?>;

const totalShift1 = <?= json_encode($total_shift_1) ?>;
const totalShift2 = <?= json_encode($total_shift_2) ?>;
const totalShift3 = <?= json_encode($total_shift_3) ?>;
const totalAll = <?= json_encode($total_all) ?>;
const trxShift1 = <?= json_encode($trx_shift_1) ?>;
const trxShift2 = <?= json_encode($trx_shift_2) ?>;
const trxShift3 = <?= json_encode($trx_shift_3) ?>;
const trxAll = <?= json_encode($trx_all) ?>;

function formatFileName(value) {
    return String(value || '').replace(/[^a-z0-9]+/gi, '_').replace(/^_+|_+$/g, '');
}

async function downloadExcel() {
    const workbook = new ExcelJS.Workbook();
    workbook.creator = 'Sales Per Shift';
    workbook.created = new Date();

    const ws = workbook.addWorksheet('Sales Per Shift');

    ws.mergeCells('A1:I1');
    ws.getCell('A1').value = 'SALES PER SHIFT';
    ws.getCell('A1').font = { bold:true, size:16, color:{argb:'FFFFFFFF'} };
    ws.getCell('A1').alignment = { horizontal:'center', vertical:'middle' };
    ws.getCell('A1').fill = { type:'pattern', pattern:'solid', fgColor:{argb:'FF0F172A'} };

    ws.mergeCells('A2:I2');
    ws.getCell('A2').value = 'Toko: ' + selectedToko + ' | Periode: ' + selectedFrom + ' s/d ' + selectedTo;
    ws.getCell('A2').font = { italic:true, bold:true };
    ws.getCell('A2').alignment = { horizontal:'center', vertical:'middle' };

    ws.mergeCells('A3:I3');
    ws.getCell('A3').value = 'Sumber nilai: salespaymentscopy sebagai utama, salespayments sebagai cadangan; digabung berdasarkan salesid.';
    ws.getCell('A3').font = { italic:true, size:10, color:{argb:'FF475569'} };
    ws.getCell('A3').alignment = { horizontal:'center', vertical:'middle', wrapText:true };

    ws.mergeCells('B4:C4');
    ws.mergeCells('D4:E4');
    ws.mergeCells('F4:G4');
    ws.mergeCells('H4:I4');

    ws.getRow(4).values = [
        'Tanggal',
        'Shift 1 (00:00 - 08:00)', '',
        'Shift 2 (08:00 - 16:00)', '',
        'Shift 3 (16:00 - 00:00)', '',
        'Total Harian', ''
    ];
    ws.getRow(5).values = ['Tanggal','Sales','Trx','Sales','Trx','Sales','Trx','Sales','Trx'];

    [4,5].forEach(function(rowNumber){
        ws.getRow(rowNumber).eachCell(function(cell){
            cell.font = { bold:true, color:{argb:'FFFFFFFF'} };
            cell.alignment = { horizontal:'center', vertical:'middle' };
            cell.fill = { type:'pattern', pattern:'solid', fgColor:{argb:'FF1E3A8A'} };
            cell.border = { top:{style:'thin'}, left:{style:'thin'}, bottom:{style:'thin'}, right:{style:'thin'} };
        });
    });

    let rowIndex = 6;
    salesShiftData.forEach(function(item){
        const row = ws.getRow(rowIndex);
        row.values = [
            item.tanggal,
            Number(item.sales_shift_1 || 0), Number(item.trx_shift_1 || 0),
            Number(item.sales_shift_2 || 0), Number(item.trx_shift_2 || 0),
            Number(item.sales_shift_3 || 0), Number(item.trx_shift_3 || 0),
            Number(item.total_sales || 0), Number(item.total_trx || 0)
        ];

        [2,4,6,8].forEach(function(col){ row.getCell(col).numFmt = '"Rp" #,##0'; });
        [3,5,7,9].forEach(function(col){ row.getCell(col).numFmt = '#,##0'; });

        row.eachCell(function(cell){
            cell.alignment = { horizontal:'center', vertical:'middle' };
            cell.border = { top:{style:'thin'}, left:{style:'thin'}, bottom:{style:'thin'}, right:{style:'thin'} };
        });

        rowIndex++;
    });

    const totalRow = ws.getRow(rowIndex);
    totalRow.values = [
        'Grand Total',
        Number(totalShift1), Number(trxShift1),
        Number(totalShift2), Number(trxShift2),
        Number(totalShift3), Number(trxShift3),
        Number(totalAll), Number(trxAll)
    ];

    [2,4,6,8].forEach(function(col){ totalRow.getCell(col).numFmt = '"Rp" #,##0'; });
    [3,5,7,9].forEach(function(col){ totalRow.getCell(col).numFmt = '#,##0'; });

    totalRow.eachCell(function(cell){
        cell.font = { bold:true, color:{argb:'FFFFFFFF'} };
        cell.alignment = { horizontal:'center', vertical:'middle' };
        cell.fill = { type:'pattern', pattern:'solid', fgColor:{argb:'FF0F172A'} };
        cell.border = { top:{style:'thin'}, left:{style:'thin'}, bottom:{style:'thin'}, right:{style:'thin'} };
    });

    ws.columns = [
        { width:16 }, { width:18 }, { width:12 }, { width:18 }, { width:12 },
        { width:18 }, { width:12 }, { width:18 }, { width:12 }
    ];

    ws.eachRow(function(row){ row.height = 22; });

    const buffer = await workbook.xlsx.writeBuffer();
    saveAs(
        new Blob([buffer], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}),
        'sales_shift_' + formatFileName(selectedToko) + '_' + selectedFrom + '_sd_' + selectedTo + '.xlsx'
    );
}
</script>

</body>
</html>
