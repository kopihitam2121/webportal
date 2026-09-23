<?php
session_start();

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Grafik</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">

    <!-- ICON LIBRARY -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        * {margin:0; padding:0; box-sizing:border-box; font-family:'Poppins', sans-serif;}

        body {
            background: linear-gradient(135deg,#0f172a,#1e293b,#020617);
            color:white;
            padding:40px;
        }

        .container {
            max-width:1200px;
            margin:auto;
        }

        .top-bar {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:40px;
        }

        h1 {font-size:28px;}

        .btn-back {
            background: linear-gradient(135deg,#3b82f6,#2563eb);
            padding:10px 18px;
            border-radius:10px;
            text-decoration:none;
            color:white;
            font-weight:600;
            box-shadow:0 5px 15px rgba(37,99,235,0.4);
            transition:0.3s;
        }

        .btn-back:hover {transform:scale(1.05);}

        .grid {
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
            gap:25px;
        }

        .card {
            position:relative;
            padding:30px;
            border-radius:20px;
            color:white;
            overflow:hidden;
            transition:0.4s;
        }

        .card::before {
            content:"";
            position:absolute;
            inset:0;
            background:linear-gradient(120deg,transparent,rgba(255,255,255,0.2),transparent);
            opacity:0;
            transition:0.4s;
        }

        .card:hover::before {
            opacity:1;
            transform:translateX(100%);
        }

        .card:hover {
            transform:translateY(-10px) scale(1.02);
        }

        .card h2 {
            margin-bottom:10px;
            font-size:20px;
        }

        .card p {
            font-size:14px;
            opacity:0.9;
        }

        .btn {
            display:inline-block;
            margin-top:20px;
            padding:10px 16px;
            border-radius:10px;
            text-decoration:none;
            color:white;
            font-weight:600;
            background:rgba(255,255,255,0.2);
            backdrop-filter:blur(6px);
            transition:0.3s;
        }

        .btn:hover {
            background:rgba(255,255,255,0.35);
        }

        /* warna card */
        .card1 {background: linear-gradient(135deg,#22c55e,#16a34a);} 
        .card2 {background: linear-gradient(135deg,#3b82f6,#1d4ed8);} 
        .card3 {background: linear-gradient(135deg,#f59e0b,#d97706);} 

        .icon {
            font-size:40px;
            margin-bottom:15px;
        }

    </style>
</head>
<body>

<div class="container">

    <div class="top-bar">
        <h1>Dashboard Grafik</h1>
        <a href="dashboard.php" class="btn-back">
            <i class="fa-solid fa-arrow-left"></i> Kembali
        </a>
    </div>

    <div class="grid">

        <div class="card card1">
            <div class="icon">
                <i class="fa-solid fa-chart-line"></i>
            </div>
            <h2>Grafik Tahunan</h2>
            <p>Analisa performa penjualan tahunan secara visual</p>
            <a href="grafik_sales.php" class="btn">Buka</a>
        </div>

        <div class="card card2">
            <div class="icon">
                <i class="fa-solid fa-store"></i>
            </div>
            <h2>Grafik By Store</h2>
            <p>Bandingkan performa tiap outlet dengan mudah</p>
            <a href="detail_outlet.php" class="btn">Buka</a>
        </div>

        <div class="card card3">
            <div class="icon">
                <i class="fa-solid fa-users"></i>
            </div>
            <h2>Grafik By Tenant</h2>
            <p>Lihat kontribusi tenant dalam bentuk grafik</p>
            <a href="chart_day.php" class="btn">Buka</a>
        </div>

    </div>

</div>

</body>
</html>
