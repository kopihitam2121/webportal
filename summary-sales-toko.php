<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

date_default_timezone_set('Asia/Jakarta');
setlocale(LC_TIME, 'id_ID.utf8', 'id_ID', 'Indonesian');

$username = strtolower(trim($_SESSION['username']));
$role     = strtoupper($_SESSION['role'] ?? '');

if (file_exists(__DIR__ . '/db.php')) {
    require_once __DIR__ . '/db.php';
}

// ====================
// MASTER TOKO
// ====================
$all_toko_list = [
    "papimart1" => "PAPIMART T2E GATE E3",
    "papimart2" => "PAPIMART T2E GATE E4",
    "papimart3" => "PAPIMART T2E GATE E5",
    "abyd2"     => "AMBIL BEKAL YUK D2",
    "abyd6"     => "AMBIL BEKAL YUK D6",
    "pod1"      => "POINT ONE D1",
    "pod3"      => "POINT ONE D3",
    "pod5"      => "POINT ONE D5",
    "pod7"      => "POINT ONE D7",
    "lst1c"     => "LATTE STORY T1C",
    "urbanb4"   => "URBAN B4",
    "urbanb6"   => "URBAN B6",
    "urbanb7"   => "URBAN B7",
    "pcb5"      => "PAPI COFFEE B5",
    "lst2f"     => "LATTE STORY T2F",
    "lst2e"     => "LATTE STORY T2E",
    "pmbim"     => "PAPIMART BIM",
    "mmart"     => "MMART TERMINAL 3",
    "pmg18"     => "PAPIMART GATE 18",
];

// ====================
// USER FULL AKSES LIHAT SEMUA TOKO
// ====================
$full_access_users = [
    'wira',
    'prengkuh',
    'wahid',
    'alfia',
    'azik',
     'muslih',
    'putri',
    'admin1',
    'whina',
    'ratna',
    'ujang'

];

// ====================
// USER YANG BOLEH EDIT
// ====================
$edit_access_users = [
    'wira',
    'alfia',
    'azik',
    'putri',
    'haris',
    'admin1',
    'ujang',
    'mustaqim',
    'jesen',
    'lusiah',
    'adit',
    'dede',
    'devi',
    'yan',
    'wulan',
    'yanto'
];

// ====================
// HAK EDIT
// ====================
$canEdit = in_array($username, $edit_access_users, true) || $role === 'SUPER ADMIN';

// ====================
// RBAC USER -> TOKO
// ====================
$user_access = [
    'mustaqim' => ['urbanb4', 'urbanb6', 'urbanb7', 'pcb5', 'lst1c', 'lst2e', 'lst2f'],
    'umam' => ['lst2e', 'lst2f'],
    'yanto' => ['lst1c'],
    'yan' => ['pmbim'],
    'wulan' => ['pmbim'],
    'dede' => ['abyd2', 'abyd6', 'pod7'],
    'devi' => ['pod1', 'pod3', 'pod5'],
    'haris' => ['papimart1', 'papimart2', 'papimart3', 'pod1', 'pod3', 'pod5', 'pod7', 'abyd2', 'abyd6', 'mmart', 'pmg18'],
    'jesen' => ['pcb5', 'urbanb4', 'urbanb6', 'urbanb7'],
    'lusiah' => ['pcb5', 'urbanb4', 'urbanb6', 'urbanb7'],
    'adit' => ['mmart', 'pmg18'],
    'admin2' => ['papimart1', 'papimart2', 'papimart3'],
];

// ====================
// TENTUKAN TOKO YANG BOLEH DILIHAT USER
// ====================
if ($role === 'SUPER ADMIN' || in_array($username, $full_access_users, true)) {
    $allowed_store_codes = array_keys($all_toko_list);
} else {
    $allowed_store_codes = $user_access[$username] ?? [];
}

$hasAccess = !empty($allowed_store_codes);

// Filter toko berdasarkan hak akses
$toko_list = [];
if ($hasAccess) {
    foreach ($allowed_store_codes as $kode) {
        if (isset($all_toko_list[$kode])) {
            $toko_list[$kode] = $all_toko_list[$kode];
        }
    }
}

// ====================
// UPDATE INLINE AJAX (AMAN)
// ====================
if ($hasAccess && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['col'], $_POST['val'], $_POST['pk_value'])) {
    if (!$canEdit) {
        echo "ERROR: Anda tidak punya hak edit";
        exit;
    }

    $col = trim($_POST['col']);
    $tanggal = mysqli_real_escape_string($conn, $_POST['pk_value']);
    $valRaw = trim($_POST['val']);
    $valClean = preg_replace('/[^0-9\-\.]/', '', $valRaw);

    // Validasi kolom wajib ada di toko yang sedang boleh diakses user
    if (!array_key_exists($col, $toko_list)) {
        echo "ERROR: Kolom tidak diizinkan";
        exit;
    }

    if ($valClean === '') {
        $q = "UPDATE omset_detail SET `$col`=NULL WHERE tanggal='$tanggal'";
        echo mysqli_query($conn, $q) ? "OK" : "ERROR: " . mysqli_error($conn);
        exit;
    }

    if (!is_numeric($valClean)) {
        echo "ERROR: bukan angka";
        exit;
    }

    $val = intval(round(floatval($valClean)));

    $cek = mysqli_query($conn, "SELECT * FROM omset_detail WHERE tanggal='$tanggal' LIMIT 1");
    $q = ($cek && mysqli_num_rows($cek) > 0)
        ? "UPDATE omset_detail SET `$col`='$val' WHERE tanggal='$tanggal' LIMIT 1"
        : "INSERT INTO omset_detail (tanggal, `$col`) VALUES ('$tanggal', '$val')";

    echo mysqli_query($conn, $q) ? "OK" : "ERROR: " . mysqli_error($conn);
    exit;
}

// ====================
// DROPDOWN BULAN
// ====================
// ====================
// LOGIC BULAN AKTIF H+1
// Tanggal 1 masih tampil bulan sebelumnya.
// Tanggal 2 dan seterusnya baru tampil bulan berjalan.
// Contoh: 1 Mei => April, 2 Mei => Mei
// ====================
$todayDay = (int)date('d');

if ($todayDay === 1) {
    $currentMonth = date('Y-m', strtotime('first day of previous month'));
} else {
    $currentMonth = date('Y-m');
}

$monthList = [];
$qMonth = mysqli_query($conn, "
    SELECT DISTINCT DATE_FORMAT(tanggal, '%Y-%m') AS bln
    FROM omset_detail
");
while ($r = mysqli_fetch_assoc($qMonth)) {
    $monthList[] = $r['bln'];
}

for ($i = -12; $i <= 12; $i++) {
    $monthList[] = date('Y-m', strtotime("$currentMonth-01 $i month"));
}

$monthList = array_unique($monthList);
rsort($monthList);

$selectedMonth = $_GET['month'] ?? $currentMonth;
if (!in_array($selectedMonth, $monthList, true)) {
    $selectedMonth = $currentMonth;
}

list($year, $month) = explode('-', $selectedMonth);
$start = date('Y-m-01', strtotime("$year-$month-01"));
$end   = date('Y-m-t', strtotime("$year-$month-01"));

// ====================
// AMBIL DATA OMSET
// ====================
$data = [];
if ($hasAccess) {
    $q = "SELECT * FROM omset_detail WHERE tanggal BETWEEN '$start' AND '$end' ORDER BY tanggal ASC";
    $res = mysqli_query($conn, $q);
    while ($r = mysqli_fetch_assoc($res)) {
        $data[$r['tanggal']] = $r;
    }
}

$period = new DatePeriod(
    new DateTime($start),
    new DateInterval('P1D'),
    (new DateTime($end))->modify('+1 day')
);

$finalData = [];
if ($hasAccess) {
    foreach ($period as $dt) {
        $t = $dt->format("Y-m-d");
        $base = ['tanggal' => $t];
        foreach (array_keys($toko_list) as $kode) {
            $base[$kode] = $data[$t][$kode] ?? "";
        }
        $finalData[$t] = $base;
    }
}

// ====================
// HITUNG TOTAL / AVG / TARGET / ACHIEVEMENT
// ====================
$totals = array_fill_keys(array_keys($toko_list), 0);
$countFilled = array_fill_keys(array_keys($toko_list), 0);
$grandPerDay = [];

foreach ($finalData as $tanggal => $row) {
    $rowTotal = 0;
    foreach ($toko_list as $k => $_) {
        $v = $row[$k] ?? "";
        if (is_numeric($v)) {
            $totals[$k] += intval($v);
            if ($v > 0) $countFilled[$k]++;
            $rowTotal += intval($v);
        }
    }
    $grandPerDay[$tanggal] = $rowTotal;
}

$totalAll = array_sum($totals);

$avg = [];
foreach ($totals as $k => $v) {
    $avg[$k] = ($countFilled[$k] > 0) ? round($v / $countFilled[$k]) : 0;
}

$targets = array_fill_keys(array_keys($toko_list), 0);

$ym = mysqli_real_escape_string($conn, $selectedMonth);
$sqlTarget = "SELECT tenant, target FROM retail_target WHERE ym = '$ym'";
$resTarget = mysqli_query($conn, $sqlTarget);

if ($hasAccess && $resTarget && mysqli_num_rows($resTarget) > 0) {
    while ($rowTarget = mysqli_fetch_assoc($resTarget)) {
        $tenantName = $rowTarget['tenant'];
        $targetVal  = $rowTarget['target'];

        if (is_numeric($targetVal)) {
            $kode = array_search($tenantName, $toko_list, true);
            if ($kode !== false) {
                $targets[$kode] = (int)$targetVal;
            }
        }
    }
}

$ach = [];
foreach ($toko_list as $k => $_) {
    $ach[$k] = ($targets[$k] > 0) ? round(($totals[$k] / $targets[$k]) * 100, 1) : 0;
}

// ====================
// TARGET PER DAY
// ====================
$daysInMonth = (int)date('t', strtotime($selectedMonth . '-01'));

$targetDaily = [];
foreach ($targets as $k => $v) {
    $targetDaily[$k] = ($daysInMonth > 0) ? round($v / $daysInMonth) : 0;
}

$grandTargetDaily = array_sum($targetDaily);
$grandTotalAll = array_sum($totals);
$grandAvg      = (count(array_filter($grandPerDay)) > 0) ? round($grandTotalAll / count(array_filter($grandPerDay))) : 0;
$grandTarget   = array_sum($targets);
$grandAch      = $grandTarget > 0 ? round(($grandTotalAll / $grandTarget) * 100, 1) : 0;

// =======================
// =======================
// EXPORT EXCEL
// =======================
if ($hasAccess && isset($_GET['export']) && $_GET['export'] === 'excel') {
    require __DIR__ . '/vendor/autoload.php';

    if (ob_get_length()) {
        ob_end_clean();
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Sales ' . $selectedMonth);

    $colorTosca      = '009688';
    $colorToscaDark  = '00695C';
    $colorWhite      = 'FFFFFF';
    $colorBorder     = 'B7C4C8';
    $colorTotal      = 'D4EDDA';
    $colorAvg        = 'FFF3CD';
    $colorTargetDay  = 'E2F0FF';
    $colorTarget     = 'CCE5FF';
    $colorAchieve    = 'C8E6C9';
    $colorAchieveBad = 'F8D7DA';

    $row = 1;

    $lastColIndex  = count($toko_list) + 2; // Tanggal + toko + Grand Total
    $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastColIndex);

    // =======================
    // JUDUL
    // =======================
    $sheet->mergeCells("A1:{$lastColLetter}1");
    $sheet->setCellValue("A1", "LAPORAN SALES MINIMARKET PERIODE " . strtoupper(strftime("%B %Y", strtotime($selectedMonth . '-01'))));
    $sheet->getStyle("A1")->getFont()->setBold(true)->setSize(14)->getColor()->setRGB($colorWhite);
    $sheet->getStyle("A1")->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    $sheet->getStyle("A1")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
          ->getStartColor()->setRGB($colorToscaDark);

    $row = 3;

    // =======================
    // HEADER TABEL
    // =======================
    $sheet->setCellValueByColumnAndRow(1, $row, 'Tanggal');
    $col = 2;
    foreach ($toko_list as $label) {
        $sheet->setCellValueByColumnAndRow($col++, $row, $label);
    }
    $sheet->setCellValueByColumnAndRow($col, $row, 'Grand Total');

    $headerRow = $row;
    $row++;

    // =======================
    // DATA HARIAN
    // =======================
    foreach ($finalData as $tanggal => $dataRow) {
        $col = 1;
        $sheet->setCellValueByColumnAndRow($col++, $row, $tanggal);
        $rowTotal = 0;

        foreach ($toko_list as $kode => $nama) {
            $val = $dataRow[$kode] ?? '';
            if ($val === '' || $val === null) {
                $sheet->setCellValueByColumnAndRow($col++, $row, '');
            } else {
                $sheet->setCellValueByColumnAndRow($col++, $row, (int)$val);
                if (is_numeric($val)) {
                    $rowTotal += (int)$val;
                }
            }
        }

        $sheet->setCellValueByColumnAndRow($col, $row, $rowTotal);
        $row++;
    }

    // =======================
    // SUMMARY
    // =======================
    $rowTotalLabel = $row;
    $sheet->setCellValue("A{$row}", 'Total');
    $col = 2;
    foreach ($totals as $v) {
        $sheet->setCellValueByColumnAndRow($col++, $row, (int)$v);
    }
    $sheet->setCellValueByColumnAndRow($col, $row, (int)$grandTotalAll);
    $row++;

    $rowAvgLabel = $row;
$sheet->setCellValue("A{$row}", 'Rata-rata');
$col = 2;

foreach ($toko_list as $k => $_) {
    $sheet->setCellValueByColumnAndRow($col++, $row, (int)($avg[$k] ?? 0));
}

$sheet->setCellValueByColumnAndRow($col, $row, (int)$grandAvg);
$row++;

    $rowTargetDailyLabel = $row;
    $sheet->setCellValue("A{$row}", 'Target / Hari');
    $col = 2;
    foreach ($targetDaily as $v) {
        $sheet->setCellValueByColumnAndRow($col++, $row, (int)$v);
    }
    $sheet->setCellValueByColumnAndRow($col, $row, (int)$grandTargetDaily);
    $row++;

    $rowTargetMonthLabel = $row;
    $sheet->setCellValue("A{$row}", 'Target / Month');
    $col = 2;
    foreach ($targets as $v) {
        $sheet->setCellValueByColumnAndRow($col++, $row, (int)$v);
    }
    $sheet->setCellValueByColumnAndRow($col, $row, (int)$grandTarget);
    $row++;

    $rowAchLabel = $row;
    $sheet->setCellValue("A{$row}", 'Achievement (%)');
    $col = 2;
    foreach ($totals as $key => $total) {
        $target = $targets[$key] ?? 0;
        $sheet->setCellValueByColumnAndRow($col++, $row, ($target > 0) ? ($total / $target) : 0);
    }
    $sheet->setCellValueByColumnAndRow($col, $row, ($grandTarget > 0) ? ($grandTotalAll / $grandTarget) : 0);

    $highestCol = $sheet->getHighestColumn();
    $highestRow = $sheet->getHighestRow();

    // =======================
    // STYLE HEADER
    // =======================
    $sheet->getStyle("A{$headerRow}:{$highestCol}{$headerRow}")->applyFromArray([
        'font' => [
            'bold' => true,
            'color' => ['rgb' => $colorWhite],
        ],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => $colorTosca],
        ],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
    ]);

    // BORDER
    $sheet->getStyle("A{$headerRow}:{$highestCol}{$highestRow}")->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                'color' => ['rgb' => $colorBorder],
            ],
        ],
    ]);

    // STYLE SUMMARY
    $sheet->getStyle("A{$rowTotalLabel}:{$highestCol}{$rowTotalLabel}")->applyFromArray([
        'font' => ['bold' => true],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => $colorTotal],
        ],
    ]);

    $sheet->getStyle("A{$rowAvgLabel}:{$highestCol}{$rowAvgLabel}")->applyFromArray([
        'font' => ['bold' => true],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => $colorAvg],
        ],
    ]);

    $sheet->getStyle("A{$rowTargetDailyLabel}:{$highestCol}{$rowTargetDailyLabel}")->applyFromArray([
        'font' => ['bold' => true],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => $colorTargetDay],
        ],
    ]);

    $sheet->getStyle("A{$rowTargetMonthLabel}:{$highestCol}{$rowTargetMonthLabel}")->applyFromArray([
        'font' => ['bold' => true],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => $colorTarget],
        ],
    ]);

    $sheet->getStyle("A{$rowAchLabel}:{$highestCol}{$rowAchLabel}")->getFont()->setBold(true);

    // FORMAT ANGKA
    if ($headerRow + 1 <= $rowAchLabel - 1) {
        $sheet->getStyle("B" . ($headerRow + 1) . ":{$highestCol}" . ($rowAchLabel - 1))
              ->getNumberFormat()->setFormatCode('#,##0');
    }

    $sheet->getStyle("B{$rowAchLabel}:{$highestCol}{$rowAchLabel}")
          ->getNumberFormat()->setFormatCode('0.0%');

    // WARNA AVG
    $colAvg = 2;
    foreach ($toko_list as $k => $_) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colAvg) . $rowAvgLabel;
        $color = ($avg[$k] >= ($targetDaily[$k] ?? 0)) ? '2E7D32' : 'C62828';
        $sheet->getStyle($cell)->getFont()->getColor()->setRGB($color);
        $colAvg++;
    }

    $grandCell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastColIndex) . $rowAvgLabel;
    $sheet->getStyle($grandCell)->getFont()->getColor()
          ->setRGB(($grandAvg >= ($grandTargetDaily ?? 0)) ? '2E7D32' : 'C62828');

    // WARNA ACHIEVEMENT
    $achColStart = 2;
    foreach ($toko_list as $k => $_) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($achColStart) . $rowAchLabel;
        $sheet->getStyle($cell)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
              ->getStartColor()->setRGB(($ach[$k] ?? 0) >= 100 ? $colorAchieve : $colorAchieveBad);
        $achColStart++;
    }

    $grandAchCell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastColIndex) . $rowAchLabel;
    $sheet->getStyle($grandAchCell)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
          ->getStartColor()->setRGB($grandAch >= 100 ? $colorAchieve : $colorAchieveBad);

    // ALIGNMENT
    $sheet->getStyle("A{$headerRow}:{$highestCol}{$highestRow}")
          ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A{$headerRow}:{$highestCol}{$highestRow}")
          ->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

    // FORMAT TANGGAL
    for ($r = $headerRow + 1; $r < $rowTotalLabel; $r++) {
        $sheet->getStyle("A{$r}")->getNumberFormat()->setFormatCode('yyyy-mm-dd');
    }

    // AUTO SIZE
    for ($i = 1; $i <= $lastColIndex; $i++) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
        $sheet->getColumnDimension($colLetter)->setAutoSize(true);
    }

    $sheet->getRowDimension(1)->setRowHeight(24);
    $sheet->getRowDimension($headerRow)->setRowHeight(35);

    // freeze header
    $sheet->freezePane('B4');

    $filename = "DATA SALES MINIMARKET PERIODE {$selectedMonth}.xlsx";

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=1024, initial-scale=0.5">
<title>Laporan Sales Minimarket</title>
<link rel="icon" type="image/png" href="img/srt2.png" />
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
body { font-family: Arial, sans-serif; background:#f5f6f7; margin:0; padding:20px; color:#1f2937; }

/* Header halaman ikut stay di atas saat user scroll halaman */
.header-bar {
    display:flex;
    justify-content:space-between;
    align-items:center;
    background:#009688;
    color:white;
    padding:10px 20px;
    border-radius:8px;
    position:sticky;
    top:0;
    z-index:80;
    box-shadow:0 8px 22px rgba(0,0,0,.12);
}
.header-bar h2 { margin:0; }

.avg-good {
    color: #2e7d32; /* hijau */
    font-weight: bold;
}
.avg-bad {
    color: #c62828; /* merah */
    font-weight: bold;
}

/* Area scroll tabel: header tabel akan freeze di dalam container ini */
.table-scroll {
    overflow:auto;
    max-height:calc(100vh - 175px);
    margin-top:10px;
    border:1px solid #d0d7de;
    border-radius:10px;
    background:#ffffff;
    box-shadow:0 12px 28px rgba(15,23,42,.08);
}

/* Sedikit lebih lebar agar horizontal scroll nyaman */
table {
    border-collapse:separate;
    border-spacing:0;
    width:100%;
    min-width:980px;
    font-size:13px;
    margin-top:0;
}
th, td {
    border-right:1px solid #ccc;
    border-bottom:1px solid #ccc;
    padding:6px 8px;
    text-align:center;
    white-space:nowrap;
}
th {
    background:#009688;
    color:white;
    position:sticky;
    top:0;
    z-index:40;
    box-shadow:0 2px 0 rgba(0,0,0,.12);
}
thead th:first-child {
    border-top-left-radius:10px;
}
thead th:last-child {
    border-top-right-radius:10px;
}
td:first-child, th:first-child {
    border-left:1px solid #ccc;
}
td.editable { cursor:pointer; }
tr:nth-child(even) td { background:#f9f9f9; }
tr.total-row td { font-weight:bold; }
input.inline-input { width:100%; text-align:center; border:none; outline:none; background:#e0f7fa; }

.page-actions {
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
    margin:10px 0;
}
.back-btn,
.action-btn {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:7px 12px;
    border-radius:7px;
    color:white;
    text-decoration:none;
    font-size:13px;
    font-weight:700;
}
.back-btn { background:#00695c; }
.back-btn:hover { background:#004d40; }
.action-btn { background:#1d6f42; }
.action-btn:hover { background:#155c35; }

.month-select { margin-left:10px; padding:4px; font-size:14px; border-radius:5px; }
td.achieve-ok { background: #c8e6c9 !important; font-weight: bold; }

@media(max-width:900px){
    body{padding:12px;}
    .header-bar{align-items:flex-start;flex-direction:column;gap:8px;}
    .table-scroll{max-height:calc(100vh - 210px);}
}

/* Freeze kolom Tanggal saat scroll horizontal */
#salesTable th:first-child,
#salesTable td:first-child {
    position: sticky;
    left: 0;
    z-index: 45;
    background: #ffffff;
    min-width: 105px;
    max-width: 105px;
    box-shadow: 2px 0 4px rgba(0,0,0,.12);
}

#salesTable thead th:first-child {
    z-index: 70;
    background: #009688;
    color: #ffffff;
}

#salesTable tbody tr:nth-child(even) td:first-child {
    background: #f9f9f9;
}

#salesTable tbody tr.total-row:nth-of-type(n) td:first-child {
    font-weight: bold;
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

<div class="header-bar">
  <div>
    <h2 style="display:inline;">Laporan Sales <?= strftime("%B %Y", strtotime($start)); ?></h2>
    <form method="get" style="display:inline;">
      <select name="month" class="month-select" onchange="this.form.submit()">
        <?php foreach($monthList as $m): ?>
          <option value="<?= htmlspecialchars($m) ?>" <?= ($m == $selectedMonth ? 'selected' : '') ?>>
            <?= strftime("%B %Y", strtotime($m . '-01')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div>Total Bulan Ini: <b>Rp <?= number_format($totalAll, 0, ',', '.') ?></b></div>
</div>

<div class="page-actions">
    <a href="dashboard.php" class="back-btn">Kembali</a>
    <a href="?month=<?= urlencode($selectedMonth) ?>&export=excel" class="action-btn">Download Excel</a>
    <a href="detail_outlet.php" class="action-btn">Lihat Grafik</a>
    <a href="summary-sales-tenant.php" class="action-btn">Laporan By Tenant</a>
</div>

<div class="table-scroll">
<table id="salesTable">
<thead>
<tr>
  <th>Tanggal</th>
  <?php foreach($toko_list as $label): ?>
    <th><?= htmlspecialchars($label) ?></th>
  <?php endforeach; ?>
  <th>Grand Total</th>
</tr>
</thead>
<tbody>
<?php foreach($finalData as $tanggal => $row): ?>
<tr>
    <td><?= htmlspecialchars($tanggal) ?></td>
    <?php
    $rowTotal = 0;
    foreach ($toko_list as $col => $_):
        $val = $row[$col] ?? "";
        $disp = ($val !== "") ? "Rp " . number_format($val, 0, ',', '.') : "";
        $editable = $canEdit ? "class='editable' data-col='" . htmlspecialchars($col, ENT_QUOTES) . "' data-tanggal='" . htmlspecialchars($tanggal, ENT_QUOTES) . "'" : "";
        if (is_numeric($val)) $rowTotal += $val;
    ?>
        <td <?= $editable ?>><?= htmlspecialchars($disp) ?></td>
    <?php endforeach; ?>
    <td><b>Rp <?= number_format($rowTotal, 0, ',', '.') ?></b></td>
</tr>
<?php endforeach; ?>

<tr class="total-row" style="background:#d4edda;">
    <td>Total</td>
    <?php foreach($toko_list as $k => $_): ?>
        <td>Rp <?= number_format($totals[$k], 0, ',', '.') ?></td>
    <?php endforeach; ?>
    <td><b>Rp <?= number_format($grandTotalAll, 0, ',', '.') ?></b></td>
</tr>

<tr class="total-row" style="background:#fff3cd;">
    <td>Rata-rata</td>
    <?php foreach($toko_list as $k => $_): 
        $isGood = ($avg[$k] >= ($targetDaily[$k] ?? 0));
        $cls = $isGood ? "avg-good" : "avg-bad";
    ?>
        <td class="<?= $cls ?>">
            Rp <?= number_format($avg[$k], 0, ',', '.') ?>
        </td>
    <?php endforeach; ?>

    <?php $grandIsGood = ($grandAvg >= ($grandTargetDaily ?? 0)); ?>
    <td class="<?= $grandIsGood ? 'avg-good' : 'avg-bad' ?>">
        <b>Rp <?= number_format($grandAvg, 0, ',', '.') ?></b>
    </td>
</tr>
<tr class="total-row" style="background:#e2f0ff;">
    <td>Target / Day </td>
    <?php foreach($toko_list as $k => $_): ?>
        <td>Rp <?= number_format($targetDaily[$k], 0, ',', '.') ?></td>
    <?php endforeach; ?>
    <td><b>Rp <?= number_format($grandTargetDaily, 0, ',', '.') ?></b></td>
</tr>

<tr class="total-row" style="background:#cce5ff;">
    <td>Target / Month</td>
    <?php foreach($toko_list as $k => $_): ?>
        <td>Rp <?= number_format($targets[$k], 0, ',', '.') ?></td>
    <?php endforeach; ?>
    <td><b>Rp <?= number_format($grandTarget, 0, ',', '.') ?></b></td>
</tr>

<tr class="total-row">
    <td>Achievement</td>
    <?php foreach($toko_list as $k => $_): 
        $cls = ($ach[$k] >= 100) ? "achieve-ok" : "";
    ?>
        <td class="<?= $cls ?>"><?= $ach[$k] ?>%</td>
    <?php endforeach; ?>
    <td class="<?= ($grandAch >= 100) ? 'achieve-ok' : '' ?>"><b><?= $grandAch ?>%</b></td>
</tr>
</tbody>
</table>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const table = document.getElementById('salesTable');
    const input = document.createElement('input');
    input.className = 'inline-input';
    let activeCell = null;

    table.addEventListener('click', e => {
        if (e.target.classList.contains('editable')) startEdit(e.target);
    });

    function startEdit(cell) {
        if (activeCell === cell) return;
        if (activeCell) finishEdit(true);
        activeCell = cell;
        const rawVal = cell.innerText.replace(/[^0-9]/g, '');
        cell.innerHTML = '';
        input.value = rawVal;
        cell.appendChild(input);
        input.focus();
        input.select();
    }

    function formatRupiah(num) {
        if (!num) return '';
        return 'Rp ' + new Intl.NumberFormat('id-ID').format(num);
    }

    function finishEdit(save = true) {
        if (!activeCell) return;
        const val = input.value.replace(/[^0-9]/g, '');
        const formatted = formatRupiah(val);
        const col = activeCell.dataset.col;
        const tanggal = activeCell.dataset.tanggal;
        activeCell.innerText = formatted;

        if (save) {
            fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ col, val, pk_value: tanggal })
            })
            .then(r => r.text())
            .then(res => {
                if (!res.startsWith('OK')) alert(res);
            })
            .catch(() => alert("Gagal koneksi ke server!"));
        }
        activeCell = null;
    }

    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            e.preventDefault();
            finishEdit(true);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            finishEdit(false);
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            const next = e.key === 'ArrowDown'
                ? activeCell.parentElement.nextElementSibling?.children[activeCell.cellIndex]
                : activeCell.parentElement.previousElementSibling?.children[activeCell.cellIndex];
            finishEdit(true);
            if (next && next.classList.contains('editable')) startEdit(next);
        }
    });

    input.addEventListener('blur', () => finishEdit(true));
});
</script>
<?php endif; ?>

</body>
</html>
