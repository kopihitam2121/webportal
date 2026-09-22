<?php
session_start();

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

$host = "202.10.41.120";
$user = "wira_user";
$pass = "Mtssepatan210300#";
$db   = "appsheet_db";

$mysqli = new mysqli($host, $user, $pass, $db);

if ($mysqli->connect_error) {
    die("Koneksi gagal: " . $mysqli->connect_error);
}

$mysqli->set_charset("utf8mb4");

/*
|--------------------------------------------------------------------------
| SEMUA USER YANG SUDAH LOGIN BOLEH EDIT
|--------------------------------------------------------------------------
*/
$canEdit = true;


/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/
function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cleanFileName($value)
{
    $value = trim((string)$value);
    $value = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $value);
    $value = trim($value, '_');

    return $value !== '' ? $value : 'Inventaris';
}


/*
|--------------------------------------------------------------------------
| AJAX HANDLER
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | UPDATE ASSET
    |--------------------------------------------------------------------------
    */
    if ($action === 'update') {

        $id    = intval($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = trim($_POST['value'] ?? '');

        $allowedFields = [
            'namaasset',
            'kategori',
            'jumlah',
            'kondisi',
            'lokasi',
            'catatan'
        ];

        if ($id <= 0) {
            echo json_encode([
                'status' => 'error',
                'message' => 'ID asset tidak valid.'
            ]);
            exit;
        }

        if (!in_array($field, $allowedFields, true)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Field tidak diperbolehkan.'
            ]);
            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | Validasi jumlah
        |--------------------------------------------------------------------------
        */
        if ($field === 'jumlah') {

            if ($value === '') {
                $value = '0';
            }

            if (!is_numeric($value)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Jumlah harus berupa angka.'
                ]);
                exit;
            }

            $value = intval($value);
        }

        /*
        |--------------------------------------------------------------------------
        | Prepared Statement
        |--------------------------------------------------------------------------
        */
        $sql = "UPDATE inventaris SET `$field` = ? WHERE no = ?";

        $stmt = $mysqli->prepare($sql);

        if (!$stmt) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Prepare gagal: ' . $mysqli->error
            ]);
            exit;
        }

        $valueString = (string)$value;

        $stmt->bind_param(
            "si",
            $valueString,
            $id
        );

        if ($stmt->execute()) {

            echo json_encode([
                'status' => 'success',
                'message' => 'Data berhasil diperbarui.'
            ]);

        } else {

            echo json_encode([
                'status' => 'error',
                'message' => 'Gagal memperbarui data.'
            ]);
        }

        $stmt->close();
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | TAMBAH ASSET
    |--------------------------------------------------------------------------
    */
    if ($action === 'add') {

        $tenant   = trim($_POST['tenant'] ?? '');
        $namaasset = trim($_POST['namaasset'] ?? '');
        $kategori  = trim($_POST['kategori'] ?? '');
        $jumlah    = intval($_POST['jumlah'] ?? 0);
        $kondisi   = trim($_POST['kondisi'] ?? '');
        $lokasi    = trim($_POST['lokasi'] ?? '');
        $catatan   = trim($_POST['catatan'] ?? '');

        if ($tenant === '') {

            echo json_encode([
                'status' => 'error',
                'message' => 'Tenant tidak ditemukan.'
            ]);

            exit;
        }

        if ($namaasset === '') {

            echo json_encode([
                'status' => 'error',
                'message' => 'Nama asset wajib diisi.'
            ]);

            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | INSERT
        |--------------------------------------------------------------------------
        */
        $sql = "
            INSERT INTO inventaris
            (
                namatenant,
                namaasset,
                kategori,
                jumlah,
                kondisi,
                lokasi,
                catatan
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ";

        $stmt = $mysqli->prepare($sql);

        if (!$stmt) {

            echo json_encode([
                'status' => 'error',
                'message' => 'Prepare gagal: ' . $mysqli->error
            ]);

            exit;
        }

        $stmt->bind_param(
            "sssisss",
            $tenant,
            $namaasset,
            $kategori,
            $jumlah,
            $kondisi,
            $lokasi,
            $catatan
        );

        if ($stmt->execute()) {

            $newId = $stmt->insert_id;

            echo json_encode([
                'status' => 'success',
                'message' => 'Asset berhasil ditambahkan.',
                'id' => $newId
            ]);

        } else {

            echo json_encode([
                'status' => 'error',
                'message' => 'Gagal menambahkan asset.'
            ]);
        }

        $stmt->close();
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE ASSET
    |--------------------------------------------------------------------------
    */
    if ($action === 'delete') {

        $id = intval($_POST['id'] ?? 0);

        if ($id <= 0) {

            echo json_encode([
                'status' => 'error',
                'message' => 'ID asset tidak valid.'
            ]);

            exit;
        }

        $stmt = $mysqli->prepare(
            "DELETE FROM inventaris WHERE no = ?"
        );

        if (!$stmt) {

            echo json_encode([
                'status' => 'error',
                'message' => 'Prepare gagal.'
            ]);

            exit;
        }

        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {

            echo json_encode([
                'status' => 'success',
                'message' => 'Asset berhasil dihapus.'
            ]);

        } else {

            echo json_encode([
                'status' => 'error',
                'message' => 'Gagal menghapus asset.'
            ]);
        }

        $stmt->close();
        exit;
    }


    echo json_encode([
        'status' => 'error',
        'message' => 'Action tidak dikenali.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| TENANT YANG DIPILIH
|--------------------------------------------------------------------------
*/

$selectedTenant = trim(
    (string)($_GET['tenant'] ?? '')
);


/*
|--------------------------------------------------------------------------
| LIST TENANT
|--------------------------------------------------------------------------
*/

$tenantRows = [];

$qTenant = $mysqli->query("
    SELECT DISTINCT namatenant
    FROM inventaris
    WHERE namatenant IS NOT NULL
    AND TRIM(namatenant) <> ''
    ORDER BY LOWER(namatenant) ASC
");

if ($qTenant) {

    while ($t = $qTenant->fetch_assoc()) {

        $tenantRows[] = $t['namatenant'];
    }
}


/*
|--------------------------------------------------------------------------
| DATA INVENTARIS
|--------------------------------------------------------------------------
*/

$dataRows = [];

if ($selectedTenant !== '') {

    $stmt = $mysqli->prepare("
        SELECT *
        FROM inventaris
        WHERE namatenant = ?
        ORDER BY LOWER(namaasset), kategori, lokasi, no ASC
    ");

    if ($stmt) {

        $stmt->bind_param(
            's',
            $selectedTenant
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {

            $dataRows[] = $row;
        }

        $stmt->close();
    }
}


/*
|--------------------------------------------------------------------------
| EXPORT EXCEL
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['export']) &&
    $_GET['export'] === 'excel'
) {

    if ($selectedTenant === '') {

        echo "Pilih tenant/toko terlebih dahulu.";
        exit;
    }

    $safeTenant = cleanFileName(
        $selectedTenant
    );

    $filename =
        'Inventaris_' .
        $safeTenant .
        '_' .
        date('Ymd_His') .
        '.xls';

    header(
        "Content-Type: application/vnd.ms-excel; charset=utf-8"
    );

    header(
        "Content-Disposition: attachment; filename={$filename}"
    );

    header("Pragma: no-cache");
    header("Expires: 0");

    $totalJumlah = 0;

    foreach ($dataRows as $r) {

        $totalJumlah +=
            (int)($r['jumlah'] ?? 0);
    }

    echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html>
<html>
<head>

<meta charset="UTF-8">

<style>

body{
    font-family:Arial,sans-serif;
    color:#1f2937;
}

table{
    border-collapse:collapse;
    width:100%;
}

.title{
    background:#14532d;
    color:#ffffff;
    font-size:20px;
    font-weight:bold;
    text-align:center;
    height:36px;
}

.tenant-title{
    background:#166534;
    color:#ffffff;
    font-size:16px;
    font-weight:bold;
    text-align:center;
    height:30px;
}

.subtitle{
    background:#dcfce7;
    color:#14532d;
    font-size:11px;
    font-weight:bold;
    text-align:center;
    height:24px;
}

.summary-label{
    background:#22c55e;
    color:#ffffff;
    font-weight:bold;
    border:1px solid #14532d;
}

.summary-value{
    background:#f0fdf4;
    color:#14532d;
    font-weight:bold;
    border:1px solid #14532d;
}

th{
    background:#15803d;
    color:#ffffff;
    font-weight:bold;
    text-align:center;
    border:1px solid #14532d;
    height:30px;
}

td{
    border:1px solid #86efac;
    height:25px;
    vertical-align:middle;
}

.tenant-separator{
    background:#bbf7d0;
    color:#14532d;
    font-weight:bold;
    font-size:13px;
    border-top:3px solid #14532d;
    border-bottom:2px solid #14532d;
    height:28px;
}

.asset{
    background:#ffffff;
}

.kategori{
    background:#f0fdf4;
}

.jumlah{
    background:#fef9c3;
    text-align:center;
    font-weight:bold;
}

.kondisi{
    background:#e0f2fe;
    text-align:center;
}

.lokasi{
    background:#ffffff;
}

.catatan{
    background:#fff7ed;
}

.footer{
    background:#bbf7d0;
    color:#14532d;
    font-weight:bold;
    border:1px solid #14532d;
}

</style>

</head>

<body>

<table>

<tr>
<td class="title" colspan="6">
DATA INVENTARIS
</td>
</tr>

<tr>
<td class="tenant-title" colspan="6">
<?= h($selectedTenant); ?>
</td>
</tr>

<tr>
<td class="subtitle" colspan="6">
Export tanggal <?= date('d-m-Y H:i:s'); ?> WIB
</td>
</tr>

<tr>
<td colspan="6"></td>
</tr>

<tr>

<td class="summary-label" colspan="2">
Nama Tenant / Toko
</td>

<td class="summary-value" colspan="4">
<?= h($selectedTenant); ?>
</td>

</tr>

<tr>

<td class="summary-label" colspan="2">
Total Data
</td>

<td class="summary-value">
<?= count($dataRows); ?>
</td>

<td class="summary-label" colspan="2">
Total Jumlah Asset
</td>

<td class="summary-value">
<?= $totalJumlah; ?>
</td>

</tr>

<tr>
<td colspan="6"></td>
</tr>

<tr>

<td class="tenant-separator" colspan="6">
TENANT: <?= h($selectedTenant); ?>
</td>

</tr>

<tr>

<th>Nama Asset</th>
<th>Kategori</th>
<th>Jumlah</th>
<th>Kondisi</th>
<th>Lokasi</th>
<th>Catatan</th>

</tr>

<?php foreach($dataRows as $row): ?>

<tr>

<td class="asset">
<?= h($row['namaasset']); ?>
</td>

<td class="kategori">
<?= h($row['kategori']); ?>
</td>

<td class="jumlah">
<?= h($row['jumlah']); ?>
</td>

<td class="kondisi">
<?= h($row['kondisi']); ?>
</td>

<td class="lokasi">
<?= h($row['lokasi']); ?>
</td>

<td class="catatan">
<?= h($row['catatan']); ?>
</td>

</tr>

<?php endforeach; ?>

<tr>

<td class="footer" colspan="2">
TOTAL DATA: <?= count($dataRows); ?>
</td>

<td class="footer">
<?= $totalJumlah; ?>
</td>

<td class="footer" colspan="3">
Generated by Web Portal SRT
</td>

</tr>

</table>

</body>
</html>

<?php

    $mysqli->close();

    exit;
}

$mysqli->close();

?>

<!DOCTYPE html>

<html lang="id">

<head>

<meta charset="UTF-8">

<title>Data Inventaris</title>

<meta
    name="viewport"
    content="width=device-width,initial-scale=1.0"
>

<link
    rel="icon"
    type="image/png"
    href="img/srt2.png"
>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<link
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    rel="stylesheet"
>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>

body{

    background:

        radial-gradient(
            circle at 18% 8%,
            rgba(34,197,94,.16),
            transparent 28%
        ),

        radial-gradient(
            circle at 90% 30%,
            rgba(20,83,45,.12),
            transparent 26%
        ),

        linear-gradient(
            180deg,
            #e8f7ec 0%,
            #f5fff7 100%
        );

    font-family:Arial,sans-serif;

    font-size:12px;

    min-height:100vh;
}

.container-custom{

    width:min(
        1180px,
        calc(100% - 24px)
    );

    background:#fff;

    padding:22px;

    margin:20px auto;

    border-radius:22px;

    box-shadow:
        0 18px 45px
        rgba(20,83,45,.16);

    border:1px solid #cfeedd;
}

.header-wrapper{

    display:flex;

    justify-content:center;

    align-items:center;

    margin-bottom:20px;

    position:relative;

    min-height:58px;
}

.header-wrapper h2{

    color:#14532d;

    margin:0;

    text-align:center;

    font-size:25px;

    font-weight:900;
}

.header-wrapper img{

    position:absolute;

    right:0;

    height:52px;
}

.toolbar-card{

    background:
        linear-gradient(
            135deg,
            #f0fdf4,
            #dcfce7
        );

    border:1px solid #bbf7d0;

    border-radius:20px;

    padding:16px;

    margin-bottom:16px;
}

.empty-state{

    min-height:420px;

    display:flex;

    align-items:center;

    justify-content:center;

    text-align:center;

    border:2px dashed #bbf7d0;

    border-radius:24px;

    background:
        linear-gradient(
            180deg,
            #ffffff,
            #f0fdf4
        );

    padding:30px 16px;
}

.empty-icon{

    width:110px;

    height:110px;

    border-radius:36px;

    margin:0 auto 18px;

    display:flex;

    align-items:center;

    justify-content:center;

    color:#fff;

    font-size:46px;

    background:
        linear-gradient(
            135deg,
            #22c55e,
            #14532d
        );

    box-shadow:
        0 18px 38px
        rgba(20,83,45,.24);
}

.empty-state h3{

    font-weight:900;

    color:#14532d;

    margin-bottom:8px;
}

.tenant-separator-web{

    background:
        linear-gradient(
            135deg,
            #14532d,
            #22c55e
        );

    color:#fff;

    font-weight:900;

    border-radius:18px;

    padding:13px 16px;

    margin:10px 0 12px;

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:10px;

    box-shadow:
        0 10px 24px
        rgba(20,83,45,.18);
}

.tenant-separator-web span{

    font-size:13px;

    opacity:.88;
}

.table thead{

    background:
        linear-gradient(
            45deg,
            #28a745,
            #14532d
        );

    color:#fff;

    font-weight:bold;

    text-transform:uppercase;

    font-size:13px;
}

.table th{

    white-space:nowrap;

    vertical-align:middle;
}

.table td{

    vertical-align:middle;
}

.table-striped>
tbody>
tr:nth-of-type(odd){

    background:#f0fdf4;
}

.table-striped>
tbody>
tr:nth-of-type(even){

    background:#ffffff;
}

.table-striped>
tbody>
tr:hover{

    background:#dcfce7;
}

.table tfoot td{

    font-weight:bold;

    background-color:#bbf7d0;

    color:#14532d;
}

.summary-box{

    display:grid;

    grid-template-columns:
        repeat(3,1fr);

    gap:12px;

    margin-bottom:14px;
}

.summary-item{

    border-radius:18px;

    padding:14px;

    background:#f0fdf4;

    border:1px solid #bbf7d0;
}

.summary-item small{

    display:block;

    color:#166534;

    font-weight:900;

    text-transform:uppercase;

    font-size:10px;
}

.summary-item strong{

    display:block;

    margin-top:6px;

    color:#14532d;

    font-size:18px;

    font-weight:900;
}


/* EDIT MODE */

.edit-mode{

    background:#fffde7 !important;

    border:2px solid #22c55e !important;

    box-shadow:
        inset 0 0 0 1px
        rgba(34,197,94,.15);
}

.inline-input{

    width:100%;

    border:1px solid #22c55e;

    border-radius:6px;

    padding:6px 8px;

    font-size:12px;

    outline:none;
}

.inline-input:focus{

    box-shadow:
        0 0 0 3px
        rgba(34,197,94,.15);
}

.action-cell{

    min-width:120px;

    text-align:center;
}

.new-row{

    background:#fffde7 !important;
}

@media(max-width:768px){

    .header-wrapper{

        justify-content:flex-start;

        padding-right:58px;
    }

    .header-wrapper h2{

        font-size:20px;

        text-align:left;
    }

    .summary-box{

        grid-template-columns:1fr;
    }

    .table{

        min-width:900px;
    }

}

</style>

</head>

<body>

<div class="container-custom">


    <!-- BUTTON ATAS -->

    <div class="d-flex flex-wrap gap-2 mb-3">

        <a
            href="dashboard.php"
            class="btn btn-success"
        >

            &laquo; Kembali

        </a>


        <?php if($selectedTenant !== ''): ?>

            <a
                href="inventaris.php?tenant=<?= urlencode($selectedTenant); ?>&export=excel"
                class="btn btn-warning fw-bold"
            >

                <i class="fa-solid fa-file-excel"></i>

                Download Excel Toko Ini

            </a>


            <a
                href="inventaris.php"
                class="btn btn-outline-secondary fw-bold"
            >

                <i class="fa-solid fa-rotate-left"></i>

                Reset Pilihan

            </a>

        <?php endif; ?>

    </div>


    <!-- HEADER -->

    <div class="header-wrapper">

        <h2>
            Data Inventaris
        </h2>

        <img
            src="img/srt2.png"
            alt="Logo SRT"
        >

    </div>


    <!-- PILIH TENANT -->

    <div class="toolbar-card">

        <form
            method="GET"
            class="row g-2 align-items-end"
        >

            <div class="col-md-8">

                <label
                    class="form-label fw-bold text-success"
                >
                    Pilih Toko / Tenant
                </label>

                <select
                    name="tenant"
                    class="form-select form-select-lg"
                    onchange="this.form.submit()"
                >

                    <option value="">
                        -- Silakan pilih toko terlebih dahulu --
                    </option>

                    <?php foreach($tenantRows as $tenant): ?>

                        <option
                            value="<?= h($tenant); ?>"
                            <?= $selectedTenant === $tenant ? 'selected' : ''; ?>
                        >

                            <?= h($tenant); ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="col-md-4">

                <button
                    class="btn btn-success btn-lg w-100 fw-bold"
                    type="submit"
                >

                    <i class="fa-solid fa-magnifying-glass"></i>

                    Tampilkan

                </button>

            </div>

        </form>

    </div>


    <?php if($selectedTenant === ''): ?>


        <!-- EMPTY -->

        <div class="empty-state">

            <div>

                <div class="empty-icon">

                    <i class="fa-solid fa-boxes-stacked"></i>

                </div>

                <h3>
                    Silakan Pilih Toko Terlebih Dahulu
                </h3>

            </div>

        </div>


    <?php else: ?>


        <?php

        $totalJumlahWeb = 0;

        foreach($dataRows as $r){

            $totalJumlahWeb +=
                (int)($r['jumlah'] ?? 0);
        }

        ?>


        <!-- SUMMARY -->

        <div class="summary-box">

            <div class="summary-item">

                <small>
                    Tenant / Toko
                </small>

                <strong id="summaryTenant">
                    <?= h($selectedTenant); ?>
                </strong>

            </div>


            <div class="summary-item">

                <small>
                    Total Data
                </small>

                <strong id="summaryTotalData">
                    <?= count($dataRows); ?>
                </strong>

            </div>


            <div class="summary-item">

                <small>
                    Total Jumlah Asset
                </small>

                <strong id="summaryTotalAsset">
                    <?= $totalJumlahWeb; ?>
                </strong>

            </div>

        </div>


        <!-- TENANT HEADER -->

        <div class="tenant-separator-web">

            <div>

                <i class="fa-solid fa-store"></i>

                <?= h($selectedTenant); ?>

            </div>

            <span id="tenantDataCount">

                <?= count($dataRows); ?>
                data inventaris

            </span>

        </div>


        <!-- TOOLBAR -->

        <div class="d-flex flex-wrap gap-2 mb-3">

            <button
                type="button"
                class="btn btn-success fw-bold"
                id="btnTambahAsset"
            >

                <i class="fa-solid fa-plus"></i>

                Tambah Asset

            </button>

            <button
                type="button"
                class="btn btn-outline-success fw-bold"
                id="btnRefresh"
            >

                <i class="fa-solid fa-rotate"></i>

                Refresh

            </button>

        </div>


        <!-- SEARCH -->

        <input
            type="text"
            id="searchInput"
            class="form-control mb-3"
            placeholder="Cari asset, kategori, kondisi, lokasi, catatan..."
        >


        <!-- TABLE -->

        <div class="table-responsive">

            <table
                class="table table-bordered table-striped table-hover"
            >

                <thead>

                    <tr>

                        <th>
                            Nama Asset
                        </th>

                        <th>
                            Kategori
                        </th>

                        <th>
                            Jumlah
                        </th>

                        <th>
                            Kondisi
                        </th>

                        <th>
                            Lokasi
                        </th>

                        <th>
                            Catatan
                        </th>

                        <th width="130">
                            Aksi
                        </th>

                    </tr>

                </thead>


                <tbody id="inventarisTable">


                    <?php if(empty($dataRows)): ?>

                        <tr id="emptyRow">

                            <td
                                colspan="7"
                                class="text-center text-muted fw-bold py-4"
                            >

                                Belum ada data inventaris
                                untuk toko ini.

                            </td>

                        </tr>

                    <?php endif; ?>


                    <?php foreach($dataRows as $row): ?>

                        <tr
                            data-id="<?= h($row['no']); ?>"
                            data-row="asset"
                        >

                            <td
                                data-field="namaasset"
                            >
                                <?= h($row['namaasset']); ?>
                            </td>


                            <td
                                data-field="kategori"
                            >
                                <?= h($row['kategori']); ?>
                            </td>


                            <td
                                data-field="jumlah"
                                class="text-center fw-bold"
                            >
                                <?= h($row['jumlah']); ?>
                            </td>


                            <td
                                data-field="kondisi"
                                class="text-center"
                            >
                                <?= h($row['kondisi']); ?>
                            </td>


                            <td
                                data-field="lokasi"
                            >
                                <?= h($row['lokasi']); ?>
                            </td>


                            <td
                                data-field="catatan"
                            >
                                <?= h($row['catatan']); ?>
                            </td>


                            <td class="action-cell">

                                <button
                                    type="button"
                                    class="btn btn-sm btn-success btn-edit"
                                >

                                    <i class="fa-solid fa-pen"></i>

                                    Edit

                                </button>

                            </td>

                        </tr>

                    <?php endforeach; ?>


                </tbody>


                <tfoot>

                    <tr>

                        <td colspan="2">

                            Total Data:

                            <span id="footerTotalData">
                                <?= count($dataRows); ?>
                            </span>

                        </td>


                        <td
                            class="text-center"
                            id="footerTotalAsset"
                        >

                            <?= $totalJumlahWeb; ?>

                        </td>


                        <td colspan="4">

                            Tenant:

                            <?= h($selectedTenant); ?>

                        </td>

                    </tr>

                </tfoot>

            </table>

        </div>


    <?php endif; ?>


</div>


<?php if($selectedTenant !== ''): ?>

<script>


/*
|--------------------------------------------------------------------------
| GLOBAL
|--------------------------------------------------------------------------
*/

const currentTenant =
    <?= json_encode($selectedTenant); ?>;


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

document
.getElementById('searchInput')
?.addEventListener(
    'keyup',
    function(){

        let filter =
            this.value
                .toLowerCase()
                .trim();

        let rows =
            document.querySelectorAll(
                '#inventarisTable tr[data-row="asset"]'
            );

        rows.forEach(
            function(row){

                let text =
                    row.textContent
                        .toLowerCase();

                row.style.display =
                    text.includes(filter)
                    ? ''
                    : 'none';

            }
        );

    }
);


/*
|--------------------------------------------------------------------------
| UPDATE CELL
|--------------------------------------------------------------------------
*/

function updateCell(
    row,
    field,
    value
){

    const id =
        row.dataset.id;


    const body =
        new URLSearchParams();


    body.append(
        'action',
        'update'
    );

    body.append(
        'id',
        id
    );

    body.append(
        'field',
        field
    );

    body.append(
        'value',
        value
    );


    return fetch(
        'inventaris.php',
        {
            method:'POST',

            headers:{
                'Content-Type':
                    'application/x-www-form-urlencoded'
            },

            body:body.toString()
        }
    )

    .then(
        response =>
            response.json()
    )

    .then(
        result => {

            if(
                result.status !==
                'success'
            ){

                throw new Error(
                    result.message ||
                    'Gagal menyimpan data.'
                );

            }

            return result;

        }
    );

}


/*
|--------------------------------------------------------------------------
| EDIT ROW
|--------------------------------------------------------------------------
*/

function enableEdit(row){

    if(
        row.classList.contains(
            'editing'
        )
    ){
        return;
    }


    row.classList.add(
        'editing'
    );


    const cells =
        row.querySelectorAll(
            'td[data-field]'
        );


    cells.forEach(
        function(cell){

            const value =
                cell.textContent.trim();

            const field =
                cell.dataset.field;


            let input;


            if(field === 'jumlah'){

                input =
                    document.createElement(
                        'input'
                    );

                input.type =
                    'number';

                input.min =
                    '0';

            }else{

                input =
                    document.createElement(
                        'input'
                    );

                input.type =
                    'text';

            }


            input.className =
                'inline-input';


            input.value =
                value;


            input.dataset.original =
                value;


            cell.innerHTML = '';

            cell.appendChild(
                input
            );


            cell.classList.add(
                'edit-mode'
            );

        }
    );


    const action =
        row.querySelector(
            '.action-cell'
        );


    action.innerHTML = `

        <div class="d-flex gap-1 justify-content-center">

            <button
                type="button"
                class="btn btn-sm btn-success btn-save"
            >
                <i class="fa-solid fa-check"></i>
                Simpan
            </button>

            <button
                type="button"
                class="btn btn-sm btn-secondary btn-cancel"
            >
                <i class="fa-solid fa-xmark"></i>
                Batal
            </button>

        </div>

    `;

}


/*
|--------------------------------------------------------------------------
| CANCEL EDIT
|--------------------------------------------------------------------------
*/

function cancelEdit(row){

    const cells =
        row.querySelectorAll(
            'td[data-field]'
        );


    cells.forEach(
        function(cell){

            const input =
                cell.querySelector(
                    'input'
                );

            if(input){

                cell.textContent =
                    input.dataset.original;
            }

            cell.classList.remove(
                'edit-mode'
            );

        }
    );


    row.classList.remove(
        'editing'
    );


    row.querySelector(
        '.action-cell'
    ).innerHTML = `

        <button
            type="button"
            class="btn btn-sm btn-success btn-edit"
        >

            <i class="fa-solid fa-pen"></i>

            Edit

        </button>

    `;

}


/*
|--------------------------------------------------------------------------
| SAVE EDIT
|--------------------------------------------------------------------------
*/

async function saveEdit(row){

    const cells =
        row.querySelectorAll(
            'td[data-field]'
        );


    const changes = [];


    cells.forEach(
        function(cell){

            const input =
                cell.querySelector(
                    'input'
                );

            if(!input){
                return;
            }


            const value =
                input.value.trim();


            const original =
                input.dataset.original;


            if(value !== original){

                changes.push({
                    field:
                        cell.dataset.field,

                    value:
                        value,

                    cell:
                        cell,

                    input:
                        input
                });

            }

        }
    );


    if(changes.length === 0){

        cancelEdit(row);

        return;

    }


    /*
    |--------------------------------------------------------------------------
    | Loading
    |--------------------------------------------------------------------------
    */

    Swal.fire({

        title:'Menyimpan...',

        text:'Perubahan sedang disimpan.',

        allowOutsideClick:false,

        didOpen:() => {

            Swal.showLoading();

        }

    });


    try{

        for(
            const change of changes
        ){

            await updateCell(
                row,
                change.field,
                change.value
            );

        }


        /*
        |--------------------------------------------------------------------------
        | Masukkan value ke cell
        |--------------------------------------------------------------------------
        */

        cells.forEach(
            function(cell){

                const input =
                    cell.querySelector(
                        'input'
                    );

                if(input){

                    cell.textContent =
                        input.value.trim();

                }

                cell.classList.remove(
                    'edit-mode'
                );

            }
        );


        row.classList.remove(
            'editing'
        );


        row.querySelector(
            '.action-cell'
        ).innerHTML = `

            <button
                type="button"
                class="btn btn-sm btn-success btn-edit"
            >

                <i class="fa-solid fa-pen"></i>

                Edit

            </button>

        `;


        updateTotals();


        Swal.fire({

            icon:'success',

            title:'Berhasil!',

            text:'Data inventaris berhasil diperbarui.',

            timer:1200,

            showConfirmButton:false

        });


    }catch(error){

        Swal.fire({

            icon:'error',

            title:'Gagal!',

            text:
                error.message ||
                'Data gagal disimpan.'

        });

    }

}


/*
|--------------------------------------------------------------------------
| EVENT DELEGATION
|--------------------------------------------------------------------------
*/

document
.getElementById('inventarisTable')
.addEventListener(
    'click',
    function(e){

        const editButton =
            e.target.closest(
                '.btn-edit'
            );


        if(editButton){

            const row =
                editButton.closest(
                    'tr'
                );

            enableEdit(row);

            return;

        }


        const saveButton =
            e.target.closest(
                '.btn-save'
            );


        if(saveButton){

            const row =
                saveButton.closest(
                    'tr'
                );

            saveEdit(row);

            return;

        }


        const cancelButton =
            e.target.closest(
                '.btn-cancel'
            );


        if(cancelButton){

            const row =
                cancelButton.closest(
                    'tr'
                );

            cancelEdit(row);

            return;

        }


        const deleteButton =
            e.target.closest(
                '.btn-delete'
            );


        if(deleteButton){

            const row =
                deleteButton.closest(
                    'tr'
                );

            deleteAsset(row);

            return;

        }

    }
);


/*
|--------------------------------------------------------------------------
| TAMBAH ASSET
|--------------------------------------------------------------------------
*/

document
.getElementById('btnTambahAsset')
?.addEventListener(
    'click',
    function(){

        const emptyRow =
            document.getElementById(
                'emptyRow'
            );


        if(emptyRow){

            emptyRow.remove();

        }


        const tbody =
            document.getElementById(
                'inventarisTable'
            );


        const row =
            document.createElement(
                'tr'
            );


        row.className =
            'new-row';


        row.dataset.row =
            'new';


        row.innerHTML = `

            <td data-field="namaasset">

                <input
                    type="text"
                    class="inline-input"
                    placeholder="Nama asset"
                >

            </td>


            <td data-field="kategori">

                <input
                    type="text"
                    class="inline-input"
                    placeholder="Kategori"
                >

            </td>


            <td
                data-field="jumlah"
                class="text-center"
            >

                <input
                    type="number"
                    min="0"
                    value="1"
                    class="inline-input"
                >

            </td>


            <td
                data-field="kondisi"
                class="text-center"
            >

                <input
                    type="text"
                    class="inline-input"
                    placeholder="Baik"
                >

            </td>


            <td data-field="lokasi">

                <input
                    type="text"
                    class="inline-input"
                    placeholder="Lokasi"
                >

            </td>


            <td data-field="catatan">

                <input
                    type="text"
                    class="inline-input"
                    placeholder="Catatan"
                >

            </td>


            <td class="action-cell">

                <div class="d-flex gap-1 justify-content-center">

                    <button
                        type="button"
                        class="btn btn-sm btn-success btn-save-new"
                    >

                        <i class="fa-solid fa-check"></i>

                        Simpan

                    </button>


                    <button
                        type="button"
                        class="btn btn-sm btn-secondary btn-cancel-new"
                    >

                        <i class="fa-solid fa-xmark"></i>

                        Batal

                    </button>

                </div>

            </td>

        `;


        tbody.prepend(
            row
        );


        const firstInput =
            row.querySelector(
                'input'
            );


        firstInput?.focus();

    }
);


/*
|--------------------------------------------------------------------------
| SAVE NEW ASSET
|--------------------------------------------------------------------------
*/

async function saveNewAsset(row){

    const inputs =
        row.querySelectorAll(
            'td[data-field] input'
        );


    const data = {};


    inputs.forEach(
        function(input){

            const field =
                input.closest(
                    'td'
                ).dataset.field;


            data[field] =
                input.value.trim();

        }
    );


    if(!data.namaasset){

        Swal.fire({

            icon:'warning',

            title:'Nama Asset Kosong',

            text:'Silakan isi nama asset terlebih dahulu.'

        });

        return;

    }


    Swal.fire({

        title:'Menambahkan asset...',

        allowOutsideClick:false,

        didOpen:() => {

            Swal.showLoading();

        }

    });


    const body =
        new URLSearchParams();


    body.append(
        'action',
        'add'
    );

    body.append(
        'tenant',
        currentTenant
    );

    body.append(
        'namaasset',
        data.namaasset || ''
    );

    body.append(
        'kategori',
        data.kategori || ''
    );

    body.append(
        'jumlah',
        data.jumlah || '0'
    );

    body.append(
        'kondisi',
        data.kondisi || ''
    );

    body.append(
        'lokasi',
        data.lokasi || ''
    );

    body.append(
        'catatan',
        data.catatan || ''
    );


    try{

        const response =
            await fetch(
                'inventaris.php',
                {
                    method:'POST',

                    headers:{
                        'Content-Type':
                            'application/x-www-form-urlencoded'
                    },

                    body:
                        body.toString()
                }
            );


        const result =
            await response.json();


        if(
            result.status !==
            'success'
        ){

            throw new Error(
                result.message ||
                'Gagal menambahkan asset.'
            );

        }


        /*
        |--------------------------------------------------------------------------
        | Ubah row baru menjadi row normal
        |--------------------------------------------------------------------------
        */

        row.dataset.id =
            result.id;

        row.dataset.row =
            'asset';

        row.classList.remove(
            'new-row'
        );


        inputs.forEach(
            function(input){

                const cell =
                    input.closest(
                        'td'
                    );


                cell.textContent =
                    input.value.trim();

            }
        );


        row.querySelector(
            '.action-cell'
        ).innerHTML = `

            <button
                type="button"
                class="btn btn-sm btn-success btn-edit"
            >

                <i class="fa-solid fa-pen"></i>

                Edit

            </button>

        `;


        updateTotals();


        Swal.fire({

            icon:'success',

            title:'Asset Berhasil Ditambahkan',

            text:
                'Data asset langsung tersimpan ke database.',

            timer:1300,

            showConfirmButton:false

        });


    }catch(error){

        Swal.fire({

            icon:'error',

            title:'Gagal!',

            text:
                error.message ||
                'Asset gagal ditambahkan.'

        });

    }

}


/*
|--------------------------------------------------------------------------
| CANCEL NEW
|--------------------------------------------------------------------------
*/

function cancelNewAsset(row){

    row.remove();


    updateTotals();

}


/*
|--------------------------------------------------------------------------
| NEW BUTTON EVENTS
|--------------------------------------------------------------------------
*/

document
.getElementById('inventarisTable')
.addEventListener(
    'click',
    function(e){

        const saveNew =
            e.target.closest(
                '.btn-save-new'
            );


        if(saveNew){

            const row =
                saveNew.closest(
                    'tr'
                );

            saveNewAsset(row);

            return;

        }


        const cancelNew =
            e.target.closest(
                '.btn-cancel-new'
            );


        if(cancelNew){

            const row =
                cancelNew.closest(
                    'tr'
                );

            cancelNewAsset(row);

            return;

        }

    }
);


/*
|--------------------------------------------------------------------------
| DELETE
|--------------------------------------------------------------------------
*/

async function deleteAsset(row){

    const id =
        row.dataset.id;


    if(!id){
        return;
    }


    const confirm =
        await Swal.fire({

            icon:'warning',

            title:'Hapus Asset?',

            text:
                'Data asset akan dihapus dari database.',

            showCancelButton:true,

            confirmButtonText:
                'Ya, Hapus',

            cancelButtonText:
                'Batal',

            confirmButtonColor:
                '#dc3545'

        });


    if(!confirm.isConfirmed){
        return;
    }


    const body =
        new URLSearchParams();


    body.append(
        'action',
        'delete'
    );

    body.append(
        'id',
        id
    );


    Swal.fire({

        title:'Menghapus...',

        allowOutsideClick:false,

        didOpen:() => {

            Swal.showLoading();

        }

    });


    try{

        const response =
            await fetch(
                'inventaris.php',
                {
                    method:'POST',

                    headers:{
                        'Content-Type':
                            'application/x-www-form-urlencoded'
                    },

                    body:
                        body.toString()
                }
            );


        const result =
            await response.json();


        if(
            result.status !==
            'success'
        ){

            throw new Error(
                result.message ||
                'Gagal menghapus asset.'
            );

        }


        row.remove();


        updateTotals();


        Swal.fire({

            icon:'success',

            title:'Terhapus!',

            text:
                'Asset berhasil dihapus.',

            timer:1000,

            showConfirmButton:false

        });


    }catch(error){

        Swal.fire({

            icon:'error',

            title:'Gagal!',

            text:
                error.message

        });

    }

}


/*
|--------------------------------------------------------------------------
| UPDATE TOTAL
|--------------------------------------------------------------------------
*/

function updateTotals(){

    const rows =
        document.querySelectorAll(
            '#inventarisTable tr[data-row="asset"]'
        );


    let totalAsset =
        0;


    rows.forEach(
        function(row){

            const jumlahCell =
                row.querySelector(
                    'td[data-field="jumlah"]'
                );


            if(jumlahCell){

                const jumlah =
                    parseInt(
                        jumlahCell.textContent.trim()
                    ) || 0;


                totalAsset +=
                    jumlah;

            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | Total Data
    |--------------------------------------------------------------------------
    */

    const totalData =
        rows.length;


    const summaryData =
        document.getElementById(
            'summaryTotalData'
        );


    if(summaryData){

        summaryData.textContent =
            totalData;

    }


    const footerData =
        document.getElementById(
            'footerTotalData'
        );


    if(footerData){

        footerData.textContent =
            totalData;

    }


    const tenantCount =
        document.getElementById(
            'tenantDataCount'
        );


    if(tenantCount){

        tenantCount.textContent =
            totalData +
            ' data inventaris';

    }


    /*
    |--------------------------------------------------------------------------
    | Total Asset
    |--------------------------------------------------------------------------
    */

    const summaryAsset =
        document.getElementById(
            'summaryTotalAsset'
        );


    if(summaryAsset){

        summaryAsset.textContent =
            totalAsset;

    }


    const footerAsset =
        document.getElementById(
            'footerTotalAsset'
        );


    if(footerAsset){

        footerAsset.textContent =
            totalAsset;

    }

}


/*
|--------------------------------------------------------------------------
| REFRESH
|--------------------------------------------------------------------------
*/

document
.getElementById('btnRefresh')
?.addEventListener(
    'click',
    function(){

        window.location.reload();

    }
);


/*
|--------------------------------------------------------------------------
| ENTER = SAVE
|--------------------------------------------------------------------------
*/

document
.getElementById('inventarisTable')
.addEventListener(
    'keydown',
    function(e){

        if(
            e.key !==
            'Enter'
        ){
            return;
        }


        const input =
            e.target.closest(
                '.inline-input'
            );


        if(!input){
            return;
        }


        e.preventDefault();


        const row =
            input.closest(
                'tr'
            );


        if(
            row.dataset.row ===
            'new'
        ){

            saveNewAsset(row);

        }else{

            saveEdit(row);

        }

    }
);


/*
|--------------------------------------------------------------------------
| INITIAL TOTAL
|--------------------------------------------------------------------------
*/

updateTotals();

</script>

<?php endif; ?>


</body>

</html>
