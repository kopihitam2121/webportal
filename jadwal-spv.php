<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

require 'db.php';

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

date_default_timezone_set('Asia/Jakarta');

$username = strtolower(trim($_SESSION['username']));
$userRole = '';

$stmtRole = $conn->prepare("SELECT role FROM users WHERE LOWER(username) = ? LIMIT 1");
if ($stmtRole) {
    $stmtRole->bind_param("s", $username);
    $stmtRole->execute();
    $resRole = $stmtRole->get_result();
    if ($resRole && $rowRole = $resRole->fetch_assoc()) {
        $userRole = strtoupper(trim((string)($rowRole['role'] ?? '')));
    }
    $stmtRole->close();
}

$isSuperAdminRole = in_array($userRole, ['SUPERADMIN', 'SUPER ADMIN'], true);

$allowedEditors = ['wira', 'prengkuh', 'yan', 'admin1', 'wulan'];
$scopedEditors = ['ujang', 'mustaqim'];
$canEditAll = in_array($username, $allowedEditors, true) || $isSuperAdminRole;
$canEditScoped = in_array($username, $scopedEditors, true);
$canEdit = $canEditAll || $canEditScoped;

$namaBulan = [
    '01' => 'JANUARI',
    '02' => 'FEBRUARI',
    '03' => 'MARET',
    '04' => 'APRIL',
    '05' => 'MEI',
    '06' => 'JUNI',
    '07' => 'JULI',
    '08' => 'AGUSTUS',
    '09' => 'SEPTEMBER',
    '10' => 'OKTOBER',
    '11' => 'NOVEMBER',
    '12' => 'DESEMBER'
];

$today = date('Y-m-d');
$success = '';
$error = '';

/* =========================================================================
| HELPER
* ========================================================================= */
function h($str)
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function normalizeTimeValue($val)
{
    $val = trim((string)$val);
    if ($val === '') return null;
    if (preg_match('/^\d{2}:\d{2}$/', $val)) return $val . ':00';
    return $val;
}

function timeHm($val)
{
    $val = trim((string)$val);
    if ($val === '') return '';
    return substr($val, 0, 5);
}

function normalizeShiftCode($name)
{
    $name = strtoupper(trim((string)$name));
    $name = preg_replace('/[^A-Z0-9]+/', '_', $name);
    $name = trim($name, '_');
    return $name !== '' ? $name : 'SHIFT_BARU';
}

function canonicalShiftCode($code)
{
    $code = strtoupper(trim((string)$code));
    $code = preg_replace('/[^A-Z0-9]+/', '_', $code);
    $code = trim($code, '_');

    $map = [
        'SHIFT1' => 'P', 'SHIFT_1' => 'P', 'SHIFT_01' => 'P', 'PAGI' => 'P', 'P' => 'P',
        'SHIFT2' => 'S', 'SHIFT_2' => 'S', 'SHIFT_02' => 'S', 'SIANG' => 'S', 'S' => 'S',
        'SHIFT3' => 'M', 'SHIFT_3' => 'M', 'SHIFT_03' => 'M', 'MALAM' => 'M', 'M' => 'M',
        'MIDDLE' => 'MDP', 'MIDDLE1' => 'MDP', 'MIDDLE_1' => 'MDP', 'MDP' => 'MDP',
        'MIDDLE2' => 'MDS', 'MIDDLE_2' => 'MDS', 'MDS' => 'MDS',
        'OFF' => 'OFF', 'CUTI' => 'CUTI',
    ];

    return $map[$code] ?? $code;
}

function getHariNama($tanggal)
{
    $hariMap = [
        'Sunday' => 'Minggu',
        'Monday' => 'Senin',
        'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis',
        'Friday' => 'Jumat',
        'Saturday' => 'Sabtu'
    ];
    $hariEn = date('l', strtotime($tanggal));
    return $hariMap[$hariEn] ?? $hariEn;
}

function getShiftDisplay($shiftCode, $shiftSettings)
{
    $shiftCode = canonicalShiftCode($shiftCode);
    if ($shiftCode === '' || $shiftCode === '-') return '-';
    if ($shiftCode === 'NOT_SCHEDULE') return 'NOT SCHEDULE';

    if (!isset($shiftSettings[$shiftCode])) {
        return $shiftCode;
    }

    $cfg = $shiftSettings[$shiftCode];
    $name = strtoupper(trim((string)($cfg['shift_name'] ?? $shiftCode)));
    $start = timeHm($cfg['start_time'] ?? '');
    $end   = timeHm($cfg['end_time'] ?? '');

    if (in_array($shiftCode, ['OFF', 'CUTI'], true)) {
        return $name;
    }

    if ($start !== '' && $end !== '') {
        return $name . ' ' . $start . ' s/d ' . $end . ' WIB';
    }

    return $name;
}

function getShiftClass($shiftCode)
{
    $shiftCode = canonicalShiftCode($shiftCode);
    if ($shiftCode === 'NOT_SCHEDULE') return 'shift-not-schedule';
    if ($shiftCode === 'OFF') return 'shift-off';
    if ($shiftCode === 'CUTI') return 'shift-cuti';
    if ($shiftCode === 'P' || $shiftCode === 'S1') return 'shift-1';
    if ($shiftCode === 'S' || $shiftCode === 'S2') return 'shift-2';
    if ($shiftCode === 'M' || $shiftCode === 'S3') return 'shift-3';
    if ($shiftCode === 'HO') return 'shift-ho';
    if ($shiftCode === 'MDP' || $shiftCode === 'MDS') return 'shift-middle';
    return 'shift-default';
}

function getCellClass($shiftCode)
{
    $shiftCode = canonicalShiftCode($shiftCode);
    if ($shiftCode === 'NOT_SCHEDULE') return 'cell-not-schedule';
    if ($shiftCode === 'OFF') return 'cell-off';
    if ($shiftCode === 'CUTI') return 'cell-cuti';
    if ($shiftCode === 'P' || $shiftCode === 'S1') return 'cell-shift1';
    if ($shiftCode === 'S' || $shiftCode === 'S2') return 'cell-shift2';
    if ($shiftCode === 'M' || $shiftCode === 'S3') return 'cell-shift3';
    if ($shiftCode === 'HO') return 'cell-ho';
    if ($shiftCode === 'MDP' || $shiftCode === 'MDS') return 'cell-middle';
    return 'cell-default';
}

function getCurrentPeriodMonthYear()
{
    $todayTs = time();
    $todayDay = (int)date('d', $todayTs);

    // Kalau hari >= 26, periode menuju bulan berikutnya
    if ($todayDay >= 26) {
        return [
            'month' => (int)date('m', strtotime('+1 month', $todayTs)),
            'year'  => (int)date('Y', strtotime('+1 month', $todayTs)),
        ];
    }

    return [
        'month' => (int)date('m', $todayTs),
        'year'  => (int)date('Y', $todayTs),
    ];
}

function buildPeriodRange($periodMonth, $periodYear)
{
    $periodMonth = (int)$periodMonth;
    $periodYear  = (int)$periodYear;

    $endDate   = sprintf('%04d-%02d-25', $periodYear, $periodMonth);
    $startDate = date('Y-m-d', strtotime('-1 month', strtotime($endDate)));
    $startDate = date('Y-m-26', strtotime($startDate));

    return [$startDate, $endDate];
}

function buildPeriodDays($startDate, $endDate)
{
    $dates = [];
    $current = strtotime($startDate);
    $endTs   = strtotime($endDate);

    while ($current <= $endTs) {
        $fullDate = date('Y-m-d', $current);
        $dates[] = [
            'full_date' => $fullDate,
            'day_num'   => date('d', $current),
            'day_name'  => getHariNama($fullDate),
            'month_num' => date('m', $current),
            'year_num'  => date('Y', $current),
            'short_label' => date('d', $current),
        ];
        $current = strtotime('+1 day', $current);
    }

    return $dates;
}

function getPeriodTitle($startDate, $endDate, $namaBulan)
{
    $sMonth = $namaBulan[date('m', strtotime($startDate))] ?? date('m', strtotime($startDate));
    $eMonth = $namaBulan[date('m', strtotime($endDate))] ?? date('m', strtotime($endDate));

    return date('d', strtotime($startDate)) . ' ' . $sMonth . ' ' . date('Y', strtotime($startDate))
        . ' s/d '
        . date('d', strtotime($endDate)) . ' ' . $eMonth . ' ' . date('Y', strtotime($endDate));
}

function monthNav($periodMonth, $periodYear, $step)
{
    $base = strtotime(sprintf('%04d-%02d-01', $periodYear, $periodMonth) . " {$step} month");
    return [
        'month' => (int)date('m', $base),
        'year'  => (int)date('Y', $base),
    ];
}


function xlsxSafeSheetTitle($title)
{
    $title = preg_replace('/[\\\\\\/\\?\\*\\[\\]\\:]/', '-', (string)$title);
    $title = trim($title);
    return mb_substr($title !== '' ? $title : 'Template Jadwal', 0, 31);
}

function buildShiftLegendText($shiftSettings, $activeShiftCodes)
{
    $preferred = [
        'P' => 'SHIFT 1 = P',
        'S' => 'SHIFT 2 = S',
        'M' => 'SHIFT 3 = M',
        'MDP' => 'MIDDLE 1 = MDP',
        'MDS' => 'MIDDLE 2 = MDS',
    ];

    $parts = [];
    foreach ($preferred as $code => $label) {
        if (in_array($code, $activeShiftCodes, true)) {
            $parts[] = $label;
        }
    }

    foreach ($activeShiftCodes as $code) {
        if (isset($preferred[$code])) continue;
        $cfg = $shiftSettings[$code] ?? [];
        $name = strtoupper(trim((string)($cfg['shift_name'] ?? $code)));
        $parts[] = $name . ' = ' . $code;
    }

    return implode('   |   ', $parts);
}

function downloadJadwalTemplateExcel($periodTitle, $periodDays, $selectedLeaders, $leaderMeta, $shiftSettings, $activeShiftCodes, $jadwalData, $namaBulan)
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(xlsxSafeSheetTitle('Template Jadwal'));

    $lastColIndex = 2 + count($periodDays);
    $lastCol = Coordinate::stringFromColumnIndex($lastColIndex);

    // Sheet 2 khusus daftar kode shift supaya template utama tetap clean.
    $shiftSheet = new Worksheet($spreadsheet, xlsxSafeSheetTitle('Kode Shift'));
    $spreadsheet->addSheet($shiftSheet, 1);

    $shiftSheet->mergeCells('A1:E1');
    $shiftSheet->setCellValue('A1', 'DAFTAR KODE SHIFT');
    $shiftSheet->mergeCells('A2:E2');
    $shiftSheet->setCellValue('A2', 'Gunakan kode pada kolom A untuk mengisi jadwal di sheet Template Jadwal.');

    $shiftSheet->setCellValue('A4', 'KODE');
    $shiftSheet->setCellValue('B4', 'NAMA SHIFT');
    $shiftSheet->setCellValue('C4', 'JAM MULAI');
    $shiftSheet->setCellValue('D4', 'JAM SELESAI');
    $shiftSheet->setCellValue('E4', 'KETERANGAN');

    $preferredShiftRows = [
        'P'   => ['SHIFT 1',  '07:00', '15:00', 'Pagi'],
        'S'   => ['SHIFT 2',  '15:00', '23:00', 'Siang'],
        'M'   => ['SHIFT 3',  '23:00', '08:00', 'Malam'],
        'MDP' => ['MIDDLE 1', '10:00', '18:00', 'Middle 1'],
        'MDS' => ['MIDDLE 2', '12:00', '20:00', 'Middle 2'],
    ];

    $shiftRow = 5;
    foreach ($preferredShiftRows as $code => $fallback) {
        if (!in_array($code, $activeShiftCodes, true)) {
            continue;
        }

        $cfg = $shiftSettings[$code] ?? [];
        $shiftSheet->setCellValue('A' . $shiftRow, $code);
        $shiftSheet->setCellValue('B' . $shiftRow, strtoupper(trim((string)($cfg['shift_name'] ?? $fallback[0]))));
        $shiftSheet->setCellValue('C' . $shiftRow, timeHm($cfg['start_time'] ?? $fallback[1]));
        $shiftSheet->setCellValue('D' . $shiftRow, timeHm($cfg['end_time'] ?? $fallback[2]));
        $shiftSheet->setCellValue('E' . $shiftRow, $fallback[3]);
        $shiftRow++;
    }

    foreach ($activeShiftCodes as $code) {
        if (isset($preferredShiftRows[$code])) {
            continue;
        }

        $cfg = $shiftSettings[$code] ?? [];
        $shiftSheet->setCellValue('A' . $shiftRow, $code);
        $shiftSheet->setCellValue('B' . $shiftRow, strtoupper(trim((string)($cfg['shift_name'] ?? $code))));
        $shiftSheet->setCellValue('C' . $shiftRow, timeHm($cfg['start_time'] ?? ''));
        $shiftSheet->setCellValue('D' . $shiftRow, timeHm($cfg['end_time'] ?? ''));
        $shiftSheet->setCellValue('E' . $shiftRow, 'Tambahan');
        $shiftRow++;
    }

    $lastShiftRow = max(5, $shiftRow - 1);

    $shiftSheet->getStyle('A1:E2')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $shiftSheet->getStyle('A1:E2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0B2A4A');
    $shiftSheet->getStyle('A1:E2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $shiftSheet->getStyle('A4:E4')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $shiftSheet->getStyle('A4:E4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('123D6B');
    $shiftSheet->getStyle('A5:E' . $lastShiftRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EFF6FF');
    $shiftSheet->getStyle('A1:E' . $lastShiftRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
    $shiftSheet->getStyle('A4:E' . $lastShiftRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $shiftSheet->getColumnDimension('A')->setWidth(14);
    $shiftSheet->getColumnDimension('B')->setWidth(22);
    $shiftSheet->getColumnDimension('C')->setWidth(16);
    $shiftSheet->getColumnDimension('D')->setWidth(16);
    $shiftSheet->getColumnDimension('E')->setWidth(24);
    $shiftSheet->getRowDimension(1)->setRowHeight(26);
    $shiftSheet->getRowDimension(2)->setRowHeight(24);
    $shiftSheet->freezePane('A5');
    $shiftSheet->setAutoFilter('A4:E' . $lastShiftRow);

    $sheet->mergeCells("A1:{$lastCol}1");
    $sheet->setCellValue('A1', 'JADWAL SPV LEADER');
    $sheet->mergeCells("A2:{$lastCol}2");
    $sheet->setCellValue('A2', 'Periode ' . $periodTitle);
    $sheet->mergeCells("A3:{$lastCol}3");

    // Baris 4 disembunyikan untuk menyimpan tanggal asli format YYYY-MM-DD.
    $sheet->setCellValue('A4', 'NAMA LEADER');
    $sheet->setCellValue('B4', 'JABATAN');

    $colIndex = 3;
    foreach ($periodDays as $day) {
        $col = Coordinate::stringFromColumnIndex($colIndex);
        $sheet->setCellValue($col . '4', $day['full_date']);
        $sheet->setCellValue($col . '5', $day['day_num'] . "\n" . substr($day['day_name'], 0, 3) . "\n" . (($namaBulan[$day['month_num']] ?? $day['month_num']) . ' ' . $day['year_num']));
        $colIndex++;
    }

    $sheet->setCellValue('A5', 'NAMA LEADER');
    $sheet->setCellValue('B5', 'JABATAN');

    $row = 6;
    foreach ($selectedLeaders as $leader) {
        $sheet->setCellValue("A{$row}", $leader);
        $sheet->setCellValue("B{$row}", $leaderMeta[$leader]['position_name'] ?? 'Leader / SPV');

        $colIndex = 3;
        foreach ($periodDays as $day) {
            $col = Coordinate::stringFromColumnIndex($colIndex);
            $existingShift = canonicalShiftCode($jadwalData[$leader][$day['full_date']] ?? '');
            $sheet->setCellValue($col . $row, $existingShift === '-' ? '' : $existingShift);
            $colIndex++;
        }
        $row++;
    }

    $lastRow = max(6, $row - 1);
    $noteRow = $lastRow + 3;

    $sheet->mergeCells("A{$noteRow}:{$lastCol}{$noteRow}");
    
    $sheet->getStyle("A1:{$lastCol}2")->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle("A1:{$lastCol}2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0B2A4A');
    $sheet->getStyle("A1:{$lastCol}2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

    $sheet->getStyle("A3:{$lastCol}3")->getFont()->setBold(true)->getColor()->setRGB('0F172A');
    $sheet->getStyle("A3:{$lastCol}3")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DBEAFE');
    $sheet->getStyle("A3:{$lastCol}3")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

    $sheet->getStyle("A5:{$lastCol}5")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle("A5:{$lastCol}5")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('123D6B');
    $sheet->getStyle("A5:{$lastCol}5")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

    $sheet->getStyle("A6:B{$lastRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EFF6FF');
    $sheet->getStyle("A6:{$lastCol}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getStyle("A6:B{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle("A1:{$lastCol}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');

    $sheet->getStyle("A{$noteRow}:{$lastCol}{$noteRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF3C7');
    $sheet->getStyle("A{$noteRow}")->getAlignment()->setWrapText(true);
    $sheet->getRowDimension($noteRow)->setRowHeight(42);

    $sheet->getColumnDimension('A')->setWidth(30);
    $sheet->getColumnDimension('B')->setWidth(24);
    for ($i = 3; $i <= $lastColIndex; $i++) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(13);
    }
    $sheet->getRowDimension(1)->setRowHeight(26);
    $sheet->getRowDimension(2)->setRowHeight(24);
    $sheet->getRowDimension(3)->setRowHeight(34);
    $sheet->getRowDimension(5)->setRowHeight(50);
    $sheet->getRowDimension(4)->setVisible(false);

    $shiftValidationFormula = "'Kode Shift'!\$A\$5:\$A\$" . $lastShiftRow;
    for ($r = 6; $r <= $lastRow; $r++) {
        for ($c = 3; $c <= $lastColIndex; $c++) {
            $cell = Coordinate::stringFromColumnIndex($c) . $r;
            $dv = $sheet->getCell($cell)->getDataValidation();
            $dv->setType(DataValidation::TYPE_LIST);
            $dv->setErrorStyle(DataValidation::STYLE_STOP);
            $dv->setAllowBlank(true);
            $dv->setShowDropDown(true);
            $dv->setFormula1($shiftValidationFormula);
        }
    }

    $sheet->freezePane('B6');
    $sheet->setAutoFilter("A5:{$lastCol}{$lastRow}");
    $spreadsheet->setActiveSheetIndex(0);

    $fileName = 'template-import-jadwal-spv-' . date('Ymd-His') . '.xlsx';

    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: max-age=0');

    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}

/* =========================================================================
| DEFAULT SHIFT SETTINGS
* ========================================================================= */
$defaultShiftSeed = [
    'P'    => ['shift_code' => 'P',    'shift_name' => 'SHIFT 1',  'start_time' => '07:00:00', 'end_time' => '15:00:00', 'is_active' => 1],
    'S'    => ['shift_code' => 'S',    'shift_name' => 'SHIFT 2',  'start_time' => '15:00:00', 'end_time' => '23:00:00', 'is_active' => 1],
    'M'    => ['shift_code' => 'M',    'shift_name' => 'SHIFT 3',  'start_time' => '20:00:00', 'end_time' => '04:00:00', 'is_active' => 1],
    'S1'  => ['shift_code' => 'S1',  'shift_name' => 'PAGI', 'start_time' => '08:00:00', 'end_time' => '16:00:00', 'is_active' => 1],
    'S2'  => ['shift_code' => 'S2',  'shift_name' => 'SIANG', 'start_time' => '16:00:00', 'end_time' => '00:00:00', 'is_active' => 1],
    'S3'  => ['shift_code' => 'S3', 'shift_name' => 'MALAM', 'start_time' => '00:00:00', 'end_time' => '08:00:00', 'is_active' => 1],
    'HO'  => ['shift_code' => 'HO', 'shift_name' => 'Head Office', 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'is_active' => 1],
    'MDP'  => ['shift_code' => 'MDP',  'shift_name' => 'MIDDLE 1', 'start_time' => '10:00:00', 'end_time' => '18:00:00', 'is_active' => 1],
    'MDS'  => ['shift_code' => 'MDS',  'shift_name' => 'MIDDLE 2', 'start_time' => '12:00:00', 'end_time' => '20:00:00', 'is_active' => 1],
    'OFF'  => ['shift_code' => 'OFF',  'shift_name' => 'OFF',      'start_time' => null,       'end_time' => null,       'is_active' => 1],
    'CUTI' => ['shift_code' => 'CUTI', 'shift_name' => 'CUTI',     'start_time' => null,       'end_time' => null,       'is_active' => 1],
];


function shiftHasTime($cfg)
{
    return trim((string)($cfg['start_time'] ?? '')) !== '' || trim((string)($cfg['end_time'] ?? '')) !== '';
}

function buildActiveShiftCodes($shiftSettings)
{
    $codes = [];
    foreach ($shiftSettings as $code => $cfg) {
        if ((int)($cfg['is_active'] ?? 1) === 1) {
            $codes[] = $code;
        }
    }
    return $codes;
}

function shiftAliasesForCanonical($canonicalCode)
{
    $canonicalCode = canonicalShiftCode($canonicalCode);

    $map = [
        'P'    => ['P', 'SHIFT1', 'SHIFT_1', 'SHIFT_01', 'PAGI'],
        'S'    => ['S', 'SHIFT2', 'SHIFT_2', 'SHIFT_02', 'SIANG'],
        'M'    => ['M', 'SHIFT3', 'SHIFT_3', 'SHIFT_03', 'MALAM'],
        'MDP'  => ['MDP', 'MIDDLE', 'MIDDLE1', 'MIDDLE_1', 'MIDDLE 1'],
        'MDS'  => ['MDS', 'MIDDLE2', 'MIDDLE_2', 'MIDDLE 2'],
        'HO'   => ['HO', 'HEAD_OFFICE', 'HEAD OFFICE', 'KANTOR', 'OFFICE'],
        'OFF'  => ['OFF'],
        'CUTI' => ['CUTI'],
    ];

    return $map[$canonicalCode] ?? [$canonicalCode];
}

function loadShiftSettingsFromDb($conn, $defaultShiftSeed)
{
    $shiftSettings = $defaultShiftSeed;

    $resShift = $conn->query("
        SELECT shift_code, shift_name, start_time, end_time, is_active
        FROM shift_settings
        ORDER BY id ASC
    ");

    if ($resShift) {
        while ($row = $resShift->fetch_assoc()) {
            $code = canonicalShiftCode($row['shift_code'] ?? '');
            if ($code === '') continue;

            $incomingName   = trim((string)($row['shift_name'] ?? $code));
            $incomingStart  = $row['start_time'] ?? null;
            $incomingEnd    = $row['end_time'] ?? null;
            $incomingActive = (int)($row['is_active'] ?? 1);

            if (!isset($shiftSettings[$code])) {
                $shiftSettings[$code] = [
                    'shift_code' => $code,
                    'shift_name' => $incomingName !== '' ? $incomingName : $code,
                    'start_time' => $incomingStart,
                    'end_time'   => $incomingEnd,
                    'is_active'  => $incomingActive,
                ];
                continue;
            }

            if ($incomingName !== '') {
                $shiftSettings[$code]['shift_name'] = $incomingName;
            }

            // Jam hanya ditimpa kalau incoming tidak kosong.
            // Ini mencegah MDP/MDS yang sudah disimpan jamnya balik kosong karena row legacy MIDDLE/MIDDLE_1/MIDDLE_2.
            if (trim((string)$incomingStart) !== '') {
                $shiftSettings[$code]['start_time'] = $incomingStart;
            }
            if (trim((string)$incomingEnd) !== '') {
                $shiftSettings[$code]['end_time'] = $incomingEnd;
            }

            $shiftSettings[$code]['is_active'] = max((int)($shiftSettings[$code]['is_active'] ?? 1), $incomingActive);
        }
    }

    return $shiftSettings;
}

function cleanupShiftRowsBeforeInsert($conn, $canonicalCode)
{
    $canonicalCode = canonicalShiftCode($canonicalCode);
    $aliases = shiftAliasesForCanonical($canonicalCode);

    foreach ($aliases as $alias) {
        $alias = strtoupper(trim((string)$alias));
        if ($alias === '') {
            continue;
        }

        $stmt = $conn->prepare("DELETE FROM shift_settings WHERE UPPER(shift_code) = ?");
        if ($stmt) {
            $stmt->bind_param("s", $alias);
            $stmt->execute();
            $stmt->close();
        }
    }
}

function tableColumnExists($conn, $tableName, $columnName)
{
    $tableName = preg_replace('/[^A-Za-z0-9_]+/', '', (string)$tableName);
    $columnName = preg_replace('/[^A-Za-z0-9_]+/', '', (string)$columnName);

    if ($tableName === '' || $columnName === '') {
        return false;
    }

    $stmt = $conn->prepare("SHOW COLUMNS FROM `{$tableName}` LIKE ?");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("s", $columnName);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();

    return $exists;
}

function shiftSettingRowExists($conn, $code)
{
    $code = canonicalShiftCode($code);
    if ($code === '') return false;

    $stmt = $conn->prepare("SELECT id FROM shift_settings WHERE UPPER(shift_code) = ? LIMIT 1");
    if (!$stmt) return false;

    $stmt->bind_param("s", $code);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();

    return $exists;
}

function saveShiftSettingRow($conn, $code, $name, $start, $end, $isActive, $username, &$error = '')
{
    $code = canonicalShiftCode($code);
    $name = strtoupper(trim((string)$name));
    $isActive = (int)$isActive;

    if ($code === '' || $name === '') {
        $error = 'Kode dan nama shift wajib diisi.';
        return false;
    }

    // Jangan delete lalu insert saat simpan pengaturan shift.
    // Kalau kode sudah ada: UPDATE. Kalau belum ada: INSERT.
    $exists = shiftSettingRowExists($conn, $code);

    if ($exists) {
        $setParts = ['shift_code = ?', 'shift_name = ?', 'start_time = ?', 'end_time = ?', 'is_active = ?'];
        $values = [$code, $name, $start, $end, $isActive];
        $types = 'ssssi';

        if (tableColumnExists($conn, 'shift_settings', 'updated_by')) {
            $setParts[] = 'updated_by = ?';
            $values[] = $username;
            $types .= 's';
        }
        if (tableColumnExists($conn, 'shift_settings', 'updated_at')) {
            $setParts[] = 'updated_at = CURRENT_TIMESTAMP';
        }

        $values[] = $code;
        $types .= 's';

        $sql = 'UPDATE shift_settings SET ' . implode(', ', $setParts) . ' WHERE UPPER(shift_code) = ?';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $error = 'Prepare update shift gagal: ' . $conn->error;
            return false;
        }

        $bindValues = [];
        foreach ($values as $k => $v) { $bindValues[$k] = $v; }
        $stmt->bind_param($types, ...$bindValues);

        if (!$stmt->execute()) {
            $error = 'Gagal update shift: ' . $stmt->error;
            $stmt->close();
            return false;
        }
        $stmt->close();
        return true;
    }

    $columns = ['shift_code', 'shift_name', 'start_time', 'end_time', 'is_active'];
    $values = [$code, $name, $start, $end, $isActive];
    $types = 'ssssi';
    $placeholders = ['?', '?', '?', '?', '?'];

    if (tableColumnExists($conn, 'shift_settings', 'created_by')) {
        $columns[] = 'created_by';
        $values[] = $username;
        $types .= 's';
        $placeholders[] = '?';
    }
    if (tableColumnExists($conn, 'shift_settings', 'updated_by')) {
        $columns[] = 'updated_by';
        $values[] = $username;
        $types .= 's';
        $placeholders[] = '?';
    }
    if (tableColumnExists($conn, 'shift_settings', 'created_at')) {
        $columns[] = 'created_at';
        $placeholders[] = 'CURRENT_TIMESTAMP';
    }
    if (tableColumnExists($conn, 'shift_settings', 'updated_at')) {
        $columns[] = 'updated_at';
        $placeholders[] = 'CURRENT_TIMESTAMP';
    }

    $columnSql = '`' . implode('`, `', $columns) . '`';
    $placeholderSql = implode(', ', $placeholders);

    $stmt = $conn->prepare("INSERT INTO shift_settings ({$columnSql}) VALUES ({$placeholderSql})");
    if (!$stmt) {
        $error = 'Prepare insert shift gagal: ' . $conn->error;
        return false;
    }

    $bindValues = [];
    foreach ($values as $k => $v) { $bindValues[$k] = $v; }
    $stmt->bind_param($types, ...$bindValues);

    if (!$stmt->execute()) {
        $error = 'Gagal insert shift: ' . $stmt->error;
        $stmt->close();
        return false;
    }
    $stmt->close();
    return true;
}



function normalizeAccessKey($value)
{
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9]+/', '', $value);
    return $value;
}

function getLeaderAliasMap()
{
    // Mapping khusus username login ke nama leader di master_leader.
    // Ini penting untuk nama yang tidak bisa ditebak otomatis dari nama lengkap,
    // contoh: username adit harus match ke M.ADITYA SEFRIYANTO.
    return [
        'GREGORIUS JESEN BANFOE' => ['jesen', 'gregorius'],
        'DEVI RETNO MINARSIH'    => ['devi'],
        'LUSIAH'                 => ['lusiah'],
        'DEDE HIDAYAT'           => ['dede'],
        'M.ADITYA SEFRIYANTO'    => ['adit', 'aditya'],
        'M. ADITYA SEFRIYANTO'   => ['adit', 'aditya'],
        'HARIS FADHILAH'         => ['haris'],
        'SURYANTO ARIYAWAN'      => ['yanto', 'suryanto'],
        'UJANG FIRMANSYAH'       => ['ujang'],
        'CHOTIBATUL UMAM'        => ['umam'],
        'M. MUSTAQIM'            => ['mustaqim'],
        'MUHAMMAD MUSTAQIM'      => ['mustaqim'],
        'ADRIYAN FAUZAN'         => ['yan', 'adriyan'],
        'WULANDARI'              => ['wulandari'],
    ];
}

function getLeaderAccessKeys($leaderName)
{
    $leaderName = trim((string)$leaderName);
    $keys = [];

    $fullKey = normalizeAccessKey($leaderName);
    if ($fullKey !== "") {
        $keys[] = $fullKey;
    }

   
    $parts = preg_split("/[^A-Za-z0-9]+/", $leaderName);
    foreach ($parts as $part) {
        $partKey = normalizeAccessKey($part);
        if ($partKey !== "" && strlen($partKey) >= 3) {
            $keys[] = $partKey;
        }
    }

   
    $aliasMap = getLeaderAliasMap();
    $upperName = strtoupper(trim($leaderName));
    if (isset($aliasMap[$upperName])) {
        foreach ($aliasMap[$upperName] as $alias) {
            $aliasKey = normalizeAccessKey($alias);
            if ($aliasKey !== "") {
                $keys[] = $aliasKey;
            }
        }
    }

    return array_values(array_unique($keys));
}

function getSupervisorTeamAccessMap()
{
    // Hanya UJANG dan MUSTAQIM yang diperlakukan sebagai supervisor.
    // UMAM tetap Crew Leader, jadi tidak lagi menjadi parent supervisor.
    return [
        'ujang' => ['ujang', 'dede', 'devi', 'haris', 'adit'],
        'mustaqim' => ['mustaqim', 'umam', 'yanto', 'jesen', 'lusiah'],
    ];
}

function isSupervisorPositionName($positionName)
{
    $positionName = strtoupper(trim((string)$positionName));
    return strpos($positionName, 'SUPERVISOR') !== false || strpos($positionName, 'SPV') !== false;
}

function isCrewLeaderPositionName($positionName)
{
    $positionName = strtoupper(trim((string)$positionName));
    return strpos($positionName, 'CREW') !== false && strpos($positionName, 'LEADER') !== false;
}

function getLoggedInLeaderName($allLeaders, $username)
{
    $usernameKey = normalizeAccessKey($username);

    foreach ($allLeaders as $leader) {
        $leaderKeys = getLeaderAccessKeys($leader);
        if (in_array($usernameKey, $leaderKeys, true)) {
            return $leader;
        }
    }

    return '';
}

function getAllowedLeaderKeysForUser($username, $canEdit, $allLeaders = [], $leaderMeta = [])
{
    global $canEditAll;
    $usernameKey = normalizeAccessKey($username);

    if (!empty($canEditAll)) {
        return ['*'];
    }

    if ($usernameKey === 'wahid') {
        return ['*'];
    }

    $loggedInLeader = getLoggedInLeaderName($allLeaders, $username);
    $allowed = [$usernameKey];

    if ($loggedInLeader !== '') {
        $allowed = array_merge($allowed, getLeaderAccessKeys($loggedInLeader));
    }

    $positionName = $loggedInLeader !== ''
        ? ($leaderMeta[$loggedInLeader]['position_name'] ?? '')
        : '';

    $teamMap = getSupervisorTeamAccessMap();

    // User editor scoped seperti ujang dan mustaqim boleh akses penuh sesuai mapping tim,
    // walaupun data jabatan di master_leader belum tertulis SUPERVISOR/SPV.
    if ($canEdit && isset($teamMap[$usernameKey])) {
        $allowed = array_merge($allowed, $teamMap[$usernameKey]);
    } elseif (isSupervisorPositionName($positionName) && isset($teamMap[$usernameKey])) {
        // Non-editor tetap hanya dapat akses tim jika jabatannya SUPERVISOR/SPV.
        $allowed = array_merge($allowed, $teamMap[$usernameKey]);
    }

    $allowed = array_map('normalizeAccessKey', $allowed);
    $allowed = array_filter($allowed, function ($v) {
        return $v !== '';
    });

    return array_values(array_unique($allowed));
}


function ensureCoreExportLeaders(array $baseLeaders, array $allLeaders)
{
    // Nama inti ini harus tetap muncul di dropdown cetak/download template.
    $coreKeys = ['ujang', 'mustaqim', 'umam', 'yan'];
    $merged = $baseLeaders;

    foreach ($allLeaders as $leader) {
        $leaderKeys = getLeaderAccessKeys($leader);
        if (count(array_intersect($leaderKeys, $coreKeys)) > 0) {
            $merged[] = $leader;
        }
    }

    $out = [];
    $seen = [];
    foreach ($merged as $leader) {
        $leader = trim((string)$leader);
        if ($leader === '') continue;
        $key = strtoupper($leader);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $leader;
    }

    return $out;
}

function filterVisibleLeadersByAccess($allLeaders, $username, $canEdit, $leaderMeta = [])
{
    $allowedKeys = getAllowedLeaderKeysForUser($username, $canEdit, $allLeaders, $leaderMeta);

    if (in_array('*', $allowedKeys, true)) {
        return $allLeaders;
    }

    $visible = [];
    foreach ($allLeaders as $leader) {
        $leaderKeys = getLeaderAccessKeys($leader);
        if (count(array_intersect($leaderKeys, $allowedKeys)) > 0) {
            $visible[] = $leader;
        }
    }

    return array_values($visible);
}

function getAccessInfoText($username, $canEdit, $allLeaders = [], $leaderMeta = [])
{
    $usernameKey = normalizeAccessKey($username);

    if (!empty($GLOBALS['canEditAll'])) {
        return 'Akses editor penuh';
    }

    if ($canEdit) {
        return 'Akses editor sesuai tim';
    }

    if ($usernameKey === 'wahid') {
        return 'Akses view all';
    }

    $loggedInLeader = getLoggedInLeaderName($allLeaders, $username);
    $positionName = $loggedInLeader !== ''
        ? ($leaderMeta[$loggedInLeader]['position_name'] ?? '')
        : '';

    if (isSupervisorPositionName($positionName)) {
        return 'Akses supervisor';
    }

    return 'Akses crew leader';
}
/* =========================================================================
| CUSTOM PERIODE 26 - 25
* ========================================================================= */
$currentPeriod = getCurrentPeriodMonthYear();
$periodMonth = isset($_GET['period_month']) ? (int)$_GET['period_month'] : (int)$currentPeriod['month'];
$periodYear  = isset($_GET['period_year']) ? (int)$_GET['period_year'] : (int)$currentPeriod['year'];

if ($periodMonth < 1 || $periodMonth > 12) {
    $periodMonth = (int)$currentPeriod['month'];
}
if ($periodYear < 2020 || $periodYear > 2100) {
    $periodYear = (int)$currentPeriod['year'];
}

[$startDate, $endDate] = buildPeriodRange($periodMonth, $periodYear);
$periodDays = buildPeriodDays($startDate, $endDate);
$periodTitle = getPeriodTitle($startDate, $endDate, $namaBulan);
$bulanIndonesia = $namaBulan[str_pad((string)$periodMonth, 2, '0', STR_PAD_LEFT)] ?? '';
$tahun = $periodYear;

$prevNav = monthNav($periodMonth, $periodYear, -1);
$nextNav = monthNav($periodMonth, $periodYear, 1);

/* =========================================================================
| LOAD LEADER
* ========================================================================= */
$allLeaders = [];
$leaderMeta = [];

$resLeader = $conn->query("SELECT leader_name, position_name, is_active FROM master_leader WHERE is_active = 1 ORDER BY leader_name ASC");
if ($resLeader) {
    while ($row = $resLeader->fetch_assoc()) {
        $leaderName = trim((string)$row['leader_name']);
        $positionName = trim((string)($row['position_name'] ?? 'Leader / SPV'));
        if ($leaderName !== '') {
            $allLeaders[] = $leaderName;
            $leaderMeta[$leaderName] = [
                'position_name' => $positionName !== '' ? $positionName : 'Leader / SPV'
            ];
        }
    }
}

if (empty($allLeaders)) {
    $allLeaders = [
        "UJANG FIRMANSYAH",
        "CHOTIBATUL UMAM",
        "MUHAMMAD MUSTAQIM"
    ];

    foreach ($allLeaders as $nm) {
        $leaderMeta[$nm] = ['position_name' => 'Leader / SPV'];
    }
}

/* =========================================================================
| FIX ROLE LABEL LEADER
| Hanya UJANG dan MUSTAQIM sebagai Supervisor.
| UMAM tetap Crew Leader.
* ========================================================================= */
foreach ($allLeaders as $leaderNameFix) {
    $leaderKeysFix = getLeaderAccessKeys($leaderNameFix);

    if (count(array_intersect($leaderKeysFix, ['ujang', 'mustaqim'])) > 0) {
        $leaderMeta[$leaderNameFix]['position_name'] = 'Supervisor';
    }

    if (count(array_intersect($leaderKeysFix, ['umam'])) > 0) {
        $leaderMeta[$leaderNameFix]['position_name'] = 'Crew Leader';
    }
}

/* =========================================================================
| LOAD SHIFT SETTINGS
* ========================================================================= */
$shiftSettings = loadShiftSettingsFromDb($conn, $defaultShiftSeed);
$activeShiftCodes = buildActiveShiftCodes($shiftSettings);

/* =========================================================================
| VISIBLE LEADER
* ========================================================================= */
$visibleLeaders = filterVisibleLeadersByAccess($allLeaders, $username, $canEdit, $leaderMeta);
$manageableLeaders = $canEditAll ? $allLeaders : $visibleLeaders;

// Khusus dropdown cetak/download template Excel:
// UJANG, MUSTAQIM, UMAM, dan YAN tetap dimunculkan walaupun tidak masuk filter akses utama.
$exportTemplateLeaders = ensureCoreExportLeaders($manageableLeaders, $allLeaders);

/* =========================================================================
| ACTIONS
* ========================================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $action = $_POST['action_type'] ?? '';

    if ($action === 'import_excel') {
        if (!isset($_FILES['import_file']) || (int)($_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = "File Excel wajib diupload.";
        } else {
            $ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['xlsx', 'xls'], true)) {
                $error = "Format file harus .xlsx atau .xls.";
            } else {
                try {
                    $spreadsheetImport = IOFactory::load($_FILES['import_file']['tmp_name']);
                    $sheetImport = $spreadsheetImport->getActiveSheet();

                    $highestRow = $sheetImport->getHighestDataRow();
                    $expectedDateByCol = [];

                    $firstDateCol = 3; // C,
                    foreach ($periodDays as $idx => $day) {
                        $expectedDateByCol[$firstDateCol + $idx] = $day['full_date'];
                    }

                    $imported = 0;
                    $invalidRows = [];

                    $stmtImport = $conn->prepare("
                        INSERT INTO jadwal_supervisor (supervisor_name, tanggal, shift, updated_by)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            shift = VALUES(shift),
                            updated_by = VALUES(updated_by),
                            updated_at = CURRENT_TIMESTAMP
                    ");

                    if (!$stmtImport) {
                        $error = "Prepare statement import gagal.";
                    } else {
                        for ($rowExcel = 6; $rowExcel <= $highestRow; $rowExcel++) {
                            $leaderName = trim((string)$sheetImport->getCell('A' . $rowExcel)->getCalculatedValue());

                            if ($leaderName === '') {
                                continue;
                            }

                            if (!in_array($leaderName, $exportTemplateLeaders, true)) {
                                $invalidRows[] = "Baris {$rowExcel}: leader '{$leaderName}' tidak valid atau bukan akses template Anda.";
                                continue;
                            }

                            foreach ($expectedDateByCol as $colIndex => $tanggalImport) {
                                $colLetter = Coordinate::stringFromColumnIndex($colIndex);
                                $shiftImport = canonicalShiftCode($sheetImport->getCell($colLetter . $rowExcel)->getCalculatedValue());

                                if ($shiftImport === '') {
                                    continue;
                                }

                                if (!in_array($shiftImport, $activeShiftCodes, true)) {
                                    $invalidRows[] = "Baris {$rowExcel} kolom {$colLetter}: shift '{$shiftImport}' tidak valid.";
                                    continue;
                                }

                                $stmtImport->bind_param("ssss", $leaderName, $tanggalImport, $shiftImport, $username);
                                if ($stmtImport->execute()) {
                                    $imported++;
                                } else {
                                    $invalidRows[] = "Baris {$rowExcel} kolom {$colLetter}: gagal simpan - " . $stmtImport->error;
                                }
                            }
                        }

                        $stmtImport->close();

                        if ($imported > 0) {
                            $success = "Import Excel selesai. {$imported} jadwal berhasil disimpan/update.";
                        } else {
                            $error = "Tidak ada jadwal yang berhasil diimport. Pastikan nama leader dan kode shift terisi sesuai template.";
                        }

                        if (!empty($invalidRows)) {
                            $error .= ($error !== '' ? " " : "") . "Catatan: " . implode(" | ", array_slice($invalidRows, 0, 8));
                            if (count($invalidRows) > 8) {
                                $error .= " | dan " . (count($invalidRows) - 8) . " error lainnya.";
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $error = "Gagal membaca file Excel: " . $e->getMessage();
                }
            }
        }
    }

    if ($action === 'save_jadwal') {
        $leader = trim($_POST['leader'] ?? '');
        $selectedDate = trim($_POST['selected_date'] ?? '');
        $shift = canonicalShiftCode($_POST['shift'] ?? '');

        if (!in_array($leader, $manageableLeaders, true)) {
            $error = "Leader tidak valid atau bukan akses Anda.";
        } elseif ($selectedDate === '' || $selectedDate < $startDate || $selectedDate > $endDate) {
            $error = "Tanggal periode tidak valid.";
        } elseif (!in_array($shift, $activeShiftCodes, true)) {
            $error = "Shift tidak valid.";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO jadwal_supervisor (supervisor_name, tanggal, shift, updated_by)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    shift = VALUES(shift),
                    updated_by = VALUES(updated_by),
                    updated_at = CURRENT_TIMESTAMP
            ");

            if ($stmt) {
                $stmt->bind_param("ssss", $leader, $selectedDate, $shift, $username);
                if ($stmt->execute()) {
                    $success = "Jadwal berhasil disimpan / diupdate.";
                } else {
                    $error = "Gagal menyimpan jadwal: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error = "Prepare statement simpan gagal.";
            }
        }
    }

    if ($action === 'delete_one') {
        $leader = trim($_POST['leader'] ?? '');
        $selectedDate = trim($_POST['selected_date'] ?? '');

        if (!in_array($leader, $manageableLeaders, true)) {
            $error = "Leader tidak valid atau bukan akses Anda.";
        } elseif ($selectedDate === '' || $selectedDate < $startDate || $selectedDate > $endDate) {
            $error = "Tanggal periode tidak valid.";
        } else {
            $stmt = $conn->prepare("
                DELETE FROM jadwal_supervisor
                WHERE supervisor_name = ? AND tanggal = ?
            ");

            if ($stmt) {
                $stmt->bind_param("ss", $leader, $selectedDate);
                if ($stmt->execute()) {
                    $success = "Jadwal tanggal terpilih berhasil dihapus.";
                } else {
                    $error = "Gagal menghapus jadwal: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error = "Prepare statement hapus gagal.";
            }
        }
    }

    if ($action === 'delete_range') {
        $leader = trim($_POST['delete_range_leader'] ?? '');
        $dateFrom = trim($_POST['delete_from_date'] ?? '');
        $dateTo = trim($_POST['delete_to_date'] ?? '');

        if (!in_array($leader, $manageableLeaders, true)) {
            $error = "Leader tidak valid atau bukan akses Anda.";
        } elseif ($dateFrom === '' || $dateTo === '' || $dateFrom < $startDate || $dateFrom > $endDate || $dateTo < $startDate || $dateTo > $endDate) {
            $error = "Rentang tanggal harus masih dalam periode {$periodTitle}.";
        } elseif ($dateTo < $dateFrom) {
            $error = "Tanggal sampai tidak boleh lebih kecil dari tanggal dari.";
        } else {
            $stmt = $conn->prepare("
                DELETE FROM jadwal_supervisor
                WHERE supervisor_name = ? AND tanggal BETWEEN ? AND ?
            ");

            if ($stmt) {
                $stmt->bind_param("sss", $leader, $dateFrom, $dateTo);
                if ($stmt->execute()) {
                    $success = "Jadwal {$leader} tanggal " . date('d-m-Y', strtotime($dateFrom)) . " s/d " . date('d-m-Y', strtotime($dateTo)) . " berhasil dihapus.";
                } else {
                    $error = "Gagal menghapus rentang jadwal: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error = "Prepare statement hapus rentang gagal.";
            }
        }
    }

    if ($action === 'delete_month') {
        $confirmMonthDelete = $_POST['confirm_delete_month'] ?? '';
        if ($confirmMonthDelete !== 'YES') {
            $error = "Untuk hapus 1 periode penuh, centang konfirmasi dulu.";
        } else {
            if ($canEditAll) {
                $stmt = $conn->prepare("
                    DELETE FROM jadwal_supervisor
                    WHERE tanggal BETWEEN ? AND ?
                ");

                if ($stmt) {
                    $stmt->bind_param("ss", $startDate, $endDate);
                    if ($stmt->execute()) {
                        $success = "Semua jadwal periode {$periodTitle} berhasil dihapus.";
                    } else {
                        $error = "Gagal menghapus jadwal satu periode: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error = "Prepare statement hapus periode gagal.";
                }
            } else {
                if (empty($manageableLeaders)) {
                    $error = "Tidak ada leader dalam akses Anda untuk dihapus.";
                } else {
                    $placeholders = implode(',', array_fill(0, count($manageableLeaders), '?'));
                    $sql = "DELETE FROM jadwal_supervisor WHERE tanggal BETWEEN ? AND ? AND supervisor_name IN ({$placeholders})";
                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        $types = 'ss' . str_repeat('s', count($manageableLeaders));
                        $params = array_merge([$startDate, $endDate], $manageableLeaders);
                        $stmt->bind_param($types, ...$params);
                        if ($stmt->execute()) {
                            $success = "Jadwal periode {$periodTitle} untuk tim Anda berhasil dihapus.";
                        } else {
                            $error = "Gagal menghapus jadwal satu periode: " . $stmt->error;
                        }
                        $stmt->close();
                    } else {
                        $error = "Prepare statement hapus periode gagal.";
                    }
                }
            }
        }
    }

    if ($action === 'set_not_schedule') {
        $leader = trim($_POST['not_schedule_leader'] ?? '');

        if (!in_array($leader, $manageableLeaders, true)) {
            $error = "Leader / SPV tidak valid atau bukan akses Anda.";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO jadwal_supervisor (supervisor_name, tanggal, shift, updated_by)
                VALUES (?, ?, 'NOT_SCHEDULE', ?)
                ON DUPLICATE KEY UPDATE
                    shift = 'NOT_SCHEDULE',
                    updated_by = VALUES(updated_by),
                    updated_at = CURRENT_TIMESTAMP
            ");

            if (!$stmt) {
                $error = "Prepare statement Set Not Schedule gagal: " . $conn->error;
            } else {
                $updated = 0;
                foreach ($periodDays as $day) {
                    $tglNotSchedule = $day['full_date'];
                    $stmt->bind_param("sss", $leader, $tglNotSchedule, $username);
                    if ($stmt->execute()) {
                        $updated++;
                    }
                }
                $stmt->close();

                if ($updated > 0) {
                    $success = "Leader / SPV {$leader} berhasil di-set NOT SCHEDULE untuk periode {$periodTitle}. User ini bisa absen tanpa jadwal shift.";
                } else {
                    $error = "Tidak ada data jadwal yang berhasil di-set NOT SCHEDULE.";
                }
            }
        }
    }

    if ($action === 'add_leader') {
        if (!$canEditAll) {
            $error = "Akses tambah leader hanya untuk editor utama.";
        } else {
        $newLeaderName = strtoupper(trim($_POST['new_leader_name'] ?? ''));
        $newPosition   = trim($_POST['new_position_name'] ?? 'Leader / SPV');

        if ($newLeaderName === '') {
            $error = "Nama leader wajib diisi.";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO master_leader (leader_name, position_name, is_active, created_by)
                VALUES (?, ?, 1, ?)
            ");

            if ($stmt) {
                $stmt->bind_param("sss", $newLeaderName, $newPosition, $username);
                if ($stmt->execute()) {
                    $success = "Leader baru berhasil ditambahkan.";
                } else {
                    $error = "Gagal menambah leader: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error = "Prepare statement tambah leader gagal.";
            }
        }
        }
    }

    if ($action === 'update_position') {
        if (!$canEditAll) {
            $error = "Akses ubah jabatan hanya untuk editor utama.";
        } else {
        $leader = trim($_POST['position_leader'] ?? '');
        $positionName = trim($_POST['position_name'] ?? '');

        if (!in_array($leader, $allLeaders, true)) {
            $error = "Leader tidak valid.";
        } elseif ($positionName === '') {
            $error = "Jabatan wajib diisi.";
        } else {
            $stmt = $conn->prepare("
                UPDATE master_leader
                SET position_name = ?
                WHERE leader_name = ?
            ");

            if ($stmt) {
                $stmt->bind_param("ss", $positionName, $leader);
                if ($stmt->execute()) {
                    $success = "Jabatan berhasil diupdate.";
                    $leaderMeta[$leader]['position_name'] = $positionName;
                } else {
                    $error = "Gagal update jabatan: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error = "Prepare statement update jabatan gagal.";
            }
        }
        }
    }

    if ($action === 'delete_leader') {
        if (!$canEditAll) {
            $error = "Akses hapus leader / SPV hanya untuk editor utama atau SUPERADMIN.";
        } else {
            $leader = trim($_POST['delete_leader_name'] ?? '');

            if (!in_array($leader, $allLeaders, true)) {
                $error = "Leader / SPV tidak valid.";
            } else {
                if (tableColumnExists($conn, 'master_leader', 'is_active')) {
                    $setParts = ['is_active = 0'];
                    $values = [];
                    $types = '';

                    if (tableColumnExists($conn, 'master_leader', 'updated_by')) {
                        $setParts[] = 'updated_by = ?';
                        $values[] = $username;
                        $types .= 's';
                    }

                    if (tableColumnExists($conn, 'master_leader', 'updated_at')) {
                        $setParts[] = 'updated_at = CURRENT_TIMESTAMP';
                    }

                    $values[] = $leader;
                    $types .= 's';

                    $sql = 'UPDATE master_leader SET ' . implode(', ', $setParts) . ' WHERE leader_name = ? LIMIT 1';
                    $stmt = $conn->prepare($sql);

                    if ($stmt) {
                        $stmt->bind_param($types, ...$values);
                        if ($stmt->execute()) {
                            $success = "Leader / SPV {$leader} berhasil dihapus dari daftar aktif.";
                        } else {
                            $error = "Gagal menghapus leader / SPV: " . $stmt->error;
                        }
                        $stmt->close();
                    } else {
                        $error = "Prepare statement hapus leader / SPV gagal.";
                    }
                } else {
                    $stmt = $conn->prepare("DELETE FROM master_leader WHERE leader_name = ? LIMIT 1");

                    if ($stmt) {
                        $stmt->bind_param("s", $leader);
                        if ($stmt->execute()) {
                            $success = "Leader / SPV {$leader} berhasil dihapus.";
                        } else {
                            $error = "Gagal menghapus leader / SPV: " . $stmt->error;
                        }
                        $stmt->close();
                    } else {
                        $error = "Prepare statement hapus leader / SPV gagal.";
                    }
                }
            }
        }
    }

    if ($action === 'add_shift') {
        if (!$canEditAll) {
            $error = "Akses pengaturan shift hanya untuk editor utama.";
        } else {
        $newShiftCodeRaw = strtoupper(trim($_POST['new_shift_code'] ?? ''));
        $newShiftName    = strtoupper(trim($_POST['new_shift_name'] ?? ''));
        $newShiftStart   = normalizeTimeValue($_POST['new_shift_start'] ?? '');
        $newShiftEnd     = normalizeTimeValue($_POST['new_shift_end'] ?? '');

        if ($newShiftName === '') {
            $error = "Nama shift wajib diisi.";
        } else {
            // Kalau kode kosong, sistem bikin dari nama shift.
            // Contoh: SHIFT 4 => SHIFT_4. Kalau mau kode pendek, isi Kode Shift misalnya S4.
            $newShiftCode = $newShiftCodeRaw !== ''
                ? canonicalShiftCode(normalizeShiftCode($newShiftCodeRaw))
                : canonicalShiftCode(normalizeShiftCode($newShiftName));

            if (saveShiftSettingRow($conn, $newShiftCode, $newShiftName, $newShiftStart, $newShiftEnd, 1, $username, $error)) {
                $success = "Shift {$newShiftCode} berhasil ditambahkan dan sudah aktif.";

                // Reload langsung supaya Pengaturan Shift dan dropdown langsung ikut update.
                $shiftSettings = loadShiftSettingsFromDb($conn, $defaultShiftSeed);
                $activeShiftCodes = buildActiveShiftCodes($shiftSettings);
            }
        }
        }
    }
    if ($action === 'save_shift_list') {
        if (!$canEditAll) {
            $error = "Akses pengaturan shift hanya untuk editor utama.";
        } else {
        $codes   = $_POST['shift_code_existing'] ?? [];
        $names   = $_POST['shift_name_existing'] ?? [];
        $starts  = $_POST['shift_start_existing'] ?? [];
        $ends    = $_POST['shift_end_existing'] ?? [];
        $actives = $_POST['shift_active_existing'] ?? [];

        $ok = true;

        for ($i = 0; $i < count($codes); $i++) {
            $code  = canonicalShiftCode($codes[$i] ?? '');
            $name  = strtoupper(trim((string)($names[$code] ?? $names[$i] ?? '')));

            $startRaw = $starts[$code] ?? $starts[$i] ?? null;
            $endRaw   = $ends[$code] ?? $ends[$i] ?? null;

            $oldStart = $shiftSettings[$code]['start_time'] ?? null;
            $oldEnd   = $shiftSettings[$code]['end_time'] ?? null;

            $start = normalizeTimeValue($startRaw ?? '');
            $end   = normalizeTimeValue($endRaw ?? '');

            if ($start === null && $oldStart !== null && trim((string)$oldStart) !== '') {
                $start = $oldStart;
            }
            if ($end === null && $oldEnd !== null && trim((string)$oldEnd) !== '') {
                $end = $oldEnd;
            }

            $isActive = isset($actives[$code]) ? 1 : 0;

            if ($code === '' || $name === '') {
                continue;
            }

            if (in_array($code, ['OFF', 'CUTI'], true)) {
                $start = null;
                $end = null;
                $isActive = 1;
            }

            if (!saveShiftSettingRow($conn, $code, $name, $start, $end, $isActive, $username, $error)) {
                $ok = false;
                break;
            }
        }

        if ($ok) {
            $success = "Daftar shift berhasil diupdate.";
            $shiftSettings = loadShiftSettingsFromDb($conn, $defaultShiftSeed);
            $activeShiftCodes = buildActiveShiftCodes($shiftSettings);
        }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$canEdit) {
    $error = "Anda tidak memiliki hak untuk mengubah data.";
}

/* =========================================================================
| RELOAD DATA AFTER ACTION
* ========================================================================= */
$allLeaders = [];
$leaderMeta = [];

$resLeader = $conn->query("SELECT leader_name, position_name, is_active FROM master_leader WHERE is_active = 1 ORDER BY leader_name ASC");
if ($resLeader) {
    while ($row = $resLeader->fetch_assoc()) {
        $leaderName = trim((string)$row['leader_name']);
        $positionName = trim((string)($row['position_name'] ?? 'Leader / SPV'));
        if ($leaderName !== '') {
            $allLeaders[] = $leaderName;
            $leaderMeta[$leaderName] = [
                'position_name' => $positionName !== '' ? $positionName : 'Leader / SPV'
            ];
        }
    }
}
if (empty($allLeaders)) {
    $allLeaders = [
        "UJANG FIRMANSYAH",
        "CHOTIBATUL UMAM",
        "MUHAMMAD MUSTAQIM"
    ];
    foreach ($allLeaders as $nm) {
        $leaderMeta[$nm] = ['position_name' => 'Leader / SPV'];
    }
}

$shiftSettings = loadShiftSettingsFromDb($conn, $defaultShiftSeed);
$activeShiftCodes = buildActiveShiftCodes($shiftSettings);

$visibleLeaders = filterVisibleLeadersByAccess($allLeaders, $username, $canEdit, $leaderMeta);
$manageableLeaders = $canEditAll ? $allLeaders : $visibleLeaders;

/* =========================================================================
| LOAD JADWAL SESUAI PERIODE 26-25
* ========================================================================= */
$jadwalData = [];

$stmt = $conn->prepare("
    SELECT supervisor_name, tanggal, shift
    FROM jadwal_supervisor
    WHERE tanggal BETWEEN ? AND ?
");
if ($stmt) {
    $stmt->bind_param("ss", $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $name = trim((string)$row['supervisor_name']);
        $tgl  = trim((string)$row['tanggal']);
        $jadwalData[$name][$tgl] = canonicalShiftCode($row['shift'] ?? '');
    }
    $stmt->close();
}

/* =========================================================================
| DOWNLOAD TEMPLATE IMPORT EXCEL - BERDASARKAN LEADER YANG DICENTANG
* ========================================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit && ($_POST['action_type'] ?? '') === 'download_template_selected') {
    $selectedLeaders = $_POST['template_leaders'] ?? [];
    if (!is_array($selectedLeaders)) {
        $selectedLeaders = [];
    }

    $selectedLeaders = array_values(array_filter($exportTemplateLeaders, function ($leader) use ($selectedLeaders) {
        return in_array($leader, $selectedLeaders, true);
    }));

    if (empty($selectedLeaders)) {
        $selectedLeaders = $exportTemplateLeaders;
    }

    $templateFromDate = trim((string)($_POST['template_from_date'] ?? ''));
    $templateToDate   = trim((string)($_POST['template_to_date'] ?? ''));

    if ($templateFromDate === '') {
        $templateFromDate = $startDate;
    }
    if ($templateToDate === '') {
        $templateToDate = $endDate;
    }

    if ($templateFromDate < $startDate || $templateFromDate > $endDate || $templateToDate < $startDate || $templateToDate > $endDate) {
        $error = "Rentang tanggal template harus masih dalam periode {$periodTitle}.";
    } elseif ($templateToDate < $templateFromDate) {
        $error = "Tanggal sampai tidak boleh lebih kecil dari tanggal dari.";
    } else {
        $templatePeriodDays = array_values(array_filter($periodDays, function ($day) use ($templateFromDate, $templateToDate) {
            return $day['full_date'] >= $templateFromDate && $day['full_date'] <= $templateToDate;
        }));

        if (empty($templatePeriodDays)) {
            $error = "Tanggal template tidak ditemukan dalam periode aktif.";
        } else {
            $templatePeriodTitle = getPeriodTitle($templateFromDate, $templateToDate, $namaBulan);
            downloadJadwalTemplateExcel($templatePeriodTitle, $templatePeriodDays, $selectedLeaders, $leaderMeta, $shiftSettings, $activeShiftCodes, $jadwalData, $namaBulan);
        }
    }
}

/* =========================================================================
| TODAY SHIFT
* ========================================================================= */
$todayShift = [];
foreach ($allLeaders as $leader) {
    $todayShift[$leader] = 'BELUM DISET';
}

$stmtToday = $conn->prepare("
    SELECT supervisor_name, shift
    FROM jadwal_supervisor
    WHERE tanggal = ?
");
if ($stmtToday) {
    $stmtToday->bind_param("s", $today);
    $stmtToday->execute();
    $resToday = $stmtToday->get_result();

    while ($row = $resToday->fetch_assoc()) {
        $todayShift[$row['supervisor_name']] = canonicalShiftCode($row['shift'] ?? '');
    }
    $stmtToday->close();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jadwal Leader / SPV</title>
    <link rel="icon" type="image/png" href="img/srt2.png" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <style>
        * { box-sizing: border-box; }

        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #071a2f, #0d2c52, #15457a);
            min-height: 100vh;
            margin: 0;
            padding: 24px;
            color: #fff;
        }

        .container {
            max-width: 1900px;
            margin: 0 auto;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .btn-back,
        .btn-nav {
            display: inline-block;
            padding: 10px 18px;
            background: linear-gradient(135deg, #0d2a52, #174a8b);
            color: #fff;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
            box-shadow: 0 4px 12px rgba(0,0,0,0.25);
            border: none;
        }

        .nav-wrap {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .period-badge {
            background: rgba(255,255,255,0.12);
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.15);
            font-size: 14px;
            font-weight: bold;
        }

        .user-badge {
            background: rgba(255,255,255,0.1);
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 14px;
            border: 1px solid rgba(255,255,255,0.12);
        }

        .header-box,
        .panel,
        .table-section {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(8px);
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,0.12);
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
        }

        .header-box {
            padding: 28px 20px;
            text-align: center;
            margin-bottom: 24px;
        }

        .header-box h1 {
            margin: 0 0 8px;
            font-size: 30px;
            color: #ffffff;
            letter-spacing: 1px;
        }

        .header-box h2 {
            margin: 0;
            font-size: 18px;
            color: #dbeafe;
            font-weight: normal;
        }

        .icon-title {
            font-size: 38px;
            margin-bottom: 14px;
            color: #93c5fd;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(310px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .card {
            background: #ffffff;
            border-radius: 18px;
            padding: 22px;
            color: #1e293b;
            box-shadow: 0 10px 25px rgba(0,0,0,0.18);
            border-top: 6px solid #1d4ed8;
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: "";
            position: absolute;
            width: 120px;
            height: 120px;
            background: rgba(37, 99, 235, 0.08);
            border-radius: 50%;
            top: -30px;
            right: -30px;
        }

        .avatar {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1d4ed8, #2563eb);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 24px;
            margin-bottom: 14px;
            position: relative;
            z-index: 2;
        }

        .card h3 {
            margin: 0 0 12px;
            font-size: 20px;
            color: #0f172a;
            position: relative;
            z-index: 2;
        }

        .meta {
            font-size: 14px;
            color: #475569;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .today-badge {
            display: inline-block;
            margin-top: 10px;
            padding: 10px 16px;
            border-radius: 14px;
            font-size: 13px;
            font-weight: bold;
            position: relative;
            z-index: 2;
            line-height: 1.45;
        }

        .shift-1 { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }
        .shift-2 { background: #fef3c7; color: #92400e; border: 1px solid #f59e0b; }
        .shift-3 { background: #dbeafe; color: #1e3a8a; border: 1px solid #60a5fa; }
        .shift-middle { background: #fde68a; color: #92400e; border: 1px solid #f59e0b; }
        .shift-ho { background: #e0f2fe; color: #075985; border: 1px solid #38bdf8; }
        .shift-off { background: #e5e7eb; color: #374151; border: 1px solid #9ca3af; }
        .shift-not-schedule { background: #fef3c7; color: #92400e; border: 1px solid #f59e0b; }
        .shift-cuti { background: #fce7f3; color: #9d174d; border: 1px solid #f472b6; }
        .shift-default { background: #ede9fe; color: #5b21b6; border: 1px solid #a78bfa; }

        .panel {
            padding: 20px;
            margin-bottom: 24px;
        }

        .panel h3 {
            margin-top: 0;
            margin-bottom: 16px;
            color: #ffffff;
        }

        .subpanel-title {
            margin: 0 0 14px;
            color: #fff;
            font-size: 18px;
        }

        .alert-success,
        .alert-error {
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 16px;
            font-weight: bold;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .editor-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 18px;
        }

        .editor-form,
        .box-panel {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 14px;
            padding: 16px;
        }

        .stack-panel {
            display: grid;
            gap: 18px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            align-items: end;
        }

        .form-row-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            align-items: end;
        }

        .form-row-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            align-items: end;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 14px;
            color: #dbeafe;
            font-weight: bold;
        }

        .form-group select,
        .form-group input[type="text"],
        .form-group input[type="time"] {
            width: 100%;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid #93c5fd;
            outline: none;
            background: #fff;
            color: #1e293b;
            font-size: 14px;
        }

        .btn-row {
            margin-top: 14px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-main,
        .btn-danger,
        .btn-secondary {
            border: none;
            border-radius: 10px;
            color: #fff;
            font-weight: bold;
            cursor: pointer;
            padding: 11px 16px;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
        }

        .btn-main { background: linear-gradient(135deg, #2563eb, #1d4ed8); }
        .btn-danger { background: linear-gradient(135deg, #dc2626, #b91c1c); }
        .btn-secondary { background: linear-gradient(135deg, #7c3aed, #6d28d9); }

        .helper-box {
            margin-top: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            background: rgba(255,255,255,0.08);
            color: #dbeafe;
            font-size: 13px;
        }

        .excel-import-box {
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 18px;
        }

        .excel-import-title {
            margin: 0 0 12px;
            color: #fff;
            font-size: 17px;
            font-weight: bold;
        }

        .excel-import-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr auto auto;
            gap: 12px;
            align-items: end;
        }

        .file-input {
            width: 100%;
            padding: 9px 12px;
            border-radius: 10px;
            border: 1px solid #93c5fd;
            outline: none;
            background: #fff;
            color: #1e293b;
            font-size: 14px;
        }

        .template-action-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .selected-leader-info {
            color: #dbeafe;
            font-size: 13px;
            margin-top: 8px;
        }

        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.55);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .modal-backdrop.show { display: flex; }

        .leader-modal {
            width: min(720px, 100%);
            max-height: 86vh;
            overflow: hidden;
            background: #ffffff;
            color: #0f172a;
            border-radius: 18px;
            box-shadow: 0 24px 70px rgba(0,0,0,0.38);
        }

        .leader-modal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 16px 18px;
            color: #fff;
            background: linear-gradient(135deg, #0b2a4a, #123d6b);
        }

        .leader-modal-head h4 {
            margin: 0;
            font-size: 17px;
        }

        .modal-close-btn {
            border: none;
            background: rgba(255,255,255,0.14);
            color: #fff;
            width: 34px;
            height: 34px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 18px;
            font-weight: bold;
        }

        .leader-modal-body { padding: 16px 18px; }

        .leader-tools {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .leader-check-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 10px;
            max-height: 360px;
            overflow: auto;
            padding-right: 4px;
        }

        .leader-check-item {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            padding: 10px 12px;
            border: 1px solid #dbe4f0;
            border-radius: 12px;
            background: #f8fbff;
            cursor: pointer;
        }

        .leader-check-item input {
            margin-top: 3px;
            transform: scale(1.1);
        }

        .leader-check-item strong {
            display: block;
            font-size: 14px;
        }

        .leader-check-item span span {
            display: block;
            margin-top: 2px;
            font-size: 12px;
            color: #475569;
        }

        .leader-modal-foot {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
            padding: 14px 18px;
            background: #f1f5f9;
            border-top: 1px solid #dbe4f0;
        }

        .mapping-list {
            margin-top: 10px;
            font-size: 13px;
            color: #dbeafe;
            line-height: 1.6;
            max-height: 180px;
            overflow: auto;
            padding-right: 4px;
        }

        .mapping-list div {
            padding: 4px 0;
            border-bottom: 1px dashed rgba(255,255,255,0.12);
        }

        .table-section {
            padding: 20px;
            overflow-x: auto;
        }

        .table-title {
            margin-top: 0;
            margin-bottom: 16px;
            color: #fff;
        }

        .legend {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .legend span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,0.1);
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 12px;
        }

        table {
            width: 100%;
            min-width: 2450px;
            border-collapse: collapse;
            background: #ffffff;
            color: #1e293b;
            border-radius: 14px;
            overflow: hidden;
        }

        thead th {
            background: linear-gradient(135deg, #0b2a4a, #123d6b);
            color: #fff;
            padding: 10px 6px;
            font-size: 12px;
            text-transform: uppercase;
            white-space: nowrap;
            border: 1px solid #1d4f91;
        }

        tbody td {
            border: 1px solid #dbe4f0;
            text-align: center;
            padding: 8px 8px;
            font-size: 13px;
            font-weight: bold;
            min-width: 170px;
            height: 88px;
            vertical-align: middle;
        }

        tbody tr:nth-child(even) {
            background: #f8fbff;
        }

        .name-col {
    text-align: left !important;
    padding-left: 14px !important;
    min-width: 260px !important;
    font-size: 14px;
    background: #eff6ff;
    color: #0f172a;

    position: sticky;
    left: 0;
    z-index: 5; /* ?? ini penting */
}

        thead .name-col {
    position: sticky;
    left: 0;
    top: 0;
    z-index: 6; /* lebih tinggi dari body */
}

thead th {
    position: sticky;
    top: 0;
    z-index: 4;
}
        .day-head {
            line-height: 1.35;
        }

        .day-head .tgl {
            font-size: 14px;
            font-weight: bold;
        }

        .day-head .hari {
            font-size: 11px;
            opacity: 0.9;
        }

        .day-head .bln {
            font-size: 10px;
            opacity: 0.85;
        }

        .shift-box-text {
            display: block;
            line-height: 1.4;
            font-size: 13px;
            white-space: normal;
            word-break: break-word;
        }

        .cell-shift1 { background: #d1fae5 !important; color: #065f46 !important; border-color: #34d399 !important; }
        .cell-shift2 { background: #fef3c7 !important; color: #92400e !important; border-color: #f59e0b !important; }
        .cell-shift3 { background: #dbeafe !important; color: #1e3a8a !important; border-color: #60a5fa !important; }
        .cell-middle { background: #fde68a !important; color: #92400e !important; border-color: #f59e0b !important; }
        .cell-ho { background: #e0f2fe !important; color: #075985 !important; border-color: #38bdf8 !important; }
        .cell-off { background: #e5e7eb !important; color: #374151 !important; border-color: #9ca3af !important; }
        .cell-not-schedule { background: #fef3c7 !important; color: #92400e !important; border-color: #f59e0b !important; font-weight:800; }
        .cell-cuti { background: #fce7f3 !important; color: #9d174d !important; border-color: #f472b6 !important; }
        .cell-default { background: #ede9fe !important; color: #5b21b6 !important; border-color: #a78bfa !important; }

        .editable-cell { cursor: pointer; }
        .editable-cell:hover {
            outline: 2px solid #2563eb;
            box-shadow: inset 0 0 0 999px rgba(37, 99, 235, 0.08);
        }

        .selected-cell {
            outline: 3px solid #dc2626 !important;
            box-shadow: inset 0 0 0 999px rgba(220, 38, 38, 0.08);
        }

        .empty-user-box {
            padding: 18px;
            border-radius: 14px;
            background: rgba(255,255,255,0.08);
            color: #fff;
            text-align: center;
            border: 1px solid rgba(255,255,255,0.12);
        }

        .shift-list-table {
            width: 100%;
            min-width: 100%;
            background: transparent;
            border-collapse: separate;
            border-spacing: 0 10px;
        }

        .shift-list-table td,
        .shift-list-table th {
            border: none;
            background: transparent;
            padding: 0 6px;
            min-width: auto;
            height: auto;
            color: #fff;
            text-align: left;
        }

        .shift-row-box {
            background: rgba(255,255,255,0.07);
            border-radius: 12px;
            padding: 12px;
        }

        details.setting-box {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 14px;
            overflow: hidden;
        }

        details.setting-box summary {
            list-style: none;
            cursor: pointer;
            padding: 16px;
            font-weight: bold;
            color: #fff;
            background: rgba(255,255,255,0.05);
            user-select: none;
        }

        details.setting-box summary::-webkit-details-marker {
            display: none;
        }

        details.setting-box summary::after {
            content: "+";
            float: right;
            font-size: 20px;
            line-height: 1;
        }

        details.setting-box[open] summary::after {
            content: "-";
        }

        .setting-content {
            padding: 16px;
            border-top: 1px solid rgba(255,255,255,0.08);
        }

        .note-period {
            margin-top: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            background: rgba(255,255,255,0.08);
            color: #dbeafe;
            font-size: 13px;
            line-height: 1.5;
        }



        .schedule-name-action { cursor: context-menu; }
        .schedule-name-action:hover { outline: 2px solid #f59e0b; box-shadow: inset 0 0 0 999px rgba(245,158,11,.08); }
        .delete-range-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 2147483000;
            background: rgba(2, 6, 23, .62);
            backdrop-filter: blur(8px);
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .delete-range-modal.show { display: flex; }
        .delete-range-card {
            width: min(480px, 100%);
            background: #ffffff;
            color: #0f172a;
            border-radius: 20px;
            box-shadow: 0 28px 80px rgba(0,0,0,.42);
            overflow: hidden;
        }
        .delete-range-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 18px;
            color: #fff;
            background: linear-gradient(135deg, #991b1b, #dc2626);
        }
        .delete-range-head h4 { margin: 0; font-size: 17px; }
        .delete-range-body { padding: 18px; }
        .delete-range-body .form-group label { color: #334155; }
        .delete-range-body .form-group input {
            width: 100%;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #0f172a;
            font-size: 14px;
        }
        .delete-range-leader-box {
            padding: 12px;
            border-radius: 14px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            margin-bottom: 14px;
            font-weight: bold;
            color: #0f172a;
        }
        .delete-range-foot {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
            padding: 14px 18px;
            background: #f1f5f9;
            border-top: 1px solid #e2e8f0;
        }

        @media (max-width: 1200px) {
            .editor-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 992px) {
            .form-row,
            .form-row-2,
            .form-row-3,
            .excel-import-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            body { padding: 14px; }
            .header-box h1 { font-size: 22px; line-height: 1.4; }
            .header-box h2 { font-size: 15px; }
            table { min-width: 2000px; }
        }
    </style>
</head>
<body>
<div class="container">

    <div class="topbar">
        <a href="dashboard.php" class="btn-back">
            <i class="fa-solid fa-arrow-left"></i> Kembali
        </a>

        <div class="nav-wrap">
            <a class="btn-nav" href="?period_month=<?php echo $prevNav['month']; ?>&period_year=<?php echo $prevNav['year']; ?>">
                <i class="fa-solid fa-chevron-left"></i> Periode Sebelumnya
            </a>

            <div class="period-badge">
                <i class="fa-solid fa-calendar-week"></i>
                <?php echo h($periodTitle); ?>
            </div>

            <a class="btn-nav" href="?period_month=<?php echo $nextNav['month']; ?>&period_year=<?php echo $nextNav['year']; ?>">
                Periode Berikutnya <i class="fa-solid fa-chevron-right"></i>
            </a>
        </div>

        <div class="user-badge">
            <i class="fa-solid fa-user"></i>
            Login sebagai: <strong><?php echo h($_SESSION['username']); ?></strong>
            <?php if ($canEditAll): ?>
                | <span style="color:#86efac;">Editor Jadwal Penuh</span>
            <?php elseif ($canEdit): ?>
                | <span style="color:#86efac;">Editor Jadwal Tim</span>
            <?php elseif ($username === 'wahid'): ?>
                | <span style="color:#93c5fd;">View All (Read Only)</span>
            <?php else: ?>
                | <span style="color:#fcd34d;">View Jadwal Pribadi</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="header-box">
        <div class="icon-title">
            <i class="fa-solid fa-calendar-days"></i>
        </div>
        <h1>JADWAL SPV LEADER</h1>
        <h2>PERIODE <?php echo h($periodTitle); ?></h2>
    </div>

    <?php if ($success): ?>
        <div class="alert-success"><?php echo h($success); ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert-error"><?php echo h($error); ?></div>
    <?php endif; ?>

    <div class="note-period" style="margin-bottom:16px;">
        <?php echo h(getAccessInfoText($username, $canEdit, $allLeaders, $leaderMeta)); ?>
    </div>

    <?php if (!$canEdit && empty($visibleLeaders)): ?>
        <div class="empty-user-box">
            Username <strong><?php echo h($_SESSION['username']); ?></strong> belum punya jadwal yang bisa ditampilkan.
            <br>Pastikan nama user sudah ada di master leader / crew leader.
        </div>
    <?php endif; ?>

    <?php if (!empty($visibleLeaders)): ?>
    <div class="cards">
        <?php foreach ($visibleLeaders as $nama): ?>
            <?php $shiftHariIni = $todayShift[$nama] ?? 'BELUM DISET'; ?>
            <div class="card">
                <div class="avatar">
                    <i class="fa-solid fa-user-tie"></i>
                </div>
                <h3><?php echo h($nama); ?></h3>
                <div class="meta">Jabatan: <?php echo h($leaderMeta[$nama]['position_name'] ?? 'Leader / SPV'); ?></div>
                <div class="meta">Hari ini: <?php echo getHariNama($today) . ', ' . date('d-m-Y', strtotime($today)); ?></div>
                <div class="today-badge <?php echo getShiftClass($shiftHariIni); ?>">
                    <i class="fa-regular fa-clock"></i>
                    Shift Today: <?php echo h(getShiftDisplay($shiftHariIni, $shiftSettings)); ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <div class="panel">
        <h3><i class="fa-solid fa-pen-to-square"></i> Pengaturan Jadwal, Jabatan, dan Shift</h3>

        <div class="excel-import-box">
            <div class="excel-import-title">
                <i class="fa-solid fa-file-excel"></i> Import Jadwal via Excel
            </div>

            <div class="excel-import-grid">
                <form method="POST" id="downloadTemplateForm">
                    <input type="hidden" name="period_month" value="<?php echo h($periodMonth); ?>">
                    <input type="hidden" name="period_year" value="<?php echo h($periodYear); ?>">

                    <div class="form-group">
                        <label>Download Template Periode Ini</label>

                       

                        <div class="template-action-row">
                            <button type="submit" name="action_type" value="download_template_selected" class="btn-secondary">
                                <i class="fa-solid fa-download"></i> Download Template Excel
                            </button>
                            <button type="button" class="btn-main" id="openLeaderPicker">
                                <i class="fa-solid fa-list-check"></i> Pilih
                            </button>
                        </div>
                        <div class="selected-leader-info" id="selectedLeaderInfo">Semua nama dicentang untuk template.</div>
                    </div>

                    <div class="modal-backdrop" id="leaderPickerModal">
                        <div class="leader-modal">
                            <div class="leader-modal-head">
                                <h4><i class="fa-solid fa-user-check"></i> Pilih Nama untuk Template Excel</h4>
                                <button type="button" class="modal-close-btn" id="closeLeaderPicker">&times;</button>
                            </div>
                            <div class="leader-modal-body">
                                <div class="leader-tools">
                                    <button type="button" class="btn-secondary" id="checkAllLeaders">Centang Semua</button>
                                    <button type="button" class="btn-danger" id="uncheckAllLeaders">Kosongkan</button>
                                </div>
                                <div class="leader-check-list">
                                    <?php foreach ($exportTemplateLeaders as $leader): ?>
                                        <label class="leader-check-item">
                                            <input type="checkbox" class="template-leader-checkbox" name="template_leaders[]" value="<?php echo h($leader); ?>" checked>
                                            <span>
                                                <strong><?php echo h($leader); ?></strong>
                                                <span><?php echo h($leaderMeta[$leader]['position_name'] ?? 'Leader / SPV'); ?></span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="leader-modal-foot">
                                <button type="button" class="btn-secondary" id="applyLeaderPicker">
                                    <i class="fa-solid fa-check"></i> Terapkan Pilihan
                                </button>
                            </div>
                        </div>
                    </div>
                </form>

                <form method="POST" enctype="multipart/form-data" style="display:contents;">
                    <div class="form-group">
                        <label>Upload File Excel yang Sudah Diisi</label>
                        <input type="file" name="import_file" class="file-input" accept=".xlsx,.xls" required>
                    </div>

                    <button type="submit" name="action_type" value="import_excel" class="btn-main"
                        onclick="return confirm('Import jadwal dari Excel untuk periode ini? Data yang sudah ada akan diupdate.');">
                        <i class="fa-solid fa-upload"></i> Import Excel
                    </button>

                    <div class="helper-box" style="margin-top:0;">
                        Klik <strong>Pilih</strong>, Silahkan centang nama yang anda pilih untuk mengatur jadwal                                           
                    </div>
                </form>
            </div>
        </div>

        <div class="editor-grid">
            <div class="editor-form">
                <h4 class="subpanel-title">Edit Jadwal</h4>

                <form method="POST" id="jadwalForm">
                    <input type="hidden" name="selected_date" id="selected_date" value="">

                    <div class="form-row">
                        <div class="form-group">
                            <label>Leader</label>
                            <select name="leader" id="leader" required>
                                <option value="">-- Pilih Leader --</option>
                                <?php foreach ($manageableLeaders as $leader): ?>
                                    <option value="<?php echo h($leader); ?>"><?php echo h($leader); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Tanggal Periode</label>
                            <select id="tanggal_view" required>
                                <option value="">-- Pilih Tanggal --</option>
                                <?php foreach ($periodDays as $item): ?>
                                    <option value="<?php echo h($item['full_date']); ?>">
                                        <?php echo h($item['day_name'] . ', ' . date('d-m-Y', strtotime($item['full_date']))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Shift</label>
                            <select name="shift" id="shift" required>
                                <option value="">-- Pilih Shift --</option>
                                <?php foreach ($activeShiftCodes as $code): ?>
                                    <option value="<?php echo h($code); ?>">
                                        <?php echo h(getShiftDisplay($code, $shiftSettings)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Status Pilihan</label>
                            <input type="text" id="selected_info" value="Belum pilih kotak" readonly>
                        </div>
                    </div>

                    <div class="btn-row">
                        <button type="submit" name="action_type" value="save_jadwal" class="btn-main">
                            <i class="fa-solid fa-floppy-disk"></i> Simpan / Update
                        </button>

                        <button type="submit" name="action_type" value="delete_one" class="btn-danger"
                            onclick="return confirm('Hapus jadwal untuk leader dan tanggal yang dipilih?');">
                            <i class="fa-solid fa-trash"></i> Hapus Tanggal Dipilih
                        </button>
                    </div>

                    <div class="helper-box">
                        Klik kotak tabel di bawah untuk edit cepat per tanggal. Sistem sekarang pakai periode 26 s/d 25.
                    </div>
                </form>
            </div>

            <div class="stack-panel">

                <details class="setting-box">
                    <summary>Tambah Leader Baru</summary>
                    <div class="setting-content">
                        <form method="POST">
                            <div class="form-row-2">
                                <div class="form-group">
                                    <label>Nama Leader</label>
                                    <input type="text" name="new_leader_name" placeholder="Contoh: WULANDARI" required>
                                </div>
                                <div class="form-group">
                                    <label>Jabatan</label>
                                    <input type="text" name="new_position_name" placeholder="Contoh: LEADER TOKO" required>
                                </div>
                            </div>
                            <div class="btn-row">
                                <button type="submit" name="action_type" value="add_leader" class="btn-main">
                                    <i class="fa-solid fa-plus"></i> Simpan Leader
                                </button>
                            </div>
                        </form>
                    </div>
                </details>

                <details class="setting-box">
                    <summary>Ubah Jabatan Leader</summary>
                    <div class="setting-content">
                        <form method="POST">
                            <div class="form-row-2">
                                <div class="form-group">
                                    <label>Leader</label>
                                    <select name="position_leader" required>
                                        <option value="">-- Pilih Leader --</option>
                                        <?php foreach ($allLeaders as $leader): ?>
                                            <option value="<?php echo h($leader); ?>"><?php echo h($leader); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Jabatan Baru</label>
                                    <input type="text" name="position_name" placeholder="Contoh: SUPERVISOR AREA" required>
                                </div>
                            </div>
                            <div class="btn-row">
                                <button type="submit" name="action_type" value="update_position" class="btn-secondary">
                                    <i class="fa-solid fa-briefcase"></i> Update Jabatan
                                </button>
                            </div>
                        </form>
                    </div>
                </details>

                <details class="setting-box">
                    <summary>Hapus Nama Leader / SPV</summary>
                    <div class="setting-content">
                        <form method="POST">
                            <div class="form-group">
                                <label>Pilih Leader / SPV yang akan dihapus</label>
                                <select name="delete_leader_name" required>
                                    <option value="">-- Pilih Leader / SPV --</option>
                                    <?php foreach ($allLeaders as $leader): ?>
                                        <option value="<?php echo h($leader); ?>"><?php echo h($leader); ?> - <?php echo h($leaderMeta[$leader]['position_name'] ?? 'Leader / SPV'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="helper-box" style="margin-bottom:12px;">
                                Data akan dihapus dari daftar aktif master leader. Jadwal lama tidak ikut dihapus.
                            </div>

                            <div class="btn-row">
                                <button type="submit" name="action_type" value="delete_leader" class="btn-danger"
                                    onclick="return confirm('Yakin hapus nama Leader / SPV ini dari daftar aktif?');">
                                    <i class="fa-solid fa-trash"></i> Hapus Leader / SPV
                                </button>
                            </div>
                        </form>
                    </div>
                </details>

           

                <details class="setting-box">
                    <summary>Pengaturan Shift</summary>
                    <div class="setting-content">
                        <form method="POST" style="margin-bottom:18px;">
                            <div class="shift-row-box">
                                <h4 class="subpanel-title" style="margin-bottom:12px;">
                                    <i class="fa-solid fa-plus"></i> Tambah Shift Baru
                                </h4>
                                <div class="form-row" style="grid-template-columns: 1fr 1.5fr 1fr 1fr auto;">
                                    <div class="form-group">
                                        <label>Kode Shift</label>
                                        <input type="text" name="new_shift_code" placeholder="Contoh: HO / S4 / MDP">
                                    </div>
                                    <div class="form-group">
                                        <label>Nama Shift</label>
                                        <input type="text" name="new_shift_name" placeholder="Contoh: HEAD OFFICE" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Mulai</label>
                                        <input type="time" name="new_shift_start">
                                    </div>
                                    <div class="form-group">
                                        <label>Selesai</label>
                                        <input type="time" name="new_shift_end">
                                    </div>
                                    <div class="form-group">
                                        <label>&nbsp;</label>
                                        <button type="submit" name="action_type" value="add_shift" class="btn-main">
                                            <i class="fa-solid fa-plus"></i> Tambah Shift
                                        </button>
                                    </div>
                                </div>
                                <div class="helper-box">
                                    Setelah disimpan, shift langsung aktif dan otomatis masuk ke dropdown Edit Jadwal, template Excel, dan validasi import.
                                </div>
                            </div>
                        </form>

                        <form method="POST">
                            <table class="shift-list-table">
                                <thead>
                                    <tr>
                                        <th>Kode</th>
                                        <th>Nama Shift</th>
                                        <th>Mulai</th>
                                        <th>Selesai</th>
                                        <th>Aktif</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($shiftSettings as $code => $cfg): ?>
                                        <tr>
                                            <td colspan="5">
                                                <div class="shift-row-box">
                                                    <div class="form-row" style="grid-template-columns: 1fr 1.5fr 1fr 1fr .6fr;">
                                                        <div class="form-group">
                                                            <label>Kode</label>
                                                            <input type="text" value="<?php echo h($code); ?>" readonly>
                                                            <input type="hidden" name="shift_code_existing[]" value="<?php echo h($code); ?>">
                                                        </div>
                                                        <div class="form-group">
                                                            <label>Nama Shift</label>
                                                            <input type="text" name="shift_name_existing[<?php echo h($code); ?>]" value="<?php echo h($cfg['shift_name']); ?>" required>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>Mulai</label>
                                                            <input type="time" name="shift_start_existing[<?php echo h($code); ?>]" value="<?php echo h(timeHm($cfg['start_time'])); ?>" <?php echo in_array($code, ['OFF','CUTI'], true) ? 'disabled' : ''; ?>>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>Selesai</label>
                                                            <input type="time" name="shift_end_existing[<?php echo h($code); ?>]" value="<?php echo h(timeHm($cfg['end_time'])); ?>" <?php echo in_array($code, ['OFF','CUTI'], true) ? 'disabled' : ''; ?>>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>Aktif</label>
                                                            <div style="padding-top:10px;">
                                                                <input type="checkbox" name="shift_active_existing[<?php echo h($code); ?>]" value="1" <?php echo ((int)$cfg['is_active'] === 1 || in_array($code, ['OFF','CUTI'], true)) ? 'checked' : ''; ?> <?php echo in_array($code, ['OFF','CUTI'], true) ? 'disabled' : ''; ?>>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <div class="btn-row">
                                <button type="submit" name="action_type" value="save_shift_list" class="btn-secondary">
                                    <i class="fa-solid fa-gear"></i> Simpan Pengaturan Shift
                                </button>
                            </div>
                        </form>
                    </div>
                </details>

                <details class="setting-box">
                    <summary>Hapus 1 Periode Penuh</summary>
                    <div class="setting-content">
                        <form method="POST" onsubmit="return confirm('Yakin hapus seluruh jadwal 1 periode penuh?');">
                            <div style="color:#dbeafe; font-size:14px; line-height:1.5;">
                                Ini akan menghapus semua jadwal periode <strong><?php echo h($periodTitle); ?></strong>.
                            </div>

                            <div style="display:flex; gap:8px; align-items:center; margin:12px 0; color:#dbeafe;">
                                <input type="checkbox" name="confirm_delete_month" value="YES" id="confirm_delete_month" required>
                                <label for="confirm_delete_month">Saya yakin ingin hapus semua jadwal periode ini</label>
                            </div>

                            <button type="submit" name="action_type" value="delete_month" class="btn-danger">
                                <i class="fa-solid fa-trash-can"></i> Delete 1 Periode
                            </button>
                        </form>
                    </div>
                </details>

            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($visibleLeaders)): ?>
    <div class="table-section">
        <h3 class="table-title">
            <i class="fa-solid fa-table"></i>
            <?php echo $canEdit ? 'Tabel Jadwal Leader / SPV' : 'Jadwal Anda'; ?>
        </h3>

        <div class="note-period" style="margin-bottom:14px;">
            Periode : <strong><?php echo h($periodTitle); ?></strong>
        </div>

      
        <table>
            <thead>
                <tr>
                    <th class="name-col">NAMA LEADER</th>
                    <?php foreach ($periodDays as $item): ?>
                        <th>
                            <div class="day-head">
                                <div class="tgl"><?php echo h($item['day_num']); ?></div>
                                <div class="hari"><?php echo h(substr($item['day_name'], 0, 3)); ?></div>
                                <div class="bln"><?php echo h(($namaBulan[$item['month_num']] ?? $item['month_num']) . ' ' . $item['year_num']); ?></div>
                            </div>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($visibleLeaders as $leader): ?>
                    <tr>
                        <td class="name-col <?php echo $canEdit ? 'schedule-name-action' : ''; ?>"
                            <?php if ($canEdit): ?>
                                data-leader="<?php echo h($leader); ?>"
                                data-from="<?php echo h($startDate); ?>"
                                data-to="<?php echo h($endDate); ?>"
                            <?php endif; ?>
                        >
                            <div><strong><?php echo h($leader); ?></strong></div>
                            <div style="font-size:12px; color:#475569;"><?php echo h($leaderMeta[$leader]['position_name'] ?? 'Leader / SPV'); ?></div>
                        </td>

                        <?php foreach ($periodDays as $item): ?>
                            <?php
                                $fullDate = $item['full_date'];
                                $val = $jadwalData[$leader][$fullDate] ?? '-';
                                $cellClass = getCellClass($val);
                                $displayText = getShiftDisplay($val, $shiftSettings);
                            ?>
                            <td
                                class="<?php echo h($cellClass); ?> <?php echo $canEdit ? 'editable-cell' : ''; ?>"
                                <?php if ($canEdit): ?>
                                    data-leader="<?php echo h($leader); ?>"
                                    data-date="<?php echo h($fullDate); ?>"
                                    data-shift="<?php echo h($val === '-' ? '' : $val); ?>"
                                    data-date-label="<?php echo h($item['day_name'] . ', ' . date('d-m-Y', strtotime($fullDate))); ?>"
                                <?php endif; ?>
                            >
                                <span class="shift-box-text"><?php echo h($displayText); ?></span>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>


<?php if ($canEdit): ?>
<div class="delete-range-modal" id="deleteRangeModal">
    <div class="delete-range-card">
        <div class="delete-range-head">
            <h4><i class="fa-solid fa-trash-can"></i> Hapus Baris Jadwal</h4>
            <button type="button" class="modal-close-btn" id="closeDeleteRangeModal">&times;</button>
        </div>
        <form method="POST" id="deleteRangeForm" onsubmit="return confirm('Yakin hapus jadwal nama ini sesuai rentang tanggal yang dipilih?');">
            <input type="hidden" name="action_type" value="delete_range">
            <input type="hidden" name="delete_range_leader" id="delete_range_leader" value="">
            <div class="delete-range-body">
                <div class="delete-range-leader-box">
                    Nama: <span id="delete_range_leader_text">-</span>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>Dari Tanggal</label>
                        <input type="date" name="delete_from_date" id="delete_from_date" min="<?php echo h($startDate); ?>" max="<?php echo h($endDate); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Sampai Tanggal</label>
                        <input type="date" name="delete_to_date" id="delete_to_date" min="<?php echo h($startDate); ?>" max="<?php echo h($endDate); ?>" required>
                    </div>
                </div>
                <div style="margin-top:10px;color:#64748b;font-size:13px;line-height:1.5;">
                    Data yang dihapus hanya jadwal milik nama ini pada rentang tanggal yang dipilih.
                </div>
            </div>
            <div class="delete-range-foot">
                <button type="button" class="btn-secondary" id="cancelDeleteRangeModal">Batal</button>
                <button type="submit" class="btn-danger"><i class="fa-solid fa-trash"></i> Delete Data</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canEdit): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const cells = document.querySelectorAll('.editable-cell');
    const leaderSelect = document.getElementById('leader');
    const tanggalView = document.getElementById('tanggal_view');
    const selectedDate = document.getElementById('selected_date');
    const shiftSelect = document.getElementById('shift');
    const selectedInfo = document.getElementById('selected_info');
    const leaderPickerModal = document.getElementById('leaderPickerModal');
    const openLeaderPicker = document.getElementById('openLeaderPicker');
    const closeLeaderPicker = document.getElementById('closeLeaderPicker');
    const applyLeaderPicker = document.getElementById('applyLeaderPicker');
    const checkAllLeaders = document.getElementById('checkAllLeaders');
    const uncheckAllLeaders = document.getElementById('uncheckAllLeaders');
    const selectedLeaderInfo = document.getElementById('selectedLeaderInfo');
    const templateLeaderCheckboxes = document.querySelectorAll('.template-leader-checkbox');

    let activeCell = null;

    function updateTemplateLeaderInfo() {
        if (!selectedLeaderInfo || !templateLeaderCheckboxes.length) return;
        let checked = 0;
        templateLeaderCheckboxes.forEach(cb => {
            if (cb.checked) checked++;
        });
        selectedLeaderInfo.textContent = checked + ' nama dipilih untuk template Excel.';
    }

    function openModal() {
        if (leaderPickerModal) leaderPickerModal.classList.add('show');
        updateTemplateLeaderInfo();
    }

    function closeModal() {
        if (leaderPickerModal) leaderPickerModal.classList.remove('show');
        updateTemplateLeaderInfo();
    }

    if (openLeaderPicker) openLeaderPicker.addEventListener('click', openModal);
    if (closeLeaderPicker) closeLeaderPicker.addEventListener('click', closeModal);
    if (applyLeaderPicker) applyLeaderPicker.addEventListener('click', closeModal);
    if (leaderPickerModal) {
        leaderPickerModal.addEventListener('click', function (e) {
            if (e.target === leaderPickerModal) closeModal();
        });
    }
    if (checkAllLeaders) {
        checkAllLeaders.addEventListener('click', function () {
            templateLeaderCheckboxes.forEach(cb => cb.checked = true);
            updateTemplateLeaderInfo();
        });
    }
    if (uncheckAllLeaders) {
        uncheckAllLeaders.addEventListener('click', function () {
            templateLeaderCheckboxes.forEach(cb => cb.checked = false);
            updateTemplateLeaderInfo();
        });
    }
    templateLeaderCheckboxes.forEach(cb => cb.addEventListener('change', updateTemplateLeaderInfo));
    updateTemplateLeaderInfo();

    function updateSelectedInfo(leader, dateLabel, shift) {
        selectedInfo.value = leader + ' | ' + dateLabel + (shift ? ' | ' + shift : ' | KOSONG');
    }

    if (tanggalView) {
        tanggalView.addEventListener('change', function () {
            selectedDate.value = this.value;
        });
    }


    const deleteRangeModal = document.getElementById('deleteRangeModal');
    const closeDeleteRangeModal = document.getElementById('closeDeleteRangeModal');
    const cancelDeleteRangeModal = document.getElementById('cancelDeleteRangeModal');
    const deleteRangeLeader = document.getElementById('delete_range_leader');
    const deleteRangeLeaderText = document.getElementById('delete_range_leader_text');
    const deleteFromDate = document.getElementById('delete_from_date');
    const deleteToDate = document.getElementById('delete_to_date');

    function openDeleteRangeModal(name, fromDate, toDate) {
        if (!deleteRangeModal) return;
        if (deleteRangeLeader) deleteRangeLeader.value = name;
        if (deleteRangeLeaderText) deleteRangeLeaderText.textContent = name;
        if (deleteFromDate) deleteFromDate.value = fromDate;
        if (deleteToDate) deleteToDate.value = toDate;
        deleteRangeModal.classList.add('show');
    }

    function closeDeleteRange() {
        if (deleteRangeModal) deleteRangeModal.classList.remove('show');
    }

    document.querySelectorAll('.schedule-name-action').forEach(nameCell => {
        nameCell.addEventListener('contextmenu', function (e) {
            e.preventDefault();
            openDeleteRangeModal(this.dataset.leader || '', this.dataset.from || '', this.dataset.to || '');
        });
        nameCell.addEventListener('dblclick', function () {
            openDeleteRangeModal(this.dataset.leader || '', this.dataset.from || '', this.dataset.to || '');
        });

        let longPressTimer = null;
        nameCell.addEventListener('touchstart', function () {
            const el = this;
            longPressTimer = setTimeout(function () {
                openDeleteRangeModal(el.dataset.leader || '', el.dataset.from || '', el.dataset.to || '');
            }, 650);
        }, {passive: true});
        ['touchend', 'touchmove', 'touchcancel'].forEach(evt => {
            nameCell.addEventListener(evt, function () {
                if (longPressTimer) clearTimeout(longPressTimer);
            }, {passive: true});
        });
    });
    if (closeDeleteRangeModal) closeDeleteRangeModal.addEventListener('click', closeDeleteRange);
    if (cancelDeleteRangeModal) cancelDeleteRangeModal.addEventListener('click', closeDeleteRange);
    if (deleteRangeModal) {
        deleteRangeModal.addEventListener('click', function (e) {
            if (e.target === deleteRangeModal) closeDeleteRange();
        });
    }

    cells.forEach(cell => {
        cell.addEventListener('click', function () {
            if (activeCell) {
                activeCell.classList.remove('selected-cell');
            }

            activeCell = this;
            activeCell.classList.add('selected-cell');

            const leader = this.dataset.leader || '';
            const fullDate = this.dataset.date || '';
            const shift = this.dataset.shift || '';
            const dateLabel = this.dataset.dateLabel || '';

            leaderSelect.value = leader;
            tanggalView.value = fullDate;
            selectedDate.value = fullDate;
            shiftSelect.value = shift;

            updateSelectedInfo(leader, dateLabel, shift);
        });
    });
});
</script>
<?php endif; ?>
</body>
</html>
