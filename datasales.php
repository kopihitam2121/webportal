<?php
/* BUILD: DATASALES-4-TABLE-SOURCE-DEDUP-20260807 */
session_start();
date_default_timezone_set('Asia/Jakarta');

/* ==== BATAS AKSES USER ==== */
$allowed_users = ['wira','wahid','cindy','yan','alfia','zahra','aca','azik','prengkuh','admin1','whina','ratna'];
$username = $_SESSION['username'] ?? '';
$role = $_SESSION['role'] ?? ''; 

if(!isset($_SESSION['username'])){
    header("Location: login.php"); 
    exit;
}

if(!in_array(strtoupper($username), array_map('strtoupper', $allowed_users))){
    echo "<script>alert('Akses Ditolak');window.location='dashboard.php';</script>";
    exit;
}

/*
 * PENTING:
 * Lepaskan lock file session secepat mungkin.
 * Tanpa ini, datasales.php yang sedang menunggu koneksi toko dapat
 * membuat halaman PHP lain dengan session login yang sama ikut menunggu.
 */
session_write_close();

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('default_socket_timeout', '5');

/**
 * Tampilkan SweetAlert lalu kembali ke halaman utama.
 * Fungsi ini langsung menghentikan proses agar halaman tidak meneruskan query.
 */
function showSalesErrorAndRedirect($title, $message, $redirect = 'dashboard.php'){
    $titleJs    = json_encode($title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $messageJs  = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $redirectJs = json_encode($redirect, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    header('Content-Type: text/html; charset=UTF-8');

    echo '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Informasi Koneksi</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<script>
(function () {
    const title = ' . $titleJs . ';
    const message = ' . $messageJs . ';
    const redirect = ' . $redirectJs . ';

    function kembali() {
        window.location.replace(redirect);
    }

    function tampilkanPesan() {
        if (typeof Swal !== "undefined") {
            Swal.fire({
                icon: "error",
                title: title,
                text: message,
                confirmButtonText: "Kembali ke Halaman Utama",
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then(kembali);
        } else {
            alert(title + "\\n\\n" + message);
            kembali();
        }
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", tampilkanPesan);
    } else {
        tampilkanPesan();
    }

    /* Fallback apabila CDN SweetAlert gagal dimuat. */
    setTimeout(function () {
        if (typeof Swal === "undefined") {
            kembali();
        }
    }, 2500);
})();
</script>
</body>
</html>';
    exit;
}

/**
 * Cek keberadaan tabel pada database outlet.
 * Nama tabel dibatasi agar aman digunakan pada information_schema.
 */
function salesTableExists($mysqli, $table){
    if(!($mysqli instanceof mysqli) || !preg_match('/^[A-Za-z0-9_]+$/', (string)$table)){
        return false;
    }

    $tableSql = $mysqli->real_escape_string($table);
    $check = @$mysqli->query(
        "SELECT 1
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = '$tableSql'
         LIMIT 1"
    );

    if(!$check){
        return false;
    }

    $exists = $check->num_rows > 0;
    $check->free();
    return $exists;
}

require_once __DIR__ . '/map.php';

$tanggal_awal = $_POST['tanggal_awal'] ?? date('Y-m-d');
$tanggal_akhir = $_POST['tanggal_akhir'] ?? date('Y-m-d');
$jam_awal = $_POST['jam_awal'] ?? '00:00';
$jam_akhir = $_POST['jam_akhir'] ?? '23:59';
$namaToko = $_POST['db_index'] ?? ($_GET['store'] ?? '');

$dataRows = [];
$dataDetail = [];
$totalTransaksi = 0;
$totalNominal = 0;
$grandQty = 0;
$grandNominal = 0;
$userStats = [];
$errorMsg = '';

if(isset($_POST['submit']) && $namaToko){
    if(!isset($map[$namaToko])){
        showSalesErrorAndRedirect(
            'Toko Tidak Ditemukan',
            'Data toko tidak ditemukan pada konfigurasi map.php.'
        );
    }

    $config = $map[$namaToko];

    $host = isset($config['ip']) ? trim($config['ip']) : '';
    $db   = isset($config['db']) ? trim($config['db']) : '';
    $dbUser = isset($config['user']) ? $config['user'] : '';
    $dbPass = isset($config['pass']) ? $config['pass'] : '';
    $port = isset($config['port']) ? (int)$config['port'] : 3306;

    /*
     * Gunakan mysqli_init agar timeout dapat dipasang sebelum koneksi.
     * CONNECT_TIMEOUT disamakan dengan persen.php agar outlet dengan jaringan lebih lambat tetap dapat tersambung.
     */
    $mysqli = mysqli_init();

    if(!$mysqli){
        showSalesErrorAndRedirect(
            'Koneksi Gagal',
            'Sistem tidak dapat menyiapkan koneksi database toko.'
        );
    }

    mysqli_options($mysqli, MYSQLI_OPT_CONNECT_TIMEOUT, 15);

    if(defined('MYSQLI_OPT_READ_TIMEOUT')){
        mysqli_options($mysqli, MYSQLI_OPT_READ_TIMEOUT, 20);
    }

    $connected = @mysqli_real_connect(
        $mysqli,
        $host,
        $dbUser,
        $dbPass,
        $db,
        $port
    );

    if(!$connected){
        error_log('[datasales] Koneksi gagal untuk outlet ' . $namaToko . ': ' . mysqli_connect_error());
        @mysqli_close($mysqli);

        showSalesErrorAndRedirect(
            'Toko Offline',
            'Toko sedang offline atau jaringan tidak merespons dalam 15 detik. Silakan periksa internet toko.'
        );
    }

    mysqli_set_charset($mysqli, 'utf8mb4');

    /*
     * Batas waktu SELECT:
     * - MAX_EXECUTION_TIME untuk MySQL
     * - max_statement_time untuk MariaDB
     * Salah satu perintah dapat tidak didukung dan sengaja diabaikan.
     */
    @$mysqli->query("SET SESSION MAX_EXECUTION_TIME = 20000");
    @$mysqli->query("SET SESSION max_statement_time = 20");

    $tanggal_awal_db  = str_replace('-', '', $tanggal_awal);
    $tanggal_akhir_db = str_replace('-', '', $tanggal_akhir);

    /* Escape semua input yang dipakai pada query. */
    $tanggalAwalSql = $mysqli->real_escape_string($tanggal_awal);
    $tanggalAkhirSql = $mysqli->real_escape_string($tanggal_akhir);
    $tanggalAwalDbSql = $mysqli->real_escape_string($tanggal_awal_db);
    $tanggalAkhirDbSql = $mysqli->real_escape_string($tanggal_akhir_db);
    $jamAwalSql = $mysqli->real_escape_string($jam_awal);
    $jamAkhirSql = $mysqli->real_escape_string($jam_akhir);

    /*
     * Empat tabel menjadi sumber data:
     * - salespayments dan salesdetail sebagai sumber utama.
     * - salespaymentscopy dan salesdetailcopy sebagai sumber tambahan/cadangan.
     *
     * Pencegahan duplikasi dilakukan per salesid:
     * - Jika salesid sudah ada di tabel utama, versi dari tabel copy tidak dipakai.
     * - Header pembayaran dikelompokkan per salesid agar beberapa baris pembayaran
     *   tidak menggandakan baris detail produk.
     */
    $hasSalesPayments     = salesTableExists($mysqli, 'salespayments');
    $hasSalesPaymentsCopy = salesTableExists($mysqli, 'salespaymentscopy');
    $hasSalesDetail       = salesTableExists($mysqli, 'salesdetail');
    $hasSalesDetailCopy   = salesTableExists($mysqli, 'salesdetailcopy');

    if(!$hasSalesPayments && !$hasSalesPaymentsCopy){
        @mysqli_close($mysqli);
        showSalesErrorAndRedirect(
            'Sumber Pembayaran Tidak Ditemukan',
            'Tabel salespayments dan salespaymentscopy tidak tersedia pada database toko.'
        );
    }

    if(!$hasSalesDetail && !$hasSalesDetailCopy){
        @mysqli_close($mysqli);
        showSalesErrorAndRedirect(
            'Sumber Detail Tidak Ditemukan',
            'Tabel salesdetail dan salesdetailcopy tidak tersedia pada database toko.'
        );
    }

    $paymentSources = [];

    if($hasSalesPayments){
        $paymentSources[] = "
            SELECT
                sp.salesid,
                MIN(sp.transdate) AS transdate,
                COALESCE(MIN(sp.usercreate), '') AS usercreate
            FROM salespayments sp
            WHERE DATE_FORMAT(sp.transdate, '%Y%m%d')
                  BETWEEN '$tanggalAwalDbSql' AND '$tanggalAkhirDbSql'
              AND TIME(sp.transdate) BETWEEN '$jamAwalSql' AND '$jamAkhirSql'
              AND sp.salesid IS NOT NULL
              AND TRIM(CAST(sp.salesid AS CHAR)) <> ''
            GROUP BY sp.salesid
        ";
    }

    if($hasSalesPaymentsCopy){
        $copyPaymentDedup = $hasSalesPayments
            ? "AND NOT EXISTS (
                   SELECT 1
                   FROM salespayments sp_main
                   WHERE sp_main.salesid = spc.salesid
                     AND DATE_FORMAT(sp_main.transdate, '%Y%m%d')
                         BETWEEN '$tanggalAwalDbSql' AND '$tanggalAkhirDbSql'
                     AND TIME(sp_main.transdate) BETWEEN '$jamAwalSql' AND '$jamAkhirSql'
               )"
            : '';

        $paymentSources[] = "
            SELECT
                spc.salesid,
                MIN(spc.transdate) AS transdate,
                COALESCE(MIN(spc.usercreate), '') AS usercreate
            FROM salespaymentscopy spc
            WHERE DATE_FORMAT(spc.transdate, '%Y%m%d')
                  BETWEEN '$tanggalAwalDbSql' AND '$tanggalAkhirDbSql'
              AND TIME(spc.transdate) BETWEEN '$jamAwalSql' AND '$jamAkhirSql'
              AND spc.salesid IS NOT NULL
              AND TRIM(CAST(spc.salesid AS CHAR)) <> ''
              $copyPaymentDedup
            GROUP BY spc.salesid
        ";
    }

    $detailSources = [];

    if($hasSalesDetail){
        $detailSources[] = [
            'table' => 'salesdetail',
            'alias' => 'sd',
            'dedup' => ''
        ];
    }

    if($hasSalesDetailCopy){
        $copyDetailDedup = $hasSalesDetail
            ? "WHERE NOT EXISTS (
                   SELECT 1
                   FROM salesdetail sd_main
                   WHERE sd_main.salesid = sdc.salesid
               )"
            : '';

        $detailSources[] = [
            'table' => 'salesdetailcopy',
            'alias' => 'sdc',
            'dedup' => $copyDetailDedup
        ];
    }

    $salesLineSources = [];

    foreach($paymentSources as $paymentSourceSql){
        foreach($detailSources as $detailSource){
            $detailTable = $detailSource['table'];
            $detailAlias = $detailSource['alias'];
            $detailDedup = $detailSource['dedup'];

            $salesLineSources[] = "
                SELECT
                    pay.salesid,
                    pay.transdate,
                    pay.usercreate,
                    $detailAlias.productid,
                    COALESCE($detailAlias.salesqty, 0) AS salesqty,
                    COALESCE($detailAlias.price, 0) AS price,
                    COALESCE(
                        $detailAlias.netamount,
                        COALESCE($detailAlias.salesqty, 0) * COALESCE($detailAlias.price, 0),
                        0
                    ) AS netamount
                FROM (
                    $paymentSourceSql
                ) pay
                JOIN $detailTable $detailAlias
                  ON $detailAlias.salesid = pay.salesid
                $detailDedup
            ";
        }
    }

    $salesLinesSql = implode("\nUNION ALL\n", $salesLineSources);

    // Satu query detail dipakai sekaligus untuk membentuk ringkasan harian.
    $queryDetail = "SELECT sales_data.salesid,
                           sales_data.transdate,
                           sales_data.usercreate,
                           sales_data.productid,
                           p.name AS product_name,
                           sales_data.salesqty,
                           sales_data.price,
                           sales_data.netamount,
                           (sales_data.salesqty * sales_data.price) AS subtotal
                    FROM (
                        $salesLinesSql
                    ) sales_data
                    JOIN product p ON p.id = sales_data.productid
                    ORDER BY sales_data.usercreate, sales_data.transdate ASC";

    $resDetail = @$mysqli->query($queryDetail);

    if(!$resDetail){
        error_log('[datasales] Query gabungan 4 tabel gagal untuk outlet ' . $namaToko . ': ' . $mysqli->error);
        @mysqli_close($mysqli);

        showSalesErrorAndRedirect(
            'Data Tidak Dapat Dimuat',
            'Koneksi database berhasil, tetapi penggabungan salesdetail, salespayments, salesdetailcopy, dan salespaymentscopy tidak selesai. Silakan coba rentang tanggal yang lebih pendek.'
        );
    }

    $summaryByDate = [];

    while($row = $resDetail->fetch_assoc()){
        $tgl = substr((string)$row['transdate'], 0, 10);
        $salesIdKey = (string)$row['salesid'];

        if(!isset($summaryByDate[$tgl])){
            $summaryByDate[$tgl] = [
                'salesids' => [],
                'nominal' => 0
            ];
        }

        $summaryByDate[$tgl]['salesids'][$salesIdKey] = true;
        $summaryByDate[$tgl]['nominal'] += (float)$row['netamount'];

        $dataDetail[] = $row;
        $grandQty += (float)$row['salesqty'];
        $grandNominal += (float)$row['subtotal'];

        if(!isset($userStats[$row['usercreate']])){
            $userStats[$row['usercreate']] = [
                'qty' => 0,
                'nominal' => 0
            ];
        }

        $userStats[$row['usercreate']]['qty'] += (float)$row['salesqty'];
        $userStats[$row['usercreate']]['nominal'] += (float)$row['subtotal'];
    }

    $resDetail->free();

    ksort($summaryByDate);
    $dataRows = [];
    $totalNominal = 0;

    foreach($summaryByDate as $tgl => $summary){
        $nominal = (float)$summary['nominal'];
        $dataRows[] = [
            'tgl' => $tgl,
            'jumlah' => count($summary['salesids']),
            'nominal' => $nominal
        ];
        $totalNominal += $nominal;
    }

    $mysqli->close();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Dashboard Sales</title>
<link rel="icon" type="image/png" href="img/srt2.png" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
body{ background:#f4f6f9; font-family:sans-serif; margin:0; padding:0; }
.header{ padding:15px; background:#fff; box-shadow:0 0 10px rgba(0,0,0,0.1); text-align:center; }

/* Loading Overlay */
#overlay{
    position:fixed; top:0; left:0; width:100%; height:100%;
    background: rgba(255,255,255,0.9);
    backdrop-filter: blur(4px);
    display:flex; justify-content:center; align-items:center;
    z-index:9999; flex-direction:column;
}
#overlay .dots span{
    display:inline-block; width:10px; height:10px; margin:0 5px;
    background:#007bff; border-radius:50%;
    animation: bounce 1s infinite;
}
#overlay .dots span:nth-child(2){ animation-delay:0.2s; }
#overlay .dots span:nth-child(3){ animation-delay:0.4s; }
@keyframes bounce{
    0%, 80%, 100%{ transform:scale(0); }
    40%{ transform:scale(1); }
}

/* Card summary, accordion, etc sama seperti script asli */
.card-summary{ display:flex; flex-wrap:wrap; gap:15px; margin:15px; justify-content:center; }
.card-summary .card{ flex:1 1 220px; padding:20px; text-align:center; border-radius:12px; background: linear-gradient(135deg,#6a11cb,#2575fc); color:#fff; box-shadow:0 4px 15px rgba(0,0,0,0.2); transition: transform 0.2s;}
.card-summary .card:hover{ transform: translateY(-5px);}
.card-summary .card h5{ font-weight:500; font-size:1rem; }
.card-summary .card h3{ font-size:1.8rem; font-weight:bold; }
.accordion-card{ background:#fff; border-radius:10px; margin-bottom:10px; padding:15px; box-shadow:0 0 8px rgba(0,0,0,0.1);}
.accordion-header{ font-weight:bold; font-size:1rem; display:flex; justify-content:space-between; align-items:center; cursor:pointer;}
.accordion-subtitle{ color:#007bff; font-size:0.9rem; text-decoration:underline; cursor:pointer; }
.user-total{ font-weight:bold; background:#f0f0f0; padding:5px; margin-top:5px; }
.spacer{ height:20px; }
.grand-total{ font-weight:bold; background:#d0d0d0; padding:8px; margin-top:10px; text-align:right; }
.table-container{ padding:15px; }
.footer{ text-align:center; font-style:italic; padding:15px; color:#555; }
</style>
</head>
<body>

<!-- Overlay Loading -->
<div id="overlay">
    <h3>Verifikasi Hak Akses...</h3>
    <div class="dots">
        <span></span><span></span><span></span>
    </div>
</div>

<div class="header" style="display:none;" id="mainContent">
    <h2>Dashboard Realtime Sales</h2>
    <div class="alert alert-warning d-flex align-items-center justify-content-between" role="alert">
        <div class="d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <div>Halaman ini bersifat terbatas. Anda termasuk user yang berhak mengakses halaman ini.</div>
        </div>
        <button class="btn btn-primary mt-2" onclick="window.location.href='dashboard.php'">Kembali</button>
    </div>
</div>

<script>
// Simulasi delay loading 2 detik
setTimeout(()=>{
    document.getElementById('overlay').style.display='none';
    document.getElementById('mainContent').style.display='block';
},2000);
</script>

</div>
<div class="d-flex align-items-start gap-3" style="padding:15px;">
    

 <!-- CARD FORM -->
    <div class="card p-3" style="flex:1; box-shadow:0 0 10px rgba(0,0,0,0.1); border-radius:10px;">
        <form method="post" class="d-flex flex-wrap gap-3 align-items-center">
            
            <div class="d-flex flex-column">
                <label>Pilih Jam Awal</label>
                <input type="time" name="jam_awal" class="form-control" value="<?= htmlspecialchars($jam_awal) ?>" required>
            </div>
            
            <div class="d-flex flex-column">
                <label>Pilih Jam Akhir</label>
                <input type="time" name="jam_akhir" class="form-control" value="<?= htmlspecialchars($jam_akhir) ?>" required>
            </div>
            
            <div class="d-flex flex-column">
                <label>Pilih Tanggal Awal</label>
                <input type="date" name="tanggal_awal" class="form-control" value="<?= htmlspecialchars($tanggal_awal) ?>" required>
            </div>
            
            <div class="d-flex flex-column">
                <label>Pilih Tanggal Akhir</label>
                <input type="date" name="tanggal_akhir" class="form-control" value="<?= htmlspecialchars($tanggal_akhir) ?>" required>
            </div>
            
            <div class="d-flex flex-column align-self-end">
                <button type="submit" name="submit" class="btn btn-primary mt-2">Tampilkan</button>
            </div>
            
            <div class="d-flex flex-column">
                <label>Pilih Toko</label>
                <select name="db_index" class="form-control" required onchange="this.form.submit()">
                    <option value="">-- Pilih Toko --</option>
                    <?php foreach($map as $store=>$c): ?>
                        <option value="<?= htmlspecialchars($store) ?>" <?= ($namaToko==$store?'selected':'') ?>><?= htmlspecialchars($store) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

        </form>
    </div>
    
    <!-- LOGO TOKO -->
    <div style="flex-shrink:0; display:flex; align-items:center; justify-content:center;">
        <?php if($namaToko && isset($map[$namaToko])): ?>
            <img src="img/<?= $map[$namaToko]['logo'] ?>" 
                 alt="<?= htmlspecialchars($namaToko) ?>" 
                 style="max-height:150px; max-width:200px; object-fit:contain; border-radius:10px; box-shadow:0 0 8px rgba(0,0,0,0.3);">
        <?php endif; ?>
    </div>

</div>

<!-- CARD SUMMARY -->
<div class="card-summary">
    <div class="card">
        <h5>Total Transaksi</h5>
        <h3>
            <?= number_format(array_sum(array_column($dataRows,'jumlah'))) ?>
        </h3>
    </div>
    <div class="card">
        <h5>Total Qty</h5>
        <h3><?= number_format($grandQty ?? 0) ?></h3>
    </div>
    <div class="card">
        <h5>Total Nominal</h5>
        <h3>Rp <?= number_format($grandNominal ?? 0,0,',','.') ?></h3>
    </div>
    <div class="card">
        <h5>Rata-Rata /day</h5>
        <h3>
            Rp 
            <?php
                $jumlahTanggal = count($dataRows);
                $rata = ($jumlahTanggal > 0) ? ($grandNominal / $jumlahTanggal) : 0;
                echo number_format($rata,0,',','.');
            ?>
        </h3>
    </div>
</div>
<!-- CHARTS -->
<div class="table-container">
    <canvas id="chartQtyNominal" style="max-height:400px;"></canvas>
    <canvas id="chartPerUser" style="max-height:400px; margin-top:30px;"></canvas>
</div>

<!-- DETAIL PRODUK AKORDION -->
<div class="table-container">
<h3>Detail Riwayat Transaksi</h3>
<?php foreach($dataRows as $row):
    $tgl = $row['tgl'];
?>
<div class="accordion-card" data-target="body-<?= $tgl ?>">
    <div class="accordion-header">
        <span><?= date('d M Y', strtotime($tgl)) ?></span>
        <span class="accordion-subtitle">Lihat Riwayat transaksi</span>
    </div>
    <div class="accordion-body" id="body-<?= $tgl ?>" style="display:none;">
        <?php 
        $currentUser=''; $userQty=0; $userNom=0;
        $totalQtyDate=0; $totalNomDate=0;
        ?>
        <table class="table table-bordered table-striped">
            <thead>
                <tr><th>Waktu</th><th>User</th><th>Product</th><th>Qty</th><th>Price</th><th>Subtotal</th></tr>
            </thead>
            <tbody>
            <?php foreach($dataDetail as $d):
                if(substr($d['transdate'],0,10)==$tgl):
                    if($currentUser=='' || $currentUser!=$d['usercreate']):
                        if($currentUser!=''): ?>
                            <tr class="spacer"><td colspan="6"></td></tr>
                            <tr class="user-total"><td colspan="3">Total <?= $currentUser ?></td><td><?= number_format($userQty) ?></td><td></td><td>Rp <?= number_format($userNom,0,',','.') ?></td></tr>
                            <tr class="spacer"><td colspan="6"></td></tr>
                        <?php 
                        endif;
                        $currentUser=$d['usercreate']; $userQty=0; $userNom=0;
                    endif;
                    $userQty += $d['salesqty']; $userNom += $d['subtotal'];
                    $totalQtyDate += $d['salesqty']; $totalNomDate += $d['subtotal'];
            ?>
                <tr>
                    <td><?= $d['transdate'] ?></td>
                    <td><?= $d['usercreate'] ?></td>
                    <td><?= $d['product_name'] ?></td>
                    <td><?= number_format($d['salesqty']) ?></td>
                    <td>Rp <?= number_format($d['price'],0,',','.') ?></td>
                    <td>Rp <?= number_format($d['subtotal'],0,',','.') ?></td>
                </tr>
            <?php endif; endforeach; ?>
            <!-- Total terakhir user -->
            <?php if($currentUser!=''): ?>
                <tr class="spacer"><td colspan="6"></td></tr>
                <tr class="user-total"><td colspan="3">Total <?= $currentUser ?></td><td><?= number_format($userQty) ?></td><td></td><td>Rp <?= number_format($userNom,0,',','.') ?></td></tr>
                <tr class="spacer"><td colspan="6"></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <!-- Grand Total per Tanggal -->
        <div class="grand-total">
            Grand Total: Qty <?= number_format($totalQtyDate) ?> | Rp <?= number_format($totalNomDate,0,',','.') ?>
            <button class="btn btn-link p-0" onclick="closeAccordion('body-<?= $tgl ?>')">Tutup Riwayat</button>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<div class="footer">Sumber data: salesdetail, salespayments, salesdetailcopy, dan salespaymentscopy. Duplikat salesid hanya ditampilkan satu kali.</div>

<script>
// Accordion open/close
document.querySelectorAll('.accordion-header').forEach(header=>{
    header.addEventListener('click',()=>{
        const body = document.getElementById(header.parentElement.dataset.target);
        body.style.display = (body.style.display==='block') ? 'none' : 'block';
    });
});
function closeAccordion(id){ document.getElementById(id).style.display='none'; }

// CHARTS
const ctx = document.getElementById('chartQtyNominal').getContext('2d');
const labels = <?= json_encode(array_map(fn($r)=>date('d M',strtotime($r['tgl'])),$dataRows)) ?>;
const qtys = <?= json_encode(array_map(fn($r)=>0,$dataRows)) ?>; // default 0
const nominals = <?= json_encode(array_map(fn($r)=>0,$dataRows)) ?>;

// hitung dari $dataRows dan $dataDetail
<?php
$qtyPerDate=[]; $nomPerDate=[];
foreach($dataRows as $r){ $qtyPerDate[$r['tgl']]=0; $nomPerDate[$r['tgl']]=0; }
foreach($dataDetail as $d){
    $tgl = substr($d['transdate'],0,10);
    $qtyPerDate[$tgl]+=$d['salesqty'];
    $nomPerDate[$tgl]+=$d['subtotal'];
}
?>
const qtyData = <?= json_encode(array_values($qtyPerDate)) ?>;
const nomData = <?= json_encode(array_values($nomPerDate)) ?>;

new Chart(ctx,{
    type:'bar',
    data:{
        labels: labels,
        datasets:[
            {label:'Qty', data:qtyData, backgroundColor:'rgba(54,162,235,0.7)', yAxisID:'y'},
            {label:'Nominal', data:nomData, backgroundColor:'rgba(255,99,132,0.7)', yAxisID:'y1'}
        ]
    },
    options:{
        responsive:true,
        interaction:{mode:'index',intersect:false},
        stacked:false,
        scales:{
            y:{type:'linear', position:'left', title:{display:true,text:'Qty'}},
            y1:{type:'linear', position:'right', title:{display:true,text:'Nominal'}, grid:{drawOnChartArea:false}}
        }
    }
});

// CHART PER USER
const ctxUser = document.getElementById('chartPerUser').getContext('2d');
const userLabels = <?= json_encode(array_keys($userStats)) ?>;
const userQty = <?= json_encode(array_map(fn($u)=>$u['qty'],$userStats)) ?>;
const userNom = <?= json_encode(array_map(fn($u)=>$u['nominal'],$userStats)) ?>;

new Chart(ctxUser,{
    type:'bar',
    data:{
        labels:userLabels,
        datasets:[
            {label:'Qty', data:userQty, backgroundColor:'rgba(75,192,192,0.7)', yAxisID:'y'},
            {label:'Nominal', data:userNom, backgroundColor:'rgba(153,102,255,0.7)', yAxisID:'y1'}
        ]
    },
    options:{
        responsive:true,
        interaction:{mode:'index',intersect:false},
        stacked:false,
        scales:{
            y:{type:'linear', position:'left', title:{display:true,text:'Qty'}},
            y1:{type:'linear', position:'right', title:{display:true,text:'Nominal'}, grid:{drawOnChartArea:false}}
        }
    }
});
</script>

</body>
</html>

