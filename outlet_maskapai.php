<?php
session_start();

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$outlets = [
    [
        'outlet' => 'Papi Mart 1 T2E3',
        'area' => 'Terminal 2 - Gate E3',
        'maskapai' => 'Super Air Jet',
        'spv' => 'UJANG FIRMANSYAH',
        'leader' => 'HARIS FADHILAH',
        'tujuan' => ['Jambi', 'Pekanbaru', 'Padang', 'Batam'],
    ],
    [
        'outlet' => 'Papi Mart 2 T2E4',
        'area' => 'Terminal 2 - Gate E4',
        'maskapai' => 'Super Air Jet',
        'spv' => 'UJANG FIRMANSYAH',
        'leader' => 'HARIS FADHILAH',
        'tujuan' => ['Denpasar', 'Pekanbaru', 'Kediri', 'Pontianak', 'Palembang'],
    ],
    [
        'outlet' => 'Papi Mart 3 T2E5',
        'area' => 'Terminal 2 - Gate E5',
        'maskapai' => 'Super Air Jet',
        'spv' => 'UJANG FIRMANSYAH',
        'leader' => 'HARIS FADHILAH',
        'tujuan' => ['Pontianak', 'Palangkaraya', 'Palembang', 'Padang'],
    ],
    [
        'outlet' => 'Ambil Bekal Yuk T2D2',
        'area' => 'Terminal 2 - Gate D2',
        'maskapai' => 'Batik Air',
        'spv' => 'UJANG FIRMANSYAH',
        'leader' => 'DEDE HIDAYAT',
        'tujuan' => ['Pontianak', 'New Yogyakarta', 'Solo', 'Lombok', 'Malang', 'Bengkulu'],
    ],
    [
        'outlet' => 'Ambil Bekal Yuk T2D6',
        'area' => 'Terminal 2 - Gate D6',
        'maskapai' => 'Batik Air',
        'spv' => 'UJANG FIRMANSYAH',
        'leader' => 'DEDE HIDAYAT',
        'tujuan' => ['Samarinda', 'Denpasar', 'Solo', 'Pekanbaru', 'Banjarmasin', 'Semarang'],
    ],
    [
        'outlet' => 'Point One T2D1',
        'area' => 'Terminal 2 - Gate D1',
        'maskapai' => 'Batik Air',
        'spv' => 'UJANG FIRMANSYAH',
        'leader' => 'DEVI RETNO MINARSIH',
        'tujuan' => ['Jambi', 'Tarakan', 'Balikpapan', 'Kualanamu', 'Pontianak', 'Surabaya', 'Yogyakarta'],
    ],
    [
        'outlet' => 'Point One T2D3',
        'area' => 'Terminal 2 - Gate D3',
        'maskapai' => 'Batik Air',
        'spv' => 'UJANG FIRMANSYAH',
        'leader' => 'DEVI RETNO MINARSIH',
        'tujuan' => ['Tj. Pinang', 'Lombok', 'Malang', 'Surabaya', 'Makassar', 'Kualanamu'],
    ],
    [
        'outlet' => 'Point One T2D5',
        'area' => 'Terminal 2 - Gate D5',
        'maskapai' => 'Batik Air',
        'spv' => 'UJANG FIMANSYAH',
        'leader' => 'DEVI RETNO MINARSIH',
        'tujuan' => ['Malang', 'Batam', 'Surabaya', 'Palembang', 'Makassar', 'Solo'],
    ],
    [
        'outlet' => 'Point One T2D7',
        'area' => 'Terminal 2 - Gate D7',
        'maskapai' => 'Batik Air',
        'spv' => 'UJANG FIMANSYAH',
        'leader' => 'DEDE HIDAYAT',
        'tujuan' => ['Surabaya', 'Pekanbaru', 'Labuan Bajo', 'Padang', 'Palangkaraya', 'Semarang', 'Batam'],
    ],
    [
        'outlet' => 'URBAN T1 B4',
        'area' => 'Terminal 1 - B4',
        'maskapai' => 'Lion Air',
        'spv' => 'M.MUSTAQIM',
        'leader' => 'GREGORIUS JESEN BANFOE & LUSIAH',
        'tujuan' => ['Palembang', 'Kualanamu', 'Batam', 'Padang', 'Denpasar', 'Lampung', 'Surabaya', 'Makassar', 'Ambon', 'Lombok', 'Pekanbaru'],
    ],
    [
        'outlet' => 'URBAN B6',
        'area' => 'Terminal 1 - B6',
        'maskapai' => 'Lion Air, Sriwijaya Air, NAM Air',
        'spv' => 'M.MUSTAQIM',
        'leader' => 'GREGORIUS JESEN BANFOE & LUSIAH',
        'tujuan' => ['Makassar', 'Yogyakarta', 'Pangkalan Bun', 'Sampit', 'Denpasar', 'Pangkal Pinang', 'Muara Bungo', 'Pontianak', 'Jambi', 'Pangkal Pinang', 'Tanjung Pandan'],
    ],
    [
        'outlet' => 'PAPI COFFEE B5',
        'area' => 'Terminal 1 - B5',
        'maskapai' => 'Lion Air, Sriwijaya Air, NAM Air',
        'spv' => 'M.MUSTAQIM',
        'leader' => 'GREGORIUS JESEN BANFOE & LUSIAH',
        'tujuan' => ['Palembang', 'Kualanamu', 'Batam', 'Padang', 'Denpasar', 'Lampung', 'Surabaya', 'Makassar', 'Ambon', 'Lombok', 'Pekanbaru'],
    ],
    [
        'outlet' => 'URBAN B7',
        'area' => 'Terminal 1 - B7',
        'maskapai' => 'Tidak ada',
        'spv' => 'M.MUSTAQIM',
        'leader' => 'GREGORIUS JESEN BANFOE & LUSIAH',
        'tujuan' => ['Tidak ada jadwal penerbangan'],
    ],
    [
        'outlet' => 'PAPIMART GT18',
        'area' => 'Gate 18',
        'maskapai' => 'Garuda Indonesia, Pelita Air',
        'spv' => 'M.MUSTAQIM',
        'leader' => 'GREGORIUS JESEN BANFOE & LUSIAH',
        'tujuan' => ['Surabaya', 'Makassar', 'Palembang', 'Samarinda', 'Kendari'],
    ],
    [
        'outlet' => 'M-MART',
        'area' => 'Area Parkir',
        'maskapai' => 'Tidak ada',
        'spv' => 'M.MUSTAQIM',
        'leader' => 'M.ADITYA SEFRIYANTO',
        'tujuan' => ['Area Parkir'],
    ],
    [
        'outlet' => 'Latte Story T1C',
        'area' => 'Terminal 1 - C',
        'maskapai' => 'Citilink',
        'spv' => 'M.MUSTAQIM',
        'leader' => 'SURYANTO ARIYAWAN',
        'tujuan' => ['Ambon', 'Makassar', 'Denpasar', 'Palembang', 'Kualanamu', 'Lombok', 'Pinang (P. Pinang)', 'Malang', 'Samarinda', 'Batam'],
    ],
    
    [
        'outlet' => 'Latte Story T2F',
        'area' => 'Terminal 2F - Selasar Gate F4',
        'maskapai' => 'Batik Air, Garuda Indonesia, Indigo, Thai Lion Air, Vietjet Air, Citilink, Air Asia',
        'spv' => 'M.MUSTAQIM',
        'leader' => 'CHOTIBATUL UMAM',
        'tujuan' => ['Kuala Lumpur', 'Penang', 'Singapore', 'Porth', 'Subang', 'Mumbai', 'Don Mueang', 'Ho Chi Minh', 'Phnom Penh', 'Hanoi'],
    ],
    [
        'outlet' => 'LATTE STORY T2E',
        'area' => 'Terminal 2E - Selasar Gate E3',
        'maskapai' => 'Lion Air, SAJ, Garuda Indonesia, Pelita Air',
        'spv' => 'M.MUSTAQIM',
        'leader' => 'CHOTIBATUL UMAM',
        'tujuan' => ['Jambi', 'Pekanbaru', 'Padang', 'Batam', 'Denpasar', 'Kediri', 'Pontianak', 'Palembang', 'Palangkaraya']
            
],
[
        'outlet' => 'PAPI MART BIM',
        'area' => 'Boarding Lounge',
        'maskapai' => 'Lion Air, SAJ, Garuda Indonesia, Pelita Air',
        'spv' => 'ADRIYAN FAUZAN',
        'leader' => 'WULANDARI',
        'tujuan' => [
            'Lion Air JT353 - 06:05',
            'SAJ IU817 - 08:55',
            'SAJ IU862 - 10:30',
            'Garuda GA149 - 12:00',
            'SAJ IU186 - 12:05',
            'Pelita IP355 - 14:35',
            'Garuda GA165 - 17:15',
            'SAJ IU901 - 17:25',
            'SAJ IU907 - 18:40',
        ],
    ],
];

$totalOutlet = count($outlets);
$totalTujuan = 0;
$maskapaiCounter = [];

foreach ($outlets as $outlet) {
    $totalTujuan += count($outlet['tujuan']);
    $maskapaiList = explode(',', $outlet['maskapai']);
    foreach ($maskapaiList as $maskapai) {
        $maskapai = trim($maskapai);
        if ($maskapai !== '' && strtolower($maskapai) !== 'tidak ada') {
            $maskapaiCounter[$maskapai] = true;
        }
    }
}

$totalMaskapai = count($maskapaiCounter);
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Data Outlet Maskapai & Flight</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="icon" type="image/png" href="img/srt2.png">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    min-height: 100vh;
    font-family: Arial, sans-serif;
    color: #fff;
    background:
        radial-gradient(circle at top left, rgba(56,189,248,.22), transparent 28%),
        radial-gradient(circle at top right, rgba(99,102,241,.22), transparent 25%),
        linear-gradient(135deg, #06101f 0%, #0a1830 45%, #102343 100%);
    padding: 20px;
}

.container { max-width: 1500px; margin: auto; }

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.btn-back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 16px;
    border-radius: 12px;
    background: linear-gradient(135deg, #0d2a52, #174a8b);
    color: #fff;
    text-decoration: none;
    font-weight: bold;
    box-shadow: 0 10px 22px rgba(0,0,0,.22);
}

.user-box {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 16px;
    border-radius: 12px;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.12);
}

.hero {
    border-radius: 24px;
    padding: 28px;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.12);
    box-shadow: 0 20px 45px rgba(0,0,0,.28);
    margin-bottom: 20px;
    position: relative;
    overflow: hidden;
}

.hero::after {
    content: "";
    position: absolute;
    width: 220px;
    height: 220px;
    right: -70px;
    top: -70px;
    border-radius: 50%;
    background: rgba(255,255,255,.08);
}

.hero-title { position: relative; z-index: 1; }

.hero-title h1 {
    margin: 0 0 8px;
    font-size: 30px;
    line-height: 1.2;
}

.hero-title p {
    margin: 0;
    color: #cbd5e1;
    line-height: 1.6;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
    margin: 20px 0;
}

.summary-card {
    padding: 18px;
    border-radius: 18px;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.12);
    box-shadow: 0 12px 26px rgba(0,0,0,.18);
}

.summary-card .icon {
    width: 48px;
    height: 48px;
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #38bdf8, #6366f1);
    margin-bottom: 12px;
}

.summary-card span {
    color: #cbd5e1;
    font-size: 13px;
}

.summary-card strong {
    display: block;
    font-size: 28px;
    margin-top: 5px;
}

.toolbar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.search-box {
    flex: 1;
    min-width: 260px;
    position: relative;
}

.search-box i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
}

.search-box input {
    width: 100%;
    padding: 13px 14px 13px 42px;
    border-radius: 14px;
    border: none;
    outline: none;
    font-size: 14px;
}

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
    gap: 18px;
}

.card {
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 22px;
    padding: 20px;
    box-shadow: 0 14px 30px rgba(0,0,0,.24);
    transition: .25s ease;
    overflow: hidden;
    position: relative;
}

.card:hover {
    transform: translateY(-5px);
    border-color: rgba(56,189,248,.35);
}

.card::before {
    content: "";
    position: absolute;
    right: -35px;
    top: -35px;
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: rgba(56,189,248,.10);
}

.card-head {
    position: relative;
    display: flex;
    gap: 13px;
    align-items: flex-start;
    margin-bottom: 14px;
}

.store-icon {
    width: 48px;
    height: 48px;
    min-width: 48px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #38bdf8, #2563eb);
    box-shadow: 0 10px 22px rgba(37,99,235,.25);
    font-size: 20px;
}

.card h3 { margin: 0; font-size: 18px; }

.area {
    margin-top: 5px;
    color: #cbd5e1;
    font-size: 12px;
}

.info-row {
    position: relative;
    display: flex;
    gap: 8px;
    align-items: flex-start;
    margin: 10px 0;
    color: #e5eefc;
    font-size: 13px;
    line-height: 1.5;
}

.info-row i {
    color: #7dd3fc;
    width: 18px;
    margin-top: 2px;
}

.maskapai-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 11px;
    border-radius: 999px;
    background: rgba(34,197,94,.14);
    border: 1px solid rgba(34,197,94,.22);
    color: #bbf7d0;
    font-size: 12px;
    font-weight: bold;
    margin: 7px 0 10px;
}

.destination-wrap { margin-top: 14px; }

.destination-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: bold;
    font-size: 13px;
    margin-bottom: 10px;
}

.destination-list {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
}

.destination-chip {
    padding: 7px 10px;
    border-radius: 999px;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.10);
    font-size: 12px;
    color: #e2e8f0;
}

.empty {
    display: none;
    text-align: center;
    padding: 30px;
    border-radius: 18px;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.12);
    color: #cbd5e1;
    margin-top: 20px;
}

@media (max-width: 800px) {
    body { padding: 14px; }
    .summary-grid { grid-template-columns: 1fr; }
    .hero { padding: 20px; }
    .hero-title h1 { font-size: 23px; }
    .grid { grid-template-columns: 1fr; }
}
</style>
</head>

<body>

<div class="container">

    <div class="topbar">
        <a href="dashboard.php" class="btn-back">
            <i class="fa-solid fa-arrow-left"></i> Kembali
        </a>

        <div class="user-box">
            <i class="fa-solid fa-user"></i>
            <?= isset($_SESSION['username']) ? h($_SESSION['username']) : 'Guest' ?>
        </div>
    </div>

    <section class="hero">
        <div class="hero-title">
            <h1><i class="fa-solid fa-plane-departure"></i> Data Outlet Maskapai & Flight</h1>
            
        </div>
    </section>

    <section class="summary-grid">
        <div class="summary-card">
            <div class="icon"><i class="fa-solid fa-store"></i></div>
            <span>Total Outlet</span>
            <strong><?= (int)$totalOutlet ?></strong>
        </div>

        <div class="summary-card">
            <div class="icon"><i class="fa-solid fa-plane"></i></div>
            <span>Total Maskapai</span>
            <strong><?= (int)$totalMaskapai ?></strong>
        </div>

        <div class="summary-card">
            <div class="icon"><i class="fa-solid fa-location-dot"></i></div>
            <span>Total Tujuan / Flight</span>
            <strong><?= (int)$totalTujuan ?></strong>
        </div>
    </section>

    <div class="toolbar">
        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="searchInput" placeholder="Cari outlet, maskapai, tujuan, SPV, atau leader...">
        </div>
    </div>

    <section class="grid" id="outletGrid">
        <?php foreach ($outlets as $item): ?>
            <?php
                $searchText = strtolower(
                    $item['outlet'] . ' ' .
                    $item['area'] . ' ' .
                    $item['maskapai'] . ' ' .
                    $item['spv'] . ' ' .
                    $item['leader'] . ' ' .
                    implode(' ', $item['tujuan'])
                );
            ?>
            <article class="card outlet-card" data-search="<?= h($searchText) ?>">
                <div class="card-head">
                    <div class="store-icon">
                        <i class="fa-solid fa-store"></i>
                    </div>
                    <div>
                        <h3><?= h($item['outlet']) ?></h3>
                        <div class="area"><i class="fa-solid fa-map-pin"></i> <?= h($item['area']) ?></div>
                    </div>
                </div>

                <div class="maskapai-badge">
                    <i class="fa-solid fa-plane"></i>
                    <?= h($item['maskapai']) ?>
                </div>

                <div class="info-row">
                    <i class="fa-solid fa-user-tie"></i>
                    <div><strong>Nama SPV:</strong> <?= h($item['spv']) ?></div>
                </div>

                <div class="info-row">
                    <i class="fa-solid fa-user-group"></i>
                    <div><strong>Nama Leader:</strong> <?= h($item['leader']) ?></div>
                </div>

                <div class="destination-wrap">
                    <div class="destination-title">
                        <i class="fa-solid fa-route"></i> Tujuan Penerbangan
                    </div>

                    <div class="destination-list">
                        <?php foreach ($item['tujuan'] as $tujuan): ?>
                            <span class="destination-chip"><?= h($tujuan) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <div class="empty" id="emptyState">
        <i class="fa-solid fa-circle-info"></i> Data tidak ditemukan.
    </div>

</div>

<script>
const searchInput = document.getElementById('searchInput');
const cards = document.querySelectorAll('.outlet-card');
const emptyState = document.getElementById('emptyState');

searchInput.addEventListener('input', function () {
    const keyword = this.value.toLowerCase().trim();
    let visibleCount = 0;

    cards.forEach(card => {
        const text = card.getAttribute('data-search') || '';
        const match = text.includes(keyword);

        card.style.display = match ? '' : 'none';
        if (match) visibleCount++;
    });

    emptyState.style.display = visibleCount === 0 ? 'block' : 'none';
});
</script>

</body>
</html>
