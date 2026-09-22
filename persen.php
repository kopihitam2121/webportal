<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

require_once "map.php";

/* ================= LOGIN ================= */
$username = isset($_SESSION['username']) ? strtolower(trim($_SESSION['username'])) : '';

if($username == ''){
    header("Location: login.php");
    exit;
}

/* ================= STORES ================= */
$ALL_STORES = array(
    "URBAN B4",
    "Papi Coffee T1B",
    "URBAN B6",
    "URBAN B7",

    "Papi Mart T2 E3",
    "Papi Mart T2 E4",
    "Papi Mart T2 E5 NEW DB",
    "Papi Mart Gate 18",
    "Papi Mart BIM",

    "Latte Story T1C",
    "Latte Story T2E",
    "Latte Story T2F",

    "Ambil Bekal Yuk D2",
    "Ambil Bekal Yuk D6",

    "Point One D1",
    "Point One D3",
    "Point One D5",
    "Point One D7"
);

/* ================= RBAC ================= */
$USER_STORE_ACCESS = array(
    "ujang"=>$ALL_STORES,
    "yan"=>$ALL_STORES,
    "wira"=>$ALL_STORES,
    "aziz"=>$ALL_STORES,
    "admin1"=>$ALL_STORES,
    "admin"=>$ALL_STORES,
    "wahid"=>$ALL_STORES,
    "prengkuh"=>$ALL_STORES,
    "alfia"=>$ALL_STORES,
    "azik"=>$ALL_STORES,
    "putri"=>$ALL_STORES,
    "whina"=>$ALL_STORES,

    "mustaqim"=>array(
        "Latte Story T1C",
        "Latte Story T2E",
        "Latte Story T2F",
        "URBAN B4",
        "URBAN B6",
        "URBAN B7",
        "Papi Coffee T1B"
    ),

    "haris"=>array(
        "Papi Mart T2 E3",
        "Papi Mart T2 E4",
        "Papi Mart T2 E5 NEW DB",
        "Papi Mart Gate 18",
        "Point One D1",
        "Point One D3",
        "Point One D5",
        "Point One D7",
        "Ambil Bekal Yuk D2",
        "Ambil Bekal Yuk D6"
    ),

 "lusiah"=>array(
        "Papi Mart T2 E3",
        "Papi Mart T2 E4",
        "Papi Mart T2 E5 NEW DB"
    ),

    "dede"=>array(
        "Papi Mart Gate 18"
    ),

    "yanto"=>array(
        "Latte Story T1C"
    ),

    "umam"=>array(
        "Latte Story T2E",
        "Latte Story T2F"
    ),

    "jesen"=>array(
        "URBAN B4",
        "URBAN B6",
        "URBAN B7",
        "Papi Coffee T1B"
    ),

    "sopyan"=>array(
        "URBAN B7",
        "Papi Coffee T1B"
    ),


    "devi"=>array(
        "Point One D1",
        "Point One D3",
        "Point One D5"
    ),

    "wulan"=>array(
        "Papi Mart BIM"
    ),


    "adit"=>array(
        "Ambil Bekal Yuk D2",
        "Ambil Bekal Yuk D6",
        "Point One D7"
    )
);

/* ================= RBAC TERPUSAT =================
   Pengaturan yang sudah disimpan dari rbac.php menjadi sumber utama.
   Jika user belum pernah disimpan, daftar lama di atas tetap digunakan.
*/
$allowedStores = isset($USER_STORE_ACCESS[$username]) ? $USER_STORE_ACCESS[$username] : array();
$rbacConn = null;

require __DIR__ . '/db.php';
if (isset($conn) && $conn instanceof mysqli) {
    $rbacConn = $conn;
}
unset($conn);

if ($rbacConn instanceof mysqli) {
    mysqli_set_charset($rbacConn, 'utf8mb4');

    @mysqli_query($rbacConn, "
        CREATE TABLE IF NOT EXISTS rbac_outlet_percentage_config_v5 (
            username VARCHAR(100) NOT NULL PRIMARY KEY,
            saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    @mysqli_query($rbacConn, "
        CREATE TABLE IF NOT EXISTS rbac_outlet_percentage_flags_v5 (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            outlet_name VARCHAR(160) NOT NULL,
            can_view TINYINT(1) NOT NULL DEFAULT 1,
            saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_outlet_percentage (username, outlet_name),
            INDEX idx_username (username),
            INDEX idx_outlet_name (outlet_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $hasCentralConfig = false;
    $stmtConfig = mysqli_prepare($rbacConn, "SELECT username FROM rbac_outlet_percentage_config_v5 WHERE username = ? LIMIT 1");
    if ($stmtConfig) {
        mysqli_stmt_bind_param($stmtConfig, 's', $username);
        mysqli_stmt_execute($stmtConfig);
        $resultConfig = mysqli_stmt_get_result($stmtConfig);
        $hasCentralConfig = $resultConfig && mysqli_num_rows($resultConfig) > 0;
        mysqli_stmt_close($stmtConfig);
    }

    if ($hasCentralConfig) {
        $allowedStores = array();
        $stmtAccess = mysqli_prepare($rbacConn, "
            SELECT outlet_name
            FROM rbac_outlet_percentage_flags_v5
            WHERE username = ? AND can_view = 1
            ORDER BY outlet_name ASC
        ");
        if ($stmtAccess) {
            mysqli_stmt_bind_param($stmtAccess, 's', $username);
            mysqli_stmt_execute($stmtAccess);
            $resultAccess = mysqli_stmt_get_result($stmtAccess);
            while ($resultAccess && $rowAccess = mysqli_fetch_assoc($resultAccess)) {
                $outletName = (string)($rowAccess['outlet_name'] ?? '');
                if ($outletName !== '' && in_array($outletName, $ALL_STORES, true)) {
                    $allowedStores[] = $outletName;
                }
            }
            mysqli_stmt_close($stmtAccess);
        }
    }

    mysqli_close($rbacConn);
}

/* ================= INVOICE PREFIX ================= */
$storeInvoicePrefix = array(
    "URBAN B4"=>"UT1B4SL",
    "URBAN B6"=>"UT1BG6SL",
    "URBAN B7"=>"UT1BG7SL",

    "Papi Coffee T1B"=>"PPT1B5SL",

    "Papi Mart T2 E3"=>"PP119SL",
    "Papi Mart T2 E4"=>"PP219SL",
    "Papi Mart T2 E5 NEW DB"=>"PP319SL",
    "Papi Mart Gate 18"=>"PG28SL",
    "Papi Mart BIM"=>"MTR3718SL",

    "Latte Story T1C"=>"T1CSL",
    "Latte Story T2E"=>"SLLST2E",
    "Latte Story T2F"=>"SL23SLT2F",

    "Ambil Bekal Yuk D2"=>"BT2D2SL",
    "Ambil Bekal Yuk D6"=>"BT2DSL",

    "Point One D1"=>"P1D1SL",
    "Point One D3"=>"P1D3SL",
    "Point One D5"=>"P1D5SL",
    "Point One D7"=>"P1D7SL"
);

/* ================= TARGET OUTLET ================= */
$storeTargets = array(
    "Point One D1"=>90,
    "Point One D3"=>80,
    "Point One D5"=>80,
    "Point One D7"=>80,

    "Papi Mart BIM"=>100,

    "Latte Story T2F"=>70,
    "Latte Story T2E"=>55,
    "Latte Story T1C"=>80,

    /* Pada tabel target tertulis PAPI COFFEE B5. */
    "Papi Coffee T1B"=>70,

    "URBAN B4"=>70,
    "URBAN B6"=>70,
    "URBAN B7"=>100,

    "Papi Mart Gate 18"=>100,

    "Ambil Bekal Yuk D2"=>85,
    "Ambil Bekal Yuk D6"=>85,

    "Papi Mart T2 E3"=>55,
    "Papi Mart T2 E4"=>55,
    "Papi Mart T2 E5 NEW DB"=>55
);

/* ================= STORE SELECT ================= */
$store = isset($_POST['store']) ? trim($_POST['store']) : '';

/* Hanya tampilkan outlet yang ada di map.php */
$allowedStores = array_values(array_intersect($allowedStores, array_keys($map)));

if($store != '' && !in_array($store, $allowedStores)){
    $store = '';
}

/* ================= HEADER ================= */
function page_header($username){ ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="img/srt2.png" />
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>Persentase</title>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
*{
    box-sizing:border-box;
}

body{
    margin:0;
    font-family:Inter, Arial, sans-serif;
    background:#eef4ff;
}

.header{
    background:linear-gradient(135deg,#2563eb,#0ea5e9);
    color:white;
    text-align:center;
    padding:26px 18px;
    border-bottom-left-radius:28px;
    border-bottom-right-radius:28px;
}

.header h2{
    margin:0;
    font-size:22px;
    font-weight:900;
}

.header small{
    display:block;
    margin-top:6px;
    font-weight:700;
    opacity:.9;
}

.card{
    width:92%;
    max-width:420px;
    margin:25px auto;
    background:white;
    padding:22px;
    border-radius:20px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    text-align:center;
}

.logo{
    width:90px;
    height:90px;
    object-fit:contain;
}

.store{
    font-size:19px;
    font-weight:900;
    margin-top:10px;
}

.percent{
    font-size:58px;
    font-weight:900;
    margin-top:12px;
}

.percent.is-below{
    color:#dc2626;
}

.percent.is-reached{
    color:#16a34a;
}

.time-info{
    margin-top:8px;
    color:#64748b;
    font-size:12px;
    font-weight:700;
}

.target-info{
    margin-top:7px;
    color:#0f172a;
    font-size:14px;
    font-weight:900;
}

.target-status{
    margin-top:3px;
    font-size:11px;
    font-weight:800;
}

.target-status.is-below{
    color:#dc2626;
}

.target-status.is-reached{
    color:#16a34a;
}

select,
button{
    width:100%;
    padding:13px;
    border-radius:12px;
    margin-top:12px;
    font-weight:800;
    font-size:15px;
}

select{
    border:1px solid #cbd5e1;
    background:#f8fafc;
}

button{
    background:#2563eb;
    color:white;
    border:none;
    cursor:pointer;
}

button:hover{
    background:#1d4ed8;
}

a.btn{
    display:block;
    margin-top:10px;
    padding:12px;
    border-radius:12px;
    text-decoration:none;
    font-weight:800;
    text-align:center;
    background:#111827;
    color:white;
}
</style>
</head>
<body>

<div class="header">
    <h2>Persentase per Outlet</h2>
    <small><?= htmlspecialchars(strtoupper($username), ENT_QUOTES, 'UTF-8') ?></small>
</div>

<?php } ?>

<?php function page_footer(){ ?>
</body>
</html>
<?php } ?>

<?php
/* ================= SELECT PAGE ================= */
if($store == ''){
    page_header($username);
?>
<div class="card">
    <h3>Pilih Outlet</h3>

    <?php if(count($allowedStores) == 0){ ?>
        <p>Belum ada outlet yang tersedia untuk user ini.</p>
        <a class="btn" href="dashboard.php">Kembali ke Dashboard</a>
    <?php } else { ?>
        <form method="POST">
            <select name="store" required>
                <option value="">-- pilih toko --</option>
                <?php foreach($allowedStores as $s){ ?>
                    <option value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php } ?>
            </select>

            <button type="submit">Lihat Data</button>

            <a class="btn" href="dashboard.php">Kembali ke Dashboard</a>
        </form>
    <?php } ?>
</div>
<?php
    page_footer();
    exit;
}

/* ================= VALIDASI ================= */
if(!isset($map[$store]) || !isset($storeInvoicePrefix[$store])){
    page_header($username); ?>
<script>
Swal.fire({
    icon:'error',
    title:'Error',
    text:'Data outlet tidak ditemukan'
}).then(()=>location.href='persen.php');
</script>
<?php
    page_footer();
    exit;
}

/* ================= DB CONNECT ================= */
$config = $map[$store];

$host = isset($config['ip']) ? $config['ip'] : '';
$db   = isset($config['db']) ? $config['db'] : '';
$user = isset($config['user']) ? $config['user'] : '';
$pass = isset($config['pass']) ? $config['pass'] : '';
$port = isset($config['port']) ? (int)$config['port'] : 3306;

$conn = mysqli_init();

mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 15);

if(defined('MYSQLI_OPT_READ_TIMEOUT')){
    mysqli_options($conn, MYSQLI_OPT_READ_TIMEOUT, 20);
}

$ok = mysqli_real_connect(
    $conn,
    $host,
    $user,
    $pass,
    $db,
    $port
);

if(!$ok){
    page_header($username); ?>
<script>
Swal.fire({
    icon:'error',
    title:'Toko Offline',
    text:'Toko ini sedang offline, hubungi tim toko untuk memeriksa jaringan internet',
    confirmButtonText:'OK'
}).then(()=>location.href='persen.php');
</script>
<?php
    page_footer();
    exit;
}

mysqli_set_charset($conn,"utf8mb4");

$prefix = $storeInvoicePrefix[$store];

/*
    Jika kode faktur selalu di awal salesid:
    contoh UT1BG6SL12345
*/
$likePrefix = $prefix . '%';

/*
    Jika nanti hasil masih 0%, kemungkinan kode faktur tidak di awal salesid.
    Ganti baris atas menjadi:
    $likePrefix = '%' . $prefix . '%';
*/

/* ================= QUERY PERSENTASE ================= */
/*
    Query mengikuti rumus asli:

    total netamount dengan prefix salesid tertentu hari ini
    dibagi total seluruh netamount hari ini
    dikali 100.

    Tanda ? dipakai agar prefix outlet tetap dinamis dan aman.
*/
$sql = "
SELECT ROUND(
    (
        (
            SELECT SUM(`salesdetail`.`netamount`)
            FROM `salesdetail`
            WHERE `salesdetail`.`salesid` LIKE ?
              AND DATE_FORMAT(`salesdetail`.`transdate`, '%Y%m%d') = DATE_FORMAT(CURDATE(), '%Y%m%d')
        )
        /
        (
            SELECT SUM(`salesdetail`.`netamount`)
            FROM `salesdetail`
            WHERE DATE_FORMAT(`salesdetail`.`transdate`, '%Y%m%d') = DATE_FORMAT(CURDATE(), '%Y%m%d')
        )
    ) * 100
) AS nilai
";

$stmt = $conn->prepare($sql);

if(!$stmt){
    $conn->close();
    page_header($username); ?>
<script>
Swal.fire({
    icon:'error',
    title:'Query Gagal',
    text:'Query persentase tidak dapat diproses',
    confirmButtonText:'OK'
}).then(()=>location.href='persen.php');
</script>
<?php
    page_footer();
    exit;
}

$stmt->bind_param("s", $likePrefix);

if(!$stmt->execute()){
    $stmt->close();
    $conn->close();

    page_header($username); ?>
<script>
Swal.fire({
    icon:'error',
    title:'Query Gagal',
    text:'Data persentase tidak dapat diambil',
    confirmButtonText:'OK'
}).then(()=>location.href='persen.php');
</script>
<?php
    page_footer();
    exit;
}

$stmt->bind_result($nilai);
$stmt->fetch();

/*
    Jika hari ini belum ada transaksi, SUM menghasilkan NULL.
    Supaya tampilan tetap 0%, nilai NULL diubah menjadi 0 di PHP.
*/
$nilai = $nilai !== null ? (float)$nilai : 0;

$stmt->close();
$conn->close();

/* ================= FORMAT HASIL ================= */
$waktuNow = date('d/m/Y H:i');

$target = isset($storeTargets[$store]) ? (float)$storeTargets[$store] : 0;

/*
    Status dibandingkan memakai angka bulat yang sama dengan tampilan.
    Contoh: nilai database 100,4 ditampilkan 100%, sehingga dianggap pas
    dengan target 100%, bukan kelebihan.
*/
$nilaiBulat  = (int) round($nilai);
$targetBulat = (int) round($target);

$nilaiDisplay  = number_format($nilaiBulat, 0, ',', '.');
$targetDisplay = number_format($targetBulat, 0, ',', '.');

if($nilaiBulat < $targetBulat){
    $percentClass = 'is-below';
    $statusText = 'Kurang dari target';
}elseif($nilaiBulat === $targetBulat){
    $percentClass = 'is-reached';
    $statusText = 'Alhamdulillah sesuai';
}else{
    $percentClass = 'is-reached';
    $statusText = 'Persentase Melebihi Target';
}

/* ================= RESULT ================= */
page_header($username);
?>

<div class="card">

    <?php if(isset($config['logo']) && $config['logo'] != ''){ ?>
        <img class="logo" src="img/<?= htmlspecialchars($config['logo'], ENT_QUOTES, 'UTF-8') ?>" alt="Logo">
    <?php } ?>

    <div class="store">
        <?= htmlspecialchars($store, ENT_QUOTES, 'UTF-8') ?>
    </div>

    <div class="percent <?= htmlspecialchars($percentClass, ENT_QUOTES, 'UTF-8') ?>">
        <?= $nilaiDisplay ?>%
    </div>

    <div class="time-info">
        Update hari ini <?= htmlspecialchars($waktuNow, ENT_QUOTES, 'UTF-8') ?> WIB
    </div>

    <div class="target-info">
        Target: <?= $targetDisplay ?>%
    </div>

    <div class="target-status <?= htmlspecialchars($percentClass, ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') ?>
    </div>

    <form method="POST">
        <button type="submit">Kembali</button>
    </form>

    <a class="btn" href="dashboard.php">Kembali ke Dashboard</a>

</div>

<?php page_footer(); ?>
