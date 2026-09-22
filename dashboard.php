<?php
/* RBAC_HIDE_ONLY_V5_2026_05_26 GENERATED: dashboard uses rbac_user_config_v5 + rbac_menu_flags_v5 */
ini_set('session.cookie_lifetime', '0');
ini_set('session.use_strict_mode', '1');

session_set_cookie_params([
    'lifetime'=>0,
    'path'=>'/',
    'secure'=>isset($_SERVER['HTTPS']),
    'httponly'=>true,
    'samesite'=>'Strict'
]);

session_start();

$logoutRequested = (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && ($_POST['action'] ?? '') === 'logout'
) || (($_GET['action'] ?? '') === 'logout');
$legacyRememberCookie = trim((string)($_COOKIE['WEBPORTAL_REMEMBER'] ?? ''));

/* Hapus cookie remember-login lama. Dashboard tidak membuat cookie ini lagi. */
if ($legacyRememberCookie !== '' || $logoutRequested) {
    setcookie('WEBPORTAL_REMEMBER', '', [
        'expires' => time() - 42000,
        'path' => '/',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE['WEBPORTAL_REMEMBER']);
}

/* POST logout menghindari halaman dashboard yang tersaji ulang dari cache PWA. */
if ($logoutRequested) {
    $logoutUsername = strtolower(trim((string)($_SESSION['username'] ?? '')));
    $rememberSelector = '';
    if (strpos($legacyRememberCookie, ':') !== false) {
        $rememberSelector = trim((string)explode(':', $legacyRememberCookie, 2)[0]);
    }

    require_once __DIR__ . '/db.php';
    if (isset($conn) && $conn) {
        if ($logoutUsername !== '') {
            $deleteRemember = @mysqli_prepare($conn, 'DELETE FROM user_remember_tokens WHERE username = ?');
            if ($deleteRemember) {
                mysqli_stmt_bind_param($deleteRemember, 's', $logoutUsername);
                @mysqli_stmt_execute($deleteRemember);
                mysqli_stmt_close($deleteRemember);
            }
        } elseif ($rememberSelector !== '') {
            $deleteRemember = @mysqli_prepare($conn, 'DELETE FROM user_remember_tokens WHERE selector = ?');
            if ($deleteRemember) {
                mysqli_stmt_bind_param($deleteRemember, 's', $rememberSelector);
                @mysqli_stmt_execute($deleteRemember);
                mysqli_stmt_close($deleteRemember);
            }
        }
    }

    $_SESSION = [];
    session_unset();

    if (ini_get('session.use_cookies')) {
        $sessionCookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $sessionCookie['path'] ?: '/',
            'domain' => $sessionCookie['domain'] ?? '',
            'secure' => (bool)($sessionCookie['secure'] ?? false),
            'httponly' => (bool)($sessionCookie['httponly'] ?? true),
            'samesite' => $sessionCookie['samesite'] ?: 'Lax',
        ]);
    }

    session_destroy();
    header('Clear-Site-Data: "cache"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    header('Location: login.php?logout=1&t=' . time(), true, 303);
    exit;
}

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/db.php';

/* Bersihkan token database lama setelah cookie remember dinonaktifkan. */
if ($legacyRememberCookie !== '' && isset($conn) && $conn) {
    $cleanupUsername = strtolower(trim((string)($_SESSION['username'] ?? '')));
    if ($cleanupUsername !== '') {
        $deleteRemember = @mysqli_prepare($conn, 'DELETE FROM user_remember_tokens WHERE username = ?');
        if ($deleteRemember) {
            mysqli_stmt_bind_param($deleteRemember, 's', $cleanupUsername);
            @mysqli_stmt_execute($deleteRemember);
            mysqli_stmt_close($deleteRemember);
        }
    }
}

mysqli_set_charset($conn, 'utf8mb4');
date_default_timezone_set('Asia/Jakarta');
if (file_exists(__DIR__ . '/maintenance.flag')) {
    include '#.php';
    exit;
}

/* =========================
   WEB PUSH NOTIFICATION CONFIG
   WAJIB DIISI:
   1) composer require minishlink/web-push
   2) vendor/bin/web-push generate:vapid
   3) Isi public/private key di bawah.
========================= */
if (!defined('PUSH_VAPID_PUBLIC_KEY')) {
    define('PUSH_VAPID_PUBLIC_KEY', 'BHQ1PIfZTKOkbsE_CoiXJWDAdFCt3y7IARB2FGQ7yNXcR3P0QJKuE25G6_hqAeC-NZTCsKK20icksEAoT66Ll-A');
}
if (!defined('PUSH_VAPID_PRIVATE_KEY')) {
    define('PUSH_VAPID_PRIVATE_KEY', 'uVwFV2KxoP8E2H-7tYZKsVYjyHGmAhEUYto7f1ZU1qI');
}
if (!defined('PUSH_VAPID_SUBJECT')) {
    define('PUSH_VAPID_SUBJECT', 'mailto:admin@srtcorp.online');
}


function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cleanDashValue($value, $fallback = '-')
{
    $value = trim((string)$value);
    return $value === '' ? $fallback : $value;
}

function parseDashNumber($value)
{
    $value = trim((string)$value);
    if ($value === '') return 0;

    // Aman untuk format Indonesia dan decimal MySQL:
    // 7.500.000, 7500000.00, 7500000, Rp 7.500.000
    $value = str_replace(['Rp', 'rp', 'RP', ' '], '', $value);
    $value = preg_replace('/[^0-9.,-]/', '', $value);

    if ($value === '' || $value === '-' || $value === '.' || $value === ',') {
        return 0;
    }

    $hasDot = strpos($value, '.') !== false;
    $hasComma = strpos($value, ',') !== false;

    if ($hasDot && $hasComma) {
        $lastDot = strrpos($value, '.');
        $lastComma = strrpos($value, ',');

        if ($lastComma > $lastDot) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } elseif ($hasDot) {
        $dotCount = substr_count($value, '.');

        if ($dotCount > 1) {
            $value = str_replace('.', '', $value);
        } else {
            $parts = explode('.', $value);
            $decimalLen = strlen($parts[1] ?? '');

            if ($decimalLen === 3) {
                $value = str_replace('.', '', $value);
            }
        }
    } elseif ($hasComma) {
        $commaCount = substr_count($value, ',');

        if ($commaCount > 1) {
            $value = str_replace(',', '', $value);
        } else {
            $parts = explode(',', $value);
            $decimalLen = strlen($parts[1] ?? '');

            if ($decimalLen === 3) {
                $value = str_replace(',', '', $value);
            } else {
                $value = str_replace(',', '.', $value);
            }
        }
    }

    return is_numeric($value) ? (float)$value : 0;
}

function formatDashRupiah($value)
{
    $raw = trim((string)$value);
    if ($raw === '') return '';

    $angka = parseDashNumber($raw);
    return $angka > 0 ? 'Rp ' . number_format($angka, 0, ',', '.') : $raw;
}

function isDashImageLink($link)
{
    $link = trim((string)$link);
    if ($link === '') return false;

    return (bool)(preg_match('/\.(jpg|jpeg|png|webp|gif)(\?.*)?$/i', $link) || strpos($link, 'uploads/') === 0);
}

function jsonResponse(array $payload)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function columnExists($conn, $table, $column)
{
    $table = preg_replace('/[^A-Za-z0-9_]+/', '', (string)$table);
    $column = preg_replace('/[^A-Za-z0-9_]+/', '', (string)$column);
    if ($table === '' || $column === '') return false;

    $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '" . mysqli_real_escape_string($conn, $column) . "'");
    return $res && mysqli_num_rows($res) > 0;
}

function tableExists($conn, $table)
{
    $table = preg_replace('/[^A-Za-z0-9_]+/', '', (string)$table);
    if ($table === '') return false;

    $res = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
    return $res && mysqli_num_rows($res) > 0;
}


/* ============================================================
   RBAC ELEMENT DASHBOARD V5
   Untuk kontrol button/card/widget dashboard per user.
   TRUE  = tampil
   FALSE = hide
   Tabel: rbac_element_master_v5 + rbac_element_flags_v5
============================================================ */
if (!function_exists('dashRbacElNormUser')) {
    function dashRbacElNormUser($username)
    {
        return strtolower(trim((string)$username));
    }
}

if (!function_exists('dashRbacElCleanKey')) {
    function dashRbacElCleanKey($value)
    {
        $value = strtolower(trim((string)$value));
        return preg_replace('/[^a-z0-9_.-]+/', '_', $value);
    }
}

if (!function_exists('dashRbacElEnsureTables')) {
    function dashRbacElEnsureTables($conn)
    {
        @mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS rbac_element_master_v5 (
                id INT AUTO_INCREMENT PRIMARY KEY,
                element_key VARCHAR(160) NOT NULL UNIQUE,
                page_key VARCHAR(120) NOT NULL DEFAULT '',
                element_type VARCHAR(40) NOT NULL DEFAULT 'other',
                title VARCHAR(160) NOT NULL,
                description VARCHAR(255) DEFAULT NULL,
                sort INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                default_can_view TINYINT(1) NOT NULL DEFAULT 1,
                saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_page_key (page_key),
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        @mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS rbac_element_flags_v5 (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100) NOT NULL,
                element_key VARCHAR(160) NOT NULL,
                can_view TINYINT(1) NOT NULL DEFAULT 1,
                saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_element (username, element_key),
                INDEX idx_username (username),
                INDEX idx_element_key (element_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        @mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS rbac_element_open_flags_v5 (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100) NOT NULL,
                element_key VARCHAR(160) NOT NULL,
                can_open TINYINT(1) NOT NULL DEFAULT 1,
                saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_element_open (username, element_key),
                INDEX idx_username (username),
                INDEX idx_element_key (element_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        @mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS rbac_element_seed_v5 (
                seed_key VARCHAR(160) NOT NULL PRIMARY KEY,
                saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

if (!function_exists('dashRbacElRegister')) {
    function dashRbacElRegister($conn, array $elements)
    {
        dashRbacElEnsureTables($conn);

        $stmt = mysqli_prepare($conn, "
            INSERT INTO rbac_element_master_v5
                (element_key, page_key, element_type, title, description, sort, is_active, default_can_view)
            VALUES (?, ?, ?, ?, ?, ?, 1, ?)
            ON DUPLICATE KEY UPDATE
                page_key = VALUES(page_key),
                element_type = VALUES(element_type),
                title = VALUES(title),
                description = VALUES(description),
                sort = VALUES(sort),
                is_active = 1,
                saved_at = CURRENT_TIMESTAMP
        ");

        if (!$stmt) return false;

        foreach ($elements as $el) {
            $key   = dashRbacElCleanKey($el['key'] ?? '');
            $page  = dashRbacElCleanKey($el['page'] ?? 'dashboard');
            $type  = dashRbacElCleanKey($el['type'] ?? 'other');
            $title = trim((string)($el['title'] ?? $key));
            $desc  = trim((string)($el['description'] ?? ''));
            $sort  = (int)($el['sort'] ?? 0);
            $def   = isset($el['default_can_view']) ? (int)$el['default_can_view'] : 1;

            if ($key === '') continue;

            mysqli_stmt_bind_param($stmt, 'sssssii', $key, $page, $type, $title, $desc, $sort, $def);
            mysqli_stmt_execute($stmt);
        }

        mysqli_stmt_close($stmt);
        return true;
    }
}

if (!function_exists('dashRbacElCanView')) {
    function dashRbacElCanView($conn, $username, $elementKey)
    {
        dashRbacElEnsureTables($conn);

        $username = dashRbacElNormUser($username);
        $elementKey = dashRbacElCleanKey($elementKey);

        if ($username === '' || $elementKey === '') return false;

        $defaultCanView = 1;

        $stmtMaster = mysqli_prepare($conn, "
            SELECT is_active, default_can_view
            FROM rbac_element_master_v5
            WHERE element_key = ?
            LIMIT 1
        ");

        if ($stmtMaster) {
            mysqli_stmt_bind_param($stmtMaster, 's', $elementKey);
            mysqli_stmt_execute($stmtMaster);
            $resMaster = mysqli_stmt_get_result($stmtMaster);
            $rowMaster = $resMaster ? mysqli_fetch_assoc($resMaster) : null;
            mysqli_stmt_close($stmtMaster);

            if ($rowMaster) {
                if ((int)$rowMaster['is_active'] !== 1) return false;
                $defaultCanView = (int)$rowMaster['default_can_view'];
            }
        }

        $stmt = mysqli_prepare($conn, "
            SELECT can_view
            FROM rbac_element_flags_v5
            WHERE username = ? AND element_key = ?
            LIMIT 1
        ");

        if (!$stmt) return $defaultCanView === 1;

        mysqli_stmt_bind_param($stmt, 'ss', $username, $elementKey);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);

        if ($row) {
            return (int)$row['can_view'] === 1;
        }

        return $defaultCanView === 1;
    }
}

if (!function_exists('dashRbacElCanOpen')) {
    function dashRbacElCanOpen($conn, $username, $elementKey)
    {
        dashRbacElEnsureTables($conn);

        $username = dashRbacElNormUser($username);
        $elementKey = dashRbacElCleanKey($elementKey);

        if ($username === '' || $elementKey === '') return true;

        $stmt = mysqli_prepare($conn, "
            SELECT can_open
            FROM rbac_element_open_flags_v5
            WHERE username = ? AND element_key = ?
            LIMIT 1
        ");

        if (!$stmt) return true;

        mysqli_stmt_bind_param($stmt, 'ss', $username, $elementKey);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);

        if (!$row) return true;
        return (int)$row['can_open'] === 1;
    }
}








if (!function_exists('dashRbacNotifElementCanViewDirect')) {
    function dashRbacNotifElementCanViewDirect($conn, $username, $elementKey)
    {
        $username = strtolower(trim((string)$username));
        $elementKey = strtolower(trim((string)$elementKey));
        $elementKey = preg_replace('/[^a-z0-9_.-]+/', '_', $elementKey);
        if ($username === '' || $elementKey === '') return true;
        if (!function_exists('tableExists') || !tableExists($conn, 'rbac_element_flags_v5')) return true;

        $stmt = mysqli_prepare($conn, "SELECT can_view FROM rbac_element_flags_v5 WHERE username=? AND element_key=? LIMIT 1");
        if (!$stmt) return true;
        mysqli_stmt_bind_param($stmt, 'ss', $username, $elementKey);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        if (!$row) return true;
        return (int)$row['can_view'] === 1;
    }
}

if (!function_exists('dashRbacNotifElementCanOpenDirect')) {
    function dashRbacNotifElementCanOpenDirect($conn, $username, $elementKey)
    {
        $username = strtolower(trim((string)$username));
        $elementKey = strtolower(trim((string)$elementKey));
        $elementKey = preg_replace('/[^a-z0-9_.-]+/', '_', $elementKey);
        if ($username === '' || $elementKey === '') return true;
        if (!function_exists('tableExists') || !tableExists($conn, 'rbac_element_open_flags_v5')) return true;

        $stmt = mysqli_prepare($conn, "SELECT can_open FROM rbac_element_open_flags_v5 WHERE username=? AND element_key=? LIMIT 1");
        if (!$stmt) return true;
        mysqli_stmt_bind_param($stmt, 'ss', $username, $elementKey);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        if (!$row) return true;
        return (int)$row['can_open'] === 1;
    }
}

if (!function_exists('dashRbacMenuCanOpenDirect')) {
    function dashRbacMenuCanOpenDirect($conn, $username, $menuKey)
    {
        $username = strtolower(trim((string)$username));
        $menuKey = preg_replace('/[^A-Za-z0-9_]+/', '', (string)$menuKey);
        if ($username === '' || $menuKey === '') return true;
        if (!function_exists('tableExists') || !tableExists($conn, 'rbac_menu_open_flags_v5')) return true;

        $stmt = mysqli_prepare($conn, "SELECT can_open FROM rbac_menu_open_flags_v5 WHERE username=? AND menu_key=? LIMIT 1");
        if (!$stmt) return true;
        mysqli_stmt_bind_param($stmt, 'ss', $username, $menuKey);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        if (!$row) return true;
        return (int)$row['can_open'] === 1;
    }
}

if (!function_exists('dashRbacNotifCanOpen')) {
    function dashRbacNotifCanOpen($conn, $username, $type)
    {
        $type = strtolower(trim((string)$type));

        if ($type === 'kegiatan') {
            return dashRbacElCanOpen($conn, $username, 'dashboard.notif_header_kegiatan');
        }

        return dashRbacElCanOpen($conn, $username, 'dashboard.notif_header_aktivitas');
    }
}

if (!function_exists('dashRbacHeroButtonElementKey')) {
    function dashRbacHeroButtonElementKey($text, $link)
    {
        $haystack = strtolower(trim((string)$text . ' ' . (string)$link));

        if ($haystack === '') return '';

        if (strpos($haystack, 'summary-sales-toko') !== false || strpos($haystack, 'lihat omset') !== false || strpos($haystack, 'data omset') !== false) {
            return 'dashboard.btn_lihat_data_omset';
        }

        if (strpos($haystack, 'jadwal-spv') !== false || strpos($haystack, 'lihat jadwal') !== false || strpos($haystack, 'jadwal') !== false) {
            return 'dashboard.btn_lihat_jadwal';
        }

        if (strpos($haystack, 'visit.php') !== false || strpos($haystack, 'buka aktivitas') !== false || strpos($haystack, 'aktivitas') !== false) {
            return 'dashboard.btn_buka_aktivitas';
        }

        if (strpos($haystack, 'absensi_monitor') !== false || strpos($haystack, 'monitoring kehadiran') !== false || strpos($haystack, 'monitoring_admin') !== false) {
            return 'dashboard.btn_monitoring_kehadiran';
        }

        if (strpos($haystack, 'lapor kehadiran') !== false || strpos($haystack, 'absensi_leader') !== false || strpos($haystack, 'absensi_selfie') !== false) {
            return 'dashboard.btn_lapor_kehadiran';
        }

        return '';
    }
}

if (!function_exists('dashRbacElSeedDashboardDefaults')) {
    function dashRbacElSeedDashboardDefaults($conn)
    {
        dashRbacElEnsureTables($conn);

        $seedKey = 'dashboard_element_default_block_whina_ratna_v1';
        $resSeed = mysqli_query($conn, "SELECT seed_key FROM rbac_element_seed_v5 WHERE seed_key = '" . mysqli_real_escape_string($conn, $seedKey) . "' LIMIT 1");
        if ($resSeed && mysqli_num_rows($resSeed) > 0) {
            return;
        }

        $defaultBlocked = [
            ['whina', 'dashboard.btn_buka_aktivitas'],
            ['whina', 'dashboard.btn_lihat_jadwal'],
            ['ratna', 'dashboard.btn_buka_aktivitas'],
            ['ratna', 'dashboard.btn_lihat_jadwal'],
        ];

        $stmt = mysqli_prepare($conn, "
            INSERT IGNORE INTO rbac_element_flags_v5 (username, element_key, can_view)
            VALUES (?, ?, 0)
        ");

        if ($stmt) {
            foreach ($defaultBlocked as $row) {
                $seedUser = dashRbacElNormUser($row[0]);
                $seedElement = dashRbacElCleanKey($row[1]);
                mysqli_stmt_bind_param($stmt, 'ss', $seedUser, $seedElement);
                mysqli_stmt_execute($stmt);
            }
            mysqli_stmt_close($stmt);
        }

        mysqli_query($conn, "
            INSERT IGNORE INTO rbac_element_seed_v5 (seed_key)
            VALUES ('" . mysqli_real_escape_string($conn, $seedKey) . "')
        ");
    }
}



function dashPickColumn($conn, $table, array $candidates, $fallback = '')
{
    foreach ($candidates as $col) {
        if (columnExists($conn, $table, $col)) {
            return $col;
        }
    }
    return $fallback;
}

function dashFormatTanggalIndo($dateValue)
{
    $raw = trim((string)$dateValue);
    if ($raw === '') return date('d F Y');

    $ts = strtotime($raw);
    if (!$ts) return $raw;

    $bulan = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];

    return date('d', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

function dashFormatJamWib($jamValue, $dateFallback = '')
{
    $raw = trim((string)$jamValue);
    if ($raw === '' && trim((string)$dateFallback) !== '') {
        $raw = trim((string)$dateFallback);
    }

    if ($raw === '') return date('H:i') . ' WIB';

    $ts = strtotime($raw);
    if ($ts) {
        return date('H:i', $ts) . ' WIB';
    }

    if (preg_match('/^(\d{1,2}):(\d{2})/', $raw, $m)) {
        return str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2] . ' WIB';
    }

    return $raw . ' WIB';
}

function dashBuildNotifText($nama, $jenis, $target, $tanggal, $jam)
{
    $nama = trim((string)$nama);
    $target = trim((string)$target);

    if ($nama === '') $nama = 'User';
    if ($target === '') $target = ($jenis === 'kegiatan') ? 'Kegiatan' : 'Outlet';

    if (function_exists('mb_strtoupper')) {
        $nama = mb_strtoupper($nama, 'UTF-8');
    } else {
        $nama = strtoupper($nama);
    }

    $tgl = dashFormatTanggalIndo($tanggal);
    $wib = dashFormatJamWib($jam, $tanggal);

    if ($jenis === 'kegiatan') {
        return $nama . ' Telah Melakukan Kegiatan pada tanggal ' . $tgl . ' Pukul ' . $wib;
    }

    return $nama . ' Telah Melakukan Visit di ' . $target . ' pada tanggal ' . $tgl . ' Pukul ' . $wib;
}

function ensureDashboardHeroButton4($conn)
{
    $res = mysqli_query($conn, "SHOW TABLES LIKE 'dashboard_hero'");
    if (!$res || mysqli_num_rows($res) === 0) {
        return;
    }

    if (!columnExists($conn, 'dashboard_hero', 'button4_text')) {
        @mysqli_query($conn, "ALTER TABLE dashboard_hero ADD button4_text VARCHAR(120) NULL DEFAULT NULL AFTER button3_link");
    }

    if (!columnExists($conn, 'dashboard_hero', 'button4_link')) {
        @mysqli_query($conn, "ALTER TABLE dashboard_hero ADD button4_link VARCHAR(255) NULL DEFAULT NULL AFTER button4_text");
    }

    if (!columnExists($conn, 'dashboard_hero', 'image_path')) {
        @mysqli_query($conn, "ALTER TABLE dashboard_hero ADD image_path VARCHAR(255) NULL DEFAULT NULL AFTER button4_link");
    }
}


function ensurePushSubscriptionsTable($conn)
{
    @mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            endpoint TEXT NOT NULL,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_endpoint (endpoint(255)),
            KEY idx_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function isPushVapidConfigured()
{
    return defined('PUSH_VAPID_PUBLIC_KEY')
        && defined('PUSH_VAPID_PRIVATE_KEY')
        && trim((string)PUSH_VAPID_PUBLIC_KEY) !== ''
        && trim((string)PUSH_VAPID_PRIVATE_KEY) !== ''
        && strpos((string)PUSH_VAPID_PUBLIC_KEY, 'ISI_') !== 0
        && strpos((string)PUSH_VAPID_PRIVATE_KEY, 'ISI_') !== 0;
}


$username = $_SESSION['username'] ?? '';
$namaLengkap = $_SESSION['nama_lengkap'] ?? $username;

$omsetAlertSessionKey = 'omset_alert_dashboard_shown_' . date('Y-m-d');
$showOmsetAlertOnLogin = empty($_SESSION[$omsetAlertSessionKey]);
if ($showOmsetAlertOnLogin) {
    $_SESSION[$omsetAlertSessionKey] = 1;
}

ensurePushSubscriptionsTable($conn);

/* =========================
   REGISTER RBAC ELEMENT DASHBOARD
   Element ini akan muncul di CRUD RBAC Element.
========================= */
dashRbacElRegister($conn, [
    [
        'key' => 'dashboard.btn_lihat_data_omset',
        'page' => 'dashboard',
        'type' => 'button',
        'title' => 'Button Lihat Data Omset',
        'description' => 'Tombol hero untuk membuka data omset dashboard.',
        'sort' => 10,
    ],
    [
        'key' => 'dashboard.btn_buka_aktivitas',
        'page' => 'dashboard',
        'type' => 'button',
        'title' => 'Button Buka Aktivitas',
        'description' => 'Tombol hero Buka Aktivitas / Visit pada dashboard.',
        'sort' => 20,
    ],
    [
        'key' => 'dashboard.btn_monitoring_kehadiran',
        'page' => 'dashboard',
        'type' => 'button',
        'title' => 'Button Monitoring Kehadiran',
        'description' => 'Tombol hero Monitoring Kehadiran pada dashboard.',
        'sort' => 30,
    ],
    [
        'key' => 'dashboard.btn_lapor_kehadiran',
        'page' => 'dashboard',
        'type' => 'button',
        'title' => 'Button Lapor Kehadiran',
        'description' => 'Tombol hero Lapor Kehadiran jika digunakan pada dashboard.',
        'sort' => 40,
    ],
    [
        'key' => 'dashboard.btn_lihat_jadwal',
        'page' => 'dashboard',
        'type' => 'button',
        'title' => 'Button Lihat Jadwal',
        'description' => 'Tombol hero Lihat Jadwal pada dashboard.',
        'sort' => 50,
    ],
    [
        'key' => 'dashboard.card_aktivitas_visit_hari_ini',
        'page' => 'dashboard',
        'type' => 'card',
        'title' => 'Card Aktivitas Visit Hari Ini',
        'description' => 'Slider/card aktivitas visit hari ini pada hero dashboard.',
        'sort' => 60,
    ],
    [
        'key' => 'dashboard.card_realisasi_work_order',
        'page' => 'dashboard',
        'type' => 'card',
        'title' => 'Card Realisasi Work Order',
        'description' => 'Slider/card realisasi pekerjaan work order minimarket.',
        'sort' => 70,
    ],
    [
        'key' => 'dashboard.card_grafik_sales',
        'page' => 'dashboard',
        'type' => 'card',
        'title' => 'Card Grafik Sales Keseluruhan',
        'description' => 'Card grafik sales keseluruhan di sisi kanan dashboard.',
        'sort' => 80,
    ],
    [
        'key' => 'dashboard.metric_daftar_aktivitas_hari_ini',
        'page' => 'dashboard',
        'type' => 'card',
        'title' => 'Metric Daftar Aktivitas Hari Ini',
        'description' => 'Card ringkasan jumlah aktivitas hari ini.',
        'sort' => 90,
    ],
    [
        'key' => 'dashboard.metric_daftar_kegiatan',
        'page' => 'dashboard',
        'type' => 'card',
        'title' => 'Metric Daftar Kegiatan',
        'description' => 'Card ringkasan jumlah kegiatan hari ini.',
        'sort' => 100,
    ],
    [
        'key' => 'dashboard.metric_jumlah_karyawan',
        'page' => 'dashboard',
        'type' => 'card',
        'title' => 'Metric Jumlah Karyawan',
        'description' => 'Card ringkasan jumlah karyawan.',
        'sort' => 110,
    ],
    [
        'key' => 'dashboard.metric_status_akun',
        'page' => 'dashboard',
        'type' => 'card',
        'title' => 'Metric Status Akun',
        'description' => 'Card status akun user login.',
        'sort' => 120,
    ],
    [
        'key' => 'dashboard.chart_aktivitas_periode',
        'page' => 'dashboard',
        'type' => 'chart',
        'title' => 'Chart Aktivitas Periode',
        'description' => 'Chart distribusi aktivitas periode berjalan.',
        'sort' => 130,
    ],
    [
        'key' => 'dashboard.chart_kegiatan_periode',
        'page' => 'dashboard',
        'type' => 'chart',
        'title' => 'Chart Kegiatan Periode',
        'description' => 'Chart distribusi kegiatan periode berjalan.',
        'sort' => 140,
    ],
    [
        'key' => 'dashboard.card_berita_bandara_penerbangan',
        'page' => 'dashboard',
        'type' => 'card',
        'title' => 'Card Berita Bandara & Penerbangan',
        'description' => 'Section berita bandara dan penerbangan pada dashboard.',
        'sort' => 150,
    ],
    [
        'key' => 'dashboard.notif_header_aktivitas',
        'page' => 'dashboard',
        'type' => 'notification',
        'title' => 'Notif Header Aktivitas',
        'description' => 'Notifikasi pojok kanan header untuk aktivitas visit. FALSE pada Dilarang Akses = notif tetap tampil tapi tidak bisa redirect.',
        'sort' => 160,
    ],
    [
        'key' => 'dashboard.notif_header_kegiatan',
        'page' => 'dashboard',
        'type' => 'notification',
        'title' => 'Notif Header Kegiatan',
        'description' => 'Notifikasi pojok kanan header untuk kegiatan. FALSE pada Dilarang Akses = notif tetap tampil tapi tidak bisa redirect.',
        'sort' => 170,
    ],
]);

dashRbacElSeedDashboardDefaults($conn);

/* =========================
   AJAX ENDPOINTS
========================= */
if (isset($_GET['ajax'])) {
    if ($_GET['ajax'] === 'push_config') {
        jsonResponse([
            'success' => true,
            'configured' => isPushVapidConfigured(),
            'publicKey' => isPushVapidConfigured() ? PUSH_VAPID_PUBLIC_KEY : '',
            'message' => isPushVapidConfigured()
                ? 'Web Push siap digunakan.'
                : 'VAPID key belum diisi. Generate VAPID key lalu isi PUSH_VAPID_PUBLIC_KEY dan PUSH_VAPID_PRIVATE_KEY.'
        ]);
    }

    if ($_GET['ajax'] === 'save_push_subscription') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            jsonResponse(['success' => false, 'message' => 'Method tidak diizinkan.']);
        }

        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            http_response_code(400);
            jsonResponse(['success' => false, 'message' => 'Payload subscription tidak valid.']);
        }

        $endpoint = trim((string)($payload['endpoint'] ?? ''));
        $p256dh = trim((string)($payload['keys']['p256dh'] ?? ''));
        $auth = trim((string)($payload['keys']['auth'] ?? ''));
        $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            http_response_code(400);
            jsonResponse(['success' => false, 'message' => 'Data subscription belum lengkap.']);
        }

        $stmt = mysqli_prepare($conn, "
            INSERT INTO push_subscriptions (username, endpoint, p256dh, auth, user_agent)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                username = VALUES(username),
                p256dh = VALUES(p256dh),
                auth = VALUES(auth),
                user_agent = VALUES(user_agent),
                updated_at = CURRENT_TIMESTAMP
        ");

        if (!$stmt) {
            http_response_code(500);
            jsonResponse(['success' => false, 'message' => 'Gagal menyiapkan query subscription.']);
        }

        mysqli_stmt_bind_param($stmt, 'sssss', $username, $endpoint, $p256dh, $auth, $userAgent);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        jsonResponse([
            'success' => (bool)$ok,
            'message' => $ok ? 'Notifikasi HP berhasil diaktifkan.' : 'Gagal menyimpan subscription.'
        ]);
    }

    if ($_GET['ajax'] === 'delete_push_subscription') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            jsonResponse(['success' => false, 'message' => 'Method tidak diizinkan.']);
        }

        $payload = json_decode(file_get_contents('php://input'), true);
        $endpoint = trim((string)($payload['endpoint'] ?? ''));

        if ($endpoint === '') {
            http_response_code(400);
            jsonResponse(['success' => false, 'message' => 'Endpoint subscription kosong.']);
        }

        $stmt = mysqli_prepare($conn, "DELETE FROM push_subscriptions WHERE endpoint = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $endpoint);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        jsonResponse(['success' => true, 'message' => 'Subscription dihapus.']);
    }

    if ($_GET['ajax'] === 'notifications') {
        $notifAll = [];

        // VISIT: source utama dari visit.php / data_aktivitas.php
        if (tableExists($conn, 'visit_activities')) {
            $nameCol = dashPickColumn($conn, 'visit_activities', ['nama_user', 'NAMA', 'nama', 'username'], 'username');
            $dateCol = dashPickColumn($conn, 'visit_activities', ['tanggal', 'TANGGAL', 'created_at'], 'created_at');
            $timeCol = dashPickColumn($conn, 'visit_activities', ['jam', 'JAM', 'created_at'], 'created_at');
            $storeCol = dashPickColumn($conn, 'visit_activities', ['store', 'OUTLET', 'outlet', 'lokasi', 'LOKASI', 'area_terminal'], '');

            $storeSelect = $storeCol !== '' ? "`{$storeCol}` AS target_outlet" : "'' AS target_outlet";

            $qVisitActivity = mysqli_query($conn, "
                SELECT id,
                       `{$nameCol}` AS nama_notif,
                       `{$dateCol}` AS tanggal_notif,
                       `{$timeCol}` AS jam_notif,
                       {$storeSelect},
                       created_at
                FROM visit_activities
                WHERE DATE(created_at) = CURDATE()
                   OR LEFT(TRIM(`{$dateCol}`), 10) = CURDATE()
                ORDER BY created_at DESC, `{$dateCol}` DESC, `{$timeCol}` DESC
                LIMIT 10
            ");

            if ($qVisitActivity) {
                while ($row = mysqli_fetch_assoc($qVisitActivity)) {
                    $notifAll[] = [
                        'text' => dashBuildNotifText(
                            $row['nama_notif'] ?? 'User',
                            'visit',
                            $row['target_outlet'] ?? 'Outlet',
                            $row['tanggal_notif'] ?? ($row['created_at'] ?? ''),
                            $row['jam_notif'] ?? ($row['created_at'] ?? '')
                        ),
                        'url' => 'data_aktivitas.php',
                        'type' => 'aktivitas',
                        'title' => 'Aktivitas Visit Baru',
                        'time_key' => trim(($row['created_at'] ?? '') . ' ' . ($row['tanggal_notif'] ?? '') . ' ' . ($row['jam_notif'] ?? '')),
                    ];
                }
            }
        }

        // KEGIATAN: source utama dari visit.php / add_kegiatan.php
        if (tableExists($conn, 'visit_kegiatan')) {
            $nameCol = dashPickColumn($conn, 'visit_kegiatan', ['nama_user', 'NAMA', 'nama', 'username'], 'username');
            $dateCol = dashPickColumn($conn, 'visit_kegiatan', ['tanggal', 'TANGGAL', 'Date', 'created_at'], 'created_at');
            $timeCol = dashPickColumn($conn, 'visit_kegiatan', ['jam', 'JAM', 'created_at'], 'created_at');

            $qVisitKegiatan = mysqli_query($conn, "
                SELECT id,
                       `{$nameCol}` AS nama_notif,
                       `{$dateCol}` AS tanggal_notif,
                       `{$timeCol}` AS jam_notif,
                       created_at
                FROM visit_kegiatan
                WHERE DATE(created_at) = CURDATE()
                   OR LEFT(TRIM(`{$dateCol}`), 10) = CURDATE()
                ORDER BY created_at DESC, `{$dateCol}` DESC, `{$timeCol}` DESC
                LIMIT 10
            ");

            if ($qVisitKegiatan) {
                while ($row = mysqli_fetch_assoc($qVisitKegiatan)) {
                    $notifAll[] = [
                        'text' => dashBuildNotifText(
                            $row['nama_notif'] ?? 'User',
                            'kegiatan',
                            'Kegiatan',
                            $row['tanggal_notif'] ?? ($row['created_at'] ?? ''),
                            $row['jam_notif'] ?? ($row['created_at'] ?? '')
                        ),
                        'url' => 'add_kegiatan.php',
                        'type' => 'kegiatan',
                        'title' => 'Kegiatan Baru',
                        'time_key' => trim(($row['created_at'] ?? '') . ' ' . ($row['tanggal_notif'] ?? '') . ' ' . ($row['jam_notif'] ?? '')),
                    ];
                }
            }
        }

        // Fallback database lama: aktivitas_daftar
        if (tableExists($conn, 'aktivitas_daftar')) {
            $nameCol = dashPickColumn($conn, 'aktivitas_daftar', ['NAMA', 'nama', 'username'], 'NAMA');
            $dateCol = dashPickColumn($conn, 'aktivitas_daftar', ['TANGGAL', 'tanggal', 'created_at'], 'TANGGAL');
            $timeCol = dashPickColumn($conn, 'aktivitas_daftar', ['JAM', 'jam', 'created_at'], 'JAM');
            $outletCol = dashPickColumn($conn, 'aktivitas_daftar', ['OUTLET', 'outlet', 'LOKASI', 'lokasi', 'store'], '');

            $outletSelect = $outletCol !== '' ? "`{$outletCol}` AS target_outlet" : "'' AS target_outlet";

            $qNotif1 = mysqli_query($conn, "
                SELECT `{$nameCol}` AS nama_notif,
                       `{$dateCol}` AS tanggal_notif,
                       `{$timeCol}` AS jam_notif,
                       {$outletSelect}
                FROM aktivitas_daftar
                WHERE DATE(`{$dateCol}`) = CURDATE()
                ORDER BY `{$dateCol}` DESC, `{$timeCol}` DESC
                LIMIT 5
            ");
            if ($qNotif1) {
                while ($row = mysqli_fetch_assoc($qNotif1)) {
                    $notifAll[] = [
                        'text' => dashBuildNotifText($row['nama_notif'] ?? 'User', 'visit', $row['target_outlet'] ?? 'Outlet', $row['tanggal_notif'] ?? '', $row['jam_notif'] ?? ''),
                        'url' => 'data_aktivitas.php',
                        'type' => 'aktivitas',
                        'title' => 'Aktivitas Visit Baru',
                        'time_key' => trim(($row['tanggal_notif'] ?? '') . ' ' . ($row['jam_notif'] ?? '')),
                    ];
                }
            }
        }

        // Fallback database lama: add_kegiatan
        if (tableExists($conn, 'add_kegiatan')) {
            $nameCol = dashPickColumn($conn, 'add_kegiatan', ['NAMA', 'nama', 'username'], 'NAMA');
            $dateCol = dashPickColumn($conn, 'add_kegiatan', ['Date', 'TANGGAL', 'tanggal', 'created_at'], 'Date');
            $timeCol = dashPickColumn($conn, 'add_kegiatan', ['JAM', 'jam', 'created_at'], 'JAM');

            $qNotif2 = mysqli_query($conn, "
                SELECT `{$nameCol}` AS nama_notif,
                       `{$dateCol}` AS tanggal_notif,
                       `{$timeCol}` AS jam_notif
                FROM add_kegiatan
                WHERE DATE(`{$dateCol}`) = CURDATE()
                ORDER BY `{$dateCol}` DESC, `{$timeCol}` DESC
                LIMIT 5
            ");
            if ($qNotif2) {
                while ($row = mysqli_fetch_assoc($qNotif2)) {
                    $notifAll[] = [
                        'text' => dashBuildNotifText($row['nama_notif'] ?? 'User', 'kegiatan', 'Kegiatan', $row['tanggal_notif'] ?? '', $row['jam_notif'] ?? ''),
                        'url' => 'add_kegiatan.php',
                        'type' => 'kegiatan',
                        'title' => 'Kegiatan Baru',
                        'time_key' => trim(($row['tanggal_notif'] ?? '') . ' ' . ($row['jam_notif'] ?? '')),
                    ];
                }
            }
        }

        usort($notifAll, function ($a, $b) {
            return strcmp($b['time_key'], $a['time_key']);
        });

        $notifAll = array_slice($notifAll, 0, 12);

        jsonResponse([
            'success' => true,
            'count' => count($notifAll),
            'items' => $notifAll,
        ]);
    }

    if ($_GET['ajax'] === 'popup') {
        $popup = null;

        $qPopup = mysqli_query($conn, "
            SELECT id, judul, isi, link 
            FROM dashboard_popup
            WHERE status = 'ON'
            ORDER BY id DESC
            LIMIT 1
        ");

        if ($qPopup && mysqli_num_rows($qPopup) > 0) {
            $popup = mysqli_fetch_assoc($qPopup);
        }

        jsonResponse([
            'success' => true,
            'popup' => $popup,
        ]);
    }

    if ($_GET['ajax'] === 'latest_news') {
        // Berita eksternal dipindah ke AJAX supaya dashboard utama tidak menunggu request internet.
        $latestNewsAjax = fetchLatestGoogleNews(6);

        jsonResponse([
            'success' => true,
            'items' => $latestNewsAjax,
        ]);
    }
}

/* =========================
   DATA DASHBOARD - DONUT RIWAYAT BULAN BERJALAN
   FIX V6:
   - Donut Aktivitas mengikuti konsep log_riwayat_aktivitas.php:
     gabungan log_riwayat_aktivitas + visit_activities.
   - Donut Kegiatan:
     gabungan log_riwayat_kegiatan + visit_kegiatan + add_kegiatan.
   - Filter bulan berjalan diproses via PHP supaya format tanggal campur tetap kebaca.
========================= */

$labels = [];
$values = [];
$labels2 = [];
$values2 = [];

function dashDonutName($value)
{
    $value = trim((string)$value);
    if ($value === '') return 'UNKNOWN';

    $value = preg_replace('/\s+/', ' ', $value);

    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($value, 'UTF-8');
    }

    return strtoupper($value);
}

function dashDonutTimestamp($tanggal, $jam = '')
{
    $tanggal = trim((string)$tanggal);
    $jam = trim((string)$jam);

    if ($tanggal === '') return 0;

    $raw = trim($tanggal . ' ' . $jam);
    $raw = trim(preg_replace('/\s+/', ' ', $raw));

    $try = [
        $raw,
        $tanggal,
    ];

    foreach ($try as $candidate) {
        $ts = strtotime($candidate);
        if ($ts) return $ts;
    }

    $bulanMap = [
        'januari' => '01', 'jan' => '01',
        'februari' => '02', 'feb' => '02',
        'maret' => '03', 'mar' => '03',
        'april' => '04', 'apr' => '04',
        'mei' => '05', 'may' => '05',
        'juni' => '06', 'jun' => '06',
        'juli' => '07', 'jul' => '07',
        'agustus' => '08', 'agu' => '08', 'august' => '08', 'aug' => '08',
        'september' => '09', 'sep' => '09',
        'oktober' => '10', 'okt' => '10', 'october' => '10', 'oct' => '10',
        'november' => '11', 'nov' => '11',
        'desember' => '12', 'des' => '12', 'december' => '12', 'dec' => '12',
    ];

    $lower = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);

    if (preg_match('/\b(\d{1,2})\s+([a-zA-Z\x{00C0}-\x{017F}]+)\s+(\d{4})\b/u', $lower, $m)) {
        $month = $bulanMap[$m[2]] ?? '';
        if ($month !== '') {
            $normalized = $m[3] . '-' . $month . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT) . ' ' . $jam;
            $ts = strtotime($normalized);
            if ($ts) return $ts;
        }
    }

    // DD-MM-YYYY atau DD/MM/YYYY
    if (preg_match('/\b(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})\b/', $tanggal, $m)) {
        $normalized = $m[3] . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT) . ' ' . $jam;
        $ts = strtotime($normalized);
        if ($ts) return $ts;
    }

    // YYYY-MM-DD atau YYYY/MM/DD
    if (preg_match('/\b(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})\b/', $tanggal, $m)) {
        $normalized = $m[1] . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($m[3], 2, '0', STR_PAD_LEFT) . ' ' . $jam;
        $ts = strtotime($normalized);
        if ($ts) return $ts;
    }

    return 0;
}

function dashDonutAddCount(&$bucket, $nama, $tanggal, $jam = '')
{
    $ts = dashDonutTimestamp($tanggal, $jam);

    if (!$ts) {
        return;
    }

    if (date('m', $ts) !== date('m') || date('Y', $ts) !== date('Y')) {
        return;
    }

    $nama = dashDonutName($nama);

    if (!isset($bucket[$nama])) {
        $bucket[$nama] = 0;
    }

    $bucket[$nama]++;
}

function dashDonutToChartArrays($bucket, &$labelsTarget, &$valuesTarget)
{
    arsort($bucket);

    foreach ($bucket as $nama => $total) {
        $labelsTarget[] = $nama;
        $valuesTarget[] = (int)$total;
    }
}

/* =========================
   DONUT AKTIVITAS
   SUMBER:
   1. log_riwayat_aktivitas
   2. visit_activities
========================= */
$aktivitasBucket = [];

// 1) History lama / appsheet.
if (tableExists($conn, 'log_riwayat_aktivitas')) {
    $qAktLog = mysqli_query($conn, "
        SELECT
            TANGGAL,
            JAM,
            NAMA
        FROM log_riwayat_aktivitas
    ");

    if ($qAktLog) {
        while ($row = mysqli_fetch_assoc($qAktLog)) {
            dashDonutAddCount(
                $aktivitasBucket,
                $row['NAMA'] ?? '',
                $row['TANGGAL'] ?? '',
                $row['JAM'] ?? ''
            );
        }
    }
}

// 2) Visit baru, sama seperti yang ikut tampil di log_riwayat_aktivitas.php.
if (tableExists($conn, 'visit_activities')) {
    $visitNameCol = dashPickColumn($conn, 'visit_activities', ['nama_user', 'NAMA', 'nama', 'username'], '');
    $visitDateCol = dashPickColumn($conn, 'visit_activities', ['tanggal', 'TANGGAL', 'created_at'], '');
    $visitTimeCol = dashPickColumn($conn, 'visit_activities', ['jam', 'JAM', 'created_at'], '');

    if ($visitNameCol !== '' && $visitDateCol !== '') {
        $safeName = preg_replace('/[^A-Za-z0-9_]+/', '', $visitNameCol);
        $safeDate = preg_replace('/[^A-Za-z0-9_]+/', '', $visitDateCol);
        $safeTime = preg_replace('/[^A-Za-z0-9_]+/', '', $visitTimeCol);

        $timeSelect = $safeTime !== '' ? "`{$safeTime}` AS jam_data" : "'' AS jam_data";

        $qVisitAkt = mysqli_query($conn, "
            SELECT
                `{$safeName}` AS nama_data,
                `{$safeDate}` AS tanggal_data,
                {$timeSelect}
            FROM visit_activities
        ");

        if ($qVisitAkt) {
            while ($row = mysqli_fetch_assoc($qVisitAkt)) {
                dashDonutAddCount(
                    $aktivitasBucket,
                    $row['nama_data'] ?? '',
                    $row['tanggal_data'] ?? '',
                    $row['jam_data'] ?? ''
                );
            }
        }
    }
}

dashDonutToChartArrays($aktivitasBucket, $labels, $values);

/* =========================
   DONUT KEGIATAN
   SUMBER:
   1. log_riwayat_kegiatan
   2. visit_kegiatan
   3. add_kegiatan
========================= */
$kegiatanBucket = [];

// 1) History kegiatan.
if (tableExists($conn, 'log_riwayat_kegiatan')) {
    $kgNameCol = dashPickColumn($conn, 'log_riwayat_kegiatan', ['NAMA', 'Nama', 'nama', 'nama_user', 'username'], '');
    $kgDateCol = dashPickColumn($conn, 'log_riwayat_kegiatan', ['Date', 'DATE', 'date', 'TANGGAL', 'Tanggal', 'tanggal', 'created_at'], '');
    $kgTimeCol = dashPickColumn($conn, 'log_riwayat_kegiatan', ['JAM', 'Jam', 'jam', 'waktu', 'created_at'], '');

    if ($kgNameCol !== '' && $kgDateCol !== '') {
        $safeName = preg_replace('/[^A-Za-z0-9_]+/', '', $kgNameCol);
        $safeDate = preg_replace('/[^A-Za-z0-9_]+/', '', $kgDateCol);
        $safeTime = preg_replace('/[^A-Za-z0-9_]+/', '', $kgTimeCol);

        $timeSelect = $safeTime !== '' ? "`{$safeTime}` AS jam_data" : "'' AS jam_data";

        $qKgLog = mysqli_query($conn, "
            SELECT
                `{$safeName}` AS nama_data,
                `{$safeDate}` AS tanggal_data,
                {$timeSelect}
            FROM log_riwayat_kegiatan
        ");

        if ($qKgLog) {
            while ($row = mysqli_fetch_assoc($qKgLog)) {
                dashDonutAddCount(
                    $kegiatanBucket,
                    $row['nama_data'] ?? '',
                    $row['tanggal_data'] ?? '',
                    $row['jam_data'] ?? ''
                );
            }
        }
    }
}

// 2) Kegiatan baru dari visit.php.
if (tableExists($conn, 'visit_kegiatan')) {
    $vkNameCol = dashPickColumn($conn, 'visit_kegiatan', ['nama_user', 'NAMA', 'Nama', 'nama', 'username'], '');
    $vkDateCol = dashPickColumn($conn, 'visit_kegiatan', ['tanggal', 'TANGGAL', 'Date', 'created_at'], '');
    $vkTimeCol = dashPickColumn($conn, 'visit_kegiatan', ['jam', 'JAM', 'created_at'], '');

    if ($vkNameCol !== '' && $vkDateCol !== '') {
        $safeName = preg_replace('/[^A-Za-z0-9_]+/', '', $vkNameCol);
        $safeDate = preg_replace('/[^A-Za-z0-9_]+/', '', $vkDateCol);
        $safeTime = preg_replace('/[^A-Za-z0-9_]+/', '', $vkTimeCol);

        $timeSelect = $safeTime !== '' ? "`{$safeTime}` AS jam_data" : "'' AS jam_data";

        $qVk = mysqli_query($conn, "
            SELECT
                `{$safeName}` AS nama_data,
                `{$safeDate}` AS tanggal_data,
                {$timeSelect}
            FROM visit_kegiatan
        ");

        if ($qVk) {
            while ($row = mysqli_fetch_assoc($qVk)) {
                dashDonutAddCount(
                    $kegiatanBucket,
                    $row['nama_data'] ?? '',
                    $row['tanggal_data'] ?? '',
                    $row['jam_data'] ?? ''
                );
            }
        }
    }
}

// 3) Fallback tabel add_kegiatan.
if (tableExists($conn, 'add_kegiatan')) {
    $akNameCol = dashPickColumn($conn, 'add_kegiatan', ['NAMA', 'Nama', 'nama', 'nama_user', 'username'], '');
    $akDateCol = dashPickColumn($conn, 'add_kegiatan', ['Date', 'DATE', 'date', 'TANGGAL', 'Tanggal', 'tanggal', 'created_at'], '');
    $akTimeCol = dashPickColumn($conn, 'add_kegiatan', ['JAM', 'Jam', 'jam', 'waktu', 'created_at'], '');

    if ($akNameCol !== '' && $akDateCol !== '') {
        $safeName = preg_replace('/[^A-Za-z0-9_]+/', '', $akNameCol);
        $safeDate = preg_replace('/[^A-Za-z0-9_]+/', '', $akDateCol);
        $safeTime = preg_replace('/[^A-Za-z0-9_]+/', '', $akTimeCol);

        $timeSelect = $safeTime !== '' ? "`{$safeTime}` AS jam_data" : "'' AS jam_data";

        $qAk = mysqli_query($conn, "
            SELECT
                `{$safeName}` AS nama_data,
                `{$safeDate}` AS tanggal_data,
                {$timeSelect}
            FROM add_kegiatan
        ");

        if ($qAk) {
            while ($row = mysqli_fetch_assoc($qAk)) {
                dashDonutAddCount(
                    $kegiatanBucket,
                    $row['nama_data'] ?? '',
                    $row['tanggal_data'] ?? '',
                    $row['jam_data'] ?? ''
                );
            }
        }
    }
}

dashDonutToChartArrays($kegiatanBucket, $labels2, $values2);

/* =========================
   COUNT & SLIDER AKTIVITAS VISIT HERO
   Source disamakan dengan data_aktivitas.php: visit_activities
========================= */
$countAktivitas = 0;
$aktivitasSlides = [];

if (tableExists($conn, 'visit_activities')) {
    $q1 = mysqli_query($conn, "
        SELECT COUNT(*) AS total
        FROM visit_activities
        WHERE DATE(created_at) = CURDATE()
           OR LEFT(TRIM(tanggal), 10) = CURDATE()
    ");
    $countAktivitas = ($q1) ? (int)(mysqli_fetch_assoc($q1)['total'] ?? 0) : 0;

    $fotoDepanSelect = columnExists($conn, 'visit_activities', 'foto_depan_store')
        ? "v.foto_depan_store AS FOTO_DEPAN"
        : "'' AS FOTO_DEPAN";

    $qAktivitasSlide = mysqli_query($conn, "
        SELECT
            v.id AS DATA_ID,
            v.nama_user AS NAMA,
            v.tanggal AS TANGGAL,
            v.jam AS JAM,
            v.hari AS HARI,
            v.area_terminal AS AREA_TERMINAL,
            v.store AS OUTLET,
            v.nama_crew AS CREW,
            v.omset_kemarin AS OMSET_KEMARIN,
            v.ada_temuan AS TEMUAN,
            CONCAT_WS('<br>',
                NULLIF(v.foto_temuan_1,''),
                NULLIF(v.foto_temuan_2,''),
                NULLIF(v.foto_temuan_3,''),
                NULLIF(v.foto_temuan_4,'')
            ) AS POINT_TEMUAN,
            CONCAT(v.latitude, ',', v.longitude) AS LOKASI,
            {$fotoDepanSelect}
        FROM visit_activities v
        WHERE DATE(v.created_at) = CURDATE()
           OR LEFT(TRIM(v.tanggal), 10) = CURDATE()
        ORDER BY v.created_at DESC, v.tanggal DESC, v.jam DESC
        LIMIT 5
    ");

    if ($qAktivitasSlide) {
        while ($row = mysqli_fetch_assoc($qAktivitasSlide)) {
            $aktivitasSlides[] = $row;
        }
    }
} elseif (tableExists($conn, 'aktivitas_daftar')) {
    // Fallback untuk database lama.
    $q1 = mysqli_query($conn, "SELECT COUNT(*) AS total FROM aktivitas_daftar WHERE DATE(TANGGAL) = CURDATE()");
    $countAktivitas = ($q1) ? (int)(mysqli_fetch_assoc($q1)['total'] ?? 0) : 0;

    $qAktivitasSlide = mysqli_query($conn, "
        SELECT
            NAMA, TANGGAL, JAM,
            '' AS HARI,
            COALESCE(OUTLET, LOKASI, 'Aktivitas Visit') AS OUTLET,
            '' AS AREA_TERMINAL,
            '' AS CREW,
            '' AS OMSET_KEMARIN,
            '' AS TEMUAN,
            COALESCE(KETERANGAN, CATATAN, '') AS POINT_TEMUAN,
            '' AS LOKASI,
            '' AS FOTO_DEPAN
        FROM aktivitas_daftar
        WHERE DATE(TANGGAL) = CURDATE()
        ORDER BY TANGGAL DESC, JAM DESC
        LIMIT 5
    ");

    if ($qAktivitasSlide) {
        while ($row = mysqli_fetch_assoc($qAktivitasSlide)) {
            $aktivitasSlides[] = $row;
        }
    }
}



/* =========================
   SLIDER KEGIATAN HARI INI + COUNT QTY PER NAMA_USER
   Sumber data: visit_kegiatan.
   Logic:
   - Data hari ini saja.
   - Group berdasarkan nama_user yang sudah di-trim dan di-uppercase.
   - Nama sama tetap dihitung sesuai jumlah input.
     Contoh: WIRA DARMAWAN input 3 baris => qty 3.
========================= */
$workOrderRealisasiSlides = [];
$countKegiatan = 0;
$kegiatanUserBucket = [];

if (tableExists($conn, 'visit_kegiatan')) {
    $kgNameCol = dashPickColumn($conn, 'visit_kegiatan', ['nama_user', 'NAMA', 'Nama', 'nama', 'username'], '');
    $kgDateCol = dashPickColumn($conn, 'visit_kegiatan', ['tanggal', 'TANGGAL', 'Date', 'date', 'created_at'], '');
    $kgTimeCol = dashPickColumn($conn, 'visit_kegiatan', ['jam', 'JAM', 'Jam', 'waktu', 'created_at'], '');
    $kgDescCol = dashPickColumn($conn, 'visit_kegiatan', ['kegiatan', 'KEGIATAN', 'nama_kegiatan', 'deskripsi', 'description', 'keterangan', 'KETERANGAN', 'catatan', 'CATATAN', 'uraian'], '');
    $kgImageCol = dashPickColumn($conn, 'visit_kegiatan', ['foto', 'FOTO', 'foto_kegiatan', 'FOTO_KEGIATAN', 'photo', 'image', 'gambar', 'dokumentasi', 'foto1', 'foto_1', 'foto_depan', 'FOTO_DEPAN'], '');

    if ($kgNameCol !== '' && $kgDateCol !== '') {
        $safeName = preg_replace('/[^A-Za-z0-9_]+/', '', $kgNameCol);
        $safeDate = preg_replace('/[^A-Za-z0-9_]+/', '', $kgDateCol);
        $safeTime = preg_replace('/[^A-Za-z0-9_]+/', '', $kgTimeCol);
        $safeDesc = preg_replace('/[^A-Za-z0-9_]+/', '', $kgDescCol);
        $safeImage = preg_replace('/[^A-Za-z0-9_]+/', '', $kgImageCol);

        $timeSelect = $safeTime !== '' ? "`{$safeTime}` AS jam_data" : "'' AS jam_data";
        $descSelect = $safeDesc !== '' ? "`{$safeDesc}` AS desc_data" : "'' AS desc_data";
        $imageSelect = $safeImage !== '' ? "`{$safeImage}` AS image_path" : "'' AS image_path";
        $createdSelect = columnExists($conn, 'visit_kegiatan', 'created_at') ? "created_at" : "'' AS created_at";
        $orderTime = $safeTime !== '' ? "`{$safeTime}` DESC" : "id DESC";

        $qKegiatanHero = mysqli_query($conn, "
            SELECT
                id,
                `{$safeName}` AS nama_data,
                `{$safeDate}` AS tanggal_data,
                {$timeSelect},
                {$descSelect},
                {$imageSelect},
                {$createdSelect}
            FROM visit_kegiatan
            ORDER BY created_at DESC, `{$safeDate}` DESC, {$orderTime}
        ");

        if ($qKegiatanHero) {
            while ($row = mysqli_fetch_assoc($qKegiatanHero)) {
                $tanggalData = $row['tanggal_data'] ?? '';
                $jamData = $row['jam_data'] ?? '';
                $createdAtData = $row['created_at'] ?? '';

                $ts = dashDonutTimestamp($tanggalData, $jamData);
                if (!$ts && trim((string)$createdAtData) !== '') {
                    $ts = strtotime((string)$createdAtData);
                }

                // Hanya hitung kegiatan hari ini.
                if (!$ts || date('Y-m-d', $ts) !== date('Y-m-d')) {
                    continue;
                }

                $namaRaw = cleanDashValue($row['nama_data'] ?? '', 'User');
                $namaKey = dashDonutName($namaRaw);
                if ($namaKey === '' || $namaKey === 'UNKNOWN') {
                    $namaKey = 'USER';
                }

                $deskripsiKegiatan = cleanDashValue($row['desc_data'] ?? '', 'Telah melakukan kegiatan hari ini.');
                $gambarKegiatan = trim((string)($row['image_path'] ?? ''));
                $waktuKegiatan = date('Y-m-d H:i:s', $ts);

                if (!isset($kegiatanUserBucket[$namaKey])) {
                    $kegiatanUserBucket[$namaKey] = [
                        'id' => $row['id'] ?? '',
                        'title' => $namaKey,
                        'description' => $deskripsiKegiatan,
                        'image_path' => $gambarKegiatan,
                        'created_at' => $waktuKegiatan,
                        'created_by' => $namaKey,
                        'qty' => 0,
                        'last_ts' => 0,
                    ];
                }

                // Nama sama tetap ditambah qty sesuai jumlah baris input.
                $kegiatanUserBucket[$namaKey]['qty']++;
                $countKegiatan++;

                // Simpan data terakhir untuk preview card/slider.
                if ($ts >= (int)$kegiatanUserBucket[$namaKey]['last_ts']) {
                    $kegiatanUserBucket[$namaKey]['id'] = $row['id'] ?? '';
                    $kegiatanUserBucket[$namaKey]['description'] = $deskripsiKegiatan;
                    $kegiatanUserBucket[$namaKey]['image_path'] = $gambarKegiatan;
                    $kegiatanUserBucket[$namaKey]['created_at'] = $waktuKegiatan;
                    $kegiatanUserBucket[$namaKey]['last_ts'] = $ts;
                }
            }
        }

        uasort($kegiatanUserBucket, function ($a, $b) {
            if ((int)($b['qty'] ?? 0) === (int)($a['qty'] ?? 0)) {
                return (int)($b['last_ts'] ?? 0) <=> (int)($a['last_ts'] ?? 0);
            }
            return (int)($b['qty'] ?? 0) <=> (int)($a['qty'] ?? 0);
        });

        foreach ($kegiatanUserBucket as $item) {
            $qty = (int)($item['qty'] ?? 0);
            if ($qty < 1) {
                continue;
            }

           

            $workOrderRealisasiSlides[] = $item;

            if (count($workOrderRealisasiSlides) >= 8) {
                break;
            }
        }
    }
}

$q3 = mysqli_query($conn, "
    SELECT COUNT(DISTINCT UPPER(TRIM(nama_lengkap))) AS total
    FROM karyawan
    WHERE nama_lengkap IS NOT NULL
      AND TRIM(nama_lengkap) <> ''
      AND is_resign = 0
");

$countKaryawan = ($q3) ? (int)(mysqli_fetch_assoc($q3)['total'] ?? 0) : 0;
$userEmailSelect = "'' AS email";
if (columnExists($conn, 'users', 'email')) {
    $userEmailSelect = "`email` AS email";
} elseif (columnExists($conn, 'users', 'email_user')) {
    $userEmailSelect = "`email_user` AS email";
} elseif (columnExists($conn, 'users', 'user_email')) {
    $userEmailSelect = "`user_email` AS email";
}

// Jabatan dipakai khusus untuk pop up profil header.
// Role tetap dipakai untuk logic akses/superadmin agar tidak mengganggu RBAC.
$userJabatanSelect = "'' AS jabatan";
if (columnExists($conn, 'users', 'jabatan')) {
    $userJabatanSelect = "`jabatan` AS jabatan";
} elseif (columnExists($conn, 'users', 'position')) {
    $userJabatanSelect = "`position` AS jabatan";
} elseif (columnExists($conn, 'users', 'job_title')) {
    $userJabatanSelect = "`job_title` AS jabatan";
}

$q4 = mysqli_query($conn, "
    SELECT role, foto, {$userEmailSelect}, {$userJabatanSelect}
    FROM users
    WHERE username = '" . mysqli_real_escape_string($conn, $username) . "'
    LIMIT 1
");
$rowUser = ($q4) ? mysqli_fetch_assoc($q4) : [];
$roleUser = $rowUser['role'] ?? 'Admin';
$userJabatan = cleanDashValue($rowUser['jabatan'] ?? '', cleanDashValue($roleUser ?? '', 'Jabatan belum tersedia'));
/* ===== SQM PROFILE PHOTO SYNC PATCH =====
   Foto dari SQM Visit disimpan di users.foto. Dashboard membaca fleksibel:
   - uploads/profile/xxx.jpg  -> langsung dipakai
   - profile/xxx.jpg          -> uploads/profile/xxx.jpg
   - xxx.jpg                  -> uploads/xxx.jpg atau uploads/profile/xxx.jpg
   - URL http/https           -> langsung dipakai
*/
if (!function_exists('dashResolveUserPhoto')) {
    function dashResolveUserPhoto($fotoRaw) {
        $fotoRaw = trim((string)$fotoRaw);
        if ($fotoRaw === '') return 'img/default.png';

        if (preg_match('/^https?:\/\//i', $fotoRaw)) {
            return $fotoRaw;
        }

        $fotoRawClean = ltrim($fotoRaw, '/');

        $candidates = [];
        if (strpos($fotoRawClean, 'uploads/') === 0 || strpos($fotoRawClean, 'img/') === 0) {
            $candidates[] = $fotoRawClean;
        } else {
            $candidates[] = 'uploads/' . $fotoRawClean;
            $candidates[] = 'uploads/profile/' . $fotoRawClean;
            $candidates[] = 'uploads/user/' . $fotoRawClean;
            $candidates[] = 'uploads/users/' . $fotoRawClean;
        }

        foreach ($candidates as $candidate) {
            if (is_file(__DIR__ . '/' . $candidate)) {
                return $candidate;
            }
        }

        // Fallback tetap return path yang paling mungkin, supaya foto baru dari Visit langsung kebaca.
        if (strpos($fotoRawClean, 'uploads/') === 0 || strpos($fotoRawClean, 'img/') === 0) {
            return $fotoRawClean;
        }
        return 'uploads/' . $fotoRawClean;
    }
}
$userPhoto = dashResolveUserPhoto($rowUser['foto'] ?? '');
$userEmail = cleanDashValue($rowUser['email'] ?? '', 'Email belum tersedia');

/* =========================
   RBAC SALES OUTLET
========================= */
$username = strtolower(trim($username));
$roleUserUpper = strtoupper(trim($roleUser ?? ''));

$full_access_users = ['wahid', 'wira', 'ujang', 'zahra', 'yan', 'alfia', 'aca', 'azik', 'prengkuh', 'admin1', 'whina', 'ratna'];

$all_toko_list = [
    'papimart1' => 'Papimart 1',
    'papimart2' => 'Papimart 2',
    'papimart3' => 'Papimart 3',
    'abyd2'     => 'ABYD 2',
    'abyd6'     => 'ABYD 6',
    'pod1'      => 'POD 1',
    'pod3'      => 'POD 3',
    'pod5'      => 'POD 5',
    'pod7'      => 'POD 7',
    'urbanb4'   => 'Urban B4',
    'urbanb6'   => 'Urban B6',
    'urbanb7'   => 'Urban B7',
    'pcb5'      => 'PCB 5',
    'mmart'     => 'MMart',
    'pmg18'     => 'PMG 18',
    'lst1c'     => 'LST 1C',
    'lst2e'     => 'LST 2E',
    'lst2f'     => 'LST 2F',
    'pmbim'     => 'PMBIM',
];

$user_access = [
    'mustaqim'   => ['urbanb4', 'urbanb6', 'urbanb7', 'pcb5', 'lst1c', 'lst2e', 'lst2f'],
    'umam'       => ['lst2e', 'lst2f'],
    'yanto'      => ['lst1c'],
    'yan'        => ['pmbim'],
    'wulandari'  => ['pmbim'],
    'dede'       => ['abyd2', 'abyd6', 'pod7'],
    'devi'       => ['pod1', 'pod3', 'pod5'],
    'haris'      => ['papimart1', 'papimart2', 'papimart3', 'abyd2', 'abyd6', 'pod1', 'pod3', 'pod5', 'pod7', 'mmart', 'pmg18'],
    'jesen'      => ['pcb5', 'urbanb4', 'urbanb6', 'urbanb7'],
    'lusiah'      => ['pcb5', 'urbanb4', 'urbanb6', 'urbanb7'],
    'adit'       => ['mmart', 'pmg18'],
    'admin2'     => ['papimart1', 'papimart2', 'papimart3'],
];

if ($roleUserUpper === 'SUPER ADMIN' || $roleUserUpper === 'SUPERADMIN' || in_array($username, $full_access_users, true)) {
    $allowed_store_codes = array_keys($all_toko_list);
} else {
    $allowed_store_codes = $user_access[$username] ?? [];
}

$hasAccess = !empty($allowed_store_codes);

$toko_list = [];
if ($hasAccess) {
    foreach ($allowed_store_codes as $kode) {
        if (isset($all_toko_list[$kode])) {
            $toko_list[$kode] = $all_toko_list[$kode];
        }
    }
}

$allowedOutletNames = array_values($toko_list);
$allowedOutletText  = !empty($allowedOutletNames) ? implode(', ', $allowedOutletNames) : 'Tidak ada outlet';

/* =========================
   HERO DASHBOARD
========================= */
$heroData = [
    'badge_text'   => 'Informasi',
    'title'        => 'Dear Spv',
    'message'      => 'Pengumpulan KPI Karyawan yang sudah di informasikan di harap segera di kumpulkan secepatnya.',
    'button1_text' => 'Lihat Omset',
    'button1_link' => 'summary-sales-toko.php',
    'button2_text' => 'Lapor Kehadiran',
    'button2_link' => 'absensi_leader_spv.php',
    'button3_text' => 'Monitoring Kehadiran',
    'button3_link' => 'absensi_monitoring_admin.php',
    'button4_text' => 'Lihat Jadwal',
    'button4_link' => 'jadwal-spv.php',
    'image_path'   => '',
];

ensureDashboardHeroButton4($conn);

$heroSelectColumns = "badge_text, title, message, button1_text, button1_link, button2_text, button2_link, button3_text, button3_link";
if (columnExists($conn, 'dashboard_hero', 'button4_text')) {
    $heroSelectColumns .= ", button4_text";
}
if (columnExists($conn, 'dashboard_hero', 'button4_link')) {
    $heroSelectColumns .= ", button4_link";
}
if (columnExists($conn, 'dashboard_hero', 'image_path')) {
    $heroSelectColumns .= ", image_path";
}

$qHero = mysqli_query($conn, "
    SELECT {$heroSelectColumns}
    FROM dashboard_hero
    WHERE is_active = 1
    ORDER BY id DESC
    LIMIT 1
");

if ($qHero && mysqli_num_rows($qHero) > 0) {
    $rowHero = mysqli_fetch_assoc($qHero);
    if ($rowHero) {
        $heroData = array_merge($heroData, $rowHero);
    }
}

// Sinkron dengan hero_dashboard_admin.php: tombol utama dibuat konsisten.
// Jika data lama belum punya tombol jadwal atau kosong, dashboard tetap menampilkan tombol jadwal.
$fixedHeroButtons = [
    1 => ['text' => 'Lihat Omset', 'link' => 'summary-sales-toko.php'],
    2 => ['text' => 'Lapor Kehadiran', 'link' => 'visit.php'],
    3 => ['text' => 'Monitoring Kehadiran', 'link' => 'absensi_monitoring_admin.php'],
    4 => ['text' => 'Lihat Jadwal', 'link' => 'jadwal-spv.php'],
];

foreach ($fixedHeroButtons as $buttonNo => $buttonData) {
    $textKey = 'button' . $buttonNo . '_text';
    $linkKey = 'button' . $buttonNo . '_link';

    // Default hanya dipakai kalau data DB kosong.
    // Kalau link/text diedit dari hero_dashboard_admin.php, dashboard memakai nilai dari DB.
    if (trim((string)($heroData[$textKey] ?? '')) === '') {
        $heroData[$textKey] = $buttonData['text'];
    }

    if (trim((string)($heroData[$linkKey] ?? '')) === '') {
        $heroData[$linkKey] = $buttonData['link'];
    }
}

/* =========================
   GRAFIK SALES BERDASARKAN RBAC USER
========================= */
$salesLabels = [];
$salesValues = [];
$totalSalesKeseluruhan = 0;

// =========================
// LOGIC BULAN H+1 (DASHBOARD)
// Tanggal 1 = bulan sebelumnya
// Tanggal 2 dst = bulan sekarang
// =========================
$todayDay = (int)date('d');

if ($todayDay === 1) {
    $selected_bulan = date('m', strtotime('first day of previous month'));
    $selected_tahun = date('Y', strtotime('first day of previous month'));
    $bulanTampil    = date('F Y', strtotime('first day of previous month'));
} else {
    $selected_bulan = date('m');
    $selected_tahun = date('Y');
    $bulanTampil    = date('F Y');
}

$excludedCols = ['tanggal', 'pmbali', 'lsa5', 'a6'];

$qCols = mysqli_query($conn, "SHOW COLUMNS FROM omset_detail");
$existingColumns = [];

if ($qCols) {
    while ($col = mysqli_fetch_assoc($qCols)) {
        $field = strtolower(trim($col['Field'] ?? ''));
        if ($field !== '') {
            $existingColumns[] = $field;
        }
    }
}

$salesColumns = [];
$targetColumnsMap = [];

if ($hasAccess) {
    foreach ($allowed_store_codes as $kode) {
        $kode = strtolower(trim($kode));

        if (
            in_array($kode, $existingColumns, true) &&
            !in_array($kode, $excludedCols, true)
        ) {
            $salesColumns[] = "`" . str_replace("`", "``", $kode) . "`";
        }
    }
}

if (!empty($salesColumns)) {
    $sumExpr = implode(' + ', array_map(function ($col) {
        return "COALESCE($col, 0)";
    }, $salesColumns));

    // Chart sales tampil 1 bulan penuh.
    // Tanggal yang belum ada omset dibuat NULL agar tidak dianggap omset 0 palsu.
    $daysInSelectedMonth = cal_days_in_month(CAL_GREGORIAN, (int)$selected_bulan, (int)$selected_tahun);
    $salesValuesByDay = [];

    for ($day = 1; $day <= $daysInSelectedMonth; $day++) {
        $dayKey = str_pad((string)$day, 2, '0', STR_PAD_LEFT);
        $salesLabels[] = $dayKey;
        $salesValuesByDay[$dayKey] = null;
    }

    $sqlSales = "
        SELECT
            DATE_FORMAT(tanggal, '%d') AS hari,
            SUM($sumExpr) AS total_sales
        FROM omset_detail
        WHERE MONTH(tanggal) = '" . mysqli_real_escape_string($conn, $selected_bulan) . "'
          AND YEAR(tanggal) = '" . mysqli_real_escape_string($conn, $selected_tahun) . "'
        GROUP BY DATE(tanggal), DATE_FORMAT(tanggal, '%d')
        ORDER BY DATE(tanggal) ASC
    ";

    $resultSales = mysqli_query($conn, $sqlSales);

    if ($resultSales) {
        while ($row = mysqli_fetch_assoc($resultSales)) {
            $hari = str_pad((string)($row['hari'] ?? ''), 2, '0', STR_PAD_LEFT);
            if (array_key_exists($hari, $salesValuesByDay)) {
                $salesValuesByDay[$hari] = (int)($row['total_sales'] ?? 0);
            }
        }
    }

    $salesValues = array_values($salesValuesByDay);
    $totalSalesKeseluruhan = array_sum(array_map(function ($value) {
        return is_numeric($value) ? (int)$value : 0;
    }, $salesValues));
}

/* =========================
   TARGET, RATA-RATA, ACHIEVEMENT
========================= */
/* =========================
   TARGET, RATA-RATA, ACHIEVEMENT
========================= */
$totalTargetBulanan = 0;
$hariBerjalan = (date('Y-m') === $selected_tahun . '-' . $selected_bulan)
    ? (int)date('d')
    : (int)cal_days_in_month(CAL_GREGORIAN, (int)$selected_bulan, (int)$selected_tahun);

$hariAdaOmset = 0;
foreach ($salesValues as $nilaiSales) {
    if ((int)$nilaiSales > 0) {
        $hariAdaOmset++;
    }
}

$rataRataSales = $hariAdaOmset > 0 ? round($totalSalesKeseluruhan / $hariAdaOmset) : 0;

// mapping kode outlet -> nama tenant di retail_target
$targetTenantMap = [
    'papimart1' => 'PAPIMART T2E GATE E3',
    'papimart2' => 'PAPIMART T2E GATE E4',
    'papimart3' => 'PAPIMART T2E GATE E5',
    'abyd2'     => 'AMBIL BEKAL YUK D2',
    'abyd6'     => 'AMBIL BEKAL YUK D6',
    'pod1'      => 'POINT ONE D1',
    'pod3'      => 'POINT ONE D3',
    'pod5'      => 'POINT ONE D5',
    'pod7'      => 'POINT ONE D7',
    'lst1c'     => 'LATTE STORY T1C',
    'urbanb4'   => 'URBAN B4',
    'urbanb6'   => 'URBAN B6',
    'urbanb7'   => 'URBAN B7',
    'pcb5'      => 'PAPI COFFEE B5',
    'lst2f'     => 'LATTE STORY T2F',
    'lst2e'     => 'LATTE STORY T2E',
    'pmbim'     => 'PAPIMART BIM',
    'mmart'     => 'MMART TERMINAL 3',
    'pmg18'     => 'PAPIMART GATE 18'
];

if ($hasAccess && !empty($allowed_store_codes)) {
    $ym = mysqli_real_escape_string($conn, $selected_tahun . '-' . $selected_bulan);

    $targetTenants = [];
    foreach ($allowed_store_codes as $kode) {
        if (isset($targetTenantMap[$kode])) {
            $targetTenants[] = $targetTenantMap[$kode];
        }
    }

    $targetTenants = array_unique($targetTenants);

    if (!empty($targetTenants)) {
        $escapedTenantNames = array_map(function ($tenant) use ($conn) {
            return "'" . mysqli_real_escape_string($conn, $tenant) . "'";
        }, $targetTenants);

        $sqlTarget = "
            SELECT tenant, target
            FROM retail_target
            WHERE ym = '$ym'
              AND tenant IN (" . implode(',', $escapedTenantNames) . ")
        ";

        $resTarget = mysqli_query($conn, $sqlTarget);
        if ($resTarget) {
            while ($rowTarget = mysqli_fetch_assoc($resTarget)) {
                $targetVal = $rowTarget['target'] ?? 0;
                if (is_numeric($targetVal)) {
                    $totalTargetBulanan += (int)$targetVal;
                }
            }
        }
    }
}

$achievementSales = $totalTargetBulanan > 0
    ? round(($totalSalesKeseluruhan / $totalTargetBulanan) * 100, 1)
    : 0;
$achievementSales = $totalTargetBulanan > 0
    ? round(($totalSalesKeseluruhan / $totalTargetBulanan) * 100, 1)
    : 0;

if ($roleUserUpper === 'SUPER ADMIN' || $roleUserUpper === 'SUPERADMIN' || in_array($username, $full_access_users, true)) {
    $salesCardSubtitle = 'Total sales semua outlet bulan ini';
} else {
    $salesCardSubtitle = $hasAccess
        ? 'Total sales outlet: ' . $allowedOutletText
        : 'Anda tidak memiliki akses outlet';
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'omset_harian_alert') {

    if (!$hasAccess || empty($allowed_store_codes)) {
        echo json_encode([
            'success' => true,
            'show' => false
        ]);
        exit;
    }

    $excludedCols = ['tanggal', 'pmbali', 'lsa5', 'a6'];

    $qCols = mysqli_query($conn, "SHOW COLUMNS FROM omset_detail");
    $existingColumns = [];

    while ($col = mysqli_fetch_assoc($qCols)) {
        $existingColumns[] = strtolower($col['Field']);
    }

    $salesColumns = [];
    foreach ($allowed_store_codes as $kode) {
        $kode = strtolower($kode);
        if (in_array($kode, $existingColumns) && !in_array($kode, $excludedCols)) {
            $salesColumns[] = "`$kode`";
        }
    }

    if (empty($salesColumns)) {
        echo json_encode([
            'success' => true,
            'show' => false
        ]);
        exit;
    }

    $sumExpr = implode(' + ', array_map(fn($c) => "COALESCE($c,0)", $salesColumns));

    $sql = "
        SELECT ($sumExpr) as total
        FROM omset_detail
        WHERE DATE(tanggal) = CURDATE() - INTERVAL 1 DAY
        LIMIT 1
    ";

    $res = mysqli_query($conn, $sql);
    $row = mysqli_fetch_assoc($res);

    $total = (int)($row['total'] ?? 0);

    echo json_encode([
        'success' => true,
        'show' => $total > 0,
        'total' => $total,
        'formatted_total' => 'Rp ' . number_format($total, 0, ',', '.'),
        'tanggal' => date('d F Y', strtotime('-1 day')),
        'outlets' => array_values($toko_list),
        'alert_key' => date('Y-m-d') . '|' . $username . '|' . $total
    ]);
    exit;
}

/* =========================
   NOTIF & POPUP
========================= */
$notifAll = [];

if (tableExists($conn, 'visit_activities')) {
    $nameCol = dashPickColumn($conn, 'visit_activities', ['nama_user', 'NAMA', 'nama', 'username'], 'username');
    $dateCol = dashPickColumn($conn, 'visit_activities', ['tanggal', 'TANGGAL', 'created_at'], 'created_at');
    $timeCol = dashPickColumn($conn, 'visit_activities', ['jam', 'JAM', 'created_at'], 'created_at');
    $storeCol = dashPickColumn($conn, 'visit_activities', ['store', 'OUTLET', 'outlet', 'lokasi', 'LOKASI', 'area_terminal'], '');
    $storeSelect = $storeCol !== '' ? "`{$storeCol}` AS target_outlet" : "'' AS target_outlet";

    $qNotifVisitActivity = mysqli_query($conn, "
        SELECT `{$nameCol}` AS nama_notif,
               `{$dateCol}` AS tanggal_notif,
               `{$timeCol}` AS jam_notif,
               {$storeSelect},
               created_at
        FROM visit_activities
        WHERE DATE(created_at) = CURDATE()
           OR LEFT(TRIM(`{$dateCol}`), 10) = CURDATE()
        ORDER BY created_at DESC, `{$dateCol}` DESC, `{$timeCol}` DESC
        LIMIT 10
    ");
    if ($qNotifVisitActivity) {
        while ($row = mysqli_fetch_assoc($qNotifVisitActivity)) {
            $notifAll[] = [
                'text' => dashBuildNotifText($row['nama_notif'] ?? 'User', 'visit', $row['target_outlet'] ?? 'Outlet', $row['tanggal_notif'] ?? ($row['created_at'] ?? ''), $row['jam_notif'] ?? ($row['created_at'] ?? '')),
                'url' => 'data_aktivitas.php',
                'type' => 'aktivitas',
                'title' => 'Aktivitas Visit Baru',
                'time_key' => trim(($row['created_at'] ?? '') . ' ' . ($row['tanggal_notif'] ?? '') . ' ' . ($row['jam_notif'] ?? '')),
            ];
        }
    }
}

if (tableExists($conn, 'visit_kegiatan')) {
    $nameCol = dashPickColumn($conn, 'visit_kegiatan', ['nama_user', 'NAMA', 'nama', 'username'], 'username');
    $dateCol = dashPickColumn($conn, 'visit_kegiatan', ['tanggal', 'TANGGAL', 'Date', 'created_at'], 'created_at');
    $timeCol = dashPickColumn($conn, 'visit_kegiatan', ['jam', 'JAM', 'created_at'], 'created_at');

    $qNotifVisitKegiatan = mysqli_query($conn, "
        SELECT `{$nameCol}` AS nama_notif,
               `{$dateCol}` AS tanggal_notif,
               `{$timeCol}` AS jam_notif,
               created_at
        FROM visit_kegiatan
        WHERE DATE(created_at) = CURDATE()
           OR LEFT(TRIM(`{$dateCol}`), 10) = CURDATE()
        ORDER BY created_at DESC, `{$dateCol}` DESC, `{$timeCol}` DESC
        LIMIT 10
    ");
    if ($qNotifVisitKegiatan) {
        while ($row = mysqli_fetch_assoc($qNotifVisitKegiatan)) {
            $notifAll[] = [
                'text' => dashBuildNotifText($row['nama_notif'] ?? 'User', 'kegiatan', 'Kegiatan', $row['tanggal_notif'] ?? ($row['created_at'] ?? ''), $row['jam_notif'] ?? ($row['created_at'] ?? '')),
                'url' => 'add_kegiatan.php',
                'type' => 'kegiatan',
                'title' => 'Kegiatan Baru',
                'time_key' => trim(($row['created_at'] ?? '') . ' ' . ($row['tanggal_notif'] ?? '') . ' ' . ($row['jam_notif'] ?? '')),
            ];
        }
    }
}

if (tableExists($conn, 'aktivitas_daftar')) {
    $nameCol = dashPickColumn($conn, 'aktivitas_daftar', ['NAMA', 'nama', 'username'], 'NAMA');
    $dateCol = dashPickColumn($conn, 'aktivitas_daftar', ['TANGGAL', 'tanggal', 'created_at'], 'TANGGAL');
    $timeCol = dashPickColumn($conn, 'aktivitas_daftar', ['JAM', 'jam', 'created_at'], 'JAM');
    $outletCol = dashPickColumn($conn, 'aktivitas_daftar', ['OUTLET', 'outlet', 'LOKASI', 'lokasi', 'store'], '');
    $outletSelect = $outletCol !== '' ? "`{$outletCol}` AS target_outlet" : "'' AS target_outlet";

    $qNotif1 = mysqli_query($conn, "
        SELECT `{$nameCol}` AS nama_notif,
               `{$dateCol}` AS tanggal_notif,
               `{$timeCol}` AS jam_notif,
               {$outletSelect}
        FROM aktivitas_daftar
        WHERE DATE(`{$dateCol}`) = CURDATE()
        ORDER BY `{$dateCol}` DESC, `{$timeCol}` DESC
        LIMIT 5
    ");
    if ($qNotif1) {
        while ($row = mysqli_fetch_assoc($qNotif1)) {
            $notifAll[] = [
                'text' => dashBuildNotifText($row['nama_notif'] ?? 'User', 'visit', $row['target_outlet'] ?? 'Outlet', $row['tanggal_notif'] ?? '', $row['jam_notif'] ?? ''),
                'url' => 'data_aktivitas.php',
                'type' => 'aktivitas',
                'time_key' => trim(($row['tanggal_notif'] ?? '') . ' ' . ($row['jam_notif'] ?? '')),
            ];
        }
    }
}

if (tableExists($conn, 'add_kegiatan')) {
    $nameCol = dashPickColumn($conn, 'add_kegiatan', ['NAMA', 'nama', 'username'], 'NAMA');
    $dateCol = dashPickColumn($conn, 'add_kegiatan', ['Date', 'TANGGAL', 'tanggal', 'created_at'], 'Date');
    $timeCol = dashPickColumn($conn, 'add_kegiatan', ['JAM', 'jam', 'created_at'], 'JAM');

    $qNotif2 = mysqli_query($conn, "
        SELECT `{$nameCol}` AS nama_notif,
               `{$dateCol}` AS tanggal_notif,
               `{$timeCol}` AS jam_notif
        FROM add_kegiatan
        WHERE DATE(`{$dateCol}`) = CURDATE()
        ORDER BY `{$dateCol}` DESC, `{$timeCol}` DESC
        LIMIT 5
    ");
    if ($qNotif2) {
        while ($row = mysqli_fetch_assoc($qNotif2)) {
            $notifAll[] = [
                'text' => dashBuildNotifText($row['nama_notif'] ?? 'User', 'kegiatan', 'Kegiatan', $row['tanggal_notif'] ?? '', $row['jam_notif'] ?? ''),
                'url' => 'add_kegiatan.php',
                'type' => 'kegiatan',
                'time_key' => trim(($row['tanggal_notif'] ?? '') . ' ' . ($row['jam_notif'] ?? '')),
            ];
        }
    }
}

usort($notifAll, function ($a, $b) {
    return strcmp($b['time_key'], $a['time_key']);
});
$notifAll = array_slice($notifAll, 0, 12);

$popup = null;
$qPopup = mysqli_query($conn, "
    SELECT id, judul, isi, link 
    FROM dashboard_popup
    WHERE status = 'ON'
    ORDER BY id DESC
    LIMIT 1
");
if ($qPopup && mysqli_num_rows($qPopup) > 0) {
    $popup = mysqli_fetch_assoc($qPopup);
}

$allowedSalesUsers = ['wahid', 'wira', 'aziz', 'ujang', 'yan', 'alfia', 'aca', 'azik', 'prengkuh', 'admin1','whina','ratna'];
$stockUsers = ['wira', 'prengkuh', 'naufal', 'ahmad' ,'admin1', 'ujang'];
$itUsers = ['wira', 'yan', 'aziz', 'admin1', 'admin2', 'admin'];

$currentPage = basename($_SERVER['PHP_SELF']);


/* ============================================================
   RBAC_HIDE_ONLY_V5_2026_05_26
   TRUE  = menu/submenu tampil di sidebar
   FALSE = menu/submenu disembunyikan dari sidebar
   Catatan: ini HANYA hide/unhide sidebar, bukan blok URL langsung.
   Tabel baru: rbac_user_config_v5 + rbac_menu_flags_v5
============================================================ */
if (!defined('RBAC_HIDE_ONLY_V5_2026_05_26')) {
    define('RBAC_HIDE_ONLY_V5_2026_05_26', true);
}

if (!function_exists('rbacV5e')) {
    function rbacV5e($value)
    {
        if (function_exists('e')) return e($value);
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rbacV5NormUser')) {
    function rbacV5NormUser($username)
    {
        return strtolower(trim((string)$username));
    }
}

if (!function_exists('rbacV5EnsureTables')) {
    function rbacV5EnsureTables($conn)
    {
        @mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS rbac_user_config_v5 (
                username VARCHAR(100) NOT NULL PRIMARY KEY,
                saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        @mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS rbac_menu_flags_v5 (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100) NOT NULL,
                menu_key VARCHAR(120) NOT NULL,
                can_view TINYINT(1) NOT NULL DEFAULT 1,
                saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_menu (username, menu_key),
                INDEX idx_username (username),
                INDEX idx_menu_key (menu_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        @mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS rbac_menu_open_flags_v5 (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100) NOT NULL,
                menu_key VARCHAR(120) NOT NULL,
                can_open TINYINT(1) NOT NULL DEFAULT 1,
                saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_menu_open (username, menu_key),
                INDEX idx_username (username),
                INDEX idx_menu_key (menu_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}


function rbacV5MenuDefinitions()
{
    /*
       SIDEBAR ENTERPRISE FINAL - BILINGUAL
       Default bahasa: Indonesia.
       Switch bahasa disimpan di localStorage: dashboard_sidebar_language.
       Key lama tetap dipertahankan supaya RBAC existing tidak rusak.
    */
    return [
        [
            'key' => 'dashboard',
            'title' => 'Dashboard',
            'title_id' => 'Dashboard',
            'title_en' => 'Dashboard',
            'icon' => 'fa-solid fa-gauge-high',
            'url' => 'dashboard.php',
            'sort' => 1,
            'children' => [],
        ],
        [
            'key' => 'dashboard_it',
            'title' => 'Dashboard IT',
            'title_id' => 'Dashboard IT',
            'title_en' => 'IT Dashboard',
            'icon' => 'fa-solid fa-screwdriver-wrench',
            'url' => '#',
            'sort' => 2,
            'children' => [
                [
                    'key' => 'it_helpdesk_menu',
                    'title' => 'IT Helpdesk',
                    'title_id' => 'IT Helpdesk',
                    'title_en' => 'IT Helpdesk',
                    'icon' => 'fa-solid fa-headset',
                    'url' => 'it-helpdesk.php',
                    'sort' => 10,
                ],

                [
                    'key' => 'it.php',
                    'title' => 'Realisasi Tim IT',
                    'title_id' => 'Realisasi Tim IT',
                    'title_en' => 'IT team implementation',
                    'icon' => 'fa-solid fa-box-archive',
                    'url' => 'it.php',
                    'sort' => 10,
                ],

                [
                    'key' => 'delete_sales',
                    'title' => 'Delete Sales',
                    'title_id' => 'Delete Sales',
                    'title_en' => 'Delete Sales',
                    'icon' => 'fa-solid fa-trash-can',
                    'url' => 'nonecsys.php',
                    'sort' => 20,
                ],
                [
                    'key' => 'backup_inventory',
                    'title' => 'Backup Inventory',
                    'title_id' => 'Backup Inventory',
                    'title_en' => 'Inventory Backup',
                    'icon' => 'fa-solid fa-box-archive',
                    'url' => 'inventorycopy.php',
                    'sort' => 25,
                ],
                [
                    'key' => 'laporan_delete_sales',
                    'title' => 'Laporan Delete Sales',
                    'title_id' => 'Laporan Delete Sales',
                    'title_en' => 'Deleted Sales Report',
                    'icon' => 'fa-solid fa-file-circle-check',
                    'url' => 'ceklis-del.php',
                    'sort' => 30,
                ],
            ],
        ],
        [
            'key' => 'master_data',
            'title' => 'Master Data',
            'title_id' => 'Master Data',
            'title_en' => 'Master Data',
            'icon' => 'fa-solid fa-database',
            'url' => '#',
            'sort' => 10,
            'children' => [
                ['key' => 'profil_perusahaan', 'title' => 'Profil Perusahaan', 'title_id' => 'Profil Perusahaan', 'title_en' => 'Company Profile', 'icon' => 'fa-solid fa-building', 'url' => 'profilperusahaan.php', 'sort' => 10],
                ['key' => 'outlet_maskapai', 'title' => 'Maskapai Penerbangan', 'title_id' => 'Maskapai Penerbangan', 'title_en' => 'Airlines', 'icon' => 'fa-solid fa-plane', 'url' => 'outlet_maskapai.php', 'sort' => 20],
                ['key' => 'data_karyawan', 'title' => 'Database Karyawan', 'title_id' => 'Database Karyawan', 'title_en' => 'Employee Database', 'icon' => 'fa-solid fa-users', 'url' => 'data_karyawan.php', 'sort' => 30],
                ['key' => 'inventaris', 'title' => 'Daftar Inventaris', 'title_id' => 'Daftar Inventaris', 'title_en' => 'Inventory List', 'icon' => 'fa-solid fa-warehouse', 'url' => 'inventaris.php', 'sort' => 10],
                ['key' => 'jam_operasional', 'title' => 'Jam Operasional', 'title_id' => 'Jam Operasional', 'title_en' => 'Operating Hours', 'icon' => 'fa-solid fa-clock', 'url' => 'jam_operasional.php', 'sort' => 40],
                ['key' => 'persentase_outlet', 'title' => 'Persentase Outlet', 'title_id' => 'Persentase Outlet', 'title_en' => 'Outlet Percentage', 'icon' => 'fa-solid fa-percent', 'url' => 'persen.php', 'sort' => 50],
            ],
        ],
        [
            'key' => 'operations',
            'title' => 'Operasional',
            'title_id' => 'Operasional',
            'title_en' => 'Operations',
            'icon' => 'fa-solid fa-briefcase',
            'url' => '#',
            'sort' => 20,
            'children' => [
                ['key' => 'alur_koordinasi', 'title' => 'Alur Koordinasi', 'title_id' => 'Alur Koordinasi', 'title_en' => 'Coordination Flow', 'icon' => 'fa-solid fa-diagram-project', 'url' => 'alur_koordinasi.php', 'sort' => 20],
                ['key' => 'data_aktivitas', 'title' => 'Monitoring Kunjungan', 'title_id' => 'Monitoring Kunjungan', 'title_en' => 'Visit Monitoring', 'icon' => 'fa-solid fa-location-dot', 'url' => 'data_aktivitas.php', 'sort' => 10],
                ['key' => 'add_kegiatan', 'title' => 'Monitoring Kegiatan', 'title_id' => 'Monitoring Kegiatan', 'title_en' => 'Daily Operations', 'icon' => 'fa-solid fa-calendar-check', 'url' => 'add_kegiatan.php', 'sort' => 20],
                ['key' => 'workorder', 'title' => 'Work Order', 'title_id' => 'Work Order', 'title_en' => 'Work Order', 'icon' => 'fa-solid fa-list-check', 'url' => 'workorder.php', 'sort' => 30],
                ['key' => 'karyawan_lineup', 'title' => 'Karyawan', 'title_id' => 'Karyawan', 'title_en' => 'Employee Assignment', 'icon' => 'fa-solid fa-user-check', 'url' => 'karyawan.php', 'sort' => 40],
                ['key' => 'jadwal_spv', 'title' => 'Jadwal Supervisor', 'title_id' => 'Jadwal Supervisor', 'title_en' => 'Supervisor Schedule', 'icon' => 'fa-solid fa-calendar-days', 'url' => 'jadwal-spv.php', 'sort' => 50],
                ['key' => 'jadwal_karyawan', 'title' => 'Jadwal Karyawan', 'title_id' => 'Jadwal Karyawan', 'title_en' => 'Employee Schedule', 'icon' => 'fa-solid fa-calendar-check', 'url' => 'jadwal_karyawan.php', 'sort' => 60],
                ['key' => 'pasbandara', 'title' => 'PAS Bandara', 'title_id' => 'PAS Bandara', 'title_en' => 'Airport Pass', 'icon' => 'fa-regular fa-id-card', 'url' => 'pasbandara.php', 'sort' => 70],
            ],
        ],
        [
            'key' => 'sales_report',
            'title' => 'Penjualan & Analitik',
            'title_id' => 'Penjualan & Analitik',
            'title_en' => 'Sales & Analytics',
            'icon' => 'fa-solid fa-chart-line',
            'url' => '#',
            'sort' => 30,
            'children' => [
                ['key' => 'summary_sales_toko', 'title' => 'Omset Harian', 'title_id' => 'Omset Harian', 'title_en' => 'Daily Sales Report', 'icon' => 'fa-solid fa-file-invoice-dollar', 'url' => 'summary-sales-toko.php', 'sort' => 10],
                ['key' => 'sales_shift', 'title' => 'Penjualan per Shift', 'title_id' => 'Penjualan per Shift', 'title_en' => 'Shift Sales', 'icon' => 'fa-solid fa-business-time', 'url' => 'sales-shift.php', 'sort' => 20],
                ['key' => 'tracking_sales', 'title' => 'Penjualan per Jam', 'title_id' => 'Penjualan per Jam', 'title_en' => 'Hourly Sales', 'icon' => 'fa-solid fa-clock-rotate-left', 'url' => 'tracking_sales.php', 'sort' => 30],
                ['key' => 'retail_target', 'title' => 'Target Penjualan', 'title_id' => 'Target Penjualan', 'title_en' => 'Sales Target', 'icon' => 'fa-solid fa-bullseye', 'url' => 'retail_target.php', 'sort' => 40],
                ['key' => 'cash_opname', 'title' => 'Cash Opname', 'title_id' => 'Cash Opname', 'title_en' => 'Cash Opname', 'icon' => 'fa-solid fa-cash-register', 'url' => 'cash_opname.php', 'sort' => 50],
                ['key' => 'basketsize', 'title' => 'Basket Size', 'title_id' => 'Basket Size', 'title_en' => 'Basket Size', 'icon' => 'fa-solid fa-basket-shopping', 'url' => 'basketsize.php', 'sort' => 60],
                ['key' => 'dashboard_grafik', 'title' => 'Dashboard Analitik', 'title_id' => 'Dashboard Analitik', 'title_en' => 'Analytics Dashboard', 'icon' => 'fa-solid fa-chart-column', 'url' => 'Dashboard Grafik.php', 'sort' => 70],
                ['key' => 'datasales', 'title' => 'Sales by Sistem', 'title_id' => 'Sales by Sistem', 'title_en' => 'Sales by System', 'icon' => 'fa-solid fa-file-lines', 'url' => 'datasales.php', 'sort' => 80],
            ],
        ],
        [
            'key' => 'inventory',
            'title' => 'Inventori',
            'title_id' => 'Inventori',
            'title_en' => 'Inventory',
            'icon' => 'fa-solid fa-boxes-stacked',
            'url' => '#',
            'sort' => 40,
            'children' => [
                ['key' => 'menupaket', 'title' => 'Menu Paket', 'title_id' => 'Menu Paket', 'title_en' => 'Packet Menu', 'icon' => 'fa-solid fa-utensils', 'url' => 'menupaket.php', 'sort' => 60],
                ['key' => 'persediaan', 'title' => 'Persediaan Barang', 'title_id' => 'Persediaan Barang', 'title_en' => 'Inventory Stock', 'icon' => 'fa-solid fa-box', 'url' => 'persediaan.php', 'sort' => 20],
                ['key' => 'persediaanmulti', 'title' => 'Persediaan Barang (Search multi)', 'title_id' => 'Persediaan Barang (Search Multi)', 'title_en' => 'Multi Location Inventory', 'icon' => 'fa-solid fa-warehouse', 'url' => 'persediaanmulti.php', 'sort' => 30],
                ['key' => 'pemakaianbarang', 'title' => 'Pemakaian Barang', 'title_id' => 'Pemakaian Barang', 'title_en' => 'Stock Usage', 'icon' => 'fa-solid fa-arrow-trend-down', 'url' => 'pemakaianbarang.php', 'sort' => 40],
                ['key' => 'pembelian', 'title' => 'Pembelian Barang', 'title_id' => 'Pembelian Barang', 'title_en' => 'Purchase Orders', 'icon' => 'fa-solid fa-cart-shopping', 'url' => 'pembelian.php', 'sort' => 50],
                ['key' => 'analisis', 'title' => 'Stock Opname', 'title_id' => 'Stock Opname', 'title_en' => 'Stock Opname', 'icon' => 'fa-solid fa-clipboard-check', 'url' => 'analisis.php', 'sort' => 60],
                ['key' => 'laporan_admin', 'title' => 'LAPORAN ADMIN', 'title_id' => 'LAPORAN ADMIN', 'title_en' => 'Admin Report', 'icon' => 'fa-solid fa-file-shield', 'url' => 'CekDataTenant.php', 'sort' => 70],
            ],
        ],
        [
            'key' => 'performance',
            'title' => 'Performa',
            'title_id' => 'Performa',
            'title_en' => 'Performance',
            'icon' => 'fa-solid fa-chart-simple',
            'url' => '#',
            'sort' => 50,
            'children' => [
                ['key' => 'data_kpi', 'title' => 'Hasil KPI', 'title_id' => 'Hasil KPI', 'title_en' => 'Employee KPI', 'icon' => 'fa-solid fa-user-tie', 'url' => 'data_kpi.php', 'sort' => 10],
                ['key' => 'form_kpi', 'title' => 'Penilaian KPI', 'title_id' => 'Penilaian KPI', 'title_en' => 'KPI Assessment', 'icon' => 'fa-solid fa-clipboard-check', 'url' => 'form-kpi.php', 'sort' => 20],
            ],
        ],
        [
            'key' => 'documents',
            'title' => 'Dokumen & SOP',
            'title_id' => 'Dokumen & SOP',
            'title_en' => 'Documents & SOP',
            'icon' => 'fa-solid fa-folder-open',
            'url' => '#',
            'sort' => 60,
            'children' => [
                ['key' => 'sop_minimarket', 'title' => 'Dokumen SOP', 'title_id' => 'Dokumen SOP', 'title_en' => 'SOP Documents', 'icon' => 'fa-solid fa-file-pdf', 'url' => 'uploads/sop/SOP-Minimarket.pdf', 'target' => '_blank', 'sort' => 10],
                ['key' => 'berkas_minimarket', 'title' => 'Manajemen Dokumen', 'title_id' => 'Manajemen Dokumen', 'title_en' => 'Document Management', 'icon' => 'fa-solid fa-folder-tree', 'url' => 'berkas-minimarket.php', 'sort' => 30],
            ],
        ],
        [
            'key' => 'system',
            'title' => 'Sistem & Pengaturan',
            'title_id' => 'Sistem & Pengaturan',
            'title_en' => 'System & Settings',
            'icon' => 'fa-solid fa-gear',
            'url' => '#',
            'sort' => 70,
            'children' => [
                ['key' => 'setting-akun', 'title' => 'Pengaturan Akun', 'title_id' => 'Pengaturan Akun', 'title_en' => 'Account Settings', 'icon' => 'fa-solid fa-user-gear', 'url' => 'setting-akun.php', 'sort' => 20],
                ['key' => 'theme_dashboard', 'title' => 'Tema Dashboard', 'title_id' => 'Tema Dashboard', 'title_en' => 'Dashboard Theme', 'icon' => 'fa-solid fa-circle-half-stroke', 'url' => '#theme-toggle', 'sort' => 25],
                ['key' => 'sidebar_language', 'title' => 'Pengaturan Bahasa', 'title_id' => 'Pengaturan Bahasa', 'title_en' => 'Settings Language', 'icon' => 'fa-solid fa-language', 'url' => '#language-toggle', 'sort' => 26],
                ['key' => 'settings', 'title' => 'Manajemen User', 'title_id' => 'Manajemen User', 'title_en' => 'User Management', 'icon' => 'fa-solid fa-users-gear', 'url' => 'settings.php', 'sort' => 30],
                ['key' => 'tambah_karyawan', 'title' => 'Perbarui Data Karyawan', 'title_id' => 'Perbarui Data Karyawan', 'title_en' => 'Update Employee Data', 'icon' => 'fa-solid fa-user-pen', 'url' => 'tambah_karyawan.php', 'sort' => 40],
                ['key' => 'log_user', 'title' => 'Log Aktivitas User', 'title_id' => 'Log Aktivitas User', 'title_en' => 'User Activity Log', 'icon' => 'fa-solid fa-shield-halved', 'url' => 'log_user.php', 'sort' => 50],
                ['key' => 'popup_admin', 'title' => 'Kelola Pop Up', 'title_id' => 'Kelola Pop Up', 'title_en' => 'Manage Pop Up', 'icon' => 'fa-solid fa-bell', 'url' => 'popup_admin.php', 'sort' => 60],
                ['key' => 'hero_dashboard_admin', 'title' => 'Kelola Hero Dashboard', 'title_id' => 'Kelola Hero Dashboard', 'title_en' => 'Manage Hero Dashboard', 'icon' => 'fa-solid fa-pen-to-square', 'url' => 'hero_dashboard_admin.php', 'sort' => 70],
                ['key' => 'pusat_modul_spv_leader', 'title' => 'Setting Kehadiran', 'title_id' => 'Setting Kehadiran', 'title_en' => 'Attendance Settings', 'icon' => 'fa-solid fa-user-clock', 'url' => 'pusat_modul_spv_leader.php', 'sort' => 80],
                ['key' => 'work_order_realisasi_admin', 'title' => 'Upload Realisasi WO', 'title_id' => 'Upload Realisasi WO', 'title_en' => 'Upload WO Realization', 'icon' => 'fa-solid fa-images', 'url' => 'work_order_realisasi_admin.php', 'sort' => 90],
                ['key' => 'dashboard_it', 'title' => 'Dashboard IT', 'title_id' => 'Dashboard IT', 'title_en' => 'IT Dashboard', 'icon' => 'fa-solid fa-desktop', 'url' => 'dashboard_it.php', 'sort' => 100],
                ['key' => 'input_it', 'title' => 'Laporan IT', 'title_id' => 'Laporan IT', 'title_en' => 'IT Report', 'icon' => 'fa-solid fa-file-circle-plus', 'url' => 'input_it.php', 'sort' => 110],
                ['key' => 'rbac', 'title' => 'Role Based Access Control', 'title_id' => 'Role Based Access Control', 'title_en' => 'Role Based Access Control', 'icon' => 'fa-solid fa-key', 'url' => 'rbac.php', 'sort' => 120],
            ],
        ],
        [
            'key' => 'Keluar',
            'title' => 'Keluar',
            'title_id' => 'Keluar',
            'title_en' => 'Logout',
            'icon' => 'fa-solid fa-right-from-bracket',
            'url' => 'logout.php',
            'sort' => 999,
            'children' => [],
        ],
    ];
}


if (!function_exists('rbacV5FlatMenuKeys')) {
    function rbacV5FlatMenuKeys(array $menus)
    {
        $keys = [];
        foreach ($menus as $menu) {
            if (!empty($menu['key'])) $keys[] = (string)$menu['key'];
            foreach (($menu['children'] ?? []) as $child) {
                if (!empty($child['key'])) $keys[] = (string)$child['key'];
            }
        }
        return array_values(array_unique($keys));
    }
}

if (!function_exists('rbacV5PageFromUrl')) {
    function rbacV5PageFromUrl($url)
    {
        $url = trim((string)$url);
        if ($url === '' || $url === '#') return '';
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) $path = $url;
        return basename(str_replace("\t", '', $path));
    }
}

if (!function_exists('rbacV5UserConfigured')) {
    function rbacV5UserConfigured($conn, $username)
    {
        $username = rbacV5NormUser($username);
        if ($username === '') return false;
        $stmt = mysqli_prepare($conn, "SELECT username FROM rbac_user_config_v5 WHERE username = ? LIMIT 1");
        if (!$stmt) return false;
        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $found = $res && mysqli_num_rows($res) > 0;
        mysqli_stmt_close($stmt);
        return $found;
    }
}

if (!function_exists('rbacV5AccessMap')) {
    function rbacV5AccessMap($conn, $username)
    {
        $username = rbacV5NormUser($username);
        $map = [];
        if ($username === '') return $map;
        $stmt = mysqli_prepare($conn, "SELECT menu_key, can_view FROM rbac_menu_flags_v5 WHERE username = ?");
        if (!$stmt) return $map;
        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && $row = mysqli_fetch_assoc($res)) {
            $map[(string)$row['menu_key']] = (int)$row['can_view'];
        }
        mysqli_stmt_close($stmt);
        return $map;
    }
}

if (!function_exists('rbacV5OpenAccessMap')) {
    function rbacV5OpenAccessMap($conn, $username)
    {
        $username = rbacV5NormUser($username);
        $map = [];
        if ($username === '') return $map;

        rbacV5EnsureTables($conn);

        $stmt = mysqli_prepare($conn, "SELECT menu_key, can_open FROM rbac_menu_open_flags_v5 WHERE username = ?");
        if (!$stmt) return $map;

        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        while ($res && $row = mysqli_fetch_assoc($res)) {
            $map[(string)$row['menu_key']] = (int)$row['can_open'];
        }

        mysqli_stmt_close($stmt);
        return $map;
    }
}

if (!function_exists('rbacV5CanOpenKey')) {
    function rbacV5CanOpenKey($key, array $openAccessMap)
    {
        $key = (string)$key;
        if ($key === '') return false;

        // Default aman: kalau belum pernah diset di RBAC, menu boleh dibuka.
        if (!array_key_exists($key, $openAccessMap)) return true;

        return (int)$openAccessMap[$key] === 1;
    }
}

if (!function_exists('rbacV5CanShowKey')) {
    function rbacV5CanShowKey($key, $userConfigured, array $accessMap)
    {
        // Hanya kontrol tampilan lokal yang selalu tersedia.
        // Persentase Outlet wajib mengikuti TRUE/FALSE dari RBAC.
        $alwaysVisibleKeys = ['theme_dashboard', 'sidebar_language'];
        if (in_array((string)$key, $alwaysVisibleKeys, true)) return true;
        if (!$userConfigured) return true;
        return isset($accessMap[$key]) && (int)$accessMap[$key] === 1;
    }
}

if (!function_exists('rbacV5BuildSidebarTree')) {
    function rbacV5BuildSidebarTree($conn, $username, $currentPage)
    {
        rbacV5EnsureTables($conn);
        $menus = rbacV5MenuDefinitions();
        $userConfigured = rbacV5UserConfigured($conn, $username);
        $accessMap = $userConfigured ? rbacV5AccessMap($conn, $username) : [];
        $openAccessMap = rbacV5OpenAccessMap($conn, $username);
        $tree = [];

        foreach ($menus as $menu) {
            $menuKey = (string)($menu['key'] ?? '');
            if ($menuKey === '') continue;
            /* =====================================================
               DASHBOARD IT - AKSES KHUSUS USER
               Parent menu Dashboard IT beserta seluruh submenu hanya tampil
               dan dapat dibuka oleh username berikut:
               - wira
               - yan
               - aziz
               - admin1
               - admin2
               - admin

               Dibuat forced-visible untuk user yang diizinkan supaya tidak
               tertutup oleh konfigurasi RBAC lama.
            ===================================================== */
            $isDashboardItForcedMenu = false;
            if ($menuKey === 'dashboard_it') {
                $dashboardItAllowedUsers = ['wira', 'yan', 'aziz', 'admin1', 'admin2', 'admin'];
                $currentSidebarUser = rbacV5NormUser($username);

                $isDashboardItForcedMenu = in_array($currentSidebarUser, $dashboardItAllowedUsers, true);

                if (!$isDashboardItForcedMenu) continue;
            }

            // Layer 1: visibility/hide-unhide tetap aktif seperti sebelumnya.
            // Khusus Dashboard IT, user yang diizinkan selalu melihat menu.
            if (!$isDashboardItForcedMenu && !rbacV5CanShowKey($menuKey, $userConfigured, $accessMap)) continue;

            // Layer 2: can_open untuk akses buka menu.
            // Khusus Dashboard IT, user yang diizinkan langsung dapat membuka submenu.
            $menuCanOpen = $isDashboardItForcedMenu ? true : rbacV5CanOpenKey($menuKey, $openAccessMap);
            $menu['can_open'] = $menuCanOpen;

            $visibleChildren = [];
            foreach (($menu['children'] ?? []) as $child) {
                $childKey = (string)($child['key'] ?? '');
                if ($childKey === '') continue;

                if ($isDashboardItForcedMenu) {
                    $child['can_open'] = true;
                    $visibleChildren[] = $child;
                    continue;
                }

                if (rbacV5CanShowKey($childKey, $userConfigured, $accessMap)) {
                    $child['can_open'] = $menuCanOpen && rbacV5CanOpenKey($childKey, $openAccessMap);
                    $visibleChildren[] = $child;
                }
            }

            $menuPage = rbacV5PageFromUrl($menu['url'] ?? '#');
            if ($menuPage === '' && empty($visibleChildren)) {
                continue;
            }

            $pages = [];
            if ($menuPage !== '') $pages[] = $menuPage;
            foreach ($visibleChildren as $child) {
                $childPage = rbacV5PageFromUrl($child['url'] ?? '#');
                if ($childPage !== '') $pages[] = $childPage;
            }

            $menu['children'] = $visibleChildren;
            $menu['pages'] = array_values(array_unique($pages));
            $menu['active'] = in_array($currentPage, $menu['pages'], true);
            $tree[] = $menu;
        }

        usort($tree, function ($a, $b) {
            return ((int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0));
        });

        return $tree;
    }
}

if (!function_exists('rbacV5RenderSidebar')) {
    function rbacV5RenderSidebar(array $tree, $currentPage)
    {
        ?>
        <aside class="sidebar" id="sidebar" data-rbac-version="RBAC_HIDE_ONLY_V5_2026_05_26">
            <div class="nav-section">
                <?php foreach ($tree as $menu): ?>
                    <?php
                    $children = $menu['children'] ?? [];
                    $hasChildren = !empty($children);
                    $active = !empty($menu['active']);
                    $titleId = (string)($menu['title_id'] ?? ($menu['title'] ?? 'Menu'));
                    $titleEn = (string)($menu['title_en'] ?? ($menu['title'] ?? $titleId));
                    $icon = (string)($menu['icon'] ?? 'fa fa-circle-dot');
                    $url = (string)($menu['url'] ?? '#');
                    $menuCanOpen = !array_key_exists('can_open', $menu) || (bool)$menu['can_open'];
                    $menuLockedClass = ''; // RBAC read-only: parent group tetap tampil normal
                    $isLogoutMenu = strcasecmp((string)($menu['key'] ?? ''), 'Keluar') === 0;
                    ?>
                    <?php if ($isLogoutMenu): ?>
                        <form method="post" action="logout.php" style="margin:0" data-logout-form>
                            <input type="hidden" name="action" value="logout">
                            <button class="menu-toggle" type="submit" data-rbac-key="Keluar">
                                <span class="left"><i class="<?= rbacV5e($icon) ?>"></i> <span data-sidebar-title data-title-id="<?= rbacV5e($titleId) ?>" data-title-en="<?= rbacV5e($titleEn) ?>"><?= rbacV5e($titleId) ?></span></span>
                                <i class="fa fa-chevron-right chevron"></i>
                            </button>
                        </form>
                    <?php elseif ($hasChildren): ?>
                        <button class="menu-toggle <?= $active ? 'active' : '' ?><?= $menuLockedClass ?>" type="button" data-rbac-key="<?= rbacV5e($menu['key'] ?? '') ?>" data-menu-title="<?= rbacV5e($titleId) ?>" data-title-id="<?= rbacV5e($titleId) ?>" data-title-en="<?= rbacV5e($titleEn) ?>">
                            <span class="left"><i class="<?= rbacV5e($icon) ?>"></i> <span data-sidebar-title data-title-id="<?= rbacV5e($titleId) ?>" data-title-en="<?= rbacV5e($titleEn) ?>"><?= rbacV5e($titleId) ?></span></span>
                            <i class="fa fa-chevron-down chevron"></i>
                        </button>
                        <ul class="submenu <?= $active ? 'show' : '' ?>">
                            <?php foreach ($children as $child): ?>
                                <?php
                                $childUrl = (string)($child['url'] ?? '#');
                                $childPage = rbacV5PageFromUrl($childUrl);
                                $childActive = ($childPage !== '' && $childPage === $currentPage);
                                $target = trim((string)($child['target'] ?? ''));
                                $childTitleId = (string)($child['title_id'] ?? ($child['title'] ?? 'Sub Menu'));
                                $childTitleEn = (string)($child['title_en'] ?? ($child['title'] ?? $childTitleId));
                                $childCanOpen = !array_key_exists('can_open', $child) || (bool)$child['can_open'];
                                $childHref = $childCanOpen ? $childUrl : '#';
                                $childClass = trim(($childActive && $childCanOpen ? 'active-link ' : '') . (!$childCanOpen ? 'locked-menu' : '')); // locked-menu hanya untuk trigger popup, tampilan tetap normal
                                ?>
                                <li data-rbac-key="<?= rbacV5e($child['key'] ?? '') ?>">
                                    <a class="<?= rbacV5e($childClass) ?>" href="<?= rbacV5e($childHref) ?>" <?= ($target !== '' && $childCanOpen) ? 'target="' . rbacV5e($target) . '"' : '' ?> data-menu-title="<?= rbacV5e($childTitleId) ?>" data-title-id="<?= rbacV5e($childTitleId) ?>" data-title-en="<?= rbacV5e($childTitleEn) ?>">
                                        <i class="<?= rbacV5e($child['icon'] ?? 'fa fa-circle-dot') ?>"></i> <span data-sidebar-title data-title-id="<?= rbacV5e($childTitleId) ?>" data-title-en="<?= rbacV5e($childTitleEn) ?>"><?= rbacV5e($childTitleId) ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <?php if ($menuCanOpen): ?>
                            <button class="menu-toggle <?= $active ? 'active' : '' ?>" type="button" onclick="window.location.href='<?= rbacV5e($url) ?>'" data-rbac-key="<?= rbacV5e($menu['key'] ?? '') ?>">
                                <span class="left"><i class="<?= rbacV5e($icon) ?>"></i> <span data-sidebar-title data-title-id="<?= rbacV5e($titleId) ?>" data-title-en="<?= rbacV5e($titleEn) ?>"><?= rbacV5e($titleId) ?></span></span>
                                <i class="fa fa-chevron-right chevron"></i>
                            </button>
                        <?php else: ?>
                            <button class="menu-toggle locked-menu <?= $active ? 'active' : '' ?>" type="button" data-rbac-key="<?= rbacV5e($menu['key'] ?? '') ?>" data-menu-title="<?= rbacV5e($titleId) ?>" data-title-id="<?= rbacV5e($titleId) ?>" data-title-en="<?= rbacV5e($titleEn) ?>">
                                <span class="left"><i class="<?= rbacV5e($icon) ?>"></i> <span data-sidebar-title data-title-id="<?= rbacV5e($titleId) ?>" data-title-en="<?= rbacV5e($titleEn) ?>"><?= rbacV5e($titleId) ?></span></span>
                                <i class="fa fa-chevron-right chevron"></i>
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </aside>
        <?php
    }
}

rbacV5EnsureTables($conn);
$rbacV5SidebarTree = rbacV5BuildSidebarTree($conn, $username, $currentPage);


/* =========================
   AKSES BUTTON HERO ABSENSI
========================= */
$attendanceMonitorUsers = ['wira', 'prengkuh', 'wahid', 'yan', 'admin1', 'ujang', 'mustaqim', 'aziz', 'haris'];
$attendanceReportBlockedUsers = ['prengkuh', 'wahid', 'ujang', 'aziz', 'admin1', 'naufal', 'ahmad', 'alfia', 'azik', 'putri'];

$canSeeMonitoringKehadiran = in_array($username, $attendanceMonitorUsers, true);
$canSeeLaporKehadiran = ($username === 'wira') || !in_array($username, $attendanceReportBlockedUsers, true);

function isAttendanceReportButton($text, $link)
{
    $haystack = strtolower(trim((string)$text . ' ' . (string)$link));
    return strpos($haystack, 'lapor kehadiran') !== false
        || strpos($haystack, 'absensi_leader') !== false
        || strpos($haystack, 'absensi_selfie') !== false
        || strpos($haystack, 'visit') !== false;
}

function isAttendanceMonitoringButton($text, $link)
{
    $haystack = strtolower(trim((string)$text . ' ' . (string)$link));
    return strpos($haystack, 'monitoring kehadiran') !== false
        || strpos($haystack, 'absensi_monitor') !== false
        || strpos($haystack, 'monitoring_admin') !== false;
}

function canShowHeroButton($text, $link, $canSeeLaporKehadiran, $canSeeMonitoringKehadiran)
{
    if (isAttendanceMonitoringButton($text, $link)) {
        return $canSeeMonitoringKehadiran;
    }

    if (isAttendanceReportButton($text, $link)) {
        return $canSeeLaporKehadiran;
    }

    return true;
}

function isMenuActive(array $pages, string $currentPage): bool
{
    return in_array($currentPage, $pages, true);
}
function isUsableNewsImage($url)
{
    $url = trim((string)$url);
    if ($url === '' || !preg_match('/^https?:\/\//i', $url)) {
        return false;
    }

    $lower = strtolower($url);
    $blockedPatterns = [
        'gstatic.com/images/branding',
        'gstatic.com/images/icons',
        'googleusercontent.com/google-news',
        'news.google.com',
        'google-news',
        'google_news',
        'news_512dp',
        'favicon',
        'logo',
        'placeholder',
        'spacer',
        'transparent',
    ];

    foreach ($blockedPatterns as $pattern) {
        if (strpos($lower, $pattern) !== false) {
            return false;
        }
    }

    return true;
}

function getAviationFallbackImage($seed = '')
{
    $images = [
        'https://images.unsplash.com/photo-1436491865332-7a61a109cc05?auto=format&fit=crop&w=900&q=80',
        'https://images.unsplash.com/photo-1540962351504-03099e0a754b?auto=format&fit=crop&w=900&q=80',
        'https://images.unsplash.com/photo-1529074963764-98f45c47344b?auto=format&fit=crop&w=900&q=80',
        'https://images.unsplash.com/photo-1569154941061-e231b4725ef1?auto=format&fit=crop&w=900&q=80',
        'https://images.unsplash.com/photo-1464037866556-6812c9d1c72e?auto=format&fit=crop&w=900&q=80',
        'https://images.unsplash.com/photo-1517976487492-5750f3195933?auto=format&fit=crop&w=900&q=80',
        'https://images.unsplash.com/photo-1570710891163-6d3b5c47248b?auto=format&fit=crop&w=900&q=80',
        'https://images.unsplash.com/photo-1517479149777-5f3b1511d5ad?auto=format&fit=crop&w=900&q=80',
    ];

    $index = 0;
    if ($seed !== '') {
        $index = abs(crc32((string)$seed)) % count($images);
    }

    return $images[$index];
}

function fetchUrlContent($url, $timeout = 6, $maxBytes = 800000)
{
    $url = trim((string)$url);
    if ($url === '' || !preg_match('/^https?:\/\//i', $url)) {
        return '';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => min(5, (int)$timeout),
            CURLOPT_TIMEOUT => (int)$timeout,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; DashboardAviationNewsBot/1.0; +https://srtcorp.online)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_HTTPHEADER => [
                'Accept: application/rss+xml, application/xml, text/xml;q=0.9, */*;q=0.8',
                'Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
            ],
        ]);
        $body = (string)curl_exec($ch);
        curl_close($ch);

        if ($body !== '') {
            return $maxBytes > 0 ? substr($body, 0, $maxBytes) : $body;
        }
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => (int)$timeout,
            'header' => "User-Agent: Mozilla/5.0 DashboardAviationNewsBot\r\n"
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ]);

    return (string)@file_get_contents($url, false, $context, 0, $maxBytes);
}

function getNewsImageFromRssItem($item)
{
    $description = (string)($item->description ?? '');
    $image = '';
    $namespaces = $item->getNameSpaces(true);

    if (isset($namespaces['media'])) {
        $media = $item->children($namespaces['media']);

        if (!empty($media->thumbnail)) {
            $attrs = $media->thumbnail->attributes();
            if (!empty($attrs['url'])) {
                $image = trim((string)$attrs['url']);
            }
        }

        if ($image === '' && !empty($media->content)) {
            $attrs = $media->content->attributes();
            if (!empty($attrs['url'])) {
                $image = trim((string)$attrs['url']);
            }
        }
    }

    if ($image === '' && !empty($item->enclosure)) {
        $attrs = $item->enclosure->attributes();
        if (!empty($attrs['url'])) {
            $image = trim((string)$attrs['url']);
        }
    }

    if ($image === '' && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $description, $m)) {
        $image = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    if ($image !== '' && strpos($image, '//') === 0) {
        $image = 'https:' . $image;
    }

    return $image;
}

function getNewsImageSearchQuery($title, $description = '', $source = '')
{
    $haystack = strtolower((string)$title . ' ' . (string)$description . ' ' . (string)$source);

    $rules = [
        ['keys' => ['garuda', 'garuda indonesia'], 'query' => 'Garuda Indonesia pesawat'],
        ['keys' => ['lion air', 'lionair'], 'query' => 'Lion Air pesawat'],
        ['keys' => ['citilink'], 'query' => 'Citilink Indonesia pesawat'],
        ['keys' => ['batik air'], 'query' => 'Batik Air pesawat'],
        ['keys' => ['airasia', 'air asia'], 'query' => 'AirAsia Indonesia pesawat'],
        ['keys' => ['super air jet'], 'query' => 'Super Air Jet pesawat'],
        ['keys' => ['transnusa'], 'query' => 'TransNusa pesawat'],
        ['keys' => ['pelita air'], 'query' => 'Pelita Air pesawat'],
        ['keys' => ['soekarno', 'soetta', 'cgk', 'terminal 3'], 'query' => 'Bandara Soekarno Hatta Terminal 3'],
        ['keys' => ['bandara', 'airport'], 'query' => 'bandara indonesia terminal pesawat'],
        ['keys' => ['airnav'], 'query' => 'AirNav Indonesia penerbangan'],
        ['keys' => ['angkasapura', 'angkasa pura', 'injourney'], 'query' => 'InJourney Airports bandara indonesia'],
        ['keys' => ['esdm', 'bahan bakar', 'avtur', 'energi'], 'query' => 'Kementerian ESDM Indonesia menteri'],
        ['keys' => ['pertamina', 'patra niaga'], 'query' => 'Pertamina avtur aviation'],
        ['keys' => ['haji', 'umrah'], 'query' => 'penerbangan haji bandara soekarno hatta'],
        ['keys' => ['cuaca', 'hujan', 'angin'], 'query' => 'pesawat cuaca buruk bandara'],
        ['keys' => ['delay', 'batal', 'dibatalkan', 'gangguan'], 'query' => 'delay penerbangan bandara'],
    ];

    foreach ($rules as $rule) {
        foreach ($rule['keys'] as $key) {
            if (strpos($haystack, $key) !== false) {
                return $rule['query'];
            }
        }
    }

    $cleanTitle = preg_replace('/\s+-\s+.+$/', '', (string)$title);
    $cleanTitle = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $cleanTitle);
    $cleanTitle = trim(preg_replace('/\s+/', ' ', $cleanTitle));

    if (function_exists('mb_substr')) {
        $cleanTitle = mb_substr($cleanTitle, 0, 80);
    } else {
        $cleanTitle = substr($cleanTitle, 0, 80);
    }

    return $cleanTitle !== '' ? $cleanTitle : 'berita penerbangan indonesia';
}

function fetchGoogleImageByQuery($query, $cacheDir)
{
    // OPTIONAL Google Images resmi:
    // Isi GOOGLE_IMAGE_API_KEY dan GOOGLE_IMAGE_CX kalau sudah punya Google Programmable Search Engine.
    if (!defined('GOOGLE_IMAGE_API_KEY')) {
        define('GOOGLE_IMAGE_API_KEY', '');
    }
    if (!defined('GOOGLE_IMAGE_CX')) {
        define('GOOGLE_IMAGE_CX', '');
    }

    $apiKey = trim((string)GOOGLE_IMAGE_API_KEY);
    $cx = trim((string)GOOGLE_IMAGE_CX);

    if ($apiKey === '' || $cx === '') {
        return '';
    }

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    $cacheFile = rtrim($cacheDir, '/\\') . '/google_img_' . md5($query) . '.txt';
    $cacheTtl = 86400;

    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
        $cached = trim((string)@file_get_contents($cacheFile));
        return isUsableNewsImage($cached) ? $cached : '';
    }

    $apiUrl = 'https://www.googleapis.com/customsearch/v1?'
        . 'key=' . rawurlencode($apiKey)
        . '&cx=' . rawurlencode($cx)
        . '&searchType=image'
        . '&safe=active'
        . '&num=3'
        . '&imgSize=large'
        . '&q=' . rawurlencode($query);

    $json = fetchUrlContent($apiUrl, 6, 600000);
    $image = '';

    if ($json !== '') {
        $data = json_decode($json, true);
        if (!empty($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                $candidate = trim((string)($item['link'] ?? ''));
                if (isUsableNewsImage($candidate)) {
                    $image = $candidate;
                    break;
                }
            }
        }
    }

    if (is_dir($cacheDir) && is_writable($cacheDir)) {
        @file_put_contents($cacheFile, $image);
    }

    return $image;
}

function fetchWikimediaImageByQuery($query, $cacheDir)
{
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    $cacheFile = rtrim($cacheDir, '/\\') . '/wiki_img_' . md5($query) . '.txt';
    $cacheTtl = 86400;

    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
        $cached = trim((string)@file_get_contents($cacheFile));
        return isUsableNewsImage($cached) ? $cached : '';
    }

    $apiUrl = 'https://commons.wikimedia.org/w/api.php?'
        . 'action=query'
        . '&generator=search'
        . '&gsrnamespace=6'
        . '&gsrlimit=8'
        . '&gsrsearch=' . rawurlencode($query)
        . '&prop=imageinfo'
        . '&iiprop=url'
        . '&iiurlwidth=900'
        . '&format=json'
        . '&origin=*';

    $json = fetchUrlContent($apiUrl, 6, 700000);
    $image = '';

    if ($json !== '') {
        $data = json_decode($json, true);
        if (!empty($data['query']['pages']) && is_array($data['query']['pages'])) {
            foreach ($data['query']['pages'] as $page) {
                if (!empty($page['imageinfo'][0])) {
                    $candidate = trim((string)($page['imageinfo'][0]['thumburl'] ?? $page['imageinfo'][0]['url'] ?? ''));
                    if (isUsableNewsImage($candidate)) {
                        $image = $candidate;
                        break;
                    }
                }
            }
        }
    }

    if (is_dir($cacheDir) && is_writable($cacheDir)) {
        @file_put_contents($cacheFile, $image);
    }

    return $image;
}

function fetchOgImageFromUrl($url, $cacheDir)
{
    $url = trim((string)$url);
    if ($url === '' || !preg_match('/^https?:\/\//i', $url)) {
        return '';
    }

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    $cacheFile = rtrim($cacheDir, '/\\') . '/news_img_' . md5($url) . '.txt';
    $cacheTtl = 86400;

    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
        $cached = trim((string)@file_get_contents($cacheFile));
        return isUsableNewsImage($cached) ? $cached : '';
    }

    $html = fetchUrlContent($url, 6, 700000);
    $image = '';

    if ($html !== '') {
        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $image = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        } elseif (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m)) {
            $image = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        } elseif (preg_match('/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $image = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        } elseif (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image["\']/i', $html, $m)) {
            $image = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
    }

    if ($image !== '' && strpos($image, '//') === 0) {
        $image = 'https:' . $image;
    }

    if (!isUsableNewsImage($image)) {
        $image = '';
    }

    if (is_dir($cacheDir) && is_writable($cacheDir)) {
        @file_put_contents($cacheFile, $image);
    }

    return $image;
}

function getSmartNewsImage($item, $title, $description, $sourceName, $link, $cacheDir)
{
    $rssImage = getNewsImageFromRssItem($item);
    $query = getNewsImageSearchQuery($title, $description, $sourceName);

    $image = fetchGoogleImageByQuery($query, $cacheDir);
    if (isUsableNewsImage($image)) {
        return $image;
    }

    $image = fetchWikimediaImageByQuery($query, $cacheDir);
    if (isUsableNewsImage($image)) {
        return $image;
    }

    $image = fetchOgImageFromUrl($link, $cacheDir);
    if (isUsableNewsImage($image)) {
        return $image;
    }

    if (isUsableNewsImage($rssImage)) {
        return $rssImage;
    }

    return getAviationFallbackImage($query . '|' . $title);
}

function fetchLatestGoogleNews($limit = 8)
{
    $limit = max(1, min(12, (int)$limit));

    // Query dibuat sederhana supaya Google News RSS tidak mengembalikan body kosong pada beberapa VPS/IPv6.
    // Filter waktu tetap 7 hari, tetapi tanpa kombinasi OR panjang.
    $newsQuery = 'penerbangan indonesia when:7d';
    $feedUrl = 'https://news.google.com/rss/search?q=' . rawurlencode($newsQuery) . '&hl=id&gl=ID&ceid=ID:id';

    $cacheDir = __DIR__ . '/cache';
    $cacheFile = $cacheDir . '/google_news_aviation_hot_7days.xml';
    $cacheTtl = 600;

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    $xmlString = '';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
        $xmlString = (string)@file_get_contents($cacheFile);
    }

    if ($xmlString === '') {
        $xmlString = fetchUrlContent($feedUrl, 12, 1200000);

        // Jangan cache response kosong / non-RSS supaya dashboard bisa mencoba ulang pada refresh berikutnya.
        if ($xmlString !== '' && stripos($xmlString, '<rss') !== false && is_dir($cacheDir) && is_writable($cacheDir)) {
            @file_put_contents($cacheFile, $xmlString);
        }
    }

    if ($xmlString === '' || stripos($xmlString, '<rss') === false) {
        return [];
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
    if (!$xml || empty($xml->channel->item)) {
        return [];
    }

    $items = [];
    $minTs = strtotime('-7 days');

    foreach ($xml->channel->item as $item) {
        if (count($items) >= $limit) break;

        $title = trim((string)$item->title);
        $link = trim((string)$item->link);
        $description = (string)$item->description;
        $pubDateRaw = trim((string)$item->pubDate);
        $pubTs = $pubDateRaw !== '' ? strtotime($pubDateRaw) : false;

        if ($pubTs && $pubTs < $minTs) {
            continue;
        }

        $sourceName = '';
        if (isset($item->source)) {
            $sourceName = trim((string)$item->source);
        }

        $cleanDescription = trim(strip_tags($description));
        $cleanDescription = html_entity_decode($cleanDescription, ENT_QUOTES, 'UTF-8');

        if (function_exists('mb_strlen') && mb_strlen($cleanDescription) > 160) {
            $cleanDescription = mb_substr($cleanDescription, 0, 157) . '...';
        } elseif (strlen($cleanDescription) > 160) {
            $cleanDescription = substr($cleanDescription, 0, 157) . '...';
        }

        $image = getSmartNewsImage($item, $title, $cleanDescription, $sourceName, $link, $cacheDir);

        $host = parse_url($link, PHP_URL_HOST) ?: 'news.google.com';
        $favicon = 'https://www.google.com/s2/favicons?domain=' . urlencode($host) . '&sz=64';

        $label = 'Info';
        $labelClass = 'info';
        $titleLower = strtolower($title . ' ' . $cleanDescription);

        if (strpos($titleLower, 'delay') !== false || strpos($titleLower, 'batal') !== false || strpos($titleLower, 'dibatalkan') !== false || strpos($titleLower, 'gangguan') !== false) {
            $label = 'Gangguan';
            $labelClass = 'danger';
        } elseif (strpos($titleLower, 'cuaca') !== false || strpos($titleLower, 'hujan') !== false || strpos($titleLower, 'angin') !== false) {
            $label = 'Cuaca';
            $labelClass = 'warning';
        } elseif (strpos($titleLower, 'esdm') !== false || strpos($titleLower, 'avtur') !== false || strpos($titleLower, 'pertamina') !== false) {
            $label = 'Energi';
            $labelClass = 'warning';
        }

        $items[] = [
            'title' => $title,
            'link' => $link,
            'description' => $cleanDescription,
            'source' => $sourceName ?: 'Google News',
            'date' => $pubTs ? date('d M Y H:i', $pubTs) : '',
            'image' => $image,
            'favicon' => $favicon,
            'label' => $label,
            'label_class' => $labelClass,
        ];
    }

    return $items;
}

function getNewsImage($item)
{
    return getNewsImageFromRssItem($item);
}

$latestNews = []; // Berita dimuat via AJAX setelah halaman utama tampil.
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Dashboard Portal Minimarket</title>

    <!-- PWA / installable app metadata -->
    <meta name="theme-color" content="#06101f">
    <meta name="application-name" content="SRT Minimarket">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="SRT Minimarket">
    <meta name="format-detection" content="telephone=no">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" sizes="180x180" href="/pwa-icon.php?size=180">
    <link rel="icon" type="image/png" href="img/srt2.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>

    <style>
        :root {
            --bg-1: #06101f;
            --bg-2: #0a1830;
            --bg-3: #102343;
            --panel-border: rgba(255, 255, 255, 0.10);
            --panel-bg: rgba(255, 255, 255, 0.06);
            --panel-bg-strong: rgba(255, 255, 255, 0.08);
            --text: #eaf2ff;
            --muted: #9fb2d1;
            --primary: #4f8cff;
            --primary-2: #7c4dff;
            --cyan: #19c7ff;
            --green: #12c483;
            --orange: #ffb020;
            --purple: #9a6bff;
            --danger: #ff5f6d;
            --shadow: 0 18px 45px rgba(3, 10, 28, 0.35);
            --shadow-soft: 0 12px 26px rgba(0, 0, 0, 0.18);
            --radius-xl: 24px;
            --radius-lg: 18px;
            --radius-md: 14px;
            --sidebar-width: 280px;
            --header-height: 82px;
            --ticker-height: 42px;
            --footer-height: 44px;
            --transition: all .28s ease;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }

        body {
            font-family: 'Inter', sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(79, 140, 255, 0.22), transparent 28%),
                radial-gradient(circle at top right, rgba(124, 77, 255, 0.18), transparent 24%),
                radial-gradient(circle at bottom, rgba(25, 199, 255, 0.12), transparent 26%),
                linear-gradient(135deg, var(--bg-1) 0%, var(--bg-2) 42%, var(--bg-3) 100%);
            min-height: 100vh;
            overflow-x: hidden;
        }
        .sales-total-wrap {
    margin: 12px 0 4px;
}

.sales-total-row {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.sensitive-value {
    letter-spacing: 0.3px;
}

.toggle-sensitive-btn {
    width: 38px;
    height: 38px;
    border: 1px solid rgba(255, 255, 255, 0.10);
    background: rgba(255, 255, 255, 0.08);
    color: #dce8ff;
    border-radius: 12px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: var(--transition);
    box-shadow: var(--shadow-soft);
}

.toggle-sensitive-btn:hover {
    transform: translateY(-1px);
    background: rgba(255, 255, 255, 0.14);
}

.toggle-sensitive-btn i {
    font-size: 15px;
}
        body::before,
        body::after {
            content: "";
            position: fixed;
            border-radius: 50%;
            filter: blur(60px);
            pointer-events: none;
            z-index: 0;
        }

        body::before {
            width: 280px;
            height: 280px;
            background: rgba(79, 140, 255, 0.18);
            top: -80px;
            left: -50px;
        }

        body::after {
            width: 260px;
            height: 260px;
            background: rgba(124, 77, 255, 0.14);
            right: -50px;
            bottom: 30px;
        }

        a { color: inherit; text-decoration: none; }
        img { display: block; max-width: 100%; }

        .app-shell { position: relative; z-index: 1; }

        .mobile-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            backdrop-filter: blur(2px);
            z-index: 1090;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .mobile-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .sidebar {
            position: fixed;
            inset: 0 auto var(--footer-height) 0;
            width: var(--sidebar-width);
            background: rgba(8, 17, 34, 0.92);
            backdrop-filter: blur(18px);
            border-right: 1px solid var(--panel-border);
            box-shadow: var(--shadow);
            padding: 18px 16px 22px;
            overflow-y: auto;
            transition: transform .32s ease, box-shadow .32s ease;
            z-index: 1100;
        }

        .sidebar.hide {
            transform: translateX(calc(-1 * var(--sidebar-width) - 20px));
        }

        .nav-section { margin-top: 0; }

        .menu-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            width: 100%;
            border: none;
            outline: none;
            cursor: pointer;
            margin: 10px 0 0;
            padding: 14px 16px;
            color: #dce8ff;
            font-size: 13px;
            font-weight: 700;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.045);
            border: 1px solid rgba(255, 255, 255, 0.055);
            transition: var(--transition);
        }

        .menu-toggle:hover,
        .menu-toggle.active {
            background: linear-gradient(135deg, rgba(79, 140, 255, 0.26), rgba(124, 77, 255, 0.18));
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(7, 15, 35, 0.24);
        }

        .menu-toggle .left {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .menu-toggle i.chevron { transition: transform .25s ease; }
        .menu-toggle.active i.chevron { transform: rotate(180deg); }

        .submenu {
            list-style: none;
            display: grid;
            gap: 8px;
            padding: 10px 6px 0 10px;
            max-height: 0;
            overflow: hidden;
            transition: max-height .3s ease;
        }

        .submenu.show { max-height: 900px; }

        .submenu li a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 13px;
            border-radius: 14px;
            color: var(--muted);
            font-size: 13px;
            transition: var(--transition);
            border-left: 3px solid transparent;
        }

        .submenu li a:hover,
        .submenu li a.active-link {
            color: #fff;
            background: rgba(79, 140, 255, 0.16);
            transform: translateX(3px);
            border-left-color: #7cc8ff;
        }

        .main-area {
            margin-left: var(--sidebar-width);
            transition: var(--transition);
        }

        .main-area.full {
            margin-left: 0;
        }

        .topbar {
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            right: 0;
            height: var(--header-height);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 0 26px;
            background: rgba(8, 17, 32, 0.72);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            transition: var(--transition);
            z-index: 1000;
        }

        .topbar.scrolled {
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
            background: rgba(8, 17, 32, 0.88);
        }

        .topbar.full { left: 0; }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .toggle-sidebar {
            width: 46px;
            height: 46px;
            border: none;
            cursor: pointer;
            border-radius: 14px;
            color: #fff;
            font-size: 18px;
            background: linear-gradient(135deg, rgba(79, 140, 255, 0.96), rgba(124, 77, 255, 0.96));
            box-shadow: 0 12px 20px rgba(79, 140, 255, 0.28);
            transition: var(--transition);
        }

        .toggle-sidebar:hover { transform: translateY(-2px); }

        .page-head h2 {
            font-size: 24px;
            font-weight: 800;
            letter-spacing: .2px;
        }

        .page-head p {
            margin-top: 4px;
            font-size: 13px;
            color: var(--muted);
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .quick-date {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #dce8ff;
            font-size: 13px;
            box-shadow: var(--shadow-soft);
        }

        .header-profile {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid rgba(255, 255, 255, 0.18);
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.18);
            flex-shrink: 0;
            transition: var(--transition);
        }

        .header-profile:hover {
            transform: translateY(-2px) scale(1.03);
            border-color: rgba(255, 255, 255, 0.28);
        }

        .header-profile img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .notif-wrap { position: relative; }

        .notif-btn {
            position: relative;
            width: 48px;
            height: 48px;
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.06);
            color: #fff;
            font-size: 18px;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: var(--shadow-soft);
        }

        .notif-btn:hover {
            transform: translateY(-2px);
            background: rgba(255, 255, 255, 0.10);
        }

        .notif-badge {
            position: absolute;
            top: -4px;
            right: -2px;
            min-width: 22px;
            height: 22px;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #ff5f6d, #ffc371);
            color: #fff;
            font-size: 11px;
            font-weight: 800;
            padding: 0 6px;
            box-shadow: 0 6px 15px rgba(255, 95, 109, 0.25);
            animation: pulseBadge 1.8s infinite;
        }

        @keyframes pulseBadge {
            0% { box-shadow: 0 0 0 0 rgba(255, 95, 109, 0.45); }
            70% { box-shadow: 0 0 0 10px rgba(255, 95, 109, 0); }
            100% { box-shadow: 0 0 0 0 rgba(255, 95, 109, 0); }
        }

        .notif-dropdown {
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            width: 360px;
            background: rgba(13, 23, 48, 0.96);
            border: 1px solid rgba(255, 255, 255, 0.10);
            border-radius: 20px;
            box-shadow: var(--shadow);
            overflow: hidden;
            opacity: 0;
            visibility: hidden;
            transform: translateY(10px);
            transition: var(--transition);
        }

        .notif-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .notif-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .notif-head h4 {
            font-size: 15px;
            font-weight: 800;
        }

        .notif-head span {
            font-size: 12px;
            color: var(--muted);
        }

        .notif-list {
            max-height: 380px;
            overflow-y: auto;
            padding: 8px;
        }

        .notif-item {
            display: flex;
            gap: 12px;
            padding: 12px;
            border-radius: 16px;
            transition: var(--transition);
        }

        .notif-item:hover {
            background: rgba(255, 255, 255, 0.06);
            transform: translateX(3px);
        }

        .notif-icon {
            width: 40px;
            height: 40px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: linear-gradient(135deg, rgba(79, 140, 255, 0.30), rgba(6, 182, 212, 0.24));
        }

        .notif-content small {
            display: block;
            font-size: 11px;
            color: #7cc8ff;
            margin-bottom: 4px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .notif-content p {
            font-size: 13px;
            line-height: 1.45;
            color: #e7efff;
        }

        .notif-empty {
            padding: 28px 18px;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
        }

        .running-bar {
            position: fixed;
            top: var(--header-height);
            left: var(--sidebar-width);
            right: 0;
            height: var(--ticker-height);
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 0 18px;
            background: linear-gradient(90deg, rgba(25, 199, 255, 0.92), rgba(79, 140, 255, 0.92));
            border-bottom: 1px solid rgba(255, 255, 255, 0.10);
            overflow: hidden;
            transition: var(--transition);
            z-index: 999;
        }

        .running-bar.full { left: 0; }

        .running-label {
            flex-shrink: 0;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.18);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .6px;
        }

        .marquee {
            white-space: nowrap;
            display: inline-block;
            padding-left: 100%;
            animation: marquee 22s linear infinite;
            font-size: 13px;
            font-weight: 600;
        }

        @keyframes marquee {
            0% { transform: translateX(0); }
            100% { transform: translateX(-140%); }
        }

        .content {
            padding: calc(var(--header-height) + var(--ticker-height) + 28px) 26px calc(var(--footer-height) + 28px);
        }

        .hero-mini {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 18px;
            margin-bottom: 20px;
        }

        .hero-card,
        .status-card {
            background: var(--panel-bg);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.09);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .hero-card { padding: 22px; }

        /* HERO LEFT - brighter premium card, dibuat senada dengan chart kanan */
        .hero-card {
            background:
                radial-gradient(circle at 88% 10%, rgba(124, 77, 255, 0.34), transparent 32%),
                radial-gradient(circle at 8% 92%, rgba(56, 189, 248, 0.20), transparent 34%),
                linear-gradient(135deg, rgba(28, 59, 112, 0.96), rgba(37, 49, 114, 0.95) 52%, rgba(30, 39, 91, 0.96));
            border-color: rgba(124, 178, 255, 0.18);
            box-shadow: 0 24px 70px rgba(18, 82, 160, 0.20), 0 18px 44px rgba(0, 0, 0, 0.20);
        }

        .hero-card::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.10), transparent 28%, rgba(255,255,255,0.03));
            pointer-events: none;
            opacity: .75;
        }

        .hero-card > * {
            position: relative;
            z-index: 1;
        }

        .hero-card::before,
        .status-card::before {
            content: "";
            position: absolute;
            width: 180px;
            height: 180px;
            right: -45px;
            top: -45px;
            border-radius: 50%;
            background: rgba(255,255,255,0.06);
        }

        .hero-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 14px;
        }

        .hero-tag {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .5px;
            color: #d7ecff;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(79, 140, 255, 0.16);
            border: 1px solid rgba(79, 140, 255, 0.22);
        }

        .hero-live {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 700;
            color: #dce8ff;
        }

        .hero-live-dot {
            width: 10px;
            height: 10px;
            background: #10d17b;
            border-radius: 50%;
            box-shadow: 0 0 0 0 rgba(16, 209, 123, 0.55);
            animation: livePulse 1.8s infinite;
        }

        @keyframes livePulse {
            0% { box-shadow: 0 0 0 0 rgba(16, 209, 123, 0.55); }
            70% { box-shadow: 0 0 0 10px rgba(16, 209, 123, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 209, 123, 0); }
        }

        .hero-card h3 {
            font-size: 23px;
            line-height: 1.3;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .hero-card p {
            color: var(--muted);
            line-height: 1.7;
            font-size: 13px;
            max-width: 92%;
        }


        .hero-info-photo-wrap {
            margin-top: 16px;
            max-width: 520px;
        }

        .hero-info-photo-btn {
            width: 100%;
            border: 0;
            padding: 0;
            cursor: pointer;
            border-radius: 22px;
            overflow: hidden;
            position: relative;
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.16);
            box-shadow: 0 18px 38px rgba(0,0,0,.24);
            display: block;
        }

        .hero-info-photo-btn img {
            width: 100%;
            height: 210px;
            object-fit: cover;
            display: block;
            transform: scale(1.002);
            transition: transform .25s ease, filter .25s ease;
        }

        .hero-info-photo-btn:hover img {
            transform: scale(1.035);
            filter: brightness(1.04);
        }

        .hero-info-photo-caption {
            position: absolute;
            left: 12px;
            right: 12px;
            bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 16px;
            background: rgba(15,23,42,.62);
            backdrop-filter: blur(12px);
            color: #fff;
            font-size: 12px;
            font-weight: 800;
        }

        .hero-photo-modal {
            position: fixed;
            inset: 0;
            z-index: 99999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(2,6,23,.72);
            backdrop-filter: blur(14px);
        }

        .hero-photo-modal.show {
            display: flex;
        }

        .hero-photo-frame {
            width: min(980px, 94vw);
            max-height: 92vh;
            border-radius: 30px;
            overflow: hidden;
            position: relative;
            background: linear-gradient(135deg, rgba(255,255,255,.20), rgba(255,255,255,.06));
            border: 1px solid rgba(255,255,255,.22);
            box-shadow: 0 32px 95px rgba(0,0,0,.48);
            padding: 10px;
        }

        .hero-photo-frame img {
            width: 100%;
            max-height: calc(92vh - 72px);
            object-fit: contain;
            display: block;
            border-radius: 24px;
            background: rgba(15,23,42,.35);
        }

        .hero-photo-close {
            position: absolute;
            top: 18px;
            right: 18px;
            width: 44px;
            height: 44px;
            border: 0;
            border-radius: 16px;
            cursor: pointer;
            background: rgba(15,23,42,.72);
            color: #fff;
            font-size: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 12px 28px rgba(0,0,0,.25);
        }

        @media (max-width: 640px) {
            .hero-info-photo-btn img { height: 170px; }
            .hero-photo-modal { padding: 12px; }
            .hero-photo-frame { border-radius: 22px; padding: 7px; }
            .hero-photo-frame img { border-radius: 18px; }
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }

        .hero-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 16px;
            border-radius: 14px;
            font-size: 13px;
            font-weight: 700;
            transition: var(--transition);
        }

        .hero-btn.primary {
            background: linear-gradient(135deg, #4f8cff, #7c4dff);
            box-shadow: 0 12px 24px rgba(79, 140, 255, 0.24);
        }

        .hero-btn.secondary {
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.08);
        }

        .hero-btn:hover { transform: translateY(-2px); }

        .status-card {
            padding: 22px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            gap: 14px;
        }

        .status-title {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .7px;
            color: #94d8ff;
            font-weight: 800;
        }

        .status-month {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: fit-content;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.08);
            color: #d8e8ff;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .6px;
        }

        .status-main {
            display: block;
        }

        .status-main strong {
            font-size: 24px;
            font-weight: 800;
            line-height: 1.2;
            display: block;
        }

        .status-main span {
            display: block;
            color: var(--muted);
            font-size: 13px;
            margin-top: 6px;
        }

        .sales-summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin: 16px 0 10px;
        }

        .sales-mini-box {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 14px;
            padding: 12px;
        }

        .sales-mini-box .label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #9fb2d1;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .sales-mini-box .value {
            font-size: 14px;
            font-weight: 800;
            color: #f4f8ff;
            line-height: 1.4;
        }

        .sales-sensitive-value.is-masked {
            letter-spacing: .8px;
            color: #dbeafe !important;
        }

        .sales-mini-box .value.ach-good { color: #86efac; }
        .sales-mini-box .value.ach-bad { color: #fca5a5; }

        .status-foot {
            font-size: 13px;
            color: var(--muted);
            line-height: 1.6;
        }

        .status-chart-wrap {
            position: relative;
            width: 100%;
            height: 220px;
            margin-top: 12px;
        }

        .status-chart-wrap canvas {
            width: 100% !important;
            height: 100% !important;
        }


        /* ===== REORDER DASHBOARD LAYOUT FINAL ===== */
        .dashboard-sales-overview {
            margin-bottom: 20px;
        }

        .dashboard-sales-overview .status-card {
            width: 100%;
            min-height: 520px;
        }

        .dashboard-hero-info-only {
            grid-template-columns: 1fr !important;
            margin-bottom: 22px;
        }

        .dashboard-hero-info-only .hero-card {
            width: 100%;
        }

        .dashboard-hero-info-only .hero-card p {
            max-width: 100%;
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }

        .metric-card,
        .chart-card {
            background: var(--panel-bg);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.09);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow);
        }

        .metric-card {
            position: relative;
            overflow: hidden;
            padding: 22px;
            transition: var(--transition);
        }

        .metric-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 38px rgba(0,0,0,0.24);
            border-color: rgba(255,255,255,0.14);
        }

        .metric-card::before {
            content: "";
            position: absolute;
            right: -26px;
            top: -26px;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.09);
        }

        .metric-card::after {
            content: "";
            position: absolute;
            inset: auto 0 0 0;
            height: 3px;
            background: linear-gradient(90deg, rgba(79,140,255,0), rgba(79,140,255,0.7), rgba(124,77,255,0.8), rgba(79,140,255,0));
            opacity: 0;
            transition: var(--transition);
        }

        .metric-card:hover::after { opacity: 1; }

        .metric-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 18px;
        }

        .metric-icon {
            width: 58px;
            height: 58px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #fff;
            box-shadow: 0 16px 24px rgba(8, 17, 32, 0.18);
        }

        .bg-blue { background: linear-gradient(135deg, #4f8cff, #60a5fa); }
        .bg-orange { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .bg-green { background: linear-gradient(135deg, #10b981, #34d399); }
        .bg-purple { background: linear-gradient(135deg, #7c4dff, #a78bfa); }

        .metric-badge {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .6px;
            padding: 7px 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #cfe2ff;
        }

        .metric-card h4 {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .metric-card .metric-value {
            font-size: 32px;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 10px;
        }

        .metric-card p {
            font-size: 13px;
            color: var(--muted);
            line-height: 1.6;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .chart-card {
            padding: 22px;
            min-height: 500px;
            display: flex;
            flex-direction: column;
            transition: var(--transition);
        }

        .chart-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 38px rgba(0,0,0,0.22);
        }

        .chart-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 18px;
        }

        .chart-title h3 {
            font-size: 17px;
            font-weight: 800;
        }

        .chart-title p {
            margin-top: 5px;
            color: var(--muted);
            font-size: 13px;
        }

        .chart-chip {
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .6px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #d8e8ff;
        }

        .chart-body {
            position: relative;
            flex: 1;
            min-height: 340px;
        }


        .news-section {
            margin-top: 22px;
        }

        .news-card {
            background: var(--panel-bg);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.09);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow);
            padding: 22px;
            overflow: hidden;
            position: relative;
        }

        .news-card::before {
            content: "";
            position: absolute;
            width: 190px;
            height: 190px;
            right: -60px;
            top: -70px;
            border-radius: 50%;
            background: rgba(25, 199, 255, 0.08);
            pointer-events: none;
        }

        .news-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 18px;
            position: relative;
            z-index: 1;
        }

        .news-title h3 {
            font-size: 18px;
            font-weight: 800;
        }

        .news-title p {
            margin-top: 5px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .news-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .6px;
            background: rgba(25, 199, 255, 0.14);
            border: 1px solid rgba(25, 199, 255, 0.18);
            color: #d8f3ff;
            white-space: nowrap;
        }

        .news-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            position: relative;
            z-index: 1;
        }

        .news-item {
            min-height: 100%;
            border-radius: 18px;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.08);
            transition: var(--transition);
        }

        .news-item:hover {
            transform: translateY(-4px);
            background: rgba(255, 255, 255, 0.09);
            box-shadow: 0 18px 30px rgba(0,0,0,0.20);
        }

        .news-thumb {
            height: 132px;
            background: linear-gradient(135deg, rgba(79,140,255,.30), rgba(124,77,255,.25));
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .news-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .news-thumb.fallback img {
            width: 54px;
            height: 54px;
            object-fit: contain;
            border-radius: 14px;
            background: rgba(255,255,255,.14);
            padding: 8px;
        }

        .news-body {
            padding: 14px;
        }

        .news-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #9fdcff;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 8px;
        }

        .news-label {
            margin-left: auto;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 900;
            color: #fff;
            background: rgba(79,140,255,.24);
            border: 1px solid rgba(255,255,255,.10);
        }

        .news-label.danger {
            background: rgba(255,95,109,.24);
        }

        .news-label.warning {
            background: rgba(255,176,32,.24);
        }

        .news-label.info {
            background: rgba(25,199,255,.20);
        }

        .news-body h4 {
            font-size: 14px;
            line-height: 1.45;
            color: #f4f8ff;
            margin-bottom: 8px;
        }

        .news-body p {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
        }

        .news-date {
            display: block;
            margin-top: 11px;
            font-size: 11px;
            color: #b9c9e6;
        }
        .empty-state {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: var(--muted);
            background: rgba(255, 255, 255, 0.04);
            border-radius: 18px;
            border: 1px dashed rgba(255, 255, 255, 0.12);
            padding: 26px;
            line-height: 1.6;
            font-size: 14px;
        }

        .footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            height: var(--footer-height);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 0 14px;
            background: rgba(8, 17, 32, 0.86);
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            color: var(--muted);
            font-size: 12px;
            z-index: 1200;
        }

        .footer-left {
            padding-left: 0;
            margin-left: 0;
            transition: var(--transition);
        }

        .footer-left.full {
            padding-left: 0;
            margin-left: 0;
        }

        .swal2-popup {
            border-radius: 26px !important;
            background: #0d1730 !important;
            color: #eff6ff !important;
            border: 1px solid rgba(255,255,255,.09) !important;
            box-shadow: 0 24px 60px rgba(3, 10, 28, 0.45) !important;
        }

        .swal2-title,
        .swal2-html-container {
            color: #eff6ff !important;
        }

        .live-toast {
            border-radius: 18px !important;
            background: rgba(13, 23, 48, 0.96) !important;
            color: #f5f9ff !important;
            border: 1px solid rgba(255,255,255,.08) !important;
            box-shadow: 0 16px 40px rgba(3, 10, 28, 0.38) !important;
        }


        .news-card-redesign {
            padding: 22px;
        }

        .aviation-news-layout {
            display: grid;
            grid-template-columns: minmax(360px, 0.95fr) minmax(520px, 1.35fr);
            gap: 18px;
            position: relative;
            z-index: 1;
        }

        .aviation-news-left {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .mini-news-card {
            display: grid;
            grid-template-rows: 128px 1fr;
            min-height: 285px;
            overflow: hidden;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.065);
            border: 1px solid rgba(255, 255, 255, 0.09);
            box-shadow: 0 12px 24px rgba(0,0,0,.14);
            transition: transform .28s ease, background .28s ease, box-shadow .28s ease;
        }

        .mini-news-card:hover {
            transform: translateY(-5px);
            background: rgba(255,255,255,.10);
            box-shadow: 0 18px 34px rgba(0,0,0,.24);
        }

        .mini-news-thumb {
            overflow: hidden;
            background: linear-gradient(135deg, rgba(79,140,255,.28), rgba(25,199,255,.16));
        }

        .mini-news-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform .45s ease;
        }

        .mini-news-card:hover .mini-news-thumb img {
            transform: scale(1.08);
        }

        .mini-news-body {
            padding: 13px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .mini-news-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            color: #9fdcff;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .35px;
        }

        .mini-news-meta span:last-child {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .mini-news-body h4 {
            font-size: 13px;
            line-height: 1.45;
            color: #f4f8ff;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .mini-news-body small {
            margin-top: auto;
            color: #b9c9e6;
            font-size: 11px;
        }

        .aviation-news-slider {
            min-height: 585px;
            position: relative;
            overflow: hidden;
            border-radius: 24px;
            border: 1px solid rgba(255,255,255,.11);
            background: rgba(255,255,255,.055);
            box-shadow: 0 18px 42px rgba(0,0,0,.24);
        }

        .featured-news-slide {
            position: absolute;
            inset: 0;
            opacity: 0;
            transform: translateX(22px) scale(.985);
            pointer-events: none;
            transition: opacity .75s ease, transform .75s ease;
        }

        .featured-news-slide.active {
            opacity: 1;
            transform: translateX(0) scale(1);
            pointer-events: auto;
        }

        .featured-news-image {
            position: absolute;
            inset: 0;
            overflow: hidden;
        }

        .featured-news-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transform: scale(1.035);
            transition: transform 5s ease;
        }

        .featured-news-slide.active .featured-news-image img {
            transform: scale(1.10);
        }

        .featured-news-overlay {
            position: absolute;
            inset: 0;
            background:
                linear-gradient(180deg, rgba(6,16,31,.08) 0%, rgba(6,16,31,.68) 47%, rgba(6,16,31,.94) 100%),
                linear-gradient(90deg, rgba(6,16,31,.72) 0%, rgba(6,16,31,.16) 62%, rgba(6,16,31,.35) 100%);
        }

        .featured-news-content {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 24px;
            z-index: 2;
        }

        .featured-news-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .featured-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 11px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .55px;
            color: #fff;
            background: rgba(25,199,255,.22);
            border: 1px solid rgba(255,255,255,.14);
            backdrop-filter: blur(8px);
        }

        .featured-badge.danger {
            background: rgba(255,95,109,.28);
        }

        .featured-badge.warning {
            background: rgba(255,176,32,.28);
        }

        .featured-source {
            color: #d7ecff;
            font-size: 12px;
            font-weight: 800;
            background: rgba(0,0,0,.22);
            border: 1px solid rgba(255,255,255,.12);
            padding: 8px 11px;
            border-radius: 999px;
            backdrop-filter: blur(8px);
        }

        .featured-news-content h3 {
            max-width: 92%;
            font-size: clamp(23px, 2.4vw, 34px);
            line-height: 1.22;
            font-weight: 900;
            color: #fff;
            text-shadow: 0 6px 22px rgba(0,0,0,.34);
            margin-bottom: 12px;
        }

        .featured-news-content p {
            max-width: 86%;
            color: #dbeafe;
            font-size: 14px;
            line-height: 1.75;
            margin-bottom: 16px;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .featured-news-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            color: #cfe2ff;
            font-size: 12px;
        }

        .featured-news-bottom strong {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #fff;
            padding: 10px 13px;
            border-radius: 14px;
            background: linear-gradient(135deg, #4f8cff, #7c4dff);
            box-shadow: 0 12px 24px rgba(79,140,255,.24);
            white-space: nowrap;
        }

        .featured-slider-dots {
            position: absolute;
            left: 22px;
            top: 22px;
            display: flex;
            gap: 7px;
            z-index: 4;
        }

        .featured-dot {
            width: 9px;
            height: 9px;
            border-radius: 999px;
            border: none;
            cursor: pointer;
            background: rgba(255,255,255,.42);
            transition: width .28s ease, background .28s ease;
        }

        .featured-dot.active {
            width: 30px;
            background: #ffffff;
        }


        .hero-activity-slider {
            margin-top: 18px;
            position: relative;
            z-index: 2;
        }

        .hero-activity-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }

        .hero-activity-head h4 {
            font-size: 14px;
            font-weight: 900;
            color: #f4f8ff;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .hero-activity-head span {
            font-size: 11px;
            color: #d8f3ff;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .55px;
            white-space: nowrap;
            padding: 7px 10px;
            border-radius: 999px;
            background: rgba(25, 199, 255, .14);
            border: 1px solid rgba(25, 199, 255, .18);
        }

        .hero-activity-viewport {
            position: relative;
            height: 210px;
            overflow: hidden;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, .11);
            background: rgba(255, 255, 255, .055);
            box-shadow: 0 18px 34px rgba(0, 0, 0, .18);
        }

        .hero-activity-track {
            height: 100%;
            display: flex;
            transition: transform .75s ease;
            will-change: transform;
        }

        .hero-activity-slide {
            position: relative;
            min-width: 100%;
            height: 100%;
            overflow: hidden;
            color: #fff;
            cursor: pointer;
            isolation: isolate;
        }

        .hero-activity-bg {
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 78% 18%, rgba(255, 255, 255, .13), transparent 23%),
                linear-gradient(135deg, rgba(79, 140, 255, .34), rgba(124, 77, 255, .18));
            z-index: -3;
        }

        .hero-activity-bg img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: .82;
            transform: scale(1.04);
            transition: transform 5s ease;
        }

        .hero-activity-slide.active .hero-activity-bg img,
        .hero-activity-slide:hover .hero-activity-bg img {
            transform: scale(1.11);
        }

        .hero-activity-placeholder {
            width: 100%;
            height: 100%;
            display: grid;
            place-items: center;
            color: rgba(255, 255, 255, .28);
            font-size: 56px;
        }

        .hero-activity-overlay {
            position: absolute;
            inset: 0;
            background:
                linear-gradient(180deg, rgba(6, 16, 31, .14) 0%, rgba(6, 16, 31, .62) 48%, rgba(6, 16, 31, .94) 100%),
                linear-gradient(90deg, rgba(6, 16, 31, .76) 0%, rgba(6, 16, 31, .28) 63%, rgba(6, 16, 31, .42) 100%);
            z-index: -2;
        }

        .hero-activity-content {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 18px;
            z-index: 2;
        }

        .hero-activity-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }

        .hero-activity-badge,
        .hero-activity-source {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .45px;
            color: #fff;
            background: rgba(25, 199, 255, .22);
            border: 1px solid rgba(255, 255, 255, .14);
            backdrop-filter: blur(8px);
            white-space: nowrap;
        }

        .hero-activity-badge.danger {
            background: rgba(255, 95, 109, .30);
        }

        .hero-activity-source {
            max-width: 48%;
            overflow: hidden;
            text-overflow: ellipsis;
            background: rgba(0, 0, 0, .24);
            text-transform: none;
            letter-spacing: 0;
        }

        .hero-activity-content h3 {
            max-width: 94%;
            margin: 0 0 7px;
            font-size: clamp(17px, 1.8vw, 23px);
            line-height: 1.22;
            font-weight: 900;
            color: #fff;
            text-shadow: 0 6px 20px rgba(0, 0, 0, .34);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .hero-activity-content p {
            max-width: 92%;
            margin: 0 0 12px;
            color: #dbeafe;
            font-size: 12px;
            line-height: 1.55;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .hero-activity-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            color: #cfe2ff;
            font-size: 11px;
            font-weight: 800;
        }

        .hero-activity-bottom span {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-width: 0;
        }

        .hero-activity-bottom strong {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: #fff;
            padding: 9px 12px;
            border-radius: 13px;
            background: linear-gradient(135deg, #4f8cff, #7c4dff);
            box-shadow: 0 12px 22px rgba(79, 140, 255, .22);
            white-space: nowrap;
        }

        .hero-activity-dots {
            position: absolute;
            left: 16px;
            top: 16px;
            display: flex;
            gap: 7px;
            z-index: 5;
        }

        .hero-activity-dot {
            width: 8px;
            height: 8px;
            border: 0;
            border-radius: 999px;
            background: rgba(255, 255, 255, .45);
            cursor: pointer;
            transition: width .28s ease, background .28s ease;
        }

        .hero-activity-dot.active {
            width: 28px;
            background: #fff;
        }

        .hero-activity-empty {
            min-height: 150px;
            padding: 18px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
            border-radius: 20px;
            border: 1px dashed rgba(255, 255, 255, .13);
            background: rgba(255, 255, 255, .04);
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }



        .wo-realisasi-slider {
            margin-top: 18px;
            position: relative;
            z-index: 2;
        }

        .wo-realisasi-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }

        .wo-realisasi-head h4 {
            font-size: 14px;
            font-weight: 900;
            color: #f4f8ff;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .wo-realisasi-head span {
            font-size: 11px;
            color: #d8f3ff;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .55px;
            white-space: nowrap;
            padding: 7px 10px;
            border-radius: 999px;
            background: rgba(25, 199, 255, .14);
            border: 1px solid rgba(25, 199, 255, .18);
        }

        .wo-realisasi-viewport {
            position: relative;
            height: 210px;
            overflow: hidden;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, .11);
            background: rgba(255, 255, 255, .055);
            box-shadow: 0 18px 34px rgba(0, 0, 0, .18);
        }

        .wo-realisasi-track {
            height: 100%;
            display: flex;
            transition: transform .75s ease;
            will-change: transform;
        }

        .wo-realisasi-slide {
            position: relative;
            min-width: 100%;
            height: 100%;
            overflow: hidden;
            color: #fff;
            cursor: pointer;
            isolation: isolate;
        }

        .wo-realisasi-bg {
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 78% 18%, rgba(255, 255, 255, .13), transparent 23%),
                linear-gradient(135deg, rgba(79, 140, 255, .34), rgba(124, 77, 255, .18));
            z-index: -3;
        }

        .wo-realisasi-bg img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: .86;
            transform: scale(1.04);
            transition: transform 5s ease;
        }

        .wo-realisasi-slide.active .wo-realisasi-bg img,
        .wo-realisasi-slide:hover .wo-realisasi-bg img {
            transform: scale(1.11);
        }

        .wo-realisasi-overlay {
            position: absolute;
            inset: 0;
            background:
                linear-gradient(180deg, rgba(6, 16, 31, .12) 0%, rgba(6, 16, 31, .60) 48%, rgba(6, 16, 31, .94) 100%),
                linear-gradient(90deg, rgba(6, 16, 31, .76) 0%, rgba(6, 16, 31, .25) 63%, rgba(6, 16, 31, .42) 100%);
            z-index: -2;
        }

        .wo-realisasi-content {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 18px;
            z-index: 2;
        }

        .wo-realisasi-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }

        .wo-realisasi-badge,
        .wo-realisasi-source {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .45px;
            color: #fff;
            background: rgba(25, 199, 255, .22);
            border: 1px solid rgba(255, 255, 255, .14);
            backdrop-filter: blur(8px);
            white-space: nowrap;
        }

        .wo-realisasi-source {
            max-width: 48%;
            overflow: hidden;
            text-overflow: ellipsis;
            background: rgba(0, 0, 0, .24);
            text-transform: none;
            letter-spacing: 0;
        }

        .wo-realisasi-content h3 {
            max-width: 94%;
            margin: 0 0 7px;
            font-size: clamp(17px, 1.8vw, 23px);
            line-height: 1.22;
            font-weight: 900;
            color: #fff;
            text-shadow: 0 6px 20px rgba(0, 0, 0, .34);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .wo-realisasi-content p {
            max-width: 92%;
            margin: 0 0 12px;
            color: #dbeafe;
            font-size: 12px;
            line-height: 1.55;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .wo-realisasi-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            color: #cfe2ff;
            font-size: 11px;
            font-weight: 800;
        }

        .wo-realisasi-bottom span {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-width: 0;
        }

        .wo-realisasi-bottom strong {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: #fff;
            padding: 9px 12px;
            border-radius: 13px;
            background: linear-gradient(135deg, #4f8cff, #7c4dff);
            box-shadow: 0 12px 22px rgba(79, 140, 255, .22);
            white-space: nowrap;
        }

        .wo-realisasi-dots {
            position: absolute;
            left: 16px;
            top: 16px;
            display: flex;
            gap: 7px;
            z-index: 5;
        }

        .wo-realisasi-dot {
            width: 8px;
            height: 8px;
            border: 0;
            border-radius: 999px;
            background: rgba(255, 255, 255, .45);
            cursor: pointer;
            transition: width .28s ease, background .28s ease;
        }

        .wo-realisasi-dot.active {
            width: 28px;
            background: #fff;
        }

        .wo-realisasi-empty {
            min-height: 150px;
            padding: 18px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
            border-radius: 20px;
            border: 1px dashed rgba(255, 255, 255, .13);
            background: rgba(255, 255, 255, .04);
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        body.page-leaving {
            opacity: .25;
            transition: opacity .28s ease;
        }


        .wo-lightbox {
            position: fixed;
            inset: 0;
            z-index: 3000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(3, 10, 28, .78);
            backdrop-filter: blur(10px);
            opacity: 0;
            visibility: hidden;
            transition: opacity .28s ease, visibility .28s ease;
        }

        .wo-lightbox.show {
            opacity: 1;
            visibility: visible;
        }

        .wo-lightbox-card {
            width: min(980px, 96vw);
            max-height: 92vh;
            border-radius: 26px;
            overflow: hidden;
            background: rgba(13, 23, 48, .98);
            border: 1px solid rgba(255,255,255,.13);
            box-shadow: 0 30px 80px rgba(0,0,0,.45);
            transform: scale(.96) translateY(12px);
            transition: transform .28s ease;
        }

        .wo-lightbox.show .wo-lightbox-card {
            transform: scale(1) translateY(0);
        }

        .wo-lightbox-image-wrap {
            position: relative;
            width: 100%;
            height: min(62vh, 620px);
            background: rgba(0,0,0,.32);
        }

        .wo-lightbox-image-wrap img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .wo-lightbox-close,
        .wo-lightbox-nav {
            position: absolute;
            border: 1px solid rgba(255,255,255,.16);
            color: #fff;
            background: rgba(0,0,0,.34);
            backdrop-filter: blur(8px);
            cursor: pointer;
            transition: var(--transition);
        }

        .wo-lightbox-close {
            top: 16px;
            right: 16px;
            width: 44px;
            height: 44px;
            border-radius: 15px;
            font-size: 18px;
        }

        .wo-lightbox-close:hover,
        .wo-lightbox-nav:hover {
            background: rgba(79,140,255,.45);
            transform: translateY(-1px);
        }

        .wo-lightbox-nav {
            top: 50%;
            transform: translateY(-50%);
            width: 48px;
            height: 58px;
            border-radius: 18px;
            font-size: 20px;
        }

        .wo-lightbox-prev { left: 16px; }
        .wo-lightbox-next { right: 16px; }

        .wo-lightbox-nav:hover {
            transform: translateY(-50%) scale(1.04);
        }

        .wo-lightbox-info {
            padding: 20px 22px 22px;
        }

        .wo-lightbox-info-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }

        .wo-lightbox-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 11px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .5px;
            background: rgba(25,199,255,.18);
            border: 1px solid rgba(25,199,255,.22);
            color: #d8f3ff;
        }

        .wo-lightbox-counter {
            color: #cfe2ff;
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }

        .wo-lightbox-info h3 {
            margin: 0 0 8px;
            color: #fff;
            font-size: clamp(19px, 2.2vw, 28px);
            line-height: 1.25;
            font-weight: 900;
        }

        .wo-lightbox-info p {
            margin: 0 0 13px;
            color: #b8cef2;
            font-size: 14px;
            line-height: 1.7;
        }

        .wo-lightbox-meta {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            color: #cfe2ff;
            font-size: 12px;
            font-weight: 800;
        }

        @media (max-width: 640px) {
            .wo-lightbox {
                padding: 12px;
            }

            .wo-lightbox-image-wrap {
                height: 56vh;
            }

            .wo-lightbox-prev { left: 8px; }
            .wo-lightbox-next { right: 8px; }

            .wo-lightbox-nav {
                width: 42px;
                height: 52px;
            }

            .wo-lightbox-info {
                padding: 16px;
            }
        }


        /* ===== DESKTOP SIDEBAR POPUP CARD MENU =====
           Desktop/laptop: submenu tidak turun ke bawah, tapi tampil sebagai card popup center.
           Mobile/tablet <= 991px tetap pakai dropdown lama agar nyaman di HP.
        */
        .desktop-menu-modal {
            position: fixed;
            inset: 0;
            z-index: 2600;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(3, 10, 28, .62);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            opacity: 0;
            visibility: hidden;
            transition: opacity .22s ease, visibility .22s ease;
        }

        .desktop-menu-modal.show {
            display: flex;
            opacity: 1;
            visibility: visible;
        }

        .desktop-menu-card {
            width: min(620px, calc(100vw - 64px));
            max-height: min(76vh, 680px);
            overflow: hidden;
            border-radius: 34px;
            background:
                radial-gradient(circle at 12% 0%, rgba(79, 140, 255, .30), transparent 34%),
                radial-gradient(circle at 92% 16%, rgba(25, 199, 255, .16), transparent 28%),
                linear-gradient(180deg, rgba(18, 30, 58, .98), rgba(8, 17, 34, .98));
            border: 1px solid rgba(255,255,255,.14);
            box-shadow: 0 30px 90px rgba(0,0,0,.50);
            transform: translateY(12px) scale(.96);
            transition: transform .22s ease;
        }

        .desktop-menu-modal.show .desktop-menu-card {
            transform: translateY(0) scale(1);
        }

        .desktop-menu-head {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 14px 18px 8px;
            position: relative;
            background: transparent;
            border-bottom: 0;
        }

        .desktop-menu-head::before {
            content: "";
            width: 64px;
            height: 5px;
            border-radius: 999px;
            background: rgba(255,255,255,.22);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.18);
        }

        .desktop-menu-icon,
        .desktop-menu-title {
            display: none !important;
        }

        .desktop-menu-close {
            position: absolute;
            top: 12px;
            right: 14px;
            width: 38px;
            height: 38px;
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,.16);
            color: #fff;
            background: rgba(255,255,255,.09);
            cursor: pointer;
            flex-shrink: 0;
            transition: var(--transition);
            backdrop-filter: blur(10px);
        }

        .desktop-menu-close:hover {
            background: rgba(255,255,255,.22);
            transform: translateY(-1px);
        }

        .desktop-menu-body {
            padding: 22px 22px 24px;
            max-height: calc(min(76vh, 680px) - 54px);
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .desktop-menu-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        .desktop-menu-item {
            min-height: 142px;
            padding: 16px 12px 14px;
            border-radius: 26px;
            color: #f4f8ff;
            background:
                linear-gradient(180deg, rgba(255,255,255,.105), rgba(255,255,255,.055));
            border: 1px solid rgba(255,255,255,.10);
            box-shadow:
                0 14px 26px rgba(0,0,0,.18),
                inset 0 1px 0 rgba(255,255,255,.08);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            text-align: center;
            transition: transform .18s ease, background .18s ease, border-color .18s ease, box-shadow .18s ease;
        }

        .desktop-menu-item:hover {
            color: #fff;
            text-decoration: none;
            transform: translateY(-5px) scale(1.015);
            background:
                linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.075));
            border-color: rgba(124, 200, 255, .30);
            box-shadow:
                0 22px 38px rgba(0,0,0,.26),
                inset 0 1px 0 rgba(255,255,255,.12);
        }

        .desktop-menu-item i {
            width: 62px;
            height: 62px;
            border-radius: 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 24px;
            background:
                radial-gradient(circle at 28% 22%, rgba(255,255,255,.34), transparent 28%),
                linear-gradient(135deg, #19c7ff, #4f8cff 52%, #7c4dff);
            box-shadow:
                0 14px 28px rgba(79,140,255,.28),
                inset 0 1px 0 rgba(255,255,255,.28);
            position: relative;
            overflow: hidden;
        }

        .desktop-menu-item i::after {
            content: "";
            position: absolute;
            inset: auto -20% -38% -20%;
            height: 70%;
            background: rgba(255,255,255,.14);
            transform: rotate(-10deg);
        }

        .desktop-menu-item:nth-child(2n) i {
            background:
                radial-gradient(circle at 28% 22%, rgba(255,255,255,.34), transparent 28%),
                linear-gradient(135deg, #22c55e, #19c7ff 52%, #4f8cff);
        }

        .desktop-menu-item:nth-child(3n) i {
            background:
                radial-gradient(circle at 28% 22%, rgba(255,255,255,.34), transparent 28%),
                linear-gradient(135deg, #ffb020, #ff5f6d 52%, #9a6bff);
        }

        .desktop-menu-item:nth-child(4n) i {
            background:
                radial-gradient(circle at 28% 22%, rgba(255,255,255,.34), transparent 28%),
                linear-gradient(135deg, #a78bfa, #7c4dff 52%, #4f8cff);
        }

        .desktop-menu-item span {
            display: block;
            color: #f4f8ff;
            font-size: 13px;
            font-weight: 850;
            line-height: 1.35;
            max-width: 100%;
        }

        /* Sidebar enterprise: submenu turun berderet di bawah menu utama, termasuk desktop. */
        @media (min-width: 992px) {
            .desktop-menu-modal {
                display: none !important;
            }

            .sidebar .submenu {
                display: grid;
            }

            .sidebar .menu-toggle.active i.chevron {
                transform: rotate(180deg);
            }
        }

        @media (max-width: 991px) {
            .desktop-menu-modal {
                display: none !important;
            }
        }

        @media (max-width: 1180px) and (min-width: 992px) {
            .desktop-menu-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }


        @media (max-width: 1280px) {
            .hero-mini { grid-template-columns: 1fr; }
            .metrics-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .charts-grid { grid-template-columns: 1fr; }
            .news-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .aviation-news-layout { grid-template-columns: 1fr; }
            .aviation-news-slider { min-height: 520px; }
        }

        @media (max-width: 991px) {
            :root { --sidebar-width: 0px; }

            .mobile-overlay { display: block; }

            .sidebar {
                transform: translateX(-110%);
                width: 290px;
            }

            .sidebar.show { transform: translateX(0); }

            .topbar,
            .running-bar { left: 0; }

            .main-area,
            .main-area.full { margin-left: 0; }

            .footer-left,
            .footer-left.full {
                padding-left: 0;
                margin-left: 0;
            }

            .metrics-grid,
            .charts-grid { grid-template-columns: 1fr; }
            .news-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .aviation-news-layout { grid-template-columns: 1fr; }
            .aviation-news-slider { min-height: 500px; }

            .quick-date { display: none; }
        }

        @media (max-width: 640px) {
            .topbar {
                height: auto;
                padding: 12px 14px;
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .topbar-left,
            .topbar-right {
                width: 100%;
                justify-content: space-between;
                align-items: center;
            }

            .topbar-left { gap: 10px; }

            .page-head h2 { font-size: 18px; }

            .page-head p {
                font-size: 12px;
                line-height: 1.5;
            }

            .running-bar {
                top: 96px;
                height: 38px;
                padding: 0 12px;
            }

            .running-label {
                font-size: 10px;
                padding: 5px 8px;
            }

            .marquee { font-size: 12px; }

            .content {
                padding: 150px 12px calc(var(--footer-height) + 16px);
            }

            .hero-card,
            .status-card,
            .metric-card,
            .chart-card {
                padding: 16px;
                border-radius: 18px;
            }

            .hero-card h3 { font-size: 18px; }

            .hero-card p,
            .status-foot { font-size: 12px; }

            .metrics-grid,
            .charts-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .news-head { align-items: flex-start; flex-direction: column; }
            .news-grid { grid-template-columns: 1fr; }
            .news-thumb { height: 170px; }
            .aviation-news-left { grid-template-columns: 1fr; }
            .aviation-news-slider { min-height: 430px; }
            .featured-news-content { padding: 18px; }
            .featured-news-content h3 { max-width: 100%; font-size: 20px; }
            .featured-news-content p { max-width: 100%; font-size: 12px; -webkit-line-clamp: 3; }
            .featured-news-bottom { align-items: flex-start; flex-direction: column; }
            .featured-source { display: none; }

            .metric-card .metric-value { font-size: 26px; }

            .chart-card { min-height: 360px; }
            .chart-body { min-height: 260px; }
            .chart-title h3 { font-size: 15px; }
            .chart-title p { font-size: 12px; }

            .notif-dropdown {
                width: min(92vw, 350px);
                right: -6px;
            }

            .header-profile {
                width: 40px;
                height: 40px;
            }

            .status-chart-wrap { height: 180px; }

            .sales-summary-grid {
                grid-template-columns: 1fr;
            }

            .footer {
                padding: 0 10px;
                font-size: 10px;
                justify-content: center;
                text-align: center;
            }
        }

        /* =========================================================
           UI/UX UPGRADE - Spacious + Mobile Friendly
           Fokus:
           - Desktop lebih lega, tidak terasa terlalu padat.
           - Mobile lebih simple: header ringkas, sidebar nyaman, chart/card tidak menekan user.
           - Tetap ringan: CSS only, tanpa library tambahan.
        ========================================================= */
        :root {
            --content-max-width: 1540px;
            --section-gap: 28px;
        }

        .content {
            max-width: var(--content-max-width);
            margin: 0 auto;
        }

        .hero-mini {
            gap: 26px;
            margin-bottom: var(--section-gap);
            align-items: stretch;
        }

        .hero-card,
        .status-card,
        .metric-card,
        .chart-card,
        .news-card {
            border-color: rgba(255, 255, 255, 0.12);
        }

        .hero-card {
            padding: 28px;
        }

        .status-card {
            padding: 28px;
        }

        .hero-card h3 {
            font-size: clamp(22px, 1.7vw, 30px);
            letter-spacing: -0.02em;
        }

        .hero-card p {
            max-width: 78%;
            font-size: 14px;
        }

        .hero-actions {
            gap: 12px;
            margin-top: 22px;
        }

        .hero-btn {
            min-height: 44px;
            padding: 12px 18px;
        }

        .hero-activity-slider,
        .wo-realisasi-slider {
            margin-top: 24px;
        }

        .hero-activity-head,
        .wo-realisasi-head {
            margin-bottom: 14px;
        }

        .hero-activity-viewport,
        .wo-realisasi-viewport {
            height: 245px;
            border-radius: 24px;
        }

        .hero-activity-content,
        .wo-realisasi-content {
            padding: 22px;
        }

        .hero-activity-content h3,
        .wo-realisasi-content h3 {
            font-size: clamp(20px, 1.9vw, 28px);
        }

        .metrics-grid {
            gap: 24px;
            margin-bottom: var(--section-gap);
        }

        .metric-card {
            min-height: 188px;
            padding: 26px;
        }

        .metric-card .metric-value {
            font-size: clamp(30px, 2.2vw, 40px);
        }

        .charts-grid {
            gap: 26px;
            margin-top: 6px;
        }

        .chart-card {
            padding: 26px;
            min-height: 540px;
        }

        .chart-head {
            margin-bottom: 24px;
        }

        .chart-body {
            min-height: 370px;
        }

        .news-section {
            margin-top: 28px;
        }

        .news-card-redesign {
            padding: 28px;
        }

        .aviation-news-layout {
            gap: 24px;
        }

        .aviation-news-left {
            gap: 18px;
        }

        .mini-news-card {
            min-height: 300px;
        }

        .aviation-news-slider {
            min-height: 610px;
        }

        .running-bar {
            box-shadow: 0 12px 26px rgba(0,0,0,.18);
        }

        .footer {
            backdrop-filter: blur(14px);
        }

        /* Desktop: sidebar menu tetap kuat, tapi lebih clean */
        @media (min-width: 1290px) {
            .hero-mini {
                grid-template-columns: minmax(0, 1.35fr) minmax(380px, .75fr);
            }

            .metrics-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        /* Laptop medium: jangan dipaksa terlalu banyak kolom */
        @media (max-width: 1440px) and (min-width: 992px) {
            .content {
                padding-left: 30px;
                padding-right: 30px;
            }

            .hero-mini {
                grid-template-columns: 1fr;
            }

            .hero-card p {
                max-width: 88%;
            }

            .status-chart-wrap {
                height: 250px;
            }

            .chart-card {
                min-height: 500px;
            }
        }

        /* Tablet & mobile: sederhanakan flow agar user tidak bingung */
        @media (max-width: 991px) {
            body {
                background:
                    radial-gradient(circle at top left, rgba(79, 140, 255, 0.18), transparent 28%),
                    linear-gradient(145deg, #06101f 0%, #0a1830 62%, #102343 100%);
            }

            .sidebar {
                width: min(86vw, 320px);
                padding: 14px 12px 22px;
            }

            .menu-toggle {
                min-height: 52px;
                margin-top: 8px;
                padding: 13px 14px;
                font-size: 13px;
                border-radius: 18px;
            }

            .submenu {
                gap: 6px;
                padding: 8px 4px 0 8px;
            }

            .submenu li a {
                min-height: 44px;
                padding: 11px 12px;
                font-size: 12.5px;
            }

            .content {
                max-width: none;
                padding-left: 18px;
                padding-right: 18px;
            }

            .hero-mini,
            .metrics-grid,
            .charts-grid,
            .aviation-news-layout {
                gap: 18px;
            }

            .hero-card,
            .status-card,
            .metric-card,
            .chart-card,
            .news-card {
                border-radius: 22px;
            }

            .hero-card,
            .status-card,
            .metric-card,
            .chart-card,
            .news-card-redesign {
                padding: 20px;
            }

            .hero-card p {
                max-width: 100%;
            }

            .hero-actions {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }

            .hero-btn {
                justify-content: center;
                text-align: center;
                padding: 12px 10px;
                font-size: 12.5px;
            }

            .hero-activity-viewport,
            .wo-realisasi-viewport {
                height: 230px;
            }

            .metrics-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .metric-card {
                min-height: 150px;
                padding: 18px;
            }

            .metric-icon {
                width: 50px;
                height: 50px;
                border-radius: 17px;
                font-size: 21px;
            }

            .metric-card .metric-value {
                font-size: 30px;
            }

            .chart-card {
                min-height: 430px;
            }

            .chart-body {
                min-height: 300px;
            }

            .news-section {
                margin-top: 20px;
            }

            .aviation-news-slider {
                min-height: 470px;
            }
        }

        /* Mobile phone: tampil seperti aplikasi, bukan dashboard desktop dipaksa kecil */
        @media (max-width: 640px) {
            :root {
                --footer-height: 42px;
            }

            body::before,
            body::after {
                display: none;
            }

            .topbar {
                position: fixed;
                height: 72px;
                padding: 10px 12px;
                flex-direction: row;
                align-items: center;
                gap: 10px;
            }

            .topbar-left {
                width: auto;
                flex: 1;
                min-width: 0;
                justify-content: flex-start;
            }

            .topbar-right {
                width: auto;
                flex-shrink: 0;
                gap: 8px;
            }

            .toggle-sidebar {
                width: 44px;
                height: 44px;
                border-radius: 16px;
                flex-shrink: 0;
            }

            .page-head {
                min-width: 0;
            }

            .page-head h2 {
                font-size: 17px;
                line-height: 1.2;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .page-head p {
                display: none;
            }

            .header-profile {
                width: 38px;
                height: 38px;
            }

            .notif-btn {
                width: 42px;
                height: 42px;
                border-radius: 15px;
                font-size: 16px;
            }

            .notif-badge {
                top: -5px;
                right: -4px;
                min-width: 20px;
                height: 20px;
                font-size: 10px;
            }

            .running-bar {
                top: 72px;
                height: 34px;
                padding: 0 10px;
                gap: 8px;
            }

            .running-label {
                font-size: 9.5px;
                padding: 5px 8px;
            }

            .marquee {
                font-size: 11px;
                animation-duration: 26s;
            }

            .content {
                padding: 122px 12px calc(var(--footer-height) + 18px);
            }

            .hero-mini {
                gap: 14px;
                margin-bottom: 18px;
            }

            .hero-top {
                align-items: flex-start;
                gap: 10px;
                margin-bottom: 12px;
            }

            .hero-tag,
            .hero-live {
                font-size: 10.5px;
            }

            .hero-card h3 {
                font-size: 18px;
            }

            .hero-card p {
                font-size: 12px;
                line-height: 1.6;
            }

            .hero-actions {
                grid-template-columns: 1fr;
            }

            .hero-btn {
                width: 100%;
                min-height: 46px;
                border-radius: 16px;
            }

            .hero-activity-head,
            .wo-realisasi-head {
                align-items: flex-start;
                gap: 8px;
            }

            .hero-activity-head h4,
            .wo-realisasi-head h4 {
                font-size: 13px;
                line-height: 1.35;
            }

            .hero-activity-head span,
            .wo-realisasi-head span {
                font-size: 10px;
                padding: 6px 8px;
            }

            .hero-activity-viewport,
            .wo-realisasi-viewport {
                height: 210px;
                border-radius: 20px;
            }

            .hero-activity-content,
            .wo-realisasi-content {
                padding: 16px;
            }

            .hero-activity-top,
            .wo-realisasi-top {
                margin-bottom: 8px;
            }

            .hero-activity-source,
            .wo-realisasi-source {
                max-width: 52%;
            }

            .hero-activity-content h3,
            .wo-realisasi-content h3 {
                font-size: 18px;
                -webkit-line-clamp: 2;
            }

            .hero-activity-content p,
            .wo-realisasi-content p {
                font-size: 11.5px;
                -webkit-line-clamp: 2;
            }

            .hero-activity-bottom,
            .wo-realisasi-bottom {
                font-size: 10.5px;
            }

            .hero-activity-bottom strong,
            .wo-realisasi-bottom strong {
                padding: 8px 10px;
            }

            .sales-summary-grid,
            .metrics-grid {
                grid-template-columns: 1fr;
            }

            .metric-card {
                min-height: auto;
                display: grid;
                grid-template-columns: auto 1fr;
                gap: 12px 14px;
                align-items: center;
            }

            .metric-top {
                grid-row: span 2;
                margin: 0;
            }

            .metric-card h4,
            .metric-card .metric-value,
            .metric-card p {
                margin: 0;
            }

            .metric-card p {
                font-size: 12px;
            }

            .status-main strong {
                font-size: 22px;
            }

            .status-chart-wrap {
                height: 210px;
            }

            .chart-card {
                min-height: 360px;
            }

            .chart-head {
                align-items: flex-start;
                flex-direction: column;
                gap: 10px;
                margin-bottom: 14px;
            }

            .chart-title h3 {
                font-size: 15px;
            }

            .chart-title p {
                font-size: 12px;
            }

            .chart-body {
                min-height: 250px;
            }

            .news-card-redesign {
                padding: 16px;
            }

            .news-head {
                gap: 8px;
                margin-bottom: 14px;
            }

            .news-title h3 {
                font-size: 16px;
            }

            .news-title p {
                font-size: 12px;
            }

            .news-chip {
                font-size: 10px;
                padding: 7px 10px;
            }

            .aviation-news-left {
                display: none;
            }

            .aviation-news-slider {
                min-height: 390px;
                border-radius: 20px;
            }

            .featured-news-content {
                padding: 16px;
            }

            .featured-news-top {
                gap: 8px;
                margin-bottom: 10px;
            }

            .featured-badge {
                font-size: 10px;
                padding: 7px 9px;
            }

            .featured-news-content h3 {
                font-size: 19px;
                line-height: 1.25;
                margin-bottom: 9px;
            }

            .featured-news-content p {
                font-size: 12px;
                line-height: 1.6;
                -webkit-line-clamp: 2;
                margin-bottom: 12px;
            }

            .featured-news-bottom strong {
                width: 100%;
                justify-content: center;
                border-radius: 16px;
            }

            .notif-dropdown {
                position: fixed;
                top: 82px;
                left: 12px;
                right: 12px;
                width: auto;
                max-height: calc(100vh - 112px);
            }

            .notif-list {
                max-height: calc(100vh - 190px);
            }

            .footer {
                height: var(--footer-height);
                font-size: 10px;
                padding: 0 8px;
            }

            .footer-left {
                display: none;
            }

            .footer div:last-child {
                width: 100%;
                text-align: center;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
        }

        /* Very small mobile */
        @media (max-width: 380px) {
            .page-head h2 {
                font-size: 15px;
            }

            .header-profile {
                display: none;
            }

            .content {
                padding-left: 10px;
                padding-right: 10px;
            }

            .hero-card,
            .status-card,
            .metric-card,
            .chart-card,
            .news-card-redesign {
                padding: 15px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
                scroll-behavior: auto !important;
                transition-duration: .01ms !important;
            }
        }


        /* ===== WEB PUSH NOTIFICATION BUTTON ===== */
        .push-enable-btn {
            position: relative;
            min-width: 46px;
            height: 46px;
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.10);
            background: linear-gradient(135deg, rgba(18, 196, 131, .22), rgba(25, 199, 255, .14));
            color: #fff;
            font-size: 16px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 0 13px;
            transition: var(--transition);
            box-shadow: var(--shadow-soft);
            flex-shrink: 0;
            white-space: nowrap;
        }

        .push-enable-btn:hover {
            transform: translateY(-2px);
            background: linear-gradient(135deg, rgba(18, 196, 131, .30), rgba(25, 199, 255, .20));
        }

        .push-enable-btn.active {
            background: linear-gradient(135deg, rgba(18, 196, 131, .96), rgba(25, 199, 255, .88));
            box-shadow: 0 12px 24px rgba(18, 196, 131, .18);
        }

        .push-enable-btn.warning {
            background: linear-gradient(135deg, rgba(255, 176, 32, .90), rgba(255, 95, 109, .76));
        }

        .push-enable-btn span {
            font-size: 12px;
            font-weight: 900;
            letter-spacing: .2px;
            white-space: nowrap;
        }


        @media (max-width: 1180px) {
            .push-enable-btn {
                width: 46px;
                min-width: 46px;
                padding: 0;
            }

            .push-enable-btn span {
                display: none;
            }
        }

        @media (max-width: 640px) {
            .push-enable-btn {
                width: 42px;
                min-width: 42px;
                height: 42px;
                border-radius: 15px;
                padding: 0;
                font-size: 15px;
            }

            .push-enable-btn span {
                display: none;
            }
        }


        .topbar-right,
        .header-actions,
        .navbar-actions {
            min-width: 0;
        }

        .notif-wrap,
        .notification-wrap {
            flex-shrink: 0;
        }



        /* =========================================================
           THEME DASHBOARD: DARK / LIGHT BIRU CERAH
           Menu pengaturan: System & Pengaturan > Tema Dashboard
        ========================================================= */
        body.theme-light {
            --bg-1: #eaf5ff;
            --bg-2: #dcebff;
            --bg-3: #f8fbff;
            --panel-border: rgba(37, 99, 235, 0.16);
            --panel-bg: rgba(255, 255, 255, 0.82);
            --panel-bg-strong: rgba(255, 255, 255, 0.96);
            --text: #0f2238;
            --muted: #5c708a;
            --primary: #1677ff;
            --primary-2: #38bdf8;
            --cyan: #0ea5e9;
            --shadow: 0 18px 45px rgba(37, 99, 235, 0.14);
            --shadow-soft: 0 12px 26px rgba(37, 99, 235, 0.10);
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(59, 130, 246, 0.18), transparent 30%),
                radial-gradient(circle at top right, rgba(14, 165, 233, 0.16), transparent 26%),
                linear-gradient(135deg, var(--bg-1) 0%, var(--bg-2) 46%, var(--bg-3) 100%);
        }

        body.theme-light .sidebar,
        body.theme-light .topbar,
        body.theme-light .ticker,
        body.theme-light .hero-card,
        body.theme-light .status-card,
        body.theme-light .metric-card,
        body.theme-light .chart-card,
        body.theme-light .news-card,
        body.theme-light .news-card-redesign,
        body.theme-light .mini-news-card,
        body.theme-light .sales-mini-box,
        body.theme-light footer {
            background: rgba(255, 255, 255, 0.82) !important;
            border-color: rgba(37, 99, 235, 0.15) !important;
            color: var(--text) !important;
            box-shadow: var(--shadow-soft);
        }

        body.theme-light .sidebar {
            background: linear-gradient(180deg, rgba(255,255,255,.94), rgba(232,244,255,.92)) !important;
            border-right-color: rgba(37, 99, 235, 0.16) !important;
        }

        body.theme-light .hero-card {
            background:
                radial-gradient(circle at 88% 10%, rgba(37, 99, 235, 0.16), transparent 32%),
                radial-gradient(circle at 8% 92%, rgba(14, 165, 233, 0.15), transparent 34%),
                linear-gradient(135deg, rgba(255,255,255,.94), rgba(232,244,255,.91)) !important;
            border-color: rgba(37, 99, 235, 0.18) !important;
            box-shadow: 0 18px 45px rgba(37, 99, 235, 0.14) !important;
        }

        body.theme-light .page-head h2,
        body.theme-light .hero-card h1,
        body.theme-light .hero-card h2,
        body.theme-light .hero-card h3,
        body.theme-light .status-card h3,
        body.theme-light .metric-card h3,
        body.theme-light .metric-value,
        body.theme-light .chart-title,
        body.theme-light .chart-card h3,
        body.theme-light .news-title,
        body.theme-light .news-card h3,
        body.theme-light .desktop-menu-title,
        body.theme-light .notif-head h4,
        body.theme-light .profile-popover h4 {
            color: #0f2238 !important;
        }

        body.theme-light .page-head p,
        body.theme-light .quick-date,
        body.theme-light .status-card p,
        body.theme-light .metric-card p,
        body.theme-light .news-card p,
        body.theme-light .mini-news-meta,
        body.theme-light .chart-chip,
        body.theme-light .status-foot,
        body.theme-light .hero-tag,
        body.theme-light .profile-popover span,
        body.theme-light .profile-popover-info {
            color: #5c708a !important;
        }

        body.theme-light .menu-toggle {
            color: #102033 !important;
            background: rgba(255, 255, 255, 0.82) !important;
            border-color: rgba(37, 99, 235, 0.13) !important;
        }

        body.theme-light .menu-toggle:hover,
        body.theme-light .menu-toggle.active {
            color: #0f3b74 !important;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.18), rgba(14, 165, 233, 0.13)) !important;
            box-shadow: 0 10px 24px rgba(37, 99, 235, 0.13) !important;
        }

        body.theme-light .submenu li a {
            color: #475569 !important;
        }

        body.theme-light .submenu li a:hover,
        body.theme-light .submenu li a.active-link {
            color: #0f3b74 !important;
            background: rgba(59, 130, 246, 0.12) !important;
            border-left-color: #1677ff !important;
        }

        body.theme-light .quick-date,
        body.theme-light .notif-btn,
        body.theme-light .toggle-sidebar,
        body.theme-light .header-profile {
            background: rgba(255,255,255,.86) !important;
            border-color: rgba(37,99,235,.16) !important;
            color: #0f2238 !important;
        }

        body.theme-light .ticker .marquee,
        body.theme-light .running-bar,
        body.theme-light .running-label,
        body.theme-light .notif-content p,
        body.theme-light .notif-content span {
            color: #0f2238 !important;
        }

        body.theme-light .hero-btn,
        body.theme-light .metric-badge,
        body.theme-light .notif-badge {
            color: #fff !important;
        }

        body.theme-light .empty-state,
        body.theme-light .notif-dropdown,
        body.theme-light .desktop-menu-card,
        body.theme-light .profile-popover {
            background: rgba(255, 255, 255, 0.96) !important;
            border-color: rgba(37,99,235,.16) !important;
            color: #0f2238 !important;
            box-shadow: 0 24px 60px rgba(37,99,235,.18) !important;
        }

        /* SweetAlert tema dashboard */
        .theme-swal-popup {
            border-radius: 28px !important;
            padding: 26px !important;
            background: linear-gradient(180deg, rgba(15,23,42,.98), rgba(13,26,55,.98)) !important;
            border: 1px solid rgba(148, 163, 184, .22) !important;
            box-shadow: 0 28px 80px rgba(0,0,0,.44) !important;
            color: #eaf2ff !important;
        }

        .theme-swal-title {
            color: #f8fbff !important;
            font-size: 26px !important;
            font-weight: 900 !important;
            letter-spacing: -.03em !important;
            margin-bottom: 6px !important;
        }

        .theme-choice-wrap {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin-top: 16px;
        }

        .theme-choice-card {
            border: 1px solid rgba(148, 163, 184, .24);
            background: rgba(255,255,255,.065);
            border-radius: 22px;
            padding: 18px;
            text-align: left;
            color: #eaf2ff;
            cursor: pointer;
            transition: all .22s ease;
            min-height: 124px;
        }

        .theme-choice-card:hover,
        .theme-choice-card.active {
            transform: translateY(-2px);
            border-color: rgba(96, 165, 250, .68);
            background: rgba(59,130,246,.15);
            box-shadow: 0 18px 42px rgba(59,130,246,.20);
        }

        .theme-choice-icon {
            width: 44px;
            height: 44px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
            color: #fff;
            background: linear-gradient(135deg, #4f46e5, #06b6d4);
        }

        .theme-choice-card.light .theme-choice-icon {
            background: linear-gradient(135deg, #0ea5e9, #60a5fa);
        }

        .theme-choice-card h4 {
            margin: 0;
            color: #f8fbff;
            font-size: 15px;
            font-weight: 900;
        }

        .theme-choice-card p {
            margin: 6px 0 0;
            color: #aab8d3;
            font-size: 12px;
            line-height: 1.45;
        }

        @media (max-width: 520px) {
            .theme-choice-wrap { grid-template-columns: 1fr; }
        }


        /* SweetAlert bahasa sidebar */
        .language-swal-popup {
            border-radius: 28px !important;
            padding: 26px !important;
            background: linear-gradient(180deg, rgba(15,23,42,.98), rgba(10,17,33,.98)) !important;
            border: 1px solid rgba(148,163,184,.22) !important;
            color: #f8fbff !important;
            box-shadow: 0 30px 90px rgba(2,6,23,.55) !important;
        }

        .language-swal-title {
            color: #f8fbff !important;
            font-size: 24px !important;
            font-weight: 900 !important;
        }

        .language-choice-wrap {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin-top: 12px;
        }

        .language-choice-card {
            border: 1px solid rgba(148,163,184,.22);
            background: rgba(15,23,42,.72);
            border-radius: 20px;
            padding: 18px;
            text-align: left;
            cursor: pointer;
            color: #f8fbff;
            transition: .22s ease;
            width: 100%;
        }

        .language-choice-card:hover,
        .language-choice-card.active {
            transform: translateY(-3px);
            border-color: rgba(56,189,248,.72);
            box-shadow: 0 16px 40px rgba(56,189,248,.18);
            background: rgba(30,41,59,.9);
        }

        .language-choice-icon {
            width: 42px;
            height: 42px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            background: linear-gradient(135deg, #2563eb, #06b6d4);
            margin-bottom: 12px;
            font-size: 18px;
        }

        .language-choice-card.english .language-choice-icon {
            background: linear-gradient(135deg, #7c3aed, #38bdf8);
        }

        .language-choice-card h4 {
            margin: 0;
            color: #f8fbff;
            font-size: 15px;
            font-weight: 900;
        }

        .language-choice-card p {
            margin: 6px 0 0;
            color: #aab8d3;
            font-size: 12px;
            line-height: 1.45;
        }

        @media (max-width: 520px) {
            .language-choice-wrap { grid-template-columns: 1fr; }
        }

        /* Popover profil kanan atas */
        .profile-wrap {
            position: relative;
            flex-shrink: 0;
        }

        .header-profile {
            display: block;
            padding: 0;
            cursor: pointer;
            background: rgba(255,255,255,.06);
        }

        .profile-popover {
            position: absolute;
            right: 0;
            top: calc(100% + 14px);
            width: min(310px, calc(100vw - 32px));
            padding: 18px;
            border-radius: 24px;
            background: linear-gradient(180deg, rgba(15,23,42,.98), rgba(13,26,55,.98));
            border: 1px solid rgba(148, 163, 184, .20);
            box-shadow: 0 28px 70px rgba(0,0,0,.38);
            opacity: 0;
            pointer-events: none;
            transform: translateY(10px) scale(.98);
            transition: opacity .22s ease, transform .22s ease;
            z-index: 1200;
        }

        .profile-popover.show {
            opacity: 1;
            pointer-events: auto;
            transform: translateY(0) scale(1);
        }

        .profile-popover::before {
            content: "";
            position: absolute;
            top: -8px;
            right: 18px;
            width: 16px;
            height: 16px;
            background: inherit;
            border-left: 1px solid rgba(148,163,184,.20);
            border-top: 1px solid rgba(148,163,184,.20);
            transform: rotate(45deg);
        }

        .profile-popover-head {
            display: flex;
            align-items: center;
            gap: 13px;
        }

        .profile-popover-head img {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid rgba(96,165,250,.34);
            box-shadow: 0 12px 26px rgba(79,140,255,.24);
        }

        .profile-popover-head h4 {
            margin: 0 0 4px;
            color: #f8fbff;
            font-size: 16px;
            line-height: 1.2;
            font-weight: 900;
        }

        .profile-popover-head span {
            display: inline-flex;
            padding: 5px 9px;
            border-radius: 999px;
            background: rgba(79,140,255,.16);
            color: #b9d5ff;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .profile-popover-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 16px;
            padding: 12px 13px;
            border-radius: 16px;
            background: rgba(255,255,255,.065);
            color: #dbeafe;
            font-size: 13px;
            word-break: break-all;
        }

        .profile-popover-info i {
            color: #38bdf8;
        }

        .profile-popover-action {
            margin-top: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            width: 100%;
            padding: 12px 14px;
            border-radius: 16px;
            color: #fff;
            text-decoration: none;
            font-size: 13px;
            font-weight: 900;
            background: linear-gradient(135deg, #4f8cff, #19c7ff);
            box-shadow: 0 14px 30px rgba(25,199,255,.18);
            transition: all .22s ease;
        }

        .profile-popover-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 36px rgba(25,199,255,.24);
        }

        body.theme-light .status-card *,
        body.theme-light .metric-card *,
        body.theme-light .chart-card *,
        body.theme-light .news-card *,
        body.theme-light .news-card-redesign *,
        body.theme-light .mini-news-card *,
        body.theme-light .sales-mini-box *,
        body.theme-light footer * {
            color: #0f2238 !important;
        }

        body.theme-light .status-card p,
        body.theme-light .metric-card p,
        body.theme-light .chart-chip,
        body.theme-light .mini-news-meta,
        body.theme-light .footer-left span,
        body.theme-light .news-label,
        body.theme-light .featured-source {
            color: #5c708a !important;
        }

        body.theme-light .hero-btn,
        body.theme-light .hero-btn *,
        body.theme-light .metric-icon,
        body.theme-light .metric-icon *,
        body.theme-light .metric-badge,
        body.theme-light .metric-badge *,
        body.theme-light .profile-popover-action,
        body.theme-light .profile-popover-action *,
        body.theme-light .notif-badge,
        body.theme-light .notif-badge * {
            color: #fff !important;
        }

        body.theme-light .hero-activity-slide,
        body.theme-light .wo-realisasi-slide,
        body.theme-light .featured-news-slide {
            border-color: rgba(37,99,235,.15) !important;
            box-shadow: var(--shadow-soft) !important;
        }

        /* ===== ONE BELL MODE =====
           Satu lonceng untuk notif internal dashboard + aktivasi push notification.
           Tombol push lama disembunyikan agar tidak double.
        */
        .push-enable-btn {
            display: none !important;
        }

        .notif-btn.push-ready {
            background: linear-gradient(135deg, rgba(18, 196, 131, .18), rgba(25, 199, 255, .10));
            border-color: rgba(18, 196, 131, .20);
        }

        /* ===== RBAC POPUP ACCESS LOCK ===== */
        .locked-link,
        .locked-parent {
            opacity: .78;
        }

        .submenu a.locked-menu,
        .menu-toggle.locked-menu {
            cursor: not-allowed;
            position: relative;
        }

        .submenu a.locked-menu {
            border: 1px solid rgba(248, 113, 113, .16);
            background: rgba(127, 29, 29, .12);
        }

        .submenu a.locked-menu:hover,
        .menu-toggle.locked-menu:hover {
            color: #fecaca;
            border-color: rgba(248, 113, 113, .35);
        }

        .locked-indicator {
            margin-left: auto;
            font-size: 11px;
            color: #fca5a5;
            opacity: .95;
        }

        body.theme-light .submenu a.locked-menu {
            background: #fff1f2;
            border-color: #fecdd3;
            color: #991b1b;
        }

        body.theme-light .submenu a.locked-menu:hover,
        body.theme-light .menu-toggle.locked-menu:hover {
            color: #be123c;
        }

        body.theme-light .locked-indicator {
            color: #e11d48;
        }

        .swal2-popup.rbac-access-popup {
            border-radius: 24px !important;
            padding: 0 !important;
            overflow: hidden !important;
        }

        .rbac-access-box {
            padding: 28px 26px 24px;
            text-align: center;
        }

        .rbac-access-icon {
            width: 76px;
            height: 76px;
            margin: 0 auto 16px;
            border-radius: 24px;
            display: grid;
            place-items: center;
            color: #fff;
            font-size: 30px;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            box-shadow: 0 18px 42px rgba(37, 99, 235, .32);
        }

        .rbac-access-title {
            font-size: 20px;
            font-weight: 900;
            color: #0f172a;
            margin-bottom: 8px;
        }

        .rbac-access-text {
            font-size: 14px;
            color: #475569;
            line-height: 1.6;
            margin: 0;
        }

        .rbac-access-menu {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 14px;
            padding: 9px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            color: #1d4ed8;
            background: #eff6ff;
            border: 1px solid #dbeafe;
        }


        /* ===== RBAC READ ONLY FINAL OVERRIDE =====
           Menu read-only tetap terlihat normal. Class locked-menu hanya dipakai JS untuk memunculkan pop up.
        */
        .locked-link,
        .locked-parent {
            opacity: 1 !important;
        }

        .submenu a.locked-menu,
        .menu-toggle.locked-menu {
            cursor: pointer !important;
            opacity: 1 !important;
        }

        .submenu a.locked-menu,
        body.theme-light .submenu a.locked-menu {
            background: transparent !important;
            border-color: transparent !important;
            color: inherit !important;
        }

        .submenu a.locked-menu:hover,
        .menu-toggle.locked-menu:hover,
        body.theme-light .submenu a.locked-menu:hover,
        body.theme-light .menu-toggle.locked-menu:hover {
            color: inherit !important;
        }

        .locked-indicator {
            display: none !important;
        }

        /* Pop up akses dibuat lebih jelas dan kontras */
        .swal2-popup.rbac-access-popup {
            background: linear-gradient(145deg, #08111f 0%, #111c35 100%) !important;
            color: #f8fafc !important;
            border: 1px solid rgba(96, 165, 250, .22) !important;
            box-shadow: 0 28px 90px rgba(0, 0, 0, .48) !important;
        }

        .swal2-popup.rbac-access-popup .swal2-html-container {
            margin: 0 !important;
            color: #f8fafc !important;
        }

        .rbac-access-title {
            color: #ffffff !important;
            font-size: 22px !important;
            letter-spacing: .2px;
        }

        .rbac-access-text {
            color: #dbeafe !important;
            font-size: 15px !important;
            font-weight: 600 !important;
        }

        .rbac-access-menu {
            color: #bfdbfe !important;
            background: rgba(37, 99, 235, .18) !important;
            border-color: rgba(147, 197, 253, .24) !important;
        }

        .swal2-popup.rbac-access-popup .swal2-confirm {
            border-radius: 12px !important;
            font-weight: 800 !important;
            padding: 11px 24px !important;
            box-shadow: 0 14px 35px rgba(37, 99, 235, .32) !important;
        }



        /* ============================================================
           FINAL POLISH OVERRIDE - running bar, footer, chart right
           Dipasang di paling bawah agar tidak ketimpa style lama.
        ============================================================ */
        .running-bar {
            background: linear-gradient(90deg, rgba(9, 22, 43, 0.92), rgba(16, 31, 61, 0.88), rgba(27, 45, 92, 0.86)) !important;
            border-top: 1px solid rgba(148, 163, 184, 0.06) !important;
            border-bottom: 1px solid rgba(96, 165, 250, 0.16) !important;
            box-shadow: 0 14px 34px rgba(2, 8, 23, 0.16) !important;
            backdrop-filter: blur(18px) saturate(140%) !important;
            -webkit-backdrop-filter: blur(18px) saturate(140%) !important;
            color: #dcecff !important;
        }

        .running-label {
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.28), rgba(99, 102, 241, 0.25)) !important;
            color: #dff6ff !important;
            border: 1px solid rgba(125, 211, 252, 0.22) !important;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.10), 0 8px 22px rgba(14, 165, 233, 0.12) !important;
        }

        .marquee {
            color: #c9dbf7 !important;
            font-weight: 700 !important;
            letter-spacing: .02em !important;
            opacity: .96 !important;
            text-shadow: 0 1px 10px rgba(15, 23, 42, 0.28) !important;
        }

        .footer {
            height: 38px !important;
            background: linear-gradient(90deg, rgba(4, 12, 26, 0.88), rgba(8, 20, 39, 0.78), rgba(13, 25, 48, 0.82)) !important;
            border-top: 1px solid rgba(96, 165, 250, 0.13) !important;
            color: #96aac7 !important;
            box-shadow: 0 -14px 34px rgba(2, 8, 23, 0.22) !important;
            backdrop-filter: blur(16px) saturate(130%) !important;
            -webkit-backdrop-filter: blur(16px) saturate(130%) !important;
            font-size: 11.5px !important;
        }

        .footer-left span,
        .footer-right span,
        .footer * {
            color: #9fb2cf !important;
        }

        .footer-right {
            display: inline-flex !important;
            align-items: center !important;
            gap: 10px !important;
            opacity: .92 !important;
        }

        .footer-dot-sep {
            width: 4px !important;
            height: 4px !important;
            border-radius: 999px !important;
            background: rgba(125, 211, 252, 0.42) !important;
            display: inline-block !important;
        }

        .status-card {
            background:
                radial-gradient(circle at 92% 4%, rgba(129, 140, 248, 0.28), transparent 34%),
                radial-gradient(circle at 6% 94%, rgba(56, 189, 248, 0.12), transparent 34%),
                linear-gradient(135deg, rgba(18, 34, 64, 0.96), rgba(28, 37, 80, 0.94) 58%, rgba(38, 43, 92, 0.92)) !important;
            border: 1px solid rgba(148, 184, 255, 0.16) !important;
            box-shadow: 0 24px 70px rgba(15, 23, 42, 0.30), 0 18px 42px rgba(29, 78, 216, 0.12) !important;
        }

        .status-card::before {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.10), rgba(255, 255, 255, 0.025)) !important;
            opacity: .75 !important;
        }

        .status-title {
            color: #9bdcff !important;
            letter-spacing: .09em !important;
        }

        .status-month {
            background: rgba(255, 255, 255, 0.075) !important;
            border: 1px solid rgba(148, 184, 255, 0.11) !important;
            color: #dbeafe !important;
        }

        .sales-total-wrap span {
            color: #aebeda !important;
        }

        .sales-mini-box {
            background: rgba(255, 255, 255, 0.055) !important;
            border: 1px solid rgba(177, 205, 255, 0.10) !important;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.035) !important;
        }

        .sales-mini-box .label {
            color: #a8b9d6 !important;
        }

        .sales-mini-box .value,
        .sales-sensitive-value {
            color: #edf5ff !important;
        }

        .sales-mini-box .value.ach-good { color: #9ff6c5 !important; }
        .sales-mini-box .value.ach-bad { color: #ffb4b4 !important; }

        .status-chart-wrap {
            margin-top: 14px !important;
            height: 230px !important;
            padding: 4px 0 0 !important;
        }

        .toggle-sensitive-btn {
            background: rgba(255, 255, 255, 0.075) !important;
            border: 1px solid rgba(186, 210, 255, 0.16) !important;
            color: #e7f0ff !important;
            box-shadow: 0 12px 28px rgba(2, 8, 23, 0.20), inset 0 1px 0 rgba(255,255,255,.05) !important;
        }

        .toggle-sensitive-btn:hover {
            background: rgba(96, 165, 250, 0.18) !important;
            border-color: rgba(125, 211, 252, 0.34) !important;
        }

        body.theme-light .running-bar {
            background: linear-gradient(90deg, rgba(235, 248, 255, .96), rgba(224, 242, 254, .92), rgba(219, 234, 254, .94)) !important;
            border-bottom: 1px solid rgba(37, 99, 235, 0.14) !important;
            color: #102033 !important;
            box-shadow: 0 12px 28px rgba(37, 99, 235, .10) !important;
        }

        body.theme-light .running-label {
            background: linear-gradient(135deg, #2563eb, #0ea5e9) !important;
            color: #ffffff !important;
            border-color: rgba(255,255,255,.36) !important;
        }

        body.theme-light .marquee {
            color: #25405e !important;
            text-shadow: none !important;
        }

        body.theme-light .footer {
            background: rgba(248, 251, 255, 0.90) !important;
            border-top: 1px solid rgba(37, 99, 235, 0.13) !important;
            box-shadow: 0 -12px 28px rgba(37, 99, 235, .10) !important;
        }

        body.theme-light .footer-left span,
        body.theme-light .footer-right span,
        body.theme-light .footer * {
            color: #53657e !important;
        }

        body.theme-light .footer-dot-sep {
            background: rgba(37, 99, 235, 0.38) !important;
        }

        body.theme-light .status-card {
            background:
                radial-gradient(circle at 92% 4%, rgba(37, 99, 235, 0.14), transparent 34%),
                radial-gradient(circle at 6% 94%, rgba(14, 165, 233, 0.13), transparent 34%),
                linear-gradient(135deg, rgba(255, 255, 255, 0.96), rgba(232, 244, 255, 0.94)) !important;
            border-color: rgba(37, 99, 235, 0.16) !important;
            box-shadow: 0 22px 55px rgba(37, 99, 235, 0.13) !important;
        }

        body.theme-light .status-title,
        body.theme-light .status-month,
        body.theme-light .sales-total-wrap span,
        body.theme-light .sales-mini-box .label,
        body.theme-light .sales-mini-box .value,
        body.theme-light .sales-sensitive-value {
            color: #0f2238 !important;
        }

        body.theme-light .sales-mini-box {
            background: rgba(255,255,255,.72) !important;
            border-color: rgba(37,99,235,.12) !important;
        }

        @media (max-width: 768px) {
            .footer { height: 42px !important; }
            .status-chart-wrap { height: 220px !important; }
        }


        /* ============================================================
           FINAL CARD TONE MATCH - hero kiri disamakan dengan chart kanan
           Dipasang paling akhir supaya tone card kiri tidak beda jauh.
        ============================================================ */
        .hero-card {
            background:
                radial-gradient(circle at 92% 4%, rgba(129, 140, 248, 0.27), transparent 34%),
                radial-gradient(circle at 7% 94%, rgba(56, 189, 248, 0.13), transparent 36%),
                linear-gradient(135deg, rgba(18, 34, 64, 0.96), rgba(28, 37, 80, 0.94) 58%, rgba(38, 43, 92, 0.92)) !important;
            border: 1px solid rgba(148, 184, 255, 0.16) !important;
            box-shadow: 0 24px 70px rgba(15, 23, 42, 0.30), 0 18px 42px rgba(29, 78, 216, 0.12) !important;
        }

        .hero-card::before {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.10), rgba(255, 255, 255, 0.025)) !important;
            opacity: .75 !important;
        }

        .hero-card::after {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.08), transparent 30%, rgba(255, 255, 255, 0.025)) !important;
            opacity: .70 !important;
        }

        .hero-tag {
            background: rgba(255, 255, 255, 0.075) !important;
            border: 1px solid rgba(148, 184, 255, 0.11) !important;
            color: #dbeafe !important;
        }

        .hero-live {
            color: #dbeafe !important;
        }

        .hero-card h3 {
            color: #f4f8ff !important;
        }

        .hero-card p {
            color: #b9c8e2 !important;
        }

        body.theme-light .hero-card {
            background:
                radial-gradient(circle at 92% 4%, rgba(37, 99, 235, 0.14), transparent 34%),
                radial-gradient(circle at 6% 94%, rgba(14, 165, 233, 0.13), transparent 34%),
                linear-gradient(135deg, rgba(255, 255, 255, 0.96), rgba(232, 244, 255, 0.94)) !important;
            border-color: rgba(37, 99, 235, 0.16) !important;
            box-shadow: 0 22px 55px rgba(37, 99, 235, 0.13) !important;
        }

        body.theme-light .hero-tag {
            background: rgba(255,255,255,.72) !important;
            border-color: rgba(37,99,235,.12) !important;
            color: #0f2238 !important;
        }

        body.theme-light .hero-live,
        body.theme-light .hero-card h3,
        body.theme-light .hero-card p {
            color: #0f2238 !important;
        }

    
/* RBAC element read-only state */
.locked-element {
    cursor: pointer !important;
}
.is-readonly-element {
    position: relative;
}
.is-readonly-element::after {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
    border-radius: inherit;
    box-shadow: inset 0 0 0 1px rgba(96, 165, 250, .10);
}


/* RBAC notification read-only tetap terlihat normal */
.notif-item.locked-element {
    cursor: pointer !important;
    opacity: 1 !important;
}
.notif-item.locked-element:hover {
    background: rgba(255, 255, 255, 0.06);
    transform: translateX(3px);
}


/* PWA install button */
.pwa-install-btn {
    position: fixed;
    right: 18px;
    bottom: calc(18px + env(safe-area-inset-bottom, 0px));
    z-index: 9995;
    display: none;
    align-items: center;
    gap: 9px;
    min-height: 44px;
    padding: 11px 15px;
    border: 1px solid rgba(255,255,255,.16);
    border-radius: 999px;
    background: rgba(6,16,31,.94);
    color: #fff;
    box-shadow: 0 14px 35px rgba(0,0,0,.32);
    font: 700 13px/1 Inter, sans-serif;
    cursor: pointer;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
}
.pwa-install-btn.show { display: inline-flex; }
.pwa-install-btn:hover { transform: translateY(-1px); }
.pwa-install-btn i { font-size: 15px; }
@media (max-width: 640px) {
    .pwa-install-btn {
        right: 12px;
        bottom: calc(12px + env(safe-area-inset-bottom, 0px));
        padding: 10px 13px;
    }
}

</style>
</head>
<body>
<button type="button" class="pwa-install-btn" id="pwaInstallBtn" aria-label="Install Web Portal Minimarket">
    <i class="fa-solid fa-arrow-down-to-bracket"></i>
    <span>Install Aplikasi</span>
</button>
<div class="app-shell">
    <div class="mobile-overlay" id="mobileOverlay"></div>

    <div class="desktop-menu-modal" id="desktopMenuModal" aria-hidden="true">
        <div class="desktop-menu-card" role="dialog" aria-modal="true" aria-labelledby="desktopMenuTitle">
            <div class="desktop-menu-head">
                <div class="desktop-menu-icon" id="desktopMenuIcon"><i class="fa fa-layer-group"></i></div>
                <div class="desktop-menu-title">
                    <small>Menu Portal</small>
                    <strong id="desktopMenuTitle">Menu</strong>
                </div>
                <button type="button" class="desktop-menu-close" id="desktopMenuClose" aria-label="Tutup menu">
                    <i class="fa fa-xmark"></i>
                </button>
            </div>
            <div class="desktop-menu-body">
                <div class="desktop-menu-grid" id="desktopMenuGrid"></div>
            </div>
        </div>
    </div>

    <?php /* RBAC_HIDE_ONLY_V5_RENDER_START */ ?>
    <!-- RBAC_HIDE_ONLY_V5_ACTIVE user=<?= e($username) ?> configured=<?= rbacV5UserConfigured($conn, $username) ? '1' : '0' ?> -->
    <?php rbacV5RenderSidebar($rbacV5SidebarTree, $currentPage); ?>

    <div class="main-area" id="mainArea">
        <header class="topbar" id="topbar">
            <div class="topbar-left">
                <button class="toggle-sidebar" id="toggleSidebar" type="button" aria-label="Toggle Sidebar">
                    <i class="fa fa-bars"></i>
                </button>
                <div class="page-head">
                    <h2>Web Portal Minimarket</h2>
                    <p>Dashboard Internal Divisi Retail Minimarket</p>
                </div>
            </div>

            <div class="topbar-right">
                <div class="quick-date">
                    <i class="fa fa-calendar-days"></i>
                    <span><?= e(date('d F Y')) ?></span>
                </div>

                <div class="profile-wrap" id="profileWrap">
                    <button class="header-profile" id="profileBtn" type="button" aria-label="Profil pengguna">
                        <img src="<?= e($userPhoto) ?>" alt="User Photo" loading="lazy" decoding="async">
                    </button>

                    <div class="profile-popover" id="profilePopover" aria-hidden="true">
                        <div class="profile-popover-head">
                            <img src="<?= e($userPhoto) ?>" alt="Foto profil <?= e($namaLengkap) ?>" loading="lazy" decoding="async">
                            <div>
                                <h4><?= e($namaLengkap) ?></h4>
                                <span><?= e($userJabatan) ?></span>
                            </div>
                        </div>
                        <div class="profile-popover-info">
                            <i class="fa-solid fa-envelope"></i>
                            <span><?= e($userEmail) ?></span>
                        </div>
                        <a class="profile-popover-action" href="setting-akun.php">
                            <i class="fa-solid fa-user-gear"></i>
                            <span>Kelola Akun</span>
                        </a>
                    </div>
                </div>
<div class="notif-wrap">
                    <button class="notif-btn" id="notifBtn" type="button">
                        <i class="fa fa-bell"></i>
                        <span class="notif-badge" id="notifCount"><?= count($notifAll) ?></span>
                    </button>

                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-head">
                            <h4>Notifikasi Terbaru</h4>
                            <span id="notifSubtitle"><?= count($notifAll) ?> item</span>
                        </div>
                        <div class="notif-list" id="notifList">
                            <?php if (count($notifAll) > 0): ?>
                                <?php foreach ($notifAll as $item): ?>
                                    <?php
                                        $notifType = strtolower(trim((string)($item['type'] ?? 'aktivitas')));
                                        $notifCanOpen = dashRbacNotifCanOpen($conn, $username, $notifType);
                                        $notifTitle = $notifType === 'kegiatan' ? 'Daftar Kegiatan' : 'Daftar Aktivitas Hari Ini';
                                        $notifHref = $notifCanOpen ? ($item['url'] ?? '#') : '#';
                                    ?>
                                    <a
                                        class="notif-item<?= $notifCanOpen ? '' : ' locked-element' ?>"
                                        href="#"
                                        data-url="<?= e($notifHref) ?>"
                                        data-can-open="<?= $notifCanOpen ? '1' : '0' ?>"
                                        data-menu-title="<?= e($notifTitle) ?>"
                                        data-title-id="<?= e($notifTitle) ?>"
                                        data-title-en="<?= e($notifType === 'kegiatan' ? 'Activity List' : 'Today Activity List') ?>"
                                        data-notif-type="<?= e($notifType) ?>"
                                        data-locked="<?= $notifCanOpen ? '0' : '1' ?>"
                                        aria-disabled="<?= $notifCanOpen ? 'false' : 'true' ?>"
                                    >
                                        <div class="notif-icon">
                                            <i class="fa <?= $notifType === 'kegiatan' ? 'fa-list-check' : 'fa-store' ?>"></i>
                                        </div>
                                        <div class="notif-content">
                                            <small><?= e($notifType) ?></small>
                                            <p><?= e($item['text']) ?></p>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="notif-empty">Belum ada notifikasi terbaru.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <div class="running-bar" id="runningBar">
    <span class="running-label">LIVE INFO</span>
    <div class="marquee">
        Selamat datang <?= e($namaLengkap) ?> di WebPortal Minimarket SRT ~ Dashboard terpusat untuk monitoring operasional, penjualan, administrasi, inventori, dan integrasi data store.
    </div>
</div>        <main class="content">
            <!-- DASHBOARD ORDER FINAL:
                 1) Card box
                 2) Grafik Sales Keseluruhan
                 3) Hero Informasi berisi Aktivitas Visit + Kegiatan slider
                 4) Chart donut
                 5) Berita
            -->

            <section class="metrics-grid">
                <?php if (dashRbacElCanView($conn, $username, 'dashboard.metric_daftar_aktivitas_hari_ini')): ?>
                <?php $metricAktCanOpen = dashRbacElCanOpen($conn, $username, 'dashboard.metric_daftar_aktivitas_hari_ini'); ?>

                <a href="<?= $metricAktCanOpen ? 'data_aktivitas.php' : '#' ?>" class="metric-card<?= $metricAktCanOpen ? '' : ' locked-element' ?>" data-menu-title="Daftar Aktivitas Hari Ini" data-title-id="Daftar Aktivitas Hari Ini" data-title-en="Today Activity List">
                    <div class="metric-top">
                        <div class="metric-icon bg-blue"><i class="fa fa-store"></i></div>
                        <span class="metric-badge">Aktivitas</span>
                    </div>
                    <h4>Daftar Aktivitas Hari Ini</h4>
                    <div class="metric-value" data-counter="<?= $countAktivitas ?>"><?= $countAktivitas ?></div>
                    <p>Pantau inspeksi dan aktivitas yang masuk hari ini secara cepat dan terstruktur.</p>
                </a>

                <?php endif; ?>

                <?php if (dashRbacElCanView($conn, $username, 'dashboard.metric_daftar_kegiatan')): ?>
                <?php $metricKegCanOpen = dashRbacElCanOpen($conn, $username, 'dashboard.metric_daftar_kegiatan'); ?>


                <a href="<?= $metricKegCanOpen ? 'add_kegiatan.php' : '#' ?>" class="metric-card<?= $metricKegCanOpen ? '' : ' locked-element' ?>" data-menu-title="Daftar Kegiatan" data-title-id="Daftar Kegiatan" data-title-en="Activity List">
                    <div class="metric-top">
                        <div class="metric-icon bg-orange"><i class="fa fa-list-check"></i></div>
                        <span class="metric-badge">Kegiatan</span>
                    </div>
                    <h4>Daftar Kegiatan</h4>
                    <div class="metric-value" data-counter="<?= $countKegiatan ?>"><?= $countKegiatan ?></div>
                    <p>Pantau kegiatan supervisor dan crew leader.</p>
                </a>


                <?php endif; ?>

                <?php if (dashRbacElCanView($conn, $username, 'dashboard.metric_jumlah_karyawan')): ?>
                <?php $metricKaryawanCanOpen = dashRbacElCanOpen($conn, $username, 'dashboard.metric_jumlah_karyawan'); ?>


                <a href="<?= $metricKaryawanCanOpen ? 'karyawan.php' : '#' ?>" class="metric-card<?= $metricKaryawanCanOpen ? '' : ' locked-element' ?>" data-menu-title="Jumlah Karyawan" data-title-id="Jumlah Karyawan" data-title-en="Employee Count">
                    <div class="metric-top">
                        <div class="metric-icon bg-green"><i class="fa fa-users"></i></div>
                        <span class="metric-badge">SDM</span>
                    </div>
                    <h4>Jumlah Karyawan</h4>
                    <div class="metric-value" data-counter="<?= $countKaryawan ?>"><?= $countKaryawan ?></div>
                    <p>Data total karyawan yang aktif</p>
                    <p>( Leader & Crew )</p>
                </a>


                <?php endif; ?>

                <?php if (dashRbacElCanView($conn, $username, 'dashboard.metric_status_akun')): ?>
                <?php $metricAkunCanOpen = dashRbacElCanOpen($conn, $username, 'dashboard.metric_status_akun'); ?>


                <a href="<?= $metricAkunCanOpen ? 'my_account.php' : '#' ?>" class="metric-card<?= $metricAkunCanOpen ? '' : ' locked-element' ?>" data-menu-title="Status Akun" data-title-id="Status Akun" data-title-en="Account Status">
                    <div class="metric-top">
                        <div class="metric-icon bg-purple"><i class="fa fa-user-shield"></i></div>
                        <span class="metric-badge">Akun</span>
                    </div>
                    <h4>Status Akun</h4>
                    <div class="metric-value" style="font-size:22px;"><?= e(ucfirst($roleUser)) ?></div>
                    <p>Kelola profil, hak akses, dan pengaturan akun.</p>
                </a>


                <?php endif; ?>
            </section>

            <section class="dashboard-sales-overview">
                <?php if (dashRbacElCanView($conn, $username, 'dashboard.card_grafik_sales')): ?>
                <?php $salesChartCanOpen = dashRbacElCanOpen($conn, $username, 'dashboard.card_grafik_sales'); ?>
                <div class="status-card<?= $salesChartCanOpen ? '' : ' locked-element is-readonly-element' ?>" data-menu-title="Grafik Sales Keseluruhan" data-title-id="Grafik Sales Keseluruhan" data-title-en="Overall Sales Chart" aria-disabled="<?= $salesChartCanOpen ? 'false' : 'true' ?>">
                    <div class="status-title">Grafik Sales Keseluruhan</div>
                    <div class="status-month"><i class="fa fa-calendar"></i> <?= e($bulanTampil) ?></div>

                    <div class="status-main">
                        <div class="sales-total-wrap">
    <div class="sales-total-row">
        <strong
            id="totalOmsetValue"
            class="sensitive-value sales-sensitive-value"
            data-real="<?= e('Rp ' . number_format($totalSalesKeseluruhan, 0, ',', '.')) ?>"
            data-mask="Rp ***"
        >Rp ******</strong>

        <button
            type="button"
            id="toggleOmsetBtn"
            class="toggle-sensitive-btn"
            aria-label="Tampilkan/Sembunyikan total omset"
            aria-pressed="false"
            title="Hide / Unhide Omset"
        >
            <i id="toggleOmsetIcon" class="fa fa-eye"></i>
        </button>
    </div>

    <span><?= e($salesCardSubtitle) ?></span>
</div>

                        <div class="sales-summary-grid">
                            <div class="sales-mini-box">
                                <div class="label">Rata-rata</div>
                                <div class="value sales-sensitive-value" data-real="<?= e('Rp ' . number_format($rataRataSales, 0, ',', '.')) ?>" data-mask="Rp ***">Rp ***</div>
                            </div>
                            <div class="sales-mini-box">
                                <div class="label">Target</div>
                                <div class="value sales-sensitive-value" data-real="<?= e('Rp ' . number_format($totalTargetBulanan, 0, ',', '.')) ?>" data-mask="Rp ***">Rp ***</div>
                            </div>
                            <div class="sales-mini-box">
                                <div class="label">Achievement</div>
                                <div class="value sales-sensitive-value <?= $achievementSales >= 100 ? 'ach-good' : 'ach-bad' ?>" data-real="<?= e(number_format($achievementSales, 1, ',', '.')) ?>%" data-mask="***">***</div>
                            </div>
                        </div>

                        <?php if (!empty($salesLabels)): ?>
                            <div class="status-chart-wrap">
                                <canvas id="salesOverallChart"></canvas>
                            </div>
                        <?php else: ?>
                            <div class="status-foot">Belum ada data sales untuk ditampilkan.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </section>

            <section class="hero-mini dashboard-hero-info-only">
                <div class="hero-card">
                    <div class="hero-top">
                        <span class="hero-tag"><i class="fa fa-sparkles"></i> <?= e($heroData['badge_text']) ?></span>
                        <span class="hero-live"><span class="hero-live-dot"></span> Live Monitoring</span>
                    </div>
                    <h3><?= e($heroData['title']) ?></h3>
                    <p><?= nl2br(e($heroData['message'])) ?></p>

                    <?php
                    $heroImagePath = trim((string)($heroData['image_path'] ?? ''));
                    $heroHasImage = $heroImagePath !== '' && isDashImageLink($heroImagePath);
                    ?>
                    <?php if ($heroHasImage): ?>
                        <div class="hero-info-photo-wrap">
                            <button type="button" class="hero-info-photo-btn" data-hero-photo-open data-photo-src="<?= e($heroImagePath) ?>" aria-label="Lihat foto informasi">
                                <img src="<?= e($heroImagePath) ?>" alt="Foto Informasi Dashboard">
                                <span class="hero-info-photo-caption">
                                    <span><i class="fa fa-image"></i> Foto Informasi</span>
                                    <span>Klik untuk perbesar <i class="fa fa-up-right-and-down-left-from-center"></i></span>
                                </span>
                            </button>
                        </div>
                    <?php endif; ?>

<div class="hero-actions">
    <?php
    $heroButtons = [
        [
            'text' => $heroData['button1_text'] ?? '',
            'link' => $heroData['button1_link'] ?? '',
            'class' => 'primary',
            'icon' => 'fa-store'
        ],
        [
            'text' => $heroData['button2_text'] ?? '',
            'link' => $heroData['button2_link'] ?? '',
            'class' => 'secondary',
            'icon' => 'fa-list-check'
        ],
        [
            'text' => $heroData['button3_text'] ?? '',
            'link' => $heroData['button3_link'] ?? '',
            'class' => 'secondary',
            'icon' => 'fa-user-check'
        ],
        [
            'text' => $heroData['button4_text'] ?? '',
            'link' => $heroData['button4_link'] ?? '',
            'class' => 'secondary',
            'icon' => 'fa-calendar-days'
        ],
    ];
    ?>

    <?php foreach ($heroButtons as $heroButton): ?>
        <?php
        $btnText = trim((string)($heroButton['text'] ?? ''));
        $btnLink = trim((string)($heroButton['link'] ?? ''));
        if ($btnText === '' || $btnLink === '') {
            continue;
        }
        if (!canShowHeroButton($btnText, $btnLink, $canSeeLaporKehadiran, $canSeeMonitoringKehadiran)) {
            continue;
        }

        $rbacHeroButtonKey = dashRbacHeroButtonElementKey($btnText, $btnLink);
        if ($rbacHeroButtonKey !== '' && !dashRbacElCanView($conn, $username, $rbacHeroButtonKey)) {
            continue;
        }
        $heroCanOpen = $rbacHeroButtonKey === '' ? true : dashRbacElCanOpen($conn, $username, $rbacHeroButtonKey);
        $heroHref = $heroCanOpen ? $btnLink : '#';
        $heroLockedClass = $heroCanOpen ? '' : ' locked-element';
        ?>
        <a href="<?= e($heroHref) ?>" class="hero-btn <?= e($heroButton['class']) ?><?= e($heroLockedClass) ?>" data-menu-title="<?= e($btnText) ?>" data-title-id="<?= e($btnText) ?>" data-title-en="<?= e($btnText) ?>">
            <i class="fa <?= e($heroButton['icon']) ?>"></i> <?= e($btnText) ?>
        </a>
    <?php endforeach; ?>
</div>

                    <?php if (dashRbacElCanView($conn, $username, 'dashboard.card_aktivitas_visit_hari_ini')): ?>
                    <?php $heroActivityCanOpen = dashRbacElCanOpen($conn, $username, 'dashboard.card_aktivitas_visit_hari_ini'); ?>
                    <div class="hero-activity-slider<?= $heroActivityCanOpen ? '' : ' is-readonly-element' ?>" id="heroActivitySlider">
                        <div class="hero-activity-head">
                            <h4><i class="fa fa-store"></i> Aktivitas Visit Hari Ini</h4>
                            <span><?= count($aktivitasSlides) ?> data</span>
                        </div>

                        <?php if (empty($aktivitasSlides)): ?>
                            <div class="hero-activity-empty">
                                <div>
                                    <strong>Belum ada aktivitas visit</strong><br>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="hero-activity-viewport">
                                <div class="hero-activity-track">
                                    <?php foreach ($aktivitasSlides as $index => $slide): ?>
                                        <?php
                                        $namaSlide = cleanDashValue($slide['NAMA'] ?? '', 'User');
                                        $tanggalSlide = cleanDashValue($slide['TANGGAL'] ?? '', '-');
                                        $jamSlide = cleanDashValue($slide['JAM'] ?? '', '-');
                                        $hariSlide = cleanDashValue($slide['HARI'] ?? '', '');
                                        $outletSlide = cleanDashValue($slide['OUTLET'] ?? '', 'Outlet Visit');
                                        $areaSlide = cleanDashValue($slide['AREA_TERMINAL'] ?? '', '');
                                        $crewSlide = cleanDashValue($slide['CREW'] ?? '', '');
                                        $temuanSlide = cleanDashValue($slide['TEMUAN'] ?? '', 'Tidak Ada');
                                        $omsetSlide = formatDashRupiah($slide['OMSET_KEMARIN'] ?? '');
                                        $fotoSlide = trim((string)($slide['FOTO_DEPAN'] ?? ''));
                                        $hasFotoSlide = isDashImageLink($fotoSlide);
                                        $isTemuanAda = stripos($temuanSlide, 'ada') !== false && stripos($temuanSlide, 'tidak') === false;

                                        $descParts = [];
                                        if ($areaSlide !== '') $descParts[] = $areaSlide;
                                        if ($crewSlide !== '') $descParts[] = 'Crew: ' . $crewSlide;
                                        if ($omsetSlide !== '') $descParts[] = 'Omset kemarin: ' . $omsetSlide;
                                        $descSlide = !empty($descParts) ? implode('  ', $descParts) : 'Klik untuk melihat detail aktivitas visit.';
                                        ?>
                                        <a
                                            class="hero-activity-slide <?= $index === 0 ? 'active' : '' ?><?= $heroActivityCanOpen ? '' : ' locked-element' ?>"
                                            href="<?= $heroActivityCanOpen ? 'data_aktivitas.php' : '#' ?>"
                                            data-slide="<?= (int)$index ?>"
                                            data-menu-title="Aktivitas Visit Hari Ini"
                                            data-title-id="Aktivitas Visit Hari Ini"
                                            data-title-en="Today Visit Activity"
                                            aria-disabled="<?= $heroActivityCanOpen ? 'false' : 'true' ?>"
                                        >
                                            <div class="hero-activity-bg">
                                                <?php if ($hasFotoSlide): ?>
                                                    <img src="<?= e($fotoSlide) ?>" alt="Foto outlet <?= e($outletSlide) ?>" loading="<?= $index === 0 ? 'eager' : 'lazy' ?>">
                                                <?php else: ?>
                                                    <div class="hero-activity-placeholder"><i class="fa fa-store"></i></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="hero-activity-overlay"></div>

                                            <div class="hero-activity-content">
                                                <div class="hero-activity-top">
                                                    <span class="hero-activity-badge <?= $isTemuanAda ? 'danger' : '' ?>">
                                                        <i class="fa fa-bolt"></i> <?= $isTemuanAda ? 'Ada Temuan' : 'Visit' ?>
                                                    </span>
                                                    <span class="hero-activity-source"><i class="fa fa-rss"></i> <?= e($namaSlide) ?></span>
                                                </div>

                                                <h3><?= e($outletSlide) ?></h3>
                                                <p><?= e($descSlide) ?></p>

                                                <div class="hero-activity-bottom">
                                                    <span><i class="fa fa-clock"></i> <?= e(trim($tanggalSlide . ($hariSlide !== '' ? '  ' . $hariSlide : '') . ' ' . $jamSlide)) ?></span>
                                                    <strong>Lihat detail <i class="fa fa-arrow-right"></i></strong>
                                                </div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>

                                <?php if (count($aktivitasSlides) > 1): ?>
                                    <div class="hero-activity-dots">
                                        <?php foreach ($aktivitasSlides as $index => $slide): ?>
                                            <button
                                                type="button"
                                                class="hero-activity-dot <?= $index === 0 ? 'active' : '' ?>"
                                                data-hero-activity-target="<?= (int)$index ?>"
                                                aria-label="Aktivitas slide <?= (int)$index + 1 ?>"
                                            ></button>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php endif; ?>

                    <?php if (dashRbacElCanView($conn, $username, 'dashboard.card_realisasi_work_order')): ?>
                    <?php $woRealisasiCanOpen = dashRbacElCanOpen($conn, $username, 'dashboard.card_realisasi_work_order'); ?>
                    <div class="wo-realisasi-slider<?= $woRealisasiCanOpen ? '' : ' is-readonly-element' ?>" id="woRealisasiSlider">
                        <div class="wo-realisasi-head">
                            <h4><i class="fa fa-list-check"></i> Kegiatan Hari Ini</h4>
                            <span><?= (int)$countKegiatan ?> kegiatan / <?= count($workOrderRealisasiSlides) ?> user</span>
                        </div>

                        <?php if (empty($workOrderRealisasiSlides)): ?>
                            <div class="wo-realisasi-empty">
                                <div>
                                    <strong>Belum ada kegiatan hari ini</strong><br>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="wo-realisasi-viewport">
                                <div class="wo-realisasi-track">
                                    <?php foreach ($workOrderRealisasiSlides as $index => $wo): ?>
                                        <?php
                                        $woTitle = cleanDashValue($wo['title'] ?? '', 'Kegiatan Hari Ini');
                                        $woDesc = cleanDashValue($wo['description'] ?? '', 'Telah melakukan kegiatan hari ini.');
                                        $woImage = trim((string)($wo['image_path'] ?? ''));
                                        $woCreator = cleanDashValue($wo['created_by'] ?? '', 'User');
                                        $woCreatedAtRaw = $wo['created_at'] ?? '';
                                        $woCreatedAt = $woCreatedAtRaw !== '' ? date('d M Y H:i', strtotime($woCreatedAtRaw)) : date('d M Y H:i');
                                        ?>
                                        <a
                                            class="wo-realisasi-slide <?= $index === 0 ? 'active' : '' ?><?= $woRealisasiCanOpen ? '' : ' locked-element' ?>"
                                            href="<?= ($woRealisasiCanOpen && $woImage !== '') ? e($woImage) : '#' ?>"
                                            data-slide="<?= (int)$index ?>"
                                            data-title="<?= e($woTitle) ?>"
                                            data-desc="<?= e($woDesc) ?>"
                                            data-image="<?= e($woImage) ?>"
                                            data-date="<?= e($woCreatedAt) ?>"
                                            data-creator="<?= e($woCreator) ?>"
                                            data-menu-title="Kegiatan Hari Ini"
                                            data-title-id="Kegiatan Hari Ini"
                                            data-title-en="Today's Activity"
                                            aria-disabled="<?= $woRealisasiCanOpen ? 'false' : 'true' ?>"
                                        >
                                            <div class="wo-realisasi-bg">
                                                <?php if ($woImage !== ''): ?>
                                                    <img src="<?= e($woImage) ?>" alt="<?= e($woTitle) ?>" loading="<?= $index === 0 ? 'eager' : 'lazy' ?>">
                                                <?php else: ?>
                                                    <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,rgba(37,99,235,.45),rgba(16,185,129,.28));font-size:52px;color:rgba(255,255,255,.75);">
                                                        <i class="fa fa-list-check"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="wo-realisasi-overlay"></div>

                                            <div class="wo-realisasi-content">
                                                <div class="wo-realisasi-top">
                                                    <span class="wo-realisasi-badge"><i class="fa fa-bolt"></i> <?= (int)($wo['qty'] ?? 1) ?>x Kegiatan</span>
                                                    <span class="wo-realisasi-source"><i class="fa fa-user"></i> <?= e($woCreator) ?></span>
                                                </div>

                                                <h3><?= e($woTitle) ?></h3>
                                                <p><?= e($woDesc) ?></p>

                                                <div class="wo-realisasi-bottom">
                                                    <span><i class="fa fa-clock"></i> <?= e($woCreatedAt) ?> WIB</span>
                                                    <?php if ($woImage !== ''): ?>
                                                        <strong>Lihat foto <i class="fa fa-expand"></i></strong>
                                                    <?php else: ?>
                                                        <strong>Detail kegiatan <i class="fa fa-arrow-right"></i></strong>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>

                                <?php if (count($workOrderRealisasiSlides) > 1): ?>
                                    <div class="wo-realisasi-dots">
                                        <?php foreach ($workOrderRealisasiSlides as $index => $wo): ?>
                                            <button
                                                type="button"
                                                class="wo-realisasi-dot <?= $index === 0 ? 'active' : '' ?>"
                                                data-wo-target="<?= (int)$index ?>"
                                                aria-label="Kegiatan slide <?= (int)$index + 1 ?>"
                                            ></button>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

            

            <?php $namaBulan = date('F Y'); ?>
            <section class="charts-grid">
                <?php if (dashRbacElCanView($conn, $username, 'dashboard.chart_aktivitas_periode')): ?>
                <?php $chartAktivitasCanOpen = dashRbacElCanOpen($conn, $username, 'dashboard.chart_aktivitas_periode'); ?>
                <div class="chart-card<?= $chartAktivitasCanOpen ? '' : ' locked-element is-readonly-element' ?>" data-menu-title="Aktivitas Periode" data-title-id="Aktivitas Periode" data-title-en="Period Activity Chart" aria-disabled="<?= $chartAktivitasCanOpen ? 'false' : 'true' ?>">
                    <div class="chart-head">
                        <div class="chart-title">
                            <h3>Aktivitas Periode <?= e($namaBulan) ?></h3>
                            <p>Distribusi aktivitas berdasarkan nama pada bulan berjalan.</p>
                        </div>
                        <span class="chart-chip">Chart 1</span>
                    </div>
                    <?php if (count($labels) === 0): ?>
                        <div class="empty-state">Belum ada data aktivitas untuk ditampilkan pada bulan ini.</div>
                    <?php else: ?>
                        <div class="chart-body"><canvas id="visitChart"></canvas></div>
                    <?php endif; ?>
                </div>

                <?php endif; ?>

                <?php if (dashRbacElCanView($conn, $username, 'dashboard.chart_kegiatan_periode')): ?>
                <?php $chartKegiatanCanOpen = dashRbacElCanOpen($conn, $username, 'dashboard.chart_kegiatan_periode'); ?>
                <div class="chart-card<?= $chartKegiatanCanOpen ? '' : ' locked-element is-readonly-element' ?>" data-menu-title="Kegiatan Periode" data-title-id="Kegiatan Periode" data-title-en="Period Operation Chart" aria-disabled="<?= $chartKegiatanCanOpen ? 'false' : 'true' ?>">
                    <div class="chart-head">
                        <div class="chart-title">
                            <h3>Kegiatan Periode <?= e($namaBulan) ?></h3>
                            <p>Distribusi kegiatan tambahan berdasarkan nama pada bulan berjalan.</p>
                        </div>
                        <span class="chart-chip">Chart 2</span>
                    </div>
                    <?php if (count($labels2) === 0): ?>
                        <div class="empty-state">Belum ada data kegiatan tambahan untuk ditampilkan pada bulan ini.</div>
                    <?php else: ?>
                        <div class="chart-body"><canvas id="kegiatanChart"></canvas></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </section>

            <?php if (dashRbacElCanView($conn, $username, 'dashboard.card_berita_bandara_penerbangan')): ?>
            <?php $newsCardCanOpen = dashRbacElCanOpen($conn, $username, 'dashboard.card_berita_bandara_penerbangan'); ?>
            <section class="news-section">
                <div class="news-card news-card-redesign<?= $newsCardCanOpen ? '' : ' locked-element is-readonly-element' ?>" data-menu-title="Berita Bandara & Penerbangan" data-title-id="Berita Bandara & Penerbangan" data-title-en="Airport & Aviation News" aria-disabled="<?= $newsCardCanOpen ? 'false' : 'true' ?>">
                    <div class="news-head">
                        <div class="news-title">
                            <h3><i class="fa fa-newspaper"></i> Berita Bandara & Penerbangan</h3>
                           
                        </div>
                        <span class="news-chip"><i class="fa fa-plane-departure"></i> Aviation Update</span>
                    </div>

                    <div class="empty-state" id="aviationNewsLoading">Memuat berita bandara & penerbangan setelah dashboard utama tampil...</div>
                    <div id="aviationNewsDynamic"></div>
                </div>
            </section>
            <?php endif; ?>
        </main>
    </div>


    <div class="wo-lightbox" id="woLightbox" aria-hidden="true">
        <div class="wo-lightbox-card" role="dialog" aria-modal="true" aria-label="Preview kegiatan hari ini">
            <div class="wo-lightbox-image-wrap">
                <img id="woLightboxImage" src="" alt="Kegiatan Hari Ini">
                <button type="button" class="wo-lightbox-close" id="woLightboxClose" aria-label="Tutup preview"><i class="fa fa-xmark"></i></button>
                <button type="button" class="wo-lightbox-nav wo-lightbox-prev" id="woLightboxPrev" aria-label="Foto sebelumnya"><i class="fa fa-chevron-left"></i></button>
                <button type="button" class="wo-lightbox-nav wo-lightbox-next" id="woLightboxNext" aria-label="Foto berikutnya"><i class="fa fa-chevron-right"></i></button>
            </div>
            <div class="wo-lightbox-info">
                <div class="wo-lightbox-info-top">
                    <span class="wo-lightbox-badge"><i class="fa fa-list-check"></i> Kegiatan Hari Ini</span>
                    <span class="wo-lightbox-counter" id="woLightboxCounter">1 / 1</span>
                </div>
                <h3 id="woLightboxTitle">Kegiatan Hari Ini</h3>
                <p id="woLightboxDesc"></p>
                <div class="wo-lightbox-meta">
                    <span><i class="fa fa-clock"></i> <span id="woLightboxDate"></span> WIB</span>
                    <span><i class="fa fa-user"></i> <span id="woLightboxCreator"></span></span>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer">
        <div class="footer-left" id="footerLeft">Divisi Minimarket</div>
        <div>&copy; 2025 SRTCorporationgroup. All Rights Reserved.</div>
    </footer>
</div>

<script>
    Chart.register(ChartDataLabels);

    const toggleSidebarBtn = document.getElementById('toggleSidebar');
    const sidebar = document.getElementById('sidebar');
    const topbar = document.getElementById('topbar');
    const runningBar = document.getElementById('runningBar');
    const mainArea = document.getElementById('mainArea');
    const footerLeft = document.getElementById('footerLeft');
    const notifBtn = document.getElementById('notifBtn');
    const notifDropdown = document.getElementById('notifDropdown');
    const notifCount = document.getElementById('notifCount');
    const notifList = document.getElementById('notifList');
    const notifSubtitle = document.getElementById('notifSubtitle');
    const mobileOverlay = document.getElementById('mobileOverlay');
    const desktopMenuModal = document.getElementById('desktopMenuModal');
    const desktopMenuClose = document.getElementById('desktopMenuClose');
    const desktopMenuTitle = document.getElementById('desktopMenuTitle');
    const desktopMenuIcon = document.getElementById('desktopMenuIcon');
    const desktopMenuGrid = document.getElementById('desktopMenuGrid');
    const totalOmsetValue = document.getElementById('totalOmsetValue');
    const toggleOmsetBtn = document.getElementById('toggleOmsetBtn');
    const toggleOmsetIcon = document.getElementById('toggleOmsetIcon');

    let lastNotificationKey = localStorage.getItem('dashboard_last_notification_key') || '';
    let lastPopupId = localStorage.getItem('dashboard_last_popup_id') || '';
    const dashboardAjaxUrl = <?= json_encode(basename($_SERVER['PHP_SELF'])) ?>;
    const dashboardNotifElementAccess = {
        aktivitas: <?= dashRbacNotifCanOpen($conn, $username, 'aktivitas') ? 'true' : 'false' ?>,
        kegiatan: <?= dashRbacNotifCanOpen($conn, $username, 'kegiatan') ? 'true' : 'false' ?>
    };

    const pushEnableBtn = document.getElementById('pushEnableBtn') || document.querySelector('.notif-btn');
    const pushSwUrl = '/sw.js';
    let pushPublicKey = '';

    // PWA: register service worker independently from Web Push support.
    // This keeps installability working on Android, iOS/iPadOS and desktop.
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(pushSwUrl, { scope: '/' }).catch(function (err) {
                console.warn('PWA service worker gagal didaftarkan:', err);
            });
        });
    }

    // PWA install UX. Chromium gets the native prompt; iOS gets Add to Home Screen guidance.
    const pwaInstallBtn = document.getElementById('pwaInstallBtn');
    let deferredPwaInstallPrompt = null;

    function webportalIsStandalonePwa() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    }

    function webportalIsIOS() {
        return /iphone|ipad|ipod/i.test(window.navigator.userAgent || '');
    }

    function updatePwaInstallButton() {
        if (!pwaInstallBtn) return;
        if (webportalIsStandalonePwa()) {
            pwaInstallBtn.classList.remove('show');
            return;
        }
        if (deferredPwaInstallPrompt || webportalIsIOS()) {
            pwaInstallBtn.classList.add('show');
        } else {
            pwaInstallBtn.classList.remove('show');
        }
    }

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredPwaInstallPrompt = event;
        updatePwaInstallButton();
    });

    window.addEventListener('appinstalled', function () {
        deferredPwaInstallPrompt = null;
        updatePwaInstallButton();
    });

    if (pwaInstallBtn) {
        pwaInstallBtn.addEventListener('click', async function () {
            if (deferredPwaInstallPrompt) {
                deferredPwaInstallPrompt.prompt();
                try {
                    await deferredPwaInstallPrompt.userChoice;
                } catch (e) {}
                deferredPwaInstallPrompt = null;
                updatePwaInstallButton();
                return;
            }

            if (webportalIsIOS()) {
                Swal.fire({
                    icon: 'info',
                    title: 'Install di iPhone / iPad',
                    html: 'Buka menu <b>Share</b> di Safari, lalu pilih <b>Add to Home Screen</b> / <b>Tambahkan ke Layar Utama</b>.',
                    confirmButtonText: 'Mengerti'
                });
            }
        });
    }

    updatePwaInstallButton();



    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        return Uint8Array.from([...rawData].map((char) => char.charCodeAt(0)));
    }

    function updatePushButton(status, text) {
        const btn = pushEnableBtn || document.querySelector('.notif-btn');
        if (!btn) return;

        btn.classList.remove('active', 'warning', 'push-ready');

        if (status === 'active') {
            btn.classList.add('push-ready');
            btn.title = dashboardT('Notifikasi real-time aktif');
        } else if (status === 'warning') {
            btn.classList.add('warning');
            btn.title = dashboardT(text || 'Notifikasi real-time belum siap');
        } else {
            btn.title = dashboardT(text || 'Notifikasi');
        }
    }

    async function getPushConfig() {
        const res = await fetch(dashboardAjaxUrl + '?ajax=push_config', { cache: 'no-store' });
        const data = await res.json();
        if (!data.success || !data.configured || !data.publicKey) {
            updatePushButton('warning', data.message || 'VAPID key belum diisi');
            return null;
        }
        pushPublicKey = data.publicKey;
        return data;
    }


    async function registerPushServiceWorker() {
        const registration = await navigator.serviceWorker.register(pushSwUrl, { scope: '/' });

        // Tunggu sampai service worker benar-benar ACTIVE.
        // Ini yang mencegah error: no active Service Worker.
        const readyRegistration = await navigator.serviceWorker.ready;

        if (!readyRegistration || !readyRegistration.active) {
            throw new Error('Service Worker belum aktif. Refresh halaman lalu coba lagi.');
        }

        return readyRegistration;
    }

    async function refreshPushStatus() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
            updatePushButton('warning', 'Browser belum support Web Push');
            return;
        }

        const cfg = await getPushConfig();
        if (!cfg) return;

        try {
            const registration = await registerPushServiceWorker();
            const sub = await registration.pushManager.getSubscription();

            if (Notification.permission === 'granted' && sub) {
                updatePushButton('active');
            } else if (Notification.permission === 'denied') {
                updatePushButton('warning', 'Notifikasi diblokir di browser');
            } else {
                updatePushButton('idle');
            }
        } catch (err) {
            updatePushButton('warning', 'Service worker gagal aktif');
        }
    }

    async function enablePushNotification(silentFromFlow = false) {
        if (typeof webportalIsIOSSafariOrUnsupportedPush === 'function' && webportalIsIOSSafariOrUnsupportedPush()) return;
        if (window.webportalSkipPushPrompt) return;
        const permissionKey = 'webportal_push_permission_required_v1';

        if (localStorage.getItem(permissionKey) === 'granted' && Notification.permission === 'granted') {
            return;
        }

        if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
            localStorage.setItem(permissionKey, 'unsupported');
            Swal.fire({
                icon: 'warning',
                title: 'Belum Support',
                text: 'Browser ini belum mendukung notifikasi realtime'
            });
            return;
        }

        const cfg = await getPushConfig();
        if (!cfg) {
            Swal.fire({
                icon: 'warning',
                title: 'VAPID key belum diisi',
                html: 'Generate VAPID key dulu, lalu isi <b>PUSH_VAPID_PUBLIC_KEY</b> dan <b>PUSH_VAPID_PRIVATE_KEY</b> di file dashboard.'
            });
            return;
        }

        try {
            const ask = await Swal.fire({
                icon: 'info',
                title: 'Izinkan Notifkasi Real Time',
                confirmButtonText: 'OK',
                showCancelButton: true,
                reverseButtons: true
            });

            if (!ask.isConfirmed) {
                localStorage.setItem(permissionKey, 'later');
                return;
            }

            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                localStorage.setItem(permissionKey, 'denied');
                updatePushButton('warning', 'Izin notifikasi belum diberikan');
                Swal.fire({
                    icon: 'info',
                    title: 'Notifikasi belum aktif',
                    text: 'Izin notifikasi belum diberikan. Aktifkan izin notifikasi dari browser/setting HP.'
                });
                return;
            }

            const registration = await registerPushServiceWorker();
            let subscription = await registration.pushManager.getSubscription();

            if (!subscription) {
                subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(pushPublicKey)
                });
            }

            const saveRes = await fetch(dashboardAjaxUrl + '?ajax=save_push_subscription', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(subscription)
            });
            const saveData = await saveRes.json();

            if (!saveData.success) {
                throw new Error(saveData.message || 'Gagal menyimpan subscription');
            }

            localStorage.setItem(permissionKey, 'granted');
            updatePushButton('active');

            Swal.fire({
                icon: 'success',
                title: 'Notifikasi Real Time Berhasil',
            });
        } catch (err) {
            updatePushButton('warning', 'Gagal mengaktifkan notifikasi');
            Swal.fire({
                icon: 'error',
                title: 'Gagal aktifkan notifikasi',
                text: err.message || 'Coba refresh halaman lalu aktifkan ulang.'
            });
        }
    }

    if (pushEnableBtn) {
        pushEnableBtn.addEventListener('click', enablePushNotification);
        refreshPushStatus();
    }

    function openSidebarMobile() {
        sidebar.classList.add('show');
        if (mobileOverlay) mobileOverlay.classList.add('show');
    }
    
    function initSensitiveOmsetToggle() {
    if (!totalOmsetValue || !toggleOmsetBtn || !toggleOmsetIcon) return;

    const storageKey = 'dashboard_hide_total_omset';
    let isHidden = localStorage.getItem(storageKey);

    // default awal: hide
    if (isHidden === null) {
        isHidden = 'true';
        localStorage.setItem(storageKey, 'true');
    }

    function renderOmsetVisibility() {
        const hiddenState = localStorage.getItem(storageKey) === 'true';
        const sensitiveValues = document.querySelectorAll('.sales-sensitive-value');

        sensitiveValues.forEach((item) => {
            const realValue = item.dataset.real || item.textContent || 'Rp 0';
            const maskValue = item.dataset.mask || '***';
            item.textContent = hiddenState ? maskValue : realValue;
            item.classList.toggle('is-masked', hiddenState);
        });

        toggleOmsetIcon.className = hiddenState ? 'fa fa-eye' : 'fa fa-eye-slash';
        toggleOmsetBtn.setAttribute('aria-pressed', hiddenState ? 'false' : 'true');
        toggleOmsetBtn.setAttribute(
            'title',
            dashboardT(hiddenState
                ? 'Tampilkan Omset, rata-rata, target, dan achievement'
                : 'Sembunyikan Omset, rata-rata, target, dan achievement')
        );
    }

    toggleOmsetBtn.addEventListener('click', function () {
        const hiddenState = localStorage.getItem(storageKey) === 'true';
        localStorage.setItem(storageKey, hiddenState ? 'false' : 'true');
        renderOmsetVisibility();
    });

    renderOmsetVisibility();
}

    function closeSidebarMobile() {
        sidebar.classList.remove('show');
        if (mobileOverlay) mobileOverlay.classList.remove('show');
    }

    function toggleSidebar() {
        const isMobile = window.innerWidth <= 991;

        if (isMobile) {
            sidebar.classList.contains('show') ? closeSidebarMobile() : openSidebarMobile();
            return;
        }

        sidebar.classList.toggle('hide');
        topbar.classList.toggle('full');
        runningBar.classList.toggle('full');
        mainArea.classList.toggle('full');
        footerLeft.classList.toggle('full');
    }

    toggleSidebarBtn.addEventListener('click', toggleSidebar);

    if (mobileOverlay) {
        mobileOverlay.addEventListener('click', closeSidebarMobile);
    }

    function isDesktopMenuMode() {
        return window.innerWidth > 991;
    }

    function escapeMenuHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (s) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[s];
        });
    }

    function closeDesktopMenuModal() {
        if (!desktopMenuModal) return;
        desktopMenuModal.classList.remove('show');
        desktopMenuModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        document.querySelectorAll('.menu-toggle.desktop-popup-active').forEach(function (btn) {
            btn.classList.remove('desktop-popup-active');
        });
    }

    function openDesktopMenuModal(button) {
        const submenu = button.nextElementSibling;
        if (!submenu || !submenu.classList.contains('submenu')) return;

        const left = button.querySelector('.left');
        const title = left ? left.textContent.trim() : 'Menu';
        const icon = left ? left.querySelector('i') : null;
        const iconClass = icon ? icon.className : 'fa fa-layer-group';

        if (desktopMenuTitle) desktopMenuTitle.textContent = title;
        if (desktopMenuIcon) desktopMenuIcon.innerHTML = '<i class="' + escapeMenuHtml(iconClass) + '"></i>';

        const links = Array.from(submenu.querySelectorAll('a'));
        if (desktopMenuGrid) {
            desktopMenuGrid.innerHTML = links.map(function (link) {
                const linkIcon = link.querySelector('i');
                const linkIconClass = linkIcon ? linkIcon.className : 'fa fa-circle-dot';
                const label = link.textContent.trim();
                const href = link.getAttribute('href') || '#';
                const target = link.getAttribute('target') ? ' target="' + escapeMenuHtml(link.getAttribute('target')) + '"' : '';
                return '<a class="desktop-menu-item" href="' + escapeMenuHtml(href) + '"' + target + '>' +
                    '<i class="' + escapeMenuHtml(linkIconClass) + '"></i>' +
                    '<span>' + escapeMenuHtml(label) + '</span>' +
                    '</a>';
            }).join('');
        }

        document.querySelectorAll('.menu-toggle.desktop-popup-active').forEach(function (btn) {
            btn.classList.remove('desktop-popup-active');
        });
        button.classList.add('desktop-popup-active');

        desktopMenuModal.classList.add('show');
        desktopMenuModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    if (desktopMenuClose) {
        desktopMenuClose.addEventListener('click', closeDesktopMenuModal);
    }

    if (desktopMenuModal) {
        desktopMenuModal.addEventListener('click', function (e) {
            if (e.target === desktopMenuModal) closeDesktopMenuModal();
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeDesktopMenuModal();
    });

    document.querySelectorAll('.menu-toggle').forEach((button) => {
        button.addEventListener('click', () => {
            const submenu = button.nextElementSibling;

            // Kalau menu tidak punya submenu, biarkan onclick/href jalan normal.
            if (!submenu || !submenu.classList.contains('submenu')) return;

            // Mode enterprise: submenu selalu turun berderet di bawah menu utama,
            // baik desktop maupun mobile. Tidak pakai pop-up modal.
            button.classList.toggle('active');
            submenu.classList.toggle('show');
        });
    });

    window.addEventListener('resize', function () {
        closeDesktopMenuModal();
    });

    document.querySelectorAll('.submenu a').forEach((link) => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 991) closeSidebarMobile();
        });
    });

    function applyDashboardTheme(theme) {
        const selectedTheme = theme === 'light' ? 'light' : 'dark';
        document.body.classList.toggle('theme-light', selectedTheme === 'light');
        localStorage.setItem('dashboard_theme', selectedTheme);
    }

    function openDashboardThemeSelector() {
        const currentTheme = localStorage.getItem('dashboard_theme') || 'dark';
        Swal.fire({
            title: 'Tema Dashboard',
            html: `
                <div class="theme-choice-wrap">
                    <button type="button" class="theme-choice-card dark" data-theme="dark">
                        <span class="theme-choice-icon"><i class="fa-solid fa-moon"></i></span>
                        <h4>Dark / Navy</h4>
                        <p>Tampilan profesional.</p>
                    </button>
                    <button type="button" class="theme-choice-card light" data-theme="light">
                        <span class="theme-choice-icon"><i class="fa-solid fa-sun"></i></span>
                        <h4>Light / Biru</h4>
                        <p>Tampilan cerah dan fresh.</p>
                    </button>
                </div>
            `,
            showConfirmButton: false,
            showCancelButton: true,
            cancelButtonText: 'Tutup',
            customClass: {
                popup: 'theme-swal-popup',
                title: 'theme-swal-title'
            },
            didOpen: function() {
                const cards = Swal.getPopup().querySelectorAll('.theme-choice-card');
                cards.forEach(function(card) {
                    if (card.dataset.theme === currentTheme) card.classList.add('active');
                    card.addEventListener('click', function() {
                        applyDashboardTheme(card.dataset.theme);
                        Swal.close();
                    });
                });
            }
        });
    }

    applyDashboardTheme(localStorage.getItem('dashboard_theme') || 'dark');

    document.querySelectorAll('a[href="#theme-toggle"]').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            openDashboardThemeSelector();
        });
    });

    const dashboardLanguageDictionary = Object.freeze({
        "Install Aplikasi": "Install App",
        "Menu Portal": "Portal Menu",
        "Dashboard Internal Divisi Retail Minimarket": "Internal Retail Minimarket Division Dashboard",
        "Kelola Akun": "Manage Account",
        "Notifikasi Terbaru": "Latest Notifications",
        "Belum ada notifikasi terbaru.": "No recent notifications.",
        "Aktivitas": "Activity",
        "aktivitas": "activity",
        "Kegiatan": "Activities",
        "kegiatan": "activity",
        "Daftar Aktivitas Hari Ini": "Today's Activity List",
        "Pantau inspeksi dan aktivitas yang masuk hari ini secara cepat dan terstruktur.": "Monitor today's inspections and activities quickly and systematically.",
        "Daftar Kegiatan": "Activity List",
        "Pantau kegiatan supervisor dan crew leader.": "Monitor supervisor and crew leader activities.",
        "SDM": "HR",
        "Jumlah Karyawan": "Employee Count",
        "Data total karyawan yang aktif": "Total active employees",
        "( Leader & Crew )": "(Leaders & Crew)",
        "Akun": "Account",
        "Status Akun": "Account Status",
        "Kelola profil, hak akses, dan pengaturan akun.": "Manage profiles, access rights, and account settings.",
        "Grafik Sales Keseluruhan": "Overall Sales Chart",
        "Sales Keseluruhan": "Overall Sales",
        "Rata-rata": "Average",
        "Belum ada data sales untuk ditampilkan.": "No sales data available.",
        "Live Monitoring": "Live Monitoring",
        "Informasi": "Information",
        "Dear Spv": "Dear Supervisor",
        "Pengumpulan KPI Karyawan yang sudah di informasikan di harap segera di kumpulkan secepatnya.": "Please submit the previously announced employee KPI data as soon as possible.",
        "Lihat Omset": "View Revenue",
        "Lapor Kehadiran": "Submit Attendance",
        "Monitoring Kehadiran": "Attendance Monitoring",
        "Lihat Jadwal": "View Schedule",
        "Foto Informasi": "Information Photo",
        "Klik untuk perbesar": "Click to enlarge",
        "Aktivitas Visit Hari Ini": "Today's Visit Activity",
        "Belum ada aktivitas visit": "No visit activity yet",
        "Lihat detail": "View details",
        "Ada Temuan": "Finding Detected",
        "Kegiatan Hari Ini": "Today's Activities",
        "Belum ada kegiatan hari ini": "No activities today",
        "Telah melakukan kegiatan hari ini.": "Activity completed today.",
        "Lihat foto": "View photo",
        "Detail kegiatan": "Activity details",
        "Aktivitas Periode": "Period Activity",
        "Distribusi aktivitas berdasarkan nama pada bulan berjalan.": "Activity distribution by person for the current month.",
        "Belum ada data aktivitas untuk ditampilkan pada bulan ini.": "No activity data available for this month.",
        "Kegiatan Periode": "Period Activities",
        "Distribusi kegiatan tambahan berdasarkan nama pada bulan berjalan.": "Additional activity distribution by person for the current month.",
        "Belum ada data kegiatan tambahan untuk ditampilkan pada bulan ini.": "No additional activity data available for this month.",
        "Berita Bandara & Penerbangan": "Airport & Aviation News",
        "Baca selengkapnya": "Read more",
        "Memuat berita bandara & penerbangan setelah dashboard utama tampil...": "Loading airport and aviation news after the main dashboard is ready...",
        "Berita penerbangan belum bisa dimuat. Dashboard utama tetap berjalan normal.": "Aviation news could not be loaded. The main dashboard remains available.",
        "Berita belum bisa dimuat. Dashboard utama tetap berjalan normal.": "News could not be loaded. The main dashboard remains available.",
        "Divisi Minimarket": "Minimarket Division",
        "Tema Dashboard": "Dashboard Theme",
        "Tampilan profesional.": "Professional appearance.",
        "Light / Biru": "Light / Blue",
        "Tampilan cerah dan fresh.": "Bright and clean appearance.",
        "Tutup": "Close",
        "Bahasa Dashboard": "Dashboard Language",
        "Bahasa Sidebar": "Dashboard Language",
        "Pengaturan Bahasa": "Language Settings",
        "Akses Dibatasi": "Access Restricted",
        "Anda tidak memiliki akses untuk membuka fitur ini.": "You do not have permission to open this feature.",
        "Mengerti": "Understood",
        "Saya Mengerti": "I Understand",
        "Buka Menu": "Open Menu",
        "Siap": "Ready",
        "Informasi:": "Information:",
        "Outlet Anda": "Your Outlets",
        "Outlet Area:": "Outlet Area:",
        "Total Omset Tanggal Berjalan": "Current Date Total Revenue",
        "Update Omset Minimarket": "Minimarket Revenue Update",
        "Laporan Omset Harian": "Daily Revenue Report",
        "Tanggal:": "Date:",
        "Pendapatan omset minimarket telah direkap secara keseluruhan.": "Minimarket revenue has been fully summarized.",
        "Pendapatan omset minimarket per tanggal": "Minimarket revenue for",
        "telah diperbarui sesuai area outlet yang menjadi tanggung jawab Anda.": "has been updated based on your assigned outlet area.",
        "Total sales semua outlet bulan ini": "Total sales for all outlets this month",
        "Anda tidak memiliki akses outlet": "You do not have outlet access",
        "Tidak ada outlet": "No outlets",
        "Notifikasi": "Notifications",
        "Notifikasi Baru": "New Notification",
        "Aktivitas Visit Baru": "New Visit Activity",
        "Kegiatan Baru": "New Activity",
        "Notif Header Aktivitas": "Visit Header Notification",
        "Notif Header Kegiatan": "Activity Header Notification",
        "Notifikasi real-time aktif": "Real-time notifications are active",
        "Notifikasi real-time belum siap": "Real-time notifications are not ready",
        "Notifikasi diblokir di browser": "Notifications are blocked in the browser",
        "Service worker gagal aktif": "The service worker could not start",
        "Service Worker belum aktif. Refresh halaman lalu coba lagi.": "The service worker is not active. Refresh the page and try again.",
        "Browser belum support Web Push": "This browser does not support Web Push",
        "Belum Support": "Not Supported",
        "Browser ini belum mendukung notifikasi realtime": "This browser does not support real-time notifications.",
        "Izinkan Notifkasi Real Time": "Allow Real-Time Notifications",
        "Izin notifikasi belum diberikan": "Notification permission has not been granted",
        "Notifikasi belum aktif": "Notifications are not active",
        "Izin notifikasi belum diberikan. Aktifkan izin notifikasi dari browser/setting HP.": "Notification permission has not been granted. Enable it in your browser or phone settings.",
        "Notifikasi Real Time Berhasil": "Real-Time Notifications Enabled",
        "Gagal mengaktifkan notifikasi": "Failed to enable notifications",
        "Gagal aktifkan notifikasi": "Failed to Enable Notifications",
        "Coba refresh halaman lalu aktifkan ulang.": "Refresh the page and try enabling notifications again.",
        "Gagal menyimpan subscription": "Failed to save the notification subscription",
        "VAPID key belum diisi": "VAPID key has not been configured",
        "Generate VAPID key dulu, lalu isi": "Generate a VAPID key first, then enter",
        "di file dashboard.": "in the dashboard file.",
        "Notifikasi Wajib Diaktifkan": "Notifications Must Be Enabled",
        "Fitur notifikasi realtime wajib aktif untuk operasional divisi.": "Real-time notifications must be enabled for division operations.",
        "Status browser saat ini masih": "The current browser status is",
        "Blocked / Ditolak": "Blocked",
        "Silakan aktifkan izin notifikasi pada pengaturan browser/site settings, lalu refresh halaman ini.": "Enable notification permission in your browser or site settings, then refresh this page.",
        "Aktifkan Notifikasi Realtime": "Enable Real-Time Notifications",
        "Untuk mendukung operasional dan update realtime, fitur notifikasi wajib diaktifkan pada perangkat ini.": "To support operations and real-time updates, notifications must be enabled on this device.",
        "Aktifkan Sekarang": "Enable Now",
        "Notifikasi Aktif": "Notifications Enabled",
        "Notifikasi realtime berhasil diaktifkan pada perangkat ini.": "Real-time notifications have been enabled on this device.",
        "Notifikasi Belum Aktif": "Notifications Are Not Active",
        "Notifikasi realtime wajib diaktifkan untuk menerima update operasional.": "Real-time notifications must be enabled to receive operational updates.",
        "Silakan aktifkan izin notifikasi dari pengaturan browser, lalu refresh halaman ini.": "Enable notification permission in your browser settings, then refresh this page.",
        "Silakan klik Aktifkan Sekarang untuk melanjutkan aktivasi notifikasi realtime.": "Select Enable Now to continue activating real-time notifications.",
        "Coba Lagi": "Try Again",
        "Install di iPhone / iPad": "Install on iPhone / iPad",
        "Buka menu Share di Safari, lalu pilih Add to Home Screen / Tambahkan ke Layar Utama.": "Open the Share menu in Safari, then select Add to Home Screen.",
        "Buka menu": "Open the",
        "di Safari, lalu pilih": "menu in Safari, then select",
        "Tambahkan ke Layar Utama": "Add to Home Screen",
        "Tampilkan Omset, rata-rata, target, dan achievement": "Show revenue, average, target, and achievement",
        "Sembunyikan Omset, rata-rata, target, dan achievement": "Hide revenue, average, target, and achievement",
        "Tampilkan/Sembunyikan total omset": "Show or hide total revenue",
        "Tutup menu": "Close menu",
        "Profil pengguna": "User profile",
        "Lihat foto informasi": "View information photo",
        "Foto Informasi Dashboard": "Dashboard Information Photo",
        "Preview kegiatan hari ini": "Preview today's activities",
        "Tutup preview": "Close preview",
        "Foto sebelumnya": "Previous photo",
        "Foto berikutnya": "Next photo",
        "Tutup foto": "Close photo",
        "Sales: belum terisi": "Sales: not entered yet",
        "Klik untuk melihat detail aktivitas visit.": "Click to view visit activity details.",
        "Default": "Default",
        "International": "International"
    });

    const dashboardMonthDictionary = Object.freeze({
        januari: 'January',
        februari: 'February',
        maret: 'March',
        april: 'April',
        mei: 'May',
        juni: 'June',
        juli: 'July',
        agustus: 'August',
        september: 'September',
        oktober: 'October',
        november: 'November',
        desember: 'December'
    });

    function dashboardTranslateMonths(value) {
        return String(value).replace(/\b(Januari|Februari|Maret|April|Mei|Juni|Juli|Agustus|September|Oktober|November|Desember)\b/gi, function(month) {
            return dashboardMonthDictionary[month.toLowerCase()] || month;
        });
    }

    function getDashboardLanguage() {
        return localStorage.getItem('dashboard_sidebar_language') === 'en' ? 'en' : 'id';
    }

    function dashboardT(value, language) {
        const source = String(value === null || value === undefined ? '' : value);
        const selectedLanguage = language === 'en' ? 'en' : (language === 'id' ? 'id' : getDashboardLanguage());
        if (selectedLanguage !== 'en' || source.trim() === '') return source;

        const leading = (source.match(/^\s*/) || [''])[0];
        const trailing = (source.match(/\s*$/) || [''])[0];
        const normalized = source.trim().replace(/\s+/g, ' ');
        let translated = dashboardLanguageDictionary[normalized] || '';
        let match;

        if (!translated && (match = normalized.match(/^(\d+)\s+kegiatan\s*\/\s*(\d+)\s+user$/i))) {
            translated = match[1] + ' activities / ' + match[2] + ' users';
        } else if (!translated && (match = normalized.match(/^(\d+)x\s+Kegiatan$/i))) {
            translated = match[1] + 'x Activities';
        } else if (!translated && (match = normalized.match(/^(\d+)\s+kegiatan$/i))) {
            translated = match[1] + ' activities';
        } else if (!translated && (match = normalized.match(/^(\d+)\s+item$/i))) {
            translated = match[1] + ' items';
        } else if (!translated && (match = normalized.match(/^Aktivitas Periode\s+(.+)$/i))) {
            translated = 'Activity Period ' + match[1];
        } else if (!translated && (match = normalized.match(/^Kegiatan Periode\s+(.+)$/i))) {
            translated = 'Activity Period ' + match[1];
        } else if (!translated && (match = normalized.match(/^Omset kemarin:\s*(.+)$/i))) {
            translated = 'Yesterday\'s revenue: ' + match[1];
        } else if (!translated && (match = normalized.match(/^Total sales outlet:\s*(.+)$/i))) {
            translated = 'Total outlet sales: ' + match[1];
        } else if (!translated && (match = normalized.match(/^Selamat datang\s+(.+?)\s+di WebPortal Minimarket SRT\s*~\s*Dashboard terpusat untuk monitoring operasional, penjualan, administrasi, inventori, dan integrasi data store\.$/i))) {
            translated = 'Welcome ' + match[1] + ' to SRT Minimarket WebPortal ~ A centralized dashboard for monitoring operations, sales, administration, inventory, and store data integration.';
        } else if (!translated && (match = normalized.match(/^Aktivitas slide\s+(\d+)$/i))) {
            translated = 'Activity slide ' + match[1];
        } else if (!translated && (match = normalized.match(/^Kegiatan slide\s+(\d+)$/i))) {
            translated = 'Activity slide ' + match[1];
        } else if (!translated && (match = normalized.match(/^Berita slide\s+(\d+)$/i))) {
            translated = 'News slide ' + match[1];
        } else if (!translated && (match = normalized.match(/^Foto outlet\s+(.+)$/i))) {
            translated = 'Outlet photo ' + match[1];
        } else if (!translated && (match = normalized.match(/^Foto profil\s+(.+)$/i))) {
            translated = 'Profile photo ' + match[1];
        } else if (!translated && (match = normalized.match(/^(.+?)\s+Telah Melakukan Kegiatan pada tanggal\s+(.+?)\s+Pukul\s+(.+)$/i))) {
            translated = match[1] + ' completed an activity on ' + match[2] + ' at ' + match[3];
        } else if (!translated && (match = normalized.match(/^(.+?)\s+Telah Melakukan Visit di\s+(.+?)\s+pada tanggal\s+(.+?)\s+Pukul\s+(.+)$/i))) {
            translated = match[1] + ' completed a visit at ' + match[2] + ' on ' + match[3] + ' at ' + match[4];
        } else if (!translated && /Omset kemarin:/i.test(normalized)) {
            translated = normalized.replace(/Omset kemarin:/gi, 'Yesterday\'s revenue:');
        }

        if (translated) {
            translated = dashboardTranslateMonths(translated);
        } else {
            const monthTranslated = dashboardTranslateMonths(normalized);
            if (monthTranslated !== normalized) translated = monthTranslated;
        }

        return translated ? leading + translated + trailing : source;
    }

    function dashboardTranslateTextNode(node, language) {
        if (!node || node.nodeType !== Node.TEXT_NODE || !node.parentElement) return;
        if (node.parentElement.closest('script, style, noscript, [data-sidebar-title]')) return;
        if (node.__dashboardLanguageSource === undefined) {
            node.__dashboardLanguageSource = node.nodeValue;
        }
        node.nodeValue = language === 'en'
            ? dashboardT(node.__dashboardLanguageSource, 'en')
            : node.__dashboardLanguageSource;
    }

    function dashboardTranslateElementAttributes(element, language) {
        if (!element || element.nodeType !== Node.ELEMENT_NODE) return;
        ['title', 'aria-label', 'placeholder', 'alt'].forEach(function(attribute) {
            if (!element.hasAttribute(attribute)) return;
            if (!element.__dashboardLanguageAttributes) element.__dashboardLanguageAttributes = {};
            if (element.__dashboardLanguageAttributes[attribute] === undefined) {
                element.__dashboardLanguageAttributes[attribute] = element.getAttribute(attribute);
            }
            const source = element.__dashboardLanguageAttributes[attribute];
            element.setAttribute(attribute, language === 'en' ? dashboardT(source, 'en') : source);
        });
    }

    function applyDashboardContentLanguage(language, root) {
        const selectedLanguage = language === 'en' ? 'en' : 'id';
        const targetRoot = root || document.body;
        if (!targetRoot) return;

        if (targetRoot.nodeType === Node.TEXT_NODE) {
            dashboardTranslateTextNode(targetRoot, selectedLanguage);
            return;
        }

        if (targetRoot.nodeType === Node.ELEMENT_NODE) {
            dashboardTranslateElementAttributes(targetRoot, selectedLanguage);
        }

        const textWalker = document.createTreeWalker(targetRoot, NodeFilter.SHOW_TEXT);
        let currentTextNode;
        while ((currentTextNode = textWalker.nextNode())) {
            dashboardTranslateTextNode(currentTextNode, selectedLanguage);
        }

        if (targetRoot.querySelectorAll) {
            targetRoot.querySelectorAll('[title], [aria-label], [placeholder], [alt]').forEach(function(element) {
                dashboardTranslateElementAttributes(element, selectedLanguage);
            });
        }
    }

    function ensureDashboardLanguageObserver() {
        if (window.__dashboardLanguageObserver || !document.body) return;
        window.__dashboardLanguageObserver = new MutationObserver(function(mutations) {
            const language = getDashboardLanguage();
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    applyDashboardContentLanguage(language, node);
                });
            });
        });
        window.__dashboardLanguageObserver.observe(document.body, { childList: true, subtree: true });
    }

    function applySidebarLanguage(language) {
        const selectedLanguage = language === 'en' ? 'en' : 'id';
        localStorage.setItem('dashboard_sidebar_language', selectedLanguage);
        document.documentElement.setAttribute('lang', selectedLanguage === 'en' ? 'en' : 'id');

        document.querySelectorAll('[data-sidebar-title]').forEach(function(el) {
            const nextText = selectedLanguage === 'en' ? (el.dataset.titleEn || el.textContent) : (el.dataset.titleId || el.textContent);
            el.textContent = nextText;
        });

        applyDashboardContentLanguage(selectedLanguage);
        ensureDashboardLanguageObserver();
        document.title = selectedLanguage === 'en' ? 'Minimarket Portal Dashboard' : 'Dashboard Portal Minimarket';
        window.dispatchEvent(new CustomEvent('dashboardlanguagechange', { detail: { language: selectedLanguage } }));
    }

    function openSidebarLanguageSelector() {
        const currentLanguage = localStorage.getItem('dashboard_sidebar_language') || 'id';
        Swal.fire({
            title: currentLanguage === 'en' ? 'Dashboard Language' : 'Bahasa Dashboard',
            html: `
                <div class="language-choice-wrap">
                    <button type="button" class="language-choice-card indonesia" data-language="id">
                        <span class="language-choice-icon"><i class="fa-solid fa-flag"></i></span>
                        <h4>Indonesia</h4>
                        <p>Default</p>
                    </button>
                    <button type="button" class="language-choice-card english" data-language="en">
                        <span class="language-choice-icon"><i class="fa-solid fa-language"></i></span>
                        <h4>English</h4>
                        <p>International</p>
                    </button>
                </div>
            `,
            showConfirmButton: false,
            showCancelButton: true,
            cancelButtonText: currentLanguage === 'en' ? 'Close' : 'Tutup',
            customClass: {
                popup: 'language-swal-popup',
                title: 'language-swal-title'
            },
            didOpen: function() {
                const cards = Swal.getPopup().querySelectorAll('.language-choice-card');
                cards.forEach(function(card) {
                    if (card.dataset.language === currentLanguage) card.classList.add('active');
                    card.addEventListener('click', function() {
                        applySidebarLanguage(card.dataset.language);
                        Swal.close();
                    });
                });
            }
        });
    }

    applySidebarLanguage(localStorage.getItem('dashboard_sidebar_language') || 'id');

    document.querySelectorAll('a[href="#language-toggle"]').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            openSidebarLanguageSelector();
        });
    });

    const profileBtn = document.getElementById('profileBtn');
    const profilePopover = document.getElementById('profilePopover');

    if (profileBtn && profilePopover) {
        profileBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            profilePopover.classList.toggle('show');
            profilePopover.setAttribute('aria-hidden', profilePopover.classList.contains('show') ? 'false' : 'true');
            if (notifDropdown) notifDropdown.classList.remove('show');
        });

        profilePopover.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }

    notifBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        notifDropdown.classList.toggle('show');
    });

    document.addEventListener('click', (e) => {
        if (!notifDropdown.contains(e.target) && !notifBtn.contains(e.target)) {
            notifDropdown.classList.remove('show');
        }
        if (profilePopover && profileBtn && !profilePopover.contains(e.target) && !profileBtn.contains(e.target)) {
            profilePopover.classList.remove('show');
            profilePopover.setAttribute('aria-hidden', 'true');
        }
    });

    window.addEventListener('scroll', () => {
        if (window.scrollY > 8) {
            topbar.classList.add('scrolled');
        } else {
            topbar.classList.remove('scrolled');
        }
    });

    function renderNotifications(items) {
        notifCount.textContent = items.length;
        notifSubtitle.textContent = items.length + ' item';

        if (!items.length) {
            notifList.innerHTML = '<div class="notif-empty">Belum ada notifikasi terbaru.</div>';
            return;
        }

        notifList.innerHTML = items.map(item => {
            const type = String(item.type || 'aktivitas').toLowerCase();
            const canOpen = type === 'kegiatan'
                ? dashboardNotifElementAccess.kegiatan
                : dashboardNotifElementAccess.aktivitas;

            const titleId = type === 'kegiatan' ? 'Notif Header Kegiatan' : 'Notif Header Aktivitas';
            const titleEn = type === 'kegiatan' ? 'Activity Header Notification' : 'Visit Header Notification';
            const targetUrl = String(item.url || '#');
            const lockedClass = canOpen ? '' : ' locked-element';
            const iconClass = type === 'kegiatan' ? 'fa-list-check' : 'fa-store';

            return `
                <a
                    class="notif-item${lockedClass}"
                    href="#"
                    data-url="${escapeDashboardHtml(targetUrl)}"
                    data-can-open="${canOpen ? '1' : '0'}"
                    data-menu-title="${escapeDashboardHtml(titleId)}"
                    data-title-id="${escapeDashboardHtml(titleId)}"
                    data-title-en="${escapeDashboardHtml(titleEn)}"
                    data-notif-type="${escapeDashboardHtml(type)}"
                    data-locked="${canOpen ? '0' : '1'}"
                    aria-disabled="${canOpen ? 'false' : 'true'}"
                >
                    <div class="notif-icon">
                        <i class="fa ${iconClass}"></i>
                    </div>
                    <div class="notif-content">
                        <small>${escapeDashboardHtml(type)}</small>
                        <p>${escapeDashboardHtml(item.text || '')}</p>
                    </div>
                </a>
            `;
        }).join('');
    }

    function showLiveToast(item) {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: item.type === 'kegiatan' ? 'success' : 'info',
            title: item.title,
            text: item.text,
            showConfirmButton: false,
            timer: 4500,
            timerProgressBar: true,
            customClass: {
                popup: 'live-toast'
            }
        });
    }

    async function loadNotifications(showToastIfNew = false) {
        try {
            const response = await fetch(dashboardAjaxUrl + '?ajax=notifications', { cache: 'no-store' });
            const data = await response.json();

            if (!data.success) return;

            renderNotifications(data.items || []);

            if (data.items && data.items.length > 0) {
                const newest = data.items[0];
                const currentKey = `${newest.type}|${newest.time_key}|${newest.text}`;

                if (!lastNotificationKey) {
                    lastNotificationKey = currentKey;
                    localStorage.setItem('dashboard_last_notification_key', currentKey);
                    return;
                }

                if (showToastIfNew && currentKey !== lastNotificationKey) {
                    lastNotificationKey = currentKey;
                    localStorage.setItem('dashboard_last_notification_key', currentKey);
                    showLiveToast(newest);
                }
            }
        } catch (error) {
            console.error('Gagal memuat notifikasi:', error);
        }
    }

    async function loadPopup(showOnlyIfNew = false) {
        try {
            const response = await fetch(dashboardAjaxUrl + '?ajax=popup', { cache: 'no-store' });
            const data = await response.json();

            if (!data.success || !data.popup) return;

            const popup = data.popup;
            const popupId = String(popup.id || '');

            if (showOnlyIfNew && popupId && popupId === lastPopupId) {
                return;
            }

            if (popupId) {
                lastPopupId = popupId;
                localStorage.setItem('dashboard_last_popup_id', popupId);
            }

            Swal.fire({
                title: popup.judul || 'Informasi',
                html: `
                    <div style="font-size:14px;line-height:1.75;color:#dbeafe;">
                        ${String(popup.isi || '').replace(/\n/g, '<br>')}
                        ${popup.link ? `<div style="margin-top:18px;"><a href="${popup.link}" style="display:inline-flex;align-items:center;gap:8px;padding:11px 14px;border-radius:14px;background:linear-gradient(135deg,#4f8cff,#7c4dff);color:#fff;font-weight:700;text-decoration:none;">Buka Menu <i class='fa fa-arrow-right'></i></a></div>` : ''}
                    </div>
                `,
                icon: 'info',
                confirmButtonText: 'Siap',
                confirmButtonColor: '#4f8cff',
                background: '#0d1730',
                color: '#eff6ff'
            });
        } catch (error) {
            console.error('Gagal memuat popup:', error);
        }
    }


    function buildOmsetAlertHtml(data) {
        const outletText = Array.isArray(data.outlets) && data.outlets.length
            ? data.outlets.join(', ')
            : 'Outlet Anda';

        return `
            <div style="font-size:14px;line-height:1.85;color:#dbeafe;text-align:left;">
                <div style="margin-bottom:12px;">
                    <strong style="color:#ffffff;">Informasi:</strong><br>
                    Pendapatan omset minimarket per tanggal <strong>${data.tanggal}</strong> telah diperbarui sesuai area outlet yang menjadi tanggung jawab Anda.
                </div>

                <div style="margin-bottom:12px;">
                    <strong style="color:#ffffff;">Outlet Area:</strong><br>
                    ${outletText}
                </div>

                <div style="
                    margin-top:16px;
                    padding:18px;
                    border-radius:18px;
                    background:linear-gradient(135deg, rgba(79,140,255,.22), rgba(124,77,255,.18));
                    border:1px solid rgba(255,255,255,.10);
                    text-align:center;
                ">
                    <div style="font-size:12px;letter-spacing:.6px;text-transform:uppercase;color:#cfe2ff;margin-bottom:8px;font-weight:700;">
                        Total Omset Tanggal Berjalan
                    </div>
                    <div style="font-size:30px;font-weight:800;color:#ffffff;line-height:1.2;">
                        ${data.formatted_total}
                    </div>
                </div>
            </div>
        `;
    }

    const shouldShowOmsetAlertOnLogin = <?= json_encode($showOmsetAlertOnLogin) ?>;
    const omsetAlertUserKey = <?= json_encode(strtolower(trim($username))) ?> || 'guest';

    function playOmsetNotificationSound() {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;

            const ctx = new AudioCtx();
            const now = ctx.currentTime;

            const osc1 = ctx.createOscillator();
            const osc2 = ctx.createOscillator();
            const gain = ctx.createGain();

            osc1.type = 'sine';
            osc2.type = 'triangle';
            osc1.frequency.setValueAtTime(880, now);
            osc2.frequency.setValueAtTime(1174, now);

            gain.gain.setValueAtTime(0.0001, now);
            gain.gain.exponentialRampToValueAtTime(0.08, now + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.45);

            osc1.connect(gain);
            osc2.connect(gain);
            gain.connect(ctx.destination);

            osc1.start(now);
            osc2.start(now + 0.03);
            osc1.stop(now + 0.42);
            osc2.stop(now + 0.45);
        } catch (error) {
            console.warn('Audio notif gagal diputar:', error);
        }
    }

    function getOmsetStorageKeys() {
        const todayKey = new Date().toISOString().slice(0, 10);
        return {
            lastAlertKey: `dashboard_omset_last_alert_${omsetAlertUserKey}_${todayKey}`,
            modalShownKey: `dashboard_omset_modal_shown_${omsetAlertUserKey}_${todayKey}`
        };
    }

    function showOmsetUpdateToast(data) {
        playOmsetNotificationSound();

        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'info',
            title: 'Update Omset Minimarket',
            html: `
                <div style="font-size:12px;line-height:1.6;">
                    Pendapatan omset minimarket per tanggal <strong>${data.tanggal}</strong> telah diperbarui sesuai area outlet yang menjadi tanggung jawab Anda.
                    <div style="margin-top:8px;font-size:16px;font-weight:800;color:#fff;">${data.formatted_total}</div>
                </div>
            `,
            showConfirmButton: false,
            timer: 6000,
            timerProgressBar: true,
            customClass: {
                popup: 'live-toast'
            }
        });
    }

    function showDashboardPopupAfterOmset() {
        setTimeout(() => {
            loadPopup(true);
        }, 2000);
    }

    async function loadOmsetHarianAlert() {
        try {
            const res = await fetch(dashboardAjaxUrl + '?ajax=omset_harian_alert', { cache: 'no-store' });
            const data = await res.json();

            if (!data.success || !data.show) {
                return false;
            }

            const key = 'omset_alert_' + data.alert_key;
            if (sessionStorage.getItem(key)) {
                return false;
            }

            sessionStorage.setItem(key, '1');

            try {
                const audio = new Audio('https://actions.google.com/sounds/v1/alarms/notification_simple-01.mp3');
                audio.play().catch(() => {});
            } catch (audioError) {
                console.warn('Audio omset gagal diputar:', audioError);
            }

            await Swal.fire({
                icon: 'success',
                title: 'Laporan Omset Harian',
                html: `
                    <div style="text-align:center; line-height:1.8;">
                        <b>Tanggal:</b> ${data.tanggal}<br><br>

                        <div style="
                            padding:15px;
                            border-radius:10px;
                            background:#1e293b;
                            text-align:center;
                            font-size:26px;
                            font-weight:bold;
                            color:#22c55e;
                        ">
                            ${data.formatted_total}
                        </div>

                        <div style="margin-top:10px; font-size:13px;">
                            Pendapatan omset minimarket telah direkap secara keseluruhan.
                        </div>
                    </div>
                `,
                confirmButtonText: 'OK',
                confirmButtonColor: '#22c55e',
                allowOutsideClick: false,
                allowEscapeKey: false
            });

            showDashboardPopupAfterOmset();
            return true;
        } catch (err) {
            console.log('error omset:', err);
            return false;
        }
    }

    async function startDashboardAlerts() {
        const omsetShown = await loadOmsetHarianAlert();

        if (!omsetShown) {
            showDashboardPopupAfterOmset();
        }
    }

    function buildDoughnutChart(canvasId, labels, values, hueStart) {
        const colors = labels.map((_, index) => `hsl(${(hueStart + (index * 38)) % 360}, 80%, 58%)`);
        const el = document.getElementById(canvasId);
        if (!el) return;

        new Chart(el, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors,
                    borderColor: 'rgba(8, 17, 32, 0.92)',
                    borderWidth: 4,
                    hoverOffset: 12
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '58%',
                animation: {
                    animateScale: true,
                    animateRotate: true
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#dce8ff',
                            padding: 18,
                            boxWidth: 14,
                            font: {
                                size: 12,
                                weight: '600'
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(8, 17, 32, 0.94)',
                        borderColor: 'rgba(255,255,255,.08)',
                        borderWidth: 1,
                        titleColor: '#fff',
                        bodyColor: '#dce8ff',
                        callbacks: {
                            label(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const value = context.raw;
                                const percent = total ? ((value / total) * 100).toFixed(1) : 0;
                                return `${context.label}: ${value} (${percent}%)`;
                            }
                        }
                    },
                    datalabels: {
                        color: '#fff',
                        font: {
                            weight: '700',
                            size: 11
                        },
                        formatter(value, context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            if (!total) return '';
                            const percent = (value / total) * 100;
                            return percent < 6 ? '' : `${percent.toFixed(0)}%`;
                        }
                    }
                }
            }
        });
    }

    function buildSalesOverallChart(canvasId, labels, values) {
        const el = document.getElementById(canvasId);
        if (!el) return;

        const ctx = el.getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, 220);
        gradient.addColorStop(0, 'rgba(96, 165, 250, 0.42)');
        gradient.addColorStop(1, 'rgba(96, 165, 250, 0.025)');

        const realValues = (values || []).map((value) => {
            if (value === null || value === undefined || value === '') return null;
            const parsed = Number(value);
            return Number.isFinite(parsed) ? parsed : null;
        });
        const emptyValues = realValues.map(() => null);
        let snakeFrame = null;
        let snakeTimer = null;

        function compactRupiah(value) {
            const number = Number(value) || 0;
            if (number >= 1000000000) return 'Rp ' + (number / 1000000000).toFixed(number >= 10000000000 ? 0 : 1).replace('.', ',') + ' M';
            if (number >= 1000000) return 'Rp ' + (number / 1000000).toFixed(number >= 10000000 ? 0 : 1).replace('.', ',') + ' Jt';
            if (number >= 1000) return 'Rp ' + (number / 1000).toFixed(0) + ' Rb';
            return 'Rp ' + number.toLocaleString('id-ID');
        }

        const chart = new Chart(el, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: dashboardT('Sales Keseluruhan'),
                    data: emptyValues.slice(),
                    borderColor: '#7cc8ff',
                    backgroundColor: gradient,
                    fill: true,
                    tension: 0.42,
                    cubicInterpolationMode: 'monotone',
                    pointRadius: function(context) {
                        return context.raw === null ? 0 : 3;
                    },
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#60a5fa',
                    pointBorderWidth: 2,
                    borderWidth: 3,
                    spanGaps: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 450,
                    easing: 'easeOutQuart'
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(8, 17, 32, 0.96)',
                        borderColor: 'rgba(124, 200, 255, .22)',
                        borderWidth: 1,
                        padding: 12,
                        cornerRadius: 14,
                        titleColor: '#fff',
                        bodyColor: '#dce8ff',
                        callbacks: {
                            label(context) {
                                if (context.raw === null || context.raw === undefined) {
                                    return dashboardT('Sales: belum terisi');
                                }
                                const value = Number(context.raw) || 0;
                                return 'Sales: Rp ' + value.toLocaleString('id-ID');
                            }
                        }
                    },
                    datalabels: { display: false }
                },
                scales: {
                    x: {
                        ticks: {
                            color: '#cfe2ff',
                            font: { size: 10, weight: '700' },
                            autoSkip: true,
                            maxRotation: 0,
                            minRotation: 0
                        },
                        grid: { color: 'rgba(255,255,255,0.035)' }
                    },
                    y: {
                        ticks: {
                            color: '#a8bedc',
                            font: { size: 11, weight: '600' },
                            callback: compactRupiah
                        },
                        grid: { color: 'rgba(255,255,255,0.045)' }
                    }
                }
            }
        });

        function runSnakeAnimation() {
            if (snakeFrame) cancelAnimationFrame(snakeFrame);
            chart.data.datasets[0].data = emptyValues.slice();
            chart.update('none');

            const length = realValues.length;
            if (length <= 1) {
                chart.data.datasets[0].data = realValues.slice();
                chart.update('none');
                return;
            }

            const duration = 3600;
            const startedAt = performance.now();

            function step(now) {
                const progress = Math.min((now - startedAt) / duration, 1);
                const headPosition = progress * (length - 1);
                const headIndex = Math.floor(headPosition);
                const segmentProgress = headPosition - headIndex;

                const nextData = realValues.map((value, index) => {
                    if (index <= headIndex) return value;
                    if (index === headIndex + 1) {
                        const previous = realValues[headIndex];
                        const next = realValues[index];

                        if (previous === null || next === null) {
                            return null;
                        }

                        return previous + ((next - previous) * segmentProgress);
                    }
                    return null;
                });

                chart.data.datasets[0].data = nextData;
                chart.update('none');

                if (progress < 1) {
                    snakeFrame = requestAnimationFrame(step);
                } else {
                    chart.data.datasets[0].data = realValues.slice();
                    chart.update('none');
                }
            }

            snakeFrame = requestAnimationFrame(step);
        }

        runSnakeAnimation();
        snakeTimer = setInterval(runSnakeAnimation, 8000);
        el.addEventListener('mouseenter', () => {
            if (snakeTimer) clearInterval(snakeTimer);
        });
        el.addEventListener('mouseleave', () => {
            if (snakeTimer) clearInterval(snakeTimer);
            snakeTimer = setInterval(runSnakeAnimation, 8000);
        });
    }

    function animateCounters() {
        document.querySelectorAll('[data-counter]').forEach((el) => {
            const target = parseInt(el.getAttribute('data-counter'), 10) || 0;
            const duration = 1100;
            const start = 0;
            const startTime = performance.now();

            function update(currentTime) {
                const progress = Math.min((currentTime - startTime) / duration, 1);
                const value = Math.floor(progress * (target - start) + start);
                el.textContent = value.toLocaleString('id-ID');

                if (progress < 1) {
                    requestAnimationFrame(update);
                } else {
                    el.textContent = target.toLocaleString('id-ID');
                }
            }

            requestAnimationFrame(update);
        });
    }


    function escapeNewsHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderLatestNews(items) {
        const loading = document.getElementById('aviationNewsLoading');
        const container = document.getElementById('aviationNewsDynamic');
        if (!container) return;

        if (!items || !items.length) {
            if (loading) loading.textContent = 'Berita penerbangan belum bisa dimuat. Dashboard utama tetap berjalan normal.';
            return;
        }

        if (loading) loading.remove();

        const miniItems = items.slice(0, 4).map((news) => {
            const image = news.image || news.favicon || '';
            return `
                <a class="mini-news-card" href="${escapeNewsHtml(news.link)}" target="_blank" rel="noopener noreferrer">
                    <div class="mini-news-thumb"><img src="${escapeNewsHtml(image)}" alt="${escapeNewsHtml(news.title)}" loading="lazy" decoding="async"></div>
                    <div class="mini-news-body">
                        <div class="mini-news-meta"><span class="news-label ${escapeNewsHtml(news.label_class || 'info')}">${escapeNewsHtml(news.label || 'Info')}</span><span>${escapeNewsHtml(news.source || 'Google News')}</span></div>
                        <h4>${escapeNewsHtml(news.title)}</h4>
                        ${news.date ? `<small><i class="fa fa-clock"></i> ${escapeNewsHtml(news.date)} WIB</small>` : ''}
                    </div>
                </a>`;
        }).join('');

        const slides = items.map((news, index) => {
            const image = news.image || news.favicon || '';
            return `
                <a class="featured-news-slide ${index === 0 ? 'active' : ''}" href="${escapeNewsHtml(news.link)}" target="_blank" rel="noopener noreferrer" data-slide="${index}">
                    <div class="featured-news-image"><img src="${escapeNewsHtml(image)}" alt="${escapeNewsHtml(news.title)}" loading="lazy" decoding="async"><div class="featured-news-overlay"></div></div>
                    <div class="featured-news-content">
                        <div class="featured-news-top"><span class="featured-badge ${escapeNewsHtml(news.label_class || 'info')}"><i class="fa fa-bolt"></i> ${escapeNewsHtml(news.label || 'Info')}</span><span class="featured-source"><i class="fa fa-rss"></i> ${escapeNewsHtml(news.source || 'Google News')}</span></div>
                        <h3>${escapeNewsHtml(news.title)}</h3>
                        ${news.description ? `<p>${escapeNewsHtml(news.description)}</p>` : ''}
                        <div class="featured-news-bottom">${news.date ? `<span><i class="fa fa-clock"></i> ${escapeNewsHtml(news.date)} WIB</span>` : '<span></span>'}<strong>Baca selengkapnya <i class="fa fa-arrow-right"></i></strong></div>
                    </div>
                </a>`;
        }).join('');

        const dots = items.map((_, index) => `<button type="button" class="featured-dot ${index === 0 ? 'active' : ''}" data-slide-target="${index}" aria-label="Berita slide ${index + 1}"></button>`).join('');

        container.innerHTML = `<div class="aviation-news-layout"><div class="aviation-news-left">${miniItems}</div><div class="aviation-news-slider" id="aviationNewsSlider">${slides}<div class="featured-slider-dots">${dots}</div></div></div>`;
        initAviationNewsSlider();
    }

    async function loadLatestNewsLazy() {
        const container = document.getElementById('aviationNewsDynamic');
        if (!container) return;
        try {
            const response = await fetch(dashboardAjaxUrl + '?ajax=latest_news', { cache: 'default' });
            const data = await response.json();
            renderLatestNews(data.items || []);
        } catch (error) {
            const loading = document.getElementById('aviationNewsLoading');
            if (loading) loading.textContent = 'Berita belum bisa dimuat. Dashboard utama tetap berjalan normal.';
            console.warn('Gagal memuat berita:', error);
        }
    }


    function initAviationNewsSlider() {
        const slider = document.getElementById('aviationNewsSlider');
        if (!slider) return;

        const slides = Array.from(slider.querySelectorAll('.featured-news-slide'));
        const dots = Array.from(slider.querySelectorAll('.featured-dot'));
        if (slides.length <= 1) return;

        let activeIndex = 0;
        let timer = null;
        const intervalMs = 5000;

        function showSlide(index) {
            activeIndex = (index + slides.length) % slides.length;

            slides.forEach((slide, i) => {
                slide.classList.toggle('active', i === activeIndex);
            });

            dots.forEach((dot, i) => {
                dot.classList.toggle('active', i === activeIndex);
            });
        }

        function nextSlide() {
            showSlide(activeIndex + 1);
        }

        function startAutoSlide() {
            stopAutoSlide();
            timer = setInterval(nextSlide, intervalMs);
        }

        function stopAutoSlide() {
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
        }

        dots.forEach((dot) => {
            dot.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                const target = parseInt(dot.getAttribute('data-slide-target'), 10) || 0;
                showSlide(target);
                startAutoSlide();
            });
        });

        slider.addEventListener('mouseenter', stopAutoSlide);
        slider.addEventListener('mouseleave', startAutoSlide);

        showSlide(0);
        startAutoSlide();
    }


    function initHeroActivitySlider() {
        const slider = document.getElementById('heroActivitySlider');
        if (!slider) return;

        const track = slider.querySelector('.hero-activity-track');
        const slides = Array.from(slider.querySelectorAll('.hero-activity-slide'));
        const dots = Array.from(slider.querySelectorAll('.hero-activity-dot'));

        if (!track || slides.length === 0) return;

        let activeIndex = 0;
        let timer = null;
        const intervalMs = 5000;

        function showSlide(index) {
            activeIndex = (index + slides.length) % slides.length;
            track.style.transform = `translateX(-${activeIndex * 100}%)`;

            slides.forEach((slide, i) => {
                slide.classList.toggle('active', i === activeIndex);
            });

            dots.forEach((dot, i) => {
                dot.classList.toggle('active', i === activeIndex);
            });
        }

        function stopAutoSlide() {
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
        }

        function startAutoSlide() {
            if (slides.length <= 1) return;
            stopAutoSlide();
            timer = setInterval(() => showSlide(activeIndex + 1), intervalMs);
        }

        dots.forEach((dot) => {
            dot.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();

                const target = parseInt(this.dataset.heroActivityTarget, 10) || 0;
                showSlide(target);
                startAutoSlide();
            });
        });

        // FIX:
        // Slider Aktivitas tidak boleh memakai WO lightbox.
        // Klik slide aktivitas diarahkan normal ke halaman Aktivitas Visit.
        slides.forEach((slide) => {
            slide.addEventListener('click', function () {
                const href = this.getAttribute('href') || 'data_aktivitas.php';
                window.location.href = href;
            });
        });

        slider.addEventListener('mouseenter', stopAutoSlide);
        slider.addEventListener('mouseleave', startAutoSlide);

        showSlide(0);
        startAutoSlide();
    }

    function initDashboardChartsLazy() {
        const runCharts = () => {
            <?php if (count($labels) > 0): ?>
            buildDoughnutChart('visitChart', <?= json_encode($labels) ?>, <?= json_encode($values) ?>, 210);
            <?php endif; ?>

            <?php if (count($labels2) > 0): ?>
            buildDoughnutChart('kegiatanChart', <?= json_encode($labels2) ?>, <?= json_encode($values2) ?>, 20);
            <?php endif; ?>

            <?php if (count($salesLabels) > 0): ?>
            buildSalesOverallChart('salesOverallChart', <?= json_encode($salesLabels) ?>, <?= json_encode($salesValues) ?>);
            <?php endif; ?>
        };

        if ('requestIdleCallback' in window) {
            requestIdleCallback(runCharts, { timeout: 2000 });
        } else {
            setTimeout(runCharts, 600);
        }
    }

    animateCounters();
    initSensitiveOmsetToggle();
    initHeroActivitySlider();
    initDashboardChartsLazy();
    setTimeout(loadLatestNewsLazy, 900);

    function initWorkOrderRealisasiSlider() {
        const slider = document.getElementById('woRealisasiSlider');
        if (!slider) return;

        const track = slider.querySelector('.wo-realisasi-track');
        const slides = Array.from(slider.querySelectorAll('.wo-realisasi-slide'));
        const dots = Array.from(slider.querySelectorAll('.wo-realisasi-dot'));
        if (!track || !slides.length) return;

        let activeIndex = 0;
        let timer = null;
        const intervalMs = 5000;

        function showSlide(index) {
            activeIndex = (index + slides.length) % slides.length;
            track.style.transform = `translateX(-${activeIndex * 100}%)`;
            slides.forEach((slide, i) => slide.classList.toggle('active', i === activeIndex));
            dots.forEach((dot, i) => dot.classList.toggle('active', i === activeIndex));
        }

        function stopAutoSlide() {
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
        }

        function startAutoSlide() {
            if (slides.length <= 1) return;
            stopAutoSlide();
            timer = setInterval(() => showSlide(activeIndex + 1), intervalMs);
        }

        dots.forEach((dot) => {
            dot.addEventListener('click', function () {
                const target = parseInt(this.dataset.woTarget, 10) || 0;
                showSlide(target);
                startAutoSlide();
            });
        });

        const lightbox = document.getElementById('woLightbox');
        const lightboxImage = document.getElementById('woLightboxImage');
        const lightboxTitle = document.getElementById('woLightboxTitle');
        const lightboxDesc = document.getElementById('woLightboxDesc');
        const lightboxDate = document.getElementById('woLightboxDate');
        const lightboxCreator = document.getElementById('woLightboxCreator');
        const lightboxCounter = document.getElementById('woLightboxCounter');
        const lightboxClose = document.getElementById('woLightboxClose');
        const lightboxPrev = document.getElementById('woLightboxPrev');
        const lightboxNext = document.getElementById('woLightboxNext');

        function renderLightbox(index) {
            if (!lightbox || !slides.length) return;

            activeIndex = (index + slides.length) % slides.length;
            const slide = slides[activeIndex];

            if (lightboxImage) {
                lightboxImage.src = slide.dataset.image || slide.getAttribute('href') || '';
                lightboxImage.alt = slide.dataset.title || 'Kegiatan Hari Ini';
            }
            if (lightboxTitle) lightboxTitle.textContent = slide.dataset.title || 'Kegiatan Hari Ini';
            if (lightboxDesc) lightboxDesc.textContent = slide.dataset.desc || '';
            if (lightboxDate) lightboxDate.textContent = slide.dataset.date || '';
            if (lightboxCreator) lightboxCreator.textContent = slide.dataset.creator || 'User';
            if (lightboxCounter) lightboxCounter.textContent = `${activeIndex + 1} / ${slides.length}`;

            showSlide(activeIndex);
        }

        function openLightbox(index) {
            stopAutoSlide();
            renderLightbox(index);
            lightbox.classList.add('show');
            lightbox.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function closeLightbox() {
            if (!lightbox) return;
            lightbox.classList.remove('show');
            lightbox.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            startAutoSlide();
        }

        slides.forEach((slide, index) => {
            slide.addEventListener('click', function (event) {
                if (slide.classList.contains('locked-element')) {
                    return;
                }

                event.preventDefault();
                openLightbox(index);
            });
        });

        if (lightboxClose) lightboxClose.addEventListener('click', closeLightbox);
        if (lightboxPrev) lightboxPrev.addEventListener('click', () => renderLightbox(activeIndex - 1));
        if (lightboxNext) lightboxNext.addEventListener('click', () => renderLightbox(activeIndex + 1));

        if (lightbox) {
            lightbox.addEventListener('click', function (event) {
                if (event.target === lightbox) closeLightbox();
            });
        }

        document.addEventListener('keydown', function (event) {
            if (!lightbox || !lightbox.classList.contains('show')) return;

            if (event.key === 'Escape') closeLightbox();
            if (event.key === 'ArrowLeft') renderLightbox(activeIndex - 1);
            if (event.key === 'ArrowRight') renderLightbox(activeIndex + 1);
        });

        slider.addEventListener('mouseenter', stopAutoSlide);
        slider.addEventListener('mouseleave', startAutoSlide);

        showSlide(0);
        startAutoSlide();
    }

    initWorkOrderRealisasiSlider();
    loadNotifications(false);
    startDashboardAlerts();

    setInterval(() => loadNotifications(true), 45000);
    setInterval(loadOmsetHarianAlert, 180000);

    window.addEventListener('resize', () => {
        if (window.innerWidth > 991) {
            closeSidebarMobile();
        }
    });
</script>
<script>

    // ===== ONE BELL NOTIFICATION MODE =====
    // Klik lonceng tetap membuka notif internal. Push permission hanya ditawarkan jika belum aktif.
    let dashboardOneBellPermissionAsked = false;

    async function maybeOfferPushFromBell() {
        return;
    }

    function bindOneBellNotification() {
        const bell = document.querySelector('.notif-btn');
        const dropdown = document.querySelector('.notif-dropdown');

        if (!bell || bell.dataset.oneBellBound === '1') return;
        bell.dataset.oneBellBound = '1';

        bell.addEventListener('click', function () {
            setTimeout(maybeOfferPushFromBell, 350);
        });
    }

    bindOneBellNotification();

    // Auto refresh badge notif internal supaya input dari visit.php naik jadi 1, 2, dst tanpa reload.
    let dashboardLastNotifCount = Number(document.querySelector('.notif-badge')?.textContent || '0') || 0;

    async function refreshDashboardNotifications() {
        try {
            const res = await fetch(dashboardAjaxUrl + '?ajax=notifications&_=' + Date.now(), { cache: 'no-store' });
            const data = await res.json();
            if (!data || !data.success) return;

            const items = Array.isArray(data.items) ? data.items : [];
            const count = Number(data.count || items.length || 0);

            const badge = document.querySelector('.notif-badge');
            if (badge) {
                badge.textContent = count;
                badge.style.display = count > 0 ? 'flex' : 'none';
            }

            const list = document.querySelector('.notif-list');
            if (list) {
                if (items.length < 1) {
                    list.innerHTML = '<div class="notif-empty">Belum ada notifikasi terbaru.</div>';
                } else {
                    list.innerHTML = items.map(function (item) {
                        const title = String(item.title || 'Notifikasi Baru');
                        const text = String(item.text || '');
                        const url = String(item.url || '#');
                        const type = String(item.type || 'info');

                        const safeTitle = title.replace(/[&<>"']/g, function (m) {
                            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m];
                        });
                        const safeText = text.replace(/[&<>"']/g, function (m) {
                            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m];
                        });
                        const safeUrl = url.replace(/"/g, '&quot;');

                        let icon = 'fa-bell';
                        if (type === 'visit_activity' || type === 'aktivitas') icon = 'fa-store';
                        if (type === 'visit_kegiatan' || type === 'kegiatan') icon = 'fa-list-check';

                        return '<a class="notif-item" href="' + safeUrl + '">' +
                            '<div class="notif-icon"><i class="fa-solid ' + icon + '"></i></div>' +
                            '<div class="notif-content"><small>' + safeTitle + '</small><p>' + safeText + '</p></div>' +
                            '</a>';
                    }).join('');
                }
            }

            if (count > dashboardLastNotifCount && dashboardLastNotifCount >= 0) {
                const bell = document.querySelector('.notif-btn');
                if (bell) {
                    bell.classList.add('push-ready');
                    setTimeout(function () { bell.classList.remove('push-ready'); }, 1200);
                }
            }

            dashboardLastNotifCount = count;
        } catch (err) {
            // silent agar dashboard tidak berat
        }
    }

    refreshDashboardNotifications();
    setInterval(refreshDashboardNotifications, 20000);

</script>

<script>
/* ===== DASHBOARD REALTIME NOTIF FIX ===== */
(function () {
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isSafari = /^((?!chrome|android|crios|fxios|edgios).)*safari/i.test(navigator.userAgent);
    window.webportalSkipPushPrompt = isIOS || isSafari;

    // Audio ringan untuk dashboard aktif. Di background/HP tertutup, bunyi mengikuti sistem HP dari Web Push.
    const ctxState = {
        audioCtx: null,
        unlocked: false
    };

    function unlockRealtimeSound() {
        if (ctxState.unlocked) return;
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            ctxState.audioCtx = ctxState.audioCtx || new AudioCtx();
            if (ctxState.audioCtx.state === 'suspended') ctxState.audioCtx.resume();
            ctxState.unlocked = true;
        } catch (e) {}
    }

    function playRealtimeDashboardSound() {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            const audioCtx = ctxState.audioCtx || new AudioCtx();
            ctxState.audioCtx = audioCtx;
            if (audioCtx.state === 'suspended') return;

            const now = audioCtx.currentTime;
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();

            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, now);
            osc.frequency.setValueAtTime(1175, now + 0.10);

            gain.gain.setValueAtTime(0.0001, now);
            gain.gain.exponentialRampToValueAtTime(0.18, now + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.38);

            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start(now);
            osc.stop(now + 0.42);
        } catch (e) {}
    }

    document.addEventListener('click', unlockRealtimeSound, { once: true });
    document.addEventListener('touchstart', unlockRealtimeSound, { once: true });

    // Override/guard push prompt: iOS/Safari dilewati agar user tidak diarahkan aktifkan notif realtime.
    const oldMaybeOffer = window.maybeOfferPushFromBell;
    window.maybeOfferPushFromBell = async function () {
        if (window.webportalSkipPushPrompt) return;
        if (typeof oldMaybeOffer === 'function') return oldMaybeOffer();
    };

    let lastRealtimeKey = localStorage.getItem('dashboard_last_realtime_notif_key_v2') || '';
    let initialized = false;

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function (m) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m];
        });
    }

    async function refreshDashboardNotificationsV2() {
        if (typeof dashboardAjaxUrl === 'undefined') return;

        try {
            const res = await fetch(dashboardAjaxUrl + '?ajax=notifications&_=' + Date.now(), { cache: 'no-store' });
            const data = await res.json();
            if (!data || !data.success) return;

            const items = Array.isArray(data.items) ? data.items : [];
            const count = Number(data.count || items.length || 0);
            const newestKey = items.length ? String((items[0].time_key || '') + '|' + (items[0].text || '')) : '';

            const badge = document.querySelector('.notif-badge');
            if (badge) {
                badge.textContent = count;
                badge.style.display = count > 0 ? 'flex' : 'none';
            }

            const list = document.querySelector('.notif-list');
            if (list) {
                if (!items.length) {
                    list.innerHTML = '<div class="notif-empty">Belum ada notifikasi terbaru.</div>';
                } else {
                    list.innerHTML = items.map(function (item) {
                        const rawType = String(item.type || 'info').toLowerCase();
                        const type = (rawType === 'kegiatan' || rawType === 'visit_kegiatan') ? 'kegiatan' : 'aktivitas';

                        const title = escapeHtml(item.title || 'Notifikasi Baru');
                        const text = escapeHtml(item.text || '');
                        const targetUrl = String(item.url || '#');

                        const canOpen = type === 'kegiatan'
                            ? dashboardNotifElementAccess.kegiatan
                            : dashboardNotifElementAccess.aktivitas;

                        const titleId = type === 'kegiatan' ? 'Notif Header Kegiatan' : 'Notif Header Aktivitas';
                        const titleEn = type === 'kegiatan' ? 'Activity Header Notification' : 'Visit Header Notification';
                        const icon = type === 'kegiatan' ? 'fa-list-check' : 'fa-store';
                        const lockedClass = canOpen ? '' : ' locked-element';

                        return '<a class="notif-item' + lockedClass + '" href="#" ' +
                            'data-url="' + escapeHtml(targetUrl) + '" ' +
                            'data-can-open="' + (canOpen ? '1' : '0') + '" ' +
                            'data-menu-title="' + escapeHtml(titleId) + '" ' +
                            'data-title-id="' + escapeHtml(titleId) + '" ' +
                            'data-title-en="' + escapeHtml(titleEn) + '" ' +
                            'data-notif-type="' + escapeHtml(type) + '" ' +
                            'data-locked="' + (canOpen ? '0' : '1') + '" ' +
                            'aria-disabled="' + (canOpen ? 'false' : 'true') + '">' +
                            '<div class="notif-icon"><i class="fa-solid ' + icon + '"></i></div>' +
                            '<div class="notif-content"><small>' + title + '</small><p>' + text + '</p></div>' +
                            '</a>';
                    }).join('');
                }
            }

            if (initialized && newestKey && newestKey !== lastRealtimeKey) {
                playRealtimeDashboardSound();

                if (window.Swal && items[0]) {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'info',
                        title: items[0].title || 'Notifikasi Baru',
                        text: items[0].text || '',
                        timer: 4200,
                        showConfirmButton: false,
                        customClass: { popup: 'live-toast' }
                    });
                }
            }

            if (newestKey) {
                lastRealtimeKey = newestKey;
                localStorage.setItem('dashboard_last_realtime_notif_key_v2', newestKey);
            }

            initialized = true;
        } catch (e) {}
    }

    window.refreshDashboardNotifications = refreshDashboardNotificationsV2;
    refreshDashboardNotificationsV2();
    setInterval(refreshDashboardNotificationsV2, 15000);
})();
</script>



<script>
/* ===== MANDATORY PUSH PERMISSION AFTER OMSET ALERT - MOBILE ONLY =====
   - Mobile/HP: wajib aktifkan notifikasi realtime setelah popup omset.
   - Desktop/Laptop/PC: dilewati, tidak muncul popup.
   - iOS Safari/unsupported: dilewati agar tidak stuck.
*/
window.webportalPushMandatoryFlow = window.webportalPushMandatoryFlow || {
    started: false,
    storageKey: 'webportal_push_permission_required_mobile_v1'
};

function webportalIsMobileDevice() {
    const ua = navigator.userAgent || '';
    const byAgent = /Android|iPhone|iPad|iPod|Opera Mini|IEMobile|Mobile/i.test(ua);
    const byScreen = window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
    return byAgent || byScreen;
}

function webportalIsIOSSafariOrUnsupportedPush() {
    const ua = navigator.userAgent || '';
    const isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isSafari = /^((?!chrome|android|crios|fxios|edgios).)*safari/i.test(ua);
    const unsupported = !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window);
    return unsupported || (isIOS && isSafari);
}

async function webportalRegisterPushSilently() {
    if (typeof enablePushNotification === 'function') {
        try {
            await enablePushNotification(true);
        } catch (e) {}
    }
}

async function webportalForceRealtimeNotificationPermission() {
    // Desktop / laptop / PC tidak perlu muncul popup permission.
    if (!webportalIsMobileDevice()) {
        return;
    }

    if (window.webportalPushMandatoryFlow.started) return;
    window.webportalPushMandatoryFlow.started = true;

    const key = window.webportalPushMandatoryFlow.storageKey;

    // iOS Safari / browser tidak support dilewati.
    if (webportalIsIOSSafariOrUnsupportedPush()) {
        localStorage.setItem(key, 'skip');
        return;
    }

    // Sudah aktif, cukup pastikan subscription tersimpan.
    if (Notification.permission === 'granted') {
        localStorage.setItem(key, 'granted');
        await webportalRegisterPushSilently();
        return;
    }

    // Kalau user pernah block/deny, browser tidak bisa dipaksa lagi lewat JS.
    if (Notification.permission === 'denied') {
        await Swal.fire({
            icon: 'warning',
            title: 'Notifikasi Wajib Diaktifkan',
            html: `
                Fitur notifikasi realtime wajib aktif untuk operasional divisi.<br><br>
                Status browser saat ini masih <b>Blocked / Ditolak</b>.<br>
                Silakan aktifkan izin notifikasi pada pengaturan browser/site settings,
                lalu refresh halaman ini.
            `,
            confirmButtonText: 'Saya Mengerti',
            allowOutsideClick: false,
            allowEscapeKey: false
        });
        return;
    }

    // Permission pertama kali: wajib aktifkan, tanpa tombol nanti.
    await Swal.fire({
        icon: 'info',
        title: 'Aktifkan Notifikasi Realtime',
        html: `
            Untuk mendukung operasional dan update realtime,
            fitur notifikasi wajib diaktifkan pada perangkat ini.
        `,
        confirmButtonText: 'Aktifkan Sekarang',
        allowOutsideClick: false,
        allowEscapeKey: false
    });

    const permission = await Notification.requestPermission();

    if (permission === 'granted') {
        localStorage.setItem(key, 'granted');
        await webportalRegisterPushSilently();

        await Swal.fire({
            icon: 'success',
            title: 'Notifikasi Aktif',
            text: 'Notifikasi realtime berhasil diaktifkan pada perangkat ini.',
            timer: 1700,
            showConfirmButton: false,
            allowOutsideClick: false,
            allowEscapeKey: false
        });

        return;
    }

    if (permission === 'denied') {
        await Swal.fire({
            icon: 'warning',
            title: 'Notifikasi Belum Aktif',
            html: `
                Notifikasi realtime wajib diaktifkan untuk menerima update operasional.<br><br>
                Silakan aktifkan izin notifikasi dari pengaturan browser,
                lalu refresh halaman ini.
            `,
            confirmButtonText: 'Saya Mengerti',
            allowOutsideClick: false,
            allowEscapeKey: false
        });
        return;
    }

    await Swal.fire({
        icon: 'error',
        title: 'Notifikasi Belum Aktif',
        text: 'Silakan klik Aktifkan Sekarang untuk melanjutkan aktivasi notifikasi realtime.',
        confirmButtonText: 'Coba Lagi',
        allowOutsideClick: false,
        allowEscapeKey: false
    });

    window.webportalPushMandatoryFlow.started = false;
    setTimeout(webportalForceRealtimeNotificationPermission, 500);
}

function webportalWaitOmsetThenForcePush() {
    // Desktop/laptop langsung skip dari awal.
    if (!webportalIsMobileDevice()) return;

    let tries = 0;

    const timer = setInterval(function () {
        tries++;

        // Tunggu semua popup SweetAlert selesai, termasuk popup omset.
        const popupOpen = document.body.classList.contains('swal2-shown') || document.querySelector('.swal2-container');

        if (!popupOpen || tries > 120) {
            clearInterval(timer);
            setTimeout(webportalForceRealtimeNotificationPermission, 500);
        }
    }, 500);
}

window.addEventListener('load', function () {
    // Beri waktu popup omset muncul dulu, lalu permission wajib muncul setelah omset ditutup.
    setTimeout(webportalWaitOmsetThenForcePush, 1300);
});

    function escapeDashboardHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getActiveSidebarLanguage() {
        return localStorage.getItem('dashboard_sidebar_language') || 'id';
    }

    function getLockedMenuTitle(menuEl) {
        const activeLang = getActiveSidebarLanguage();
        if (activeLang === 'en') {
            const englishTitle = menuEl.getAttribute('data-title-en')
                || menuEl.getAttribute('data-menu-title')
                || 'Menu';
            return dashboardT(englishTitle, 'en');
        }

        return menuEl.getAttribute('data-title-id')
            || menuEl.getAttribute('data-menu-title')
            || 'Menu';
    }

    // FINAL NOTIF HEADER RBAC HANDLER:
    // Semua klik notifikasi dicegah default dulu.
    // TRUE redirect manual dari data-url, FALSE popup akses dibatasi.
    document.addEventListener('click', function(e) {
        const notifItem = e.target.closest('.notif-item');
        if (!notifItem) return;

        e.preventDefault();
        e.stopPropagation();
        if (typeof e.stopImmediatePropagation === 'function') {
            e.stopImmediatePropagation();
        }

        const canOpen =
            notifItem.getAttribute('data-can-open') === '1' &&
            notifItem.getAttribute('data-locked') !== '1' &&
            notifItem.getAttribute('aria-disabled') !== 'true';

        const targetUrl = notifItem.getAttribute('data-url') || '#';

        if (canOpen && targetUrl !== '#') {
            window.location.href = targetUrl;
            return;
        }

        const activeLanguage = getActiveSidebarLanguage();
        const menuTitleSource = activeLanguage === 'en'
            ? (notifItem.getAttribute('data-title-en') || notifItem.getAttribute('data-menu-title') || 'Notifikasi')
            : (notifItem.getAttribute('data-title-id') || notifItem.getAttribute('data-menu-title') || 'Notifikasi');
        const menuTitle = dashboardT(menuTitleSource, activeLanguage);

        Swal.fire({
            customClass: { popup: 'rbac-access-popup' },
            html: `
                <div class="rbac-access-box">
                    <div class="rbac-access-icon">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div class="rbac-access-title">Akses Dibatasi</div>
                    <p class="rbac-access-text">
                        Anda tidak memiliki akses untuk membuka fitur ini.
                    </p>
                    <div class="rbac-access-menu">
                        <i class="fa-solid fa-circle-info"></i>
                        <span>${escapeDashboardHtml(menuTitle)}</span>
                    </div>
                </div>
            `,
            showConfirmButton: true,
            confirmButtonText: 'Mengerti',
            confirmButtonColor: '#2563eb',
            background: '#ffffff',
            color: '#0f172a'
        });
    }, true);

document.addEventListener('click', function(e) {
        const lockedMenu = e.target.closest('.locked-menu, .locked-element');
        if (e.target.closest('.notif-item')) return;
        if (!lockedMenu) return;

        e.preventDefault();
        e.stopPropagation();
        if (typeof e.stopImmediatePropagation === 'function') {
            e.stopImmediatePropagation();
        }

        const menuTitle = getLockedMenuTitle(lockedMenu);

        Swal.fire({
            customClass: {
                popup: 'rbac-access-popup'
            },
            html: `
                <div class="rbac-access-box">
                    <div class="rbac-access-icon">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div class="rbac-access-title">Akses Dibatasi</div>
                    <p class="rbac-access-text">
                        Anda tidak memiliki akses untuk membuka fitur ini.
                    </p>
                    <div class="rbac-access-menu">
                        <i class="fa-solid fa-circle-info"></i>
                        <span>${escapeDashboardHtml(menuTitle)}</span>
                    </div>
                </div>
            `,
            showConfirmButton: true,
            confirmButtonText: 'Mengerti',
            confirmButtonColor: '#2563eb',
            background: '#ffffff',
            color: '#0f172a',
            showClass: {
                popup: 'swal2-show'
            },
            hideClass: {
                popup: 'swal2-hide'
            }
        });
    }, true);


</script>


<div class="hero-photo-modal" id="heroPhotoModal" aria-hidden="true">
    <div class="hero-photo-frame">
        <button type="button" class="hero-photo-close" id="heroPhotoClose" aria-label="Tutup foto">
            <i class="fa fa-xmark"></i>
        </button>
        <img id="heroPhotoModalImg" src="" alt="Foto Informasi Dashboard">
    </div>
</div>

<script>
(function(){
    const modal = document.getElementById('heroPhotoModal');
    const modalImg = document.getElementById('heroPhotoModalImg');
    const closeBtn = document.getElementById('heroPhotoClose');

    if (!modal || !modalImg || !closeBtn) return;

    function openHeroPhoto(src) {
        if (!src) return;
        modalImg.src = src;
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeHeroPhoto() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        modalImg.removeAttribute('src');
        document.body.style.overflow = '';
    }

    document.addEventListener('click', function(e){
        const btn = e.target.closest('[data-hero-photo-open]');
        if (!btn) return;
        e.preventDefault();
        openHeroPhoto(btn.getAttribute('data-photo-src'));
    });

    closeBtn.addEventListener('click', closeHeroPhoto);
    modal.addEventListener('click', function(e){
        if (e.target === modal) closeHeroPhoto();
    });
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && modal.classList.contains('show')) {
            closeHeroPhoto();
        }
    });
})();
</script>

</body>
</html>
