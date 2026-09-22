<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ================= KONEKSI DB =================
   Pastikan file db.php ada di folder yang sama dan berisi koneksi:
   <?php
   $conn = mysqli_connect("host","user","password","database");
   if(!$conn){ die("Koneksi gagal: ".mysqli_connect_error()); }
*/
require_once __DIR__ . "/db.php";

if(!isset($conn) || !$conn){
    die("Koneksi database belum tersedia. Cek file db.php");
}

$isWira = (strtolower($_SESSION['username'] ?? '') === 'wira');

/* ================= HAK AKSES DELETE =================
   Delete boleh untuk role SUPERVISOR ke atas.
   User dengan jabatan/role LEADER tetap TIDAK boleh delete.
   Beberapa nama key session didukung agar kompatibel dengan login yang berbeda.
*/
function currentUserRoleSession(){
    $keys = ['role', 'user_role', 'jabatan', 'level', 'posisi'];
    foreach($keys as $key){
        if(isset($_SESSION[$key]) && trim((string)$_SESSION[$key]) !== ''){
            return trim((string)$_SESSION[$key]);
        }
    }
    return '';
}

function isLeaderRole($role){
    $role = strtolower(trim((string)$role));
    return $role !== '' && strpos($role, 'leader') !== false;
}

function isSupervisorOrAbove($role){
    $role = strtolower(trim((string)$role));
    if($role === '') return false;

    // Leader sengaja dikecualikan meskipun nama role mengandung keyword lain.
    if(strpos($role, 'leader') !== false) return false;

    $allowedKeywords = [
        'supervisor',
        'spv',
        'coordinator',
        'koordinator',
        'assistant manager',
        'asst manager',
        'manager',
        'head',
        'general manager',
        'gm',
        'director',
        'direktur',
        'owner',
        'admin',
        'administrator',
        'hrd',
        'human resource'
    ];

    foreach($allowedKeywords as $keyword){
        if(strpos($role, $keyword) !== false){
            return true;
        }
    }
    return false;
}

$currentUserRole = currentUserRoleSession();
$currentUserJabatan = trim((string)($_SESSION['jabatan'] ?? ''));
$isLeaderUser = isLeaderRole($currentUserRole) || isLeaderRole($currentUserJabatan);
$canDelete = !$isLeaderUser && ($isWira || isSupervisorOrAbove($currentUserRole) || isSupervisorOrAbove($currentUserJabatan));

/* ================= HELPERS ================= */
function e($str){
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}


if (!function_exists('masaKerja')) {
    function masaKerja($tgl){
        if(!$tgl || $tgl=='0000-00-00') return '-';
        $start = new DateTime($tgl);
        $now = new DateTime();
        $d = $start->diff($now);
        return $d->y.' th '.$d->m.' bl '.$d->d.' hr';
    }
}

function tanggalIndo($date){
    if(!$date || $date === '0000-00-00') return '';
    $ts = strtotime($date);
    if(!$ts) return '';
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    return date('j', $ts).' '.$bulan[(int)date('n', $ts)].' '.date('Y', $ts);
}

function formatStatusKaryawan($status, $resignAt = ''){
    $status = trim((string)$status);
    if($status === 'RESIGN'){
        $tgl = tanggalIndo($resignAt);
        return $tgl !== '' ? "Resign pada tanggal ".$tgl : "RESIGN";
    }
    return $status;
}

function hasColumn($conn, $table, $column){
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $q = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $q && mysqli_num_rows($q) > 0;
}

/* ================= AUTO ADD COLUMN UNTUK FITUR RESIGN ================= */
if(!hasColumn($conn, 'karyawan', 'is_resign')){
    mysqli_query($conn, "ALTER TABLE karyawan ADD COLUMN is_resign TINYINT(1) NOT NULL DEFAULT 0 AFTER keterangan");
}
if(!hasColumn($conn, 'karyawan', 'resign_at')){
    mysqli_query($conn, "ALTER TABLE karyawan ADD COLUMN resign_at DATETIME NULL AFTER is_resign");
}
if(!hasColumn($conn, 'karyawan', 'berakhir_kontrak')){
    mysqli_query($conn, "ALTER TABLE karyawan ADD COLUMN berakhir_kontrak DATE NULL AFTER resign_at");
}
if(!hasColumn($conn, 'karyawan', 'is_mutasi_divisi')){
    mysqli_query($conn, "ALTER TABLE karyawan ADD COLUMN is_mutasi_divisi TINYINT(1) NOT NULL DEFAULT 0 AFTER berakhir_kontrak");
}
if(!hasColumn($conn, 'karyawan', 'mutasi_divisi_at')){
    mysqli_query($conn, "ALTER TABLE karyawan ADD COLUMN mutasi_divisi_at DATETIME NULL AFTER is_mutasi_divisi");
}
if(!hasColumn($conn, 'karyawan', 'divisi_baru')){
    mysqli_query($conn, "ALTER TABLE karyawan ADD COLUMN divisi_baru VARCHAR(80) NULL AFTER mutasi_divisi_at");
}

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS mutasi_divisi (
        id INT AUTO_INCREMENT PRIMARY KEY,
        karyawan_id INT NOT NULL,
        nama_lengkap VARCHAR(180) NOT NULL,
        jabatan VARCHAR(100) NULL,
        terminal_lama VARCHAR(180) NULL,
        outlet_lama VARCHAR(180) NULL,
        divisi_baru VARCHAR(80) NOT NULL,
        created_by VARCHAR(100) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mutasi_karyawan (karyawan_id),
        INDEX idx_mutasi_divisi (divisi_baru),
        INDEX idx_mutasi_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ================= AUTO PINDAH KE KARYAWAN RESIGN SETELAH 2 HARI =================
   Alur:
   - User pilih status RESIGN
   - Isi Tanggal Resign di popup
   - Data tetap tampil di lineup aktif selama 2 hari dari tanggal resign
   - Setelah lewat 2 hari, saat halaman ini dibuka, data otomatis pindah ke card Karyawan Resign
*/
mysqli_query($conn, "
    UPDATE karyawan
    SET is_resign=1,
        updated_at=NOW()
    WHERE IFNULL(is_resign,0)=0
      AND status_surat='RESIGN'
      AND resign_at IS NOT NULL
      AND DATE_ADD(DATE(resign_at), INTERVAL 2 DAY) <= CURDATE()
");

/* ================= MIGRASI NAMA STATUS LAMA =================
   Data lama "Surat Pembinaan" otomatis diubah menjadi "Kartu Pembinaan"
*/
mysqli_query($conn, "
    UPDATE karyawan
    SET status_surat = REPLACE(status_surat, 'Surat Pembinaan', 'Kartu Pembinaan'),
        updated_at = NOW()
    WHERE status_surat LIKE 'Surat Pembinaan%'
");


/* ================= LIST STATUS OPTIONS ================= */
$statusOptions = [
    "" => "-- Pilih Status --",
    "Kartu Pembinaan 1" => "Kartu Pembinaan 1",
    "Kartu Pembinaan 2" => "Kartu Pembinaan 2",
    "Kartu Pembinaan 3" => "Kartu Pembinaan 3",
    "Surat Peringatan 1" => "Surat Peringatan 1",
    "Surat Peringatan 2" => "Surat Peringatan 2",
    "Surat Peringatan 3" => "Surat Peringatan 3",
    "RESIGN" => "RESIGN",
];

$divisiOptions = [
    "Wrapping",
    "Cellular",
    "Reflexology",
    "Hans",
    "F&B",
    "Money Changer",
    "Head Office",
];

/* ================= AMBIL ENUM JABATAN DARI DB ================= */
$jabatanOptions = [];
$col = mysqli_fetch_assoc(mysqli_query($conn, "SHOW COLUMNS FROM karyawan LIKE 'jabatan'"));
if($col && isset($col['Type'])){
    if(preg_match("/^enum\((.*)\)$/", $col['Type'], $m)){
        $vals = str_getcsv($m[1], ',', "'");
        foreach($vals as $v){
            $jabatanOptions[$v] = $v;
        }
    }
}
if(empty($jabatanOptions)){
    $jabatanOptions = [
        "CREW" => "CREW",
        "PLAN BARISTA" => "PLAN BARISTA",
        "TRAINING" => "TRAINING",
        "CREW LEADER" => "CREW LEADER",
    ];
}

/* ================= EXPORT EXCEL ================= */
if(isset($_GET['export'])){
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=LINE UP KARYAWAN.xls");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "
    <html>
    <head>

<style>
.col_nama{white-space:nowrap;min-width:260px;}
.col_jabatan{width:120px;white-space:nowrap;}
</style>

        <meta charset='UTF-8'>
        <style>
            body{font-family:Arial,sans-serif;font-size:12px;}
            table{border-collapse:collapse;width:100%;}
            th,td{border:1px solid #9e9e9e;padding:6px 8px;vertical-align:middle;}
            .title{font-size:16px;font-weight:bold;text-align:center;background:#1f4e78;color:#fff;}
            .terminal-row{background:#d9eaf7;font-weight:bold;color:#1f1f1f;font-size:13px;}
            .outlet-row{background:#e2f0d9;font-weight:bold;color:#1f1f1f;}
            .header-row th{background:#4472c4;color:#fff;text-align:center;font-weight:bold;}
            .crewleader-label{background:#fff2cc;font-weight:bold;color:#7f6000;}
            .crewleader-value{background:#fff9e6;font-weight:bold;}
            .resign-title{background:#ffcdd2;font-weight:bold;color:#b71c1c;}
            .text-center{text-align:center;}
            .blank-row td{border:none;height:12px;background:#fff;}
        </style>
    </head>
    <body>
    <table>
    <tr><td class='title' colspan='8'>DATA KARYAWAN PER TERMINAL & OUTLET</td></tr>
    <tr class='blank-row'><td colspan='8'></td></tr>
    ";

    $qTerminal = mysqli_query($conn, "SELECT id, nama_terminal FROM terminal ORDER BY id ASC");
    while($t = mysqli_fetch_assoc($qTerminal)){
        $terminalId = mysqli_real_escape_string($conn, $t['id']);
        echo "<tr class='terminal-row'><td colspan='8'>TERMINAL : ".e($t['nama_terminal'])."</td></tr>";

        $qOutlet = mysqli_query($conn, "SELECT id, nama_outlet FROM outlet WHERE terminal_id='{$terminalId}' ORDER BY nama_outlet ASC");
        while($o = mysqli_fetch_assoc($qOutlet)){
            $outletId = mysqli_real_escape_string($conn, $o['id']);
            echo "<tr class='outlet-row'><td colspan='8'>OUTLET / TITIK : ".e($o['nama_outlet'])."</td></tr>";
            echo "<tr class='header-row'>
                    <th width='5%'>No</th>
                    <th width='22%'>Nama</th>
                    <th width='14%'>Jabatan</th>
                    <th width='15%'>Status</th>
                    <th width='18%'>Keterangan</th>
                    <th width='12%'>Nama PT</th>
                    <th width='7%'>Terminal Pas</th>
                    <th width='12%'>Masa Berlaku Pas</th>
                  </tr>";

            $no = 1;
            $qKaryawan = mysqli_query($conn, "
                SELECT nama_lengkap, jabatan, IFNULL(status_surat,'') AS status_surat,
                       IFNULL(keterangan,'') AS keterangan, IFNULL(nama_pt,'') AS nama_pt,
                       IFNULL(terminal_pas,'') AS terminal_pas,
                       IFNULL(DATE_FORMAT(masa_berlaku_pas,'%Y-%m-%d'),'') AS masa_berlaku_pas,
                       IFNULL(DATE_FORMAT(resign_at,'%Y-%m-%d'),'') AS resign_at
                FROM karyawan
                WHERE outlet_id='{$outletId}'
                  AND jabatan <> 'CREW LEADER'
                  AND IFNULL(is_resign,0)=0
              AND IFNULL(is_mutasi_divisi,0)=0
                ORDER BY nama_lengkap ASC
            ");

            while($d = mysqli_fetch_assoc($qKaryawan)){
                echo "<tr>
                        <td class='text-center'>{$no}</td>
                        <td>".e($d['nama_lengkap'])."</td>
                        <td>".e($d['jabatan'])."</td>
                        <td>".e(formatStatusKaryawan($d['status_surat'], $d['resign_at'] ?? ''))."</td>
                        <td>".e($d['keterangan'])."</td>
                        <td>".e($d['nama_pt'])."</td>
                        <td>".e($d['terminal_pas'])."</td>
                        <td>".e($d['masa_berlaku_pas'])."</td>
                    </tr>";
                $no++;
            }

            $qLeader = mysqli_query($conn, "SELECT nama_lengkap FROM karyawan WHERE outlet_id='{$outletId}' AND jabatan='CREW LEADER' AND IFNULL(is_resign,0)=0
              AND IFNULL(is_mutasi_divisi,0)=0 ORDER BY nama_lengkap ASC");
            $leaderNames = [];
            while($l = mysqli_fetch_assoc($qLeader)) $leaderNames[] = $l['nama_lengkap'];

            $leaderGabung = !empty($leaderNames) ? e(implode(', ', $leaderNames)) : 'VACANT';
            echo "<tr>
                    <td colspan='2' class='crewleader-label'>CREW LEADER</td>
                    <td colspan='6' class='crewleader-value'>{$leaderGabung}</td>
                 </tr>";

            $qResign = mysqli_query($conn, "
                SELECT nama_lengkap, jabatan, IFNULL(keterangan,'') AS keterangan,
                       IFNULL(DATE_FORMAT(resign_at,'%Y-%m-%d'),'') AS resign_at,
                       IFNULL(DATE_FORMAT(berakhir_kontrak,'%Y-%m-%d'),'') AS berakhir_kontrak
                FROM karyawan
                WHERE outlet_id='{$outletId}'
                  AND jabatan <> 'CREW LEADER'
                  AND IFNULL(is_resign,0)=1
                ORDER BY resign_at DESC, nama_lengkap ASC
            ");

            if($qResign && mysqli_num_rows($qResign) > 0){
                echo "<tr><td colspan='8' class='resign-title'>Karyawan Resign</td></tr>";
                $nr = 1;
                while($r = mysqli_fetch_assoc($qResign)){
                    echo "<tr>
                            <td class='text-center'>{$nr}</td>
                            <td>".e($r['nama_lengkap'])."</td>
                            <td>".e($r['jabatan'])."</td>
                            <td colspan='3'>".e($r['keterangan'])."</td>
                            <td>".e($r['resign_at'])."</td><td>".e($r['berakhir_kontrak'] ?: '-')."</td>
                          </tr>";
                    $nr++;
                }
            }

            echo "<tr class='blank-row'><td colspan='8'></td></tr>";
        }
        echo "<tr class='blank-row'><td colspan='8'></td></tr>";
    }

    echo "</table>
<script>
function toggleActionMenu(btn){
  let menu = btn.nextElementSibling;
  if(!menu) return;
  menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
}
document.addEventListener('click', function(e){
  document.querySelectorAll('.action-dropdown').forEach(m=>{
    if(!m.contains(e.target) && !e.target.closest('.btn-action-toggle')){
      m.style.display='none';
    }
  });
});
</script>

</body></html>";
    exit;
}

/* ================= UPDATE STATUS ================= */
if(isset($_POST['update_status'])){
    $id = mysqli_real_escape_string($conn, $_POST['id'] ?? '');
    $status = mysqli_real_escape_string($conn, $_POST['status_surat'] ?? '');

    // RESIGN wajib lewat popup agar tanggal resign tidak kosong
    if($status === 'RESIGN'){
        header("Location: karyawan.php");
        exit;
    }

    mysqli_query($conn, "
        UPDATE karyawan
        SET status_surat='$status',
            updated_at=NOW()
        WHERE id='$id'
    ");
    header("Location: karyawan.php");
    exit;
}

/* ================= UPLOAD DOKUMEN ================= */
if(isset($_POST['upload_dokumen'])){
    $karyawan_id = mysqli_real_escape_string($conn, $_POST['karyawan_id'] ?? '');
    $uploadDir = __DIR__ . "/uploads/dokumen_karyawan/";

    if(!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    if(isset($_FILES['dokumen']) && $_FILES['dokumen']['error'] == 0){
        $ext = strtolower(pathinfo($_FILES['dokumen']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','webp'];
        if(!in_array($ext, $allowed)) die("Format file harus jpg/jpeg/png/webp");

        $newName = "dok_{$karyawan_id}_".time().".".$ext;
        $targetPath = $uploadDir.$newName;

        if(move_uploaded_file($_FILES['dokumen']['tmp_name'], $targetPath)){
            $dbPath = "uploads/dokumen_karyawan/".$newName;
            mysqli_query($conn, "INSERT INTO karyawan_dokumen (karyawan_id, file_path) VALUES ('$karyawan_id', '$dbPath')");
            header("Location: karyawan.php");
            exit;
        }else{
            die("Gagal upload dokumen");
        }
    }else{
        die("File dokumen tidak valid");
    }
}

/* ================= AJAX GET DOKUMEN TERAKHIR ================= */
if(isset($_GET['get_dokumen'])){
    $id = mysqli_real_escape_string($conn, $_GET['id'] ?? '');
    $q = mysqli_query($conn, "SELECT file_path FROM karyawan_dokumen WHERE karyawan_id='$id' ORDER BY id DESC LIMIT 1");
    $d = mysqli_fetch_assoc($q);

    header("Content-Type: application/json");
    echo json_encode(["ok" => $d ? true : false, "file_path" => $d['file_path'] ?? ""]);
    exit;
}

/* ================= AJAX LOAD OUTLET ================= */
if(isset($_GET['get_outlet'])){
    $terminal_id = mysqli_real_escape_string($conn, $_GET['terminal_id'] ?? '');
    $q = mysqli_query($conn, "SELECT * FROM outlet WHERE terminal_id='$terminal_id' ORDER BY nama_outlet ASC");
    echo "<option value=''>-- Pilih Outlet --</option>";
    while($d = mysqli_fetch_assoc($q)){
        echo "<option value='".e($d['id'])."'>".e($d['nama_outlet'])."</option>";
    }
    exit;
}

/* ================= AJAX GET KARYAWAN UNTUK CREW LEADER ================= */
if(isset($_GET['get_karyawan_outlet'])){
    header("Content-Type: application/json");

    $q = mysqli_query($conn, "
        SELECT x.id, x.nama_lengkap, x.jabatan, x.nama_outlet, x.nama_terminal
        FROM (
            SELECT 
                MIN(k.id) AS id,
                TRIM(k.nama_lengkap) AS nama_lengkap,
                MIN(k.jabatan) AS jabatan,
                MIN(o.nama_outlet) AS nama_outlet,
                MIN(t.nama_terminal) AS nama_terminal
            FROM karyawan k
            LEFT JOIN outlet o ON k.outlet_id = o.id
            LEFT JOIN terminal t ON k.terminal_id = t.id
            WHERE TRIM(IFNULL(k.nama_lengkap,'')) <> ''
              AND IFNULL(k.is_resign,0)=0
              AND IFNULL(k.is_mutasi_divisi,0)=0
            GROUP BY TRIM(k.nama_lengkap)
        ) x
        ORDER BY x.nama_lengkap ASC
    ");

    $rows = [];
    while($r = mysqli_fetch_assoc($q)){
        $rows[] = [
            'id' => $r['id'],
            'nama_lengkap' => $r['nama_lengkap'],
            'jabatan' => $r['jabatan'],
            'nama_outlet' => $r['nama_outlet'] ?? '',
            'nama_terminal' => $r['nama_terminal'] ?? '',
        ];
    }

    echo json_encode(["ok"=>true,"data"=>$rows]);
    exit;
}

/* ================= AJAX TAMBAH KARYAWAN PER OUTLET ================= */
if(isset($_POST['ajax_add_karyawan'])){
    header("Content-Type: application/json");

    $terminal_id = mysqli_real_escape_string($conn, $_POST['terminal_id'] ?? '');
    $outlet_id   = mysqli_real_escape_string($conn, $_POST['outlet_id'] ?? '');
    $nama        = trim($_POST['nama'] ?? '');
    $jabatan     = trim($_POST['jabatan'] ?? 'CREW');
    $keterangan  = trim($_POST['keterangan'] ?? '');

    $namaEsc = mysqli_real_escape_string($conn, $nama);
    $jabEsc  = mysqli_real_escape_string($conn, $jabatan);
    $ketEsc  = mysqli_real_escape_string($conn, $keterangan);

    if($terminal_id==='' || $outlet_id==='' || $nama===''){
        echo json_encode(["ok"=>false,"msg"=>"Data tambah karyawan belum lengkap"]);
        exit;
    }

    if(!isset($jabatanOptions[$jabatan])){
        echo json_encode(["ok"=>false,"msg"=>"Jabatan tidak valid"]);
        exit;
    }

    if($jabatan === 'CREW LEADER'){
        mysqli_query($conn, "UPDATE karyawan SET jabatan='CREW', updated_at=NOW() WHERE outlet_id='$outlet_id' AND jabatan='CREW LEADER'");
    }

    $ok = mysqli_query($conn, "
        INSERT INTO karyawan (terminal_id, outlet_id, nama_lengkap, jabatan, keterangan, is_resign, created_at, updated_at)
        VALUES ('$terminal_id', '$outlet_id', '$namaEsc', '$jabEsc', '$ketEsc', 0, NOW(), NOW())
    ");

    if(!$ok){
        echo json_encode(["ok"=>false,"msg"=>"Gagal tambah karyawan: ".mysqli_error($conn)]);
        exit;
    }

    echo json_encode(["ok"=>true,"msg"=>"Karyawan berhasil ditambahkan"]);
    exit;
}

/* ================= AJAX SET / TAMBAH CREW LEADER ================= */
if(isset($_POST['ajax_set_crew_leader'])){
    header("Content-Type: application/json");

    $target_outlet_id = mysqli_real_escape_string($conn, $_POST['outlet_id'] ?? '');
    $karyawan_id = mysqli_real_escape_string($conn, $_POST['karyawan_id'] ?? '');

    if($target_outlet_id==='' || $karyawan_id===''){
        echo json_encode(["ok"=>false,"msg"=>"Data crew leader tidak lengkap"]);
        exit;
    }

    $cekKaryawan = mysqli_query($conn, "
        SELECT *
        FROM karyawan
        WHERE id='$karyawan_id' AND IFNULL(is_resign,0)=0
              AND IFNULL(is_mutasi_divisi,0)=0
        LIMIT 1
    ");
    $row = mysqli_fetch_assoc($cekKaryawan);
    if(!$row){
        echo json_encode(["ok"=>false,"msg"=>"Karyawan tidak ditemukan / sudah resign"]);
        exit;
    }

    $cekTargetOutlet = mysqli_query($conn, "SELECT id, terminal_id FROM outlet WHERE id='$target_outlet_id' LIMIT 1");
    $targetOutlet = mysqli_fetch_assoc($cekTargetOutlet);
    if(!$targetOutlet){
        echo json_encode(["ok"=>false,"msg"=>"Outlet tujuan tidak ditemukan"]);
        exit;
    }

    $target_terminal_id = mysqli_real_escape_string($conn, $targetOutlet['terminal_id']);
    $namaEsc = mysqli_real_escape_string($conn, $row['nama_lengkap'] ?? '');
    $statusEsc = mysqli_real_escape_string($conn, $row['status_surat'] ?? '');
    $ketEsc = mysqli_real_escape_string($conn, $row['keterangan'] ?? '');
    $namaPtEsc = mysqli_real_escape_string($conn, $row['nama_pt'] ?? '');
    $terminalPasEsc = mysqli_real_escape_string($conn, $row['terminal_pas'] ?? '');
    $fotoPasEsc = mysqli_real_escape_string($conn, $row['foto_pas'] ?? '');

    $masaPasSql = !empty($row['masa_berlaku_pas']) ? "'".mysqli_real_escape_string($conn, $row['masa_berlaku_pas'])."'" : "NULL";
    $berakhirKontrakSql = !empty($row['berakhir_kontrak']) ? "'".mysqli_real_escape_string($conn, $row['berakhir_kontrak'])."'" : "NULL";

    mysqli_begin_transaction($conn);
    try{
        /*
          LOGIKA BARU:
          - Data asal TIDAK di-update dan TIDAK dipindah outlet.
          - Sistem membuat baris baru/copy di outlet tujuan sebagai CREW LEADER.
          - Jadi kalau nama tersebut leader/crew di toko lain, data toko asal tetap stay.
        */

        // Hindari dobel nama leader yang sama di outlet tujuan.
        $cekDuplikat = mysqli_query($conn, "
            SELECT id
            FROM karyawan
            WHERE outlet_id='$target_outlet_id'
              AND TRIM(LOWER(nama_lengkap)) = TRIM(LOWER('$namaEsc'))
              AND jabatan='CREW LEADER'
              AND IFNULL(is_resign,0)=0
              AND IFNULL(is_mutasi_divisi,0)=0
            LIMIT 1
        ");
        if($cekDuplikat && mysqli_num_rows($cekDuplikat) > 0){
            throw new Exception("Nama ini sudah menjadi Crew Leader di outlet tujuan");
        }

        $ok = mysqli_query($conn, "
            INSERT INTO karyawan
            (terminal_id, outlet_id, nama_lengkap, jabatan, status_surat, keterangan,
             nama_pt, terminal_pas, masa_berlaku_pas, foto_pas, is_resign, resign_at,
             berakhir_kontrak, created_at, updated_at)
            VALUES
            ('$target_terminal_id', '$target_outlet_id', '$namaEsc', 'CREW LEADER', '$statusEsc', '$ketEsc',
             '$namaPtEsc', '$terminalPasEsc', $masaPasSql, '$fotoPasEsc', 0, NULL,
             $berakhirKontrakSql, NOW(), NOW())
        ");
        if(!$ok) throw new Exception(mysqli_error($conn));

        mysqli_commit($conn);
        echo json_encode(["ok"=>true,"msg"=>"Crew Leader berhasil ditambahkan tanpa mengubah data toko asal","nama"=>$row['nama_lengkap']]);
    }catch(Exception $e){
        mysqli_rollback($conn);
        echo json_encode(["ok"=>false,"msg"=>"Gagal tambah Crew Leader: ".$e->getMessage()]);
    }
    exit;
}

/* ================= AJAX DELETE CREW LEADER PERMANEN - SUPERVISOR KE ATAS ================= */
if(isset($_POST['ajax_delete_crew_leader'])){
    header("Content-Type: application/json");

    if(!$canDelete){
        echo json_encode(["ok"=>false,"msg"=>"Unauthorized. Delete hanya untuk Supervisor ke atas, dan Leader tidak memiliki akses delete."]);
        exit;
    }

    $karyawan_id = mysqli_real_escape_string($conn, $_POST['karyawan_id'] ?? '');
    if($karyawan_id === ''){
        echo json_encode(["ok"=>false,"msg"=>"ID Crew Leader kosong"]);
        exit;
    }

    $cek = mysqli_query($conn, "
        SELECT id, nama_lengkap, jabatan
        FROM karyawan
        WHERE id='$karyawan_id'
        LIMIT 1
    ");
    $row = mysqli_fetch_assoc($cek);

    if(!$row){
        echo json_encode(["ok"=>false,"msg"=>"Crew Leader tidak ditemukan"]);
        exit;
    }

    if($row['jabatan'] !== 'CREW LEADER'){
        echo json_encode(["ok"=>false,"msg"=>"Data ini bukan Crew Leader"]);
        exit;
    }

    mysqli_begin_transaction($conn);
    try{
        mysqli_query($conn, "DELETE FROM karyawan_dokumen WHERE karyawan_id='$karyawan_id'");
        mysqli_query($conn, "DELETE FROM history_mutasi WHERE karyawan_id='$karyawan_id'");

        $ok = mysqli_query($conn, "
            DELETE FROM karyawan
            WHERE id='$karyawan_id'
              AND jabatan='CREW LEADER'
        ");

        if(!$ok) throw new Exception(mysqli_error($conn));

        mysqli_commit($conn);
        echo json_encode(["ok"=>true,"msg"=>"Crew Leader berhasil dihapus permanen"]);
    }catch(Exception $e){
        mysqli_rollback($conn);
        echo json_encode(["ok"=>false,"msg"=>"Gagal delete Crew Leader: ".$e->getMessage()]);
    }
    exit;
}

/* ================= AJAX DELETE KARYAWAN PERMANEN - SUPERVISOR KE ATAS ================= */
if(isset($_POST['ajax_delete_karyawan'])){
    header("Content-Type: application/json");

    if(!$canDelete){
        echo json_encode(["ok"=>false,"msg"=>"Unauthorized. Delete hanya untuk Supervisor ke atas, dan Leader tidak memiliki akses delete."]);
        exit;
    }

    $id = mysqli_real_escape_string($conn, $_POST['id'] ?? '');

    if($id === ''){
        echo json_encode(["ok"=>false,"msg"=>"ID karyawan kosong"]);
        exit;
    }

    $cek = mysqli_query($conn, "SELECT id, jabatan FROM karyawan WHERE id='$id' LIMIT 1");
    $row = mysqli_fetch_assoc($cek);
    if(!$row){
        echo json_encode(["ok"=>false,"msg"=>"Karyawan tidak ditemukan"]);
        exit;
    }

    if($row['jabatan'] === 'CREW LEADER'){
        echo json_encode(["ok"=>false,"msg"=>"Crew Leader tidak bisa dihapus dari tombol crew. Gunakan Delete Crew Leader untuk jadikan VACANT."]);
        exit;
    }

    mysqli_begin_transaction($conn);
    try{
        mysqli_query($conn, "DELETE FROM karyawan_dokumen WHERE karyawan_id='$id'");
        mysqli_query($conn, "DELETE FROM history_mutasi WHERE karyawan_id='$id'");

        $ok = mysqli_query($conn, "
            DELETE FROM karyawan
            WHERE id='$id'
              AND jabatan <> 'CREW LEADER'
        ");

        if(!$ok) throw new Exception(mysqli_error($conn));

        mysqli_commit($conn);
        echo json_encode(["ok"=>true,"msg"=>"Data karyawan berhasil dihapus permanen"]);
    }catch(Exception $e){
        mysqli_rollback($conn);
        echo json_encode(["ok"=>false,"msg"=>"Gagal delete permanen: ".$e->getMessage()]);
    }
    exit;
}

/* ================= AJAX SAVE STATUS RESIGN DARI POPUP ================= */
if(isset($_POST['ajax_save_resign_status'])){
    header("Content-Type: application/json");

    $id = mysqli_real_escape_string($conn, $_POST['id'] ?? '');
    $tanggal_resign = mysqli_real_escape_string($conn, $_POST['tanggal_resign'] ?? '');

    if($id === '' || $tanggal_resign === ''){
        echo json_encode(["ok"=>false,"msg"=>"ID dan tanggal resign wajib diisi"]);
        exit;
    }

    $cek = mysqli_query($conn, "
        SELECT id, jabatan
        FROM karyawan
        WHERE id='$id'
        LIMIT 1
    ");
    $row = mysqli_fetch_assoc($cek);

    if(!$row){
        echo json_encode(["ok"=>false,"msg"=>"Karyawan tidak ditemukan"]);
        exit;
    }

    if($row['jabatan'] === 'CREW LEADER'){
        echo json_encode(["ok"=>false,"msg"=>"Crew Leader tidak bisa di-resign dari status ini. Hapus/ganti Crew Leader dulu."]);
        exit;
    }

    $ok = mysqli_query($conn, "
        UPDATE karyawan
        SET status_surat='RESIGN',
            resign_at='$tanggal_resign',
            updated_at=NOW()
        WHERE id='$id'
          AND jabatan <> 'CREW LEADER'
    ");

    if(!$ok){
        echo json_encode(["ok"=>false,"msg"=>"Gagal simpan status resign: ".mysqli_error($conn)]);
        exit;
    }

    echo json_encode(["ok"=>true,"msg"=>"Status RESIGN tersimpan. Data akan pindah ke Karyawan Resign setelah 2 hari dari tanggal resign."]);
    exit;
}


/* ================= AJAX DELETE PERMANEN KARYAWAN RESIGN - SUPERVISOR KE ATAS ================= */
if(isset($_POST['ajax_delete_resign_permanent'])){
    header("Content-Type: application/json");

    if(!$canDelete){
        echo json_encode(["ok"=>false,"msg"=>"Unauthorized. Delete hanya untuk Supervisor ke atas, dan Leader tidak memiliki akses delete."]);
        exit;
    }

    $id = mysqli_real_escape_string($conn, $_POST['id'] ?? '');

    if($id === ''){
        echo json_encode(["ok"=>false,"msg"=>"ID karyawan kosong"]);
        exit;
    }

    mysqli_begin_transaction($conn);
    try{
        mysqli_query($conn, "DELETE FROM karyawan_dokumen WHERE karyawan_id='$id'");
        mysqli_query($conn, "DELETE FROM history_mutasi WHERE karyawan_id='$id'");

        $ok = mysqli_query($conn, "
            DELETE FROM karyawan
            WHERE id='$id'
              AND IFNULL(is_resign,0)=1
              AND jabatan <> 'CREW LEADER'
        ");

        if(!$ok) throw new Exception(mysqli_error($conn));

        mysqli_commit($conn);
        echo json_encode(["ok"=>true,"msg"=>"Data karyawan resign berhasil dihapus permanen"]);
    }catch(Exception $e){
        mysqli_rollback($conn);
        echo json_encode(["ok"=>false,"msg"=>"Gagal delete permanen: ".$e->getMessage()]);
    }
    exit;
}

/* ================= AJAX UPDATE INLINE TANGGAL RESIGN ================= */
if(isset($_POST['ajax_update_tanggal_resign'])){
    header("Content-Type: application/json");

    $id = mysqli_real_escape_string($conn, $_POST['id'] ?? '');
    $tanggal_resign = mysqli_real_escape_string($conn, $_POST['tanggal_resign'] ?? '');

    if($id === ''){
        echo json_encode(["ok"=>false,"msg"=>"ID karyawan kosong"]);
        exit;
    }

    $tanggalSql = ($tanggal_resign !== '') ? "'$tanggal_resign'" : "NULL";

    $ok = mysqli_query($conn, "
        UPDATE karyawan
        SET resign_at=$tanggalSql,
            updated_at=NOW()
        WHERE id='$id'
          AND IFNULL(is_resign,0)=1
    ");

    if(!$ok){
        echo json_encode(["ok"=>false,"msg"=>"Gagal update tanggal resign: ".mysqli_error($conn)]);
        exit;
    }

    echo json_encode(["ok"=>true,"msg"=>"Tanggal resign tersimpan"]);
    exit;
}


/* ================= AJAX UPDATE BERAKHIR KONTRAK ================= */
if(isset($_POST['ajax_update_berakhir_kontrak'])){
    header("Content-Type: application/json");

    $id = mysqli_real_escape_string($conn, $_POST['id'] ?? '');
    $berakhir_kontrak = mysqli_real_escape_string($conn, $_POST['berakhir_kontrak'] ?? '');

    if($id === ''){
        echo json_encode(["ok"=>false,"msg"=>"ID karyawan kosong"]);
        exit;
    }

    $kontrakSql = ($berakhir_kontrak !== '') ? "'$berakhir_kontrak'" : "NULL";

    $ok = mysqli_query($conn, "
        UPDATE karyawan
        SET berakhir_kontrak=$kontrakSql,
            updated_at=NOW()
        WHERE id='$id'
          AND IFNULL(is_resign,0)=1
    ");

    if(!$ok){
        echo json_encode(["ok"=>false,"msg"=>"Gagal update berakhir kontrak: ".mysqli_error($conn)]);
        exit;
    }

    echo json_encode(["ok"=>true,"msg"=>"Berakhir kontrak tersimpan"]);
    exit;
}

/* ================= AJAX EDIT ================= */
if(isset($_POST['ajax_edit'])) {
    header("Content-Type: application/json");

    $id         = mysqli_real_escape_string($conn, $_POST['id'] ?? '');
    $nama       = mysqli_real_escape_string($conn, $_POST['nama'] ?? '');
    $jabatan    = mysqli_real_escape_string($conn, $_POST['jabatan'] ?? '');
    $status     = mysqli_real_escape_string($conn, $_POST['status_surat'] ?? '');
    $tglResign  = mysqli_real_escape_string($conn, $_POST['tanggal_resign'] ?? '');
    $tglMasukPt = mysqli_real_escape_string($conn, $_POST['tanggal_masuk_pt'] ?? '');
    $keterangan = mysqli_real_escape_string($conn, $_POST['keterangan'] ?? '');

    if($id=='' || $nama=='' || $jabatan==''){
        echo json_encode(["ok"=>false,"msg"=>"Data tidak lengkap"]);
        exit;
    }

    if($status === 'RESIGN' && $tglResign === ''){
        echo json_encode(["ok"=>false,"msg"=>"Tanggal resign wajib diisi jika status RESIGN"]);
        exit;
    }

    if($status !== 'RESIGN'){
        $tglResign = '';
    }

    $tglResignSql = ($tglResign !== '') ? "'$tglResign'" : "NULL";
    $tglMasukPtSql = ($tglMasukPt !== '') ? "'$tglMasukPt'" : "NULL";

    $ok = mysqli_query($conn, "
        UPDATE karyawan
        SET nama_lengkap='$nama',
            jabatan='$jabatan',
            tanggal_masuk_pt=$tglMasukPtSql,
            status_surat='$status',
            resign_at=$tglResignSql,
            keterangan='$keterangan',
            updated_at=NOW()
        WHERE id='$id'
    ");

    if(!$ok){
        echo json_encode(["ok"=>false,"msg"=>"Gagal update: ".mysqli_error($conn)]);
        exit;
    }

    echo json_encode(["ok"=>true,"msg"=>"Berhasil update"]);
    exit;
}

/* ================= AJAX MUTASI ================= */
if(isset($_POST['ajax_mutasi'])) {
    header("Content-Type: application/json");

    $id            = mysqli_real_escape_string($conn, $_POST['karyawan_id'] ?? '');
    $terminal_baru = mysqli_real_escape_string($conn, $_POST['terminal_baru'] ?? '');
    $outlet_baru   = mysqli_real_escape_string($conn, $_POST['outlet_baru'] ?? '');
    $user          = $_SESSION['username'] ?? 'SYSTEM';

    if($id=='' || $terminal_baru=='' || $outlet_baru==''){
        echo json_encode(["ok"=>false,"msg"=>"Data mutasi tidak lengkap"]);
        exit;
    }

    $lamaQ = mysqli_query($conn, "
        SELECT t.nama_terminal, o.nama_outlet
        FROM karyawan k
        JOIN terminal t ON k.terminal_id=t.id
        JOIN outlet o ON k.outlet_id=o.id
        WHERE k.id='$id'
        LIMIT 1
    ");
    $lama = mysqli_fetch_assoc($lamaQ);

    $okUpdate = mysqli_query($conn, "
        UPDATE karyawan
        SET terminal_id='$terminal_baru',
            outlet_id='$outlet_baru',
            updated_at=NOW()
        WHERE id='$id'
          AND IFNULL(is_resign,0)=0
              AND IFNULL(is_mutasi_divisi,0)=0
    ");

    if(!$okUpdate){
        echo json_encode(["ok"=>false,"msg"=>"Gagal mutasi (update DB): ".mysqli_error($conn)]);
        exit;
    }

    mysqli_query($conn, "
        INSERT INTO history_mutasi
        (karyawan_id, terminal_lama, outlet_lama, terminal_baru, outlet_baru, user)
        VALUES
        ('$id',
        '".mysqli_real_escape_string($conn, $lama['nama_terminal'] ?? '')."',
        '".mysqli_real_escape_string($conn, $lama['nama_outlet'] ?? '')."',
        (SELECT nama_terminal FROM terminal WHERE id='$terminal_baru'),
        (SELECT nama_outlet FROM outlet WHERE id='$outlet_baru'),
        '".mysqli_real_escape_string($conn, $user)."')
    ");

    echo json_encode(["ok"=>true,"msg"=>"Berhasil mutasi"]);
    exit;
}


/* ================= AJAX MUTASI DIVISI ================= */
if(isset($_POST['ajax_mutasi_divisi'])) {
    header("Content-Type: application/json");

    $id = mysqli_real_escape_string($conn, $_POST['karyawan_id'] ?? '');
    $divisi_baru = trim((string)($_POST['divisi_baru'] ?? ''));
    $user = $_SESSION['username'] ?? 'SYSTEM';

    if($id === '' || $divisi_baru === ''){
        echo json_encode(["ok"=>false,"msg"=>"Data mutasi divisi tidak lengkap"]);
        exit;
    }

    if(!in_array($divisi_baru, $divisiOptions, true)){
        echo json_encode(["ok"=>false,"msg"=>"Divisi tidak valid"]);
        exit;
    }

    $divisiEsc = mysqli_real_escape_string($conn, $divisi_baru);

    $qOld = mysqli_query($conn, "
        SELECT k.id, k.nama_lengkap, k.jabatan,
               IFNULL(t.nama_terminal,'') AS nama_terminal,
               IFNULL(o.nama_outlet,'') AS nama_outlet
        FROM karyawan k
        LEFT JOIN terminal t ON k.terminal_id=t.id
        LEFT JOIN outlet o ON k.outlet_id=o.id
        WHERE k.id='$id'
          AND IFNULL(k.is_resign,0)=0
          AND IFNULL(k.is_mutasi_divisi,0)=0
        LIMIT 1
    ");
    $old = mysqli_fetch_assoc($qOld);

    if(!$old){
        echo json_encode(["ok"=>false,"msg"=>"Karyawan tidak ditemukan / sudah resign / sudah mutasi divisi"]);
        exit;
    }

    $ok = mysqli_query($conn, "
        UPDATE karyawan
        SET is_mutasi_divisi=1,
            mutasi_divisi_at=NOW(),
            divisi_baru='$divisiEsc',
            updated_at=NOW()
        WHERE id='$id'
          AND IFNULL(is_resign,0)=0
          AND IFNULL(is_mutasi_divisi,0)=0
    ");

    if(!$ok){
        echo json_encode(["ok"=>false,"msg"=>"Gagal mutasi divisi: ".mysqli_error($conn)]);
        exit;
    }

    mysqli_query($conn, "
        INSERT INTO mutasi_divisi
        (karyawan_id, nama_lengkap, jabatan, terminal_lama, outlet_lama, divisi_baru, created_by)
        VALUES
        ('$id',
         '".mysqli_real_escape_string($conn, $old['nama_lengkap'] ?? '')."',
         '".mysqli_real_escape_string($conn, $old['jabatan'] ?? '')."',
         '".mysqli_real_escape_string($conn, $old['nama_terminal'] ?? '')."',
         '".mysqli_real_escape_string($conn, $old['nama_outlet'] ?? '')."',
         '$divisiEsc',
         '".mysqli_real_escape_string($conn, $user)."')
    ");

    echo json_encode(["ok"=>true,"msg"=>"Berhasil mutasi divisi. Nama hilang dari toko lama dan masuk ke tabel Mutasi Divisi."]);
    exit;
}

/* ================= AJAX GET PAS BANDARA ================= */
if(isset($_GET['get_pas_karyawan'])){
    $id = mysqli_real_escape_string($conn, $_GET['id'] ?? '');

    $q = mysqli_query($conn, "
        SELECT id,
               IFNULL(nama_pt,'') AS nama_pt,
               IFNULL(terminal_pas,'') AS terminal_pas,
               IFNULL(DATE_FORMAT(masa_berlaku_pas,'%Y-%m-%d'),'') AS masa_berlaku_pas,
               IFNULL(foto_pas,'') AS foto_pas
        FROM karyawan
        WHERE id='$id'
        LIMIT 1
    ");
    $d = mysqli_fetch_assoc($q);

    header("Content-Type: application/json");
    echo json_encode(["ok" => $d ? true : false, "data" => $d ?: null]);
    exit;
}

/* ================= AJAX SAVE PAS BANDARA ================= */
if(isset($_POST['save_pas_karyawan'])){
    header("Content-Type: application/json");

    if(!$isWira){
        echo json_encode(["ok"=>false,"msg"=>"Unauthorized"]);
        exit;
    }

    $id       = mysqli_real_escape_string($conn, $_POST['id'] ?? '');
    $nama_pt  = mysqli_real_escape_string($conn, $_POST['nama_pt'] ?? '');
    $terminal = mysqli_real_escape_string($conn, $_POST['terminal_pas'] ?? '');
    $masa     = mysqli_real_escape_string($conn, $_POST['masa_berlaku_pas'] ?? '');

    if($id==''){
        echo json_encode(["ok"=>false,"msg"=>"ID kosong"]);
        exit;
    }

    $masaSql = ($masa !== '') ? "'$masa'" : "NULL";

    $ok = mysqli_query($conn, "
        UPDATE karyawan
        SET nama_pt='$nama_pt',
            terminal_pas='$terminal',
            masa_berlaku_pas=$masaSql,
            updated_at=NOW()
        WHERE id='$id'
    ");

    if(!$ok){
        echo json_encode(["ok"=>false,"msg"=>"DB error: ".mysqli_error($conn)]);
        exit;
    }

    echo json_encode(["ok"=>true]);
    exit;
}

/* ================= AJAX UPLOAD FOTO PAS BANDARA ================= */
if(isset($_POST['upload_foto_pas'])){
    header("Content-Type: application/json");

    if(!$isWira){
        echo json_encode(["ok"=>false,"msg"=>"Unauthorized"]);
        exit;
    }

    $id = mysqli_real_escape_string($conn, $_POST['id'] ?? '');
    if($id==''){
        echo json_encode(["ok"=>false,"msg"=>"ID kosong"]);
        exit;
    }

    $uploadDir = __DIR__ . "/uploads/foto_pas/";
    if(!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    if(!isset($_FILES['foto']) || $_FILES['foto']['error'] != 0){
        echo json_encode(["ok"=>false,"msg"=>"File tidak valid"]);
        exit;
    }

    $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','webp'];
    if(!in_array($ext, $allowed)){
        echo json_encode(["ok"=>false,"msg"=>"Format harus jpg/jpeg/png/webp"]);
        exit;
    }

    $newName = "pas_" . $id . "_" . time() . "." . $ext;
    $targetPath = $uploadDir . $newName;

    if(!move_uploaded_file($_FILES['foto']['tmp_name'], $targetPath)){
        echo json_encode(["ok"=>false,"msg"=>"Gagal upload"]);
        exit;
    }

    $dbPath = "uploads/foto_pas/" . $newName;
    $ok = mysqli_query($conn, "UPDATE karyawan SET foto_pas='$dbPath', updated_at=NOW() WHERE id='$id'");

    if(!$ok){
        echo json_encode(["ok"=>false,"msg"=>"DB error: ".mysqli_error($conn)]);
        exit;
    }

    echo json_encode(["ok"=>true,"foto"=>$dbPath]);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>

<style>
.col_nama{white-space:nowrap;min-width:260px;}
.col_jabatan{width:120px;white-space:nowrap;}
</style>

<meta charset="UTF-8">
<title>Data Karyawan</title>
<link rel="icon" type="image/png" href="img/srt2.png" />
<style>
body{font-family:'Segoe UI',sans-serif;background:#f4f6f9;margin:20px;color:#263238;}
h1{text-align:center;margin-bottom:30px;}
.section-terminal{margin-bottom:40px;background:#fff;padding:20px;border-radius:14px;box-shadow:0 3px 12px rgba(0,0,0,0.06);}
.section-terminal h2{border-left:5px solid #009688;padding-left:10px;}
.outlet-box{margin-top:25px;padding:16px;background:#fafafa;border-radius:12px;border:1px solid #eee;}
.outlet-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px;}
.outlet-title{font-weight:700;color:#009688;font-size:17px;}
.outlet-actions{display:flex;align-items:center;gap:8px;}
.btn-plus{width:34px;height:34px;border:none;border-radius:50%;background:linear-gradient(135deg,#00a884,#00796b);color:#fff;font-size:22px;line-height:34px;padding:0;cursor:pointer;box-shadow:0 4px 10px rgba(0,0,0,.15);}
.btn-plus:hover{transform:translateY(-1px);}
table{
  width:100%;
  border-collapse:collapse;
  background:#fff;
  border-radius:10px;
  overflow:visible;
  border:2px solid #263238;
}
th{
  background:#263238;
  color:#fff;
  padding:10px 8px;
  font-size:13px;
  text-align:center;
  border:2px solid #37474f;
}
td{
  padding:9px 8px;
  border:2px solid #cfd8dc;
  vertical-align:middle;
  font-size:13px;
  text-align:left;
}
button{padding:6px 10px;border:none;border-radius:7px;background:#1565c0;color:#fff;cursor:pointer;font-size:12px;margin-right:4px;}
button:hover{opacity:.95;}
textarea,input,select{font-family:inherit;box-sizing:border-box;}
.top-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:25px;flex-wrap:wrap;gap:10px;}
.btn-back{text-decoration:none;background:linear-gradient(135deg,#009688,#00695c);color:white;padding:10px 18px;border-radius:30px;font-weight:600;font-size:14px;box-shadow:0 4px 10px rgba(0,0,0,0.15);transition:0.3s;}
.btn-back:hover{transform:translateY(-2px);}
.btn-export{text-decoration:none;background:linear-gradient(135deg,#1565c0,#0d47a1);color:white;padding:10px 18px;border-radius:30px;font-weight:600;font-size:14px;box-shadow:0 4px 10px rgba(0,0,0,0.15);transition:0.3s;}
.btn-export:hover{transform:translateY(-2px);}
.crew-leader-box{margin-top:12px;padding:12px 14px;background:#eef5ff;border:1px solid #d7e6ff;border-radius:10px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;}
.crew-leader{font-weight:700;color:#1565c0;font-size:14px;}
.crew-leader-name.vacant{color:#d32f2f;font-style:italic;}
.crew-action-wrap{display:flex;gap:8px;flex-wrap:wrap;}
.crew-leader-list{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;}
.leader-chip{display:inline-flex;align-items:center;gap:6px;background:#eaf3ff;border:1px solid #cfe2ff;color:#1565c0;border-radius:999px;padding:6px 8px;font-weight:700;}
.leader-chip .btn-delete-leader{padding:3px 7px;border-radius:999px;margin:0;font-size:11px;}
.resign-box{margin-top:12px;padding:12px 14px;background:#fff5f5;border:1px solid #ffcdd2;border-radius:10px;}
.resign-title{font-weight:800;color:#c62828;margin-bottom:10px;}
.mutasi-divisi-card{border:1px solid #bbdefb;background:#f4f9ff;}
.mutasi-divisi-card h2{border-left-color:#1565c0 !important;color:#1565c0 !important;}
.mutasi-divisi-card th{background:#1565c0 !important;border-color:#0d47a1 !important;}
.mutasi-divisi-card td{border-color:#bbdefb;}
.badge-divisi{display:inline-block;background:#e3f2fd;color:#0d47a1;border:1px solid #90caf9;border-radius:999px;padding:4px 9px;font-weight:800;font-size:12px;}
.btn-divisi{background:#1976d2 !important;}
.btn-divisi:hover{background:#0d47a1 !important;}
.resign-box th{background:#b71c1c;}
.resign-global-card{border:1px solid #ffcdd2;background:#fffafa;}
.resign-global-card th{background:#b71c1c;}
.inline-tanggal-resign:focus{outline:none;border-color:#c62828;box-shadow:0 0 0 3px rgba(198,40,40,.12);}
.save-resign-info{display:block;margin-top:4px;color:#607d8b;font-size:11px;min-height:14px;}
.inline-berakhir-kontrak:focus{outline:none;border-color:#1565c0;box-shadow:0 0 0 3px rgba(21,101,192,.12);}
.save-kontrak-info{display:block;margin-top:4px;color:#607d8b;font-size:11px;min-height:14px;}
.btn-secondary{background:#455a64;}
.btn-danger{background:#d32f2f;}
.btn-doc{background:#009688;}

/* ===== MENU AKSI TITIK 3 ===== */
.col-aksi{width:70px;min-width:70px;text-align:center;position:relative;}
.action-menu-wrap{position:relative;display:inline-block;}
.btn-action-toggle{
  width:36px;
  height:34px;
  padding:0;
  margin:0;
  border-radius:9px;
  background:#455a64;
  color:#fff;
  font-size:22px;
  line-height:28px;
  font-weight:700;
  letter-spacing:2px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  box-shadow:0 2px 8px rgba(0,0,0,.12);
}
.btn-action-toggle:hover{background:#263238;opacity:1;}
.action-dropdown{
  display:none;
  position:absolute;
  top:calc(100% + 7px);
  right:0;
  min-width:180px;
  padding:6px;
  background:#fff;
  border:1px solid #dfe5ea;
  border-radius:10px;
  box-shadow:0 10px 28px rgba(0,0,0,.18);
  z-index:9999;
  text-align:left;
}
.action-dropdown.show{display:block;}
.action-dropdown button{
  display:block;
  width:100%;
  margin:0;
  padding:9px 11px;
  border-radius:7px;
  background:transparent !important;
  color:#263238;
  text-align:left;
  font-size:12px;
  font-weight:600;
  white-space:nowrap;
}
.action-dropdown button:hover{
  background:#f2f5f7 !important;
  opacity:1;
}
.action-dropdown .btn-edit{color:#1565c0;}
.action-dropdown .btn-mutasi{color:#1565c0;}
.action-dropdown .btn-mutasi-divisi{color:#1976d2;}
.action-dropdown .btn-upload{color:#00897b;}
.action-dropdown .btn-delete-karyawan{color:#d32f2f;}
.action-dropdown::before{
  content:'';
  position:absolute;
  top:-5px;
  right:12px;
  width:10px;
  height:10px;
  background:#fff;
  border-left:1px solid #dfe5ea;
  border-top:1px solid #dfe5ea;
  transform:rotate(45deg);
}
.status-select{width:100%;padding:6px;border-radius:6px;border:1px solid #ddd;}
.link-lihat,.link-pas{color:#1565c0;cursor:pointer;text-decoration:underline;font-weight:600;}
.doc-img{width:100%;max-height:70vh;object-fit:contain;border:1px solid #eee;border-radius:10px;}
.kmodal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999999;}
.kmodal-content{background:white;width:420px;margin:6% auto;padding:20px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.2);}
.kclose{float:right;cursor:pointer;color:red;font-weight:bold;font-size:18px;}
#kpopupPas{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999999;align-items:center;justify-content:center;padding:20px;}
#kpopupPas .kmodal-content{margin:0 !important;max-height:90vh;overflow:auto;}
.drawer{display:none;position:fixed;top:0;right:0;width:420px;max-width:95vw;height:100vh;background:#fff;z-index:9999999;box-shadow:-10px 0 30px rgba(0,0,0,.18);overflow:auto;}
.drawer-header{padding:18px 18px 12px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:2;}
.drawer-body{padding:18px;}
.drawer-search{width:100%;padding:10px 12px;border:1px solid #dcdcdc;border-radius:10px;margin-bottom:12px;}
.list-karyawan{display:flex;flex-direction:column;gap:8px;max-height:58vh;overflow:auto;padding-right:4px;}
.item-karyawan{border:1px solid #e6e6e6;border-radius:10px;padding:10px 12px;cursor:pointer;transition:.2s;background:#fff;}
.item-karyawan:hover{border-color:#1565c0;background:#f7fbff;}
.item-karyawan.active{border-color:#1565c0;background:#eaf3ff;box-shadow:0 0 0 2px rgba(21,101,192,.08);}
.item-karyawan small{display:block;color:#607d8b;margin-top:4px;}
.drawer-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:9999998;}
.form-row{margin-bottom:12px;}
.form-row label{display:block;font-weight:600;margin-bottom:6px;}
.text-muted{color:#607d8b;font-size:12px;}
.empty-state{padding:14px;border:1px dashed #cfd8dc;border-radius:10px;text-align:center;color:#607d8b;background:#fafafa;}
.search-panel{position:sticky;top:10px;z-index:50;background:rgba(255,255,255,.96);border:1px solid #e0e7ef;box-shadow:0 8px 24px rgba(0,0,0,.08);border-radius:16px;padding:14px;margin:0 auto 22px;max-width:900px;}
.search-panel label{display:block;font-weight:700;color:#263238;margin-bottom:8px;}
.search-box-wrap{display:flex;gap:8px;align-items:center;}
.search-box-wrap input{width:100%;padding:12px 14px;border:1px solid #cfd8dc;border-radius:12px;font-size:15px;outline:none;}
.search-box-wrap input:focus{border-color:#009688;box-shadow:0 0 0 3px rgba(0,150,136,.12);}
.btn-clear-search{background:#607d8b;border-radius:12px;padding:12px 14px;white-space:nowrap;}
.search-info{margin-top:8px;color:#607d8b;font-size:12px;}
.search-no-result{display:none;max-width:900px;margin:0 auto 18px;padding:14px;border-radius:12px;border:1px dashed #ef9a9a;background:#fff5f5;color:#c62828;text-align:center;font-weight:600;}

table input,
table select,
table textarea{
  text-align:left;
}

.col_nama,
.col_keterangan{
  text-align:left !important;
  font-weight:600;
}

/* Kolom utilitas tetap center, sedangkan isi data utama rata kiri */
td:first-child,
.col-aksi{
  text-align:center;
}

@media(max-width:768px){table{font-size:12px;display:block;overflow:auto;white-space:nowrap;}.kmodal-content{width:92%;}.drawer{width:100%;}}
</style>
</head>
<body>

<div class="top-bar">
  <a href="dashboard.php" class="btn-back">&#8592; Kembali ke Dashboard</a>
  <a href="karyawan.php?export=1" class="btn-export">Export Excel</a>
</div>

<h1>DATA KARYAWAN PER TERMINAL & OUTLET</h1>

<div class="search-panel">
  <label for="globalSearchKaryawan">Search Karyawan</label>
  <div class="search-box-wrap">
    <input type="text" id="globalSearchKaryawan" placeholder="Ketik nama / jabatan / outlet / terminal... contoh: MU">
    <button type="button" class="btn-clear-search" id="clearSearchKaryawan">Reset</button>
  </div>
  <div class="search-info" id="searchInfoKaryawan">Ketik minimal 1 huruf, data akan langsung difilter.</div>
</div>
<div class="search-no-result" id="searchNoResultKaryawan">Data tidak ditemukan.</div>

<?php
$terminal = mysqli_query($conn, "SELECT * FROM terminal ORDER BY id ASC");
while($t = mysqli_fetch_assoc($terminal)){
  echo "<div class='section-terminal'>";
  echo "<h2>".e($t['nama_terminal'])."</h2>";

  $terminalIdSafe = mysqli_real_escape_string($conn, $t['id']);
  $outlet = mysqli_query($conn, "SELECT * FROM outlet WHERE terminal_id='".$terminalIdSafe."' ORDER BY nama_outlet ASC");
  while($o = mysqli_fetch_assoc($outlet)){
    $outletIdSafe = mysqli_real_escape_string($conn, $o['id']);

    echo "<div class='outlet-box'>";

    echo "<div class='outlet-head'>
            <div class='outlet-title'>".e($o['nama_outlet'])."</div>
            <div class='outlet-actions'>
              <button type='button' class='btn-plus btn-add-karyawan'
                title='Tambah karyawan'
                data-terminal-id='".e($t['id'])."'
                data-terminal-nama='".e($t['nama_terminal'])."'
                data-outlet-id='".e($o['id'])."'
                data-outlet-nama='".e($o['nama_outlet'])."'>+</button>
            </div>
          </div>";

    echo "<table>
      <tr>
        <th width='5%'>No</th>
        <th>Nama Lengkap</th>
        <th width='18%'>Jabatan</th>
        <th width='15%'>Tanggal Masuk PT</th>
        <th width='12%'>Masa Kerja</th>
        <th width='20%'>Status</th>
        <th>Keterangan</th>
        <th width='10%'>Dokumen</th>
        <th width='7%'>Aksi</th>
      </tr>";

    $no = 1;
    $adaCrew = false;
    $karyawan = mysqli_query($conn, "
      SELECT id, nama_lengkap, jabatan, IFNULL(tanggal_masuk_pt,'') AS tanggal_masuk_pt, IFNULL(status_surat,'') AS status_surat,
             IFNULL(DATE_FORMAT(resign_at,'%Y-%m-%d'),'') AS resign_at,
             IFNULL(keterangan,'') AS keterangan
      FROM karyawan
      WHERE outlet_id='".$outletIdSafe."'
        AND jabatan!='CREW LEADER'
        AND IFNULL(is_resign,0)=0
              AND IFNULL(is_mutasi_divisi,0)=0
      ORDER BY nama_lengkap ASC
    ");

    while($k = mysqli_fetch_assoc($karyawan)){
      $adaCrew = true;
      $karyawanIdSafe = mysqli_real_escape_string($conn, $k['id']);

      $dok = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT file_path FROM karyawan_dokumen
        WHERE karyawan_id='".$karyawanIdSafe."'
        ORDER BY id DESC LIMIT 1
      "));
      $adaDok = $dok ? true : false;
      $statusHtml = e(formatStatusKaryawan($k['status_surat'], $k['resign_at'] ?? ''));

      $dokHtml = $adaDok
        ? "<span class='link-lihat' data-action='lihat-dokumen' data-id='".e($k['id'])."'>Lihat</span>"
        : "-";

      $isBlockPas = (($o['nama_outlet'] ?? '') === "M'MART T3");
      $namaCell = $isBlockPas
        ? e($k['nama_lengkap'])
        : "<span class='link-pas'
            data-id='".e($k['id'])."'
            data-nama='".e($k['nama_lengkap'])."'
            data-jabatan='".e($k['jabatan'])."'
            data-outlet='".e($o['nama_outlet'])."'
           >".e($k['nama_lengkap'])."</span>";

      echo "<tr id='row_karyawan_".e($k['id'])."'>
        <td>".$no."</td>
        <td class='col_nama'>".$namaCell."</td>
        <td class='col_jabatan'>".e($k['jabatan'])."</td>
        <td>".e($k['tanggal_masuk_pt'] ?? '')."</td>
        <td>".e(masaKerja($k['tanggal_masuk_pt'] ?? ''))."</td>
        <td>".$statusHtml."</td>
        <td class='col_keterangan'>".e($k['keterangan'])."</td>
        <td style='text-align:center;'>".$dokHtml."</td>
        <td class='col-aksi'>
          <div class='action-menu-wrap'>
            <button type='button' class='btn-action-toggle'
              aria-label='Buka menu aksi'
              title='Aksi'
              onclick='toggleActionMenu(this)'>&#8942;</button>

            <div class='action-dropdown'>
              <button type='button' class='btn-edit'
                data-id='".e($k['id'])."'
                data-nama='".e($k['nama_lengkap'])."'
                data-jabatan='".e($k['jabatan'])."'
                data-tanggal-masuk-pt='".e($k['tanggal_masuk_pt'] ?? '')."'
                data-status='".e($k['status_surat'])."'
                data-resign-at='".e($k['resign_at'] ?? '')."'
                data-keterangan='".e($k['keterangan'])."'>Edit</button>

              <button type='button' class='btn-mutasi'
                data-id='".e($k['id'])."'
                data-nama='".e($k['nama_lengkap'])."'>Mutasi</button>

              <button type='button' class='btn-mutasi-divisi'
                data-id='".e($k['id'])."'
                data-nama='".e($k['nama_lengkap'])."'>Mutasi Divisi</button>

              <button type='button' class='btn-upload'
                data-id='".e($k['id'])."'
                data-nama='".e($k['nama_lengkap'])."'>Tambah Dokumen</button>

              ".($canDelete ? "<button type='button' class='btn-delete-karyawan'
                data-id='".e($k['id'])."'
                data-nama='".e($k['nama_lengkap'])."'>Delete</button>" : "")."
            </div>
          </div>
        </td>
      </tr>";
      $no++;
    }

    if(!$adaCrew){
      echo "<tr><td colspan='9' style='text-align:center;color:#607d8b;'>Belum ada data crew di outlet ini</td></tr>";
    }

    echo "</table>";

    $leaders = [];
    $qLeaders = mysqli_query($conn, "
      SELECT id, nama_lengkap, jabatan
      FROM karyawan
      WHERE outlet_id='".$outletIdSafe."'
        AND jabatan='CREW LEADER'
        AND IFNULL(is_resign,0)=0
              AND IFNULL(is_mutasi_divisi,0)=0
      ORDER BY nama_lengkap ASC
    ");
    while($ld = mysqli_fetch_assoc($qLeaders)){
      $leaders[] = $ld;
    }

    $leaderHtml = "";
    if(empty($leaders)){
      $leaderHtml = "<span class='crew-leader-name vacant' id='crewLeaderText_".e($o['id'])."'>Vacant</span>";
    }else{
      $leaderHtml .= "<div class='crew-leader-list' id='crewLeaderText_".e($o['id'])."'>";
      foreach($leaders as $ld){
        $leaderHtml .= "<span class='leader-chip'><span class='link-pas'
              data-id='".e($ld['id'])."'
              data-nama='".e($ld['nama_lengkap'])."'
              data-jabatan='".e($ld['jabatan'] ?? 'CREW LEADER')."'
              data-outlet='".e($o['nama_outlet'])."'
              title='Klik untuk lihat data Pas Bandara'>".e($ld['nama_lengkap'])."</span>";
        if($canDelete){
          $leaderHtml .= " <button type='button' class='btn-danger btn-delete-leader'
              data-karyawan-id='".e($ld['id'])."'
              data-nama='".e($ld['nama_lengkap'])."'>Delete</button>";
        }
        $leaderHtml .= "</span>";
      }
      $leaderHtml .= "</div>";
    }

    echo "<div class='crew-leader-box' id='crewLeaderBox_".e($o['id'])."'>
            <div class='crew-leader'>
              Crew Leader : ".$leaderHtml."
            </div>
            <div class='crew-action-wrap'>
              <button type='button' class='btn-secondary btn-ganti-leader'
                data-terminal-id='".e($t['id'])."'
                data-terminal-nama='".e($t['nama_terminal'])."'
                data-outlet-id='".e($o['id'])."'
                data-outlet-nama='".e($o['nama_outlet'])."'
              >Ganti / Tambah</button>
            </div>
          </div>";

    echo "</div>";
  }

  echo "</div>";
}
?>

<?php
/* ================= CARD GLOBAL MUTASI DIVISI ================= */
$qMutasiDivisi = mysqli_query($conn, "
  SELECT id, karyawan_id, nama_lengkap, jabatan, terminal_lama, outlet_lama, divisi_baru, created_by,
         IFNULL(DATE_FORMAT(created_at,'%Y-%m-%d %H:%i'),'') AS tanggal_mutasi
  FROM mutasi_divisi
  ORDER BY created_at DESC, id DESC
");

echo "<div class='section-terminal mutasi-divisi-card' id='cardMutasiDivisi'>
        <h2>Mutasi Divisi</h2>
        <div class='text-muted' style='margin-bottom:12px;'>Data karyawan yang sudah dipindahkan ke divisi lain. Nama otomatis hilang dari toko lama.</div>
        <table>
          <tr>
            <th width='5%'>No</th>
            <th>Nama Lengkap</th>
            <th width='13%'>Jabatan</th>
            <th width='16%'>Divisi Baru</th>
            <th width='16%'>Terminal Lama</th>
            <th width='18%'>Outlet Lama</th>
            <th width='14%'>Tanggal Mutasi</th>
            <th width='10%'>User</th>
          </tr>";

if($qMutasiDivisi && mysqli_num_rows($qMutasiDivisi) > 0){
  $noMutasiDivisi = 1;
  while($m = mysqli_fetch_assoc($qMutasiDivisi)){
    echo "<tr class='row-mutasi-divisi' id='row_mutasi_divisi_".e($m['id'])."'>
            <td>".$noMutasiDivisi."</td>
            <td>".e($m['nama_lengkap'])."</td>
            <td>".e($m['jabatan'])."</td>
            <td><span class='badge-divisi'>".e($m['divisi_baru'])."</span></td>
            <td>".e($m['terminal_lama'])."</td>
            <td>".e($m['outlet_lama'])."</td>
            <td>".e($m['tanggal_mutasi'])."</td>
            <td>".e($m['created_by'])."</td>
          </tr>";
    $noMutasiDivisi++;
  }
}else{
  echo "<tr class='row-mutasi-divisi-empty'>
          <td colspan='8' style='text-align:center;padding:22px;color:#607d8b;font-weight:700;'>
            Belum ada data mutasi divisi
          </td>
        </tr>";
}

echo "</table></div>";
?>

<?php
/* ================= CARD GLOBAL KARYAWAN RESIGN PALING BAWAH ================= */
$qResignGlobal = mysqli_query($conn, "
  SELECT k.id, k.nama_lengkap, k.jabatan,
         IFNULL(DATE_FORMAT(k.resign_at,'%Y-%m-%d'),'') AS tanggal_resign,
         IFNULL(DATE_FORMAT(k.berakhir_kontrak,'%Y-%m-%d'),'') AS berakhir_kontrak,
         IFNULL(k.keterangan,'') AS keterangan,
         IFNULL(o.nama_outlet,'') AS nama_outlet,
         IFNULL(t.nama_terminal,'') AS nama_terminal
  FROM karyawan k
  LEFT JOIN outlet o ON k.outlet_id=o.id
  LEFT JOIN terminal t ON k.terminal_id=t.id
  WHERE IFNULL(k.is_resign,0)=1
    AND k.jabatan!='CREW LEADER'
  ORDER BY k.resign_at DESC, k.nama_lengkap ASC
");

echo "<div class='section-terminal resign-global-card' id='cardKaryawanResign'>
        <h2 style='border-left-color:#c62828;color:#c62828;'>Karyawan Resign</h2>
        <div class='text-muted' style='margin-bottom:12px;'>Data masuk 2 hari setelah tanggal input Resign.</div>
        <table>
          <tr>
            <th width='5%'>No</th>
            <th>Nama Lengkap</th>
            <th width='14%'>Jabatan</th>
            <th width='14%'>Tanggal Resign</th>
            <th width='14%'>Berakhir Kontrak</th>
            <th width='16%'>Terminal</th>
            <th width='18%'>Outlet</th>
            <th>Keterangan</th>";
if($canDelete){
  echo "<th width='10%'>Aksi</th>";
}
echo "</tr>";

if($qResignGlobal && mysqli_num_rows($qResignGlobal) > 0){
  $noGlobalResign = 1;
  while($r = mysqli_fetch_assoc($qResignGlobal)){
    echo "<tr class='row-resign' id='row_resign_".e($r['id'])."'>
            <td>".$noGlobalResign."</td>
            <td>".e($r['nama_lengkap'])."</td>
            <td>".e($r['jabatan'])."</td>
            <td>
              <input type='date'
                class='inline-tanggal-resign'
                data-id='".e($r['id'])."'
                value='".e($r['tanggal_resign'])."'
                style='width:100%;padding:6px;border:1px solid #ddd;border-radius:6px;'>
              <small class='save-resign-info' id='save_resign_info_".e($r['id'])."'></small>
            </td>
            <td>
              <input type='date'
                class='inline-berakhir-kontrak'
                data-id='".e($r['id'])."'
                value='".e($r['berakhir_kontrak'])."'
                style='width:100%;padding:6px;border:1px solid #ddd;border-radius:6px;'>
              <small class='save-kontrak-info' id='save_kontrak_info_".e($r['id'])."'></small>
            </td>
            <td>".e($r['nama_terminal'])."</td>
            <td>".e($r['nama_outlet'])."</td>
            <td>".e($r['keterangan'])."</td>";
    if($canDelete){
      echo "<td>
              <button type='button'
                class='btn-danger btn-delete-resign-permanent'
                data-id='".e($r['id'])."'
                data-nama='".e($r['nama_lengkap'])."'>Delete</button>
            </td>";
    }
    echo "</tr>";
    $noGlobalResign++;
  }
}else{
  $colspan = $canDelete ? 9 : 8;
  echo "<tr class='row-resign-empty'>
          <td colspan='".$colspan."' style='text-align:center;padding:22px;color:#607d8b;font-weight:700;'>
            Belum ada data (data masuk setelah 2 hari kerja)
          </td>
        </tr>";
}

echo "</table></div>";
?>


<!-- POPUP STATUS RESIGN -->
<div id="kpopupResign" class="kmodal">
  <div class="kmodal-content">
    <span class="kclose" onclick="karyawanCloseResign()">X</span>
    <h3>Status RESIGN</h3>
    <p id="resign_nama_text" style="margin-top:-6px;color:#607d8b;"></p>
    <form id="formResignStatus">
      <input type="hidden" id="resign_id">
      <div class="form-row">
        <label>Tanggal Resign</label>
        <input type="date" id="resign_tanggal" required style="width:100%;padding:8px;">
        <small class="text-muted">Setelah disimpan, data tetap tampil di lineup aktif selama 2 hari, lalu otomatis pindah ke Karyawan Resign.</small>
      </div>
      <button type="submit" class="btn-danger">Simpan RESIGN</button>
    </form>
  </div>
</div>

<!-- POPUP EDIT -->
<div id="kpopupEdit" class="kmodal">
  <div class="kmodal-content">
    <span class="kclose" onclick="karyawanCloseEdit()">X</span>
    <h3>Edit Karyawan</h3>
    <form id="formEditKaryawan">
      <input type="hidden" name="id" id="edit_id">
      <div class="form-row">
        <label>Nama</label>
        <input type="text" name="nama" id="edit_nama" required style="width:100%;padding:8px;">
      </div>
      <div class="form-row">
        <label>Jabatan</label>
        <select name="jabatan" id="edit_jabatan" style="width:100%;padding:8px;">
          <?php foreach($jabatanOptions as $v => $lbl): ?>
            <option value="<?= e($v) ?>"><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label>Tanggal Masuk PT</label>
        <input type="date" name="tanggal_masuk_pt" id="edit_tanggal_masuk_pt" style="width:100%;padding:8px;">
      </div>
      <div class="form-row">
        <label>Status</label>
        <select name="status_surat" id="edit_status_surat" style="width:100%;padding:8px;">
          <?php foreach($statusOptions as $v => $lbl): ?>
            <option value="<?= e($v) ?>"><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row" id="edit_resign_tanggal_wrap" style="display:none;">
        <label>Tanggal Resign</label>
        <input type="date" id="edit_resign_tanggal" name="tanggal_resign" style="width:100%;padding:8px;">
        <small class="text-muted">Jika status RESIGN, tanggal ini akan tampil di kolom Status.</small>
      </div>
      <div class="form-row">
        <label>Keterangan</label>
        <textarea id="edit_keterangan" name="keterangan" style="width:100%;padding:8px;min-height:80px;" placeholder="Isi keterangan..."></textarea>
      </div>
      <button type="submit">Simpan</button>
    </form>
  </div>
</div>

<!-- POPUP TAMBAH KARYAWAN -->
<div id="kpopupAdd" class="kmodal">
  <div class="kmodal-content">
    <span class="kclose" onclick="karyawanCloseAdd()">X</span>
    <h3>Tambah Karyawan</h3>
    <form id="formAddKaryawan">
      <input type="hidden" id="add_terminal_id" name="terminal_id">
      <input type="hidden" id="add_outlet_id" name="outlet_id">

      <div class="form-row">
        <label>Terminal</label>
        <input type="text" id="add_terminal_nama" readonly style="width:100%;padding:8px;background:#f5f5f5;border:1px solid #ddd;border-radius:8px;">
      </div>
      <div class="form-row">
        <label>Outlet / Toko</label>
        <input type="text" id="add_outlet_nama" readonly style="width:100%;padding:8px;background:#f5f5f5;border:1px solid #ddd;border-radius:8px;">
      </div>
      <div class="form-row">
        <label>Nama Karyawan</label>
        <input type="text" id="add_nama" name="nama" required style="width:100%;padding:8px;">
      </div>
      <div class="form-row">
        <label>Jabatan</label>
        <select id="add_jabatan" name="jabatan" style="width:100%;padding:8px;">
          <?php foreach($jabatanOptions as $v => $lbl): ?>
            <option value="<?= e($v) ?>" <?= $v === 'CREW' ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label>Keterangan</label>
        <textarea id="add_keterangan" name="keterangan" style="width:100%;padding:8px;min-height:80px;" placeholder="Opsional"></textarea>
      </div>
      <button type="submit">Simpan Karyawan</button>
    </form>
  </div>
</div>

<!-- POPUP MUTASI -->
<div id="kpopupMutasi" class="kmodal">
  <div class="kmodal-content">
    <span class="kclose" onclick="karyawanCloseMutasi()">X</span>
    <h3>Mutasi Karyawan</h3>
    <form id="formMutasiKaryawan">
      <input type="hidden" name="karyawan_id" id="karyawan_id">
      <div class="form-row">
        <label>Nama (Read Only)</label>
        <input type="text" id="mutasi_nama" readonly style="width:100%;padding:8px;background:#f5f5f5;">
      </div>
      <div class="form-row">
        <label>Pilih Terminal</label>
        <select name="terminal_baru" id="terminalSelect" required style="width:100%;padding:8px;">
          <option value="">-- Pilih Terminal --</option>
          <?php
          $t2 = mysqli_query($conn,"SELECT * FROM terminal ORDER BY id ASC");
          while($x=mysqli_fetch_assoc($t2)){
            echo "<option value='".e($x['id'])."'>".e($x['nama_terminal'])."</option>";
          }
          ?>
        </select>
      </div>
      <div class="form-row">
        <label>Pilih Outlet</label>
        <select name="outlet_baru" id="outletSelect" required style="width:100%;padding:8px;">
          <option value="">-- Pilih Outlet --</option>
        </select>
      </div>
      <button type="submit">Simpan Mutasi</button>
    </form>
  </div>
</div>

<!-- POPUP MUTASI DIVISI -->
<div id="kpopupMutasiDivisi" class="kmodal">
  <div class="kmodal-content">
    <span class="kclose" onclick="karyawanCloseMutasiDivisi()">X</span>
    <h3>Mutasi Divisi / Head Office</h3>
    <form id="formMutasiDivisi">
      <input type="hidden" name="karyawan_id" id="mutasi_divisi_id">
      <div class="form-row">
        <label>Nama (Read Only)</label>
        <input type="text" id="mutasi_divisi_nama" readonly style="width:100%;padding:8px;background:#f5f5f5;">
      </div>
      <div class="form-row">
        <label>Pilih Divisi / Tujuan Baru</label>
        <select name="divisi_baru" id="divisiSelect" required style="width:100%;padding:8px;">
          <option value="">-- Pilih Divisi / Head Office --</option>
          <?php foreach($divisiOptions as $dv): ?>
            <option value="<?= e($dv) ?>"><?= e($dv) ?></option>
          <?php endforeach; ?>
        </select>
        <small class="text-muted">Setelah disimpan, nama akan hilang dari toko lama dan masuk ke tabel Mutasi Divisi.</small>
      </div>
      <button type="submit" class="btn-divisi">Simpan Mutasi</button>
    </form>
  </div>
</div>

<!-- POPUP UPLOAD DOKUMEN -->
<div id="kpopupUpload" class="kmodal">
  <div class="kmodal-content">
    <span class="kclose" onclick="karyawanCloseUpload()">X</span>
    <h3>Tambah Dokumen</h3>
    <p id="nama_upload" style="margin-top:-6px;color:#607d8b;"></p>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="karyawan_id" id="upload_karyawan_id">
      <label>Pilih Foto Dokumen (jpg/png/webp)</label>
      <input type="file" name="dokumen" accept=".jpg,.jpeg,.png,.webp" required style="width:100%;margin:10px 0;">
      <button type="submit" name="upload_dokumen" class="btn-doc">Upload</button>
    </form>
  </div>
</div>

<!-- POPUP LIHAT DOKUMEN -->
<div id="kpopupLihat" class="kmodal">
  <div class="kmodal-content">
    <span class="kclose" onclick="karyawanCloseLihat()">X</span>
    <h3>Dokumen Karyawan</h3>
    <img id="imgDokumen" class="doc-img" src="" alt="Dokumen">
  </div>
</div>

<!-- POPUP PAS BANDARA -->
<div id="kpopupPas" class="kmodal">
  <div class="kmodal-content" style="width:760px;max-width:95%;">
    <span class="kclose" onclick="karyawanClosePas()">X</span>
    <h3>DATA PAS BANDARA</h3>

    <input type="hidden" id="pas_id">

    <div style="display:flex;gap:18px;flex-wrap:wrap;">
      <div style="flex:1;min-width:300px;">
        <b>DATA DIRI</b><br><br>

        NAMA :
        <input id="pas_nama" readonly style="width:100%;padding:8px;margin:6px 0 10px;background:#f5f5f5;border:1px solid #ddd;border-radius:6px;">

        JABATAN :
        <input id="pas_jabatan" readonly style="width:100%;padding:8px;margin:6px 0 10px;background:#f5f5f5;border:1px solid #ddd;border-radius:6px;">

        OUTLET :
        <input id="pas_outlet" readonly style="width:100%;padding:8px;margin:6px 0 10px;background:#f5f5f5;border:1px solid #ddd;border-radius:6px;">

        <b>PAS BANDARA</b><br><br>

        NAMA PT :
        <input id="pas_nama_pt" style="width:100%;padding:8px;margin:6px 0 10px;border:1px solid #ddd;border-radius:6px;">

        TERMINAL :
        <input id="pas_terminal" style="width:100%;padding:8px;margin:6px 0 10px;border:1px solid #ddd;border-radius:6px;">

        MASA BERLAKU :
        <input type="date" id="pas_masa" style="width:100%;padding:8px;margin:6px 0 0;border:1px solid #ddd;border-radius:6px;">

        <small id="pas_info" style="display:block;margin-top:8px;color:#607d8b;"></small>
      </div>

      <div style="width:230px;min-width:230px;text-align:center;">
        <div style="border:1px solid #ddd;border-radius:10px;padding:10px;">
          <img id="pas_foto" src="" style="width:100%;height:260px;object-fit:cover;border-radius:8px;">
        </div>

        <div id="pas_upload_wrap" style="margin-top:10px;">
          <input type="file" id="pas_file" accept=".jpg,.jpeg,.png,.webp" style="width:100%;">
          <button type="button" id="btnUploadPas" class="btn-doc" style="margin-top:8px;width:100%;">Choose / Edit Foto</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- DRAWER GANTI CREW LEADER -->
<div id="leaderDrawerOverlay" class="drawer-overlay"></div>
<div id="leaderDrawer" class="drawer">
  <div class="drawer-header">
    <div>
      <div style="font-size:18px;font-weight:700;">Ganti / Tambah Crew Leader</div>
      <div class="text-muted" id="leaderDrawerOutletInfo">Pilih karyawan untuk ditambahkan sebagai Crew Leader</div>
    </div>
    <button type="button" class="btn-danger" onclick="closeLeaderDrawer()">Tutup</button>
  </div>
  <div class="drawer-body">
    <input type="hidden" id="leader_outlet_id">
    <input type="hidden" id="leader_selected_id">

    <input type="text" id="leaderSearch" class="drawer-search" placeholder="Search nama / jabatan / outlet / terminal...">

    <div id="leaderList" class="list-karyawan"></div>
    <div id="leaderEmpty" class="empty-state" style="display:none;">Tidak ada nama karyawan yang bisa dipilih.</div>

    <div style="margin-top:14px;display:flex;gap:8px;justify-content:flex-end;">
      <button type="button" class="btn-secondary" onclick="closeLeaderDrawer()">Batal</button>
      <button type="button" id="btnSimpanLeader">Simpan</button>
    </div>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function(){
  const IS_WIRA = <?= $isWira ? "true" : "false" ?>;
  const CAN_DELETE = <?= $canDelete ? "true" : "false" ?>;
  const DEFAULT_AVATAR = "https://www.gravatar.com/avatar/?d=mp&s=400";

  let leaderCandidates = [];

  const searchInputKaryawan = document.getElementById('globalSearchKaryawan');
  const clearSearchKaryawan = document.getElementById('clearSearchKaryawan');
  const searchInfoKaryawan = document.getElementById('searchInfoKaryawan');
  const searchNoResultKaryawan = document.getElementById('searchNoResultKaryawan');

  function normalizeSearchText(text){
    return String(text || '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function applyKaryawanSearch(){
    const rawKeyword = searchInputKaryawan ? searchInputKaryawan.value : '';
    const keyword = normalizeSearchText(rawKeyword);
    let totalVisibleRows = 0;
    let totalVisibleOutlets = 0;

    document.querySelectorAll('.section-terminal').forEach(section => {
      let terminalHasMatch = false;
      const terminalTitle = normalizeSearchText(section.querySelector('h2')?.textContent || '');

      section.querySelectorAll('.outlet-box').forEach(outletBox => {
        const outletTitle = normalizeSearchText(outletBox.querySelector('.outlet-title')?.textContent || '');
        const leaderText = normalizeSearchText(outletBox.querySelector('.crew-leader')?.textContent || '');
        const resignText = normalizeSearchText(outletBox.querySelector('.resign-box')?.textContent || '');
        let outletHasMatch = false;

        outletBox.querySelectorAll('tr[id^="row_karyawan_"]').forEach(row => {
          const rowText = normalizeSearchText(row.textContent || '');
          const searchableText = [terminalTitle, outletTitle, leaderText, resignText, rowText].join(' ');
          const isMatch = keyword === '' || searchableText.includes(keyword);
          row.style.display = isMatch ? '' : 'none';
          if(isMatch){
            outletHasMatch = true;
            totalVisibleRows++;
          }
        });

        const outletMetaMatch = keyword !== '' && [terminalTitle, outletTitle, leaderText, resignText].join(' ').includes(keyword);
        if(keyword === '' || outletHasMatch || outletMetaMatch){
          outletBox.style.display = '';
          terminalHasMatch = true;
          totalVisibleOutlets++;
        }else{
          outletBox.style.display = 'none';
        }
      });

      section.style.display = (keyword === '' || terminalHasMatch) ? '' : 'none';
    });

    if(searchInfoKaryawan){
      searchInfoKaryawan.textContent = keyword === ''
        ? 'Ketik minimal 1 huruf, data akan langsung difilter.'
        : `Hasil pencarian "${rawKeyword}": ${totalVisibleRows} baris karyawan / ${totalVisibleOutlets} outlet.`;
    }

    const mutasiDivisiCard = document.getElementById('cardMutasiDivisi');
    let totalVisibleMutasiDivisi = 0;
    if(mutasiDivisiCard){
      let mutasiDivisiHasMatch = false;
      mutasiDivisiCard.querySelectorAll('tr.row-mutasi-divisi').forEach(row => {
        const rowText = normalizeSearchText(row.textContent || '');
        const isMatch = keyword === '' || rowText.includes(keyword);
        row.style.display = isMatch ? '' : 'none';
        if(isMatch){
          mutasiDivisiHasMatch = true;
          totalVisibleMutasiDivisi++;
        }
      });
      mutasiDivisiCard.style.display = (keyword === '' || mutasiDivisiHasMatch) ? '' : 'none';
    }

    const resignCard = document.getElementById('cardKaryawanResign');
    let totalVisibleResign = 0;
    if(resignCard){
      let resignHasMatch = false;
      resignCard.querySelectorAll('tr.row-resign').forEach(row => {
        const rowText = normalizeSearchText(row.textContent || '');
        const isMatch = keyword === '' || rowText.includes(keyword);
        row.style.display = isMatch ? '' : 'none';
        if(isMatch){
          resignHasMatch = true;
          totalVisibleResign++;
        }
      });
      resignCard.style.display = (keyword === '' || resignHasMatch) ? '' : 'none';
    }

    if(searchNoResultKaryawan){
      searchNoResultKaryawan.style.display = (keyword !== '' && totalVisibleRows === 0 && totalVisibleOutlets === 0 && totalVisibleMutasiDivisi === 0 && totalVisibleResign === 0) ? 'block' : 'none';
    }
  }

  if(searchInputKaryawan){
    searchInputKaryawan.addEventListener('input', applyKaryawanSearch);
  }
  if(clearSearchKaryawan){
    clearSearchKaryawan.addEventListener('click', function(){
      searchInputKaryawan.value = '';
      applyKaryawanSearch();
      searchInputKaryawan.focus();
    });
  }

  function openModal(id){
    const el = document.getElementById(id);
    if(el) el.style.display = 'block';
  }
  function closeModal(id){
    const el = document.getElementById(id);
    if(el) el.style.display = 'none';
  }


  window.handleStatusKaryawanChange = function(sel){
    if(!sel) return;

    if(sel.value === 'RESIGN'){
      document.getElementById('resign_id').value = sel.dataset.id || '';
      document.getElementById('resign_nama_text').textContent = 'Nama: ' + (sel.dataset.nama || '');
      document.getElementById('resign_tanggal').value = new Date().toISOString().slice(0,10);
      openModal('kpopupResign');

      // Balikkan tampilan select dulu sampai popup berhasil disimpan
      sel.value = sel.dataset.prev || '';
      return;
    }

    sel.dataset.prev = sel.value;
    sel.form.submit();
  };

  window.karyawanCloseEdit = () => closeModal('kpopupEdit');
  window.karyawanCloseAdd = () => closeModal('kpopupAdd');
  window.karyawanCloseMutasi = () => closeModal('kpopupMutasi');
  window.karyawanCloseMutasiDivisi = () => closeModal('kpopupMutasiDivisi');
  window.karyawanCloseUpload = () => closeModal('kpopupUpload');
  window.karyawanCloseLihat = () => closeModal('kpopupLihat');
  window.karyawanClosePas = () => document.getElementById('kpopupPas').style.display='none';
  window.karyawanCloseResign = () => closeModal('kpopupResign');

  window.closeLeaderDrawer = function(){
    document.getElementById('leaderDrawer').style.display = 'none';
    document.getElementById('leaderDrawerOverlay').style.display = 'none';
    document.getElementById('leader_selected_id').value = '';
    document.getElementById('leaderSearch').value = '';
    document.getElementById('leaderList').innerHTML = '';
  };

  document.getElementById('leaderDrawerOverlay').addEventListener('click', closeLeaderDrawer);

  function escapeHtml(text){
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML;
  }

  function renderLeaderList(keyword = ''){
    const wrap = document.getElementById('leaderList');
    const empty = document.getElementById('leaderEmpty');
    const selectedId = document.getElementById('leader_selected_id').value;
    const q = keyword.trim().toLowerCase();

    const filtered = leaderCandidates.filter(item => {
      const nama = (item.nama_lengkap || '').toLowerCase();
      const jabatan = (item.jabatan || '').toLowerCase();
      const outlet = (item.nama_outlet || '').toLowerCase();
      const terminal = (item.nama_terminal || '').toLowerCase();
      return nama.includes(q) || jabatan.includes(q) || outlet.includes(q) || terminal.includes(q);
    });

    wrap.innerHTML = '';

    if(filtered.length === 0){
      empty.style.display = 'block';
      return;
    }
    empty.style.display = 'none';

    filtered.forEach(item => {
      const div = document.createElement('div');
      div.className = 'item-karyawan' + (String(selectedId) === String(item.id) ? ' active' : '');
      div.dataset.id = item.id;
      div.innerHTML = `<strong>${escapeHtml(item.nama_lengkap)}</strong><small>${escapeHtml(item.jabatan || '')} - ${escapeHtml(item.nama_outlet || '-')} - ${escapeHtml(item.nama_terminal || '-')}</small>`;
      div.addEventListener('click', function(){
        document.getElementById('leader_selected_id').value = item.id;
        renderLeaderList(document.getElementById('leaderSearch').value);
      });
      wrap.appendChild(div);
    });
  }

  async function openLeaderDrawer(outletId, outletNama, terminalNama){
    document.getElementById('leader_outlet_id').value = outletId;
    document.getElementById('leader_selected_id').value = '';
    document.getElementById('leaderDrawerOutletInfo').textContent = `${terminalNama} / ${outletNama}`;
    document.getElementById('leaderDrawer').style.display = 'block';
    document.getElementById('leaderDrawerOverlay').style.display = 'block';
    document.getElementById('leaderList').innerHTML = '<div class="empty-state">Loading data karyawan...</div>';

    try{
      const r = await fetch(`karyawan.php?get_karyawan_outlet=1`);
      const res = await r.json();
      if(!res.ok) throw new Error(res.msg || 'Gagal load data');
      leaderCandidates = Array.isArray(res.data) ? res.data : [];
      renderLeaderList('');
    }catch(err){
      document.getElementById('leaderList').innerHTML = '';
      document.getElementById('leaderEmpty').style.display = 'block';
      alert(err.message || 'Gagal load kandidat crew leader');
    }
  }

  document.getElementById('leaderSearch').addEventListener('input', function(){
    renderLeaderList(this.value);
  });

  document.getElementById('btnSimpanLeader').addEventListener('click', async function(){
    const outletId = document.getElementById('leader_outlet_id').value;
    const karyawanId = document.getElementById('leader_selected_id').value;

    if(!karyawanId){
      alert('Pilih nama karyawan dulu bro');
      return;
    }

    const fd = new FormData();
    fd.append('ajax_set_crew_leader', '1');
    fd.append('outlet_id', outletId);
    fd.append('karyawan_id', karyawanId);

    try{
      const r = await fetch('karyawan.php', {method:'POST', body:fd});
      const res = await r.json();
      if(!res.ok) throw new Error(res.msg || 'Gagal simpan / tambah crew leader');
      closeLeaderDrawer();
      location.reload();
    }catch(err){
      alert(err.message || 'Gagal simpan / tambah crew leader');
    }
  });


  const formResignStatus = document.getElementById('formResignStatus');
  if(formResignStatus){
    formResignStatus.addEventListener('submit', function(e){
      e.preventDefault();

      const id = document.getElementById('resign_id').value;
      const tanggal = document.getElementById('resign_tanggal').value;

      if(!id || !tanggal){
        alert('Tanggal resign wajib diisi bro');
        return;
      }

      const fd = new FormData();
      fd.append('ajax_save_resign_status', '1');
      fd.append('id', id);
      fd.append('tanggal_resign', tanggal);

      fetch('karyawan.php', {method:'POST', body:fd})
        .then(async r => {
          const txt = await r.text();
          try{return JSON.parse(txt);}
          catch(e){console.log(txt); throw new Error('Response bukan JSON');}
        })
        .then(res => {
          if(!res.ok){
            alert(res.msg || 'Gagal simpan resign');
            return;
          }
          karyawanCloseResign();
          alert(res.msg || 'Status RESIGN tersimpan');
          location.reload();
        })
        .catch(err => alert(err.message || 'Gagal simpan resign'));
    });
  }

  document.addEventListener('click', function(e){
    const deleteResignPermanent = e.target.closest('.btn-delete-resign-permanent');
    if(deleteResignPermanent){
      const id = deleteResignPermanent.dataset.id || '';
      const nama = deleteResignPermanent.dataset.nama || '';

      if(!confirm(`Yakin delete permanen data resign ${nama}? Data akan hilang dari database.`)) return;

      const fd = new FormData();
      fd.append('ajax_delete_resign_permanent', '1');
      fd.append('id', id);

      fetch('karyawan.php', {method:'POST', body:fd})
        .then(async r => {
          const txt = await r.text();
          try{return JSON.parse(txt);}
          catch(e){console.log(txt); throw new Error('Response bukan JSON');}
        })
        .then(res => {
          if(!res.ok){
            alert(res.msg || 'Gagal delete data resign');
            return;
          }
          location.reload();
        })
        .catch(err => alert(err.message || 'Gagal delete data resign'));

      return;
    }

    const addBtn = e.target.closest('.btn-add-karyawan');
    if(addBtn){
      document.getElementById('add_terminal_id').value = addBtn.dataset.terminalId || '';
      document.getElementById('add_outlet_id').value = addBtn.dataset.outletId || '';
      document.getElementById('add_terminal_nama').value = addBtn.dataset.terminalNama || '';
      document.getElementById('add_outlet_nama').value = addBtn.dataset.outletNama || '';
      document.getElementById('add_nama').value = '';
      document.getElementById('add_jabatan').value = 'CREW';
      document.getElementById('add_keterangan').value = '';
      openModal('kpopupAdd');
      return;
    }

    const gantiLeader = e.target.closest('.btn-ganti-leader');
    if(gantiLeader){
      openLeaderDrawer(
        gantiLeader.dataset.outletId,
        gantiLeader.dataset.outletNama || '',
        gantiLeader.dataset.terminalNama || ''
      );
      return;
    }

    const deleteLeader = e.target.closest('.btn-delete-leader');
    if(deleteLeader){
      const karyawanId = deleteLeader.dataset.karyawanId || '';
      const nama = deleteLeader.dataset.nama || '';

      if(!confirm(`Yakin delete permanen Crew Leader ${nama}? Data akan hilang dan tidak masuk ke crew.`)) return;

      const fd = new FormData();
      fd.append('ajax_delete_crew_leader', '1');
      fd.append('karyawan_id', karyawanId);

      fetch('karyawan.php', {method:'POST', body:fd})
        .then(async r => {
          const txt = await r.text();
          try{return JSON.parse(txt);}catch(e){console.log(txt); throw new Error('Response bukan JSON');}
        })
        .then(res => {
          if(!res.ok){ alert(res.msg || 'Gagal delete Crew Leader'); return; }
          location.reload();
        })
        .catch(err => alert(err.message || 'Gagal delete Crew Leader'));
      return;
    }

    const deleteKaryawan = e.target.closest('.btn-delete-karyawan');
    if(deleteKaryawan){
      const id = deleteKaryawan.dataset.id || '';
      const nama = deleteKaryawan.dataset.nama || '';

      if(!confirm(`Yakin delete permanen ${nama}? Data akan hilang total dari aktif dan Karyawan Resign.`)) return;

      const fd = new FormData();
      fd.append('ajax_delete_karyawan', '1');
      fd.append('id', id);

      fetch('karyawan.php', {method:'POST', body:fd})
        .then(async r => {
          const txt = await r.text();
          try{return JSON.parse(txt);}
          catch(e){console.log(txt); throw new Error('Response bukan JSON');}
        })
        .then(res => {
          if(!res.ok){
            alert(res.msg || 'Gagal delete permanen karyawan');
            return;
          }
          location.reload();
        })
        .catch(err => alert(err.message || 'Gagal delete permanen karyawan'));

      return;
    }

    const editBtn = e.target.closest('.btn-edit');
    if(editBtn){
      document.getElementById('edit_id').value = editBtn.dataset.id || '';
      document.getElementById('edit_nama').value = editBtn.dataset.nama || '';
      document.getElementById('edit_jabatan').value = editBtn.dataset.jabatan || '';
      document.getElementById('edit_tanggal_masuk_pt').value = editBtn.dataset.tanggalMasukPt || '';
      document.getElementById('edit_status_surat').value = editBtn.dataset.status || '';
      document.getElementById('edit_resign_tanggal').value = editBtn.dataset.resignAt || '';
      toggleEditResignTanggal();
      document.getElementById('edit_keterangan').value = editBtn.dataset.keterangan || '';
      openModal('kpopupEdit');
      return;
    }

    const mutasiBtn = e.target.closest('.btn-mutasi');
    if(mutasiBtn){
      document.getElementById('karyawan_id').value = mutasiBtn.dataset.id || '';
      document.getElementById('mutasi_nama').value = mutasiBtn.dataset.nama || '';
      const outletSelect = document.getElementById('outletSelect');
      if(outletSelect) outletSelect.innerHTML = "<option value=''>-- Pilih Outlet --</option>";
      openModal('kpopupMutasi');
      return;
    }

    const mutasiDivisiBtn = e.target.closest('.btn-mutasi-divisi');
    if(mutasiDivisiBtn){
      document.getElementById('mutasi_divisi_id').value = mutasiDivisiBtn.dataset.id || '';
      document.getElementById('mutasi_divisi_nama').value = mutasiDivisiBtn.dataset.nama || '';
      const divisiSelect = document.getElementById('divisiSelect');
      if(divisiSelect) divisiSelect.value = '';
      openModal('kpopupMutasiDivisi');
      return;
    }

    const uploadBtn = e.target.closest('.btn-upload');
    if(uploadBtn){
      document.getElementById('upload_karyawan_id').value = uploadBtn.dataset.id || '';
      document.getElementById('nama_upload').innerText = uploadBtn.dataset.nama || '';
      openModal('kpopupUpload');
      return;
    }

    const lihat = e.target.closest("[data-action='lihat-dokumen']");
    if(lihat){
      const id = lihat.getAttribute('data-id');
      fetch('karyawan.php?get_dokumen=1&id=' + encodeURIComponent(id))
        .then(r => r.json())
        .then(d => {
          if(!d.ok){ alert('Dokumen belum ada.'); return; }
          document.getElementById('imgDokumen').src = d.file_path;
          openModal('kpopupLihat');
        })
        .catch(() => alert('Gagal load dokumen'));
      return;
    }

    const nm = e.target.closest('.link-pas');
    if(nm){
      const id = nm.dataset.id;
      document.getElementById('pas_id').value = id;
      document.getElementById('pas_nama').value = nm.dataset.nama || '';
      document.getElementById('pas_jabatan').value = nm.dataset.jabatan || '';
      document.getElementById('pas_outlet').value = nm.dataset.outlet || '';

      setPasMode(IS_WIRA);
      document.getElementById('pas_nama_pt').value = '';
      document.getElementById('pas_terminal').value = '';
      document.getElementById('pas_masa').value = '';
      document.getElementById('pas_foto').src = DEFAULT_AVATAR;
      const info = document.getElementById('pas_info');
      if(info) info.textContent = '';

      fetch('karyawan.php?get_pas_karyawan=1&id=' + encodeURIComponent(id))
        .then(r => r.json())
        .then(d => {
          if(d.ok && d.data){
            document.getElementById('pas_nama_pt').value = d.data.nama_pt || '';
            document.getElementById('pas_terminal').value = d.data.terminal_pas || '';
            document.getElementById('pas_masa').value = d.data.masa_berlaku_pas || '';
            document.getElementById('pas_foto').src = (d.data.foto_pas && d.data.foto_pas.trim() !== '') ? d.data.foto_pas : DEFAULT_AVATAR;
          }
          document.getElementById('kpopupPas').style.display = 'flex';
        })
        .catch(() => alert('Gagal load data pas bandara'));
      return;
    }
  });


  document.addEventListener('change', function(e){
    const input = e.target.closest('.inline-tanggal-resign');
    if(!input) return;

    const id = input.dataset.id || '';
    const tanggal = input.value || '';
    const info = document.getElementById('save_resign_info_' + id);
    if(info) info.textContent = 'Menyimpan...';

    const fd = new FormData();
    fd.append('ajax_update_tanggal_resign', '1');
    fd.append('id', id);
    fd.append('tanggal_resign', tanggal);

    fetch('karyawan.php', {method:'POST', body:fd})
      .then(async r => {
        const txt = await r.text();
        try{return JSON.parse(txt);}
        catch(e){console.log(txt); throw new Error('Response bukan JSON');}
      })
      .then(res => {
        if(!res.ok){
          if(info) info.textContent = 'Gagal';
          alert(res.msg || 'Gagal update tanggal resign');
          return;
        }
        if(info) info.textContent = 'Tersimpan';
        setTimeout(() => { if(info) info.textContent = ''; }, 1800);
      })
      .catch(err => {
        if(info) info.textContent = 'Gagal';
        alert(err.message || 'Gagal update tanggal resign');
      });
  });


  document.addEventListener('change', function(e){
    const input = e.target.closest('.inline-berakhir-kontrak');
    if(!input) return;

    const id = input.dataset.id || '';
    const kontrak = input.value || '';
    const info = document.getElementById('save_kontrak_info_' + id);
    if(info) info.textContent = 'Menyimpan...';

    const fd = new FormData();
    fd.append('ajax_update_berakhir_kontrak', '1');
    fd.append('id', id);
    fd.append('berakhir_kontrak', kontrak);

    fetch('karyawan.php', {method:'POST', body:fd})
      .then(async r => {
        const txt = await r.text();
        try{return JSON.parse(txt);}
        catch(e){console.log(txt); throw new Error('Response bukan JSON');}
      })
      .then(res => {
        if(!res.ok){
          if(info) info.textContent = 'Gagal';
          alert(res.msg || 'Gagal update berakhir kontrak');
          return;
        }
        if(info) info.textContent = 'Tersimpan';
        setTimeout(() => { if(info) info.textContent = ''; }, 1800);
      })
      .catch(err => {
        if(info) info.textContent = 'Gagal';
        alert(err.message || 'Gagal update berakhir kontrak');
      });
  });

  const terminalSelect = document.getElementById('terminalSelect');
  if(terminalSelect){
    terminalSelect.addEventListener('change', function(){
      fetch('karyawan.php?get_outlet=1&terminal_id=' + encodeURIComponent(this.value))
        .then(res => res.text())
        .then(data => {
          const outletSelect = document.getElementById('outletSelect');
          if(outletSelect) outletSelect.innerHTML = data;
        })
        .catch(() => alert('Gagal load outlet'));
    });
  }

  const formAdd = document.getElementById('formAddKaryawan');
  if(formAdd){
    formAdd.addEventListener('submit', function(e){
      e.preventDefault();
      const fd = new FormData();
      fd.append('ajax_add_karyawan', '1');
      fd.append('terminal_id', document.getElementById('add_terminal_id').value);
      fd.append('outlet_id', document.getElementById('add_outlet_id').value);
      fd.append('nama', document.getElementById('add_nama').value);
      fd.append('jabatan', document.getElementById('add_jabatan').value);
      fd.append('keterangan', document.getElementById('add_keterangan').value);

      fetch('karyawan.php', {method:'POST', body:fd})
        .then(async r => {
          const txt = await r.text();
          try{return JSON.parse(txt);}catch(e){console.log(txt); throw new Error('Response bukan JSON');}
        })
        .then(res => {
          if(!res.ok){ alert(res.msg || 'Gagal tambah karyawan'); return; }
          karyawanCloseAdd();
          location.reload();
        })
        .catch(err => alert(err.message || 'Gagal tambah karyawan'));
    });
  }


  function toggleEditResignTanggal(){
    const statusEl = document.getElementById('edit_status_surat');
    const wrap = document.getElementById('edit_resign_tanggal_wrap');
    const tgl = document.getElementById('edit_resign_tanggal');
    if(!statusEl || !wrap || !tgl) return;

    if(statusEl.value === 'RESIGN'){
      wrap.style.display = 'block';
      if(!tgl.value){
        tgl.value = new Date().toISOString().slice(0,10);
      }
    }else{
      wrap.style.display = 'none';
      tgl.value = '';
    }
  }

  const editStatusSurat = document.getElementById('edit_status_surat');
  if(editStatusSurat){
    editStatusSurat.addEventListener('change', toggleEditResignTanggal);
  }

  const formEdit = document.getElementById('formEditKaryawan');
  if(formEdit){
    formEdit.addEventListener('submit', function(e){
      e.preventDefault();
      const fd = new FormData();
      fd.append('ajax_edit', '1');
      fd.append('id', document.getElementById('edit_id').value);
      fd.append('nama', document.getElementById('edit_nama').value);
      fd.append('jabatan', document.getElementById('edit_jabatan').value);
      fd.append('tanggal_masuk_pt', document.getElementById('edit_tanggal_masuk_pt').value);
      fd.append('status_surat', document.getElementById('edit_status_surat').value);
      fd.append('tanggal_resign', document.getElementById('edit_resign_tanggal').value);
      fd.append('keterangan', document.getElementById('edit_keterangan').value);

      fetch('karyawan.php', { method:'POST', body: fd })
        .then(async r => {
          const text = await r.text();
          try { return JSON.parse(text); }
          catch(e){ console.log('RESP BUKAN JSON:', text); throw new Error('Response bukan JSON, cek console.'); }
        })
        .then(res => {
          if(!res.ok){ alert(res.msg || 'Gagal update'); return; }
          karyawanCloseEdit();
          location.reload();
        })
        .catch(err => alert(err.message || 'Gagal koneksi (AJAX edit)'));
    });
  }

  const formMutasi = document.getElementById('formMutasiKaryawan');
  if(formMutasi){
    formMutasi.addEventListener('submit', function(e){
      e.preventDefault();
      const terminalBaru = document.getElementById('terminalSelect').value;
      const outletBaru = document.getElementById('outletSelect').value;
      if(!terminalBaru || !outletBaru){
        alert('Terminal dan Outlet wajib dipilih');
        return;
      }

      const fd = new FormData();
      fd.append('ajax_mutasi', '1');
      fd.append('karyawan_id', document.getElementById('karyawan_id').value);
      fd.append('terminal_baru', terminalBaru);
      fd.append('outlet_baru', outletBaru);

      fetch('karyawan.php', { method:'POST', body: fd })
        .then(async r => {
          const text = await r.text();
          try { return JSON.parse(text); }
          catch(e){ console.log('RESP BUKAN JSON:', text); throw new Error('Response bukan JSON, cek console.'); }
        })
        .then(res => {
          if(!res.ok){ alert(res.msg || 'Gagal mutasi'); return; }
          karyawanCloseMutasi();
          location.reload();
        })
        .catch(err => alert(err.message || 'Gagal koneksi (AJAX mutasi)'));
    });
  }

  const formMutasiDivisi = document.getElementById('formMutasiDivisi');
  if(formMutasiDivisi){
    formMutasiDivisi.addEventListener('submit', function(e){
      e.preventDefault();
      const divisiBaru = document.getElementById('divisiSelect').value;
      if(!divisiBaru){
        alert('Divisi baru wajib dipilih');
        return;
      }

      const nama = document.getElementById('mutasi_divisi_nama').value || '';
      if(!confirm(`Yakin mutasi ${nama} ke divisi ${divisiBaru}? Nama akan hilang dari toko lama.`)) return;

      const fd = new FormData();
      fd.append('ajax_mutasi_divisi', '1');
      fd.append('karyawan_id', document.getElementById('mutasi_divisi_id').value);
      fd.append('divisi_baru', divisiBaru);

      fetch('karyawan.php', { method:'POST', body: fd })
        .then(async r => {
          const text = await r.text();
          try { return JSON.parse(text); }
          catch(e){ console.log('RESP BUKAN JSON:', text); throw new Error('Response bukan JSON, cek console.'); }
        })
        .then(res => {
          if(!res.ok){ alert(res.msg || 'Gagal mutasi divisi'); return; }
          karyawanCloseMutasiDivisi();
          alert(res.msg || 'Berhasil mutasi divisi');
          location.reload();
        })
        .catch(err => alert(err.message || 'Gagal koneksi (AJAX mutasi divisi)'));
    });
  }

  function setPasMode(canEdit){
    const ro = !canEdit;
    ['pas_nama_pt','pas_terminal'].forEach(id => {
      const el = document.getElementById(id);
      if(el) el.readOnly = ro;
    });
    const masa = document.getElementById('pas_masa');
    if(masa) masa.disabled = ro;
    const wrap = document.getElementById('pas_upload_wrap');
    if(wrap) wrap.style.display = ro ? 'none' : 'block';
  }

  async function savePasToDB(){
    if(!IS_WIRA) return;
    const fd = new FormData();
    fd.append('save_pas_karyawan','1');
    fd.append('id', document.getElementById('pas_id').value);
    fd.append('nama_pt', document.getElementById('pas_nama_pt').value);
    fd.append('terminal_pas', document.getElementById('pas_terminal').value);
    fd.append('masa_berlaku_pas', document.getElementById('pas_masa').value);

    const r = await fetch('karyawan.php', {method:'POST', body:fd});
    const res = await r.json().catch(()=>({ok:false,msg:'Response bukan JSON'}));
    const info = document.getElementById('pas_info');
    if(info) info.textContent = res.ok ? 'Tersimpan' : ('Gagal: ' + (res.msg || ''));
  }

  ['pas_nama_pt','pas_terminal'].forEach(id => {
    const el = document.getElementById(id);
    if(!el) return;
    el.addEventListener('keydown', function(ev){
      if(ev.key === 'Enter'){
        ev.preventDefault();
        savePasToDB();
      }
    });
    el.addEventListener('blur', savePasToDB);
  });

  const masa = document.getElementById('pas_masa');
  if(masa) masa.addEventListener('change', savePasToDB);

  async function uploadFotoPas(){
    if(!IS_WIRA) return;
    const f = document.getElementById('pas_file');
    if(!f || !f.files || !f.files[0]) return alert('Pilih foto dulu bro');

    const fd = new FormData();
    fd.append('upload_foto_pas','1');
    fd.append('id', document.getElementById('pas_id').value);
    fd.append('foto', f.files[0]);

    const r = await fetch('karyawan.php', {method:'POST', body:fd});
    const res = await r.json().catch(()=>({ok:false,msg:'Response bukan JSON'}));
    if(!res.ok) return alert(res.msg || 'Gagal upload');

    document.getElementById('pas_foto').src = res.foto || DEFAULT_AVATAR;
    const info = document.getElementById('pas_info');
    if(info) info.textContent = 'Foto tersimpan';
  }

  const btnUp = document.getElementById('btnUploadPas');
  if(btnUp) btnUp.addEventListener('click', uploadFotoPas);
});
</script>


<script>
function closeAllActionMenus(exceptMenu = null){
  document.querySelectorAll('.action-dropdown.show').forEach(menu => {
    if(menu !== exceptMenu) menu.classList.remove('show');
  });
}

function toggleActionMenu(btn){
  const menu = btn.nextElementSibling;
  if(!menu) return;

  const willOpen = !menu.classList.contains('show');
  closeAllActionMenus(menu);
  menu.classList.toggle('show', willOpen);
}

document.addEventListener('click', function(e){
  const toggle = e.target.closest('.btn-action-toggle');
  const dropdown = e.target.closest('.action-dropdown');

  if(!toggle && !dropdown){
    closeAllActionMenus();
    return;
  }

  // Setelah salah satu item dipilih, tutup menu.
  if(dropdown && e.target.closest('button')){
    setTimeout(() => closeAllActionMenus(), 0);
  }
});

document.addEventListener('keydown', function(e){
  if(e.key === 'Escape') closeAllActionMenus();
});
</script>

</body>
</html>
