<?php
session_start();
if(!isset($_SESSION['username'])){
    header("Location: login.php");
    exit;
}

date_default_timezone_set('Asia/Jakarta');

/* ====== MAP TOKO ====== */
$map = [
    "URBAN B4" => ["db"=>"urbant1b4","ip"=>"10.20.30.19","user"=>"root","pass"=>"kantuik","logo"=>"urban.png"],
    "Papi Coffee T1B" => ["db"=>"papimartt1b","ip"=>"10.20.30.61","user"=>"root","pass"=>"kantuik","logo"=>"papimart.png"],
    "URBAN B6" => ["db"=>"urbant1g6","ip"=>"10.20.30.26","user"=>"root","pass"=>"kantuik","logo"=>"urban.png"],
    "URBAN B7" => ["db"=>"urbanb7n","ip"=>"10.20.30.27","user"=>"root","pass"=>"kantuik","logo"=>"urban.png"],

    "Papi Mart T2 E3" => ["db"=>"papimart1n","ip"=>"10.20.30.20","user"=>"root","pass"=>"kantuik","logo"=>"papimart.png"],
    "Papi Mart T2 E4" => ["db"=>"papimart2n","ip"=>"10.20.30.15","user"=>"root","pass"=>"kantuik","logo"=>"papimart.png"],
    "Papi Mart T2 E5 NEW DB" => ["db"=>"papimart3new","ip"=>"10.20.30.8","user"=>"root","pass"=>"kantuik","logo"=>"papimart.png"],
    "Papi Mart T2 E5" => ["db"=>"papimart3n","ip"=>"10.20.30.8","user"=>"root","pass"=>"kantuik","logo"=>"papimart.png"],
    "Papi Mart Gate 18" => ["db"=>"pmg18","ip"=>"10.20.30.31","user"=>"root","pass"=>"kantuik","logo"=>"papimart.png"],

    "Latte Story T1C"=> ["db"=>"t1c","ip"=>"10.20.30.40","user"=>"root","pass"=>"kantuik","logo"=>"lattestory.png"],
    "Latte Story T2E" => ["db"=>"aedlst2e","ip"=>"10.20.30.14","user"=>"root","pass"=>"kantuik","logo"=>"lattestory.png"],
    "Latte Story T2F" => ["db"=>"lattestoryt2f","ip"=>"10.20.30.13","user"=>"root","pass"=>"kantuik","logo"=>"lattestory.png"],

    "Ambil Bekal Yuk D2" => ["db"=>"bekal2","ip"=>"10.20.30.23","user"=>"root","pass"=>"kantuik","logo"=>"aby.png"],
    "Ambil Bekal Yuk D6" => ["db"=>"bekal1","ip"=>"10.20.30.7","user"=>"root","pass"=>"kantuik","logo"=>"aby.png"],

    "Point One D1" => ["db"=>"pointd1","ip"=>"10.20.30.30","user"=>"root","pass"=>"kantuik","logo"=>"pointone.png"],
    "Point One D3" => ["db"=>"poind3","ip"=>"10.20.30.36","user"=>"root","pass"=>"kantuik","logo"=>"pointone.png"],
    "Point One D5" => ["db"=>"point1d5","ip"=>"10.20.30.24","user"=>"root","pass"=>"kantuik","logo"=>"pointone.png"],
    "Point One D7" => ["db"=>"point1d7","ip"=>"10.20.30.21","user"=>"root","pass"=>"kantuik","logo"=>"pointone.png"],

    "Papi Mart BIM" => ["db"=>"bim2026","ip"=>"10.20.30.51","user"=>"root","pass"=>"m1traminangmart","logo"=>"papimart.png"],
    "M Mart" => ["db"=>"miniinter","ip"=>"10.147.17.100","user"=>"root","pass"=>"kantuik","logo"=>"mmart.png"],
    "DC Minimarket" => ["db"=>"dcpapimart","ip"=>"10.147.17.138","user"=>"root","pass"=>"kantuik","logo"=>"papimart.png"]
];

/*
|--------------------------------------------------------------------------
| PEMETAAN KATEGORI TOKO
|--------------------------------------------------------------------------
| Nanti bro tinggal pindahkan nama toko ke array yang sesuai.
| Nama toko WAJIB sama persis dengan key yang ada di $map.
*/
$hybridStores = [
    "URBAN B4",
    "URBAN B6",
    "URBAN B7",
    "Latte Story T1C",
    "Latte Story T2E",
    "Latte Story T2F",
    "Papi Coffee T1B"
    
];

$convenienceStores = [
    "Papi Mart T2 E3",
    "Papi Mart T2 E4",
    "Papi Mart T2 E5",
    "Papi Mart T2 E5 NEW DB",
    "Papi Mart Gate 18",
    "Ambil Bekal Yuk D2",
    "Ambil Bekal Yuk D6",
    "Point One D1",
    "Point One D3",
    "Point One D5",
    "Point One D7",
    "M Mart",
    "Papi Mart BIM"

];

/* ====== PARAM & FILTER ====== */
$store = isset($_GET['store']) ? trim($_GET['store']) : '';
$storeType = isset($_GET['store_type']) ? trim($_GET['store_type']) : '';
$tgl1 = isset($_GET['tgl1']) ? trim($_GET['tgl1']) : date('Y-m-01');
$tgl2 = isset($_GET['tgl2']) ? trim($_GET['tgl2']) : date('Y-m-d');
$ids_raw = isset($_GET['ids']) ? trim($_GET['ids']) : "0710018;0710019;0710011;0710023;0710023;0710012;0710548;0710549;071009;SL2011;SL2012;S071532;0710017;0710015;0710016;0710010;PAO;10014SL;1001SL;1002SL;1003SL;1004SL;1005SL;
1006SL;1007SL;1008SL;1010SL;1011SL;1012SL;1013SL;1014SL;1015SL;SLM21;slm23;0710020;0710014;071006;S4992101036;S8991002101333;S8991002101630;S8991002101722;S8991002103238;S8991002103436;S8991002103764;S8991002103832;S8991002103931
;S8991002104914;S8991002105485;S8991002105584;S8991002105676;S8991002115101;S8991002115149;s8991002133624;s8991002133648;S8991002133822;s8991002135376;s8991002143036L;s8991002143050;s8991998111415;S8991998111514;s8991998111811;S8991998113419
;S8991998114218;S8991998114317;S8991998116816;S8991998118315;S8992696409057;S8992696521797;S8992753031894;S8992753102006;S8992775913000;S8992933453119;S8994171101289;S8996001414002;S8996001440049;S8996001440087;S8996001440124;S8996001440353
;S8996001440520;S8997018250218;S8997018250287;S8997033701030;S8997033701047;S8997033701054;S8998666001306;S8999999195649;S9311931024036;PKTSEMANGAT;PKTKENYANG1;PKTPAGI;PKTKENYANG2;PKTKENYANG3;PKTKENYANG4;PKTKENYANG5;1005pm;1006pm;1007pm;1008pm;1001pm;1002pm;1003pm;1004pm";


$format = isset($_GET['format']) ? $_GET['format'] : ''; // excel/pdf
$doXls = $format==='excel';
$doPdf = $format==='pdf';

$INV_COL = 'transtype';

/* Sanitasi daftar id */
$ids = array_filter(array_map(function($x){
    $x = trim($x);
    if ($x === '') return null;
    return preg_match('/^[A-Za-z0-9 _.\-\/]+$/u', $x) ? $x : null;
}, preg_split('/[;,]+/u', $ids_raw)));
if (!$ids) $ids = [''];
$in_clause = implode(',', array_map(fn($x) => "'".addslashes($x)."'", $ids));

$rows = [];
$days = (new DateTime($tgl1))->diff(new DateTime($tgl2))->days + 1;

/* ====== QUERY HANYA JALAN JIKA PILIH STORE ====== */
if($store && isset($map[$store])){
    $cfg = $map[$store];

    $mysqli = @mysqli_connect($cfg['ip'], $cfg['user'], $cfg['pass'], $cfg['db']);
    if (!$mysqli) {
        echo <<<'HTML'
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, maximum-scale=5.0, minimum-scale=0.5">
  <title>Koneksi Offline</title>
  <link rel="icon" type="image/png" href="img/srt2.png" />
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
</head>
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, maximum-scale=5.0, minimum-scale=0.5"><title>Koneksi Toko Offline</title>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<script>
Swal.fire({
  icon: 'error',
  title: 'Mohon Maaf',
  html: 'Toko yang anda pilih sedang <b>offline</b>.<br>Silahkan hubungi tim toko tersebut.<br><br><button id="btnBack" class="swal2-confirm swal2-styled">Kembali</button>',
  showConfirmButton: false,
  allowOutsideClick: false,
  didOpen: () => {
    const btn = Swal.getHtmlContainer().querySelector('#btnBack');
    if (btn) btn.addEventListener('click', () => { window.location.href = "menupaket.php"; });
  }
});
</script>
</body>
</html>
HTML;
        exit;
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $ok = @mysqli_set_charset($mysqli, 'utf8');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    if (!$ok) {
        $mysqli->query("SET NAMES utf8");
        $mysqli->query("SET CHARACTER SET utf8");
        $mysqli->query("SET collation_connection = 'utf8_general_ci'");
    }

    $sql = "
    SELECT * FROM (
      /* CHILD */
      SELECT p.id AS product_id, p.name AS NamaProduk, pb.id AS bahan_id, pb.name AS NamaBahanBaku,
             Round(COALESCE(sd.qty_produk,0)) AS PenjualanProduk, COALESCE(beli.in_in,0) AS PembelianBahan,
             Round(COALESCE(jual.out_out,0)) AS PenjualanBahan, COALESCE(pakai.out_out,0) AS PemakaianBahan,
             COALESCE(saldo_in.in_in,0)-COALESCE(saldo_out.out_out,0) AS SaldoBahan, 0 AS IsTanpaPaket
      FROM product p
      LEFT JOIN productpackage pp ON pp.packageid = p.id
      LEFT JOIN product pb ON pb.id = pp.productid
      LEFT JOIN (SELECT sd.productid,SUM(sd.salesqty) AS qty_produk FROM salesdetail sd WHERE DATE(sd.transdate) BETWEEN ? AND ? GROUP BY sd.productid) sd ON sd.productid=p.id
      LEFT JOIN (SELECT i.productid,SUM(i.invin) AS in_in FROM inventory i WHERE DATE(i.transdate) BETWEEN ? AND ? AND i.{$INV_COL}=1 GROUP BY i.productid) beli ON beli.productid=pb.id
      LEFT JOIN (SELECT i.productid,SUM(i.invout) AS out_out FROM inventory i WHERE DATE(i.transdate) BETWEEN ? AND ? AND i.{$INV_COL}=3 GROUP BY i.productid) jual ON jual.productid=pb.id
      LEFT JOIN (SELECT i.productid,SUM(i.invout) AS out_out FROM inventory i WHERE DATE(i.transdate) BETWEEN ? AND ? AND i.{$INV_COL}=21 GROUP BY i.productid) pakai ON pakai.productid=pb.id
      LEFT JOIN (SELECT i.productid,SUM(i.invin) AS in_in FROM inventory i WHERE DATE(i.transdate)<=? GROUP BY i.productid) saldo_in ON saldo_in.productid=pb.id
      LEFT JOIN (SELECT i.productid,SUM(i.invout) AS out_out FROM inventory i WHERE DATE(i.transdate)<=? GROUP BY i.productid) saldo_out ON saldo_out.productid=pb.id
      WHERE p.id IN ($in_clause) AND pb.id IS NOT NULL

      UNION ALL

      /* PARENT */
      SELECT p.id AS product_id, p.name AS NamaProduk, p.id AS bahan_id, CONCAT(p.name,'') AS NamaBahanBaku,
             Round(COALESCE(sd.qty_produk,0)) AS PenjualanProduk, COALESCE(beli.in_in,0) AS PembelianBahan,
             Round(COALESCE(jual.out_out,0)) AS PenjualanBahan, COALESCE(pakai.out_out,0) AS PemakaianBahan,
             COALESCE(saldo_in.in_in,0)-COALESCE(saldo_out.out_out,0) AS SaldoBahan, 1 AS IsTanpaPaket
      FROM product p
      LEFT JOIN (SELECT sd.productid,SUM(sd.salesqty) AS qty_produk FROM salesdetail sd WHERE DATE(sd.transdate) BETWEEN ? AND ? GROUP BY sd.productid) sd ON sd.productid=p.id
      LEFT JOIN (SELECT i.productid,SUM(i.invin) AS in_in FROM inventory i WHERE DATE(i.transdate) BETWEEN ? AND ? AND i.{$INV_COL}=1 GROUP BY i.productid) beli ON beli.productid=p.id
      LEFT JOIN (SELECT i.productid,SUM(i.invout) AS out_out FROM inventory i WHERE DATE(i.transdate) BETWEEN ? AND ? AND i.{$INV_COL}=3 GROUP BY i.productid) jual ON jual.productid=p.id
      LEFT JOIN (SELECT i.productid,SUM(i.invout) AS out_out FROM inventory i WHERE DATE(i.transdate) BETWEEN ? AND ? AND i.{$INV_COL}=21 GROUP BY i.productid) pakai ON pakai.productid=p.id
      LEFT JOIN (SELECT i.productid,SUM(i.invin) AS in_in FROM inventory i WHERE DATE(i.transdate)<=? GROUP BY i.productid) saldo_in ON saldo_in.productid=p.id
      LEFT JOIN (SELECT i.productid,SUM(i.invout) AS out_out FROM inventory i WHERE DATE(i.transdate)<=? GROUP BY i.productid) saldo_out ON saldo_out.productid=p.id
      WHERE p.id IN ($in_clause) AND (COALESCE(beli.in_in,0)+COALESCE(jual.out_out,0)+COALESCE(pakai.out_out,0)+COALESCE(saldo_in.in_in,0)+COALESCE(saldo_out.out_out,0))>0
    ) t
    ORDER BY t.NamaProduk,t.NamaBahanBaku
    ";

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param(
      'ssssssssssssssssssss',
      $tgl1,$tgl2,$tgl1,$tgl2,$tgl1,$tgl2,$tgl1,$tgl2,$tgl2,$tgl2,
      $tgl1,$tgl2,$tgl1,$tgl2,$tgl1,$tgl2,$tgl1,$tgl2,$tgl2,$tgl2
    );
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r=$res->fetch_assoc()) $rows[]=$r;
    $stmt->close();
    $mysqli->close();

    /*
    |--------------------------------------------------------------------------
    | FILTER STOK KOSONG
    |--------------------------------------------------------------------------
    | Jika saldo/stok bahan di toko kosong atau minus, data tidak ditampilkan.
    | Yang tampil hanya item dengan SaldoBahan > 0.
    */
    $rows = array_values(array_filter($rows, function($r){
        return isset($r['SaldoBahan']) && (float)$r['SaldoBahan'] > 0;
    }));
}

/* ====== EXPORT EXCEL ====== */
if($doXls){
    $filename = "MenuPaket_{$store}_{$tgl1}_sd_{$tgl2}_" . date('Ymd_His') . ".xls";
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");

    echo "<table border='1'><tr>
          <th>No</th><th>Nama Produk</th><th>Bahan Baku</th><th>Penjualan Produk</th>
          <th>Avg/Day</th><th>Pembelian</th><th>Penjualan Bhn</th><th>Pemakaian/Spoil</th><th>Saldo Bahan</th>
          </tr>";

    $no=0;$current=null;
    foreach($rows as $r){
        $isNewGroup = $current !== $r['product_id'];
        if($isNewGroup){
            $current=$r['product_id'];$no++;
            $avg=$days>0?round($r['PenjualanProduk']/$days,2):0;
            echo "<tr style='background:#eef4ff;font-weight:bold'>
                  <td>$no</td><td colspan='2'>{$r['NamaProduk']}</td>
                  <td>{$r['PenjualanProduk']}</td><td>$avg</td><td colspan='4'></td></tr>";
        }
        $saldo = (float)$r['SaldoBahan'];
        $namaBahan = $r['NamaBahanBaku'] ? htmlspecialchars($r['NamaBahanBaku']) : '';

        if ($r['IsTanpaPaket'] == 1 && $namaBahan === '') {
            continue;
        }

        if ($r['IsTanpaPaket'] == 1) {
            $namaBahan = "<em>$namaBahan</em>";
        }
        echo "<tr>
              <td></td><td></td><td>$namaBahan</td>
              <td></td><td></td>
              <td>{$r['PembelianBahan']}</td>
              <td>{$r['PenjualanBahan']}</td>
              <td>{$r['PemakaianBahan']}</td>
              <td>$saldo</td>
              </tr>";
    }
    echo "</table>";
    exit;
}

/* ====== EXPORT PDF ====== */
if($doPdf){
    ?>
    <!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=1024, initial-scale=0.5, minimum-scale=0.25, maximum-scale=5.0, user-scalable=yes">
<title>Daftar Food Service</title>
<link rel="icon" type="image/png" href="img/srt2.png" />
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
      <style>
        body{font-family:Arial;font-size:11px}
        table{border-collapse:collapse;width:100%}
        th,td{border:1px solid #555;padding:4px}
        th{background:#efefef}
      </style>
    </head>
    <body onload="setTimeout(()=>window.print(),300)">
    <h3>Menu Paket - <?=htmlspecialchars($store)?></h3>
    <div>Periode: <?=htmlspecialchars($tgl1)?> s/d <?=htmlspecialchars($tgl2)?></div>
    <table>
      <thead>
        <tr>
          <th>No</th><th>Nama Produk</th><th>Bahan Baku</th><th>Penjualan Produk</th>
          <th>Avg/Day</th><th>Pembelian</th><th>Penjualan Bhn</th><th>Pemakaian/Spoil</th><th>Saldo Bahan</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $no=0;$current=null;
      foreach($rows as $r){
          $isNewGroup = $current !== $r['product_id'];
          if($isNewGroup){
              $current=$r['product_id'];$no++;
              $avg=$days>0?round($r['PenjualanProduk']/$days,2):0;
              echo "<tr style='background:#eef4ff;font-weight:bold'>
                    <td>$no</td><td colspan='2'>{$r['NamaProduk']}</td>
                    <td>{$r['PenjualanProduk']}</td><td>$avg</td><td colspan='4'></td></tr>";
          }
          $saldo = (float)$r['SaldoBahan'];
          $namaBahan = $r['NamaBahanBaku'] ? htmlspecialchars($r['NamaBahanBaku']) : '';

          if ($r['IsTanpaPaket'] == 1 && $namaBahan === '') {
              continue;
          }

          if ($r['IsTanpaPaket'] == 1) {
              $namaBahan = "<em>$namaBahan</em>";
          }
          echo "<tr>
                <td></td><td></td><td>$namaBahan</td>
                <td></td><td></td>
                <td>{$r['PembelianBahan']}</td>
                <td>{$r['PenjualanBahan']}</td> 
                <td>{$r['PemakaianBahan']}</td>
                <td>$saldo</td>
                </tr>";
      }
      ?>
      </tbody>
    </table>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, maximum-scale=5.0, minimum-scale=0.5">
  <title>Menu Paket - Web Portal Minimarket</title>
  <link rel="icon" type="image/png" href="img/srt2.png" />
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{
      --brand:#0d6efd;
      --brand2:#6610f2;
      --dark:#1f2937;
    }
    body{
      background:#f4f7fb;
      min-height:100vh;
      font-family:Arial, Helvetica, sans-serif;
    }
    .hero-header{
      background:linear-gradient(135deg, #0d6efd 0%, #0b5ed7 48%, #6610f2 100%);
      color:#fff;
      border-radius:0 0 28px 28px;
      box-shadow:0 12px 34px rgba(13,110,253,.22);
    }
    .hero-header .badge{
      background:rgba(255,255,255,.18);
      border:1px solid rgba(255,255,255,.35);
      color:#fff;
      font-weight:500;
    }
    .card{
      border:0;
      box-shadow:0 10px 28px rgba(15,23,42,.08);
      border-radius:22px;
    }
    .store-card{
      min-height:245px;
      transition:.2s ease;
      overflow:hidden;
      position:relative;
    }
    .store-card:hover{
      transform:translateY(-4px);
      box-shadow:0 16px 40px rgba(15,23,42,.14);
    }
    .store-icon{
      width:70px;
      height:70px;
      border-radius:20px;
      display:flex;
      align-items:center;
      justify-content:center;
      margin:0 auto 18px;
      color:#fff;
      font-size:32px;
      box-shadow:0 10px 22px rgba(13,110,253,.25);
    }
    .store-icon.hybrid{
      background:linear-gradient(135deg,#0d6efd,#00b4d8);
    }
    .store-icon.convenience{
      background:linear-gradient(135deg,#6610f2,#d63384);
    }
    .selector-wrap{
      max-width:980px;
      margin:-36px auto 24px;
      position:relative;
      z-index:5;
    }
    .table thead th{
      position:sticky;
      top:0;
      z-index:2;
      background:#0d6efd;
      color:#fff;
      white-space:nowrap;
    }
    .table tbody tr.group-row td{
      background:#eef4ff;
      font-weight:700;
      color:#0d47a1;
      border-top:2px solid #cfe2ff;
    }
    .badge-soft{
      background:#eef4ff;
      color:#0d47a1;
      border:1px solid #cfe2ff;
    }
    .chip{
      display:inline-block;
      padding:.25rem .65rem;
      border-radius:999px;
      background:#eef4ff;
      color:#0d47a1;
      font-size:.85rem;
      font-weight:600;
    }
    .good{
      background:#e9f7ef!important;
    }
    .empty-state{
      padding:42px 20px;
      text-align:center;
      color:#6c757d;
    }
    @media print{
      .no-print{display:none!important}
      .hero-header{box-shadow:none;border-radius:0;color:#000;background:#fff}
      .card{box-shadow:none;border:1px solid #ddd}
    }
  </style>
</head>
<body>

<div class="hero-header py-4 py-md-5 mb-4">
  <div class="container text-center">
    <div class="mb-3">
      <span class="badge rounded-pill px-3 py-2">
        <i class="fa-solid fa-store me-1"></i> Web Portal Minimarket
      </span>
    </div>
    <h1 class="fw-bold mb-2">Laporan Produk & Bahan Baku</h1>
    <p class="mb-0 opacity-75">
      Pilih kategori toko, pilih nama toko, lalu sistem hanya menampilkan item yang stoknya masih tersedia.
    </p>
  </div>
</div>

<div class="container pb-5">

  <form method="get" id="storeChooser" class="selector-wrap no-print">
    <input type="hidden" name="store" id="selectedStore" value="<?=htmlspecialchars($store)?>">
    <input type="hidden" name="store_type" id="selectedStoreType" value="<?=htmlspecialchars($storeType)?>">
    <input type="hidden" name="ids" value="<?=htmlspecialchars($ids_raw)?>">

    <div class="card mb-4">
      <div class="card-body">
        <div class="row g-3 align-items-end">
          <div class="col-md-4">
            <label class="form-label fw-semibold">Dari Tanggal</label>
            <input type="date" class="form-control" name="tgl1" value="<?=htmlspecialchars($tgl1)?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Sampai Tanggal</label>
            <input type="date" class="form-control" name="tgl2" value="<?=htmlspecialchars($tgl2)?>" required>
          </div>
          <div class="col-md-4 d-grid">
            <a href="dashboard.php" class="btn btn-outline-primary">
              <i class="fa-solid fa-arrow-left"></i> Kembali Dashboard
            </a>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4 justify-content-center">
      <div class="col-md-6 col-lg-5">
        <div class="card store-card h-100">
          <div class="card-body text-center p-4">
            <div class="store-icon hybrid">
              <i class="fa-solid fa-shop"></i>
            </div>
            <h4 class="fw-bold mb-1">HYBRID STORE</h4>
            <p class="text-muted small mb-3">Pilih toko kategori hybrid.</p>

            <select class="form-select mb-3" id="hybridSelect">
              <option value="">- Pilih Hybrid Store -</option>
              <?php foreach($hybridStores as $storeName): ?>
                <?php if(isset($map[$storeName])): ?>
                  <option value="<?=htmlspecialchars($storeName)?>" <?= $storeName===$store?'selected':''?>>
                    <?=htmlspecialchars($storeName)?>
                  </option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-primary w-100 choose-store" data-type="HYBRID STORE" data-select="hybridSelect">
              <i class="fa-solid fa-filter me-1"></i> Tampilkan Laporan
            </button>
          </div>
        </div>
      </div>

      <div class="col-md-6 col-lg-5">
        <div class="card store-card h-100">
          <div class="card-body text-center p-4">
            <div class="store-icon convenience">
              <i class="fa-solid fa-store"></i>
            </div>
            <h4 class="fw-bold mb-1">CONVENIENCE STORE</h4>
            <p class="text-muted small mb-3">Pilih toko kategori convenience.</p>

            <select class="form-select mb-3" id="convenienceSelect">
              <option value="">- Pilih Convenience Store -</option>
              <?php foreach($convenienceStores as $storeName): ?>
                <?php if(isset($map[$storeName])): ?>
                  <option value="<?=htmlspecialchars($storeName)?>" <?= $storeName===$store?'selected':''?>>
                    <?=htmlspecialchars($storeName)?>
                  </option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-dark w-100 choose-store" data-type="CONVENIENCE STORE" data-select="convenienceSelect">
              <i class="fa-solid fa-filter me-1"></i> Tampilkan Laporan
            </button>
          </div>
        </div>
      </div>
    </div>
  </form>

  <?php if(!empty($store)): ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
      <h3 class="me-auto mb-0">Hasil Laporan</h3>
      <span class="chip"><i class="fa-solid fa-store me-1"></i> <?=htmlspecialchars($storeType ?: 'TOKO')?>: <?=htmlspecialchars($store)?></span>
      <span class="chip"><i class="fa-solid fa-calendar-days me-1"></i> <?=htmlspecialchars($tgl1)?> s/d <?=htmlspecialchars($tgl2)?></span>

      <a class="btn btn-success btn-sm no-print ms-md-2"
         href="?<?= http_build_query(array_merge($_GET,['format'=>'excel'])) ?>">
         <i class="fa-solid fa-file-excel me-1"></i> Download Excel
      </a>
      <a class="btn btn-danger btn-sm no-print"
         href="?<?= http_build_query(array_merge($_GET,['format'=>'pdf'])) ?>" target="_blank">
         <i class="fa-solid fa-file-pdf me-1"></i> Download PDF
      </a>
      <a class="btn btn-outline-secondary btn-sm no-print" href="menupaket.php">
         <i class="fa-solid fa-rotate-left me-1"></i> Reset
      </a>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th style="width:52px">No</th>
              <th>Nama Produk</th>
              <th>Bahan Baku</th>
              <th class="text-end">Penjualan Produk</th>
              <th class="text-end">Avg/Day</th>
              <th class="text-end">Pembelian</th>
              <th class="text-end">Penjualan Bhn</th>
              <th class="text-end">Pemakaian/Spoil</th>
              <th class="text-end">Saldo Bahan</th>
            </tr>
          </thead>
          <tbody>
          <?php
            $no=0;
            $current=null;

            if(empty($store)):
          ?>
              <tr>
                <td colspan="9">
                  <div class="empty-state">
                    <i class="fa-solid fa-hand-pointer fa-2x mb-3"></i>
                    <div class="fw-semibold">Silakan pilih kategori dan toko terlebih dahulu.</div>
                    <div class="small">Data laporan akan muncul setelah bro klik tombol Tampilkan Laporan.</div>
                  </div>
                </td>
              </tr>
          <?php
            elseif(empty($rows)):
          ?>
              <tr>
                <td colspan="9">
                  <div class="empty-state">
                    <i class="fa-solid fa-box-open fa-2x mb-3"></i>
                    <div class="fw-semibold">Tidak ada stok tersedia pada periode ini.</div>
                    <div class="small">Item dengan saldo stok kosong/minus otomatis tidak ditampilkan.</div>
                  </div>
                </td>
              </tr>
          <?php
            else:
              foreach($rows as $r):
                $isNewGroup = $current !== $r['product_id'];
                if($isNewGroup):
                  $current=$r['product_id'];
                  $no++;
                  $avg=$days>0?round($r['PenjualanProduk']/$days,2):0;
          ?>
                  <tr class="group-row">
                    <td><?=$no?></td>
                    <td colspan="2"><?=htmlspecialchars($r['NamaProduk'])?></td>
                    <td class="text-end"><span class="badge bg-primary"><?=number_format((float)$r['PenjualanProduk'])?></span></td>
                    <td class="text-end"><span class="badge badge-soft"><?=number_format((float)$avg,2)?></span></td>
                    <td class="text-end" colspan="4"></td>
                  </tr>
          <?php endif;
                  $saldo=(float)$r['SaldoBahan'];
                  $namaBahan = $r['NamaBahanBaku'] ? htmlspecialchars($r['NamaBahanBaku']) : '';
                  if(!empty($r['IsTanpaPaket']) && $r['IsTanpaPaket']==1) {
                    $namaBahan="<em>$namaBahan</em>";
                  }
          ?>
                  <tr class="good">
                    <td></td>
                    <td></td>
                    <td><?=$namaBahan?></td>
                    <td class="text-end"></td>
                    <td class="text-end"></td>
                    <td class="text-end"><?=number_format((float)$r['PembelianBahan'])?></td>
                    <td class="text-end"><?=number_format((float)$r['PenjualanBahan'])?></td>
                    <td class="text-end"><?=number_format((float)$r['PemakaianBahan'])?></td>
                    <td class="text-end fw-semibold"><?=number_format($saldo)?></td>
                  </tr>
          <?php
              endforeach;
            endif;
          ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <p class="text-muted small mt-3">
    Keterangan: item dengan <b>Saldo Bahan &le; 0</b> otomatis disembunyikan. Inventory: 1 = Pembelian, 3 = Penjualan, 21 = Pemakaian.
  </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.querySelectorAll('.choose-store').forEach(function(btn){
  btn.addEventListener('click', function(e){
    const selectId = this.getAttribute('data-select');
    const type = this.getAttribute('data-type');
    const select = document.getElementById(selectId);
    const value = select ? select.value : '';

    if(!value){
      e.preventDefault();
      Swal.fire({
        icon:'warning',
        title:'Pilih toko dulu bro',
        text:'Silakan pilih salah satu toko di kategori ' + type + '.'
      });
      return;
    }

    document.getElementById('selectedStore').value = value;
    document.getElementById('selectedStoreType').value = type;
  });
});
</script>
</body>
</html>
