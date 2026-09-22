<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Koneksi database tidak ditemukan. Pastikan db.php menghasilkan variabel $conn.');
}
$conn->set_charset('utf8mb4');

$title = 'LAPORAN RUTINAN TIM IT MINIMARKET';
$executor = 'Tim IT';
$outlets = [
    'PAPIMART 1','PAPIMART 2','PAPIMART 3','PAPIMART BIM','PAPIMART GATE 18',
    'AMBIL BEKAL YUK D2','AMBIL BEKAL YUK D6','POINT ONE D1','POINT ONE D3',
    'POINT ONE D5','POINT ONE D7','LATTE STORY T1C','LATTE STORY T2F',
    'LATTE STORY T2E','URBAN B4','URBAN B6','URBAN B7','PAPI COFFE B5','REFLEXOLOGY'
];

$create = "CREATE TABLE IF NOT EXISTS laporan_delete_non_ecsys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_date DATE NOT NULL,
    outlet VARCHAR(120) NOT NULL,
    is_done TINYINT(1) NOT NULL DEFAULT 0,
    note TEXT NULL,
    executor VARCHAR(120) NOT NULL DEFAULT 'Wira Darmawan',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_report_outlet (report_date, outlet),
    KEY idx_report_date (report_date),
    KEY idx_is_done (is_done)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$conn->query($create)) {
    http_response_code(500);
    exit('Gagal membuat tabel: ' . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8'));
}

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function validDate(string $d): bool {
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}
function dateId(string $d): string {
    $bulan = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $t = strtotime($d);
    return $t ? date('d', $t).' '.$bulan[(int)date('n',$t)].' '.date('Y',$t) : $d;
}
function getRows(mysqli $conn, string $date, array $outlets): array {
    $saved = [];
    $stmt = $conn->prepare('SELECT outlet,is_done,note FROM laporan_delete_non_ecsys WHERE report_date=?');
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $saved[$r['outlet']] = $r;
    $stmt->close();
    $rows = [];
    foreach ($outlets as $outlet) {
        $r = $saved[$outlet] ?? [];
        $rows[] = [
            'outlet'=>$outlet,
            'is_done'=>(int)($r['is_done'] ?? 0),
            'note'=>(string)($r['note'] ?? '')
        ];
    }
    return $rows;
}

$date = $_GET['date'] ?? $_POST['report_date'] ?? date('Y-m-d');
if (!validDate($date)) $date = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='save') {
    $date = (string)($_POST['report_date'] ?? '');
    if (!validDate($date)) exit('Tanggal tidak valid.');
    $done = $_POST['done'] ?? [];
    $notes = $_POST['note'] ?? [];

    $sql = "INSERT INTO laporan_delete_non_ecsys
        (report_date,outlet,is_done,note,executor)
        VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            is_done=VALUES(is_done),
            note=VALUES(note),
            executor=VALUES(executor)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) exit('Prepare gagal: '.$conn->error);

    $conn->begin_transaction();
    try {
        foreach ($outlets as $i=>$outlet) {
            $status = isset($done[$i]) ? 1 : 0;
            $note = trim((string)($notes[$i] ?? ''));
            $stmt->bind_param('ssiss', $date, $outlet, $status, $note, $executor);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        }
        $conn->commit();
        $stmt->close();
        header('Location: ?date='.urlencode($date).'&saved=1');
        exit;
    } catch (Throwable $ex) {
        $conn->rollback();
        $stmt->close();
        exit('Gagal menyimpan: '.e($ex->getMessage()));
    }
}

$rows = getRows($conn, $date, $outlets);
$total = count($rows);
$doneCount = count(array_filter($rows, fn($r)=>(int)$r['is_done']===1));
$pending = $total-$doneCount;
$progress = $total ? (int)round(($doneCount/$total)*100) : 0;
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= e($title) ?></title>
<link rel="icon" href="img/srt2.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>
<style>
:root{--blue:#3b82f6;--blue2:#1d4ed8;--blue3:#1e3a8a;--soft:#eff6ff;--bg:#eef5ff;--text:#0f172a;--muted:#64748b;--line:#dbeafe;--green:#15803d;--amber:#d97706;--shadow:0 12px 32px rgba(30,64,175,.10)}
*{box-sizing:border-box}html,body{width:100%;min-height:100%;margin:0}body{min-height:100vh;background:radial-gradient(circle at top left,rgba(59,130,246,.20),transparent 30%),linear-gradient(180deg,#f8fbff 0,var(--bg) 100%);font-family:Inter,system-ui,sans-serif;color:var(--text);overflow-x:hidden}button,input,textarea{font:inherit}.app{width:100%;min-height:100vh;margin:0;padding:0 0 108px}.hero{width:100%;padding:26px 30px;color:#fff;background:radial-gradient(circle at 86% 18%,rgba(255,255,255,.22),transparent 25%),linear-gradient(135deg,var(--blue3),var(--blue));box-shadow:0 16px 34px rgba(30,64,175,.24)}.hero small{display:inline-flex;align-items:center;gap:8px;padding:7px 10px;border-radius:999px;background:rgba(255,255,255,.17);font-weight:800;letter-spacing:.08em}.hero h1{margin:14px 0 7px;font-size:clamp(22px,4vw,38px);line-height:1.12;letter-spacing:-.04em}.hero p{margin:0;color:rgba(255,255,255,.90);font-weight:600}.top{display:grid;grid-template-columns:minmax(280px,1fr) repeat(3,minmax(130px,.5fr));gap:14px;margin:16px 18px 0}.card{background:rgba(255,255,255,.96);border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow)}.date-card,.stat{padding:16px}.label{display:block;color:var(--muted);font-size:10px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.date-row{display:flex;gap:9px;margin-top:8px}.date-row input{flex:1;min-width:0;border:1px solid var(--line);border-radius:13px;padding:11px 12px;font-weight:800;outline:none;background:#f8fbff}.date-row input:focus{border-color:var(--blue);box-shadow:0 0 0 4px rgba(59,130,246,.12)}.date-row button{border:0;border-radius:13px;background:linear-gradient(135deg,var(--blue2),var(--blue));color:#fff;padding:0 16px;font-weight:900}.stat .value{margin-top:7px;font-size:27px;line-height:1;font-weight:900}.done .value{color:var(--green)}.pending .value{color:var(--amber)}.prog .value{color:var(--blue2)}.report{margin:14px 18px 0;overflow:hidden}.toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:17px 18px;border-bottom:1px solid var(--line);background:linear-gradient(180deg,#ffffff,#f8fbff)}.toolbar h2{margin:0;font-size:18px}.toolbar p{margin:4px 0 0;color:var(--muted);font-size:12px;font-weight:600}.checkall{display:flex;align-items:center;gap:8px;color:var(--blue2);font-size:12px;font-weight:900;white-space:nowrap}.checkall input,.donebox{width:20px;height:20px;accent-color:var(--blue)}.tablewrap{overflow:auto;max-height:calc(100vh - 315px)}.table{width:100%;min-width:620px;border-collapse:separate;border-spacing:0}.table th{position:sticky;top:0;z-index:2;padding:13px;background:var(--blue3);color:#fff;font-size:10px;letter-spacing:.06em;text-transform:uppercase;text-align:left}.table td{padding:12px 13px;border-bottom:1px solid var(--line);vertical-align:middle;background:rgba(255,255,255,.96)}.table tr:nth-child(even) td{background:#f8fbff}.table tr.done-row td{background:#effcf4}.no{width:54px;text-align:center!important}.status{width:80px;text-align:center!important}.outlet{font-size:13px;font-weight:900}.note{width:100%;min-width:220px;min-height:42px;padding:10px;border:1px solid var(--line);border-radius:11px;resize:vertical;outline:none;background:#fff}.note:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(59,130,246,.12)}.bottom{position:fixed;z-index:20;left:50%;bottom:max(12px,env(safe-area-inset-bottom));transform:translateX(-50%);width:min(900px,calc(100% - 24px));display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:10px;border:1px solid rgba(219,234,254,.95);border-radius:20px;background:rgba(255,255,255,.94);box-shadow:0 18px 45px rgba(30,64,175,.22);backdrop-filter:blur(15px)}.btn{min-height:49px;border:0;border-radius:14px;display:flex;align-items:center;justify-content:center;gap:8px;font-size:13px;font-weight:900;cursor:pointer}.save{color:#fff;background:linear-gradient(135deg,var(--blue2),var(--blue))}.pdf{color:var(--blue2);background:var(--soft);border:1px solid #bfdbfe}
@media(max-width:850px){.top{grid-template-columns:repeat(3,1fr)}.date-card{grid-column:1/-1}.tablewrap{max-height:none}}@media(max-width:620px){.hero{padding:20px 16px}.top{margin:10px 10px 0;gap:8px}.report{margin:10px 10px 0}.stat{text-align:center;padding:12px 8px}.stat .value{font-size:22px}.toolbar{align-items:flex-start;padding:15px 14px}.table{min-width:580px}.bottom{grid-template-columns:1.15fr .85fr;width:calc(100% - 16px)}}
</style>
<style>
.hero-topline{display:flex;align-items:center;justify-content:space-between;gap:14px}.back-dashboard{flex:0 0 auto;min-height:42px;display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:9px 14px;color:var(--blue3);border:1px solid rgba(255,255,255,.82);border-radius:12px;background:#fff;box-shadow:0 8px 20px rgba(15,23,42,.18);font-size:12px;font-weight:900;text-decoration:none;transition:transform .18s ease,box-shadow .18s ease}.back-dashboard:hover{color:var(--blue3);transform:translateY(-2px);box-shadow:0 12px 26px rgba(15,23,42,.24)}
@media(max-width:620px){.hero-topline{align-items:stretch;flex-direction:column}.back-dashboard{width:100%}}
</style>
</head>
<body>
<main class="app">
<section class="hero">
<div class="hero-topline">
<small><i class="fa-solid fa-screwdriver-wrench"></i> Maintenance Report</small>
<a class="back-dashboard" href="../dashboard.php"><i class="fa-solid fa-arrow-left"></i> Kembali ke Dashboard</a>
</div>
<h1><?= e($title) ?></h1>
<p>Nama Eksekutor: <?= e($executor) ?></p>
</section>

<section class="top">
<form method="get" class="card date-card">
<span class="label">Tanggal Laporan</span>
<div class="date-row"><input type="date" name="date" value="<?= e($date) ?>" required><button type="submit"><i class="fa-solid fa-magnifying-glass"></i></button></div>
</form>
<div class="card stat done"><span class="label">Selesai</span><div class="value" id="doneCount"><?= $doneCount ?></div></div>
<div class="card stat pending"><span class="label">Belum</span><div class="value" id="pendingCount"><?= $pending ?></div></div>
<div class="card stat prog"><span class="label">Progress</span><div class="value" id="progressCount"><?= $progress ?>%</div></div>
</section>

<form method="post" id="reportForm">
<input type="hidden" name="action" value="save">
<input type="hidden" name="report_date" value="<?= e($date) ?>">
<section class="card report">
<div class="toolbar">
<div><h2>Checklist Outlet</h2><p><?= e(dateId($date)) ?></p></div>
<label class="checkall"><input type="checkbox" id="checkAll"> Centang Semua</label>
</div>
<div class="tablewrap">
<table class="table">
<thead><tr><th class="no">No</th><th>Nama Outlet</th><th class="status">Selesai</th><th>Keterangan</th></tr></thead>
<tbody>
<?php foreach($rows as $i=>$r): ?>
<tr class="<?= $r['is_done'] ? 'done-row':'' ?>" data-row="<?= $i ?>">
<td class="no"><?= $i+1 ?></td>
<td class="outlet"><?= e($r['outlet']) ?></td>
<td class="status"><input class="donebox" type="checkbox" name="done[<?= $i ?>]" value="1" <?= $r['is_done']?'checked':'' ?>></td>
<td><textarea class="note" name="note[<?= $i ?>]" placeholder="Keterangan"><?= e($r['note']) ?></textarea></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>
</form>
</main>

<div class="bottom">
<button class="btn save" type="submit" form="reportForm"><i class="fa-solid fa-floppy-disk"></i> Simpan Checklist</button>
<button class="btn pdf" type="button" id="exportPdf"><i class="fa-solid fa-file-pdf"></i> Export PDF</button>
</div>

<script>
const total=<?= $total ?>;
const boxes=[...document.querySelectorAll('.donebox')];
const checkAll=document.getElementById('checkAll');
const doneCount=document.getElementById('doneCount');
const pendingCount=document.getElementById('pendingCount');
const progressCount=document.getElementById('progressCount');
function refresh(){const done=boxes.filter(x=>x.checked).length;doneCount.textContent=done;pendingCount.textContent=total-done;progressCount.textContent=(total?Math.round(done/total*100):0)+'%';checkAll.checked=total>0&&done===total;checkAll.indeterminate=done>0&&done<total;boxes.forEach((x,i)=>document.querySelector('[data-row="'+i+'"]').classList.toggle('done-row',x.checked));}
boxes.forEach(x=>x.addEventListener('change',refresh));
checkAll.addEventListener('change',()=>{boxes.forEach(x=>x.checked=checkAll.checked);refresh()});
refresh();

<?php if(isset($_GET['saved'])): ?>
Swal.fire({icon:'success',title:'Tersimpan',text:'Checklist tanggal <?= e(dateId($date)) ?> berhasil disimpan',timer:1800,showConfirmButton:false});
<?php endif; ?>

document.getElementById('exportPdf').addEventListener('click',()=>{
    const {jsPDF}=window.jspdf;
    const doc=new jsPDF({orientation:'landscape',unit:'mm',format:'a4'});
    const rows=<?= json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
    const liveRows=rows.map((r,i)=>[
        i+1,
        r.outlet,
        boxes[i].checked?'SELESAI':'BELUM',
        document.querySelector('textarea[name="note['+i+']"]').value||'-'
    ]);
    const done=boxes.filter(x=>x.checked).length;
    doc.setTextColor(29,78,216);doc.setFont('helvetica','bold');doc.setFontSize(17);doc.text('<?= e($title) ?>',14,16);
    doc.setTextColor(30,41,59);doc.setFontSize(10);doc.text('Tanggal Laporan: <?= e(dateId($date)) ?>',14,24);doc.text('Nama Eksekutor: <?= e($executor) ?>',14,30);doc.text('Progress: '+done+' dari '+total+' outlet selesai',14,36);
    doc.autoTable({startY:42,head:[['No','Nama Outlet','Status','Keterangan']],body:liveRows,theme:'grid',styles:{fontSize:8,cellPadding:2.4,overflow:'linebreak'},headStyles:{fillColor:[30,58,138],textColor:255,fontStyle:'bold'},columnStyles:{0:{cellWidth:12,halign:'center'},1:{cellWidth:72},2:{cellWidth:30,halign:'center'},3:{cellWidth:'auto'}},alternateRowStyles:{fillColor:[249,250,251]},margin:{left:14,right:14}});
    doc.setFontSize(8);doc.setTextColor(100);doc.text('Dicetak: '+new Date().toLocaleString('id-ID')+' WIB',14,doc.internal.pageSize.getHeight()-8);
    doc.save('Laporan_Rutinan_Tim_IT_Minimarket_<?= e($date) ?>.pdf');
});
</script>
</body>
</html>
