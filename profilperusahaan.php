<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

$host = "localhost";
$user = "wira_local";
$pass = "Mtssepatan210300#";
$db   = "appsheet_db";

$conn = mysqli_connect($host, $user, $pass, $db);
if (!$conn) {
    die("Koneksi gagal: " . mysqli_connect_error());
}

// Ambil data perusahaan
$sql = "SELECT * FROM perusahaan LIMIT 1";
$result = mysqli_query($conn, $sql);
$perusahaan = mysqli_fetch_assoc($result);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=1024, initial-scale=0.5, minimum-scale=0.25, maximum-scale=5.0, user-scalable=yes">
<title>Profil Perusahaan</title>

<!-- Favicon -->
<link rel="icon" type="image/png" href="img/srt2.png" />

<!-- Font Awesome & Chart.js -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body {
      font-family: Arial, sans-serif;
      margin: 0;
      padding: 0;
      animation: bgColorChange 20s infinite alternate;
      transition: background 1s ease-in-out;
      position: relative;
    }

    @keyframes bgColorChange {
      0%   { background-color: #121212; }
      25%  { background-color: #1a1a2e; }
      50%  { background-color: #162447; }
      75%  { background-color: #1b1b2f; }
      100% { background-color: #121212; }
    }

    .profile-card {
      max-width: 700px;
      margin: 50px auto;
      padding: 30px;
      background: rgba(30,30,30,0.95);
      border-radius: 15px;
      box-shadow: 0 4px 15px rgba(0,0,0,0.5);
      text-align: center;
      color: #e0e0e0;
      transition: transform 0.3s ease;
      position: relative;
      z-index: 2;
    }

    .profile-card:hover { transform: translateY(-5px); }
    .profile-logo { max-width: 150px; margin-bottom: 20px; }
    .company-name { font-size: 1.8rem; font-weight: bold; color: #ffffff; }
    .company-division { font-size: 1.2rem; font-weight: 500; color: #0d6efd; margin-bottom: 20px; }
    hr { border-color: #444; }
    .btn-primary { background-color: #0d6efd; border: none; }
    .btn-primary:hover { background-color: #0b5ed7; }

    /* Watermark SRT halus */
    body::before {
      content: "";
      position: fixed;
      top: 0; left: 0;
      width: 100%; height: 100%;
      background-image: url('img/srt.png');
      background-repeat: repeat;
      background-size: 150px 150px;
      opacity: 0.03; /* lebih transparan */
      pointer-events: none;
      z-index: 1;
    }
  </style>
</head>
<body>

<div class="profile-card">
  <img src="img/srt.png" alt="Logo Perusahaan" class="profile-logo">
  <h2 class="company-name"><?= htmlspecialchars($perusahaan['namaperusahaan']); ?></h2>
  <h5 class="company-division"><?= htmlspecialchars($perusahaan['divisi']); ?></h5>
  <hr>
  <p><strong>Alamat:</strong> <?= htmlspecialchars($perusahaan['alamat']); ?></p>
  <p><strong>Telepon:</strong> <?= htmlspecialchars($perusahaan['notelepon']); ?></p>
  <p><strong>Email:</strong> <?= htmlspecialchars($perusahaan['email']); ?></p>
  <a href="dashboard.php" class="btn btn-primary mt-3">Kembali ke Dashboard</a>
</div>

</body>
</html>
