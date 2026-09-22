<?php
session_start();
require 'db.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

date_default_timezone_set('Asia/Jakarta');

function h($v){
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

function shiftClass($shift){
    switch(strtoupper(trim((string)$shift))){
        case 'OFF': return 'shift-off';
        case 'P': return 'shift-p';
        case 'S': return 'shift-s';
        case 'M': return 'shift-m';
        case 'MD1': return 'shift-md1';
        case 'MD2': return 'shift-md2';
        case 'C': return 'shift-cuti';
        default: return '';
    }
}

function hariSingkat($tgl){
    $hari = [
        'Sun'=>'Min','Mon'=>'Sen','Tue'=>'Sel','Wed'=>'Rab',
        'Thu'=>'Kam','Fri'=>'Jum','Sat'=>'Sab'
    ];
    return $hari[date('D', strtotime($tgl))] ?? '';
}

function buildPeriod(){

    $periodeList = [];
    $start = new DateTime('2026-08-26');

    for($i=0; $i<24; $i++){
        $s = clone $start;
        $e = clone $start;
        $e->modify('+1 month')->modify('-1 day');

        $periodeList[] = [
            'start'=>$s->format('Y-m-d'),
            'end'=>$e->format('Y-m-d'),
            'label'=>$s->format('d F Y').' s/d '.$e->format('d F Y')
        ];

        $start->modify('+1 month');
    }

    $selected = $_GET['periode'] ?? $periodeList[0]['start'];

    foreach($periodeList as $p){
        if($p['start']==$selected){

            $tanggal=[];
            $x=strtotime($p['start']);
            $end=strtotime($p['end']);

            while($x <= $end){
                $tanggal[]=date('Y-m-d',$x);
                $x=strtotime('+1 day',$x);
            }

            return [$p['start'],$p['end'],$tanggal,$periodeList];
        }
    }

    return [
        $periodeList[0]['start'],
        $periodeList[0]['end'],
        [],
        $periodeList
    ];
}

list($startDate, $endDate, $tanggal, $periodeList) = buildPeriod();
$periode = date('d F Y', strtotime($startDate)).' s/d '.date('d F Y', strtotime($endDate));
$allowedShifts = ['P','S','M','MD1','MD2','OFF','C'];

/* =====================================================
   DOWNLOAD TEMPLATE EXCEL BULK
   Format: ID | Nama | tanggal 26 ... 25
===================================================== */
if(isset($_GET['download'], $_GET['outlet_id'])){
    $outlet_id = (int)$_GET['outlet_id'];
    if($outlet_id <= 0){
        http_response_code(400);
        exit('Outlet tidak valid.');
    }

    $stmtOutlet = $conn->prepare('SELECT nama_outlet FROM outlet WHERE id=? LIMIT 1');
    $stmtOutlet->bind_param('i', $outlet_id);
    $stmtOutlet->execute();
    $out = $stmtOutlet->get_result()->fetch_assoc();
    if(!$out){
        http_response_code(404);
        exit('Outlet tidak ditemukan.');
    }

    $excel = new Spreadsheet();
    $sheet = $excel->getActiveSheet();
    $sheet->setTitle('Jadwal');

    $lastColIndex = 2 + count($tanggal);
    $lastCol = Coordinate::stringFromColumnIndex($lastColIndex);

    $sheet->mergeCells('A1:'.$lastCol.'1');
    $sheet->setCellValue('A1', 'TEMPLATE BULK JADWAL KARYAWAN');
    $sheet->mergeCells('A2:'.$lastCol.'2');
    $sheet->setCellValue('A2', 'OUTLET : '.$out['nama_outlet']);
    $sheet->setCellValue('A3', 'OUTLET ID');
    $sheet->setCellValue('B3', $outlet_id);
    $sheet->mergeCells('C3:'.$lastCol.'3');
    $sheet->setCellValue('C3', 'PERIODE : '.$periode);
    $sheet->mergeCells('A5:'.$lastCol.'5');
    $sheet->setCellValue('A5', 'Isi shift dengan: P, S, M, MD1, MD2, atau OFF. Kosongkan sel jika tidak ingin mengubah jadwal pada tanggal tersebut.');

    $sheet->setCellValue('A7', 'ID KARYAWAN');
    $sheet->setCellValue('B7', 'NAMA KARYAWAN');

    $col = 3;
    foreach($tanggal as $tgl){
        $sheet->setCellValueByColumnAndRow($col, 7, date('d', strtotime($tgl))."\n".hariSingkat($tgl));
        $col++;
    }

    // Ambil karyawan outlet
    $stmtK = $conn->prepare("SELECT id, nama_lengkap
        FROM karyawan
        WHERE outlet_id=?
          AND (is_resign=0 OR is_resign IS NULL)
          AND (is_mutasi_divisi=0 OR is_mutasi_divisi IS NULL)
          AND (status='AKTIF' OR status IS NULL)
          AND UPPER(COALESCE(jabatan,''))<>'CREW LEADER'
        ORDER BY nama_lengkap");
    $stmtK->bind_param('i', $outlet_id);
    $stmtK->execute();
    $rsK = $stmtK->get_result();

    // Ambil jadwal existing periode ini supaya template bisa sekaligus dipakai edit bulk
    $existing = [];
    $stmtJ = $conn->prepare("SELECT jk.karyawan_id, jk.tanggal, jk.shift
        FROM jadwal_karyawan jk
        INNER JOIN karyawan k ON k.id=jk.karyawan_id
        WHERE k.outlet_id=? AND jk.tanggal BETWEEN ? AND ?");
    $stmtJ->bind_param('iss', $outlet_id, $startDate, $endDate);
    $stmtJ->execute();
    $rsJ = $stmtJ->get_result();
    while($j = $rsJ->fetch_assoc()){
        $existing[(int)$j['karyawan_id']][$j['tanggal']] = strtoupper(trim($j['shift']));
    }

    $row = 8;
    while($k = $rsK->fetch_assoc()){
        $kid = (int)$k['id'];
        $sheet->setCellValue('A'.$row, $kid);
        $sheet->setCellValue('B'.$row, $k['nama_lengkap']);

        $col = 3;
        foreach($tanggal as $tgl){
            if(isset($existing[$kid][$tgl])){
                $sheet->setCellValueByColumnAndRow($col, $row, $existing[$kid][$tgl]);
            }
            $col++;
        }
        $row++;
    }

    $lastDataRow = max(8, $row - 1);

    // Style
    $sheet->getStyle('A1:'.$lastCol.'1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1:'.$lastCol.'5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A7:'.$lastCol.'7')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('1E40AF');
    $sheet->getStyle('A7:'.$lastCol.'7')->getFont()->setBold(true)->getColor()->setARGB('FFFFFF');
    $sheet->getStyle('A7:'.$lastCol.$lastDataRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle('C7:'.$lastCol.'7')->getAlignment()->setWrapText(true)->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getColumnDimension('A')->setWidth(14);
    $sheet->getColumnDimension('B')->setWidth(30);
    for($i=3; $i<=$lastColIndex; $i++){
        $sheet->getColumnDimensionByColumn($i)->setWidth(7);
    }
    $sheet->getRowDimension(7)->setRowHeight(32);

    // Dropdown shift
    $validation = new DataValidation();
    $validation->setType(DataValidation::TYPE_LIST);
    $validation->setErrorStyle(DataValidation::STYLE_STOP);
    $validation->setAllowBlank(true);
    $validation->setShowInputMessage(true);
    $validation->setShowErrorMessage(true);
    $validation->setShowDropDown(true);
    $validation->setErrorTitle('Shift tidak valid');
    $validation->setError('Gunakan P, S, M, MD1, MD2, OFF, atau C.');
    $validation->setPromptTitle('Pilih shift');
    $validation->setPrompt('P, S, M, MD1, MD2, OFF');
    $validation->setFormula1('"P,S,M,MD1,MD2,OFF,C"');

    for($r=8; $r<=$lastDataRow; $r++){
        for($c=3; $c<=$lastColIndex; $c++){
            $sheet->getCellByColumnAndRow($c, $r)->setDataValidation(clone $validation);
        }
    }

    $sheet->freezePane('C8');
    $sheet->setAutoFilter('A7:'.$lastCol.$lastDataRow);

    $safeOutlet = preg_replace('/[^A-Za-z0-9_-]+/', '_', $out['nama_outlet']);
    $filename = 'Template_Jadwal_'.$safeOutlet.'_'.date('Ymd').'.xlsx';

    while(ob_get_level() > 0){ ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: max-age=0');
    (new Xlsx($excel))->save('php://output');
    exit;
}

/* =====================================================
   IMPORT EXCEL BULK
===================================================== */
if(isset($_POST['import_excel'])){
    $errors = [];
    $success = 0;
    $skipped = 0;
    $processed = 0;

    try{
        if(!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK){
            throw new Exception('File Excel belum dipilih atau gagal di-upload.');
        }

        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if(!in_array($ext, ['xlsx','xls'])){
            throw new Exception('Format file harus .xlsx atau .xls.');
        }

        $excel = IOFactory::load($_FILES['file']['tmp_name']);
        $sheet = $excel->getActiveSheet();

        // Outlet ID ditanam pada template di B3
        $templateOutletId = (int)$sheet->getCell('B3')->getCalculatedValue();
        $formOutletId = (int)($_POST['outlet_id'] ?? 0);
        $outlet_id = $templateOutletId > 0 ? $templateOutletId : $formOutletId;
        if($outlet_id <= 0){
            throw new Exception('Outlet ID pada template tidak ditemukan. Download ulang template dari sistem.');
        }
        if($formOutletId > 0 && $templateOutletId > 0 && $formOutletId !== $templateOutletId){
            throw new Exception('Outlet pada file Excel tidak sama dengan outlet yang dipilih.');
        }

        $highestRow = $sheet->getHighestRow();
        $expectedDateCols = 2 + count($tanggal);
        $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        if($highestColIndex < $expectedDateCols){
            throw new Exception('Kolom tanggal pada template tidak lengkap. Download ulang template terbaru.');
        }

        // Prepared statements
        $stmtCheck = $conn->prepare("SELECT id, nama_lengkap FROM karyawan
            WHERE id=? AND outlet_id=?
              AND (is_resign=0 OR is_resign IS NULL)
              AND (is_mutasi_divisi=0 OR is_mutasi_divisi IS NULL)
            LIMIT 1");

        $stmtSave = $conn->prepare("INSERT INTO jadwal_karyawan (karyawan_id,tanggal,shift)
            VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE shift=VALUES(shift)");

        $conn->begin_transaction();

        for($r=8; $r<=$highestRow; $r++){
            $rawId = trim((string)$sheet->getCell('A'.$r)->getCalculatedValue());
            $namaExcel = trim((string)$sheet->getCell('B'.$r)->getCalculatedValue());

            if($rawId === '' && $namaExcel === ''){
                continue;
            }
            if(!ctype_digit((string)$rawId)){
                $errors[] = "Baris {$r}: ID karyawan tidak valid.";
                $skipped++;
                continue;
            }

            $karyawanId = (int)$rawId;
            $stmtCheck->bind_param('ii', $karyawanId, $outlet_id);
            $stmtCheck->execute();
            $karyawan = $stmtCheck->get_result()->fetch_assoc();
            if(!$karyawan){
                $errors[] = "Baris {$r}: ID {$karyawanId} tidak ditemukan pada outlet template.";
                $skipped++;
                continue;
            }

            $col = 3;
            foreach($tanggal as $tgl){
                $shift = strtoupper(trim((string)$sheet->getCellByColumnAndRow($col, $r)->getCalculatedValue()));
                $col++;

                // Sel kosong = tidak mengubah data tanggal tersebut
                if($shift === ''){
                    continue;
                }

                $processed++;
                if(!in_array($shift, $allowedShifts, true)){
                    $errors[] = "Baris {$r} / {$tgl}: shift '{$shift}' tidak valid.";
                    $skipped++;
                    continue;
                }

                $stmtSave->bind_param('iss', $karyawanId, $tgl, $shift);
                if(!$stmtSave->execute()){
                    throw new Exception("Gagal menyimpan ID {$karyawanId} tanggal {$tgl}: ".$stmtSave->error);
                }
                $success++;
            }
        }

        $conn->commit();
        $_SESSION['bulk_result'] = [
            'type' => 'success',
            'message' => 'Import Excel selesai.',
            'success' => $success,
            'skipped' => $skipped,
            'processed' => $processed,
            'errors' => array_slice($errors, 0, 25)
        ];
    }catch(Throwable $e){
        if(isset($conn) && method_exists($conn, 'rollback')){
            try{ $conn->rollback(); }catch(Throwable $ignore){}
        }
        $_SESSION['bulk_result'] = [
            'type' => 'error',
            'message' => $e->getMessage(),
            'success' => 0,
            'skipped' => 0,
            'processed' => 0,
            'errors' => []
        ];
    }

    header('Location: jadwal_karyawan.php');
    exit;
}

/* =====================================================
   DATA OUTLET + KARYAWAN + JADWAL
===================================================== */
$outlet = [];
$q = $conn->query('SELECT id,nama_outlet FROM outlet ORDER BY nama_outlet');
while($r = $q->fetch_assoc()){
    $outlet[] = $r;
}

$outletData = [];
$sql = "SELECT o.id outlet_id, o.nama_outlet, k.id karyawan_id, k.nama_lengkap
        FROM outlet o
        LEFT JOIN karyawan k ON k.outlet_id=o.id
          AND (k.is_resign=0 OR k.is_resign IS NULL)
          AND (k.is_mutasi_divisi=0 OR k.is_mutasi_divisi IS NULL)
          AND (k.status='AKTIF' OR k.status IS NULL)
          AND UPPER(COALESCE(k.jabatan,''))<>'CREW LEADER'
        ORDER BY o.nama_outlet,k.nama_lengkap";
$res = $conn->query($sql);
while($r = $res->fetch_assoc()){
    if($r['karyawan_id']){
        $outletData[$r['nama_outlet']][] = $r;
    }
}

// Load semua jadwal periode sekali saja (hindari query per-cell)
$jadwalMap = [];
$stmtAll = $conn->prepare('SELECT karyawan_id,tanggal,shift FROM jadwal_karyawan WHERE tanggal BETWEEN ? AND ?');
$stmtAll->bind_param('ss', $startDate, $endDate);
$stmtAll->execute();
$rsAll = $stmtAll->get_result();
while($j = $rsAll->fetch_assoc()){
    $jadwalMap[(int)$j['karyawan_id']][$j['tanggal']] = strtoupper(trim($j['shift']));
}

$bulkResult = $_SESSION['bulk_result'] ?? null;
unset($_SESSION['bulk_result']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Jadwal Karyawan</title>
<style>
*{box-sizing:border-box} body{margin:0;font-family:Inter,Arial,sans-serif;background:#f8fafc;color:#0f172a}
.header{padding:22px 24px 8px}.title{text-align:center;font-size:28px;font-weight:800}.period{text-align:center;color:#64748b;margin-top:6px}
.back-btn{position:absolute;right:22px;top:20px;background:#0f172a;color:#fff;padding:10px 16px;border-radius:10px;text-decoration:none;font-weight:700}
.toolbar-wrap{padding:14px 20px}.toolbar{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;box-shadow:0 4px 18px rgba(15,23,42,.05)}
.toolbar input[type=text],.toolbar select,.toolbar input[type=file]{min-height:40px;border:1px solid #cbd5e1;border-radius:9px;padding:8px 10px;background:#fff}
.btn{border:0;border-radius:9px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px}.btn-blue{background:#2563eb;color:#fff}.btn-green{background:#16a34a;color:#fff}.btn:hover{filter:brightness(.96)}
.import-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.hint{font-size:12px;color:#64748b;flex-basis:100%}
.alert{margin:0 20px 10px;padding:14px 16px;border-radius:12px;border:1px solid}.alert-success{background:#ecfdf5;border-color:#86efac;color:#166534}.alert-error{background:#fef2f2;border-color:#fecaca;color:#991b1b}.alert ul{margin:8px 0 0 20px;max-height:180px;overflow:auto}
.outlet-section{margin:22px 20px;overflow:hidden;border-radius:12px;border:1px solid #e2e8f0;background:#fff}.outlet-title{background:#0f172a;color:#fff;padding:11px 14px;font-weight:800}
.table-scroll{overflow-x:auto}table{border-collapse:collapse;width:max-content;min-width:100%;background:#fff}th,td{border:1px solid #cbd5e1;height:34px;text-align:center;white-space:nowrap}th{background:#1e40af;color:#fff;position:sticky;top:0;z-index:2}.nama{text-align:left;padding:0 10px;min-width:240px;position:sticky;left:0;background:#fff;z-index:1}.kotak{min-width:42px;font-weight:800;cursor:text;outline:none}.kotak:focus{box-shadow:inset 0 0 0 2px #3b82f6}.saving{opacity:.55}.save-error{box-shadow:inset 0 0 0 2px #ef4444!important}
.shift-off{background:#fee2e2;color:#dc2626}.shift-cuti{background:#dcfce7;color:#166534}.shift-cuti{background:#fee2e2;color:#dc2626}.shift-p{background:#dbeafe;color:#2563eb}.shift-s{background:#fef3c7;color:#d97706}.shift-m{background:#1f2937;color:#fff}.shift-md1{background:#dcfce7;color:#15803d}.shift-md2{background:#ede9fe;color:#7c3aed}
@media(max-width:700px){.back-btn{position:static;display:inline-flex;margin:12px 20px 0}.header{padding-top:8px}.title{font-size:22px}.toolbar{align-items:stretch}.toolbar>*{width:100%}.import-form{width:100%}.import-form>*{width:100%}.nama{min-width:180px}}
</style>
</head>
<body>
<a href="dashboard.php" class="back-btn">&larr; Kembali</a>
<div class="header">
    <div class="title">JADWAL KARYAWAN</div>
    <div class="period">Periode <?=h($periode)?></div>
</div>

<?php if($bulkResult): ?>
<div class="alert <?=$bulkResult['type']==='success'?'alert-success':'alert-error'?>">
    <strong><?=h($bulkResult['message'])?></strong>
    <?php if($bulkResult['type']==='success'): ?>
        <div>Berhasil disimpan: <b><?=h($bulkResult['success'])?></b> &nbsp;|&nbsp; Dilewati/gagal validasi: <b><?=h($bulkResult['skipped'])?></b> &nbsp;|&nbsp; Sel terisi diproses: <b><?=h($bulkResult['processed'])?></b></div>
    <?php endif; ?>
    <?php if(!empty($bulkResult['errors'])): ?>
        <ul><?php foreach($bulkResult['errors'] as $err): ?><li><?=h($err)?></li><?php endforeach; ?></ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="toolbar-wrap">
<div class="toolbar">
    <select onchange="ubahPeriode(this.value)">
        <?php foreach($periodeList as $p): ?>
        <option value="<?=h($p['start'])?>" <?=($p['start']==$startDate?'selected':'')?>>
            <?=h($p['label'])?>
        </option>
        <?php endforeach; ?>
    </select>
    <input id="searchOutlet" type="text" placeholder="Cari outlet..." oninput="cariOutlet(this.value)">
    <select id="outlet" onchange="syncOutlet()">
        <?php foreach($outlet as $o): ?>
            <option value="<?=h($o['id'])?>"><?=h($o['nama_outlet'])?></option>
        <?php endforeach; ?>
    </select>
    <button type="button" class="btn btn-blue" onclick="downloadExcel()"> Download Template Excel</button>

    <form method="post" enctype="multipart/form-data" class="import-form" onsubmit="return validateImport()">
        <input type="hidden" name="outlet_id" id="import_outlet_id" value="<?=isset($outlet[0])?h($outlet[0]['id']):''?>">
        <input type="file" name="file" id="excelFile" accept=".xlsx,.xls" required>
        <button class="btn btn-green" type="submit" name="import_excel" value="1"> Import Bulk Excel</button>
    </form>
    <div class="hint">Silahkan melakukan update jadwal</div>
</div>
</div>

<?php foreach($outletData as $namaOutlet => $rows): ?>
<div class="outlet-section" data-outlet-name="<?=h(strtolower($namaOutlet))?>">
    <div class="outlet-title">OUTLET : <?=h($namaOutlet)?></div>
    <div class="table-scroll">
    <table>
        <tr>
            <th class="nama">NAMA KARYAWAN</th>
            <?php foreach($tanggal as $d): ?>
                <th><?=date('d',strtotime($d))?><br><small><?=hariSingkat($d)?></small></th>
            <?php endforeach; ?>
        </tr>
        <?php foreach($rows as $k): ?>
        <tr>
            <td class="nama"><?=h($k['nama_lengkap'])?></td>
            <?php foreach($tanggal as $tgl):
                $kid = (int)$k['karyawan_id'];
                $shift = $jadwalMap[$kid][$tgl] ?? '';
            ?>
            <td class="kotak <?=shiftClass($shift)?>"
                contenteditable="true"
                spellcheck="false"
                data-old="<?=h($shift)?>"
                onfocus="this.dataset.old=this.innerText.trim().toUpperCase()"
                onblur="saveShift('<?=$kid?>','<?=h($tgl)?>',this)"><?=h($shift)?></td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
</div>
<?php endforeach; ?>

<script>
const allowedShifts = ['','P','S','M','MD1','MD2','OFF','C'];

function setShiftColor(obj, shift){
    obj.classList.remove('shift-off','shift-cuti','shift-p','shift-s','shift-m','shift-md1','shift-md2','save-error');
    switch(shift){
        case 'OFF': obj.classList.add('shift-off'); break;
        case 'C': obj.classList.add('shift-cuti'); break;
        case 'P': obj.classList.add('shift-p'); break;
        case 'S': obj.classList.add('shift-s'); break;
        case 'M': obj.classList.add('shift-m'); break;
        case 'MD1': obj.classList.add('shift-md1'); break;
        case 'MD2': obj.classList.add('shift-md2'); break;
    }
}

async function saveShift(id, tgl, obj){
    let shift = obj.innerText.trim().toUpperCase();
    obj.innerText = shift;

    if(!allowedShifts.includes(shift)){
        alert('Shift tidak valid. Gunakan P, S, M, MD1, MD2, OFF, C, atau kosong.');
        obj.innerText = obj.dataset.old || '';
        setShiftColor(obj, obj.innerText.trim().toUpperCase());
        return;
    }

    setShiftColor(obj, shift);
    obj.classList.add('saving');

    try{
        const body = new URLSearchParams({karyawan_id:id, tanggal:tgl, shift:shift});
        const response = await fetch('save_jadwal.php', {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
            body:body.toString()
        });
        const data = await response.json();
        if(!response.ok || !data.success){
            throw new Error(data.message || 'Gagal menyimpan jadwal.');
        }
        obj.dataset.old = shift;
    }catch(err){
        obj.classList.add('save-error');
        obj.innerText = obj.dataset.old || '';
        setShiftColor(obj, obj.innerText.trim().toUpperCase());
        alert(err.message);
    }finally{
        obj.classList.remove('saving');
    }
}

function ubahPeriode(v){
    let url = new URL(window.location.href);
    url.searchParams.set('periode',v);
    window.location.href=url.toString();
}

function syncOutlet(){
    const outlet = document.getElementById('outlet');
    document.getElementById('import_outlet_id').value = outlet.value;
}

function downloadExcel(){
    const id = document.getElementById('outlet').value;
    if(!id){ alert('Pilih outlet terlebih dahulu.'); return; }
    window.location.href = 'jadwal_karyawan.php?download=1&outlet_id=' + encodeURIComponent(id) + '&periode=' + encodeURIComponent('<?=h($startDate)?>');
}

function validateImport(){
    syncOutlet();
    const file = document.getElementById('excelFile').value;
    if(!file){ alert('Pilih file Excel terlebih dahulu.'); return false; }
    return true;
}

function cariOutlet(v){
    v = (v || '').toLowerCase();
    document.querySelectorAll('.outlet-section').forEach(el => {
        el.style.display = el.dataset.outletName.includes(v) ? '' : 'none';
    });

    const select = document.getElementById('outlet');
    for(const option of select.options){
        option.hidden = !option.text.toLowerCase().includes(v);
    }
}

document.addEventListener('keydown', function(e){
    if(e.target.classList.contains('kotak') && e.key === 'Enter'){
        e.preventDefault();
        e.target.blur();
    }
});

syncOutlet();
</script>
</body>
</html>
