<?php 
/* 
|-------------------------------------------------------------------------- 
| LAPORAN REALISASI PENGEMBANGAN DAN ACCEPT REQUEST 
| SYSTEM SQM - WEBPORTAL RETAIL 
|-------------------------------------------------------------------------- 
| Compatible: PHP 7.4 / PHP 8.x 
| PDF: DOMPDF 
|-------------------------------------------------------------------------- 
*/ 
 
error_reporting(E_ALL); 
ini_set('display_errors', '1'); 
date_default_timezone_set('Asia/Jakarta'); 
 
$self = basename(__FILE__); 
 
/* 
|-------------------------------------------------------------------------- 
| FUNGSI DASAR 
|-------------------------------------------------------------------------- 
*/ 
 
function e($value) 
{ 
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); 
} 
 
function redirectSelf() 
{ 
    global $self; 
 
    header('Location: ' . $self); 
    exit; 
} 
 
function formatTanggalIndonesia($tanggal) 
{ 
    if (empty($tanggal) || $tanggal === '0000-00-00') { 
        return '-'; 
    } 
 
    $bulan = [ 
        1  => 'Januari', 
        2  => 'Februari', 
        3  => 'Maret', 
        4  => 'April', 
        5  => 'Mei', 
        6  => 'Juni', 
        7  => 'Juli', 
        8  => 'Agustus', 
        9  => 'September', 
        10 => 'Oktober', 
        11 => 'November', 
        12 => 'Desember' 
    ]; 
 
    $timestamp = strtotime($tanggal); 
 
    if (!$timestamp) { 
        return $tanggal; 
    } 
 
    $hari = date('j', $timestamp); 
    $bln  = (int)date('n', $timestamp); 
    $tahun = date('Y', $timestamp); 
 
    return $hari . ' ' . $bulan[$bln] . ' ' . $tahun; 
} 
 
 
/* 
|-------------------------------------------------------------------------- 
| AUTO CREATE DB.PHP 
|-------------------------------------------------------------------------- 
*/ 
 
$dbFile = __DIR__ . '/db.php'; 
 
if (!file_exists($dbFile)) { 
 
    $dbPhp = <<<'PHP'
<?php 
 
$host = "localhost"; 
$user = "root"; 
$pass = ""; 
$db   = "sqm_report"; 
 
$conn = mysqli_connect($host, $user, $pass); 
 
if (!$conn) { 
    die("Koneksi MySQL gagal: " . mysqli_connect_error()); 
} 
 
mysqli_set_charset($conn, "utf8mb4"); 
 
$sqlDatabase = " 
CREATE DATABASE IF NOT EXISTS `$db` 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci 
"; 
 
if (!mysqli_query($conn, $sqlDatabase)) { 
    die("Gagal membuat database: " . mysqli_error($conn)); 
} 
 
if (!mysqli_select_db($conn, $db)) { 
    die("Gagal memilih database: " . mysqli_error($conn)); 
} 
 
PHP;
 
    @file_put_contents($dbFile, $dbPhp); 
} 
 
 
/* 
|-------------------------------------------------------------------------- 
| KONEKSI 
|-------------------------------------------------------------------------- 
*/ 
 
if (file_exists($dbFile)) { 
 
    require_once $dbFile; 
 
} else { 
 
    /* 
    | Fallback jika server tidak mengizinkan pembuatan db.php otomatis 
    */ 
 
    $conn = mysqli_connect( 
        'localhost', 
        'root', 
        '' 
    ); 
 
    if (!$conn) { 
        die( 
            'Koneksi database gagal: ' . 
            mysqli_connect_error() 
        ); 
    } 
 
    mysqli_set_charset( 
        $conn, 
        'utf8mb4' 
    ); 
 
    mysqli_query( 
        $conn, 
        " 
        CREATE DATABASE IF NOT EXISTS sqm_report 
        CHARACTER SET utf8mb4 
        COLLATE utf8mb4_unicode_ci 
        " 
    ); 
 
    mysqli_select_db( 
        $conn, 
        'sqm_report' 
    ); 
} 
 
 
if (!isset($conn) || !$conn) { 
    die('Variabel koneksi $conn tidak ditemukan pada db.php'); 
} 
 
 
/* 
|-------------------------------------------------------------------------- 
| PASTIKAN DATABASE TERPILIH 
|-------------------------------------------------------------------------- 
*/ 
 
mysqli_query( 
    $conn, 
    " 
    CREATE DATABASE IF NOT EXISTS sqm_report 
    CHARACTER SET utf8mb4 
    COLLATE utf8mb4_unicode_ci 
    " 
); 
 
mysqli_select_db( 
    $conn, 
    'sqm_report' 
); 
 
mysqli_set_charset( 
    $conn, 
    'utf8mb4' 
); 
 
 
/* 
|-------------------------------------------------------------------------- 
| CREATE TABLE OTOMATIS 
|-------------------------------------------------------------------------- 
*/ 
 
$sqlCreateTable = " 
CREATE TABLE IF NOT EXISTS laporan_sqm ( 
 
    id INT UNSIGNED NOT NULL AUTO_INCREMENT, 
 
    nama VARCHAR(100) NOT NULL, 
 
    divisi VARCHAR(100) NOT NULL DEFAULT 'Retail', 
 
    tanggal_update DATE NOT NULL, 
 
    project VARCHAR(255) NOT NULL, 
 
    deskripsi TEXT NOT NULL, 
 
    problem TEXT NOT NULL, 
 
    tindakan TEXT NOT NULL, 
 
    result TEXT NOT NULL, 
 
    created_at TIMESTAMP 
        NOT NULL 
        DEFAULT CURRENT_TIMESTAMP, 
 
    updated_at TIMESTAMP 
        NOT NULL 
        DEFAULT CURRENT_TIMESTAMP 
        ON UPDATE CURRENT_TIMESTAMP, 
 
    PRIMARY KEY (id), 
 
    INDEX idx_nama (nama), 
 
    INDEX idx_tanggal (tanggal_update) 
 
) 
ENGINE=InnoDB 
DEFAULT CHARSET=utf8mb4 
COLLATE=utf8mb4_unicode_ci 
"; 
 
if (!mysqli_query($conn, $sqlCreateTable)) { 
 
    die( 
        'Gagal membuat table laporan_sqm: ' . 
        mysqli_error($conn) 
    ); 
} 
 
 
/* 
|-------------------------------------------------------------------------- 
| MIGRATION 
| Kalau table lama belum punya field Project, tambahkan otomatis 
|-------------------------------------------------------------------------- 
*/ 
 
$cekProject = mysqli_query( 
    $conn, 
    " 
    SHOW COLUMNS 
    FROM laporan_sqm 
    LIKE 'project' 
    " 
); 
 
if ($cekProject && mysqli_num_rows($cekProject) === 0) { 
 
    mysqli_query( 
        $conn, 
        " 
        ALTER TABLE laporan_sqm 
        ADD COLUMN project VARCHAR(255) 
        NOT NULL DEFAULT '' 
        AFTER tanggal_update 
        " 
    ); 
} 
 
 
/* 
|-------------------------------------------------------------------------- 
| NAMA YANG DIIZINKAN 
|-------------------------------------------------------------------------- 
*/ 
 
$allowedNames = [ 
    'Adrian Fauzan', 
    'Wira Darmawan' 
]; 
 
 
/* 
|-------------------------------------------------------------------------- 
| EXPORT PDF 
|-------------------------------------------------------------------------- 
| 
| ?pdf=all&nama_laporan=Adrian%20Fauzan 
| = History untuk satu nama yang dipilih 
| 
| ?pdf=12 
| = Satu laporan ID 12 
|-------------------------------------------------------------------------- 
*/ 
 
if (isset($_GET['pdf'])) { 
 
    $autoload = __DIR__ . '/vendor/autoload.php'; 
 
    if (!file_exists($autoload)) { 
 
        die( 
            '<div style=" 
                font-family:Arial; 
                max-width:700px; 
                margin:50px auto; 
                padding:25px; 
                border:1px solid #ddd; 
                border-radius:12px; 
            "> 
                <h2>DOMPDF belum terinstall</h2> 
 
                <p>Jalankan perintah berikut dari folder project:</p> 
 
                <pre style=" 
                    background:#f5f5f5; 
                    padding:15px; 
                    border-radius:8px; 
                ">composer require dompdf/dompdf</pre> 
 
                <p> 
                    Setelah selesai, pastikan ada file: 
                </p> 
 
                <pre>vendor/autoload.php</pre> 
 
                <p> 
                    <a href="' . e($self) . '"> 
                        Kembali 
                    </a> 
                </p> 
            </div>' 
        ); 
    } 
 
    require_once $autoload; 
 
 
    /* 
    |-------------------------------------------------------------------------- 
    | CSS PDF 
    |-------------------------------------------------------------------------- 
    */ 
 
    $pdfCss = ' 
    <style> 
 
        @page { 
            margin: 
                14mm 
                10mm 
                18mm 
                10mm; 
        } 
 
        * { 
            box-sizing: 
                border-box; 
        } 
 
        body { 
            margin: 0; 
            padding: 0; 
            font-family: 
                DejaVu Sans, 
                Arial, 
                sans-serif; 
            font-size: 
                9px; 
            color: 
                #1f2937; 
        } 
 
        .report-title { 
            text-align: 
                center; 
            font-size: 
                15px; 
            line-height: 
                1.45; 
            font-weight: 
                bold; 
            margin-bottom: 
                16px; 
            color: 
                #163a5f; 
        } 
 
        .sub-title { 
            font-size: 
                13px; 
        } 
 
        .info-table { 
            width: 
                100%; 
            border-collapse: 
                collapse; 
            margin-bottom: 
                15px; 
        } 
 
        .info-table td { 
            border-bottom: 
                1px solid #d6dce3; 
            padding: 
                5px 7px; 
            vertical-align: 
                top; 
        } 
 
        .info-label { 
            width: 
                120px; 
            font-weight: 
                bold; 
            color: 
                #374151; 
        } 
 
        .info-separator { 
            width: 
                10px; 
            text-align: 
                center; 
        } 
 
        table.report-table { 
            width: 
                100%; 
            border-collapse: 
                collapse; 
            table-layout: 
                fixed; 
        } 
 
        .report-table thead { 
            display: 
                table-header-group; 
        } 
 
        .report-table th { 
            border: 
                1px solid #7890a8; 
            background: 
                #e6edf5; 
            color: 
                #163a5f; 
            font-weight: 
                bold; 
            text-align: 
                center; 
            padding: 
                7px 5px; 
            line-height: 
                1.3; 
        } 
 
        .report-table td { 
            border: 
                1px solid #a8b3bf; 
            padding: 
                6px; 
            vertical-align: 
                top; 
            line-height: 
                1.45; 
            word-wrap: 
                break-word; 
            overflow-wrap: 
                break-word; 
        } 
 
        .number-cell { 
            text-align: 
                center; 
            width: 
                4%; 
        } 
 
        tr { 
            page-break-inside: 
                avoid; 
        } 
 
        .empty { 
            text-align: 
                center; 
            padding: 
                20px; 
            color: 
                #777; 
        } 
 
        .footer { 
            position: 
                fixed; 
            left: 
                0; 
            right: 
                0; 
            bottom: 
                -10mm; 
            text-align: 
                center; 
            font-size: 
                7px; 
            color: 
                #8a929a; 
        } 
 
    </style> 
    '; 
 
 
    /* 
    |-------------------------------------------------------------------------- 
    | PDF SEMUA HISTORY 
    |-------------------------------------------------------------------------- 
    */ 
 
    if ($_GET['pdf'] === 'all') { 
 
        $reportNama = trim($_GET['nama_laporan'] ?? ''); 
 
        $tanggalMulai = trim($_GET['tanggal_mulai'] ?? ''); 
        $tanggalSelesai = trim($_GET['tanggal_selesai'] ?? ''); 
 
        if (!in_array($reportNama, $allowedNames, true)) { 
            die('Silakan pilih nama terlebih dahulu sebelum preview / export PDF.'); 
        } 
 
        $sqlPdfAll = " 
            SELECT * 
            FROM laporan_sqm 
            WHERE nama = ? 
        "; 
 
        $paramsPdf = [ 
            $reportNama 
        ]; 
 
        $typesPdf = "s"; 
 
        if ($tanggalMulai !== '' && $tanggalSelesai !== '') { 
 
            $sqlPdfAll .= " 
                AND tanggal_update BETWEEN ? AND ? 
            "; 
 
            $paramsPdf[] = $tanggalMulai; 
            $paramsPdf[] = $tanggalSelesai; 
            $typesPdf .= "ss"; 
        } 
 
        $sqlPdfAll .= " 
            ORDER BY 
                tanggal_update DESC, 
                id DESC 
        "; 
 
        $stmtPdfAll = mysqli_prepare( 
            $conn, 
            $sqlPdfAll 
        ); 
 
        mysqli_stmt_bind_param( 
            $stmtPdfAll, 
            $typesPdf, 
            ...$paramsPdf 
        ); 
 
        mysqli_stmt_execute($stmtPdfAll); 
 
        $queryPdf = mysqli_stmt_get_result($stmtPdfAll); 
 
        $html = ' 
        <!DOCTYPE html> 
        <html> 
        <head> 
            <meta charset="UTF-8"> 
            ' . $pdfCss . ' 
        </head> 
 
        <body> 
 
            <div class="report-title"> 
 
                LAPORAN REALISASI PENGEMBANGAN 
                DAN ACCEPT REQUEST 
 
                <br> 
 
                <span class="sub-title"> 
                    SYSTEM SQM - WEBPORTAL RETAIL 
 
                </span>  
 
                <br> 
 
        
            </div> 
 
 
            <table class="report-table"> 
 
                <thead> 
 
                    <tr> 
 
                        <th style="width:3%"> 
                            No 
                        </th> 
 
                        <th style="width:8%"> 
                            Nama 
                        </th> 
 
                        <th style="width:6%"> 
                            Divisi 
                        </th> 
 
                        <th style="width:8%"> 
                            Tanggal Update 
                        </th> 
 
                        <th style="width:12%"> 
                            Project 
                        </th> 
 
                        <th style="width:15%"> 
                            Deskripsi 
                        </th> 
 
                        <th style="width:15%"> 
                            Problem 
                        </th> 
 
                        <th style="width:18%"> 
                            Tindakan Perbaikan 
                        </th> 
 
                        <th style="width:15%"> 
                            Result 
                        </th> 
 
                    </tr> 
 
                </thead> 
 
                <tbody> 
        '; 
 
        $nomor = 1; 
 
        if ( 
            $queryPdf && 
            mysqli_num_rows($queryPdf) > 0 
        ) { 
 
            while ( 
                $rowPdf = 
                mysqli_fetch_assoc($queryPdf) 
            ) { 
 
                $html .= ' 
 
                <tr> 
 
                    <td class="number-cell"> 
                        ' . $nomor++ . ' 
                    </td> 
 
                    <td> 
                        ' . e($rowPdf['nama']) . ' 
                    </td> 
 
                    <td> 
                        ' . e($rowPdf['divisi']) . ' 
                    </td> 
 
                    <td style="text-align:center"> 
                        ' . 
                        e( 
                            formatTanggalIndonesia( 
                                $rowPdf['tanggal_update'] 
                            ) 
                        ) 
                        . ' 
                    </td> 
 
                    <td> 
                        ' . e($rowPdf['project']) . ' 
                    </td> 
 
                    <td> 
                        ' . 
                        nl2br( 
                            e( 
                                $rowPdf['deskripsi'] 
                            ) 
                        ) 
                        . ' 
                    </td> 
 
                    <td> 
                        ' . 
                        nl2br( 
                            e( 
                                $rowPdf['problem'] 
                            ) 
                        ) 
                        . ' 
                    </td> 
 
                    <td> 
                        ' . 
                        nl2br( 
                            e( 
                                $rowPdf['tindakan'] 
                            ) 
                        ) 
                        . ' 
                    </td> 
 
                    <td> 
                        ' . 
                        nl2br( 
                            e( 
                                $rowPdf['result'] 
                            ) 
                        ) 
                        . ' 
                    </td> 
 
                </tr> 
                '; 
            } 
 
        } else { 
 
            $html .= ' 
 
            <tr> 
 
                <td 
                    colspan="9" 
                    class="empty" 
                > 
                    Belum ada data laporan. 
                </td> 
 
            </tr> 
            '; 
        } 
 
        $html .= ' 
                </tbody> 
 
            </table> 
 
 
            <div class="footer"> 
                Generated by Retail System SQM 
            </div> 
 
        </body> 
        </html> 
        '; 
 
        $fileName = 
            'History_Laporan_SQM_' . 
            preg_replace( 
                '/[^A-Za-z0-9_-]/', 
                '_', 
                $reportNama 
            ) . 
            '_' . 
            date('Ymd_His') . 
            '.pdf'; 
 
        mysqli_stmt_close($stmtPdfAll); 
    } 
 
 
    /* 
    |-------------------------------------------------------------------------- 
    | PDF SATU DATA 
    |-------------------------------------------------------------------------- 
    */ 
 
    else { 
 
        $pdfId = 
            filter_var( 
                $_GET['pdf'], 
                FILTER_VALIDATE_INT 
            ); 
 
        if (!$pdfId) { 
            die('ID PDF tidak valid.'); 
        } 
 
        $stmtPdf = mysqli_prepare( 
            $conn, 
            " 
            SELECT * 
            FROM laporan_sqm 
            WHERE id = ? 
            LIMIT 1 
            " 
        ); 
 
        mysqli_stmt_bind_param( 
            $stmtPdf, 
            'i', 
            $pdfId 
        ); 
 
        mysqli_stmt_execute( 
            $stmtPdf 
        ); 
 
        $resultPdf = 
            mysqli_stmt_get_result( 
                $stmtPdf 
            ); 
 
        $rowPdf = 
            mysqli_fetch_assoc( 
                $resultPdf 
            ); 
 
        mysqli_stmt_close( 
            $stmtPdf 
        ); 
 
        if (!$rowPdf) { 
 
            die( 
                'Data laporan tidak ditemukan.' 
            ); 
        } 
 
 
        $html = ' 
        <!DOCTYPE html> 
 
        <html> 
 
        <head> 
 
            <meta charset="UTF-8"> 
 
            ' . $pdfCss . ' 
 
        </head> 
 
 
        <body> 
 
 
            <div class="report-title"> 
 
                LAPORAN REALISASI PENGEMBANGAN 
                DAN ACCEPT REQUEST 
 
                <br> 
 
                <span class="sub-title"> 
 
                    SYSTEM SQM - WEBPORTAL RETAIL 
 
                </span> 
 
            </div> 
 
 
            <table class="info-table"> 
 
                <tr> 
 
                    <td class="info-label"> 
                        Nama 
                    </td> 
 
                    <td class="info-separator"> 
                        : 
                    </td> 
 
                    <td> 
                        ' . e($rowPdf['nama']) . ' 
                    </td> 
 
                </tr> 
 
 
                <tr> 
 
                    <td class="info-label"> 
                        Divisi 
                    </td> 
 
                    <td class="info-separator"> 
                        : 
                    </td> 
 
                    <td> 
                        ' . e($rowPdf['divisi']) . ' 
                    </td> 
 
                </tr> 
 
 
                <tr> 
 
                    <td class="info-label"> 
                        Tanggal Update 
                    </td> 
 
                    <td class="info-separator"> 
                        : 
                    </td> 
 
                    <td> 
                        ' . 
                        e( 
                            formatTanggalIndonesia( 
                                $rowPdf['tanggal_update'] 
                            ) 
                        ) 
                        . ' 
                    </td> 
 
                </tr> 
 
 
                <tr> 
 
                    <td class="info-label"> 
                        Project 
                    </td> 
 
                    <td class="info-separator"> 
                        : 
                    </td> 
 
                    <td> 
                        ' . e($rowPdf['project']) . ' 
                    </td> 
 
                </tr> 
 
            </table> 
 
 
            <table class="report-table"> 
 
                <thead> 
 
                    <tr> 
 
                        <th style="width:5%"> 
                            No 
                        </th> 
 
                        <th style="width:20%"> 
                            Deskripsi 
                        </th> 
 
                        <th style="width:22%"> 
                            Problem 
                        </th> 
 
                        <th style="width:30%"> 
                            Tindakan Perbaikan 
                        </th> 
 
                        <th style="width:23%"> 
                            Result 
                        </th> 
 
                    </tr> 
 
                </thead> 
 
 
                <tbody> 
 
                    <tr> 
 
                        <td class="number-cell"> 
                            1 
                        </td> 
 
                        <td> 
                            ' . 
                            nl2br( 
                                e( 
                                    $rowPdf['deskripsi'] 
                                ) 
                            ) 
                            . ' 
                        </td> 
 
                        <td> 
                            ' . 
                            nl2br( 
                                e( 
                                    $rowPdf['problem'] 
                                ) 
                            ) 
                            . ' 
                        </td> 
 
                        <td> 
                            ' . 
                            nl2br( 
                                e( 
                                    $rowPdf['tindakan'] 
                                ) 
                            ) 
                            . ' 
                        </td> 
 
                        <td> 
                            ' . 
                            nl2br( 
                                e( 
                                    $rowPdf['result'] 
                                ) 
                            ) 
                            . ' 
                        </td> 
 
                    </tr> 
 
                </tbody> 
 
            </table> 
 
 
            <div class="footer"> 
 
                Generated by Retail System SQM 
 
            </div> 
 
 
        </body> 
 
        </html> 
        '; 
 
 
        $fileName = 
            'Laporan_SQM_' . 
            preg_replace( 
                '/[^A-Za-z0-9_-]/', 
                '_', 
                $rowPdf['nama'] 
            ) . 
            '_' . 
            date('Ymd') . 
            '.pdf'; 
    } 
 
 
    /* 
    |-------------------------------------------------------------------------- 
    | CREATE DOMPDF 
    |-------------------------------------------------------------------------- 
    */ 
 
    $options = 
        new \Dompdf\Options(); 
 
    $options->set( 
        'isRemoteEnabled', 
        false 
    ); 
 
    $options->set( 
        'isHtml5ParserEnabled', 
        true 
    ); 
 
    $dompdf = 
        new \Dompdf\Dompdf( 
            $options 
        ); 
 
    $dompdf->loadHtml( 
        $html, 
        'UTF-8' 
    ); 
 
    $dompdf->setPaper( 
        'A4', 
        'landscape' 
    ); 
 
    $dompdf->render(); 
 
    $dompdf->stream( 
        $fileName, 
        [ 
            'Attachment' => false 
        ] 
    ); 
 
    exit; 
} 
 
 
/* 
|-------------------------------------------------------------------------- 
| SIMPAN / UPDATE DATA 
|-------------------------------------------------------------------------- 
*/ 
 
$message = ''; 
$messageType = 'success'; 
 
if ( 
    $_SERVER['REQUEST_METHOD'] === 'POST' && 
    isset($_POST['action']) 
) { 
 
    /* 
    |-------------------------------------------------------------------------- 
    | SIMPAN DATA BARU 
    |-------------------------------------------------------------------------- 
    */ 
 
    if ($_POST['action'] === 'save') { 
 
        $nama = 
            trim( 
                $_POST['nama'] ?? '' 
            ); 
 
        $divisi = 
            trim( 
                $_POST['divisi'] ?? '' 
            ); 
 
        $tanggal = 
            trim( 
                $_POST['tanggal_update'] ?? '' 
            ); 
 
        $project = 
            trim( 
                $_POST['project'] ?? '' 
            ); 
 
        $deskripsi = 
            trim( 
                $_POST['deskripsi'] ?? '' 
            ); 
 
        $problem = 
            trim( 
                $_POST['problem'] ?? '' 
            ); 
 
        $tindakan = 
            trim( 
                $_POST['tindakan'] ?? '' 
            ); 
 
        $result = 
            trim( 
                $_POST['result'] ?? '' 
            ); 
 
 
        if ( 
            !in_array( 
                $nama, 
                $allowedNames, 
                true 
            ) 
        ) { 
 
            $message = 
                'Nama tidak valid.'; 
 
            $messageType = 
                'danger'; 
 
        } elseif ( 
            $divisi === '' || 
            $tanggal === '' || 
            $project === '' || 
            $deskripsi === '' || 
            $problem === '' || 
            $tindakan === '' || 
            $result === '' 
        ) { 
 
            $message = 
                'Semua field wajib diisi.'; 
 
            $messageType = 
                'danger'; 
 
        } else { 
 
            $stmt = 
                mysqli_prepare( 
                    $conn, 
                    " 
                    INSERT INTO laporan_sqm 
                    ( 
                        nama, 
                        divisi, 
                        tanggal_update, 
                        project, 
                        deskripsi, 
                        problem, 
                        tindakan, 
                        result 
                    ) 
                    VALUES 
                    (?, ?, ?, ?, ?, ?, ?, ?) 
                    " 
                ); 
 
            mysqli_stmt_bind_param( 
                $stmt, 
                'ssssssss', 
                $nama, 
                $divisi, 
                $tanggal, 
                $project, 
                $deskripsi, 
                $problem, 
                $tindakan, 
                $result 
            ); 
 
            if ( 
                mysqli_stmt_execute( 
                    $stmt 
                ) 
            ) { 
 
                mysqli_stmt_close( 
                    $stmt 
                ); 
 
                header( 
                    'Location: ' . 
                    $self . 
                    '?status=saved' 
                ); 
 
                exit; 
 
            } else { 
 
                $message = 
                    'Gagal menyimpan data: ' . 
                    mysqli_stmt_error( 
                        $stmt 
                    ); 
 
                $messageType = 
                    'danger'; 
 
                mysqli_stmt_close( 
                    $stmt 
                ); 
            } 
        } 
    } 
 
 
    /* 
    |-------------------------------------------------------------------------- 
    | UPDATE 
    |-------------------------------------------------------------------------- 
    */ 
 
    if ($_POST['action'] === 'update') { 
 
        $id = 
            (int)( 
                $_POST['id'] ?? 0 
            ); 
 
        $nama = 
            trim( 
                $_POST['nama'] ?? '' 
            ); 
 
        $divisi = 
            trim( 
                $_POST['divisi'] ?? '' 
            ); 
 
        $tanggal = 
            trim( 
                $_POST['tanggal_update'] ?? '' 
            ); 
 
        $project = 
            trim( 
                $_POST['project'] ?? '' 
            ); 
 
        $deskripsi = 
            trim( 
                $_POST['deskripsi'] ?? '' 
            ); 
 
        $problem = 
            trim( 
                $_POST['problem'] ?? '' 
            ); 
 
        $tindakan = 
            trim( 
                $_POST['tindakan'] ?? '' 
            ); 
 
        $result = 
            trim( 
                $_POST['result'] ?? '' 
            ); 
 
        $stmt = 
            mysqli_prepare( 
                $conn, 
                " 
                UPDATE laporan_sqm 
 
                SET 
                    nama = ?, 
                    divisi = ?, 
                    tanggal_update = ?, 
                    project = ?, 
                    deskripsi = ?, 
                    problem = ?, 
                    tindakan = ?, 
                    result = ? 
 
                WHERE id = ? 
                " 
            ); 
 
        mysqli_stmt_bind_param( 
            $stmt, 
            'ssssssssi', 
            $nama, 
            $divisi, 
            $tanggal, 
            $project, 
            $deskripsi, 
            $problem, 
            $tindakan, 
            $result, 
            $id 
        ); 
 
        if ( 
            mysqli_stmt_execute( 
                $stmt 
            ) 
        ) { 
 
            mysqli_stmt_close( 
                $stmt 
            ); 
 
            header( 
                'Location: ' . 
                $self . 
                '?status=updated' 
            ); 
 
            exit; 
 
        } else { 
 
            $message = 
                'Gagal update: ' . 
                mysqli_stmt_error( 
                    $stmt 
                ); 
 
            $messageType = 
                'danger'; 
 
            mysqli_stmt_close( 
                $stmt 
            ); 
        } 
    } 
 
 
    /* 
    |-------------------------------------------------------------------------- 
    | HAPUS 
    |-------------------------------------------------------------------------- 
    */ 
 
    if ($_POST['action'] === 'delete') { 
 
        $id = 
            (int)( 
                $_POST['id'] ?? 0 
            ); 
 
        if ($id > 0) { 
 
            $stmt = 
                mysqli_prepare( 
                    $conn, 
                    " 
                    DELETE 
                    FROM laporan_sqm 
                    WHERE id = ? 
                    " 
                ); 
 
            mysqli_stmt_bind_param( 
                $stmt, 
                'i', 
                $id 
            ); 
 
            mysqli_stmt_execute( 
                $stmt 
            ); 
 
            mysqli_stmt_close( 
                $stmt 
            ); 
        } 
 
        header( 
            'Location: ' . 
            $self . 
            '?status=deleted' 
        ); 
 
        exit; 
    } 
} 
 
 
/* 
|-------------------------------------------------------------------------- 
| STATUS MESSAGE 
|-------------------------------------------------------------------------- 
*/ 
 
if ( 
    isset($_GET['status']) 
) { 
 
    if ( 
        $_GET['status'] === 'saved' 
    ) { 
 
        $message = 
            'Data berhasil disimpan.'; 
 
    } 
 
    elseif ( 
        $_GET['status'] === 'updated' 
    ) { 
 
        $message = 
            'Data berhasil diperbarui.'; 
 
    } 
 
    elseif ( 
        $_GET['status'] === 'deleted' 
    ) { 
 
        $message = 
            'Data berhasil dihapus.'; 
 
    } 
} 
 
 
/* 
|-------------------------------------------------------------------------- 
| MODE EDIT 
|-------------------------------------------------------------------------- 
*/ 
 
$editData = null; 
 
if ( 
    isset($_GET['edit']) 
) { 
 
    $editId = 
        filter_var( 
            $_GET['edit'], 
            FILTER_VALIDATE_INT 
        ); 
 
    if ($editId) { 
 
        $stmtEdit = 
            mysqli_prepare( 
                $conn, 
                " 
                SELECT * 
                FROM laporan_sqm 
                WHERE id = ? 
                LIMIT 1 
                " 
            ); 
 
        mysqli_stmt_bind_param( 
            $stmtEdit, 
            'i', 
            $editId 
        ); 
 
        mysqli_stmt_execute( 
            $stmtEdit 
        ); 
 
        $editResult = 
            mysqli_stmt_get_result( 
                $stmtEdit 
            ); 
 
        $editData = 
            mysqli_fetch_assoc( 
                $editResult 
            ); 
 
        mysqli_stmt_close( 
            $stmtEdit 
        ); 
    } 
} 
 
 
/* 
|-------------------------------------------------------------------------- 
| HISTORY 
|-------------------------------------------------------------------------- 
*/ 
 
$selectedReportName = 
    trim($_GET['nama_laporan'] ?? ''); 
 
if (!in_array($selectedReportName, $allowedNames, true)) { 
    $selectedReportName = ''; 
} 
 
$data = false; 
$totalData = 0; 
 
if ($selectedReportName !== '') { 
 
    $sqlHistory = " 
        SELECT * 
        FROM laporan_sqm 
        WHERE nama = ? 
    "; 
 
    $paramsHistory = [ 
        $selectedReportName 
    ]; 
 
    $typesHistory = "s"; 
 
    $tanggalMulaiHistory = trim($_GET['tanggal_mulai'] ?? ''); 
    $tanggalSelesaiHistory = trim($_GET['tanggal_selesai'] ?? ''); 
 
    if ($tanggalMulaiHistory !== '' && $tanggalSelesaiHistory !== '') { 
 
        $sqlHistory .= " 
            AND tanggal_update BETWEEN ? AND ? 
        "; 
 
        $paramsHistory[] = $tanggalMulaiHistory; 
        $paramsHistory[] = $tanggalSelesaiHistory; 
        $typesHistory .= "ss"; 
    } 
 
    $sqlHistory .= " 
        ORDER BY 
            tanggal_update DESC, 
            id DESC 
    "; 
 
    $stmtHistory = mysqli_prepare( 
        $conn, 
        $sqlHistory 
    ); 
 
    mysqli_stmt_bind_param( 
        $stmtHistory, 
        $typesHistory, 
        ...$paramsHistory 
    ); 
 
    mysqli_stmt_execute($stmtHistory); 
 
    $data = mysqli_stmt_get_result($stmtHistory); 
 
    $totalData = 
        $data 
        ? mysqli_num_rows($data) 
        : 0; 
} 
 
 
/* 
|-------------------------------------------------------------------------- 
| DEFAULT FORM 
|-------------------------------------------------------------------------- 
*/ 
 
$formNama = 
    $editData['nama'] 
    ?? 'Wira Darmawan'; 
 
$formDivisi = 
    $editData['divisi'] 
    ?? 'Retail'; 
 
$formTanggal = 
    $editData['tanggal_update'] 
    ?? date('Y-m-d'); 
 
$formProject = 
    $editData['project'] 
    ?? 'System SQM - Webportal Retail'; 
 
$formDeskripsi = 
    $editData['deskripsi'] 
    ?? ''; 
 
$formProblem = 
    $editData['problem'] 
    ?? ''; 
 
$formTindakan = 
    $editData['tindakan'] 
    ?? ''; 
 
$formResult = 
    $editData['result'] 
    ?? ''; 
 
?> 
<!DOCTYPE html> 
 
<html lang="id"> 
 
<head> 
 
<meta charset="UTF-8"> 
 
<meta 
    name="viewport" 
    content="width=device-width, initial-scale=1" 
> 
 
<title> 
    Retail System SQM 
</title> 
 
 
<style> 
 
* { 
    box-sizing: 
        border-box; 
} 
 
body { 
    margin: 
        0; 
    background: 
        #f3f6f9; 
    color: 
        #263442; 
    font-family: 
        Arial, 
        Helvetica, 
        sans-serif; 
} 
 
.page { 
    width: 
        min(1500px, 96%); 
    margin: 
        28px auto 50px; 
} 
 
.card { 
    background: 
        #ffffff; 
    border: 
        1px solid #e2e8ee; 
    border-radius: 
        12px; 
    box-shadow: 
        0 5px 20px 
        rgba(28, 47, 65, .06); 
    overflow: 
        hidden; 
} 
 
.report-header { 
    position: relative;
    padding: 
        28px 135px 20px 24px; 
    text-align: 
        center; 
    border-bottom: 
        1px solid #e4e9ee; 
    background: 
        linear-gradient( 
            180deg, 
            #ffffff 0%, 
            #f8fafc 100% 
        ); 
}

.header-back-btn {
    position: absolute;
    right: 22px;
    top: 50%;
    transform: translateY(-50%);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 40px;
    padding: 9px 15px;
    border: 1px solid #c7d1da;
    border-radius: 8px;
    background: #173b5f;
    color: #ffffff;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    box-shadow: 0 4px 12px rgba(23, 59, 95, .18);
    cursor: pointer;
    transition: transform .18s ease, background .18s ease, box-shadow .18s ease;
}

.header-back-btn:hover {
    background: #0f2e4a;
    box-shadow: 0 6px 16px rgba(23, 59, 95, .28);
    transform: translateY(-50%) translateX(-2px);
}
 
.report-header h1 { 
    margin: 
        0; 
    font-size: 
        23px; 
    line-height: 
        1.35; 
    color: 
        #173b5f; 
    letter-spacing: 
        .25px; 
} 
 
.report-header h2 { 
    margin: 
        5px 0 0; 
    font-size: 
        18px; 
    color: 
        #41586d; 
} 
 
.card-body { 
    padding: 
        24px; 
} 
 
.form-grid { 
    display: 
        grid; 
    grid-template-columns: 
        repeat(4, 1fr); 
    gap: 
        16px; 
} 
 
.field { 
    margin-bottom: 
        16px; 
} 
 
.field label { 
    display: 
        block; 
    margin-bottom: 
        7px; 
    font-size: 
        13px; 
    font-weight: 
        700; 
    color: 
        #34495e; 
} 
 
.input { 
    width: 
        100%; 
    min-height: 
        42px; 
    border: 
        1px solid #ccd6df; 
    border-radius: 
        7px; 
    background: 
        #fff; 
    padding: 
        10px 11px; 
    font-family: 
        inherit; 
    font-size: 
        14px; 
    color: 
        #283746; 
    outline: 
        none; 
    transition: 
        .15s ease; 
} 
 
.input:focus { 
    border-color: 
        #527ca5; 
    box-shadow: 
        0 0 0 3px 
        rgba(82, 124, 165, .10); 
} 
 
textarea.input { 
    min-height: 
        105px; 
    resize: 
        vertical; 
    line-height: 
        1.5; 
} 
 
.section-title { 
    margin: 
        7px 0 16px; 
    padding-bottom: 
        10px; 
    border-bottom: 
        1px solid #e6ebef; 
    font-size: 
        16px; 
    color: 
        #203e5b; 
} 
 
.actions { 
    display: 
        flex; 
    flex-wrap: 
        wrap; 
    gap: 
        9px; 
    margin-top: 
        4px; 
} 
 
.btn { 
    display: 
        inline-flex; 
    align-items: 
        center; 
    justify-content: 
        center; 
    min-height: 
        39px; 
    padding: 
        9px 15px; 
    border: 
        0; 
    border-radius: 
        7px; 
    font-family: 
        inherit; 
    font-size: 
        13px; 
    font-weight: 
        700; 
    text-decoration: 
        none; 
    cursor: 
        pointer; 
    white-space: 
        nowrap; 
} 
 
.btn-primary { 
    background: 
        #1f5c8c; 
    color: 
        #fff; 
} 
 
.btn-primary:hover { 
    background: 
        #194e78; 
} 
 
.btn-pdf { 
    background: 
        #a93434; 
    color: 
        #fff; 
} 
 
.btn-pdf:hover { 
    background: 
        #8e2b2b; 
} 
 
.btn-secondary { 
    background: 
        #647585; 
    color: 
        #fff; 
} 
 
.btn-edit { 
    background: 
        #df9f28; 
    color: 
        #fff; 
} 
 
.btn-delete { 
    background: 
        #bd4040; 
    color: 
        #fff; 
} 
 
.btn-small { 
    min-height: 
        31px; 
    padding: 
        6px 9px; 
    font-size: 
        11px; 
} 
 
.alert { 
    margin-bottom: 
        18px; 
    padding: 
        12px 14px; 
    border-radius: 
        7px; 
    font-size: 
        13px; 
} 
 
.alert-success { 
    background: 
        #e9f7ef; 
    border: 
        1px solid #b9e2c9; 
    color: 
        #23623b; 
} 
 
.alert-danger { 
    background: 
        #fff0f0; 
    border: 
        1px solid #efb7b7; 
    color: 
        #8a2525; 
} 
 
.history-card { 
    margin-top: 
        22px; 
} 
 
.history-head { 
    display: 
        flex; 
    align-items: 
        center; 
    justify-content: 
        space-between; 
    gap: 
        15px; 
    padding: 
        18px 20px; 
    border-bottom: 
        1px solid #e2e8ee; 
} 
 
.history-title { 
    margin: 
        0; 
    color: 
        #203e5b; 
    font-size: 
        18px; 
} 
 
.history-tools { 
    display: 
        flex; 
    align-items: 
        center; 
    flex-wrap: 
        wrap; 
    gap: 
        8px; 
} 
 
.history-filter { 
    display: 
        flex; 
    align-items: 
        center; 
    flex-wrap: 
        wrap; 
    gap: 
        8px; 
    margin: 
        0; 
} 
 
.history-filter .input { 
    width: 
        auto; 
    min-width: 
        210px; 
} 
 
.count { 
    display: 
        inline-block; 
    margin-left: 
        7px; 
    background: 
        #e9eef3; 
    border-radius: 
        20px; 
    padding: 
        3px 8px; 
    color: 
        #53677a; 
    font-size: 
        11px; 
} 
 
.table-wrap { 
    width: 
        100%; 
    overflow-x: 
        auto; 
} 
 
table.history { 
    width: 
        100%; 
    min-width: 
        1450px; 
    border-collapse: 
        collapse; 
    table-layout: 
        fixed; 
} 
 
.history th { 
    background: 
        #e7edf3; 
    color: 
        #244762; 
    border: 
        1px solid #c7d1da; 
    padding: 
        10px 7px; 
    text-align: 
        center; 
    font-size: 
        12px; 
    line-height: 
        1.35; 
} 
 
.history td { 
    border: 
        1px solid #d6dde4; 
    padding: 
        9px; 
    vertical-align: 
        top; 
    font-size: 
        12px; 
    line-height: 
        1.45; 
    background: 
        #fff; 
    overflow-wrap: 
        anywhere; 
} 
 
.history tbody tr:nth-child(even) td { 
    background: 
        #fafbfc; 
} 
 
.history tbody tr:hover td { 
    background: 
        #f4f8fb; 
} 
 
.col-no { 
    width: 
        45px; 
    text-align: 
        center; 
} 
 
.col-name { 
    width: 
        110px; 
} 
 
.col-divisi { 
    width: 
        75px; 
} 
 
.col-date { 
    width: 
        105px; 
} 
 
.col-project { 
    width: 
        160px; 
} 
 
.col-description { 
    width: 
        190px; 
} 
 
.col-problem { 
    width: 
        190px; 
} 
 
.col-action { 
    width: 
        220px; 
} 
 
.col-result { 
    width: 
        190px; 
} 
 
.col-buttons { 
    width: 
        165px; 
} 
 
.row-actions { 
    display: 
        flex; 
    gap: 
        5px; 
    flex-wrap: 
        wrap; 
} 
 
.empty-row { 
    text-align: 
        center; 
    padding: 
        30px !important; 
    color: 
        #6d7c89; 
} 
 
.system-footer { 
    margin-top: 
        17px; 
    text-align: 
        center; 
    font-size: 
        9px; 
    color: 
        #949ca3; 
} 
 
@media ( 
    max-width: 950px 
) { 
 
    .form-grid { 
        grid-template-columns: 
            repeat(2, 1fr); 
    } 
 
} 
 
@media ( 
    max-width: 600px 
) { 
 
    .page { 
        width: 
            96%; 
        margin-top: 
            12px; 
    } 
 
    .form-grid { 
        grid-template-columns: 
            1fr; 
    } 
 
    .report-header h1 { 
        font-size: 
            18px; 
    } 
 
    .report-header h2 { 
        font-size: 
            15px; 
    } 

    .report-header {
        padding: 70px 16px 18px;
    }

    .header-back-btn {
        top: 16px;
        right: 16px;
        transform: none;
    }

    .header-back-btn:hover {
        transform: translateX(-2px);
    }
 
    .card-body { 
        padding: 
            16px; 
    } 
 
    .history-head { 
        flex-direction: 
            column; 
        align-items: 
            flex-start; 
    } 
 
    .history-tools, 
    .history-filter { 
        width: 
            100%; 
    } 
 
    .history-filter .input { 
        width: 
            100%; 
    } 
 
} 
 
</style> 
 
</head> 
 
 
<body> 
 
 
<div class="page"> 
 
 
    <!-- ============================== 
         FORM INPUT 
    =============================== --> 
 
    <div class="card"> 
 
 
        <div class="report-header"> 

            <a href=dashboard.php class="header-back-btn" title="Kembali ke halaman sebelumnya">
                &#8592; Kembali
            </a>
 
            <h1> 
 
                LAPORAN REALISASI PENGEMBANGAN 
                DAN ACCEPT REQUEST 
 
            </h1> 
 
            <h2> 
 
                SYSTEM SQM - WEBPORTAL RETAIL 
 
            </h2> 
 
        </div> 
 
 
        <div class="card-body"> 
 
 
            <?php if ($message !== ''): ?> 
 
                <div 
                    class="alert alert-<?= e($messageType) ?>" 
                > 
 
                    <?= e($message) ?> 
 
                </div> 
 
            <?php endif; ?> 
 
 
            <form 
                method="post" 
                action="<?= e($self) ?>" 
            > 
 
 
                <input 
                    type="hidden" 
                    name="action" 
                    value="<?= $editData ? 'update' : 'save' ?>" 
                > 
 
 
                <?php if ($editData): ?> 
 
                    <input 
                        type="hidden" 
                        name="id" 
                        value="<?= (int)$editData['id'] ?>" 
                    > 
 
                <?php endif; ?> 
 
 
                <div class="form-grid"> 
 
 
                    <!-- NAMA --> 
 
                    <div class="field"> 
 
                        <label> 
                            Nama 
                        </label> 
 
                        <select 
                            class="input" 
                            name="nama" 
                            required 
                        > 
 
                            <option 
                                value="Adrian Fauzan" 
                                <?= $formNama === 'Adrian Fauzan' ? 'selected' : '' ?> 
                            > 
                                Adrian Fauzan 
                            </option> 
 
                            <option 
                                value="Wira Darmawan" 
                                <?= $formNama === 'Wira Darmawan' ? 'selected' : '' ?> 
                            > 
                                Wira Darmawan 
                            </option> 
 
                        </select> 
 
                    </div> 
 
 
                    <!-- DIVISI --> 
 
                    <div class="field"> 
 
                        <label> 
                            Divisi 
                        </label> 
 
                        <input 
                            class="input" 
                            type="text" 
                            name="divisi" 
                            value="<?= e($formDivisi) ?>" 
                            required 
                        > 
 
                    </div> 
 
 
                    <!-- TANGGAL --> 
 
                    <div class="field"> 
 
                        <label> 
                            Tanggal Update 
                        </label> 
 
                        <input 
                            class="input" 
                            type="date" 
                            name="tanggal_update" 
                            value="<?= e($formTanggal) ?>" 
                            required 
                        > 
 
                    </div> 
 
 
                    <!-- PROJECT --> 
 
                    <div class="field"> 
 
                        <label> 
                            Project 
                        </label> 
 
                        <input 
                            class="input" 
                            type="text" 
                            name="project" 
                            value="<?= e($formProject) ?>" 
                            placeholder="Nama project" 
                            required 
                        > 
 
                    </div> 
 
 
                </div> 
 
 
                <h3 class="section-title"> 
 
                    Detail Realisasi / Request 
 
                </h3> 
 
 
                <!-- DESKRIPSI --> 
 
                <div class="field"> 
 
                    <label> 
                        Deskripsi 
                    </label> 
 
                    <textarea 
                        class="input" 
                        name="deskripsi" 
                        required 
                        placeholder="Masukkan deskripsi pekerjaan..." 
                    ><?= e($formDeskripsi) ?></textarea> 
 
                </div> 
 
 
                <!-- PROBLEM --> 
 
                <div class="field"> 
 
                    <label> 
                        Problem 
                    </label> 
 
                    <textarea 
                        class="input" 
                        name="problem" 
                        required 
                        placeholder="Masukkan problem..." 
                    ><?= e($formProblem) ?></textarea> 
 
                </div> 
 
 
                <!-- TINDAKAN --> 
 
                <div class="field"> 
 
                    <label> 
                        Tindakan Perbaikan 
                    </label> 
 
                    <textarea 
                        class="input" 
                        name="tindakan" 
                        required 
                        placeholder="Masukkan tindakan perbaikan..." 
                    ><?= e($formTindakan) ?></textarea> 
 
                </div> 
 
 
                <!-- RESULT --> 
 
                <div class="field"> 
 
                    <label> 
                        Result 
                    </label> 
 
                    <textarea 
                        class="input" 
                        name="result" 
                        required 
                        placeholder="Masukkan hasil / result..." 
                    ><?= e($formResult) ?></textarea> 
 
                </div> 
 
 
                <div class="actions"> 
 
 
                    <button 
                        type="submit" 
                        class="btn btn-primary" 
                    > 
 
                        <?= $editData 
                            ? 'Update Data' 
                            : 'Simpan Data' 
                        ?> 
 
                    </button> 
 
 
                    <?php if ($editData): ?> 
 
                        <a 
                            href="<?= e($self) ?>" 
                            class="btn btn-secondary" 
                        > 
 
                            Batal Edit 
 
                        </a> 
 
                    <?php endif; ?> 
 
 
                    <?php if ($selectedReportName !== ''): ?> 
 
                        <a 
                            href="<?= e($self) ?>?pdf=all&amp;nama_laporan=<?= urlencode($selectedReportName) ?>&amp;tanggal_mulai=<?= urlencode($_GET['tanggal_mulai'] ?? '') ?>&amp;tanggal_selesai=<?= urlencode($_GET['tanggal_selesai'] ?? '') ?>" 
                            target="_blank" 
                            class="btn btn-pdf" 
                        > 
 
                            Export PDF <?= e($selectedReportName) ?> 
 
                        </a> 
 
                    <?php else: ?> 
 
                        <a 
                            href="#history-report" 
                            class="btn btn-pdf" 
                        > 
 
                            Pilih Nama untuk Export 
 
                        </a> 
 
                    <?php endif; ?> 
 
 
                </div> 
 
 
            </form> 
 
 
        </div> 
 
    </div> 
 
 
 
    <!-- ============================== 
         HISTORY 
    =============================== --> 
 
 
    <div class="card history-card" id="history-report"> 
 
 
        <div class="history-head"> 
 
 
            <h3 class="history-title"> 
 
                History Laporan 
 
                <span class="count"> 
 
                    <?= (int)$totalData ?> Data 
 
                </span> 
 
            </h3> 
 
 
            <div class="history-tools"> 
 
                <form 
                    method="get" 
                    action="<?= e($self) ?>#history-report" 
                    class="history-filter" 
                > 
 
                    <select 
                        name="nama_laporan" 
                        class="input" 
                        required 
                    > 
 
                        <option value=""> 
                            -- Pilih Nama -- 
                        </option> 
 
                        <?php foreach ($allowedNames as $reportName): ?> 
 
                            <option 
                                value="<?= e($reportName) ?>" 
                                <?= $selectedReportName === $reportName ? 'selected' : '' ?> 
                            > 
                                <?= e($reportName) ?> 
                            </option> 
 
                        <?php endforeach; ?> 
 
                    </select> 
 
                    <input 
                        type="date" 
                        name="tanggal_mulai" 
                        class="input" 
                        value="<?= e($_GET['tanggal_mulai'] ?? '') ?>" 
                    > 
 
                    <input 
                        type="date" 
                        name="tanggal_selesai" 
                        class="input" 
                        value="<?= e($_GET['tanggal_selesai'] ?? '') ?>" 
                    > 
 
                    <button 
                        type="submit" 
                        class="btn btn-primary" 
                    > 
                        Tampilkan 
                    </button> 
 
                </form> 
 
 
                <?php if ($selectedReportName !== ''): ?> 
 
                    <a 
                        href="<?= e($self) ?>?pdf=all&amp;nama_laporan=<?= urlencode($selectedReportName) ?>&amp;tanggal_mulai=<?= urlencode($_GET['tanggal_mulai'] ?? '') ?>&amp;tanggal_selesai=<?= urlencode($_GET['tanggal_selesai'] ?? '') ?>" 
                        target="_blank" 
                        class="btn btn-pdf" 
                    > 
                        Export PDF <?= e($selectedReportName) ?> 
                    </a> 
 
                <?php endif; ?> 
 
            </div> 
 
 
        </div> 
 
 
 
        <div class="table-wrap"> 
 
 
            <table class="history"> 
 
 
                <thead> 
 
 
                <tr> 
 
                    <th class="col-no"> 
                        No 
                    </th> 
 
                    <th class="col-name"> 
                        Nama 
                    </th> 
 
                    <th class="col-divisi"> 
                        Divisi 
                    </th> 
 
                    <th class="col-date"> 
                        Tanggal Update 
                    </th> 
 
                    <th class="col-project"> 
                        Project 
                    </th> 
 
                    <th class="col-description"> 
                        Deskripsi 
                    </th> 
 
                    <th class="col-problem"> 
                        Problem 
                    </th> 
 
                    <th class="col-action"> 
                        Tindakan Perbaikan 
                    </th> 
 
                    <th class="col-result"> 
                        Result 
                    </th> 
 
                    <th class="col-buttons"> 
                        Aksi 
                    </th> 
 
                </tr> 
 
 
                </thead> 
 
 
                <tbody> 
 
 
                <?php 
 
                $nomor = 1; 
 
                if ( 
                    $data && 
                    mysqli_num_rows($data) > 0 
                ): 
 
                    while ( 
                        $row = 
                        mysqli_fetch_assoc($data) 
                    ): 
 
                ?> 
 
 
                    <tr> 
 
 
                        <td class="col-no"> 
 
                            <?= $nomor++ ?> 
 
                        </td> 
 
 
                        <td> 
 
                            <?= e($row['nama']) ?> 
 
                        </td> 
 
 
                        <td> 
 
                            <?= e($row['divisi']) ?> 
 
                        </td> 
 
 
                        <td> 
 
                            <?= e( 
                                formatTanggalIndonesia( 
                                    $row['tanggal_update'] 
                                ) 
                            ) ?> 
 
                        </td> 
 
 
                        <td> 
 
                            <?= e($row['project']) ?> 
 
                        </td> 
 
 
                        <td> 
 
                            <?= nl2br( 
                                e( 
                                    $row['deskripsi'] 
                                ) 
                            ) ?> 
 
                        </td> 
 
 
                        <td> 
 
                            <?= nl2br( 
                                e( 
                                    $row['problem'] 
                                ) 
                            ) ?> 
 
                        </td> 
 
 
                        <td> 
 
                            <?= nl2br( 
                                e( 
                                    $row['tindakan'] 
                                ) 
                            ) ?> 
 
                        </td> 
 
 
                        <td> 
 
                            <?= nl2br( 
                                e( 
                                    $row['result'] 
                                ) 
                            ) ?> 
 
                        </td> 
 
 
                        <td> 
 
 
                            <div class="row-actions"> 
 
 
                                <!-- PDF SATU DATA --> 
 
                                <a 
                                    href="<?= e($self) ?>?pdf=<?= (int)$row['id'] ?>" 
                                    target="_blank" 
                                    class="btn btn-pdf btn-small" 
                                > 
 
                                    PDF 
 
                                </a> 
 
 
                                <!-- EDIT --> 
 
                                <a 
                                    href="<?= e($self) ?>?edit=<?= (int)$row['id'] ?>" 
                                    class="btn btn-edit btn-small" 
                                > 
 
                                    Edit 
 
                                </a> 
 
 
                                <!-- DELETE --> 
 
                                <form 
                                    method="post" 
                                    action="<?= e($self) ?>" 
                                    onsubmit=" 
                                        return confirm( 
                                            'Yakin ingin menghapus data ini?' 
                                        ); 
                                    " 
                                    style="margin:0" 
                                > 
 
                                    <input 
                                        type="hidden" 
                                        name="action" 
                                        value="delete" 
                                    > 
 
                                    <input 
                                        type="hidden" 
                                        name="id" 
                                        value="<?= (int)$row['id'] ?>" 
                                    > 
 
                                    <button 
                                        type="submit" 
                                        class="btn btn-delete btn-small" 
                                    > 
 
                                        Hapus 
 
                                    </button> 
 
                                </form> 
 
 
                            </div> 
 
 
                        </td> 
 
 
                    </tr> 
 
 
                <?php 
 
                    endwhile; 
 
                else: 
 
                ?> 
 
 
                    <tr> 
 
                        <td 
                            colspan="10" 
                            class="empty-row" 
                        > 
 
                            <?php if ($selectedReportName === ''): ?> 
                                Silakan pilih nama terlebih dahulu untuk menampilkan laporan. 
                            <?php else: ?> 
                                Belum ada data laporan untuk <?= e($selectedReportName) ?>. 
                            <?php endif; ?> 
 
                        </td> 
 
                    </tr> 
 
 
                <?php endif; ?> 
 
 
                </tbody> 
 
 
            </table> 
 
 
        </div> 
 
 
    </div> 
 
 
 
    <!-- FOOTER WEBSITE --> 
 
    <div class="system-footer"> 
 
        Generated by Retail System SQM 
 
    </div> 
 
 
</div> 
 
 
</body> 
 
</html>
