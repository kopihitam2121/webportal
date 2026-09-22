<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);


$conn = mysqli_connect("202.10.41.120","wira_user","Mtssepatan210300#","appsheet_db");
if(!$conn) die("Koneksi gagal");

// ================= AUTH CHECK (EDIT ONLY) =================
$username = $_SESSION["username"] ?? "";
$role     = $_SESSION["role"] ?? "";
$isEditor = (strtolower($username) === "wira" || strtolower($role) === "superadmin");

// ================= BLOK NAMA (M'MART T3) =================
$blockedNames = [
  "ERIKO DWI PANGESTU",
  "GOVINDA",
  "M. IRFAN HASIM",
  "M. ZEHAN ZENNETI ZAM SURYADI",
  "OKTAVIO DWI KURNIAWAN",
  "SURYANAH",
  "VENDA AJI MUKTI"
];

$blockedSql = "'" . implode("','", array_map(function($n) use ($conn){
  return mysqli_real_escape_string($conn, strtoupper(trim($n)));
}, $blockedNames)) . "'";

// ================= FILTER MASA BERLAKU =================
$tanggalCari = "";
$whereExtra = "";
if(isset($_GET['cari'])){
  $tanggalCari = mysqli_real_escape_string($conn, $_GET['berlaku_sampai'] ?? '');
  if($tanggalCari !== ""){
    $whereExtra = " AND k.masa_berlaku_pas = '$tanggalCari'";
  }
}

// ================= SUBQUERY DATA TERBARU PER NAMA =================
// MySQL 8+
// Ambil 1 data terbaru per nama:
// 1) updated_at paling baru
// 2) jika sama / null -> id paling besar
$latestPerNameSubquery = "
  SELECT *
  FROM (
    SELECT
      k.*,
      ROW_NUMBER() OVER (
        PARTITION BY UPPER(TRIM(REGEXP_REPLACE(k.nama_lengkap, '[[:space:]]+', ' ')))
        ORDER BY
          COALESCE(k.updated_at, '1970-01-01 00:00:00') DESC,
          k.id DESC
      ) AS rn
    FROM karyawan k
    WHERE
      k.nama_pt IS NOT NULL
      AND TRIM(k.nama_pt) <> ''
      AND UPPER(TRIM(k.nama_lengkap)) NOT IN ($blockedSql)
  ) z
  WHERE z.rn = 1
";

// ================= Placeholder Avatar (SVG data-uri) =================
$placeholderSvg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="400" height="520" viewBox="0 0 400 520">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#eef2f7"/>
      <stop offset="1" stop-color="#d9e2ec"/>
    </linearGradient>
  </defs>
  <rect width="400" height="520" rx="28" fill="url(#g)"/>
  <circle cx="200" cy="190" r="78" fill="#b9c6d6"/>
  <path d="M75 470c18-105 89-150 125-150h0c36 0 107 45 125 150" fill="#b9c6d6"/>
  <text x="200" y="510" text-anchor="middle" font-family="Segoe UI, Arial" font-size="20" fill="#6b7c93">No Photo</text>
</svg>
SVG;
$defaultAvatar = "data:image/svg+xml;utf8," . rawurlencode($placeholderSvg);

// ================= EXPORT EXCEL (TANPA FOTO) =================
if(isset($_GET['export'])){
  header("Content-Type: application/vnd.ms-excel; charset=utf-8");
  header("Content-Disposition: attachment; filename=PAS_BANDARA_MINIMARKET.xls");
  header("Pragma: no-cache");
  header("Expires: 0");

  echo "<html><head><meta charset='UTF-8'>
  <style>
    body{font-family:Arial,sans-serif;font-size:12px;}
    table{border-collapse:collapse;width:100%;}
    th,td{border:1px solid #9e9e9e;padding:6px 8px;vertical-align:middle;}
    .title{background:#1f4e78;color:#fff;font-size:16px;font-weight:bold;text-align:center;}
    .header th{background:#4472c4;color:#fff;font-weight:bold;text-align:center;}
    .center{text-align:center;}
  </style>
  </head><body>";

  echo "<table>";
  echo "<tr><td colspan='8' class='title'>DATA PAS BANDARA MINIMARKET</td></tr>";
  echo "<tr class='header'>
          <th>No</th>
          <th>Nama Lengkap</th>
          <th>Jabatan</th>
          <th>Nama PT</th>
          <th>Terminal PAS</th>
          <th>Outlet / Penempatan</th>
          <th>Terminal Kerja</th>
          <th>Masa Berlaku</th>
        </tr>";

  $qExp = mysqli_query($conn, "
    SELECT
      k.id,
      IFNULL(k.nama_lengkap,'') AS nama_lengkap,
      IFNULL(k.jabatan,'') AS jabatan,
      IFNULL(k.nama_pt,'') AS nama_pt,
      IFNULL(k.terminal_pas,'') AS terminal_pas,
      IFNULL(o.nama_outlet,'') AS nama_outlet,
      IFNULL(t.nama_terminal,'') AS nama_terminal,
      IFNULL(DATE_FORMAT(k.masa_berlaku_pas,'%Y-%m-%d'),'') AS masa_berlaku_pas
    FROM ($latestPerNameSubquery) k
    LEFT JOIN outlet o ON k.outlet_id = o.id
    LEFT JOIN terminal t ON k.terminal_id = t.id
    WHERE 1=1
      $whereExtra
    ORDER BY t.nama_terminal ASC, o.nama_outlet ASC, k.nama_lengkap ASC
  ");

  if(!$qExp) die("Export query error: ".mysqli_error($conn));

  $no = 1;
  while($r = mysqli_fetch_assoc($qExp)){
    echo "<tr>
            <td class='center'>".$no++."</td>
            <td>".htmlspecialchars($r['nama_lengkap'])."</td>
            <td>".htmlspecialchars($r['jabatan'])."</td>
            <td>".htmlspecialchars($r['nama_pt'])."</td>
            <td>".htmlspecialchars($r['terminal_pas'])."</td>
            <td>".htmlspecialchars($r['nama_outlet'])."</td>
            <td>".htmlspecialchars($r['nama_terminal'])."</td>
            <td>".htmlspecialchars($r['masa_berlaku_pas'])."</td>
          </tr>";
  }

  echo "</table>";
  echo "</body></html>";
  exit;
}

// ================= AJAX UPDATE FOTO (foto_pas) =================
if(isset($_POST['ajax']) && $_POST['ajax'] === 'update_foto'){
  header("Content-Type: application/json; charset=utf-8");

  if(!$isEditor){
    http_response_code(403);
    echo json_encode(["ok"=>false,"msg"=>"FORBIDDEN"]);
    exit;
  }

  $id = mysqli_real_escape_string($conn, $_POST['id'] ?? '');
  if($id === ""){
    http_response_code(400);
    echo json_encode(["ok"=>false,"msg"=>"ID kosong"]);
    exit;
  }

  if(!isset($_FILES['foto']) || $_FILES['foto']['error'] != 0){
    http_response_code(400);
    echo json_encode(["ok"=>false,"msg"=>"File foto tidak valid"]);
    exit;
  }

  $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
  $allowed = ['jpg','jpeg','png','webp'];
  if(!in_array($ext, $allowed, true)){
    http_response_code(400);
    echo json_encode(["ok"=>false,"msg"=>"Format harus jpg/jpeg/png/webp"]);
    exit;
  }

  $folder = __DIR__ . "/uploads/foto_pas/";
  if(!is_dir($folder)) mkdir($folder, 0777, true);

  $safeName  = preg_replace('/[^a-zA-Z0-9\.\-\_]/','', basename($_FILES['foto']['name']));
  $newName   = "pas_" . $id . "_" . time() . "_" . $safeName;
  $targetAbs = $folder . $newName;

  if(!move_uploaded_file($_FILES['foto']['tmp_name'], $targetAbs)){
    http_response_code(500);
    echo json_encode(["ok"=>false,"msg"=>"Gagal upload foto"]);
    exit;
  }

  $dbPath = "uploads/foto_pas/" . $newName;

  $ok = mysqli_query($conn, "
    UPDATE karyawan
    SET foto_pas='".mysqli_real_escape_string($conn,$dbPath)."', updated_at=NOW()
    WHERE id='$id'
  ");
  if(!$ok){
    http_response_code(500);
    echo json_encode(["ok"=>false,"msg"=>"DB error: ".mysqli_error($conn)]);
    exit;
  }

  echo json_encode(["ok"=>true,"foto"=>$dbPath]);
  exit;
}

// ================= AJAX INLINE UPDATE =================
if(isset($_POST['ajax']) && $_POST['ajax'] === 'inline_update'){
  header("Content-Type: application/json; charset=utf-8");

  if(!$isEditor){
    http_response_code(403);
    echo json_encode(["ok"=>false,"msg"=>"FORBIDDEN"]);
    exit;
  }

  $id  = trim($_POST["id"] ?? "");
  $col = trim($_POST["col"] ?? "");
  $val = trim($_POST["val"] ?? "");

  if($id === ""){
    http_response_code(400);
    echo json_encode(["ok"=>false,"msg"=>"ID kosong"]);
    exit;
  }

  $allowedCols = ["nama_pt","terminal_pas","masa_berlaku_pas"];
  if(!in_array($col, $allowedCols, true)){
    http_response_code(400);
    echo json_encode(["ok"=>false,"msg"=>"Kolom tidak diizinkan"]);
    exit;
  }

  if($col === "masa_berlaku_pas"){
    if($val !== "" && !preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $val)){
      http_response_code(400);
      echo json_encode(["ok"=>false,"msg"=>"Format tanggal harus YYYY-MM-DD"]);
      exit;
    }
  }

  $idEsc = mysqli_real_escape_string($conn, $id);

  if($col === "masa_berlaku_pas"){
    if($val === ""){
      $sql = "UPDATE karyawan SET masa_berlaku_pas=NULL, updated_at=NOW() WHERE id=?";
      $stmt = mysqli_prepare($conn, $sql);
      if(!$stmt){
        http_response_code(500);
        echo json_encode(["ok"=>false,"msg"=>"Prepare gagal: ".mysqli_error($conn)]);
        exit;
      }
      mysqli_stmt_bind_param($stmt, "s", $idEsc);
    } else {
      $sql = "UPDATE karyawan SET masa_berlaku_pas=?, updated_at=NOW() WHERE id=?";
      $stmt = mysqli_prepare($conn, $sql);
      if(!$stmt){
        http_response_code(500);
        echo json_encode(["ok"=>false,"msg"=>"Prepare gagal: ".mysqli_error($conn)]);
        exit;
      }
      mysqli_stmt_bind_param($stmt, "ss", $val, $idEsc);
    }
  } else {
    $sql = "UPDATE karyawan SET {$col}=?, updated_at=NOW() WHERE id=?";
    $stmt = mysqli_prepare($conn, $sql);
    if(!$stmt){
      http_response_code(500);
      echo json_encode(["ok"=>false,"msg"=>"Prepare gagal: ".mysqli_error($conn)]);
      exit;
    }
    mysqli_stmt_bind_param($stmt, "ss", $val, $idEsc);
  }

  $ok  = mysqli_stmt_execute($stmt);
  $err = mysqli_stmt_error($stmt);
  $aff = mysqli_stmt_affected_rows($stmt);
  mysqli_stmt_close($stmt);

  if(!$ok){
    http_response_code(500);
    echo json_encode(["ok"=>false,"msg"=>"Query gagal: ".$err]);
    exit;
  }

  echo json_encode(["ok"=>true,"affected"=>$aff]);
  exit;
}

// ================= QUERY DATA TABEL =================
$sqlTable = "
  SELECT
    k.id,
    IFNULL(k.nama_lengkap,'') AS nama_lengkap,
    IFNULL(k.jabatan,'') AS jabatan,
    IFNULL(k.nama_pt,'') AS nama_pt,
    IFNULL(k.terminal_pas,'') AS terminal_pas,
    IFNULL(o.nama_outlet,'') AS nama_outlet,
    IFNULL(t.nama_terminal,'') AS nama_terminal,
    k.masa_berlaku_pas,
    IFNULL(DATE_FORMAT(k.masa_berlaku_pas,'%Y-%m-%d'),'') AS masa_berlaku_pas_fmt,
    IFNULL(k.foto_pas,'') AS foto_pas,
    CASE
      WHEN k.masa_berlaku_pas IS NULL THEN NULL
      ELSE DATEDIFF(k.masa_berlaku_pas, CURDATE())
    END AS sisa_hari
  FROM ($latestPerNameSubquery) k
  LEFT JOIN outlet o ON k.outlet_id = o.id
  LEFT JOIN terminal t ON k.terminal_id = t.id
  WHERE 1=1
    $whereExtra
  ORDER BY t.nama_terminal ASC, o.nama_outlet ASC, k.nama_lengkap ASC
";
$q = mysqli_query($conn, $sqlTable);
if(!$q) die("Query tabel error: ".mysqli_error($conn));

// ================= ALERT EXPIRE (0 - 40 hari) =================
$alertData = [];
$sqlAlert = "
  SELECT
    k.id,
    k.nama_lengkap,
    IFNULL(o.nama_outlet,'') AS nama_outlet,
    IFNULL(t.nama_terminal,'') AS nama_terminal,
    IFNULL(DATE_FORMAT(k.masa_berlaku_pas,'%Y-%m-%d'),'') AS masa_berlaku_pas,
    DATEDIFF(k.masa_berlaku_pas, CURDATE()) AS sisa_hari
  FROM ($latestPerNameSubquery) k
  LEFT JOIN outlet o ON k.outlet_id = o.id
  LEFT JOIN terminal t ON k.terminal_id = t.id
  WHERE
    k.masa_berlaku_pas IS NOT NULL
    AND DATEDIFF(k.masa_berlaku_pas, CURDATE()) BETWEEN 0 AND 40
  ORDER BY sisa_hari ASC, k.nama_lengkap ASC
";
$qAlert = mysqli_query($conn, $sqlAlert);
if($qAlert){
  while($a=mysqli_fetch_assoc($qAlert)) $alertData[] = $a;
}

// ================= SVG ICONS =================
$svgSearch = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><path d="M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="currentColor" stroke-width="2"/><path d="M16.5 16.5 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
$svgExport = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><path d="M12 3v10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M8 9l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 17v3h16v-3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
$svgReset  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><path d="M21 12a9 9 0 1 1-3-6.7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M21 3v6h-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
?>
<!DOCTYPE html>
<html>
<head>
  <title>DATA PAS BANDARA MINIMARKET</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
:root{
  --bg:#f4f6f9; --card:#ffffff; --text:#0f172a; --muted:#64748b; --line:#e2e8f0;
  --brand:#0ea5e9; --brand2:#2563eb; --warn:#f59e0b; --danger:#ef4444; --ok:#22c55e;
}
*{box-sizing:border-box}
body{margin:0;font-family:'Segoe UI',sans-serif;background:var(--bg);color:var(--text)}
.header{
  height:72px;background:var(--card);
  display:flex;align-items:center;justify-content:space-between;
  padding:0 22px;box-shadow:0 2px 10px rgba(2,8,23,.06);
  position:sticky;top:0;z-index:10
}
.header-left{display:flex;align-items:center;gap:12px}
.logo{height:44px;object-fit:contain}
.btn-back{
  background:#0f172a;color:#fff;border:none;padding:10px 14px;border-radius:12px;
  cursor:pointer;display:flex;align-items:center;gap:8px;font-size:14px;font-weight:800
}
.btn-back:hover{opacity:.92}
.container{
  background:var(--card);margin:18px;padding:18px;border-radius:16px;
  box-shadow:0 2px 12px rgba(2,8,23,.06)
}
.title{display:flex;flex-wrap:wrap;gap:12px;align-items:end;justify-content:space-between;margin-bottom:12px}
.title h1{margin:0;font-size:22px;letter-spacing:.2px}

.filterbar{
  display:flex;gap:10px;flex-wrap:wrap;align-items:end;
  padding:12px;border:1px solid var(--line);border-radius:14px;background:#fbfdff
}
.filterbar label{font-weight:900;font-size:12px;color:var(--muted);display:block;margin-bottom:6px}
.filterbar input[type="date"]{padding:10px 12px;border-radius:12px;border:1px solid var(--line);outline:none;min-width:210px}
.btn{
  height:42px;padding:0 16px;border:none;border-radius:12px;cursor:pointer;font-weight:900;
  display:inline-flex;align-items:center;gap:8px
}
.btn svg{display:block}
.btn-primary{background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff}
.btn-primary:hover{opacity:.92}
.btn-ghost{background:#fff;border:1px solid var(--line);color:var(--text)}
.btn-ghost:hover{background:#f8fafc}

.tablewrap{margin-top:14px;overflow:auto;border:1px solid var(--line);border-radius:14px}
table{width:100%;border-collapse:separate;border-spacing:0;min-width:1280px}
thead th{
  position:sticky;top:0;z-index:1;
  background:linear-gradient(135deg,#0f172a,#1f2937);
  color:#fff;font-size:12px;letter-spacing:.6px;text-transform:uppercase;
  padding:12px;border-bottom:1px solid rgba(255,255,255,.12);
}
tbody td{padding:12px;border-bottom:1px solid var(--line);vertical-align:middle;font-size:14px;background:#fff}
tbody tr:hover td{background:#f8fafc}
td.muted{color:var(--muted);font-size:13px}
.nowrap{white-space:nowrap}
.cell-edit[contenteditable="true"]{
  outline:none;border-radius:10px;padding:8px 10px;background:#fff7ed;border:1px dashed #fdba74;cursor:text
}
.foto{
  width:72px;height:92px;object-fit:cover;border-radius:14px;cursor:pointer;
  border:1px solid var(--line);background:#f1f5f9
}
.foto:hover{transform:translateY(-1px);transition:.15s;box-shadow:0 6px 16px rgba(2,8,23,.12)}
.col-foto{width:110px;text-align:center}
.col-no{width:60px}
.col-nama{min-width:220px;font-weight:900}
.col-penempatan{min-width:220px}
.col-terminalkerja{min-width:160px}
.sub{display:block;font-size:12px;color:var(--muted);font-weight:700;margin-top:2px}
.badge{
  display:inline-flex;align-items:center;gap:6px;
  padding:6px 10px;border-radius:999px;font-size:12px;font-weight:900;
  border:1px solid var(--line);background:#f8fafc;color:var(--muted)
}
.badge.ok{background:#ecfdf5;color:#15803d;border-color:#bbf7d0}
.badge.warn{background:#fffbeb;color:#a16207;border-color:#fde68a}
.badge.danger{background:#fef2f2;color:#b91c1c;border-color:#fecaca}
.pill{
  display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;
  font-size:12px;font-weight:900;border:1px solid var(--line);background:#f8fafc;color:var(--muted)
}
.modal{
  display:none;position:fixed;inset:0;background:rgba(2,8,23,.85);
  justify-content:center;align-items:center;flex-direction:column;z-index:9999;padding:18px
}
.modal-card{
  width:min(980px, 96vw);background:#0b1220;border:1px solid rgba(255,255,255,.14);
  border-radius:16px;padding:14px
}
.modal img{width:100%;max-height:72vh;object-fit:contain;border-radius:12px;background:#0b1220}
.modal-actions{margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
.modal-actions button{padding:10px 14px;border:none;border-radius:12px;cursor:pointer;font-weight:900}
.btn-download{background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff}
.btn-ganti{background:#fbbf24;color:#0b1220}
.btn-close{background:#111827;color:#fff;border:1px solid rgba(255,255,255,.14)}
.small-note{font-size:12px;color:var(--muted);margin-top:10px;line-height:1.45}
</style>
</head>

<body>

<div class="header">
  <div class="header-left">
    <button class="btn-back" onclick="history.back()">Kembali</button>
    <img src="img/srt4.png" class="logo" alt="Logo">
  </div>
  <div style="display:flex;gap:10px;align-items:center;">
    <img src="img/otban.jpg" class="logo" alt="Logo">
  </div>
</div>

<div class="container">
  <div class="title">
    <div>
      <h1>DATA PAS BANDARA MINIMARKET</h1>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;">
      <a class="btn btn-ghost" style="text-decoration:none" href="?<?php
        $qs = $_GET; $qs['export'] = 1;
        echo htmlspecialchars(http_build_query($qs));
      ?>">
        <?php echo $svgExport; ?> Export Excel
      </a>
    </div>
  </div>

  <form method="GET" class="filterbar">
    <div>
      <label>Filter Masa Berlaku</label>
      <input type="date" name="berlaku_sampai" value="<?php echo htmlspecialchars($tanggalCari); ?>">
    </div>
    <button class="btn btn-primary" type="submit" name="cari" value="1">
      <?php echo $svgSearch; ?> Cari
    </button>
    <a class="btn btn-ghost" href="pasbandara.php" style="text-decoration:none">
      <?php echo $svgReset; ?> Reset
    </a>
  </form>

  <div class="tablewrap">
    <table>
      <thead>
        <tr>
          <th class="col-no">No</th>
          <th>Nama Lengkap</th>
          <th>Jabatan</th>
          <th>Nama PT</th>
          <th>Terminal PAS</th>
          <th class="col-penempatan">Outlet / Penempatan</th>
          <th class="col-terminalkerja">Terminal Kerja</th>
          <th class="nowrap">Masa Berlaku</th>
          <th class="col-foto">Foto</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $no = 1;
      while($r=mysqli_fetch_assoc($q)):
        $foto = trim($r['foto_pas'] ?? '');
        $fotoShow = ($foto !== "") ? $foto : $defaultAvatar;

        $sisa = $r['sisa_hari'];
        $badge = "";
        if($r['masa_berlaku_pas'] === null || $r['masa_berlaku_pas_fmt'] === ""){
          $badge = "<span class='pill'>Belum diisi</span>";
        } else {
          if($sisa !== null){
            if($sisa < 0) $badge = "<span class='badge danger'>Expired</span>";
            else if($sisa <= 40) $badge = "<span class='badge warn'>".$sisa." hari lagi</span>";
            else $badge = "<span class='badge ok'>Aman</span>";
          }
        }
      ?>
        <tr data-id="<?php echo htmlspecialchars($r['id']); ?>">
          <td class="muted"><?php echo $no++; ?></td>

          <td class="col-nama">
            <?php echo htmlspecialchars($r['nama_lengkap']); ?>
            <span class="sub">ID: <?php echo htmlspecialchars($r['id']); ?></span>
          </td>

          <td><?php echo htmlspecialchars($r['jabatan']); ?></td>

          <td class="cell-edit" data-col="nama_pt" <?php echo $isEditor ? 'contenteditable="true"' : ''; ?>>
            <?php echo htmlspecialchars($r['nama_pt']); ?>
          </td>

          <td class="cell-edit" data-col="terminal_pas" <?php echo $isEditor ? 'contenteditable="true"' : ''; ?>>
            <?php echo htmlspecialchars($r['terminal_pas']); ?>
          </td>

          <td>
            <?php echo htmlspecialchars($r['nama_outlet']); ?>
          </td>

          <td>
            <?php echo htmlspecialchars($r['nama_terminal']); ?>
          </td>

          <td class="cell-edit nowrap" data-col="masa_berlaku_pas" <?php echo $isEditor ? 'contenteditable="true"' : ''; ?>>
            <?php echo htmlspecialchars($r['masa_berlaku_pas_fmt']); ?>
            <div style="margin-top:6px;"><?php echo $badge; ?></div>
          </td>

          <td class="col-foto">
            <img
              src="<?php echo htmlspecialchars($fotoShow); ?>"
              class="foto"
              alt="Foto PAS"
              onclick="openModal('<?php echo htmlspecialchars($fotoShow, ENT_QUOTES); ?>','<?php echo htmlspecialchars($r['id'], ENT_QUOTES); ?>')"
              onerror="this.onerror=null; this.src='<?php echo htmlspecialchars($defaultAvatar, ENT_QUOTES); ?>';"
            >
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>

  <?php if($isEditor): ?>
    <div class="small-note">
      Klik cell <b>Nama PT / Terminal PAS / Masa Berlaku</b>, lalu ketik, lalu tekan <b>Enter</b> untuk simpan.
      <br>Format tanggal: <b>YYYY-MM-DD</b>. Kosongkan tanggal lalu Enter untuk set NULL.
      <br><b>Outlet / Penempatan</b> dan <b>Terminal Kerja</b> otomatis ikut dari data mutasi / update di <b>karyawan.php</b>.
    </div>
  <?php endif; ?>
</div>

<div class="modal" id="modalFoto">
  <div class="modal-card">
    <img id="modalImg" alt="Foto PAS">
    <div class="modal-actions">
      <button class="btn-download" onclick="downloadFoto()">Unduh</button>
      <?php if($isEditor): ?>
        <button class="btn-ganti" onclick="gantiFoto()">Ganti Foto</button>
      <?php endif; ?>
      <button class="btn-close" onclick="closeModal()">Tutup</button>
    </div>
  </div>
</div>

<input type="file" id="fileGanti" hidden accept=".jpg,.jpeg,.png,.webp">

<script>
const isEditor = <?php echo $isEditor ? "true" : "false"; ?>;
let currentSrc = "", currentId = "";
const DEFAULT_AVATAR = <?php echo json_encode($defaultAvatar); ?>;

function openModal(src, id){
  currentSrc = src || DEFAULT_AVATAR;
  currentId = id || "";
  document.getElementById("modalImg").src = currentSrc;
  document.getElementById("modalFoto").style.display = "flex";
}
function closeModal(){
  document.getElementById("modalFoto").style.display = "none";
}
function downloadFoto(){
  const a = document.createElement("a");
  a.href = currentSrc || DEFAULT_AVATAR;
  a.download = "foto_pas_" + (currentId || "karyawan") + ".jpg";
  document.body.appendChild(a);
  a.click();
  a.remove();
}

function gantiFoto(){
  if(!isEditor) return;
  Swal.fire({
    title:'Ganti foto PAS?',
    text:'Pilih gambar jpg/png/webp',
    icon:'question',
    showCancelButton:true
  }).then(r=>{
    if(r.isConfirmed) document.getElementById("fileGanti").click();
  });
}

document.getElementById("fileGanti").onchange = ()=>{
  if(!isEditor) return;

  const file = document.getElementById("fileGanti").files[0];
  if(!file) return;

  const fd = new FormData();
  fd.append("ajax","update_foto");
  fd.append("id", currentId);
  fd.append("foto", file);

  fetch("pasbandara.php", {method:"POST", body:fd})
    .then(async r=>{
      const text = await r.text();
      let data;
      try{ data = JSON.parse(text); }catch(e){ throw new Error("Server tidak balikin JSON:\n"+text); }
      if(!data.ok) throw new Error(data.msg || "Gagal update foto");
      return data;
    })
    .then(data=>{
      const foto = data.foto || DEFAULT_AVATAR;

      document.getElementById("modalImg").src = foto;
      currentSrc = foto;

      const tr = document.querySelector(`tr[data-id="${CSS.escape(currentId)}"]`);
      if(tr){
        const img = tr.querySelector("img.foto");
        if(img) img.src = foto;
      }

      Swal.fire({icon:'success',title:'Berhasil',text:'Foto diperbarui',timer:1400,showConfirmButton:false});
    })
    .catch(err=>{
      Swal.fire({icon:'error',title:'Error',text:err.message});
    });
};

// INLINE EDIT
if(isEditor){
  document.querySelectorAll(".cell-edit[contenteditable='true']").forEach(td=>{
    td.addEventListener("focus", ()=>{
      td.dataset.before = (td.innerText || "").trim();
    });

    td.addEventListener("keydown", (e)=>{
      if(e.key === "Enter"){
        e.preventDefault();
        td.blur();
      }
      if(e.key === "Escape"){
        td.innerText = td.dataset.before || td.innerText;
        td.blur();
      }
    });

    td.addEventListener("blur", ()=>{
      const before = (td.dataset.before || "").trim();
      const rawText = (td.innerText || "").trim();

      let after = rawText;

      if(td.dataset.col === "masa_berlaku_pas"){
        after = rawText.split('\n')[0].trim();
      }

      if(after === before) return;

      const tr = td.closest("tr");
      const id = tr ? tr.getAttribute("data-id") : "";
      const col = td.getAttribute("data-col");

      const fd = new FormData();
      fd.append("ajax","inline_update");
      fd.append("id", id);
      fd.append("col", col);
      fd.append("val", after);

      fetch("pasbandara.php", {method:"POST", body:fd})
        .then(async r=>{
          const text = await r.text();
          let data;
          try{ data = JSON.parse(text); }catch(e){ throw new Error("Server tidak balikin JSON:\n"+text); }
          if(!data.ok) throw new Error(data.msg || "Update gagal");
          return data;
        })
        .then(()=>{
          td.dataset.before = after;
          Swal.fire({
            toast:true,
            position:'top-end',
            timer:1400,
            showConfirmButton:false,
            icon:'success',
            title:'Tersimpan'
          });
          setTimeout(()=>location.reload(), 500);
        })
        .catch(err=>{
          location.reload();
          Swal.fire({icon:'error',title:'Error',text:err.message});
        });
    });
  });
}

// Close modal
document.addEventListener("keydown", e=>{
  if(e.key === "Escape") closeModal();
});
document.getElementById("modalFoto").addEventListener("click", e=>{
  if(e.target.id === "modalFoto") closeModal();
});

// ALERT PAS EXPIRE <= 40 hari
const alertPas = <?php echo json_encode($alertData); ?>;
if(alertPas.length > 0){
  let html = '<ul style="text-align:left;padding-left:20px;margin:0">';
  alertPas.forEach(d=>{
    html += `<li style="margin:0 0 10px 0">
      <b>${escapeHtml(d.nama_lengkap)}</b><br>
      Outlet / Penempatan: <b>${escapeHtml(d.nama_outlet)}</b><br>
      Terminal Kerja: <b>${escapeHtml(d.nama_terminal)}</b><br>
      Masa berlaku: <b>${escapeHtml(d.masa_berlaku_pas)}</b> | sisa <b>${d.sisa_hari} hari</b>
    </li>`;
  });
  html += '</ul>';

  Swal.fire({
    icon:'warning',
    title:'Peringatan Masa Berlaku PAS',
    html: html,
    confirmButtonText:'Mengerti',
    width: 700
  });
}

function escapeHtml(s){
  return String(s ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#039;');
}
</script>

</body>
</html>
