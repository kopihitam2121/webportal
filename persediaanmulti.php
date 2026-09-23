<?php
session_start();
if(!isset($_SESSION['username'])){
    header("Location: login.php");
    exit;
}

// ============================
// MAP KONEKSI TOKO
// ============================
if (!isset($map)) {
	require_once __DIR__ . '/map.php';
}

date_default_timezone_set('Asia/Jakarta');

/* ====== INPUT ====== */
$store    = (isset($_GET['store']) && $_GET['store']!=='') ? $_GET['store'] : null;
$tgl1     = isset($_GET['tgl1']) ? $_GET['tgl1'] : date('Y-01-01');
$tgl2     = isset($_GET['tgl2']) ? $_GET['tgl2'] : date('Y-m-d');
$export   = isset($_GET['export']) ? $_GET['export'] : '';
$doXls    = ($export === 'xls');
$doPdf    = ($export === 'pdf');


/* ====== VALIDASI TANGGAL ====== */
$dt1 = DateTime::createFromFormat('Y-m-d', $tgl1);
$dt2 = DateTime::createFromFormat('Y-m-d', $tgl2);
if (!$dt1 || !$dt2) {
  if ($store !== null) { http_response_code(400); die("Format tanggal tidak valid (yyyy-mm-dd)."); }
  $tgl1 = date('Y-01-01'); $tgl2 = date('Y-m-d');
  $dt1 = new DateTime($tgl1); $dt2 = new DateTime($tgl2);
}
if ($dt1 > $dt2) { $tmp=$tgl1; $tgl1=$tgl2; $tgl2=$tmp; $tmp=$dt1; $dt1=$dt2; $dt2=$tmp; }

$fromDT      = $tgl1 . ' 00:00:00';
$toExclusive = date('Y-m-d', strtotime($tgl2 . ' +1 day')) . ' 00:00:00';

/* ====== FORM JIKA BELUM PILIH TOKO ====== */
if ($store === null) {
  ?>
  <!DOCTYPE html>
  <html lang="id">
  <head>
    <meta charset="UTF-8">
    <title>Persediaan Barang</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{background:#f7f8fb}.card{border:0;box-shadow:0 8px 24px rgba(0,0,0,.06);border-radius:18px}</style>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  </head>
  <body>
    <div class="container py-5">
      <h3 class="mb-4">Persediaan Barang</h3>
      <a href="dashboard.php" class="btn btn-outline-primary"><i class="fa-solid fa-arrow-left"></i> Kembali</a>
      <div class="card"><div class="card-body">
        <form class="row g-3" method="get">
          <div class="col-sm-3">
            <label class="form-label">Dari Tanggal</label>
            <input type="date" class="form-control" name="tgl1" value="<?=htmlspecialchars($tgl1)?>" required>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Sampai Tanggal</label>
            <input type="date" class="form-control" name="tgl2" value="<?=htmlspecialchars($tgl2)?>" required>
          </div>
          <div class="col-sm-4">
            <label class="form-label">Pilih Toko</label>
            <select class="form-select" name="store" required>
              <option value="">--Pilih Toko--</option>
              <?php foreach($map as $k=>$cfg): ?>
                <option value="<?=htmlspecialchars($k)?>"><?=htmlspecialchars($k)?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-2 d-grid">
            <label class="form-label">&nbsp;</label>
            <button class="btn btn-primary">Lihat Data</button>
                   </div>
        </form>
      </div></div>
    </div>
  </body></html>
  <?php
  exit;
}

/* ====== VALIDASI TOKO ====== */
if (!isset($map[$store])) { http_response_code(400); die("Toko tidak valid."); }

/* ====== KONEKSI DB ====== */
$cfg = $map[$store];
$mysqli = @mysqli_connect($cfg['ip'], $cfg['user'], $cfg['pass'], $cfg['db']);
if (!$mysqli) { die("Koneksi gagal ke database toko."); }
$mysqli->set_charset("utf8");

/* ====== QUERY ====== */
$sql = "
SELECT 
  t.ProductID,
  t.NamaProduk,
  t.Harga,
  t.SaldoTerakhir,
  t.Penjualan,
  t.RataRataPerHari,
  CASE
    WHEN t.RataRataPerHari > 10 THEN 'Very Fast Moving'
    WHEN t.RataRataPerHari >  5 THEN 'Fast Moving'
    WHEN t.RataRataPerHari >  2 THEN 'Medium Moving'
    WHEN t.RataRataPerHari >  0 THEN 'Slow Moving'
    ELSE 'Very Slow Moving'
  END AS Kategori
FROM (
  SELECT 
    invbase.productid AS ProductID,
    COALESCE(p.name, CONCAT('Product ID ', invbase.productid)) AS NamaProduk,
    COALESCE(p.salesprice1,0) AS Harga,
    COALESCE(saldo.SaldoTerakhir,0) AS SaldoTerakhir,
    COALESCE(jual.TotalJual,0) AS Penjualan,
    ROUND(COALESCE(jual.TotalJual,0) / GREATEST(DATEDIFF(?, ?)+1, 1), 2) AS RataRataPerHari
  FROM (
    SELECT DISTINCT productid
    FROM inventory
    WHERE productid IS NOT NULL
  ) invbase
  LEFT JOIN product p 
    ON p.id = invbase.productid
  LEFT JOIN (
    SELECT productid, SUM(COALESCE(salesqty,0)) AS TotalJual
    FROM salesdetail
    WHERE transdate >= ? AND transdate < ?
    GROUP BY productid
  ) jual 
    ON jual.productid = invbase.productid
  LEFT JOIN (
    SELECT productid, SUM(COALESCE(invin,0) - COALESCE(invout,0)) AS SaldoTerakhir
    FROM inventory
    WHERE transdate < ?
    GROUP BY productid
  ) saldo 
    ON saldo.productid = invbase.productid
) t
WHERE t.SaldoTerakhir >= 0
ORDER BY 
  t.Penjualan DESC,
  t.SaldoTerakhir DESC,
  t.NamaProduk ASC
";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('sssss', $tgl2, $tgl1, $fromDT, $toExclusive, $toExclusive);
$stmt->execute();
$res  = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ====== EXPORT EXCEL ====== */
if ($doXls) {
    $safeStore = preg_replace('/[^A-Za-z0-9_\-]+/','_', $store);
    $filename  = "Persediaan_{$safeStore}_{$tgl1}_sd_{$tgl2}_" . date('Ymd_His') . ".xls";

    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "<table border='1'>";
    echo "<thead style='background:#e8eef7;font-weight:bold;'>";
    echo "<tr>
            <th>No</th>
            <th>ProductID</th>
            <th>Nama Produk</th>
            <th>Saldo Terakhir</th>
            <th>Harga</th>
            <th>Penjualan</th>
            <th>Rata-rata/Hari</th>
            <th>Kategori</th>
          </tr>";
    echo "</thead><tbody>";

    $n = 1;
    foreach ($rows as $r) {
        echo "<tr>";
        echo "<td>{$n}</td>";
        echo "<td>".htmlspecialchars($r['ProductID'])."</td>";
        echo "<td>".htmlspecialchars($r['NamaProduk'])."</td>";
        echo "<td>".(int)$r['SaldoTerakhir']."</td>";
        echo "<td>".number_format((float)$r['Harga'], 0, ',', '.')."</td>";
        echo "<td>".(int)$r['Penjualan']."</td>";
        echo "<td>".number_format((float)$r['RataRataPerHari'], 2, ',', '.')."</td>";
        echo "<td>".htmlspecialchars($r['Kategori'])."</td>";
        echo "</tr>";
        $n++;
    }

    if ($n === 1) {
        echo "<tr><td colspan='8' style='text-align:center;'>Tidak ada data.</td></tr>";
    }

    echo "</tbody></table>";
    $mysqli->close();
    exit;
}

/* ====== EXPORT PDF ====== */
if ($doPdf) {
    $safeStore = htmlspecialchars($store, ENT_QUOTES, 'UTF-8');
    header("Content-Type: text/html; charset=utf-8");
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
      <meta charset="UTF-8">
      <title>Persediaan Barang - <?=$safeStore?></title>
      <link rel="icon" type="image/png" href="img/srt2.png" />
      <style>
        @page { size: A4 landscape; margin: 10mm; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color:#000; }
        h2 { margin: 0 0 6px 0; }
        .meta { margin-bottom: 10px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #444; padding: 4px 6px; }
        thead th { background: #efefef; }
        .text-end { text-align: right; }
        .muted { color:#666; }
        @media screen {
          body { background:#f5f5f5; padding:20px; }
          .paper { background:#fff; padding:15px; box-shadow:0 0 10px rgba(0,0,0,0.15); }
          .toolbar { display:block; margin-bottom:15px; }
        }
        @media print { .toolbar { display:none; } }
      </style>
    </head>
    <body onload="setTimeout(()=>window.print(),400)">
      <div class="toolbar">
        <button onclick="window.print()">Cetak / Simpan PDF</button>
        <button onclick="window.close()">Tutup</button>
      </div>
      <div class="paper">
        <h2>Persediaan Barang</h2>
        <div class="meta">
          Toko: <b><?=$safeStore?></b><br>
          Periode: <b><?=htmlspecialchars($tgl1)?> s/d <?=htmlspecialchars($tgl2)?></b><br>
          Dibuat: <span class="muted"><?=date('Y-m-d H:i:s')?></span>
        </div>
        <table>
          <thead>
            <tr>
              <th>No</th><th>ProductID</th><th>Nama Produk</th>
              <th class="text-end">Saldo Terakhir</th>
              <th class="text-end">Harga</th>
              <th class="text-end">Penjualan</th>
              <th class="text-end">Rata-rata/Hari</th>
              <th>Kategori</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!empty($rows)): $n=1; foreach($rows as $r): ?>
            <tr>
              <td><?=$n++?></td>
              <td><?=htmlspecialchars($r['ProductID'])?></td>
              <td><?=htmlspecialchars($r['NamaProduk'])?></td>
              <td class="text-end"><?=number_format((float)$r['SaldoTerakhir'],0,',','.')?></td>
              <td class="text-end"><?=number_format((float)$r['Harga'],0,',','.')?></td>
              <td class="text-end"><?=number_format((float)$r['Penjualan'],0,',','.')?></td>
              <td class="text-end"><?=number_format((float)$r['RataRataPerHari'],2,',','.')?></td>
              <td><?=htmlspecialchars($r['Kategori'])?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="8" class="text-center muted">Tidak ada data.</td></tr>
          <?php endif; ?>
          </tbody>
          <tfoot>
            <tr><td colspan="8" class="muted">Generated by System • <?=date('Y-m-d H:i')?></td></tr>
          </tfoot>
        </table>
      </div>
    </body></html>
    <?php
    $mysqli->close();
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Web Portal Minimarket</title>
<link rel="icon" type="image/png" href="img/srt2.png" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
<style>
body{background:#f7f8fb;}
.card{border:0;box-shadow:0 8px 24px rgba(0,0,0,.06);border-radius:18px;}
.table thead th{position:sticky;top:0;z-index:2;background:#0d6efd;color:#fff;}
#searchBox{position:relative;max-width:400px;}
.autocomplete-suggestions{
  position:absolute;background:#fff;border:1px solid #ddd;
  width:100%;max-height:200px;overflow-y:auto;border-radius:8px;z-index:1000;
}
.autocomplete-suggestions div{
  padding:6px 10px;cursor:pointer;
}
.autocomplete-suggestions div:hover{
  background:#0d6efd;color:#fff;
}
.search-tags{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:5px;}
.search-tag{
  background:#198754;color:white;padding:4px 8px;border-radius:10px;
  display:flex;align-items:center;gap:4px;font-size:14px;
}
.search-tag i{cursor:pointer;}
</style>
</head>
<body>
<div class="container py-4">
  <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
    <h3 class="me-auto mb-0"><i class="fa-solid fa-boxes"></i> Persediaan Barang</h3>
    <span class="badge bg-secondary">Toko: <?=htmlspecialchars($store)?></span>
    <span class="badge bg-info">Periode: <?=htmlspecialchars($tgl1)?> s/d <?=htmlspecialchars($tgl2)?></span>
  </div>

  <div class="card mb-4"><div class="card-body">
    <form class="row g-3 align-items-end" method="get">
      <div class="col-sm-3">
        <label class="form-label">Dari Tanggal</label>
        <input type="date" class="form-control" name="tgl1" value="<?=htmlspecialchars($tgl1)?>">
      </div>
      <div class="col-sm-3">
        <label class="form-label">Sampai Tanggal</label>
        <input type="date" class="form-control" name="tgl2" value="<?=htmlspecialchars($tgl2)?>">
      </div>
      <div class="col-sm-4">
        <label class="form-label">Pilih Toko</label>
        <select class="form-select" name="store">
          <?php foreach($map as $k=>$cfg): ?>
            <option value="<?=htmlspecialchars($k)?>" <?=$k==$store?'selected':''?>><?=htmlspecialchars($k)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2 d-grid">
        <button class="btn btn-primary"><i class="fa-solid fa-filter"></i> Filter</button> 	
      </div>
    </form>
  </div></div>

  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex gap-2">
      <a href="?store=<?=urlencode($store)?>&tgl1=<?=$tgl1?>&tgl2=<?=$tgl2?>&export=xls" class="btn btn-success">
        <i class="fa-solid fa-file-excel"></i> Excel
      </a>
      <a href="?store=<?=urlencode($store)?>&tgl1=<?=$tgl1?>&tgl2=<?=$tgl2?>&export=pdf" target="_blank" class="btn btn-danger">
        <i class="fa-solid fa-file-pdf"></i> PDF
      </a>
       <a href="dashboard.php" class="btn btn-outline-primary">
                        <i class="fa-solid fa-arrow-left"></i> Kembali
 </a>
    </div>


    <!-- Input search dengan autocomplete -->
    <div id="searchBox">
      <div class="search-tags" id="searchTags"></div>
      <input type="text" id="searchInput" class="form-control" placeholder="Ketik nama produk...">
      <div class="autocomplete-suggestions" id="suggestions"></div>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table table-bordered table-hover" id="dataTable">
      <thead>
        <tr>
          <th>No</th><th>ProductID</th><th>Nama Produk</th>
          <th>Saldo Terakhir</th><th>Harga</th>
          <th>Penjualan</th><th>Rata-rata/Hari</th><th>Kategori</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!empty($rows)): $n=1; foreach($rows as $r): ?>
        <tr>
          <td><?=$n++?></td>
          <td><?=htmlspecialchars($r['ProductID'])?></td>
          <td><?=htmlspecialchars($r['NamaProduk'])?></td>
          <td class="text-end"><?=number_format((float)$r['SaldoTerakhir'],0,',','.')?></td>
          <td class="text-end"><?=number_format((float)$r['Harga'],0,',','.')?></td>
          <td class="text-end"><?=number_format((float)$r['Penjualan'],0,',','.')?></td>
          <td class="text-end"><?=number_format((float)$r['RataRataPerHari'],2,',','.')?></td>
          <td><?=htmlspecialchars($r['Kategori'])?></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="8" class="text-center text-muted">Tidak ada data.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
const dataProduk = Array.from(document.querySelectorAll('#dataTable tbody tr')).map(tr => tr.cells[2].innerText);
const input = document.getElementById('searchInput');
const suggestions = document.getElementById('suggestions');
const searchTags = document.getElementById('searchTags');
let selectedWords = [];

function showSuggestions(value){
  suggestions.innerHTML = '';
  if(!value) return;
  const filtered = dataProduk.filter(p => p.toLowerCase().includes(value.toLowerCase())).slice(0,10);
  filtered.forEach(item=>{
    const div = document.createElement('div');
    div.textContent = item;
    div.onclick = ()=>addTag(item);
    suggestions.appendChild(div);
  });
}

function addTag(word){
  if(selectedWords.includes(word)) return;
  selectedWords.push(word);
  renderTags();
  input.value = '';
  suggestions.innerHTML = '';
  filterTable();
}

function removeTag(word){
  selectedWords = selectedWords.filter(w=>w!==word);
  renderTags();
  filterTable();
}

function renderTags(){
  searchTags.innerHTML = '';
  selectedWords.forEach(w=>{
    const tag = document.createElement('div');
    tag.className = 'search-tag';
    tag.innerHTML = `${w} <i class="fa-solid fa-xmark" onclick="removeTag('${w}')"></i>`;
    searchTags.appendChild(tag);
  });
}

function filterTable(){
  const rows = document.querySelectorAll('#dataTable tbody tr');
  if(selectedWords.length === 0){
    rows.forEach(tr => tr.style.display = '');
    return;
  }
  rows.forEach(tr=>{
    const text = tr.innerText.toLowerCase();
    const match = selectedWords.some(word => text.includes(word.toLowerCase()));
    tr.style.display = match ? '' : 'none';
  });
}

input.addEventListener('input', e=>{
  showSuggestions(e.target.value);
});
document.addEventListener('click', e=>{
  if(!suggestions.contains(e.target) && e.target!==input){
    suggestions.innerHTML = '';
  }
});
</script>
</body>
</html>
