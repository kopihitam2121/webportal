<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/map.php';

$store    = $_GET['store'] ?? '';
$tgl1     = $_GET['tgl1'] ?? date('Y-m-01');
$tgl2     = $_GET['tgl2'] ?? date('Y-m-d');
$q        = trim((string)($_GET['q'] ?? ''));
$download = isset($_GET['download']);
$rows     = [];
$errorMsg = '';

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Support angka model database biasa: 1227.67
 * dan angka format Indonesia: 1.227,67
 */
function to_float_id($value)
{
    if ($value === null || $value === '') return 0;

    if (is_numeric($value)) {
        return (float)$value;
    }

    $value = trim((string)$value);
    $value = str_replace(['Rp', ' '], '', $value);

    // Jika format Indonesia: 1.227,67
    if (preg_match('/,\d{1,}$/', $value)) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } else {
        $value = str_replace(',', '', $value);
    }

    return (float)$value;
}

function fmt_id($value, $maxDecimal = 2)
{
    $num = to_float_id($value);
    $decimal = (abs($num - round($num)) > 0.000001) ? $maxDecimal : 0;

    return number_format($num, $decimal, ',', '.');
}

function fmt_rp($value)
{
    $num = to_float_id($value);
    $decimal = (abs($num - round($num)) > 0.000001) ? 2 : 0;

    return 'Rp ' . number_format($num, $decimal, ',', '.');
}

function build_url(array $extra = [])
{
    $base = [
        'store' => $_GET['store'] ?? '',
        'tgl1'  => $_GET['tgl1'] ?? date('Y-m-01'),
        'tgl2'  => $_GET['tgl2'] ?? date('Y-m-d'),
        'q'     => $_GET['q'] ?? '',
    ];

    $params = array_merge($base, $extra);

    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) unset($params[$k]);
    }

    return '?' . http_build_query($params);
}

if ($store && isset($map[$store])) {
    $cfg = $map[$store];

    $mysqli = @mysqli_connect($cfg['ip'], $cfg['user'], $cfg['pass'], $cfg['db']);

    if (!$mysqli) {
        $errorMsg = 'Toko ' . $store . ' offline atau koneksi database gagal.';
    } else {
        mysqli_set_charset($mysqli, "utf8");

        /*
         * FIX NOMINAL:
         * Data inventory DIAGREGASI DULU berdasarkan No Faktur + ProductID + Tanggal + User.
         * Tujuannya supaya join ke inventoryoutdetail tidak menggandakan baris inventory.
         * Query lama menghitung langsung setelah join, sehingga jika detail/memo dobel,
         * Qty/Nominal bisa terlihat aneh atau muncul baris dobel.
         */
        $sql = "
            SELECT
                ia.NoFaktur,
                ia.product_id,
                COALESCE(p.name, ia.product_id) AS NamaProduk,
                ia.Tanggal,
                ia.QtyPemakaian,
                ia.NominalPemakaian,
                ia.usercreate,
                COALESCE(io.memo, '') AS memo,
                COALESCE(saldo.SaldoTerakhir, 0) AS SaldoTerakhir
            FROM (
                SELECT
                    i.transid AS NoFaktur,
                    i.productid AS product_id,
                    DATE(i.transdate) AS Tanggal,
                    i.usercreate,
                    SUM(COALESCE(i.invout, 0)) AS QtyPemakaian,
                    SUM(COALESCE(i.invout, 0) * COALESCE(i.invvalue, 0)) AS NominalPemakaian
                FROM inventory i
                WHERE i.transdate >= ?
                  AND i.transdate < DATE_ADD(?, INTERVAL 1 DAY)
                  AND i.transtype = 21
                GROUP BY
                    i.transid,
                    i.productid,
                    DATE(i.transdate),
                    i.usercreate
            ) ia
            LEFT JOIN product p
                ON p.id = ia.product_id
            LEFT JOIN (
                SELECT
                    transid,
                    productid,
                    GROUP_CONCAT(DISTINCT NULLIF(TRIM(memo), '') ORDER BY memo SEPARATOR ' | ') AS memo
                FROM inventoryoutdetail
                GROUP BY transid, productid
            ) io
                ON io.transid = ia.NoFaktur
               AND io.productid = ia.product_id
            LEFT JOIN (
                SELECT
                    productid,
                    SUM(COALESCE(invin, 0) - COALESCE(invout, 0)) AS SaldoTerakhir
                FROM inventory
                GROUP BY productid
            ) saldo
                ON saldo.productid = ia.product_id
            WHERE 1 = 1
        ";

        $types = 'ss';
        $params = [$tgl1, $tgl2];

        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql .= "
                AND (
                    ia.NoFaktur LIKE ?
                    OR ia.product_id LIKE ?
                    OR COALESCE(p.name, '') LIKE ?
                    OR COALESCE(ia.usercreate, '') LIKE ?
                    OR COALESCE(io.memo, '') LIKE ?
                )
            ";
            $types .= 'sssss';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $sql .= "
            ORDER BY ia.Tanggal ASC, ia.NoFaktur ASC, NamaProduk ASC
        ";

        $stmt = $mysqli->prepare($sql);

        if (!$stmt) {
            $errorMsg = 'Query gagal disiapkan: ' . $mysqli->error;
        } else {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();

            $res = $stmt->get_result();

            while ($r = $res->fetch_assoc()) {
                $r['QtyPemakaian']     = to_float_id($r['QtyPemakaian']);
                $r['NominalPemakaian'] = to_float_id($r['NominalPemakaian']);
                $r['SaldoTerakhir']    = to_float_id($r['SaldoTerakhir']);

                $rows[] = $r;
            }

            $stmt->close();
        }

        $mysqli->close();
    }
}

/* ================= EXPORT EXCEL PHPSPREADSHEET ================= */
if ($download) {
    if (!$store || !isset($map[$store])) {
        die("Store belum dipilih atau tidak valid.");
    }

    if ($errorMsg !== '') {
        die($errorMsg);
    }

    $autoload = __DIR__ . '/vendor/autoload.php';

    if (!file_exists($autoload)) {
        die("PhpSpreadsheet belum terinstall. Jalankan: composer require phpoffice/phpspreadsheet");
    }

    require_once $autoload;

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Pemakaian Barang');

    $numberFormat   = '[$-421]#,##0.##';
    $currencyFormat = '[$Rp-421] #,##0.##';

    $sheet->mergeCells('A1:J1');
    $sheet->setCellValue('A1', 'LAPORAN PEMAKAIAN BARANG');

    $sheet->mergeCells('A2:J2');
    $title2 = 'Toko : ' . $store . ' | Periode : ' . $tgl1 . ' s/d ' . $tgl2;
    if ($q !== '') $title2 .= ' | Search : ' . $q;
    $sheet->setCellValue('A2', $title2);

    $sheet->getStyle('A1:A2')->getFont()->setBold(true);
    $sheet->getStyle('A1:A2')->getAlignment()->setHorizontal(
        \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER
    );
    $sheet->getStyle('A1')->getFont()->setSize(16);

    $headers = [
        'No',
        'Tanggal',
        'No Faktur',
        'ID',
        'Nama Produk',
        'Qty',
        'Nominal',
        'Pembuat',
        'Keterangan',
        'Saldo'
    ];

    $startRow = 4;
    $col = 'A';

    foreach ($headers as $header) {
        $sheet->setCellValue($col . $startRow, $header);
        $col++;
    }

    $sheet->getStyle('A4:J4')->getFont()->setBold(true);
    $sheet->getStyle('A4:J4')->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFE9ECEF');

    $sheet->getStyle('A4:J4')->getAlignment()->setHorizontal(
        \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER
    );

    $sheet->freezePane('A5');
    $sheet->setAutoFilter('A4:J4');

    $rowExcel = 5;

    if (!$rows) {
        $sheet->mergeCells('A5:J5');
        $sheet->setCellValue('A5', 'Tidak ada data');
        $sheet->getStyle('A5')->getAlignment()->setHorizontal(
            \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER
        );
    } else {
        $no = 0;
        $lastTanggal = '';
        $subQty = 0;
        $subNom = 0;
        $grandQty = 0;
        $grandNom = 0;

        foreach ($rows as $r) {
            if ($lastTanggal !== '' && $lastTanggal !== $r['Tanggal']) {
                $sheet->mergeCells('A' . $rowExcel . ':E' . $rowExcel);
                $sheet->setCellValue('A' . $rowExcel, 'TOTAL ' . $lastTanggal);
                $sheet->setCellValue('F' . $rowExcel, $subQty);
                $sheet->setCellValue('G' . $rowExcel, $subNom);

                $sheet->getStyle('A' . $rowExcel . ':J' . $rowExcel)->getFont()->setBold(true);
                $sheet->getStyle('A' . $rowExcel . ':J' . $rowExcel)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFF2F2F2');

                $sheet->getStyle('A' . $rowExcel)->getAlignment()->setHorizontal(
                    \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT
                );

                $sheet->getStyle('F' . $rowExcel)->getNumberFormat()->setFormatCode($numberFormat);
                $sheet->getStyle('G' . $rowExcel)->getNumberFormat()->setFormatCode($currencyFormat);

                $rowExcel++;
                $subQty = 0;
                $subNom = 0;
            }

            $no++;
            $lastTanggal = $r['Tanggal'];

            $qty     = to_float_id($r['QtyPemakaian']);
            $nominal = to_float_id($r['NominalPemakaian']);
            $saldo   = to_float_id($r['SaldoTerakhir']);

            $subQty += $qty;
            $subNom += $nominal;
            $grandQty += $qty;
            $grandNom += $nominal;

            $sheet->setCellValue('A' . $rowExcel, $no);
            $sheet->setCellValue('B' . $rowExcel, $r['Tanggal']);
            $sheet->setCellValueExplicit('C' . $rowExcel, $r['NoFaktur'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('D' . $rowExcel, $r['product_id'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('E' . $rowExcel, $r['NamaProduk']);
            $sheet->setCellValue('F' . $rowExcel, $qty);
            $sheet->setCellValue('G' . $rowExcel, $nominal);
            $sheet->setCellValue('H' . $rowExcel, $r['usercreate']);
            $sheet->setCellValue('I' . $rowExcel, $r['memo']);
            $sheet->setCellValue('J' . $rowExcel, $saldo);

            $sheet->getStyle('F' . $rowExcel)->getNumberFormat()->setFormatCode($numberFormat);
            $sheet->getStyle('G' . $rowExcel)->getNumberFormat()->setFormatCode($currencyFormat);
            $sheet->getStyle('J' . $rowExcel)->getNumberFormat()->setFormatCode($numberFormat);

            $rowExcel++;
        }

        // Subtotal terakhir
        $sheet->mergeCells('A' . $rowExcel . ':E' . $rowExcel);
        $sheet->setCellValue('A' . $rowExcel, 'TOTAL ' . $lastTanggal);
        $sheet->setCellValue('F' . $rowExcel, $subQty);
        $sheet->setCellValue('G' . $rowExcel, $subNom);

        $sheet->getStyle('A' . $rowExcel . ':J' . $rowExcel)->getFont()->setBold(true);
        $sheet->getStyle('A' . $rowExcel . ':J' . $rowExcel)->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFF2F2F2');

        $sheet->getStyle('A' . $rowExcel)->getAlignment()->setHorizontal(
            \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT
        );

        $sheet->getStyle('F' . $rowExcel)->getNumberFormat()->setFormatCode($numberFormat);
        $sheet->getStyle('G' . $rowExcel)->getNumberFormat()->setFormatCode($currencyFormat);

        $rowExcel++;

        // Grand total
        $sheet->mergeCells('A' . $rowExcel . ':E' . $rowExcel);
        $sheet->setCellValue('A' . $rowExcel, 'GRAND TOTAL');
        $sheet->setCellValue('F' . $rowExcel, $grandQty);
        $sheet->setCellValue('G' . $rowExcel, $grandNom);

        $sheet->getStyle('A' . $rowExcel . ':J' . $rowExcel)->getFont()->setBold(true);
        $sheet->getStyle('A' . $rowExcel . ':J' . $rowExcel)->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFDFF0D8');

        $sheet->getStyle('A' . $rowExcel)->getAlignment()->setHorizontal(
            \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT
        );

        $sheet->getStyle('F' . $rowExcel)->getNumberFormat()->setFormatCode($numberFormat);
        $sheet->getStyle('G' . $rowExcel)->getNumberFormat()->setFormatCode($currencyFormat);
    }

    $lastDataRow = max($rowExcel, 5);

    $sheet->getStyle('A4:J' . $lastDataRow)->getBorders()->getAllBorders()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

    $sheet->getStyle('A4:J' . $lastDataRow)->getAlignment()->setVertical(
        \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
    );

    $sheet->getStyle('F5:G' . $lastDataRow)->getAlignment()->setHorizontal(
        \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT
    );

    $sheet->getStyle('J5:J' . $lastDataRow)->getAlignment()->setHorizontal(
        \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT
    );

    foreach (range('A', 'J') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    $safeStore = preg_replace('/[^A-Za-z0-9_\-]/', '_', $store);
    $safeSearch = $q !== '' ? '_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $q) : '';
    $filename = "pemakaian_barang_{$safeStore}_{$tgl1}_sd_{$tgl2}{$safeSearch}.xlsx";

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}

$downloadUrl = '';
if ($store) {
    $downloadUrl = build_url(['download' => 1]);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Pemakaian Barang</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body {
    background: #f7f8fb;
}

.app-header {
    position: sticky;
    top: 0;
    z-index: 1030;
    background: #f7f8fb;
    padding: 14px 0;
    border-bottom: 1px solid #e5e7eb;
}

.card {
    border: 0;
    box-shadow: 0 6px 18px rgba(0,0,0,.08);
}

.table-wrap {
    max-height: calc(100vh - 285px);
    overflow: auto;
}

.table thead th {
    background: #0d6efd;
    color: #fff;
    position: sticky;
    top: 0;
    z-index: 10;
    white-space: nowrap;
    vertical-align: middle;
}

.table td {
    vertical-align: middle;
    white-space: nowrap;
}

.table td:nth-child(5),
.table td:nth-child(9) {
    white-space: normal;
    min-width: 220px;
}

.header-title {
    margin: 0;
    font-weight: 700;
}

.header-subtitle {
    font-size: 13px;
    color: #6c757d;
}

.search-hint {
    font-size: 12px;
    color: #6c757d;
    margin-top: 6px;
}

.live-search-wrap {
    border: 1px solid #dbeafe;
    background: linear-gradient(135deg, #eff6ff, #ffffff);
    border-radius: 14px;
    padding: 12px;
}

.search-result-badge {
    display: none;
    align-items: center;
    gap: 6px;
    border-radius: 999px;
    padding: 7px 12px;
    background: #0d6efd;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

.table tbody tr[data-row="data"] {
    transition: background-color .18s ease, color .18s ease, transform .18s ease;
}

.table tbody tr.search-hit > td {
    background: #0d6efd !important;
    color: #ffffff !important;
    font-weight: 700;
    border-color: #9ec5fe !important;
}

.table tbody tr.search-hit:hover > td {
    background: #0b5ed7 !important;
    color: #ffffff !important;
}

.table tbody tr.search-hit.search-pulse > td {
    animation: searchBluePulse .85s ease-in-out 1;
}

@keyframes searchBluePulse {
    0% {
        background: #9ec5fe;
        color: #052c65;
    }
    50% {
        background: #0d6efd;
        color: #ffffff;
    }
    100% {
        background: #0d6efd;
        color: #ffffff;
    }
}

.table tbody tr[data-row="summary"] td {
    position: relative;
    z-index: 1;
}

</style>
</head>

<body>

<div class="container-fluid px-4">

    <div class="app-header">
        <div class="d-flex justify-content-between align-items-center gap-3">
            <div>
                <h3 class="header-title">Laporan Pemakaian Barang</h3>
                <div class="header-subtitle">
                    Periode: <?=h($tgl1)?> s/d <?=h($tgl2)?>
                    <?php if ($store): ?>
                        | Toko: <?=h($store)?>
                    <?php endif; ?>
                    <?php if ($q !== ''): ?>
                        | Search: <?=h($q)?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="d-flex gap-2">
                <?php if ($store): ?>
                    <a class="btn btn-success" href="<?=h($downloadUrl)?>">
                        Download Excel
                    </a>
                <?php endif; ?>

                <a href="dashboard.php" class="btn btn-secondary">
                    Kembali
                </a>
            </div>
        </div>
    </div>

    <div class="card my-3">
        <div class="card-body">
            <form class="row g-3 align-items-end" method="get">
                <div class="col-md-2">
                    <label class="form-label">Dari</label>
                    <input type="date" name="tgl1" class="form-control" value="<?=h($tgl1)?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Sampai</label>
                    <input type="date" name="tgl2" class="form-control" value="<?=h($tgl2)?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Toko</label>
                    <select name="store" class="form-select">
                        <option value="">- Pilih -</option>
                        <?php foreach ($map as $k => $v): ?>
                            <option value="<?=h($k)?>" <?=$k == $store ? 'selected' : ''?>>
                                <?=h($k)?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Search Cepat</label>
                    <div class="live-search-wrap">
                        <div class="d-flex gap-2 align-items-center">
                            <input
                                type="text"
                                name="q"
                                id="searchTable"
                                class="form-control"
                                value="<?=h($q)?>"
                                autocomplete="off"
                                placeholder="Cari faktur, ID, produk, pembuat, memo"
                            >
                            <span id="searchResultBadge" class="search-result-badge">0 ditemukan</span>
                        </div>
                        <div class="search-hint">
                            
                        </div>
                    </div>
                </div>

                <div class="col-md-2 d-grid gap-2">
                    <button class="btn btn-primary">
                        Terapkan
                    </button>
                    <?php if ($q !== ''): ?>
                        <a class="btn btn-outline-secondary" href="<?=h(build_url(['q' => '']))?>">Reset Search</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php if ($errorMsg !== ''): ?>
        <div class="alert alert-danger">
            <?=h($errorMsg)?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="table-wrap">
            <table class="table table-hover table-bordered mb-0">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Tanggal</th>
                        <th>No Faktur</th>
                        <th>ID</th>
                        <th>Nama Produk</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Nominal</th>
                        <th>Pembuat</th>
                        <th>Keterangan</th>
                        <th class="text-end">Saldo</th>
                    </tr>
                </thead>

                <tbody id="pemakaianTbody">
                <?php
                if (!$store) {
                    echo "<tr><td colspan='10' class='text-center text-muted py-4'>Silakan pilih filter</td></tr>";
                } elseif ($errorMsg !== '') {
                    echo "<tr><td colspan='10' class='text-center text-danger py-4'>" . h($errorMsg) . "</td></tr>";
                } elseif (!$rows) {
                    echo "<tr><td colspan='10' class='text-center text-muted py-4'>Tidak ada data</td></tr>";
                } else {
                    $no = 0;
                    $lastTanggal = '';
                    $subQty = 0;
                    $subNom = 0;
                    $grandQty = 0;
                    $grandNom = 0;

                    foreach ($rows as $r) {
                        if ($lastTanggal != '' && $lastTanggal != $r['Tanggal']) {
                            echo "
                            <tr class='table-secondary fw-bold' data-row='summary'>
                                <td colspan='5' class='text-end'>TOTAL " . h($lastTanggal) . "</td>
                                <td class='text-end'>" . fmt_id($subQty) . "</td>
                                <td class='text-end'>" . fmt_rp($subNom) . "</td>
                                <td colspan='3'></td>
                            </tr>";

                            $subQty = 0;
                            $subNom = 0;
                        }

                        $no++;
                        $lastTanggal = $r['Tanggal'];

                        $qty     = to_float_id($r['QtyPemakaian']);
                        $nominal = to_float_id($r['NominalPemakaian']);

                        $subQty += $qty;
                        $subNom += $nominal;
                        $grandQty += $qty;
                        $grandNom += $nominal;
                        ?>
                        <tr data-row="data">
                            <td><?=h($no)?></td>
                            <td><?=h($r['Tanggal'])?></td>
                            <td><?=h($r['NoFaktur'])?></td>
                            <td><?=h($r['product_id'])?></td>
                            <td><?=h($r['NamaProduk'])?></td>
                            <td class="text-end"><?=fmt_id($r['QtyPemakaian'])?></td>
                            <td class="text-end"><?=fmt_rp($r['NominalPemakaian'])?></td>
                            <td><?=h($r['usercreate'])?></td>
                            <td><?=h($r['memo'])?></td>
                            <td class="text-end"><?=fmt_id($r['SaldoTerakhir'])?></td>
                        </tr>
                        <?php
                    }

                    echo "
                    <tr class='table-secondary fw-bold' data-row='summary'>
                        <td colspan='5' class='text-end'>TOTAL " . h($lastTanggal) . "</td>
                        <td class='text-end'>" . fmt_id($subQty) . "</td>
                        <td class='text-end'>" . fmt_rp($subNom) . "</td>
                        <td colspan='3'></td>
                    </tr>";

                    echo "
                    <tr class='table-success fw-bold' data-row='summary'>
                        <td colspan='5' class='text-end'>GRAND TOTAL</td>
                        <td class='text-end'>" . fmt_id($grandQty) . "</td>
                        <td class='text-end'>" . fmt_rp($grandNom) . "</td>
                        <td colspan='3'></td>
                    </tr>";
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>

</div>


<script>
(function () {
    const searchInput = document.getElementById('searchTable');
    const tbody = document.getElementById('pemakaianTbody');
    const badge = document.getElementById('searchResultBadge');

    if (!searchInput || !tbody) return;

    const dataRows = Array.from(tbody.querySelectorAll('tr[data-row="data"]'));
    const summaryRows = Array.from(tbody.querySelectorAll('tr[data-row="summary"]'));

    dataRows.forEach((row, index) => {
        row.dataset.originalIndex = String(index);
    });

    function normalize(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/\s+/g, ' ')
            .trim();
    }

    function resetPulse(rows) {
        rows.forEach(row => {
            row.classList.remove('search-pulse');
            void row.offsetWidth;
            row.classList.add('search-pulse');
        });
    }

    function updateBadge(count, keyword) {
        if (!badge) return;

        if (!keyword) {
            badge.style.display = 'none';
            badge.textContent = '0 ditemukan';
            return;
        }

        badge.style.display = 'inline-flex';
        badge.textContent = count + ' ditemukan';
    }

    function reorderRows(keyword) {
        const fragment = document.createDocumentFragment();
        const hits = [];
        const others = [];

        dataRows.forEach(row => {
            row.classList.remove('search-hit', 'search-pulse');

            const text = normalize(row.innerText);
            if (keyword && text.includes(keyword)) {
                row.classList.add('search-hit');
                hits.push(row);
            } else {
                others.push(row);
            }
        });

        if (keyword) {
            hits.forEach(row => fragment.appendChild(row));
            others.forEach(row => fragment.appendChild(row));
            summaryRows.forEach(row => fragment.appendChild(row));
            tbody.appendChild(fragment);

            resetPulse(hits.slice(0, 20));
            updateBadge(hits.length, keyword);

            if (hits.length > 0) {
                const tableWrap = tbody.closest('.table-wrap');
                if (tableWrap) tableWrap.scrollTop = 0;
            }
        } else {
            dataRows
                .sort((a, b) => Number(a.dataset.originalIndex || 0) - Number(b.dataset.originalIndex || 0))
                .forEach(row => fragment.appendChild(row));
            summaryRows.forEach(row => fragment.appendChild(row));
            tbody.appendChild(fragment);
            updateBadge(0, '');
        }
    }

    let searchTimer = null;

    function applySearch() {
        const keyword = normalize(searchInput.value);
        reorderRows(keyword);
    }

    searchInput.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(applySearch, 80);
    });

    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            applySearch();
        }
    });

    if (normalize(searchInput.value) !== '') {
        setTimeout(applySearch, 150);
    }
})();
</script>

</body>
</html>
