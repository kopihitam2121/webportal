<?php
/* BUILD: BASKETSIZE-JOIN-SALESDETAILCOPY-20260806 */
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

date_default_timezone_set('Asia/Jakarta');

/* ================= ANTI TIMEOUT / GATEWAY FIX ================= */
@ini_set('max_execution_time', '25');
@ini_set('default_socket_timeout', '5');
@ini_set('mysqli.connect_timeout', '3');
@set_time_limit(25);

function bs_valid_date($date, $fallback) {
    $date = trim((string)$date);
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) return $fallback;
    return $date;
}

function bs_connect_store($cfg, &$error = '') {
    mysqli_report(MYSQLI_REPORT_OFF);

    $conn = mysqli_init();
    if (!$conn) {
        $error = 'Init koneksi gagal.';
        return false;
    }

    @mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 3);

    if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
        @mysqli_options($conn, MYSQLI_OPT_READ_TIMEOUT, 8);
    }

    $host = trim((string)($cfg['ip'] ?? ''));
    $user = (string)($cfg['user'] ?? '');
    $pass = (string)($cfg['pass'] ?? '');
    $db   = (string)($cfg['db'] ?? '');

    if ($host === '' || $user === '' || $db === '') {
        $error = 'Config database toko tidak lengkap.';
        return false;
    }

    $ok = @mysqli_real_connect($conn, $host, $user, $pass, $db, 3306, null, MYSQLI_CLIENT_COMPRESS);

    if (!$ok) {
        $error = mysqli_connect_error() ?: 'Toko offline / koneksi database gagal.';
        @mysqli_close($conn);
        return false;
    }

    @mysqli_set_charset($conn, 'utf8mb4');

    @mysqli_query($conn, "SET SESSION MAX_EXECUTION_TIME=10000");
    @mysqli_query($conn, "SET SESSION max_statement_time=10");

    return $conn;
}

/* ================= HELPER STRUKTUR DATABASE ================= */
function bs_table_exists($conn, $table) {
    if (!($conn instanceof mysqli) || !preg_match('/^[A-Za-z0-9_]+$/', (string)$table)) {
        return false;
    }

    $sql = "SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
            LIMIT 1";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, 's', $table);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $exists = $result && mysqli_num_rows($result) > 0;
    mysqli_stmt_close($stmt);

    return $exists;
}

function bs_table_columns($conn, $table) {
    if (!($conn instanceof mysqli) || !preg_match('/^[A-Za-z0-9_]+$/', (string)$table)) {
        return [];
    }

    $result = mysqli_query($conn, 'SHOW COLUMNS FROM `' . $table . '`');
    if (!$result) {
        return [];
    }

    $columns = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $field = trim((string)($row['Field'] ?? ''));
        if ($field !== '') {
            $columns[strtolower($field)] = $field;
        }
    }
    mysqli_free_result($result);

    return $columns;
}

function bs_pick_column($columns, $candidates) {
    foreach ($candidates as $candidate) {
        $key = strtolower((string)$candidate);
        if (isset($columns[$key])) {
            return (string)$columns[$key];
        }
    }

    return null;
}

function bs_qi($identifier) {
    return '`' . str_replace('`', '``', (string)$identifier) . '`';
}

function bs_bind_params($stmt, $types, &$values) {
    if ($types === '') {
        return true;
    }

    $refs = [];
    $refs[] = &$types;

    foreach ($values as $key => $value) {
        $refs[] = &$values[$key];
    }

    return call_user_func_array([$stmt, 'bind_param'], $refs);
}

$username = strtolower(trim($_SESSION['username'] ?? ''));
$role     = strtoupper($_SESSION['role'] ?? '');


require_once __DIR__ . '/map.php';



$full_access_users = [
    'wira',
    'wahid',
    'prengkuh',
    'yan',
    'putri',
    'azik',
    'zahra',
    'alfia',
    'admin1',
    'whina',
    'ratna',
    'ujang'
];

/* ================= RBAC USER -> TOKO ================= */
$user_access = [
'haris' => [
        "Papi Mart T2 E3",
        "Papi Mart T2 E4",
        "Papi Mart T2 E5",
        "Papi Mart T2 E5 NEW DB",
        "Ambil Bekal Yuk D2",
        "Ambil Bekal Yuk D6",
        "Point One D1",
        "Point One D3",
        "Point One D5",
        "Point One D7",
        "Papi Mart Gate 18",
        "M Mart"
    ],

    'admin' => [
        "Papi Mart T2 E3",
        "Papi Mart T2 E4",
        "Papi Mart T2 E5",
        "Papi Mart T2 E5 NEW DB",
        "Ambil Bekal Yuk D2",
        "Ambil Bekal Yuk D6",
        "Point One D1",
        "Point One D3",
        "Point One D5",
        "Point One D7",
        "Papi Mart Gate 18",
        "M Mart"
    ],

'admin2' => [
        "Papi Mart T2 E3",
        "Papi Mart T2 E4",
        "Papi Mart T2 E5",
        "Ambil Bekal Yuk D2",
        "Ambil Bekal Yuk D6",
        "Point One D1",
        "Point One D3",
        "Point One D5",
        "Point One D7"
    ],

    'mustaqim' => [
        "URBAN B4",
        "Papi Coffee T1B",
        "URBAN B6",
        "URBAN B7",
        "Latte Story T1C",
        "Latte Story T2E",
        "Latte Story T2F"

        
    ],
    'umam' => [
        "Latte Story T1C",
        "Latte Story T2E",
        "Latte Story T2F"
    ]
];

/* ================= VALIDASI FILE MAP ================= */
if (!isset($map) || !is_array($map)) {
    die("File map.php tidak valid atau variabel \$map tidak ditemukan.");
}

/* ================= TENTUKAN HAK AKSES ================= */
if ($role === 'SUPER ADMIN' || in_array($username, $full_access_users, true)) {
    $allowed_toko_names = array_keys($map);
} else {
    $allowed_toko_names = $user_access[$username] ?? [];
}

$hasAccess = !empty($allowed_toko_names);

/* ================= FILTER MAP SESUAI RBAC ================= */
$filtered_map = [];
if ($hasAccess) {
    foreach ($allowed_toko_names as $namaToko) {
        if (isset($map[$namaToko])) {
            $filtered_map[$namaToko] = $map[$namaToko];
        }
    }
}

/* ================= INPUT FILTER ================= */
$toko  = trim((string)($_GET['toko'] ?? ''));
$from  = bs_valid_date($_GET['from'] ?? '', date('Y-m-01'));
$to    = bs_valid_date($_GET['to'] ?? '', date('Y-m-d'));

$fromObj = new DateTime($from);
$toObj = new DateTime($to);

if ($fromObj > $toObj) {
    $to = $from;
    $toObj = new DateTime($to);
}

$maxRangeDays = 62;
$diffDays = (int)$fromObj->diff($toObj)->format('%a');

$rangeTooLong = false;
if ($diffDays > $maxRangeDays) {
    $toObj = clone $fromObj;
    $toObj->modify('+' . $maxRangeDays . ' days');
    $to = $toObj->format('Y-m-d');
    $rangeTooLong = true;
}

/* ================= VALIDASI TOKO TERPILIH ================= */
if ($hasAccess && $toko !== '' && !isset($filtered_map[$toko])) {
    $toko = '';
}

$data = [];
$chartTanggal = [];
$chartBasket  = [];
$offline = false;
$db_error = '';
$query_error = '';
$source_info = '';

/* ================= GRAND TOTAL & RATA-RATA ================= */
$grand_total_sales = 0;
$grand_total_transaksi = 0;
$grand_total_basket_size = 0;

$rata_rata_sales = 0;
$rata_rata_transaksi = 0;
$rata_rata_basket_size = 0;

/* ================= AMBIL DATA ================= */
if ($hasAccess && $toko && isset($filtered_map[$toko])) {
    $cfg = $filtered_map[$toko];

    $conn = bs_connect_store($cfg, $db_error);

    if (!$conn) {
        $offline = true;
    } else {
        $fromDateTime = $from . ' 00:00:00';
        $toExclusive = (new DateTime($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

        /*
         * Gabungkan salespayments dan salesdetailcopy pada level nomor faktur.
         *
         * Kenapa tidak langsung JOIN baris-ke-baris:
         * - Satu faktur dapat mempunyai beberapa baris pembayaran.
         * - Satu faktur dapat mempunyai beberapa produk di salesdetailcopy.
         * JOIN langsung dapat menggandakan nominal dan jumlah transaksi.
         *
         * Aturan nilai:
         * 1. Nominal salespayments dipakai jika tersedia dan jumlahnya bukan nol.
         * 2. Jika tidak ada pembayaran, nominal salesdetailcopy dipakai.
         * 3. Faktur yang hanya ada di salesdetailcopy tetap dihitung.
         */
        $sourceParts = [];
        $bindValues = [];
        $bindTypes = '';
        $sourceNames = [];

        if (bs_table_exists($conn, 'salespayments')) {
            $paymentColumns = bs_table_columns($conn, 'salespayments');
            $paymentSalesId = bs_pick_column($paymentColumns, ['salesid', 'invoiceid', 'invoice']);
            $paymentDate = bs_pick_column($paymentColumns, ['transdate', 'paymentdate', 'created_at', 'date']);
            $paymentValue = bs_pick_column($paymentColumns, ['debit', 'amount', 'paymentamount', 'nominal']);

            if ($paymentSalesId && $paymentDate && $paymentValue) {
                $sourceParts[] = "
                    SELECT
                        CAST(sp." . bs_qi($paymentSalesId) . " AS CHAR) AS salesid,
                        sp." . bs_qi($paymentDate) . " AS transdate,
                        COALESCE(sp." . bs_qi($paymentValue) . ", 0) AS nominal,
                        1 AS source_priority
                    FROM `salespayments` sp
                    WHERE sp." . bs_qi($paymentDate) . " >= ?
                      AND sp." . bs_qi($paymentDate) . " < ?
                ";

                $bindTypes .= 'ss';
                $bindValues[] = $fromDateTime;
                $bindValues[] = $toExclusive;
                $sourceNames[] = 'salespayments';
            }
        }

        if (bs_table_exists($conn, 'salesdetailcopy')) {
            $detailColumns = bs_table_columns($conn, 'salesdetailcopy');
            $detailSalesId = bs_pick_column($detailColumns, ['salesid', 'invoiceid', 'invoice']);
            $detailDate = bs_pick_column($detailColumns, ['transdate', 'salesdate', 'created_at', 'date']);
            $detailNet = bs_pick_column($detailColumns, ['netamount']);
            $detailGross = bs_pick_column($detailColumns, ['grossamount']);
            $detailQty = bs_pick_column($detailColumns, ['salesqty', 'qty', 'quantity']);
            $detailPrice = bs_pick_column($detailColumns, ['price', 'salesprice', 'unitprice']);

            $detailValueExpression = '';
            if ($detailNet) {
                $detailValueExpression = 'COALESCE(sd.' . bs_qi($detailNet) . ', 0)';
            } elseif ($detailGross) {
                $detailValueExpression = 'COALESCE(sd.' . bs_qi($detailGross) . ', 0)';
            } elseif ($detailQty && $detailPrice) {
                $detailValueExpression = '(COALESCE(sd.' . bs_qi($detailQty) . ', 0) * COALESCE(sd.' . bs_qi($detailPrice) . ', 0))';
            }

            if ($detailSalesId && $detailDate && $detailValueExpression !== '') {
                $sourceParts[] = "
                    SELECT
                        CAST(sd." . bs_qi($detailSalesId) . " AS CHAR) AS salesid,
                        sd." . bs_qi($detailDate) . " AS transdate,
                        " . $detailValueExpression . " AS nominal,
                        2 AS source_priority
                    FROM `salesdetailcopy` sd
                    WHERE sd." . bs_qi($detailDate) . " >= ?
                      AND sd." . bs_qi($detailDate) . " < ?
                ";

                $bindTypes .= 'ss';
                $bindValues[] = $fromDateTime;
                $bindValues[] = $toExclusive;
                $sourceNames[] = 'salesdetailcopy';
            }
        }

        $stmt = false;
        $res = false;

        if (!$sourceParts) {
            $query_error = 'Tabel salespayments/salesdetailcopy atau kolom yang dibutuhkan tidak ditemukan.';
        } else {
            $unionSql = implode("\nUNION ALL\n", $sourceParts);

            $sql = "
                SELECT
                    DATE(invoice_time) AS tanggal,
                    ROUND(SUM(invoice_nominal)) AS total_sales,
                    COUNT(*) AS total_transaksi,
                    CASE
                        WHEN COUNT(*) > 0 THEN ROUND(SUM(invoice_nominal) / COUNT(*), 2)
                        ELSE 0
                    END AS basket_size
                FROM (
                    SELECT
                        salesid,
                        COALESCE(
                            MIN(CASE WHEN source_priority = 1 THEN transdate END),
                            MIN(CASE WHEN source_priority = 2 THEN transdate END)
                        ) AS invoice_time,
                        CASE
                            WHEN SUM(CASE WHEN source_priority = 1 THEN nominal ELSE 0 END) <> 0
                            THEN SUM(CASE WHEN source_priority = 1 THEN nominal ELSE 0 END)
                            ELSE SUM(CASE WHEN source_priority = 2 THEN nominal ELSE 0 END)
                        END AS invoice_nominal
                    FROM (
                        " . $unionSql . "
                    ) combined_sources
                    WHERE salesid IS NOT NULL
                      AND salesid <> ''
                    GROUP BY salesid
                ) invoice_summary
                WHERE invoice_time IS NOT NULL
                GROUP BY DATE(invoice_time)
                ORDER BY tanggal
            ";

            $stmt = mysqli_prepare($conn, $sql);

            if (!$stmt) {
                $query_error = 'Query gagal dipersiapkan: ' . mysqli_error($conn);
            } elseif (!bs_bind_params($stmt, $bindTypes, $bindValues)) {
                $query_error = 'Parameter query gagal dipasang.';
            } elseif (!mysqli_stmt_execute($stmt)) {
                $query_error = 'Query gagal dijalankan: ' . mysqli_stmt_error($stmt);
            } else {
                $res = mysqli_stmt_get_result($stmt);
                $source_info = implode(' + ', $sourceNames);
            }
        }

        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $row['total_sales'] = (float)($row['total_sales'] ?? 0);
                $row['total_transaksi'] = (int)($row['total_transaksi'] ?? 0);
                $row['basket_size'] = (float)($row['basket_size'] ?? 0);

                $data[] = $row;
                $chartTanggal[] = $row['tanggal'];
                $chartBasket[]  = round($row['basket_size']);

                $grand_total_sales += $row['total_sales'];
                $grand_total_transaksi += $row['total_transaksi'];
                $grand_total_basket_size += $row['basket_size'];
            }

            $jumlah_hari = count($data);
            if ($jumlah_hari > 0) {
                $rata_rata_sales = $grand_total_sales / $jumlah_hari;
                $rata_rata_transaksi = $grand_total_transaksi / $jumlah_hari;
                $rata_rata_basket_size = $grand_total_basket_size / $jumlah_hari;
            }

            if ($grand_total_transaksi > 0) {
                $grand_total_basket_size = $grand_total_sales / $grand_total_transaksi;
            }
        }

        if ($stmt) {
            mysqli_stmt_close($stmt);
        }

        mysqli_close($conn);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Basket Size Analytics</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="icon" type="image/png" href="img/srt2.png" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script src="https://cdn.jsdelivr.net/npm/exceljs/dist/exceljs.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/file-saver/dist/FileSaver.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.5.31/dist/jspdf.plugin.autotable.min.js"></script>

<style>
*{box-sizing:border-box}
body{
    margin:0;
    font-family:'Segoe UI',sans-serif;
    background:#eef2f7;
}
header{
    background:#1f2937;
    color:#fff;
    padding:20px 30px;
    display:flex;
    align-items:center;
    justify-content:space-between;
}
header h1{
    margin:0;
    font-size:22px;
}
header i{color:#38bdf8;margin-right:10px}

.container{
    padding:25px;
    min-height:calc(100vh - 80px);
}
.filter{
    display:flex;
    gap:15px;
    margin-bottom:20px;
    flex-wrap:wrap;
}
select,input,button{
    padding:10px 14px;
    border-radius:8px;
    border:1px solid #ccc;
}
button{
    background:#2563eb;
    color:#fff;
    border:none;
    cursor:pointer;
}
button:hover{background:#1d4ed8}

.card{
    background:#fff;
    border-radius:12px;
    padding:20px;
    box-shadow:0 6px 16px rgba(0,0,0,.08);
    margin-bottom:25px;
}

table{
    width:100%;
    border-collapse:collapse;
}
th,td{
    padding:12px;
    border-bottom:1px solid #eee;
    text-align:center;
}
th{
    background:#1f2937;
    color:#fff;
}
tr:hover{background:#f8fafc}

.chart-box{
    height:380px;
}
</style>
</head>

<body>

<?php if (!$hasAccess): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({
        icon: 'error',
        title: 'Akses Ditolak',
        html: 'Maaf, Anda tidak memiliki hak akses ke halaman ini.',
        confirmButtonText: 'Kembali',
        allowOutsideClick: false,
        allowEscapeKey: false
    }).then(() => {
        window.location.href = 'dashboard.php';
    });
});
</script>
<?php else: ?>

<header>
    <h1><i class="fa-solid fa-chart-line"></i> Basket Size Analytics</h1>
    <div><?= date('d M Y') ?></div>
    <a href="dashboard.php" class="btn btn-outline-primary">Kembali</a>
</header>

<div class="container">

<form class="filter" method="GET">
    <select name="toko" required>
        <option value="">Pilih Toko</option>
        <?php foreach($filtered_map as $k => $v): ?>
        <option value="<?= htmlspecialchars($k) ?>" <?= ($k == $toko ? 'selected' : '') ?>>
            <?= htmlspecialchars($k) ?>
        </option>
        <?php endforeach; ?>
    </select>

    <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
    <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
    <button type="submit"><i class="fa-solid fa-filter"></i> Terapkan</button>

    <?php if ($data): ?>
    <button type="button" onclick="downloadExcel()">
        <i class="fa-solid fa-file-excel"></i> Download Excel
    </button>
    <button type="button" onclick="downloadPDF()">
        <i class="fa-solid fa-file-pdf"></i> Download PDF
    </button>
    <?php endif; ?>
</form>

<?php if (!empty($rangeTooLong)): ?>
<div class="card">
    <div class="alert alert-warning mb-0">
        Periode terlalu panjang. Untuk mencegah timeout, data otomatis dibatasi maksimal <?= (int)$maxRangeDays ?> hari sampai tanggal <b><?= htmlspecialchars($to) ?></b>.
    </div>
</div>
<?php endif; ?>

<?php if ($toko && $offline): ?>
<div class="card">
    <div class="alert alert-danger mb-0">
        Koneksi ke database toko <b><?= htmlspecialchars($toko) ?></b> gagal / offline.<br>
        <small><?= htmlspecialchars($db_error ?: 'Timeout koneksi atau database toko tidak merespons.') ?></small>
    </div>
</div>
<?php endif; ?>

<?php if ($toko && !$offline && $query_error !== ''): ?>
<div class="card">
    <div class="alert alert-danger mb-0">
        Data tidak dapat diproses.<br>
        <small><?= htmlspecialchars($query_error, ENT_QUOTES, 'UTF-8') ?></small>
    </div>
</div>
<?php endif; ?>

<?php if ($data): ?>
<div class="card">
<table id="basketTable">
<tr>
    <th>Tanggal</th>
    <th>Total Sales</th>
    <th>Total Transaksi</th>
    <th>Basket Size</th>
</tr>
<?php foreach($data as $d): ?>
<tr>
    <td><?= htmlspecialchars($d['tanggal']) ?></td>
    <td>Rp <?= number_format($d['total_sales']) ?></td>
    <td><?= number_format($d['total_transaksi']) ?></td>
    <td>Rp <?= number_format($d['basket_size']) ?></td>
</tr>
<?php endforeach; ?>

<tr>
    <th>Grand Total</th>
    <th>Rp <?= number_format($grand_total_sales) ?></th>
    <th><?= number_format($grand_total_transaksi) ?></th>
    <th>Rp <?= number_format($grand_total_basket_size) ?></th>
</tr>

<tr>
    <th>Rata-Rata</th>
    <th>Rp <?= number_format($rata_rata_sales) ?></th>
    <th><?= number_format($rata_rata_transaksi) ?></th>
    <th>Rp <?= number_format($rata_rata_basket_size) ?></th>
</tr>
</table>

<?php if ($source_info !== ''): ?>
<div class="small text-muted text-center mt-3">
    Sumber data: <?= htmlspecialchars($source_info, ENT_QUOTES, 'UTF-8') ?> | digabung unik berdasarkan nomor faktur.
</div>
<?php endif; ?>
</div>

<div class="card chart-box">
    <canvas id="basketChart"></canvas>
</div>
<?php elseif ($toko && !$offline): ?>
<div class="card">
    <div class="alert alert-warning mb-0">
        Data basket size untuk <b><?= htmlspecialchars($toko) ?></b> pada periode tersebut tidak ditemukan.
    </div>
</div>
<?php endif; ?>

</div>

<?php if ($data): ?>
<script>
const basketData = <?= json_encode($data) ?>;
const selectedToko = <?= json_encode($toko) ?>;
const selectedFrom = <?= json_encode($from) ?>;
const selectedTo = <?= json_encode($to) ?>;
const grandTotalSales = <?= json_encode($grand_total_sales) ?>;
const grandTotalTransaksi = <?= json_encode($grand_total_transaksi) ?>;
const grandTotalBasketSize = <?= json_encode($grand_total_basket_size) ?>;
const rataRataSales = <?= json_encode($rata_rata_sales) ?>;
const rataRataTransaksi = <?= json_encode($rata_rata_transaksi) ?>;
const rataRataBasketSize = <?= json_encode($rata_rata_basket_size) ?>;
const basketSourceInfo = <?= json_encode($source_info, JSON_UNESCAPED_UNICODE) ?>;

const ctx = document.getElementById('basketChart');
if (ctx) {
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chartTanggal) ?>,
            datasets: [{
                label: 'Basket Size',
                data: <?= json_encode($chartBasket) ?>,
                borderWidth: 3,
                tension: 0.3,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: true }
            }
        }
    });
}

async function downloadExcel() {
    const workbook = new ExcelJS.Workbook();
    const worksheet = workbook.addWorksheet('Basket Size');

    worksheet.columns = [
        { header: 'Tanggal', key: 'tanggal', width: 18 },
        { header: 'Total Sales', key: 'total_sales', width: 20 },
        { header: 'Total Transaksi', key: 'total_transaksi', width: 20 },
        { header: 'Basket Size', key: 'basket_size', width: 18 }
    ];

    worksheet.mergeCells('A1:D1');
    worksheet.getCell('A1').value = 'BASKET SIZE ANALYTICS';
    worksheet.getCell('A1').font = { bold: true, size: 14, color: { argb: 'FFFFFFFF' } };
    worksheet.getCell('A1').alignment = { horizontal: 'center', vertical: 'middle' };
    worksheet.getCell('A1').fill = {
        type: 'pattern',
        pattern: 'solid',
        fgColor: { argb: 'FF1F3A5F' }
    };

    worksheet.mergeCells('A2:D2');
    worksheet.getCell('A2').value = 'Toko: ' + selectedToko + ' | Periode: ' + selectedFrom + ' s/d ' + selectedTo;
    worksheet.getCell('A2').alignment = { horizontal: 'center', vertical: 'middle' };
    worksheet.getCell('A2').font = { italic: true };

    worksheet.mergeCells('A3:D3');
    worksheet.getCell('A3').value = 'Sumber data: ' + basketSourceInfo + ' | digabung unik berdasarkan nomor faktur';
    worksheet.getCell('A3').alignment = { horizontal: 'center', vertical: 'middle' };
    worksheet.getCell('A3').font = { italic: true, size: 9, color: { argb: 'FF64748B' } };

    const headerRow = worksheet.getRow(4);
    headerRow.values = ['Tanggal', 'Total Sales', 'Total Transaksi', 'Basket Size'];

    headerRow.eachCell(function(cell) {
        cell.font = { bold: true, color: { argb: 'FFFFFFFF' } };
        cell.alignment = { horizontal: 'center', vertical: 'middle' };
        cell.fill = {
            type: 'pattern',
            pattern: 'solid',
            fgColor: { argb: 'FF1F3A5F' }
        };
        cell.border = {
            top: { style: 'thin', color: { argb: 'FF000000' } },
            left: { style: 'thin', color: { argb: 'FF000000' } },
            bottom: { style: 'thin', color: { argb: 'FF000000' } },
            right: { style: 'thin', color: { argb: 'FF000000' } }
        };
    });

    let rowIndex = 5;
    basketData.forEach(function(item) {
        const row = worksheet.getRow(rowIndex);
        row.getCell(1).value = item.tanggal;
        row.getCell(2).value = Number(item.total_sales);
        row.getCell(3).value = Number(item.total_transaksi);
        row.getCell(4).value = Number(item.basket_size);

        row.getCell(2).numFmt = '"Rp" #,##0';
        row.getCell(3).numFmt = '#,##0';
        row.getCell(4).numFmt = '"Rp" #,##0';

        row.eachCell(function(cell) {
            cell.alignment = { horizontal: 'center', vertical: 'middle' };
            cell.border = {
                top: { style: 'thin', color: { argb: 'FF000000' } },
                left: { style: 'thin', color: { argb: 'FF000000' } },
                bottom: { style: 'thin', color: { argb: 'FF000000' } },
                right: { style: 'thin', color: { argb: 'FF000000' } }
            };
        });

        rowIndex++;
    });

    const totalRow = worksheet.getRow(rowIndex);
    totalRow.getCell(1).value = 'Grand Total';
    totalRow.getCell(2).value = Number(grandTotalSales);
    totalRow.getCell(3).value = Number(grandTotalTransaksi);
    totalRow.getCell(4).value = Number(grandTotalBasketSize);

    totalRow.getCell(2).numFmt = '"Rp" #,##0';
    totalRow.getCell(3).numFmt = '#,##0';
    totalRow.getCell(4).numFmt = '"Rp" #,##0';

    totalRow.eachCell(function(cell) {
        cell.font = { bold: true, color: { argb: 'FFFFFFFF' } };
        cell.alignment = { horizontal: 'center', vertical: 'middle' };
        cell.fill = {
            type: 'pattern',
            pattern: 'solid',
            fgColor: { argb: 'FF1F3A5F' }
        };
        cell.border = {
            top: { style: 'thin', color: { argb: 'FF000000' } },
            left: { style: 'thin', color: { argb: 'FF000000' } },
            bottom: { style: 'thin', color: { argb: 'FF000000' } },
            right: { style: 'thin', color: { argb: 'FF000000' } }
        };
    });

    rowIndex++;

    const avgRow = worksheet.getRow(rowIndex);
    avgRow.getCell(1).value = 'Rata-Rata';
    avgRow.getCell(2).value = Number(rataRataSales);
    avgRow.getCell(3).value = Number(rataRataTransaksi);
    avgRow.getCell(4).value = Number(rataRataBasketSize);

    avgRow.getCell(2).numFmt = '"Rp" #,##0';
    avgRow.getCell(3).numFmt = '#,##0';
    avgRow.getCell(4).numFmt = '"Rp" #,##0';

    avgRow.eachCell(function(cell) {
        cell.font = { bold: true, color: { argb: 'FFFFFFFF' } };
        cell.alignment = { horizontal: 'center', vertical: 'middle' };
        cell.fill = {
            type: 'pattern',
            pattern: 'solid',
            fgColor: { argb: 'FF305496' }
        };
        cell.border = {
            top: { style: 'thin', color: { argb: 'FF000000' } },
            left: { style: 'thin', color: { argb: 'FF000000' } },
            bottom: { style: 'thin', color: { argb: 'FF000000' } },
            right: { style: 'thin', color: { argb: 'FF000000' } }
        };
    });

    worksheet.eachRow(function(row) {
        row.height = 20;
    });

    const buffer = await workbook.xlsx.writeBuffer();
    saveAs(
        new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }),
        'basket_size_' + selectedToko.replace(/\s+/g, '_') + '_' + selectedFrom + '_sd_' + selectedTo + '.xlsx'
    );
}

function downloadPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('p', 'mm', 'a4');

    doc.setFontSize(14);
    doc.text("Basket Size Analytics", 14, 15);

    doc.setFontSize(10);
    doc.text("Toko: " + selectedToko, 14, 22);
    doc.text("Periode: " + selectedFrom + " s/d " + selectedTo, 14, 28);
    doc.setFontSize(8);
    doc.text("Sumber data: " + basketSourceInfo + " | unik per nomor faktur", 14, 34);

    const bodyRows = basketData.map(function(item) {
        return [
            item.tanggal,
            "Rp " + Number(item.total_sales).toLocaleString('id-ID'),
            Number(item.total_transaksi).toLocaleString('id-ID'),
            "Rp " + Number(item.basket_size).toLocaleString('id-ID')
        ];
    });

    bodyRows.push([
        "Grand Total",
        "Rp " + Number(grandTotalSales).toLocaleString('id-ID'),
        Number(grandTotalTransaksi).toLocaleString('id-ID'),
        "Rp " + Number(grandTotalBasketSize).toLocaleString('id-ID')
    ]);

    bodyRows.push([
        "Rata-Rata",
        "Rp " + Number(rataRataSales).toLocaleString('id-ID'),
        Number(rataRataTransaksi).toLocaleString('id-ID'),
        "Rp " + Number(rataRataBasketSize).toLocaleString('id-ID')
    ]);

    doc.autoTable({
        startY: 40,
        head: [["Tanggal", "Total Sales", "Total Transaksi", "Basket Size"]],
        body: bodyRows,
        theme: 'grid',
        styles: {
            halign: 'center'
        },
        headStyles: {
            fillColor: [31, 41, 55]
        }
    });

    doc.save("basket_size_" + selectedToko.replace(/\s+/g, "_") + "_" + selectedFrom + "_sd_" + selectedTo + ".pdf");
}
</script>
<?php endif; ?>

<?php endif; ?>


<script>
document.addEventListener('DOMContentLoaded', function(){
    const form = document.querySelector('form.filter');
    if (!form) return;

    form.addEventListener('submit', function(){
        const btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Memuat...';
        }
    });
});
</script>

</body>
</html>
