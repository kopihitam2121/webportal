<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

/* ===== WAJIB LOGIN ===== */
if (!isset($_SESSION['username']) || $_SESSION['username'] == '') {
    header('Location: login.php');
    exit;
}

/* ===== KONEKSI UTAMA WEBPORTAL ===== */
require_once __DIR__ . '/db.php';

/* ===== MAP TOKO ===== */
require_once __DIR__ . '/map.php';

/* ===== CEK KONEKSI DB UTAMA ===== */
if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Koneksi database utama ($conn) dari db.php tidak ditemukan.');
}

$username = strtolower(trim($_SESSION['username'] ?? ''));

/* ================= USER BOLEH AKSES HALAMAN INI ================= */
$allowed_cashopname_users = [
    'wira',
    'umam',
    'ujang',
    'mustaqim',
    'prengkuh'
];

if (!in_array($username, $allowed_cashopname_users)) {
    echo "<script>alert('Akses ditolak');window.location='dashboard.php';</script>";
    exit;
}

/* ================= FULL ACCESS USERS ================= */
$full_access_users = [
    'wira',
    'wahid',
    'prengkuh',
    'yan',
    'putri',
    'azik',
    'zahra',
    'alfia'
];

/* ================= RBAC USER -> TOKO ================= */
$user_access = [
    'ujang' => [
        "Papi Mart T2 E3",
        "Papi Mart T2 E4",
        "Papi Mart T2 E5",
        "Papi Mart T2 E5 NEW DB",
        "Ambil Bekal Yuk D2",
        "Ambil Bekal Yuk D6",
        "Point One D1",
        "Point One D3",
        "Point One D5",
        "Point One D7",
        "Papi Mart Gate 18",
        "M Mart",
        "URBAN B4",
        "Papi Coffee T1B",
        "URBAN B6",
        "URBAN B7",
        "Latte Story T1C",
        "Latte Story T2E",
        "Latte story T2F"
  

    ],

    'admin2' => [
        "Papi Mart T2 E3",
        "Papi Mart T2 E4",
        "Papi Mart T2 E5",
        "Ambil Bekal Yuk D2",
        "Ambil Bekal Yuk D6",
        "Point One D1",
        "Point One D3",
        "Point One D5",
        "Point One D7"
    ],

    'mustaqim' => [
        "URBAN B4",
        "Papi Coffee T1B",
        "URBAN B6",
        "URBAN B7",
        "Latte Story T1C",
        "Latte Story T2E",
        "Latte story T2F"
    ],

    'umam' => [
        "Latte Story T1C",
        "Latte Story T2E",
        "Latte story T2F"
    ]
];

/* ================= FILTER TOKO SESUAI USER ================= */
$map_filtered = [];

if (in_array($username, $full_access_users)) {
    $map_filtered = $map;
} elseif (isset($user_access[$username])) {
    foreach ($user_access[$username] as $allowed_store) {
        if (isset($map[$allowed_store])) {
            $map_filtered[$allowed_store] = $map[$allowed_store];
        }
    }
}

/* kalau hasil filter kosong, tolak */
if (empty($map_filtered)) {
    echo "<script>alert('Anda tidak memiliki akses toko untuk Cash Opname');window.location='dashboard.php';</script>";
    exit;
}

/*
|--------------------------------------------------------------------------
| DEFAULT
|--------------------------------------------------------------------------
*/
$tanggal  = $_POST['tanggal'] ?? date('Y-m-d');
$shift    = $_POST['shift'] ?? '';
$fisik    = $_POST['fisik'] ?? '';
$namaToko = $_POST['db_index'] ?? '';

$jam_awal = '';
$jam_akhir = '';
$namaShift = '';

$system = 0;
$selisih = 0;
$keterangan = '';
$selisihDisplay = 'Rp 0';

$errorMsg = '';
$successMsg = '';
$showResult = false;

/*
|--------------------------------------------------------------------------
| VALIDASI TOKO POST
|--------------------------------------------------------------------------
*/
if ($namaToko !== '' && !isset($map_filtered[$namaToko])) {
    $errorMsg = 'Anda tidak punya akses ke toko tersebut.';
    $namaToko = '';
}

/*
|--------------------------------------------------------------------------
| SET SHIFT
|--------------------------------------------------------------------------
*/
if ($shift === '1') {
    $jam_awal = '00:00:00';
    $jam_akhir = '07:59:59';
    $namaShift = 'Shift 1';
} elseif ($shift === '2') {
    $jam_awal = '08:00:00';
    $jam_akhir = '15:59:59';
    $namaShift = 'Shift 2';
} elseif ($shift === '3') {
    $jam_awal = '16:00:00';
    $jam_akhir = '23:59:59';
    $namaShift = 'Shift 3';
}

/*
|--------------------------------------------------------------------------
| PROSES SUBMIT
|--------------------------------------------------------------------------
*/
if (isset($_POST['submit'])) {
    $showResult = true;

    if ($namaToko === '') {
        $errorMsg = 'Silakan pilih toko dulu.';
    } elseif (!isset($map_filtered[$namaToko])) {
        $errorMsg = 'Anda tidak punya akses ke toko tersebut.';
    } elseif ($tanggal === '') {
        $errorMsg = 'Tanggal wajib dipilih.';
    } elseif ($shift === '') {
        $errorMsg = 'Shift wajib dipilih.';
    } elseif ($fisik === '') {
        $errorMsg = 'Fisik (Tunai + Cashless) wajib diisi.';
    } else {
        $config = $map_filtered[$namaToko];

        $mysqli_toko = @mysqli_connect(
            $config['ip'],
            $config['user'],
            $config['pass'],
            $config['db']
        );

        if (!$mysqli_toko) {
            $errorMsg = 'Toko sedang offline / tidak bisa konek: ' . mysqli_connect_error();
        } else {
            $tanggal_safe   = $mysqli_toko->real_escape_string($tanggal);
            $jam_awal_safe  = $mysqli_toko->real_escape_string($jam_awal);
            $jam_akhir_safe = $mysqli_toko->real_escape_string($jam_akhir);

            $querySystem = "SELECT COALESCE(SUM(d.netamount), 0) AS total_system
                            FROM salesdetail d
                            JOIN salespayments s ON s.salesid = d.salesid
                            WHERE DATE(s.transdate) = '$tanggal_safe'
                              AND TIME(s.transdate) BETWEEN '$jam_awal_safe' AND '$jam_akhir_safe'";

            $resSystem = $mysqli_toko->query($querySystem);

            if ($resSystem) {
                $rowSystem = $resSystem->fetch_assoc();
                $system = (float)($rowSystem['total_system'] ?? 0);
            } else {
                $errorMsg = 'Query system gagal: ' . $mysqli_toko->error;
            }

            $mysqli_toko->close();

            if ($errorMsg === '') {
                $fisik = (float)str_replace(',', '', $fisik);
                $selisih = $fisik - $system;

                if ($selisih < 0) {
                    $keterangan = 'Minus';
                    $selisihDisplay = '(-) Rp ' . number_format(abs($selisih), 0, ',', '.');
                } elseif ($selisih > 0) {
                    $keterangan = 'Over';
                    $selisihDisplay = '(+) Rp ' . number_format(abs($selisih), 0, ',', '.');
                } else {
                    $keterangan = 'Sesuai';
                    $selisihDisplay = 'Rp 0';
                }

                /* ===== SIMPAN HISTORY SELALU INSERT ===== */
                $username_safe   = $conn->real_escape_string($username);
                $toko_safe       = $conn->real_escape_string($namaToko);
                $tanggal_safe_db = $conn->real_escape_string($tanggal);
                $shift_safe      = $conn->real_escape_string($namaShift);
                $keterangan_safe = $conn->real_escape_string($keterangan);

                $insert = "INSERT INTO hasil_co
                            (username, toko, tanggal, shift, nominal_system, fisik, selisih, keterangan)
                           VALUES
                            ('$username_safe', '$toko_safe', '$tanggal_safe_db', '$shift_safe', '$system', '$fisik', '$selisih', '$keterangan_safe')";

                if (!$conn->query($insert)) {
                    $errorMsg = 'Gagal simpan history: ' . $conn->error;
                } else {
                    $successMsg = 'Data cash opname berhasil disimpan ke history.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Opname</title>
    <link rel="icon" type="image/png" href="img/srt2.png" />
    <style>
        :root{
            --bg:#0a0f1f;
            --card:rgba(18,24,46,.82);
            --card-strong:rgba(20,28,58,.96);
            --border:rgba(255,255,255,.08);
            --text:#eef2ff;
            --muted:#96a2c7;
            --primary:#6ea8fe;
            --primary2:#4f8cff;
            --success:#34d399;
            --danger:#ff5d73;
            --info:#7dd3fc;
            --shadow:0 20px 60px rgba(0,0,0,.35);
        }
        *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
        body{
            margin:0;padding:16px;color:var(--text);
            font-family:Inter,Arial,Helvetica,sans-serif;
            background:
            radial-gradient(circle at top left, rgba(79,140,255,.18), transparent 28%),
            radial-gradient(circle at bottom right, rgba(52,211,153,.10), transparent 22%),
            linear-gradient(135deg,#060914 0%,#0b1020 45%,#101733 100%);
        }
        .container{max-width:1100px;margin:0 auto}
        .hero,.panel,.summary-card{
            border:1px solid var(--border);
            border-radius:24px;
            box-shadow:var(--shadow);
        }
        .hero{
            margin-bottom:18px;padding:20px;
            background:linear-gradient(135deg, rgba(110,168,254,.13), rgba(52,211,153,.08));
        }
        .hero-title{margin:0;font-size:clamp(24px,3vw,36px);font-weight:800}
        .hero-subtitle{margin-top:8px;color:var(--muted);font-size:14px}
        .panel{background:var(--card);overflow:hidden}
        .panel-header{padding:22px 22px 0}
        .panel-title{margin:0;font-size:21px;font-weight:800}
        .panel-body{padding:22px}
        .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
        .form-group{display:flex;flex-direction:column;gap:8px}
        .form-group.full{grid-column:1/-1}
        label{font-size:13px;font-weight:700;color:#dce6ff}
        .input-wrap{position:relative}
        input,select{
            width:100%;height:54px;border:1px solid rgba(255,255,255,.10);
            border-radius:16px;background:rgba(255,255,255,.05);color:var(--text);
            padding:0 16px;outline:none;font-size:15px;appearance:none
        }
        input:focus,select:focus{
            border-color:rgba(110,168,254,.78);
            box-shadow:0 0 0 4px rgba(110,168,254,.14)
        }
        select option{color:#111827}
        .select-arrow{position:absolute;right:16px;top:50%;transform:translateY(-50%);pointer-events:none;color:#a3aed0;font-size:12px}
        .shift-note{font-size:12px;color:var(--muted)}
        .action-row{margin-top:20px;display:flex;gap:12px;flex-wrap:wrap}
        .btn{
            height:52px;padding:0 22px;border-radius:16px;border:none;cursor:pointer;
            font-size:15px;font-weight:800
        }
        .btn-primary{color:#fff;background:linear-gradient(135deg,var(--primary),var(--primary2))}
        .btn-secondary{color:var(--text);background:rgba(255,255,255,.06);border:1px solid var(--border)}
        .alert{margin-top:16px;padding:14px 16px;border-radius:16px;font-size:14px}
        .alert-danger{background:rgba(255,93,115,.10);border:1px solid rgba(255,93,115,.25);color:#ffc7cf}
        .alert-success{background:rgba(52,211,153,.10);border:1px solid rgba(52,211,153,.25);color:#c6f7e2}
        .result-wrap{margin-top:18px}
        .summary-card{background:var(--card-strong);overflow:hidden}
        .summary-top{
            padding:22px;border-bottom:1px solid rgba(255,255,255,.06);
            display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap
        }
        .summary-title{margin:0;font-size:20px;font-weight:800}
        .summary-badge{
            padding:9px 14px;border-radius:999px;font-size:13px;font-weight:800;
            border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.05)
        }
        .summary-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;padding:22px}
        .info-card{
            border-radius:20px;border:1px solid rgba(255,255,255,.06);
            background:linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02));
            padding:18px;min-height:110px
        }
        .info-label{
            margin-bottom:10px;font-size:12px;font-weight:800;color:var(--muted);
            text-transform:uppercase;letter-spacing:.9px
        }
        .info-value{font-size:clamp(18px,2vw,24px);font-weight:800;color:#fff;line-height:1.35}
        .info-value.sm{font-size:18px}
        .status-text{font-size:24px;font-weight:900}
        .status-minus{color:var(--danger)}
        .status-over{color:var(--success)}
        .status-sesuai{color:var(--info)}
        .footer-note{
            padding:16px 22px 20px;text-align:center;color:var(--muted);font-size:13px;
            border-top:1px solid rgba(255,255,255,.06)
        }
        @media (max-width:768px){
            .form-grid,.summary-grid{grid-template-columns:1fr}
            .btn{width:100%}
            input,select{font-size:16px}
        }
    </style>
</head>
<body>
<div class="container">

    <section class="hero">
        <h1 class="hero-title">Cash Opname</h1>
        <div class="hero-subtitle">
            User login: <strong><?= htmlspecialchars($username) ?></strong>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <h2 class="panel-title">Form Input</h2>
        </div>

        <div class="panel-body">
            <form method="post">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Pilih Toko</label>
                        <div class="input-wrap">
                            <select name="db_index" required>
                                <option value="">-- Pilih Toko --</option>
                                <?php foreach($map_filtered as $store => $cfg): ?>
                                    <option value="<?= htmlspecialchars($store) ?>" <?= ($namaToko === $store ? 'selected' : '') ?>>
                                        <?= htmlspecialchars($store) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="select-arrow"></span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Tanggal</label>
                        <div class="input-wrap">
                            <input type="date" name="tanggal" value="<?= htmlspecialchars($tanggal) ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Shift</label>
                        <div class="input-wrap">
                            <select name="shift" required>
                                <option value="">-- Pilih Shift --</option>
                                <option value="1" <?= ($shift === '1' ? 'selected' : '') ?>>Shift 1</option>
                                <option value="2" <?= ($shift === '2' ? 'selected' : '') ?>>Shift 2</option>
                                <option value="3" <?= ($shift === '3' ? 'selected' : '') ?>>Shift 3</option>
                            </select>
                            <span class="select-arrow"></span>
                        </div>
                        <div class="shift-note">Shift 1 (00:00 - 08:00), Shift 2 (08:00 - 16:00), Shift 3 (16:00 - 00:00)</div>
                    </div>

                    <div class="form-group full">
                        <label>Fisik (Tunai + Cashless)</label>
                        <div class="input-wrap">
                            <input type="number" step="0.01" name="fisik" placeholder="Masukkan total fisik" value="<?= htmlspecialchars($_POST['fisik'] ?? '') ?>" required>
                        </div>
                    </div>
                </div>

                <div class="action-row">
                    <button type="submit" name="submit" class="btn btn-primary">Simpan / Proses</button>
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='cash_opname.php'">Reset</button>
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='Hasil_CO.php'">History</button>
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='dashboard.php'">Kembali</button>
                </div>
            </form>

            <?php if ($errorMsg !== ''): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
            <?php endif; ?>

            <?php if ($successMsg !== '' && $errorMsg === ''): ?>
                <div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($showResult && $errorMsg === ''): ?>
        <section class="result-wrap">
            <div class="summary-card">
                <div class="summary-top">
                    <h3 class="summary-title">Hasil Cash Opname</h3>
                    <div class="summary-badge"><?= htmlspecialchars($namaShift) ?></div>
                </div>

                <div class="summary-grid">
                    <div class="info-card">
                        <div class="info-label">Toko</div>
                        <div class="info-value sm"><?= htmlspecialchars($namaToko) ?></div>
                    </div>

                    <div class="info-card">
                        <div class="info-label">Tanggal</div>
                        <div class="info-value sm"><?= date('d M Y', strtotime($tanggal)) ?></div>
                    </div>

                    <div class="info-card">
                        <div class="info-label">Shift</div>
                        <div class="info-value sm"><?= htmlspecialchars($namaShift) ?></div>
                    </div>

                    <div class="info-card">
                        <div class="info-label">Nominal System</div>
                        <div class="info-value">Rp <?= number_format($system, 0, ',', '.') ?></div>
                    </div>

                    <div class="info-card">
                        <div class="info-label">Fisik (Tunai + Cashless)</div>
                        <div class="info-value">Rp <?= number_format((float)$fisik, 0, ',', '.') ?></div>
                    </div>

                    <div class="info-card">
                        <div class="info-label">Selisih</div>
                        <div class="info-value"><?= htmlspecialchars($selisihDisplay) ?></div>
                    </div>

                    <div class="info-card">
                        <div class="info-label">Keterangan</div>
                        <div class="status-text <?=
                            $keterangan === 'Minus' ? 'status-minus' :
                            ($keterangan === 'Over' ? 'status-over' : 'status-sesuai')
                        ?>">
                            <?= htmlspecialchars($keterangan) ?>
                        </div>
                    </div>
                </div>

                <div class="footer-note">data yang ditampilkan adalah valid system</div>
            </div>
        </section>
    <?php endif; ?>

</div>
</body>
</html>
