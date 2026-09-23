<?php
session_start();

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

if (!isset($map)) {
    require_once __DIR__ . '/map.php';
}

function e($str)
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function isPriorityRestock($kategori)
{
    return in_array(trim((string)$kategori), [
        'Very Fast Moving',
        'Fast Moving',
        'Medium Moving'
    ], true);
}

function renderOfflineAlert($store = '')
{
    $storeText = $store !== '' ? e($store) : 'Toko yang dipilih';

    echo <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Toko Offline</title>
<link rel="icon" type="image/png" href="img/srt2.png" />
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
body{
    margin:0;
    font-family:Arial,sans-serif;
    background:#f7f8fb;
}
</style>
</head>
<body>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({
        icon: 'error',
        title: 'Mohon Maaf',
        html: '{$storeText} sedang <b>offline</b>.<br>Harap menghubungi toko dan menyambungkan komputer ke internet.',
        confirmButtonText: 'Kembali',
        allowOutsideClick: false,
        allowEscapeKey: false
    }).then(() => {
        window.location.href = 'persediaan.php';
    });
});
</script>
</body>
</html>
HTML;
    exit;
}

function isStoreOnline($host, $port = 3306, $timeout = 2)
{
    $errno  = 0;
    $errstr = '';

    $fp = @fsockopen($host, (int)$port, $errno, $errstr, (float)$timeout);
    if ($fp) {
        fclose($fp);
        return true;
    }

    return false;
}

function connectStoreDb($cfg)
{
    mysqli_report(MYSQLI_REPORT_OFF);

    $host = $cfg['ip'] ?? '';
    $user = $cfg['user'] ?? '';
    $pass = $cfg['pass'] ?? '';
    $db   = $cfg['db'] ?? '';
    $port = isset($cfg['port']) ? (int)$cfg['port'] : 3306;

    if ($host === '' || $user === '' || $db === '') {
        return false;
    }

    $mysqli = mysqli_init();

    if (!$mysqli) {
        return false;
    }

    mysqli_options($mysqli, MYSQLI_OPT_CONNECT_TIMEOUT, 3);

    $connected = @mysqli_real_connect(
        $mysqli,
        $host,
        $user,
        $pass,
        $db,
        $port
    );

    if (!$connected) {
        return false;
    }

    if (!@mysqli_set_charset($mysqli, 'utf8')) {
        @mysqli_query($mysqli, "SET NAMES utf8");
        @mysqli_query($mysqli, "SET CHARACTER SET utf8");
        @mysqli_query($mysqli, "SET collation_connection = 'utf8_general_ci'");
    }

    return $mysqli;
}

$store  = (isset($_GET['store']) && $_GET['store'] !== '') ? trim($_GET['store']) : null;
$tgl1   = isset($_GET['tgl1']) ? $_GET['tgl1'] : date('Y-m-01');
$tgl2   = isset($_GET['tgl2']) ? $_GET['tgl2'] : date('Y-m-d');
$export = isset($_GET['export']) ? $_GET['export'] : '';

$searchGeneral  = isset($_GET['search_general']) ? trim($_GET['search_general']) : '';
$searchMultiple = isset($_GET['search_multiple']) ? trim($_GET['search_multiple']) : '';

$doXls = ($export === 'xls');
$doPdf = ($export === 'pdf');

$dt1 = DateTime::createFromFormat('Y-m-d', $tgl1);
$dt2 = DateTime::createFromFormat('Y-m-d', $tgl2);

if (!$dt1 || !$dt2) {
    if ($store !== null) {
        http_response_code(400);
        die("Format tanggal tidak valid (yyyy-mm-dd).");
    }

    $tgl1 = date('Y-m-01');
    $tgl2 = date('Y-m-d');

    $dt1 = new DateTime($tgl1);
    $dt2 = new DateTime($tgl2);
}

if ($dt1 > $dt2) {
    $tmp  = $tgl1;
    $tgl1 = $tgl2;
    $tgl2 = $tmp;

    $tmp2 = $dt1;
    $dt1  = $dt2;
    $dt2  = $tmp2;
}

$fromDT      = $tgl1 . ' 00:00:00';
$toExclusive = date('Y-m-d', strtotime($tgl2 . ' +1 day')) . ' 00:00:00';

if ($store === null) {
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=1024, initial-scale=0.5, minimum-scale=0.25, maximum-scale=5.0, user-scalable=yes">
<title>Persediaan Barang</title>
<link rel="icon" type="image/png" href="img/srt2.png" />
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:#f7f8fb}
.card{border:0;box-shadow:0 8px 24px rgba(0,0,0,.06);border-radius:18px}
</style>
</head>
<body>
<div class="container-fluid px-2 px-md-3 py-5">
    <h3 class="mb-4">Persediaan Barang</h3>

    <a href="dashboard.php" class="btn btn-outline-primary">
        <i class="fa-solid fa-arrow-left"></i> Kembali
    </a>

    <div class="card mt-3">
        <div class="card-body">
            <form class="row g-3" method="get">
                <div class="col-sm-3">
                    <label class="form-label">Dari Tanggal</label>
                    <input type="date" class="form-control" name="tgl1" value="<?= e($tgl1) ?>" required>
                </div>

                <div class="col-sm-3">
                    <label class="form-label">Sampai Tanggal</label>
                    <input type="date" class="form-control" name="tgl2" value="<?= e($tgl2) ?>" required>
                </div>

                <div class="col-sm-4">
                    <label class="form-label">Pilih Toko</label>
                    <select class="form-select" name="store" required>
                        <option value="">Pilih Toko</option>
                        <?php foreach ($map as $k => $cfg): ?>
                            <option value="<?= e($k) ?>"><?= e($k) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-sm-2 d-grid">
                    <label class="form-label">&nbsp;</label>
                    <button class="btn btn-primary">Lihat Data</button>
                </div>
            </form>

            <?php if (empty($map)): ?>
                <div class="text-danger mt-3">Isi dulu daftar toko di variabel <code>$map</code>.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
    exit;
}

if (!isset($map[$store])) {
    http_response_code(400);
    die("Toko tidak valid.");
}

$cfg = $map[$store];

if (!isStoreOnline($cfg['ip'], $cfg['port'] ?? 3306, 2)) {
    renderOfflineAlert($store);
}

$mysqli = connectStoreDb($cfg);

if (!$mysqli) {
    renderOfflineAlert($store);
}

@mysqli_query($mysqli, "SET SESSION sql_big_selects = 1");
@mysqli_query($mysqli, "SET SESSION max_execution_time = 30000");

/*
|--------------------------------------------------------------------------
| FAST QUERY
|--------------------------------------------------------------------------
| - Inventory hanya diagregasi satu kali sebagai base saldo.
| - Stok minus langsung dibuang di HAVING.
| - Stok 0 yang tidak pernah jual tidak ditampilkan agar ringan.
| - Salesdetail dihitung hanya sesuai periode filter.
*/
$sql = "
SELECT 
  x.ProductID,
  x.NamaProduk,
  x.Harga,
  x.SaldoTerakhir,
  x.Penjualan,
  x.RataRataPerHari,
  CASE
    WHEN x.RataRataPerHari > 10 THEN 'Very Fast Moving'
    WHEN x.RataRataPerHari >  5 THEN 'Fast Moving'
    WHEN x.RataRataPerHari >  2 THEN 'Medium Moving'
    WHEN x.RataRataPerHari >  0 THEN 'Slow Moving'
    ELSE 'Very Slow Moving'
  END AS Kategori
FROM (
    SELECT 
        saldo.productid AS ProductID,
        COALESCE(p.name, CONCAT('Product ID ', saldo.productid)) AS NamaProduk,
        COALESCE(p.salesprice1, 0) AS Harga,
        saldo.SaldoTerakhir,
        COALESCE(jual.TotalJual, 0) AS Penjualan,
        ROUND(
            COALESCE(jual.TotalJual, 0) / GREATEST(DATEDIFF(?, ?) + 1, 1),
            2
        ) AS RataRataPerHari
    FROM (
        SELECT 
            productid,
            SUM(COALESCE(invin, 0) - COALESCE(invout, 0)) AS SaldoTerakhir
        FROM inventory
        WHERE productid IS NOT NULL
          AND transdate < ?
        GROUP BY productid
        HAVING SaldoTerakhir >= 0
    ) saldo
    LEFT JOIN product p
        ON p.id = saldo.productid
    LEFT JOIN (
        SELECT 
            productid,
            SUM(COALESCE(salesqty, 0)) AS TotalJual
        FROM salesdetail
        WHERE transdate >= ?
          AND transdate < ?
        GROUP BY productid
    ) jual
        ON jual.productid = saldo.productid
    WHERE saldo.SaldoTerakhir > 0
       OR COALESCE(jual.TotalJual, 0) > 0
) x
ORDER BY 
  x.Penjualan DESC,
  x.SaldoTerakhir ASC,
  x.NamaProduk ASC
";

$rows = [];

$stmt = $mysqli->prepare($sql);

if ($stmt) {
    $stmt->bind_param('sssss', $tgl2, $tgl1, $toExclusive, $fromDT, $toExclusive);

    if ($stmt->execute()) {
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }

    $stmt->close();
}

$totalProduk     = count($rows);
$totalSaldo      = 0;
$totalPenjualan  = 0;
$totalNilaiStok  = 0;
$stokKosongAlert = [];
$alertProductIds = [];

foreach ($rows as $r) {
    $productId = (string)$r['ProductID'];
    $saldo     = (float)$r['SaldoTerakhir'];
    $harga     = (float)$r['Harga'];
    $penjualan = (float)$r['Penjualan'];
    $kategori  = (string)$r['Kategori'];

    $totalSaldo     += $saldo;
    $totalPenjualan += $penjualan;
    $totalNilaiStok += ($saldo * $harga);

    if (
        $saldo == 0 &&
        $penjualan > 0 &&
        isPriorityRestock($kategori)
    ) {
        $stokKosongAlert[] = [
            'product_id' => $productId,
            'nama'       => $r['NamaProduk'],
            'saldo'      => $saldo,
            'penjualan'  => $penjualan,
            'kategori'   => $kategori
        ];

        $alertProductIds[] = $productId;
    }
}

if ($doXls) {
    $safeStore = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $store);
    $filename  = "Persediaan_{$safeStore}_{$tgl1}_sd_{$tgl2}_" . date('Ymd_His') . ".xls";

    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Cache-Control: max-age=0, no-cache, must-revalidate, proxy-revalidate");
    header("Pragma: public");
    header("Expires: 0");

    echo "<table border='1'>";

    echo "<tr style='background:#e8eef7;font-weight:bold;'>";
    echo "<th>No</th>";
    echo "<th>ProductID</th>";
    echo "<th>Nama Produk</th>";
    echo "<th>Saldo Terakhir</th>";
    echo "<th>Harga</th>";
    echo "<th>Penjualan</th>";
    echo "<th>Rata-rata/Hari</th>";
    echo "<th>Kategori</th>";
    echo "</tr>";

    $n = 1;

    foreach ($rows as $r) {
        $productId = (string)$r['ProductID'];
        $style = '';

        if (in_array($productId, $alertProductIds, true)) {
            $style = " style='background:#FED8B1;'";
        }

        echo "<tr{$style}>";
        echo "<td>" . $n++ . "</td>";
        echo "<td>" . e($r['ProductID']) . "</td>";
        echo "<td>" . e($r['NamaProduk']) . "</td>";
        echo "<td align='right'>" . number_format((float)$r['SaldoTerakhir'], 0, ',', '.') . "</td>";
        echo "<td align='right'>" . number_format((float)$r['Harga'], 0, ',', '.') . "</td>";
        echo "<td align='right'>" . number_format((float)$r['Penjualan'], 0, ',', '.') . "</td>";
        echo "<td align='right'>" . number_format((float)$r['RataRataPerHari'], 2, ',', '.') . "</td>";
        echo "<td>" . e($r['Kategori']) . "</td>";
        echo "</tr>";
    }

    echo "<tr style='background:#cfe2ff;font-weight:bold;'>";
    echo "<td colspan='3' align='right'>TOTAL DATA: " . number_format($totalProduk, 0, ',', '.') . " PRODUK</td>";
    echo "<td align='right'>" . number_format($totalSaldo, 0, ',', '.') . "</td>";
    echo "<td align='right'>Nilai Stok: " . number_format($totalNilaiStok, 0, ',', '.') . "</td>";
    echo "<td align='right'>" . number_format($totalPenjualan, 0, ',', '.') . "</td>";
    echo "<td colspan='2'></td>";
    echo "</tr>";

    echo "</table>";

    $mysqli->close();
    exit;
}

if ($doPdf) {
    header("Content-Type: text/html; charset=utf-8");
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Persediaan Barang (Cetak)</title>
<link rel="icon" type="image/png" href="img/srt2.png" />
<style>
@page { size: A4 landscape; margin: 10mm; }
body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color:#000; }
h2 { margin: 0 0 6px 0; }
.meta { margin-bottom: 10px; }
table { border-collapse: collapse; width: 100%; }
th, td { border: 1px solid #555; padding: 4px 6px; }
thead th { background: #efefef; }
thead { display: table-header-group; }
tfoot { display: table-footer-group; }
.text-end { text-align: right; }
.muted { color:#666; }
.stok-kosong { background:#FED8B1!important; }
.toolbar { display: none; }
@media screen {
    .toolbar { display: block; margin-bottom: 10px; }
    body { background: #f5f5f5; padding: 16px; }
    .paper { background: #fff; padding: 12px; box-shadow: 0 0 8px rgba(0,0,0,.15); }
}
</style>
</head>
<body onload="setTimeout(function(){window.print()},300)">
<div class="toolbar">
    <button onclick="window.print()">Cetak / Simpan PDF</button>
    <button onclick="window.close()">Tutup</button>
</div>

<div class="paper">
    <h2>Persediaan Barang</h2>

    <div class="meta">
        Toko: <b><?= e($store) ?></b><br>
        Periode: <b><?= e($tgl1) ?> s/d <?= e($tgl2) ?></b><br>
        Dibuat: <span class="muted"><?= date('Y-m-d H:i:s') ?></span>
    </div>

    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>ProductID</th>
                <th>Nama Produk</th>
                <th class="text-end">Saldo Terakhir</th>
                <th class="text-end">Harga</th>
                <th class="text-end">Penjualan</th>
                <th class="text-end">Rata-rata/Hari</th>
                <th>Kategori</th>
            </tr>
        </thead>

        <tbody>
            <?php
            $n = 0;
            foreach ($rows as $r):
                $n++;
                $productId = (string)$r['ProductID'];
                $rowClass  = in_array($productId, $alertProductIds, true) ? 'stok-kosong' : '';
            ?>
            <tr class="<?= $rowClass ?>">
                <td><?= $n ?></td>
                <td><?= e($r['ProductID']) ?></td>
                <td><?= e($r['NamaProduk']) ?></td>
                <td class="text-end"><?= number_format((float)$r['SaldoTerakhir'], 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format((float)$r['Harga'], 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format((float)$r['Penjualan'], 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format((float)$r['RataRataPerHari'], 2, ',', '.') ?></td>
                <td><?= e($r['Kategori']) ?></td>
            </tr>
            <?php endforeach; ?>

            <?php if (empty($rows)): ?>
            <tr>
                <td colspan="8" class="muted" style="text-align:center;padding:16px">
                    Tidak ada data.
                </td>
            </tr>
            <?php endif; ?>
        </tbody>

        <tfoot>
            <tr style="font-weight:bold;background:#cfe2ff;">
                <td colspan="3" class="text-end">
                    TOTAL DATA: <?= number_format($totalProduk, 0, ',', '.') ?> PRODUK
                </td>
                <td class="text-end"><?= number_format($totalSaldo, 0, ',', '.') ?></td>
                <td class="text-end">Nilai Stok: <?= number_format($totalNilaiStok, 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format($totalPenjualan, 0, ',', '.') ?></td>
                <td colspan="2"></td>
            </tr>

            <tr>
                <td colspan="8" class="muted">
                    Generated by System <?= date('Y-m-d H:i') ?>
                </td>
            </tr>
        </tfoot>
    </table>
</div>
</body>
</html>
<?php
    $mysqli->close();
    exit;
}

$mysqli->close();

$alertJson = json_encode(
    $stokKosongAlert,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=1024, initial-scale=0.5, minimum-scale=0.25, maximum-scale=5.0, user-scalable=yes">
<title>Persediaan Barang</title>
<link rel="icon" type="image/png" href="img/srt2.png" />
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body{
    background:#f7f8fb;
}

.card{
    border:0;
    box-shadow:0 8px 24px rgba(0,0,0,.06);
    border-radius:18px;
}

.table thead th{
    position:sticky;
    top:0;
    z-index:2;
    background:#0d6efd;
    color:#fff;
    white-space:nowrap;
}

.table tfoot td{
    position:sticky;
    bottom:0;
    z-index:2;
    background:#cfe2ff!important;
}

.chip{
    display:inline-block;
    padding:.15rem .5rem;
    border-radius:999px;
    background:#f1f3f5;
    font-size:.8rem;
}

.good{
    background:#e9f7ef!important;
}

.stok-kosong{
    background:#FED8B1!important;
}

.table-responsive{
    max-height: calc(100vh - 280px);
}

.swal-wide{
    width:900px!important;
}

.swal-popup-scroll{
    max-height:360px;
    overflow:auto;
    margin-top:10px;
}

.swal-popup-table{
    width:100%;
    border-collapse:collapse;
    font-size:14px;
}

.swal-popup-table th{
    background:#0d6efd;
    color:#fff;
    padding:7px 8px;
    border:1px solid #d6dce5;
    text-align:left;
}

.swal-popup-table td{
    padding:6px 8px;
    border:1px solid #d6dce5;
    color:#111;
}

.swal-popup-table .text-end{
    text-align:right;
}
</style>
</head>

<body>
<div class="container-fluid px-2 px-md-3 py-4">
    <div class="d-flex align-items-center mb-3">
        <h3 class="me-auto mb-0">
            <i class="fa-solid fa-boxes"></i> Persediaan Barang
        </h3>

        <span class="chip">Toko: <?= e($store) ?></span>
        <span class="chip ms-2">Periode: <?= e($tgl1) ?> s/d <?= e($tgl2) ?></span>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form class="row g-3 align-items-end" method="get">
                <div class="col-sm-3">
                    <label class="form-label">Dari Tanggal</label>
                    <input type="date" class="form-control" name="tgl1" value="<?= e($tgl1) ?>" required>
                </div>

                <div class="col-sm-3">
                    <label class="form-label">Sampai Tanggal</label>
                    <input type="date" class="form-control" name="tgl2" value="<?= e($tgl2) ?>" required>
                </div>

                <div class="col-sm-3">
                    <label class="form-label">Pilih Toko</label>
                    <select class="form-select" name="store" required>
                        <option value="">------ Pilih Toko -----</option>
                        <?php foreach ($map as $k => $cfg): ?>
                            <option value="<?= e($k) ?>" <?= $k === $store ? 'selected' : '' ?>>
                                <?= e($k) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 d-flex gap-2 flex-wrap">
                    <a href="dashboard.php" class="btn btn-outline-primary">
                        <i class="fa-solid fa-arrow-left"></i> Kembali
                    </a>

                    <button class="btn btn-primary">
                        Terapkan Filter
                    </button>

                    <a class="btn btn-outline-secondary" href="?">
                        Reset
                    </a>

                    <a class="btn btn-success <?= empty($rows) ? 'disabled' : '' ?>"
                       href="?store=<?= urlencode($store) ?>&tgl1=<?= urlencode($tgl1) ?>&tgl2=<?= urlencode($tgl2) ?>&export=xls">
                        <i class="fa-solid fa-file-excel"></i> Download Excel
                    </a>

                    <a class="btn btn-danger <?= empty($rows) ? 'disabled' : '' ?>"
                       href="?store=<?= urlencode($store) ?>&tgl1=<?= urlencode($tgl1) ?>&tgl2=<?= urlencode($tgl2) ?>&export=pdf"
                       target="_blank" rel="noopener">
                        <i class="fa-solid fa-file-pdf"></i> Download PDF
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="mb-3">
        <input type="text" id="searchInput" class="form-control" placeholder="Cari ProductID atau Nama Produk...">
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-0" id="dataTable">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>ProductID</th>
                            <th>Nama Produk</th>
                            <th class="text-end">Saldo Terakhir</th>
                            <th class="text-end">Harga</th>
                            <th class="text-end">Penjualan</th>
                            <th class="text-end">Rata-rata/Hari</th>
                            <th>Kategori</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php
                    $no = 0;

                    foreach ($rows as $r):
                        $no++;

                        $productId = (string)$r['ProductID'];
                        $saldo     = (float)$r['SaldoTerakhir'];

                        if (in_array($productId, $alertProductIds, true)) {
                            $rowClass = 'stok-kosong';
                        } elseif ($saldo > 0) {
                            $rowClass = 'good';
                        } else {
                            $rowClass = '';
                        }
                    ?>
                        <tr class="<?= $rowClass ?>">
                            <td><?= $no ?></td>
                            <td><?= e($r['ProductID']) ?></td>
                            <td><?= e($r['NamaProduk']) ?></td>
                            <td class="text-end"><?= number_format((float)$r['SaldoTerakhir'], 0, ',', '.') ?></td>
                            <td class="text-end"><?= number_format((float)$r['Harga'], 0, ',', '.') ?></td>
                            <td class="text-end"><?= number_format((float)$r['Penjualan'], 0, ',', '.') ?></td>
                            <td class="text-end"><?= number_format((float)$r['RataRataPerHari'], 2, ',', '.') ?></td>
                            <td><?= e($r['Kategori']) ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">
                                Tidak ada data ditemukan.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>

                    <?php if (!empty($rows)): ?>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="3" class="text-end">
                                TOTAL DATA: <?= number_format($totalProduk, 0, ',', '.') ?> PRODUK
                            </td>

                            <td class="text-end">
                                <?= number_format($totalSaldo, 0, ',', '.') ?>
                            </td>

                            <td class="text-end">
                                Nilai Stok: <?= number_format($totalNilaiStok, 0, ',', '.') ?>
                            </td>

                            <td class="text-end">
                                <?= number_format($totalPenjualan, 0, ',', '.') ?>
                            </td>

                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const stokKosongAlert = <?= $alertJson ?: '[]' ?>;

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function formatNumberId(value, decimal = 0) {
    const num = Number(value || 0);

    return num.toLocaleString('id-ID', {
        minimumFractionDigits: decimal,
        maximumFractionDigits: decimal
    });
}

const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('input', function () {
        const q = this.value.toLowerCase();

        document.querySelectorAll('#dataTable tbody tr').forEach(function(tr) {
            tr.style.display = tr.innerText.toLowerCase().includes(q) ? '' : 'none';
        });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    if (!stokKosongAlert || stokKosongAlert.length === 0) {
        return;
    }

   let html = `
    <div>
        <p style="margin-bottom:12px;font-size:16px;text-align:center">
            Silahkan di cek persediaan anda !
        </p>

        <div style="text-align:left">
            <div class="swal-popup-scroll">
                <table class="swal-popup-table">
                    <thead>
                        <tr>
                            <th>Nama Produk</th>
                            <th class="text-end">Saldo Terakhir</th>
                            <th class="text-end">Penjualan</th>
                            <th>Kategori</th>
                        </tr>
                    </thead>
                    <tbody>
    `;

    stokKosongAlert.forEach(function (item) {
        html += `
            <tr>
                <td>${escapeHtml(item.nama)}</td>
                <td class="text-end">${formatNumberId(item.saldo, 0)}</td>
                <td class="text-end">${formatNumberId(item.penjualan, 0)}</td>
                <td>${escapeHtml(item.kategori)}</td>
            </tr>
        `;
    });

    html += `
                    </tbody>
                </table>
            </div>
        </div>
    `;

    Swal.fire({
        icon: 'warning',
        title: 'Persediaan Kosong',
        html: html,
        customClass: {
            popup: 'swal-wide'
        },
        confirmButtonText: 'Baik, Saya Cek',
        allowOutsideClick: true,
        allowEscapeKey: true
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
