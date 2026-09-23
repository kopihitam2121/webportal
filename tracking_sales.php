<?php
/* BUILD: TRACKING-SALES-JOIN-SALESDETAILCOPY-20260806 */
session_start();
date_default_timezone_set('Asia/Jakarta');

/* ==== BATAS AKSES ==== */
$allowed_users = ['wira','wahid','yan','mustaqim','haris','ujang','cindy','prengkuh','zahra','alfia','azik','aca','muslih','admin1','whina','ratna'];
$username = isset($_SESSION['username']) ? (string)$_SESSION['username'] : '';

if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

if (!in_array(strtoupper($username), array_map('strtoupper', $allowed_users), true)) {
    echo "<!DOCTYPE html>
    <html lang='id'>
    <head>
        <meta charset='UTF-8'>
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    </head>
    <body>
    <script>
        Swal.fire({
            icon: 'error',
            title: 'Akses Ditolak',
            html: 'Anda tidak memiliki akses ke halaman ini.',
            allowOutsideClick: false
        }).then(function(){ window.location.href='dashboard.php'; });
    </script>
    </body>
    </html>";
    exit;
}

function tracking_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tracking_valid_date($value)
{
    $value = trim((string)$value);
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
}

function tracking_table_exists(mysqli $db, $table)
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$table)) {
        return false;
    }

    $sql = "SELECT 1 FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();

    return $exists;
}

function tracking_table_columns(mysqli $db, $table)
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$table)) {
        return [];
    }

    $result = $db->query('SHOW COLUMNS FROM `' . $table . '`');
    if (!$result) {
        return [];
    }

    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $field = trim((string)($row['Field'] ?? ''));
        if ($field !== '') {
            $columns[strtolower($field)] = $field;
        }
    }
    $result->free();

    return $columns;
}

function tracking_pick_column(array $columns, array $candidates)
{
    foreach ($candidates as $candidate) {
        $key = strtolower((string)$candidate);
        if (isset($columns[$key])) {
            return $columns[$key];
        }
    }
    return null;
}

function tracking_qi($identifier)
{
    return '`' . str_replace('`', '``', (string)$identifier) . '`';
}

function tracking_bind_params(mysqli_stmt $stmt, $types, array &$values)
{
    $refs = [];
    $refs[] = &$types;
    foreach ($values as $key => $value) {
        $refs[] = &$values[$key];
    }
    return call_user_func_array([$stmt, 'bind_param'], $refs);
}

$dataJam = [];
$dataJumlah = [];
$dataTotalNominal = [];
$tanggal_input = date('Y-m-d');
$totalTransaksi = 0;
$totalNominal = 0;
$namaToko = '';

/* ===== MAP TOKO ===== */
require_once __DIR__ . '/map.php';

if (!isset($map) || !is_array($map)) {
    die('File map.php tidak valid atau variabel $map tidak ditemukan.');
}

/* ==== PROSES FORM ==== */
$offline_error = '';
$query_error = '';
$source_info = '';

if (isset($_POST['submit'])) {
    $selected_label = trim((string)($_POST['db_index'] ?? ''));
    $tgl = trim((string)($_POST['tanggal'] ?? ''));

    if (!tracking_valid_date($tgl)) {
        $query_error = 'Format tanggal tidak valid.';
    } elseif (!isset($map[$selected_label])) {
        $query_error = 'Database tidak dikenali.';
    } else {
        $tanggal_input = $tgl;
        $config = $map[$selected_label];
        $namaToko = $selected_label;

        mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = mysqli_init();
        if ($mysqli) {
            @mysqli_options($mysqli, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
            if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
                @mysqli_options($mysqli, MYSQLI_OPT_READ_TIMEOUT, 20);
            }
        }

        $port = isset($config['port']) ? (int)$config['port'] : 3306;
        $connected = $mysqli && @mysqli_real_connect(
            $mysqli,
            (string)($config['ip'] ?? ''),
            (string)($config['user'] ?? ''),
            (string)($config['pass'] ?? ''),
            (string)($config['db'] ?? ''),
            $port
        );

        if (!$connected) {
            $offline_error = mysqli_connect_error() ?: 'Koneksi database toko gagal.';
            if ($mysqli) {
                @mysqli_close($mysqli);
            }
        } else {
            @mysqli_set_charset($mysqli, 'utf8mb4');

            $dataJam = $dataJumlah = $dataTotalNominal = [];
            for ($h = 0; $h < 24; $h++) {
                $hour = str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':00';
                $dataJam[$hour] = 0;
                $dataJumlah[$hour] = 0;
                $dataTotalNominal[$hour] = 0;
            }

            $startDateTime = $tgl . ' 00:00:00';
            $endDateTime = (new DateTime($tgl))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

            /*
             * Data digabung pada level SALESID supaya tidak terjadi perkalian baris:
             * - salespayments bisa memiliki beberapa metode pembayaran per faktur.
             * - salesdetailcopy bisa memiliki beberapa produk per faktur.
             *
             * Nilai salespayments diprioritaskan bila tersedia dan tidak nol.
             * Jika faktur tidak memiliki nominal pembayaran, nilai salesdetailcopy dipakai.
             * Faktur yang hanya terdapat di salesdetailcopy tetap ikut tampil.
             */
            $sourceParts = [];
            $bindValues = [];
            $bindTypes = '';
            $sourceNames = [];

            if (tracking_table_exists($mysqli, 'salespayments')) {
                $paymentColumns = tracking_table_columns($mysqli, 'salespayments');
                $paymentSalesId = tracking_pick_column($paymentColumns, ['salesid', 'invoiceid', 'invoice']);
                $paymentDate = tracking_pick_column($paymentColumns, ['transdate', 'paymentdate', 'created_at']);
                $paymentValue = tracking_pick_column($paymentColumns, ['debit', 'amount', 'paymentamount', 'nominal']);

                if ($paymentSalesId && $paymentDate && $paymentValue) {
                    $sourceParts[] = "
                        SELECT
                            CAST(sp." . tracking_qi($paymentSalesId) . " AS CHAR) AS salesid,
                            sp." . tracking_qi($paymentDate) . " AS transdate,
                            COALESCE(sp." . tracking_qi($paymentValue) . ", 0) AS nominal,
                            1 AS source_priority
                        FROM `salespayments` sp
                        WHERE sp." . tracking_qi($paymentDate) . " >= ?
                          AND sp." . tracking_qi($paymentDate) . " < ?
                    ";
                    $bindTypes .= 'ss';
                    $bindValues[] = $startDateTime;
                    $bindValues[] = $endDateTime;
                    $sourceNames[] = 'salespayments';
                }
            }

            if (tracking_table_exists($mysqli, 'salesdetailcopy')) {
                $detailColumns = tracking_table_columns($mysqli, 'salesdetailcopy');
                $detailSalesId = tracking_pick_column($detailColumns, ['salesid', 'invoiceid', 'invoice']);
                $detailDate = tracking_pick_column($detailColumns, ['transdate', 'salesdate', 'created_at']);
                $netAmount = tracking_pick_column($detailColumns, ['netamount']);
                $grossAmount = tracking_pick_column($detailColumns, ['grossamount']);
                $qtyColumn = tracking_pick_column($detailColumns, ['salesqty', 'qty', 'quantity']);
                $priceColumn = tracking_pick_column($detailColumns, ['price', 'salesprice', 'unitprice']);

                $detailValueExpression = '';
                if ($netAmount) {
                    $detailValueExpression = 'COALESCE(sd.' . tracking_qi($netAmount) . ', 0)';
                } elseif ($grossAmount) {
                    $detailValueExpression = 'COALESCE(sd.' . tracking_qi($grossAmount) . ', 0)';
                } elseif ($qtyColumn && $priceColumn) {
                    $detailValueExpression = '(COALESCE(sd.' . tracking_qi($qtyColumn) . ', 0) * COALESCE(sd.' . tracking_qi($priceColumn) . ', 0))';
                }

                if ($detailSalesId && $detailDate && $detailValueExpression !== '') {
                    $sourceParts[] = "
                        SELECT
                            CAST(sd." . tracking_qi($detailSalesId) . " AS CHAR) AS salesid,
                            sd." . tracking_qi($detailDate) . " AS transdate,
                            " . $detailValueExpression . " AS nominal,
                            2 AS source_priority
                        FROM `salesdetailcopy` sd
                        WHERE sd." . tracking_qi($detailDate) . " >= ?
                          AND sd." . tracking_qi($detailDate) . " < ?
                    ";
                    $bindTypes .= 'ss';
                    $bindValues[] = $startDateTime;
                    $bindValues[] = $endDateTime;
                    $sourceNames[] = 'salesdetailcopy';
                }
            }

            if (!$sourceParts) {
                $query_error = 'Tabel salespayments/salesdetailcopy atau kolom yang dibutuhkan tidak ditemukan.';
            } else {
                $unionSql = implode("\nUNION ALL\n", $sourceParts);

                $query = "
                    SELECT
                        DATE_FORMAT(invoice_time, '%H') AS jam,
                        COUNT(*) AS jumlah_sales,
                        ROUND(SUM(invoice_nominal)) AS total_nominal
                    FROM (
                        SELECT
                            salesid,
                            COALESCE(
                                MIN(CASE WHEN source_priority = 1 THEN transdate END),
                                MIN(CASE WHEN source_priority = 2 THEN transdate END)
                            ) AS invoice_time,
                            CASE
                                WHEN SUM(CASE WHEN source_priority = 1 THEN nominal ELSE 0 END) <> 0
                                THEN SUM(CASE WHEN source_priority = 1 THEN nominal ELSE 0 END)
                                ELSE SUM(CASE WHEN source_priority = 2 THEN nominal ELSE 0 END)
                            END AS invoice_nominal
                        FROM (
                            " . $unionSql . "
                        ) combined_sources
                        WHERE salesid IS NOT NULL
                          AND salesid <> ''
                        GROUP BY salesid
                    ) invoice_summary
                    WHERE invoice_time IS NOT NULL
                    GROUP BY DATE_FORMAT(invoice_time, '%H')
                    ORDER BY jam ASC
                ";

                $stmt = $mysqli->prepare($query);
                if (!$stmt) {
                    $query_error = 'Query gagal dipersiapkan: ' . $mysqli->error;
                } else {
                    if ($bindTypes !== '' && !tracking_bind_params($stmt, $bindTypes, $bindValues)) {
                        $query_error = 'Parameter query gagal dipasang.';
                    } elseif (!$stmt->execute()) {
                        $query_error = 'Query gagal dijalankan: ' . $stmt->error;
                    } else {
                        $result = $stmt->get_result();
                        while ($result && ($row = $result->fetch_assoc())) {
                            $hour = str_pad((string)$row['jam'], 2, '0', STR_PAD_LEFT) . ':00';
                            if (array_key_exists($hour, $dataJumlah)) {
                                $dataJumlah[$hour] = (int)($row['jumlah_sales'] ?? 0);
                                $dataTotalNominal[$hour] = (float)($row['total_nominal'] ?? 0);
                            }
                        }
                        $source_info = implode(' + ', $sourceNames);
                    }
                    $stmt->close();
                }
            }

            $dataJam = array_keys($dataJam);
            $dataJumlah = array_values($dataJumlah);
            $dataTotalNominal = array_values($dataTotalNominal);

            $totalTransaksi = array_sum($dataJumlah);
            $totalNominal = array_sum($dataTotalNominal);

            $mysqli->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Hourly Sales Report</title>

<link rel="icon" type="image/png" href="img/srt2.png" />
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
body {
    font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;
    margin:0; padding:0;
    background: url('img/soeta.jpg') center/cover no-repeat fixed;
    position:relative; min-height:100vh;
    color:#333;
}
body::before {
    content:"";
    position:fixed;
    top:0; left:0;
    width:100%; height:100%;
    background: url('img/soeta.jpg') center/cover no-repeat;
    opacity:0.05; z-index:0; pointer-events:none;
}
.header {
    display:flex; justify-content:space-between; align-items:center;
    padding:20px 40px;
    background: linear-gradient(90deg, #0d6efd, #0a58ca);
    color:#fff;
    border-bottom-left-radius:15px; border-bottom-right-radius:15px;
    box-shadow:0 4px 15px rgba(0,0,0,0.2); position:relative; z-index:1;
}
.header h2 { margin:0; font-size:1.8rem; }
.header .info { display:flex; align-items:center; gap:15px; }
.logo-wrapper { width:60px; height:60px; }
.logo-wrapper img { width:100%; height:100%; object-fit:contain; border-radius:10px; border:2px solid #ccc; box-shadow:0 2px 10px rgba(0,0,0,0.2);}
.chart-container {
    max-width:1000px;
    margin:40px auto;
    padding:38px 30px 26px;
    background:rgba(255,255,255,0.95);
    border-radius:15px;
    box-shadow:0 8px 25px rgba(0,0,0,0.15);
    position:relative;
    overflow:visible;
    height:auto;
}
.chart-canvas-area {
    position:relative;
    width:100%;
    height:430px;
}
canvas { display:block; }
.total { text-align:center; font-weight:bold; margin-top:10px; font-size:16px; color:#0d6efd; }
form { text-align:center; margin-bottom:20px; }
select,input { padding:8px 12px; border-radius:6px; border:1px solid #ccc; margin:5px; }
button {
    padding:8px 18px; border:none; border-radius:8px;
    background:#0d6efd; color:#fff; cursor:pointer; transition:.3s;
}
button:hover { background:#094bb5; }
.card-total {
    display:flex; justify-content:space-around; margin-top:15px; font-weight:bold;
    font-size:16px; color:#0d6efd;
}
.source-note {
    width:100%;
    margin:16px auto 0;
    padding:10px 14px;
    text-align:center;
    color:#475569;
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:10px;
    font-size:12px;
    font-weight:600;
    line-height:1.5;
    white-space:normal;
    overflow-wrap:anywhere;
}
@media (max-width: 900px) {
    .header { padding:16px; flex-direction:column; align-items:stretch; gap:12px; }
    .header .info { flex-wrap:wrap; justify-content:center; }
    .chart-container { margin:20px 12px; padding:24px 15px 20px; height:auto; }
    .chart-canvas-area { height:380px; }
    .card-total { flex-direction:column; gap:6px; text-align:center; }
}
</style>
</head>
<body>

<?php if (!empty($offline_error)): ?>
<script>
Swal.fire({
    icon: 'error',
    title: 'Toko Offline',
    html: 'Toko yang Anda pilih sedang offline.<br>Silakan hubungi tim toko.<br><br>' + <?= json_encode($offline_error, JSON_UNESCAPED_UNICODE) ?>,
    allowOutsideClick: false
});
</script>
<?php endif; ?>

<?php if (!empty($query_error)): ?>
<script>
Swal.fire({
    icon: 'error',
    title: 'Data Gagal Diproses',
    text: <?= json_encode($query_error, JSON_UNESCAPED_UNICODE) ?>,
    allowOutsideClick: false
});
</script>
<?php endif; ?>

<div class="header">
    <a href="dashboard.php" class="btn btn-outline-light">Kembali</a>
    <h2>Hourly Sales Report</h2>
    <div class="info">
        <?php if (!empty($namaToko) && !empty($map[$namaToko]['logo'])): ?>
        <div class="logo-wrapper">
            <img src="img/<?= tracking_h($map[$namaToko]['logo']) ?>" alt="<?= tracking_h($namaToko) ?>">
        </div>
        <?php endif; ?>
        <span><?= tracking_h($namaToko) ?></span>
        <form method="post" style="margin-left:20px;">
            <select name="db_index" required>
                <option value="">-- Pilih Toko --</option>
                <?php foreach ($map as $store => $c): ?>
                    <option value="<?= tracking_h($store) ?>" <?= ($namaToko === $store ? 'selected' : '') ?>>
                        <?= tracking_h($store) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="tanggal" value="<?= tracking_h($tanggal_input) ?>" required>
            <button type="submit" name="submit">Tampilkan</button>
        </form>
    </div>
</div>

<?php if (!empty($dataJam) && empty($offline_error) && empty($query_error)): ?>
<div class="chart-container">
    <div class="chart-canvas-area">
        <canvas id="chartCombined"></canvas>
    </div>
    <div class="card-total">
        <div>Total Transaksi: <?= number_format($totalTransaksi, 0, ',', '.') ?></div>
        <div>Total Nominal: Rp <?= number_format($totalNominal, 0, ',', '.') ?></div>
    </div>
    <?php if ($source_info !== ''): ?>
        <div class="source-note">Sumber data: Webportal Minimarket system</div>
    <?php endif; ?>
</div>

<script>
const labels = <?= json_encode($dataJam, JSON_UNESCAPED_UNICODE) ?>;
const dataJumlah = <?= json_encode($dataJumlah, JSON_UNESCAPED_UNICODE) ?>;
const dataNominal = <?= json_encode($dataTotalNominal, JSON_UNESCAPED_UNICODE) ?>;
const tokoColor = <?= json_encode($map[$namaToko]['color'] ?? '#007bff', JSON_UNESCAPED_UNICODE) ?>;

const ctx = document.getElementById('chartCombined').getContext('2d');
new Chart(ctx, {
    type:'bar',
    data:{
        labels:labels,
        datasets:[
            { type:'bar', label:'Jumlah Transaksi', data:dataJumlah, backgroundColor:tokoColor, yAxisID:'y1' },
            { type:'line', label:'Total Nominal (Rp)', data:dataNominal, borderColor:'#ff9900', backgroundColor:'rgba(255,153,0,0.2)', yAxisID:'y2', tension:0.2, fill:true }
        ]
    },
    options:{
        responsive:true,
        maintainAspectRatio:false,
        layout: { padding: { top:20, bottom:30, left:10, right:10 } },
        interaction:{mode:'index',intersect:false},
        plugins:{
            datalabels:{display:false},
            legend:{position:'top'},
            title:{display:true,text:<?= json_encode($namaToko . ' - Tanggal ' . $tanggal_input, JSON_UNESCAPED_UNICODE) ?>}
        },
        scales:{
            y1:{type:'linear',position:'left',beginAtZero:true,title:{display:true,text:'Jumlah Transaksi'}},
            y2:{type:'linear',position:'right',beginAtZero:true,title:{display:true,text:'Total Nominal (Rp)'},
                ticks:{callback:function(v){ return 'Rp ' + Number(v).toLocaleString('id-ID'); }}}
        }
    },
    plugins:[ChartDataLabels]
});
</script>
<?php endif; ?>

</body>
</html>
