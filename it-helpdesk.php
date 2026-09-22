<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}
require_once 'db.php';
if (isset($conn) && $conn instanceof mysqli) mysqli_set_charset($conn, 'utf8mb4');
date_default_timezone_set('Asia/Jakarta');

/*
 * IT HELPDESK OUTSTANDING EDITION
 * Outstanding = ticket sebelum hari ini yang statusnya belum SELESAI.
 * Ticket tersebut tetap dapat diproses dan diperbarui oleh Tim IT.
 */

/* ===== IT HELPDESK WEB PUSH NOTIFICATION CONFIG ===== */
if (!defined('PUSH_VAPID_PUBLIC_KEY')) {
    define('PUSH_VAPID_PUBLIC_KEY', 'BHQ1PIfZTKOkbsE_CoiXJWDAdFCt3y7IARB2FGQ7yNXcR3P0QJKuE25G6_hqAeC-NZTCsKK20icksEAoT66Ll-A');
}
if (!defined('PUSH_VAPID_PRIVATE_KEY')) {
    define('PUSH_VAPID_PRIVATE_KEY', 'uVwFV2KxoP8E2H-7tYZKsVYjyHGmAhEUYto7f1ZU1qI');
}
if (!defined('PUSH_VAPID_SUBJECT')) {
    define('PUSH_VAPID_SUBJECT', 'mailto:admin@srtcorp.online');
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function itUser($v){ return strtolower(trim((string)$v)); }
function itStatusLabel($s){
    $s = strtoupper(trim((string)$s));
    if ($s === 'PROSES') return 'Diproses';
    if ($s === 'SELESAI') return 'Selesai';
    if ($s === 'DITUNDA') return 'Ditunda';
    return 'Pending';
}
function itStatusUserText($s){
    $s = strtoupper(trim((string)$s));
    if ($s === 'PROSES') return 'Permintaan anda sedang di Proses';
    if ($s === 'SELESAI') return 'Permintaan anda telah selesai dikerjakan, silakan dicek';
    if ($s === 'DITUNDA') return 'Permintaan ditunda / menunggu tindak lanjut IT';
    return 'Pending - menunggu respon IT';
}
function itStatusClass($s){
    $s = strtoupper(trim((string)$s));
    if ($s === 'PROSES') return 'process';
    if ($s === 'SELESAI') return 'done';
    if ($s === 'DITUNDA') return 'hold';
    return 'pending';
}
function itColumnExists($conn, $table, $column) {
    $table = preg_replace('/[^A-Za-z0-9_]+/', '', (string)$table);
    $column = preg_replace('/[^A-Za-z0-9_]+/', '', (string)$column);
    if (!$conn || $table === '' || $column === '') return false;
    $stmt = $conn->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    if (!$stmt) return false;
    $stmt->bind_param('s', $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();
    return $ok;
}
function itEnsureTable($conn){
    if (!$conn) return false;
    @$conn->query("CREATE TABLE IF NOT EXISTS it_helpdesk_tickets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_no VARCHAR(20) DEFAULT NULL UNIQUE,
        requester_username VARCHAR(100) NOT NULL,
        requester_name VARCHAR(150) DEFAULT NULL,
        store_name VARCHAR(180) NOT NULL,
        issue_note LONGTEXT NOT NULL,
        photo_path VARCHAR(255) DEFAULT NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'PENDING',
        it_username VARCHAR(100) DEFAULT NULL,
        it_note LONGTEXT DEFAULT NULL,
        ticket_sent_at DATETIME DEFAULT NULL,
        process_at DATETIME DEFAULT NULL,
        done_at DATETIME DEFAULT NULL,
        user_status_seen_at DATETIME DEFAULT NULL,
        user_status_seen_key VARCHAR(120) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_requester (requester_username),
        INDEX idx_store (store_name),
        INDEX idx_status (status),
        INDEX idx_created (created_at),
        INDEX idx_ticket (ticket_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $cols = [
        'ticket_no' => "ALTER TABLE it_helpdesk_tickets ADD ticket_no VARCHAR(20) DEFAULT NULL UNIQUE AFTER id",
        'requester_username' => "ALTER TABLE it_helpdesk_tickets ADD requester_username VARCHAR(100) NOT NULL AFTER ticket_no",
        'requester_name' => "ALTER TABLE it_helpdesk_tickets ADD requester_name VARCHAR(150) DEFAULT NULL AFTER requester_username",
        'store_name' => "ALTER TABLE it_helpdesk_tickets ADD store_name VARCHAR(180) NOT NULL AFTER requester_name",
        'issue_note' => "ALTER TABLE it_helpdesk_tickets ADD issue_note LONGTEXT NOT NULL AFTER store_name",
        'photo_path' => "ALTER TABLE it_helpdesk_tickets ADD photo_path VARCHAR(255) DEFAULT NULL AFTER issue_note",
        'status' => "ALTER TABLE it_helpdesk_tickets ADD status VARCHAR(40) NOT NULL DEFAULT 'PENDING' AFTER photo_path",
        'it_username' => "ALTER TABLE it_helpdesk_tickets ADD it_username VARCHAR(100) DEFAULT NULL AFTER status",
        'it_note' => "ALTER TABLE it_helpdesk_tickets ADD it_note LONGTEXT DEFAULT NULL AFTER it_username",
        'ticket_sent_at' => "ALTER TABLE it_helpdesk_tickets ADD ticket_sent_at DATETIME DEFAULT NULL AFTER it_note",
        'process_at' => "ALTER TABLE it_helpdesk_tickets ADD process_at DATETIME DEFAULT NULL AFTER ticket_sent_at",
        'done_at' => "ALTER TABLE it_helpdesk_tickets ADD done_at DATETIME DEFAULT NULL AFTER process_at",
        'user_status_seen_at' => "ALTER TABLE it_helpdesk_tickets ADD user_status_seen_at DATETIME DEFAULT NULL AFTER done_at",
        'user_status_seen_key' => "ALTER TABLE it_helpdesk_tickets ADD user_status_seen_key VARCHAR(120) DEFAULT NULL AFTER user_status_seen_at",
        'it_push_notified_at' => "ALTER TABLE it_helpdesk_tickets ADD it_push_notified_at DATETIME DEFAULT NULL AFTER user_status_seen_key",
        'it_push_notified_key' => "ALTER TABLE it_helpdesk_tickets ADD it_push_notified_key VARCHAR(120) DEFAULT NULL AFTER it_push_notified_at"
    ];
    foreach ($cols as $col => $sql) if (!itColumnExists($conn, 'it_helpdesk_tickets', $col)) @ $conn->query($sql);
    @ $conn->query("ALTER TABLE it_helpdesk_tickets CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    return true;
}
function itGenerateTicketNo($conn){
    $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    for ($try=0; $try<50; $try++) {
        $prefix = '';
        for ($i=0; $i<3; $i++) $prefix .= $letters[random_int(0, strlen($letters)-1)];
        $no = $prefix . random_int(1000, 9999);
        $stmt = $conn->prepare("SELECT id FROM it_helpdesk_tickets WHERE ticket_no=? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $no);
            $stmt->execute();
            $res = $stmt->get_result();
            $exists = $res && $res->num_rows > 0;
            $stmt->close();
            if (!$exists) return $no;
        } else return $no;
    }
    return 'TKT' . time();
}


/* ============================================================
   IT HELPDESK PUSH NOTIFICATION SYSTEM
   Sinkron dengan dashboard.php: tabel push_subscriptions + VAPID key sama.
============================================================ */
function itJsonResponse(array $payload){
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function itEnsurePushSubscriptionsTable($conn){
    if (!$conn) return false;
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
    return true;
}

function itPushVapidConfigured(){
    return defined('PUSH_VAPID_PUBLIC_KEY')
        && defined('PUSH_VAPID_PRIVATE_KEY')
        && trim((string)PUSH_VAPID_PUBLIC_KEY) !== ''
        && trim((string)PUSH_VAPID_PRIVATE_KEY) !== ''
        && strpos((string)PUSH_VAPID_PUBLIC_KEY, 'ISI_') !== 0
        && strpos((string)PUSH_VAPID_PRIVATE_KEY, 'ISI_') !== 0;
}

function itFindComposerAutoload(){
    $candidates = [
        __DIR__ . '/vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
        dirname(dirname(__DIR__)) . '/vendor/autoload.php',
        '/var/www/html/vendor/autoload.php',
        '/var/www/html/appsheet/vendor/autoload.php',
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) return $candidate;
    }
    return '';
}

function itDeleteExpiredPushEndpoint($conn, $endpoint){
    $endpoint = trim((string)$endpoint);
    if (!$conn || $endpoint === '') return false;
    $stmt = mysqli_prepare($conn, "DELETE FROM push_subscriptions WHERE endpoint = ? LIMIT 1");
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 's', $endpoint);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function itSendWebPushToUsers($conn, array $targetUsers, $title, $body, $url = 'it-helpdesk.php', $icon = 'img/it.png'){
    $result = ['success'=>false, 'attempted'=>0, 'sent'=>0, 'failed'=>0, 'message'=>''];
    if (!$conn) { $result['message'] = 'Koneksi database tidak tersedia.'; return $result; }
    if (!itPushVapidConfigured()) { $result['message'] = 'VAPID key belum dikonfigurasi.'; return $result; }

    $autoload = itFindComposerAutoload();
    if ($autoload === '') { $result['message'] = 'vendor/autoload.php tidak ditemukan. Jalankan composer require minishlink/web-push.'; return $result; }
    require_once $autoload;

    if (!class_exists('Minishlink\\WebPush\\WebPush') || !class_exists('Minishlink\\WebPush\\Subscription')) {
        $result['message'] = 'Library minishlink/web-push belum tersedia.';
        return $result;
    }

    itEnsurePushSubscriptionsTable($conn);
    $cleanUsers = [];
    foreach ($targetUsers as $user) {
        $user = itUser($user);
        if ($user !== '') $cleanUsers[$user] = true;
    }
    $cleanUsers = array_keys($cleanUsers);
    if (empty($cleanUsers)) { $result['message'] = 'Target user kosong.'; return $result; }

    $subs = [];
    $placeholders = implode(',', array_fill(0, count($cleanUsers), '?'));
    $sql = "SELECT id, username, endpoint, p256dh, auth FROM push_subscriptions WHERE LOWER(username) IN ($placeholders) ORDER BY updated_at DESC";
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        $types = str_repeat('s', count($cleanUsers));
        $refs = [$types];
        foreach ($cleanUsers as $k => $v) { $refs[] = &$cleanUsers[$k]; }
        call_user_func_array([$stmt, 'bind_param'], $refs);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            if (!empty($row['endpoint']) && !empty($row['p256dh']) && !empty($row['auth'])) $subs[] = $row;
        }
        mysqli_stmt_close($stmt);
    }

    if (empty($subs)) { $result['message'] = 'Belum ada subscription aktif untuk target user.'; return $result; }

    $payload = json_encode([
        'title' => (string)$title,
        'body'  => (string)$body,
        'url'   => (string)$url,
        'icon'  => (string)$icon,
        'badge' => (string)$icon,
        'tag'   => 'it-helpdesk-' . md5((string)$title . '|' . (string)$body . '|' . (string)$url),
    ]);

    $webPush = new \Minishlink\WebPush\WebPush([
        'VAPID' => [
            'subject' => PUSH_VAPID_SUBJECT,
            'publicKey' => PUSH_VAPID_PUBLIC_KEY,
            'privateKey' => PUSH_VAPID_PRIVATE_KEY,
        ],
    ]);

    foreach ($subs as $sub) {
        $subscription = \Minishlink\WebPush\Subscription::create([
            'endpoint' => $sub['endpoint'],
            'publicKey' => $sub['p256dh'],
            'authToken' => $sub['auth'],
        ]);
        $webPush->queueNotification($subscription, $payload);
        $result['attempted']++;
    }

    foreach ($webPush->flush() as $report) {
        $endpoint = $report->getRequest()->getUri()->__toString();
        if ($report->isSuccess()) {
            $result['sent']++;
        } else {
            $result['failed']++;
            $reason = (string)$report->getReason();
            if (stripos($reason, '410') !== false || stripos($reason, '404') !== false || stripos($reason, 'gone') !== false || stripos($reason, 'expired') !== false || stripos($reason, 'unsubscribed') !== false) {
                itDeleteExpiredPushEndpoint($conn, $endpoint);
            }
        }
    }

    $result['success'] = $result['sent'] > 0;
    $result['message'] = $result['sent'] > 0 ? 'Push notification terkirim.' : 'Push notification gagal dikirim.';
    return $result;
}

function itHelpdeskPushRecipients(){
    // User penerima notifikasi HP untuk ticket IT baru.
    // Tambahkan username lain di sini jika diperlukan.
    return ['wira', 'aziz', 'yan', 'admin1'];
}


function itFetchTicketRowById($conn, $ticketId){
    $ticketId = (int)$ticketId;
    if (!$conn || $ticketId <= 0) return null;
    $stmt = $conn->prepare("SELECT * FROM it_helpdesk_tickets WHERE id=? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $ticketId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function itNotifyRequesterTicketStatus($conn, $ticketId, $statusLabel, $extraNote = ''){
    $ticket = itFetchTicketRowById($conn, $ticketId);
    if (!$ticket) return false;
    $requester = itUser($ticket['requester_username'] ?? '');
    if ($requester === '') return false;
    $ticketNo = trim((string)($ticket['ticket_no'] ?? ''));
    $store = trim((string)($ticket['store_name'] ?? 'Toko'));
    $body = $store . ' - ' . $statusLabel;
    if ($ticketNo !== '') $body = 'Ticket ' . $ticketNo . ': ' . $body;
    if (trim((string)$extraNote) !== '') $body .= ' - ' . trim((string)$extraNote);
    $res = itSendWebPushToUsers($conn, [$requester], 'Update Ticket IT', $body, 'visit.php?page=it-helpdesk', 'img/it.png');
    return !empty($res['success']);
}


/* ============================================================
   MONITORING SERVER MINIMARKET - MERGED FROM minimarket.php
   Membaca map.php jika tersedia, lalu tampil langsung di halaman utama IT Helpdesk.
============================================================ */
function itServerCheckOnline($ip, $port = 3306)
{
    $ip = trim((string)$ip);
    $port = (int)$port;
    if ($ip === '') return false;
    if ($port < 1) $port = 3306;
    $connection = @fsockopen($ip, $port, $errno, $errstr, 1.5);
    if ($connection) { fclose($connection); return true; }
    return false;
}
function itServerLoadMap()
{
    $serverMap = [];
    $mapFile = __DIR__ . '/map.php';
    if (is_file($mapFile)) {
        $map = [];
        require $mapFile;
        if (isset($map) && is_array($map)) $serverMap = $map;
    }
    return $serverMap;
}
function itServerBuildRows(array $serverMap)
{
    $rows = [];
    foreach ($serverMap as $nama => $data) {
        if (!is_array($data)) $data = [];
        $ip = (string)($data['ip'] ?? '');
        $port = isset($data['port']) ? (int)$data['port'] : 3306;
        $online = itServerCheckOnline($ip, $port);
        $rows[] = [
            'nama' => (string)$nama,
            'ip' => $ip,
            'port' => $port,
            'db' => (string)($data['db'] ?? '-'),
            'user' => (string)($data['user'] ?? '-'),
            'status' => $online ? 'ONLINE' : 'OFFLINE',
            'class' => $online ? 'online' : 'offline',
            'storeClass' => $online ? 'store-online' : 'store-offline',
            'barClass' => $online ? '' : 'offline-bar',
        ];
    }
    return $rows;
}
function itServerBuildPreviewRows(array $serverMap)
{
    // FAST LOAD PATCH:
    // Untuk page load normal, jangan ping semua server satu per satu.
    // Status real ONLINE/OFFLINE tetap dicek via AJAX server_monitor setelah halaman tampil.
    $rows = [];
    foreach ($serverMap as $nama => $data) {
        if (!is_array($data)) $data = [];
        $ip = (string)($data['ip'] ?? '');
        $port = isset($data['port']) ? (int)$data['port'] : 3306;
        $rows[] = [
            'nama' => (string)$nama,
            'ip' => $ip,
            'port' => $port,
            'db' => (string)($data['db'] ?? '-'),
            'user' => (string)($data['user'] ?? '-'),
            'status' => 'CHECKING',
            'class' => 'checking',
            'storeClass' => 'store-checking',
            'barClass' => 'checking-bar',
        ];
    }
    return $rows;
}
function itServerSummary(array $rows)
{
    $total = count($rows); $online = 0; $offline = 0;
    foreach ($rows as $r) {
        if (($r['status'] ?? '') === 'ONLINE') $online++; else $offline++;
    }
    return ['total'=>$total, 'online'=>$online, 'offline'=>$offline];
}
$itServerMap = itServerLoadMap();
if (isset($_GET['ajax']) && $_GET['ajax'] === 'server_monitor') {
    header('Content-Type: application/json; charset=utf-8');
    $serverRowsAjax = itServerBuildRows($itServerMap);
    echo json_encode([
        'success' => true,
        'rows' => $serverRowsAjax,
        'summary' => itServerSummary($serverRowsAjax),
        'time' => date('d M Y H:i:s'),
    ]);
    exit;
}
// FAST LOAD PATCH: page normal pakai preview agar pindah menu tidak menunggu fsockopen semua server.
$itServerRows = itServerBuildPreviewRows($itServerMap);
$itServerSummary = ['total'=>count($itServerRows), 'online'=>0, 'offline'=>0];

itEnsureTable($conn);
$username = itUser($_SESSION['username'] ?? '');
$profileName = $_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'IT Support';

function itResolveProfilePhoto($conn, $username){
    $username = itUser($username);
    if (!$conn || $username === '') return '';
    $tbl = @$conn->query("SHOW TABLES LIKE 'users'");
    if (!$tbl || $tbl->num_rows < 1) return '';
    $photoCol = '';
    foreach (['foto','photo','profile_photo','avatar','image_path','gambar'] as $c) {
        if (itColumnExists($conn, 'users', $c)) { $photoCol = $c; break; }
    }
    if ($photoCol === '') return '';
    $nameCol = itColumnExists($conn, 'users', 'username') ? 'username' : '';
    if ($nameCol === '') return '';
    $sql = "SELECT `{$photoCol}` AS foto_user FROM users WHERE username=? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return '';
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $foto = trim((string)($row['foto_user'] ?? ''));
    if ($foto === '') return '';
    if (preg_match('/^https?:\/\//i', $foto)) return $foto;
    $clean = ltrim($foto, '/');
    $candidates = [];
    if (strpos($clean, 'uploads/') === 0 || strpos($clean, 'img/') === 0) $candidates[] = $clean;
    else {
        $candidates[] = 'uploads/' . $clean;
        $candidates[] = 'uploads/profile/' . $clean;
        $candidates[] = 'uploads/user/' . $clean;
        $candidates[] = 'uploads/users/' . $clean;
        $candidates[] = $clean;
    }
    foreach ($candidates as $candidate) {
        if (is_file(__DIR__ . '/' . $candidate)) return $candidate;
    }
    return $candidates[0] ?? '';
}

function itDeleteLocalTicketPhoto($path){
    $path = trim((string)$path);
    if ($path === '' || preg_match('/^https?:\/\//i', $path)) return false;
    $path = strtok($path, '?#');
    $path = ltrim((string)$path, '/');
    if ($path === '' || strpos($path, '..') !== false) return false;
    $full = realpath(__DIR__ . '/' . $path);
    $base = realpath(__DIR__);
    if (!$full || !$base || strpos($full, $base) !== 0 || !is_file($full)) return false;
    return @unlink($full);
}
$profilePhoto = itResolveProfilePhoto($conn, $username);
$allowedItUsers = ['wira','aziz','yan','admin1','ujang','admin','admin2','it','support','itsupport'];
$canManage = in_array($username, $allowedItUsers, true);
$alert = ['type'=>'','title'=>'','text'=>''];


itEnsurePushSubscriptionsTable($conn);

/* =========================
   AJAX PUSH NOTIFICATION IT HELPDESK
========================= */
if (isset($_GET['ajax'])) {
    if ($_GET['ajax'] === 'push_config') {
        itJsonResponse([
            'success' => true,
            'configured' => itPushVapidConfigured(),
            'publicKey' => itPushVapidConfigured() ? PUSH_VAPID_PUBLIC_KEY : '',
            'message' => itPushVapidConfigured()
                ? 'Web Push IT Helpdesk siap digunakan.'
                : 'VAPID key belum diisi.'
        ]);
    }

    if ($_GET['ajax'] === 'save_push_subscription') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            itJsonResponse(['success' => false, 'message' => 'Method tidak diizinkan.']);
        }

        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            http_response_code(400);
            itJsonResponse(['success' => false, 'message' => 'Payload subscription tidak valid.']);
        }

        $endpoint = trim((string)($payload['endpoint'] ?? ''));
        $p256dh = trim((string)($payload['keys']['p256dh'] ?? ''));
        $auth = trim((string)($payload['keys']['auth'] ?? ''));
        $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            http_response_code(400);
            itJsonResponse(['success' => false, 'message' => 'Data subscription belum lengkap.']);
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
            itJsonResponse(['success' => false, 'message' => 'Gagal menyiapkan query subscription.']);
        }

        mysqli_stmt_bind_param($stmt, 'sssss', $username, $endpoint, $p256dh, $auth, $userAgent);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        itJsonResponse([
            'success' => (bool)$ok,
            'message' => $ok ? 'Notifikasi HP IT Helpdesk berhasil diaktifkan.' : 'Gagal menyimpan subscription.'
        ]);
    }

    if ($_GET['ajax'] === 'delete_push_subscription') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            itJsonResponse(['success' => false, 'message' => 'Method tidak diizinkan.']);
        }
        $payload = json_decode(file_get_contents('php://input'), true);
        $endpoint = trim((string)($payload['endpoint'] ?? ''));
        if ($endpoint !== '') itDeleteExpiredPushEndpoint($conn, $endpoint);
        itJsonResponse(['success' => true, 'message' => 'Subscription dihapus.']);
    }

    if ($_GET['ajax'] === 'it_push_check') {
        if (!$canManage) {
            itJsonResponse(['success'=>true, 'sent'=>0, 'checked'=>0, 'message'=>'User bukan penerima IT.']);
        }

        /*
           Push IT Helpdesk mengikuti NOTIF BIASA:
           - Notif biasa = ticket PENDING hari ini yang muncul di bell/popup.
           - Push hanya dikirim kalau ada ticket PENDING BARU setelah latest_id yang sudah diketahui browser.
           - Jadi saat halaman dibuka dan sudah ada pending lama, push tidak langsung meledak.
        */
        $afterId = (int)($_GET['after_id'] ?? 0);

        $pendingCountNow = 0;
        $latestPendingIdNow = 0;
        $qMeta = $conn->query("SELECT COUNT(*) AS total, COALESCE(MAX(id),0) AS latest_id FROM it_helpdesk_tickets WHERE UPPER(status)='PENDING' AND DATE(created_at)=CURDATE()");
        if ($qMeta && ($meta = $qMeta->fetch_assoc())) {
            $pendingCountNow = (int)($meta['total'] ?? 0);
            $latestPendingIdNow = (int)($meta['latest_id'] ?? 0);
        }

        // Kalau belum ada ticket pending baru melebihi patokan browser, tidak kirim push.
        if ($latestPendingIdNow <= $afterId) {
            itJsonResponse([
                'success'=>true,
                'checked'=>0,
                'processed'=>0,
                'attempted'=>0,
                'sent'=>0,
                'latest_id'=>$latestPendingIdNow,
                'pending_count'=>$pendingCountNow,
                'message'=>'Belum ada notif biasa baru.'
            ]);
        }

        $newTickets = [];
        $stmtPush = $conn->prepare("SELECT id, requester_username, requester_name, store_name, issue_note, created_at FROM it_helpdesk_tickets WHERE UPPER(status)='PENDING' AND DATE(created_at)=CURDATE() AND id > ? AND (it_push_notified_at IS NULL OR it_push_notified_at='0000-00-00 00:00:00') ORDER BY id ASC LIMIT 20");
        if ($stmtPush) {
            $stmtPush->bind_param('i', $afterId);
            $stmtPush->execute();
            $resPush = $stmtPush->get_result();
            while ($resPush && ($row = $resPush->fetch_assoc())) $newTickets[] = $row;
            $stmtPush->close();
        }

        $sentTotal = 0;
        $attemptedTotal = 0;
        $processedIds = [];
        foreach ($newTickets as $ticket) {
            $ticketIdPush = (int)($ticket['id'] ?? 0);
            $namaReq = trim((string)($ticket['requester_name'] ?: $ticket['requester_username'] ?: 'User'));
            $storeReq = trim((string)($ticket['store_name'] ?? 'Toko'));
            $issueReq = trim(preg_replace('/\s+/', ' ', (string)($ticket['issue_note'] ?? 'Ada permintaan IT baru.')));
            if (function_exists('mb_strlen') && mb_strlen($issueReq, 'UTF-8') > 90) $issueReq = mb_substr($issueReq, 0, 87, 'UTF-8') . '...';
            elseif (strlen($issueReq) > 90) $issueReq = substr($issueReq, 0, 87) . '...';

            $notifTitle = 'INFORMASI PENGADUAN';
            $notifBody = 'Dari toko "' . $storeReq . '" oleh user "' . $namaReq . '" perihal "' . $issueReq . '". Klik untuk proses.';

            $pushResult = itSendWebPushToUsers(
                $conn,
                itHelpdeskPushRecipients(),
                $notifTitle,
                $notifBody,
                'it-helpdesk.php?ticket_id=' . $ticketIdPush,
                'img/it.png'
            );
            $sentTotal += (int)($pushResult['sent'] ?? 0);
            $attemptedTotal += (int)($pushResult['attempted'] ?? 0);
            // Tandai sudah diproses walaupun belum ada subscription aktif, supaya tidak spam ulang setiap 5 detik.
            $processedIds[] = $ticketIdPush;
        }

        if (!empty($processedIds)) {
            $idsSql = implode(',', array_map('intval', $processedIds));
            $conn->query("UPDATE it_helpdesk_tickets SET it_push_notified_at=NOW(), it_push_notified_key=CONCAT('it-ticket-', id) WHERE id IN ($idsSql)");
        }

        $firstNotifText = '';
        $firstNotifTitle = 'INFORMASI PENGADUAN';
        if (!empty($newTickets)) {
            $firstTicket = $newTickets[0];
            $firstNama = trim((string)($firstTicket['requester_name'] ?: $firstTicket['requester_username'] ?: 'User'));
            $firstStore = trim((string)($firstTicket['store_name'] ?? 'Toko'));
            $firstIssue = trim(preg_replace('/\s+/', ' ', (string)($firstTicket['issue_note'] ?? 'Ada pengajuan baru.')));
            if (function_exists('mb_strlen') && mb_strlen($firstIssue, 'UTF-8') > 90) $firstIssue = mb_substr($firstIssue, 0, 87, 'UTF-8') . '...';
            elseif (strlen($firstIssue) > 90) $firstIssue = substr($firstIssue, 0, 87) . '...';
            $firstNotifText = 'Dari toko "' . $firstStore . '" oleh user "' . $firstNama . '" perihal "' . $firstIssue . '". Klik untuk proses.';
        }

        itJsonResponse([
            'success'=>true,
            'checked'=>count($newTickets),
            'processed'=>count($processedIds),
            'attempted'=>$attemptedTotal,
            'sent'=>$sentTotal,
            'latest_id'=>$latestPendingIdNow,
            'pending_count'=>$pendingCountNow,
            'notif_title'=>$firstNotifTitle,
            'notif_text'=>$firstNotifText,
            'message'=>$sentTotal > 0 ? 'Push mengikuti notif biasa pengaduan baru.' : 'Notif biasa baru terdeteksi, tetapi belum ada subscription aktif.'
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['ticket_action'] ?? '';
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $note = trim((string)($_POST['it_note'] ?? ''));

    /*
       Menjaga halaman asal setelah aksi ticket.
       Dashboard    : ticket aktif hari ini
       Outstanding  : ticket sebelum hari ini yang belum selesai
       History      : ticket yang sudah selesai
       Result       : rekap aktivitas hari ini
    */
    $redirectMode = strtolower(trim((string)($_POST['redirect_mode'] ?? 'dashboard')));
    if (!in_array($redirectMode, ['dashboard','outstanding','history','result'], true)) {
        $redirectMode = 'dashboard';
    }
    $redirectBase = $redirectMode === 'dashboard'
        ? 'it-helpdesk.php'
        : 'it-helpdesk.php?mode=' . rawurlencode($redirectMode);
    $redirectSeparator = strpos($redirectBase, '?') === false ? '?' : '&';

    if ($ticketId <= 0) {
        $alert = ['type'=>'error','title'=>'Data tidak valid','text'=>'Ticket tidak ditemukan.'];
    } elseif (!$canManage) {
        $alert = ['type'=>'error','title'=>'Akses ditolak','text'=>'User ini belum diberi akses dashboard IT.'];
    } elseif ($action === 'edit_it_note') {
        $editNoteOnly = trim((string)($_POST['it_note'] ?? ''));
        $stmt = $conn->prepare("UPDATE it_helpdesk_tickets SET it_username=?, it_note=?, user_status_seen_at=NULL, user_status_seen_key=NULL WHERE id=? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ssi', $username, $editNoteOnly, $ticketId);
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok) {
                itNotifyRequesterTicketStatus($conn, $ticketId, 'Catatan IT diperbarui', $editNoteOnly);
                header('Location: ' . $redirectBase . $redirectSeparator . 'note_edited=1&ticket_id=' . $ticketId);
                exit;
            }
        }
        $alert = ['type'=>'error','title'=>'Gagal edit catatan IT','text'=>$conn->error ?: 'Catatan IT gagal diperbarui.'];
    } elseif ($action === 'edit_ticket') {
        $editStore = trim((string)($_POST['store_name'] ?? ''));
        $editIssue = trim((string)($_POST['issue_note'] ?? ''));
        $editStatus = strtoupper(trim((string)($_POST['status'] ?? 'PENDING')));
        $editNote = trim((string)($_POST['it_note'] ?? ''));
        if (!in_array($editStatus, ['PENDING','PROSES','DITUNDA','SELESAI'], true)) $editStatus = 'PENDING';
        if ($editStore === '' || $editIssue === '') {
            $alert = ['type'=>'error','title'=>'Data belum lengkap','text'=>'Nama toko dan issue wajib diisi.'];
        } else {
            $stmt = $conn->prepare("UPDATE it_helpdesk_tickets SET store_name=?, issue_note=?, status=?, it_username=?, it_note=?, process_at=CASE WHEN ?='PROSES' THEN COALESCE(process_at,NOW()) ELSE process_at END, done_at=CASE WHEN ?='SELESAI' THEN COALESCE(done_at,NOW()) ELSE done_at END, user_status_seen_at=NULL, user_status_seen_key=NULL WHERE id=? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('sssssssi', $editStore, $editIssue, $editStatus, $username, $editNote, $editStatus, $editStatus, $ticketId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    itNotifyRequesterTicketStatus($conn, $ticketId, 'Data ticket diperbarui', $editNote);
                    header('Location: ' . $redirectBase . $redirectSeparator . 'edited=1&ticket_id=' . $ticketId);
                    exit;
                }
            }
            $alert = ['type'=>'error','title'=>'Gagal edit ticket','text'=>$conn->error ?: 'Ticket gagal diperbarui.'];
        }
    } elseif ($action === 'delete_ticket') {
        $oldPhoto = '';
        $stmtPhoto = $conn->prepare("SELECT photo_path FROM it_helpdesk_tickets WHERE id=? LIMIT 1");
        if ($stmtPhoto) {
            $stmtPhoto->bind_param('i', $ticketId);
            $stmtPhoto->execute();
            $resPhoto = $stmtPhoto->get_result();
            $rowPhoto = $resPhoto ? $resPhoto->fetch_assoc() : null;
            $oldPhoto = trim((string)($rowPhoto['photo_path'] ?? ''));
            $stmtPhoto->close();
        }
        $redirectUrl = $redirectBase . $redirectSeparator . 'deleted=1';

        $stmt = $conn->prepare("DELETE FROM it_helpdesk_tickets WHERE id=? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $ticketId);
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok) {
                if ($oldPhoto !== '') itDeleteLocalTicketPhoto($oldPhoto);
                header('Location: ' . $redirectUrl);
                exit;
            }
        }
        $alert = ['type'=>'error','title'=>'Gagal menghapus','text'=>$conn->error ?: 'Ticket gagal dihapus permanen.'];
    } elseif ($action === 'send_ticket') {
        $ticketNo = itGenerateTicketNo($conn);
        $newStatus = 'PROSES';
        $stmt = $conn->prepare("UPDATE it_helpdesk_tickets SET ticket_no=COALESCE(NULLIF(ticket_no,''), ?), status=?, it_username=?, it_note=?, ticket_sent_at=COALESCE(ticket_sent_at,NOW()), process_at=COALESCE(process_at,NOW()), user_status_seen_at=NULL, user_status_seen_key=NULL WHERE id=? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ssssi', $ticketNo, $newStatus, $username, $note, $ticketId);
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok) {
                itNotifyRequesterTicketStatus($conn, $ticketId, 'Permintaan sedang diproses oleh Tim IT', $note);
                header('Location: ' . $redirectBase . $redirectSeparator . 'sent=1&ticket_id=' . $ticketId);
                exit;
            }
        }
        $alert = ['type'=>'error','title'=>'Gagal kirim ticket','text'=>$conn->error ?: 'Ticket gagal diperbarui.'];
    } elseif ($action === 'update_status') {
        $status = strtoupper(trim((string)($_POST['status'] ?? 'PENDING')));
        if (!in_array($status, ['PENDING','PROSES','DITUNDA','SELESAI'], true)) $status = 'PENDING';
        $stmt = $conn->prepare("UPDATE it_helpdesk_tickets SET status=?, it_username=?, it_note=?, process_at=CASE WHEN ?='PROSES' THEN COALESCE(process_at,NOW()) ELSE process_at END, done_at=CASE WHEN ?='SELESAI' THEN COALESCE(done_at,NOW()) ELSE done_at END, user_status_seen_at=NULL, user_status_seen_key=NULL WHERE id=? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('sssssi', $status, $username, $note, $status, $status, $ticketId);
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok) {
                itNotifyRequesterTicketStatus($conn, $ticketId, 'Status: ' . itStatusLabel($status), $note);
                header('Location: ' . $redirectBase . $redirectSeparator . 'updated=1&ticket_id=' . $ticketId);
                exit;
            }
        }
        $alert = ['type'=>'error','title'=>'Gagal update','text'=>$conn->error ?: 'Status gagal diperbarui.'];
    }
}
if (isset($_GET['sent'])) $alert = ['type'=>'success','title'=>'No ticket terkirim','text'=>'No ticket sudah tampil di fitur IT Helpdesk user.'];
if (isset($_GET['updated'])) $alert = ['type'=>'success','title'=>'Status dikirim','text'=>'Status anda telah dikirim ke user.'];
if (isset($_GET['edited'])) $alert = ['type'=>'success','title'=>'Ticket berhasil diperbarui','text'=>'Perubahan data riwayat ticket sudah disimpan.'];
if (isset($_GET['note_edited'])) $alert = ['type'=>'success','title'=>'Catatan IT diperbarui','text'=>'Perubahan Catatan IT sudah berhasil disimpan.'];
if (isset($_GET['deleted'])) $alert = ['type'=>'success','title'=>'Ticket berhasil dihapus','text'=>'Data ticket sudah dihapus permanen dari sistem.'];

$filter = strtoupper(trim((string)($_GET['status'] ?? 'ALL')));
if (!in_array($filter, ['ALL','PENDING','PROSES','DITUNDA','SELESAI'], true)) $filter = 'ALL';
$search = trim((string)($_GET['q'] ?? ''));

$mode = strtolower(trim((string)($_GET['mode'] ?? 'dashboard')));
if (!in_array($mode, ['dashboard','outstanding','history','result'], true)) $mode = 'dashboard';

$counts = ['ALL'=>0,'PENDING'=>0,'PROSES'=>0,'DITUNDA'=>0,'SELESAI'=>0];
$qCnt = $conn->query("SELECT status, COUNT(*) AS total FROM it_helpdesk_tickets WHERE DATE(created_at)=CURDATE() AND UPPER(status) <> 'SELESAI' GROUP BY status");
if ($qCnt) while ($r=$qCnt->fetch_assoc()) { $s=strtoupper((string)$r['status']); if(isset($counts[$s])) $counts[$s]=(int)$r['total']; $counts['ALL']+=(int)$r['total']; }
$outstandingCount = 0;
$qOutCnt = $conn->query("
    SELECT COUNT(*) AS total
    FROM it_helpdesk_tickets
    WHERE DATE(created_at) < CURDATE()
      AND UPPER(status) <> 'SELESAI'
");
if ($qOutCnt && ($rr=$qOutCnt->fetch_assoc())) {
    $outstandingCount = (int)($rr['total'] ?? 0);
}

$historyCount = 0;
$qHisCnt = $conn->query("
    SELECT COUNT(*) AS total
    FROM it_helpdesk_tickets
    WHERE UPPER(status) = 'SELESAI'
");
if ($qHisCnt && ($rr=$qHisCnt->fetch_assoc())) {
    $historyCount = (int)($rr['total'] ?? 0);
}
$todayAllCount = 0;
$qTodayAll = $conn->query("SELECT COUNT(*) AS total FROM it_helpdesk_tickets WHERE DATE(created_at)=CURDATE()");
if ($qTodayAll && ($rr=$qTodayAll->fetch_assoc())) $todayAllCount = (int)($rr['total'] ?? 0);

$where = [];
$params = [];
$types = '';
if ($mode === 'history') {
    // Riwayat hanya berisi ticket yang sudah selesai.
    $where[] = "UPPER(status) = 'SELESAI'";
} elseif ($mode === 'outstanding') {
    // Ticket yang melewati hari tetap aktif dan bisa diproses/update.
    $where[] = "DATE(created_at) < CURDATE()";
    $where[] = "UPPER(status) <> 'SELESAI'";
} else {
    // Dashboard dan Result menggunakan ticket aktif hari ini.
    $where[] = "DATE(created_at) = CURDATE()";
    $where[] = "UPPER(status) <> 'SELESAI'";
}
if ($filter !== 'ALL') { $where[] = 'status=?'; $params[] = $filter; $types .= 's'; }
if ($search !== '') {
    $where[] = "(ticket_no LIKE CONCAT('%',?,'%') OR requester_username LIKE CONCAT('%',?,'%') OR requester_name LIKE CONCAT('%',?,'%') OR store_name LIKE CONCAT('%',?,'%') OR issue_note LIKE CONCAT('%',?,'%'))";
    for($i=0;$i<5;$i++){ $params[]=$search; $types.='s'; }
}
$sql = "SELECT * FROM it_helpdesk_tickets" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY FIELD(status,'PENDING','PROSES','DITUNDA','SELESAI'), created_at DESC LIMIT 300";
$stmt = $conn->prepare($sql);
$tickets = [];
if ($stmt) {
    if ($types !== '') {
        $refs = [$types];
        foreach ($params as $k=>$v) { $params[$k]=(string)$v; $refs[]=&$params[$k]; }
        call_user_func_array([$stmt,'bind_param'], $refs);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row=$res->fetch_assoc())) $tickets[]=$row;
    $stmt->close();
}
$latestTicketId = (int)($_GET['ticket_id'] ?? 0);

$pendingNotifTickets = [];
$qNotif = $conn->query("SELECT * FROM it_helpdesk_tickets WHERE status='PENDING' AND DATE(created_at)=CURDATE() ORDER BY created_at DESC LIMIT 20");
if ($qNotif) { while ($nr = $qNotif->fetch_assoc()) $pendingNotifTickets[] = $nr; }
$latestPendingNotif = $pendingNotifTickets[0] ?? null;
$pendingNotifCount = count($pendingNotifTickets);

function itFetchAll($conn, $sql){
    $rows = [];
    $q = $conn->query($sql);
    if ($q) while($r=$q->fetch_assoc()) $rows[]=$r;
    return $rows;
}
function itScalar($conn, $sql){
    $q=$conn->query($sql); if($q && ($r=$q->fetch_assoc())) return (int)($r['total'] ?? 0); return 0;
}
function itDateIndo($dateValue){
    $ts = strtotime((string)$dateValue); if(!$ts) $ts=time();
    $bulan=[1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    return date('d',$ts).' '.$bulan[(int)date('n',$ts)].' '.date('Y',$ts);
}
function itSafeImageSrc($path){
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('/^https?:\/\//i', $path)) return $path;
    return ltrim($path, '/');
}

function itPdfEscapeText($text){
    $text = (string)$text;
    $text = strip_tags($text);
    $text = preg_replace('/\s+/', ' ', $text);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
        if ($converted !== false) $text = $converted;
    }
    $text = preg_replace('/[^\x20-\x7E\xA0-\xFF]/', ' ', $text);
    return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $text);
}
function itPdfLimit($text, $len=40){
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string)$text)));
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $len) return mb_substr($text, 0, max(0,$len-3), 'UTF-8') . '...';
    if (!function_exists('mb_strlen') && strlen($text) > $len) return substr($text, 0, max(0,$len-3)) . '...';
    return $text;
}
function itPdfWrap($text, $maxChars, $maxLines=3){
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string)$text)));
    if ($text === '') return ['-'];
    $words = preg_split('/\s+/', $text);
    $lines = []; $line = '';
    foreach ($words as $w) {
        if ($line === '') { $line = $w; continue; }
        if (strlen($line . ' ' . $w) <= $maxChars) $line .= ' ' . $w;
        else { $lines[] = $line; $line = $w; }
        if (count($lines) >= $maxLines) break;
    }
    if (count($lines) < $maxLines && $line !== '') $lines[] = $line;
    if (count($lines) > $maxLines) $lines = array_slice($lines, 0, $maxLines);
    if (count($lines) === $maxLines && strlen(implode(' ', $words)) > strlen(implode(' ', $lines))) {
        $last = $lines[$maxLines-1];
        $lines[$maxLines-1] = substr($last, 0, max(0, $maxChars-3)) . '...';
    }
    return $lines ?: ['-'];
}
function itPdfText($x, $y, $text, $font='F1', $size=8, $r=0, $g=0, $b=0){
    return "BT\n/".$font." ".(float)$size." Tf\n".(float)$r." ".(float)$g." ".(float)$b." rg\n".(float)$x." ".(float)$y." Td\n(".itPdfEscapeText($text).") Tj\nET\n";
}
function itPdfCenter($y, $text, $font='F2', $size=14, $r=0, $g=0, $b=0, $pageW=842){
    $plain = preg_replace('/[^\x20-\x7E]/', '', (string)$text);
    $w = strlen($plain) * $size * 0.27;
    $x = max(24, ($pageW - $w) / 2);
    return itPdfText($x, $y, $text, $font, $size, $r, $g, $b);
}
function itPdfRect($x, $y, $w, $h, $r, $g, $b, $fill=true, $stroke=false){
    $op = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S');
    return (float)$r.' '.(float)$g.' '.(float)$b.($fill?' rg':' RG')."\n".(float)$x.' '.(float)$y.' '.(float)$w.' '.(float)$h." re {$op}\n";
}
function itPdfLine($x1,$y1,$x2,$y2,$r=0.86,$g=0.9,$b=0.96){
    return (float)$r.' '.(float)$g.' '.(float)$b." RG\n0.6 w\n".(float)$x1.' '.(float)$y1." m\n".(float)$x2.' '.(float)$y2." l\nS\n";
}
function itPdfResolveLocalPath($path){
    $path = trim((string)$path);
    if ($path === '' || preg_match('/^https?:\/\//i', $path)) return '';
    $path = strtok($path, '?#');
    $path = ltrim((string)$path, '/');
    $full = __DIR__ . '/' . $path;
    return is_file($full) ? $full : '';
}
function itPdfPrepareImage($rawPath){
    $full = itPdfResolveLocalPath($rawPath);
    if ($full === '') return null;
    $info = @getimagesize($full);
    if (!$info || empty($info[0]) || empty($info[1])) return null;
    $mime = strtolower((string)($info['mime'] ?? ''));
    $data = '';
    if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
        $data = (string)@file_get_contents($full);
    } elseif (function_exists('imagejpeg')) {
        $im = null;
        if ($mime === 'image/png' && function_exists('imagecreatefrompng')) $im = @imagecreatefrompng($full);
        elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $im = @imagecreatefromwebp($full);
        elseif ($mime === 'image/gif' && function_exists('imagecreatefromgif')) $im = @imagecreatefromgif($full);
        if ($im) {
            ob_start(); @imagejpeg($im, null, 82); $data = (string)ob_get_clean(); @imagedestroy($im);
        }
    }
    if ($data === '') return null;
    return ['data'=>$data,'w'=>(int)$info[0],'h'=>(int)$info[1]];
}

function itReportImageSrc($path){
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('/^https?:\/\//i', $path)) return $path;
    $full = itPdfResolveLocalPath($path);
    if ($full === '') return itSafeImageSrc($path);
    $info = @getimagesize($full);
    if (!$info || empty($info['mime'])) return itSafeImageSrc($path);
    $data = @file_get_contents($full);
    if ($data === false || $data === '') return itSafeImageSrc($path);
    return 'data:' . $info['mime'] . ';base64,' . base64_encode($data);
}
function itBuildRecapHtml(array $rows, $forPdf = false){
    $today = itDateIndo(date('Y-m-d'));
    $generated = date('d/m/Y H:i') . ' WIB';
    ob_start();
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <style>
            @page { margin: 18px; }
            *{box-sizing:border-box}
            body{font-family:Arial,Helvetica,sans-serif;background:#f3f6fb;color:#111827;margin:0;padding:<?php echo $forPdf ? '0' : '24px'; ?>}
            .report{max-width:1180px;margin:auto;background:#fff;border-radius:22px;padding:26px;box-shadow:<?php echo $forPdf ? 'none' : '0 18px 50px rgba(15,23,42,.12)'; ?>}
            .head{border-radius:18px;background:#1d4ed8;color:#fff;padding:18px 20px;text-align:center;margin-bottom:18px}
            .head h1{margin:0;text-transform:uppercase;font-size:22px;letter-spacing:.5px}
            .head p{margin:7px 0 0;color:#dbeafe;font-weight:700;font-size:12px}
            .summary{display:table;width:100%;margin:0 0 14px;border-collapse:separate;border-spacing:8px 0}
            .sum-card{display:table-cell;background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:10px 12px;color:#1e3a8a;font-size:12px;font-weight:800}
            .sum-card b{display:block;color:#0f172a;font-size:18px;margin-top:4px}
            .tools{display:flex;gap:10px;justify-content:flex-end;margin-bottom:16px}
            .tools a{border:0;border-radius:12px;padding:11px 14px;font-weight:800;text-decoration:none;background:#2563eb;color:white;cursor:pointer;font-size:13px}
            .tools a.back{background:#64748b}
            table{width:100%;border-collapse:collapse;font-size:11px;table-layout:fixed}
            th{background:#1d4ed8;color:#fff;padding:9px 7px;border:1px solid #1e40af;text-align:left;vertical-align:middle}
            td{padding:8px 7px;border:1px solid #dbe4f0;vertical-align:top;word-wrap:break-word;line-height:1.35}
            tr:nth-child(even) td{background:#f8fbff}
            .status{display:inline-block;border-radius:999px;padding:4px 8px;font-weight:900;font-size:10px;background:#fef3c7;color:#92400e}
            .status.process{background:#dbeafe;color:#1d4ed8}.status.done{background:#dcfce7;color:#166534}.status.hold{background:#fee2e2;color:#991b1b}
            .photo-grid img{width:88px;height:64px;object-fit:cover;border-radius:9px;border:1px solid #cbd5e1;display:block}
            .footer{margin-top:18px;text-align:center;color:#64748b;font-size:11px;font-weight:700;border-top:1px solid #e2e8f0;padding-top:12px}
            .muted{color:#64748b;font-size:10px;font-weight:700}.empty{text-align:center;padding:28px;color:#64748b;font-weight:800}
            <?php if(!$forPdf): ?>@media print{.tools{display:none}body{background:#fff;padding:0}.report{box-shadow:none;border-radius:0}th{-webkit-print-color-adjust:exact;print-color-adjust:exact;background:#1d4ed8!important;color:#fff!important}}<?php endif; ?>
        
/* ===== CLEAN SQUARE UI PATCH ===== */
.hero-shell,.hero-panel{display:none!important}
.stat-card,.chart-card,.requester-list,.ticket-list,.result-section,.ticket-card,
.topbar,.running-strip,.brand,.side-link,.mode-link,.toolbar,.searchbox,
.btn,.user-chip,.it-notif-bell,.it-modal-card,.it-popup-card,.it-new-ticket-main,
.requester-item,.ticket-head,.ticket-photo,.ticket-status,.result-actions a,.result-actions button,
.chart-icon,.stat-icon,.avatar,.profile-avatar-img,.filter-pill,.status,.status-pill,
input,textarea,select,button{border-radius:6px!important}
.stat-card:before,.chart-card:before,.hero-panel:before{border-radius:0!important}
.stats,.chart-grid{margin-top:0!important}


/* ===== IT HELP DESK ACTION + SIDEBAR LOGO PATCH ===== */
.logo{overflow:hidden!important;background:#ffffff!important;padding:0!important}
.logo:after{display:none!important}
.logo img{width:100%!important;height:100%!important;object-fit:cover!important;display:block!important;border-radius:18px!important}
.history-action-wrap{display:flex;align-items:center;gap:8px;flex-wrap:nowrap}
.history-icon-btn{width:38px;height:38px;min-height:38px;border:0;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;color:#fff;box-shadow:0 10px 22px rgba(15,23,42,.15);transition:.2s ease}
.history-icon-btn:hover{transform:translateY(-2px)}
.history-icon-edit{background:linear-gradient(135deg,#2563eb,#06b6d4)}
.history-icon-delete{background:linear-gradient(135deg,#dc2626,#f97316)}
.swal2-popup.it-swal-rounded{border-radius:26px!important}
.it-swal-form{display:grid;gap:10px;text-align:left;margin-top:8px}
.it-swal-form label{display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
.it-swal-form input,.it-swal-form textarea,.it-swal-form select{width:100%;border:1px solid #dbe4f0;border-radius:14px;padding:11px 12px;outline:none;font:inherit;font-size:13px;background:#f8fbff;color:#0f172a}
.it-swal-form textarea{min-height:95px;resize:vertical}
.it-note-box{margin-top:10px;background:#f0fdf4;border:1px solid #bbf7d0;padding:12px;border-radius:8px!important}
.it-note-box-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:7px}
.it-note-box-head b{color:#166534}
.it-note-edit-btn{border:0;background:#16a34a;color:#fff;border-radius:6px!important;padding:7px 10px;font-weight:800;font-size:12px;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.it-note-edit-btn:hover{filter:brightness(.95)}

</style>
    </head>
    <body>
    <div class="report">
        <?php if(!$forPdf): ?><div class="tools"><a href="it-helpdesk.php?download_pdf=today">Download PDF</a><a class="back" href="it-helpdesk.php?mode=result">Kembali</a></div><?php endif; ?>
        <div class="head">
            <h1>Rekap Aktivitas IT Support Minimarket</h1>
            <p>Tanggal <?php echo h($today); ?> &bull; Dibuat <?php echo h($generated); ?></p>
        </div>
        <div class="summary">
            <div class="sum-card">Total Permintaan<b><?php echo count($rows); ?></b></div>
            <div class="sum-card">Tanggal Rekap<b><?php echo h($today); ?></b></div>
            <div class="sum-card">Sumber Data<b>IT Helpdesk</b></div>
        </div>
        <table>
            <thead>
                <tr>
                    <th style="width:35px">No</th>
                    <th style="width:78px">Nomor Ticket</th>
                    <th style="width:130px">User / Jabatan</th>
                    <th style="width:95px">Toko</th>
                    <th>Issue</th>
                    <th style="width:75px">Status</th>
                    <th style="width:95px">Waktu</th>
                    <th>Catatan IT</th>
                    <th style="width:105px">Foto</th>
                </tr>
            </thead>
            <tbody>
            <?php if(empty($rows)): ?>
                <tr><td colspan="9" class="empty">Belum ada aktivitas IT hari ini.</td></tr>
            <?php endif; ?>
            <?php foreach($rows as $i=>$r): $stCls = itStatusClass($r['status'] ?? ''); ?>
                <tr>
                    <td><?php echo $i+1; ?></td>
                    <td><b><?php echo h($r['ticket_no'] ?: '-'); ?></b></td>
                    <td><b><?php echo h($r['requester_name'] ?: $r['requester_username']); ?></b><br><span class="muted"><?php echo h($r['requester_username']); ?></span></td>
                    <td><?php echo h($r['store_name']); ?></td>
                    <td><?php echo nl2br(h($r['issue_note'])); ?></td>
                    <td><span class="status <?php echo h($stCls); ?>"><?php echo h(itStatusLabel($r['status'])); ?></span></td>
                    <td><?php echo h(!empty($r['created_at']) ? date('d/m/Y H:i', strtotime($r['created_at'])) : '-'); ?></td>
                    <td><?php echo nl2br(h($r['it_note'] ?: '-')); ?></td>
                    <td><?php if(!empty($r['photo_path'])): ?><div class="photo-grid"><img src="<?php echo h(itReportImageSrc($r['photo_path'])); ?>" alt="Foto"></div><?php else: ?>-<?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="footer">Data valid system SQM Minimarket - IT Support Minimarket</div>
    </div>
    </body>
    </html>
    <?php
    return (string)ob_get_clean();
}
function itDownloadRecapPdf(array $rows){
    $filename = 'rekap-aktivitas-it-support-' . date('Y-m-d') . '.pdf';
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
    $html = itBuildRecapHtml($rows, true);
    while (ob_get_level()) { @ob_end_clean(); }
    if (class_exists('Dompdf\\Dompdf')) {
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        if (method_exists($options, 'set')) $options->set('isHtml5ParserEnabled', true);
        if (method_exists($options, 'setChroot')) $options->setChroot(__DIR__);
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $dompdf->stream($filename, ['Attachment' => true]);
        exit;
    }
    if (class_exists('Mpdf\\Mpdf')) {
        $mpdf = new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A4-L','margin_left'=>8,'margin_right'=>8,'margin_top'=>8,'margin_bottom'=>8]);
        $mpdf->WriteHTML($html);
        $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
        exit;
    }
    $pdf = itBuildTodayRecapPdf($rows);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

function itPdfImageDo($name, $x, $y, $boxW, $boxH, $imgW, $imgH){
    $scale = min($boxW / max(1,$imgW), $boxH / max(1,$imgH));
    $w = $imgW * $scale; $h = $imgH * $scale;
    $dx = $x + ($boxW - $w) / 2; $dy = $y + ($boxH - $h) / 2;
    return "q\n".(float)$w." 0 0 ".(float)$h." ".(float)$dx." ".(float)$dy." cm\n/".$name." Do\nQ\n";
}
function itBuildTodayRecapPdf(array $rows){
    $pageW = 842; $pageH = 595; $margin = 22;
    $cols = [24,60,98,78,172,58,70,130,80];
    $heads = ['No','Ticket','User / Jabatan','Toko','Issue','Status','Waktu','Catatan IT','Foto'];
    $objects = [];
    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
    $nextObj = 5; $images = [];
    $pages = []; $contentObjects = []; $pageObjects = [];
    $current = ''; $pageImgs = [];
    $y = 0;
    $drawHeader = function() use (&$current,&$y,$pageW,$pageH,$margin,$cols,$heads){
        $current .= itPdfRect(0, 0, $pageW, $pageH, 0.96, 0.98, 1, true, false);
        $current .= itPdfRect($margin, $pageH-72, $pageW-($margin*2), 48, 0.11, 0.32, 0.75, true, false);
        $current .= itPdfCenter($pageH-44, 'REKAP AKTIVITAS IT SUPPORT MINIMARKET', 'F2', 16, 1, 1, 1, $pageW);
        $current .= itPdfCenter($pageH-61, 'Tanggal '.itDateIndo(date('Y-m-d')), 'F1', 10, 1, 1, 1, $pageW);
        $x=$margin; $tableY=$pageH-104; $h=23;
        $current .= itPdfRect($x, $tableY, array_sum($cols), $h, 0.12, 0.29, 0.67, true, false);
        foreach($heads as $i=>$head){
            $current .= itPdfText($x+4, $tableY+8, $head, 'F2', 7.3, 1, 1, 1);
            $x += $cols[$i];
        }
        $y = $tableY;
    };
    $finishPage = function() use (&$current,&$pageImgs,&$pages,&$contentObjects,&$pageObjects,&$objects,&$nextObj,$pageW,$pageH,$margin){
        $current .= itPdfText($pageW/2-125, 22, 'Data valid system SQM Minimarket - IT Support Minimarket', 'F2', 8, .39, .45, .55);
        $contentId = $nextObj++;
        $objects[$contentId] = "<< /Length ".strlen($current)." >>\nstream\n".$current."endstream";
        $pageId = $nextObj++;
        $xobj = '';
        foreach($pageImgs as $nm=>$objId){ $xobj .= '/'.$nm.' '.$objId.' 0 R '; }
        $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageW} {$pageH}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>".($xobj!==''?' /XObject << '.$xobj.' >>':'')." >> /Contents {$contentId} 0 R >>";
        $pages[] = $pageId;
        $current=''; $pageImgs=[];
    };
    $drawHeader();
    if (empty($rows)) {
        $current .= itPdfRect($margin, $y-48, array_sum($cols), 48, 1,1,1, true, true);
        $current .= itPdfCenter($y-28, 'Belum ada aktivitas IT hari ini.', 'F2', 11, .39,.45,.55, $pageW);
    }
    foreach($rows as $idx=>$r){
        $rowH = 68;
        if ($y - $rowH < 52) { $finishPage(); $drawHeader(); }
        $top = $y; $bottom = $top - $rowH;
        $current .= itPdfRect($margin, $bottom, array_sum($cols), $rowH, 1,1,1, true, false);
        $x = $margin;
        foreach($cols as $cw){ $current .= itPdfLine($x, $bottom, $x, $top); $x += $cw; }
        $current .= itPdfLine($x, $bottom, $x, $top);
        $current .= itPdfLine($margin, $bottom, $margin+array_sum($cols), $bottom);
        $x=$margin; $ty=$top-14;
        $current .= itPdfText($x+4,$ty,(string)($idx+1),'F2',7.5,.12,.16,.24); $x += $cols[0];
        $current .= itPdfText($x+4,$ty,itPdfLimit($r['ticket_no'] ?: '-',12),'F2',7.3,.06,.18,.42); $x += $cols[1];
        $userLines = itPdfWrap(($r['requester_name'] ?: $r['requester_username']).' / '.$r['requester_username'], 21, 3);
        foreach($userLines as $li=>$ln) $current .= itPdfText($x+4,$ty-($li*9),$ln,$li===0?'F2':'F1',6.8,.12,.16,.24); $x += $cols[2];
        foreach(itPdfWrap($r['store_name'] ?? '-', 16, 3) as $li=>$ln) $current .= itPdfText($x+4,$ty-($li*9),$ln,'F1',6.8,.12,.16,.24); $x += $cols[3];
        foreach(itPdfWrap($r['issue_note'] ?? '-', 38, 5) as $li=>$ln) $current .= itPdfText($x+4,$ty-($li*9),$ln,'F1',6.6,.12,.16,.24); $x += $cols[4];
        $status = itStatusLabel($r['status'] ?? 'PENDING');
        $current .= itPdfText($x+4,$ty,$status,'F2',7,.06,.38,.22); $x += $cols[5];
        $created = !empty($r['created_at']) ? date('d/m/Y H:i', strtotime($r['created_at'])) : '-';
        foreach(itPdfWrap($created, 14, 2) as $li=>$ln) $current .= itPdfText($x+4,$ty-($li*9),$ln,'F1',6.8,.12,.16,.24); $x += $cols[6];
        foreach(itPdfWrap($r['it_note'] ?: '-', 29, 5) as $li=>$ln) $current .= itPdfText($x+4,$ty-($li*9),$ln,'F1',6.6,.12,.16,.24); $x += $cols[7];
        if (!empty($r['photo_path'])) {
            $img = itPdfPrepareImage($r['photo_path']);
            if ($img) {
                $imgKey = md5($r['photo_path'].'|'.strlen($img['data']));
                if (!isset($images[$imgKey])) {
                    $imgName = 'Im'.(count($images)+1);
                    $imgId = $nextObj++;
                    $objects[$imgId] = "<< /Type /XObject /Subtype /Image /Width ".$img['w']." /Height ".$img['h']." /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($img['data'])." >>\nstream\n".$img['data']."\nendstream";
                    $images[$imgKey] = ['name'=>$imgName,'id'=>$imgId,'w'=>$img['w'],'h'=>$img['h']];
                }
                $im = $images[$imgKey]; $pageImgs[$im['name']] = $im['id'];
                $current .= itPdfImageDo($im['name'], $x+6, $bottom+8, $cols[8]-12, $rowH-16, $im['w'], $im['h']);
            } else {
                $current .= itPdfText($x+6,$ty,'Foto tersedia','F2',6.6,.12,.16,.24);
            }
        } else {
            $current .= itPdfText($x+6,$ty,'-','F1',7,.39,.45,.55);
        }
        $y = $bottom;
    }
    $finishPage();
    $kids = '';
    foreach($pages as $pid) $kids .= $pid.' 0 R ';
    $objects[2] = "<< /Type /Pages /Kids [ {$kids}] /Count ".count($pages)." >>";
    ksort($objects);
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $xref = [0 => 0];
    foreach($objects as $id=>$obj){
        $xref[$id] = strlen($pdf);
        $pdf .= $id." 0 obj\n".$obj."\nendobj\n";
    }
    $start = strlen($pdf);
    $pdf .= "xref\n0 ".(max(array_keys($objects))+1)."\n";
    $pdf .= "0000000000 65535 f \n";
    for($i=1;$i<=max(array_keys($objects));$i++) $pdf .= sprintf('%010d 00000 n ', $xref[$i] ?? 0)."\n";
    $pdf .= "trailer\n<< /Size ".(max(array_keys($objects))+1)." /Root 1 0 R >>\nstartxref\n{$start}\n%%EOF";
    return $pdf;
}

$todayTotalReq = itScalar($conn, "SELECT COUNT(*) AS total FROM it_helpdesk_tickets WHERE DATE(created_at)=CURDATE()");
$weekTotalReq = itScalar($conn, "SELECT COUNT(*) AS total FROM it_helpdesk_tickets WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)");
$monthTotalReq = itScalar($conn, "SELECT COUNT(*) AS total FROM it_helpdesk_tickets WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())");

$chartTodayLabels=[]; $chartTodayValues=[]; $hourBucket=[];
for($i=0;$i<24;$i++){ $hourBucket[str_pad((string)$i,2,'0',STR_PAD_LEFT)] = 0; }
foreach(itFetchAll($conn, "SELECT DATE_FORMAT(created_at,'%H') AS label, COUNT(*) AS total FROM it_helpdesk_tickets WHERE DATE(created_at)=CURDATE() GROUP BY DATE_FORMAT(created_at,'%H')") as $r){ $hourBucket[$r['label']] = (int)$r['total']; }
foreach($hourBucket as $k=>$v){ $chartTodayLabels[]=$k.':00'; $chartTodayValues[]=$v; }

$chartWeekLabels=[]; $chartWeekValues=[]; $weekBucket=[];
for($i=6;$i>=0;$i--){ $d=date('Y-m-d', strtotime('-'.$i.' day')); $weekBucket[$d]=0; }
foreach(itFetchAll($conn, "SELECT DATE(created_at) AS label, COUNT(*) AS total FROM it_helpdesk_tickets WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(created_at)") as $r){ if(isset($weekBucket[$r['label']])) $weekBucket[$r['label']] = (int)$r['total']; }
foreach($weekBucket as $k=>$v){ $chartWeekLabels[]=date('d/m', strtotime($k)); $chartWeekValues[]=$v; }

$chartMonthLabels=[]; $chartMonthValues=[]; $monthBucket=[]; $daysInMonth=(int)date('t');
for($i=1;$i<=$daysInMonth;$i++){ $d=date('Y-m-').str_pad((string)$i,2,'0',STR_PAD_LEFT); $monthBucket[$d]=0; }
foreach(itFetchAll($conn, "SELECT DATE(created_at) AS label, COUNT(*) AS total FROM it_helpdesk_tickets WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE()) GROUP BY DATE(created_at)") as $r){ if(isset($monthBucket[$r['label']])) $monthBucket[$r['label']] = (int)$r['total']; }
foreach($monthBucket as $k=>$v){ $chartMonthLabels[]=date('d', strtotime($k)); $chartMonthValues[]=$v; }

$todayRecapTickets = itFetchAll($conn, "SELECT * FROM it_helpdesk_tickets WHERE DATE(created_at)=CURDATE() ORDER BY created_at DESC LIMIT 500");

if (isset($_GET['download_pdf']) && $_GET['download_pdf'] === 'today') {
    itDownloadRecapPdf($todayRecapTickets);
}

if (isset($_GET['report']) && $_GET['report'] === 'today') {
    echo itBuildRecapHtml($todayRecapTickets, false);
    exit;
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>IT Support Minimarket</title>
<link rel="icon" type="image/png" href="img/bulat.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{
    --bg:#eef4ff;
    --ink:#172033;
    --muted:#66758f;
    --soft:#f7faff;
    --line:rgba(91,117,160,.18);
    --card:rgba(255,255,255,.78);
    --card-solid:#ffffff;
    --primary:#3f6df6;
    --primary2:#06b6d4;
    --accent:#8b5cf6;
    --success:#16a34a;
    --warning:#d97706;
    --danger:#dc2626;
    --dark:#0b1220;
    --radius:6px;
    --shadow:0 22px 54px rgba(21,37,74,.12);
    --shadow2:0 16px 34px rgba(21,37,74,.10);
    --sidebar:286px;
}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%;scroll-behavior:smooth}
body{
    font-family:Inter,Segoe UI,Arial,sans-serif;
    color:var(--ink);
    background:
        radial-gradient(circle at 12% 0,rgba(63,109,246,.18),transparent 34%),
        radial-gradient(circle at 88% 12%,rgba(6,182,212,.16),transparent 30%),
        linear-gradient(135deg,#f8fbff 0%,#eef4ff 48%,#e9f7ff 100%);
    overflow-x:hidden;
    font-weight:400;
}
body:before{content:"";position:fixed;inset:0;pointer-events:none;background-image:linear-gradient(rgba(63,109,246,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(63,109,246,.045) 1px,transparent 1px);background-size:36px 36px;mask-image:linear-gradient(to bottom,rgba(0,0,0,.78),transparent 82%)}
a{text-decoration:none;color:inherit}button,input,textarea,select{font:inherit}
.layout{position:relative;z-index:1;min-height:100vh;display:grid;grid-template-columns:var(--sidebar) 1fr}
.sidebar{position:sticky;top:0;height:100vh;overflow:auto;padding:22px 16px;color:#dbeafe;background:linear-gradient(180deg,rgba(11,18,32,.96),rgba(14,25,48,.95));border-right:1px solid rgba(255,255,255,.10);box-shadow:20px 0 60px rgba(11,18,32,.18);transition:transform .28s ease,width .28s ease}.sidebar::-webkit-scrollbar{width:0}.sidebar{scrollbar-width:none}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:18px;padding:12px;border-radius:24px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09)}
.logo{position:relative;width:50px;height:50px;border-radius:18px;background:linear-gradient(135deg,var(--primary),var(--primary2));display:grid;place-items:center;box-shadow:0 18px 36px rgba(63,109,246,.30)}
.logo:after{content:"</>";position:absolute;right:-7px;bottom:-8px;font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:600;background:#0b1220;color:#67e8f9;border:1px solid rgba(103,232,249,.35);border-radius:999px;padding:4px 6px}.brand strong{display:block;font-size:16px;line-height:1.22;font-weight:600;letter-spacing:.1px}.brand small{display:block;margin-top:4px;color:#8fa5c7;font-size:12px;font-weight:400}.side-title{margin:20px 8px 10px;color:#7d8fac;font-size:11px;font-weight:600;letter-spacing:.8px;text-transform:uppercase}.side-link{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 13px;border-radius:17px;color:#dbeafe;text-decoration:none;margin-bottom:7px;border:1px solid transparent;transition:.22s ease}.side-link span{display:flex;align-items:center;gap:10px;font-weight:500;font-size:13px}.side-link i{width:18px;text-align:center;color:#9ec5ff}.side-link b{font-size:11px;font-weight:600;background:rgba(255,255,255,.10);padding:5px 8px;border-radius:999px;color:#dbeafe}.side-link:hover,.side-link.active{background:rgba(255,255,255,.10);border-color:rgba(255,255,255,.11);transform:translateX(3px)}.mode-link.active{background:linear-gradient(135deg,rgba(63,109,246,.34),rgba(6,182,212,.18));box-shadow:inset 0 0 0 1px rgba(255,255,255,.10)}
.content{min-width:0;padding:22px 24px 40px;min-height:100vh}.topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:14px;position:sticky;top:0;z-index:40;padding:12px;border-radius:30px;background:rgba(255,255,255,.70);border:1px solid rgba(255,255,255,.78);backdrop-filter:blur(18px);box-shadow:0 15px 38px rgba(21,37,74,.08)}.topbar-left{display:flex;align-items:center;gap:12px}.mobile-menu-btn{width:46px;height:46px;border:0;border-radius:16px;background:linear-gradient(135deg,var(--dark),#1e293b);color:#fff;display:none;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 14px 28px rgba(15,23,42,.16)}.title h1{margin:0;font-size:26px;letter-spacing:-.7px;font-weight:600}.title p{margin:5px 0 0;color:var(--muted);font-size:12.5px;font-weight:400}.top-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.top-return{background:#111827!important;min-height:46px;border-radius:16px}.user-chip{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid rgba(91,117,160,.18);border-radius:20px;padding:8px 12px;box-shadow:0 12px 28px rgba(21,37,74,.08)}.user-chip strong{font-size:13px;font-weight:600}.user-chip small{font-size:11px;color:var(--muted);font-weight:400}.avatar,.profile-avatar-img{width:40px;height:40px;border-radius:16px;display:grid;place-items:center;flex:0 0 auto}.avatar{background:linear-gradient(135deg,#e0f2fe,#eef2ff);color:#1d4ed8;font-weight:600}.profile-avatar-img{object-fit:cover;border:2px solid #fff;box-shadow:0 8px 20px rgba(21,37,74,.14)}
.it-notif-bell{position:relative;width:50px;height:50px;border:0;border-radius:18px;background:#fff;color:var(--primary);box-shadow:0 12px 28px rgba(21,37,74,.10);cursor:pointer;font-size:18px;border:1px solid rgba(91,117,160,.18)}.it-notif-bell span{position:absolute;right:-5px;top:-6px;min-width:22px;height:22px;border-radius:999px;background:linear-gradient(135deg,#ef4444,#f97316);color:#fff;display:grid;place-items:center;font-size:11px;font-weight:600;box-shadow:0 8px 18px rgba(239,68,68,.32)}.it-notif-bell.has-new{animation:itBellPulse 1.8s ease-in-out infinite}@keyframes itBellPulse{0%,100%{transform:translateY(0)}50%{transform:translateY(-4px)}}
.running-strip{height:42px;display:flex;align-items:center;gap:12px;margin:0 0 18px;border-radius:20px;overflow:hidden;background:linear-gradient(135deg,#0b1220,#172554);color:#dbeafe;border:1px solid rgba(255,255,255,.12);box-shadow:0 16px 34px rgba(15,23,42,.14)}.running-label{height:100%;display:flex;align-items:center;gap:8px;padding:0 16px;background:linear-gradient(135deg,var(--primary),var(--primary2));font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.7px;white-space:nowrap}.running-track{flex:1;overflow:hidden;white-space:nowrap}.running-text{display:inline-block;padding-left:100%;animation:runText 25s linear infinite;font-size:13px;font-weight:400;color:#c8d7ee}@keyframes runText{from{transform:translateX(0)}to{transform:translateX(-100%)}}
.hero-shell{position:relative;display:grid;grid-template-columns:1fr;gap:16px;margin-bottom:18px}.hero-panel,.stat-card,.chart-card,.requester-list,.ticket-list,.result-section,.ticket-card{backdrop-filter:blur(18px)}.hero-panel{position:relative;overflow:hidden;border-radius:32px;padding:24px;background:linear-gradient(135deg,rgba(255,255,255,.92),rgba(239,246,255,.80));border:1px solid rgba(255,255,255,.86);box-shadow:var(--shadow)}.hero-panel:before{content:"";position:absolute;width:240px;height:240px;right:-80px;top:-80px;border-radius:50%;background:radial-gradient(circle,rgba(63,109,246,.20),transparent 70%)}.hero-panel h2{position:relative;margin:0 0 8px;font-size:28px;letter-spacing:-.8px;font-weight:600}.hero-panel p{position:relative;margin:0;color:var(--muted);line-height:1.65;font-size:14px;max-width:760px}.hero-tags{position:relative;display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.hero-tags span{display:inline-flex;align-items:center;gap:7px;padding:9px 12px;border-radius:999px;background:#fff;border:1px solid rgba(91,117,160,.16);font-size:12px;color:#334155;font-weight:500}.terminal-card{border-radius:32px;background:linear-gradient(180deg,#0b1220,#111827);border:1px solid rgba(255,255,255,.12);box-shadow:var(--shadow);color:#dbeafe;overflow:hidden}.terminal-head{height:42px;display:flex;align-items:center;gap:7px;padding:0 14px;border-bottom:1px solid rgba(255,255,255,.09)}.terminal-dot{width:10px;height:10px;border-radius:50%;background:#ef4444}.terminal-dot:nth-child(2){background:#f59e0b}.terminal-dot:nth-child(3){background:#22c55e}.terminal-title{margin-left:7px;font-family:'JetBrains Mono',monospace;font-size:12px;color:#94a3b8}.terminal-code{padding:18px;font-family:'JetBrains Mono',monospace;font-size:12px;line-height:1.8;color:#b8c7df}.terminal-code span{color:#67e8f9}.terminal-code b{color:#86efac;font-weight:500}.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-bottom:18px}.stat-card{position:relative;overflow:hidden;border-radius:28px;background:var(--card);border:1px solid rgba(255,255,255,.88);padding:18px;box-shadow:var(--shadow2);transition:.25s ease}.stat-card:before{content:"";position:absolute;right:-28px;top:-36px;width:120px;height:120px;border-radius:50%;background:linear-gradient(135deg,rgba(63,109,246,.18),rgba(6,182,212,.12))}.stat-icon{position:relative;width:46px;height:46px;border-radius:18px;display:grid;place-items:center;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;margin-bottom:14px;box-shadow:0 16px 30px rgba(63,109,246,.22)}.stat-card small{position:relative;display:block;color:var(--muted);font-size:12px;font-weight:500;text-transform:none}.stat-card strong{position:relative;display:block;margin-top:8px;font-size:34px;letter-spacing:-1px;font-weight:600}.stat-card em{position:relative;display:block;margin-top:7px;color:#94a3b8;font-size:11px;font-style:normal}.fancy-hover{transition:transform .25s ease,box-shadow .25s ease,border-color .25s ease}.fancy-hover:hover{transform:translateY(-6px);box-shadow:0 28px 70px rgba(21,37,74,.16);border-color:rgba(63,109,246,.22)}
.chart-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px}.chart-card{border-radius:28px;background:var(--card);border:1px solid rgba(255,255,255,.86);padding:16px;box-shadow:var(--shadow2)}.chart-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}.chart-head small{display:block;color:var(--muted);font-size:12px;font-weight:500}.chart-head strong{display:block;margin-top:5px;font-size:24px;font-weight:600}.chart-icon{width:44px;height:44px;border-radius:17px;background:linear-gradient(135deg,#eff6ff,#ecfeff);color:var(--primary);display:grid;place-items:center;border:1px solid rgba(91,117,160,.14)}.chart-box{position:relative;height:210px}.toolbar{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;margin-bottom:16px}.searchbox{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.84);border:1px solid rgba(91,117,160,.17);border-radius:20px;padding:0 14px;box-shadow:0 10px 24px rgba(21,37,74,.05)}.searchbox input{height:50px;border:0;outline:0;width:100%;background:transparent;color:var(--ink);font-weight:400}.btn{border:0;border-radius:18px;min-height:50px;padding:0 16px;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 14px 28px rgba(63,109,246,.20);transition:.22s ease}.btn:hover{transform:translateY(-2px);box-shadow:0 18px 34px rgba(63,109,246,.25)}.btn.dark{background:#111827}.btn.green{background:linear-gradient(135deg,#22c55e,#16a34a)}.btn.gray{background:#64748b}.btn.light{background:#eef2ff!important;color:#3345d8!important;box-shadow:none!important}.board{display:grid;grid-template-columns:330px minmax(0,1fr);gap:16px}.requester-list,.ticket-list,.result-section{background:var(--card);border:1px solid rgba(255,255,255,.86);border-radius:30px;box-shadow:var(--shadow2);overflow:hidden}.panel-head{padding:16px 18px;border-bottom:1px solid rgba(91,117,160,.12);background:rgba(255,255,255,.45)}.panel-head strong{font-size:14px;font-weight:600}.requester-scroll{max-height:calc(100vh - 390px);overflow:auto;padding:10px}.requester-scroll::-webkit-scrollbar,.ticket-list::-webkit-scrollbar,.it-popup-panel::-webkit-scrollbar{width:6px}.requester-scroll::-webkit-scrollbar-thumb,.ticket-list::-webkit-scrollbar-thumb,.it-popup-panel::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:999px}.requester-item{display:grid;grid-template-columns:42px 1fr auto;gap:10px;align-items:center;border-radius:20px;padding:11px;text-decoration:none;color:var(--ink);transition:.2s ease}.requester-item:hover,.requester-item.active{background:rgba(63,109,246,.08);transform:translateX(2px)}.requester-item strong{font-size:13px;font-weight:600}.requester-item small{display:block;color:var(--muted);font-size:11px;font-weight:400;margin-top:3px}.ticket-list{padding:14px}.ticket-card{border:1px solid rgba(91,117,160,.14);border-radius:28px;padding:16px;margin-bottom:14px;background:rgba(255,255,255,.82);box-shadow:0 12px 26px rgba(21,37,74,.06);transition:.25s ease}.ticket-card.highlight{border-color:var(--primary);box-shadow:0 0 0 4px rgba(63,109,246,.11),0 18px 40px rgba(21,37,74,.10)}.ticket-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}.ticket-head h3{margin:0;font-size:18px;line-height:1.25;font-weight:600}.ticket-head p{margin:5px 0 0;color:var(--muted);font-size:12px;font-weight:400}.status{display:inline-flex;align-items:center;gap:7px;border-radius:999px;padding:8px 11px;font-size:11px;font-weight:600;white-space:nowrap}.status.pending{background:#fef3c7;color:#92400e}.status.process{background:#dbeafe;color:#1d4ed8}.status.done{background:#dcfce7;color:#166534}.status.hold{background:#fee2e2;color:#991b1b}.ticket-no{display:inline-flex;align-items:center;gap:7px;border-radius:999px;background:#111827;color:#fff;padding:8px 11px;font-size:11px;font-weight:600}.meta-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:9px;margin:12px 0}.meta{border-radius:18px;background:#f8fbff;border:1px solid rgba(91,117,160,.12);padding:10px}.meta small{display:block;color:#94a3b8;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.3px}.meta b{display:block;margin-top:4px;font-size:12px;font-weight:600;word-break:break-word}.issue{border-radius:20px;background:#f8fbff;border:1px solid rgba(91,117,160,.12);padding:12px;color:#334155;font-size:13px;line-height:1.6;white-space:pre-line;font-weight:400}.photo{display:block;margin-top:10px;border-radius:22px;overflow:hidden;background:#0f172a}.photo img{display:block;width:100%;max-height:300px;object-fit:cover}.actions{margin-top:12px;display:grid;grid-template-columns:1.1fr 1fr;gap:10px}.note textarea{width:100%;min-height:96px;border:1px solid rgba(91,117,160,.18);border-radius:19px;background:#f8fbff;padding:12px;resize:vertical;font:inherit;font-size:13px;color:var(--ink)}.status-select{height:46px;width:100%;border:1px solid rgba(91,117,160,.18);border-radius:17px;padding:0 12px;background:#fff;font-weight:500;color:var(--ink)}.action-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.empty{padding:42px;text-align:center;color:var(--muted);font-weight:500}.result-section{padding:16px;margin-bottom:18px}.result-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.result-head h2{margin:0;font-size:20px;font-weight:600}.result-actions{display:flex;gap:10px;flex-wrap:wrap}.table-wrap{overflow:auto;border-radius:22px;border:1px solid rgba(91,117,160,.14);background:#fff}.result-table{width:100%;border-collapse:collapse;min-width:900px;font-size:12px}.result-table th{background:#111827;color:#fff;text-align:left;padding:12px;font-weight:500}.result-table td{border-bottom:1px solid #eef2f7;padding:11px;vertical-align:top;line-height:1.45}.result-table small{color:var(--muted)}.mini-photo{width:80px;height:58px;object-fit:cover;border-radius:12px;border:1px solid #e2e8f0}.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.42);backdrop-filter:blur(4px);z-index:79}

/* ===== MERGED SERVER MONITORING SECTION ===== */
.server-monitor-section{margin-top:18px;background:rgba(11,18,32,.94);color:#e5eefc;border:1px solid rgba(255,255,255,.10);box-shadow:var(--shadow2);overflow:hidden}
.server-monitor-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:16px 18px;border-bottom:1px solid rgba(255,255,255,.10);background:linear-gradient(135deg,#0b1220,#172554)}
.server-title{display:flex;align-items:center;gap:12px}.server-title i{width:42px;height:42px;display:grid;place-items:center;background:linear-gradient(135deg,#22c55e,#06b6d4);color:#fff}.server-title h2{margin:0;font-size:18px;font-weight:600}.server-title p{margin:4px 0 0;color:#a7b7d4;font-size:12px;font-weight:400}.server-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.server-chip{display:inline-flex;align-items:center;gap:8px;min-height:38px;padding:0 12px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.10);font-size:12px;color:#dbeafe}.server-refresh{min-height:38px;border:0;background:linear-gradient(135deg,#2563eb,#06b6d4);color:#fff;padding:0 13px;cursor:pointer;font-weight:500}
.server-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;padding:14px;background:rgba(255,255,255,.035)}.server-card{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);padding:14px;transition:.2s ease}.server-card:hover{transform:translateY(-2px);background:rgba(255,255,255,.08)}.server-card.store-offline{opacity:.62}.server-card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}.server-card-head strong{font-size:15px;font-weight:600;color:#fff}.server-status{display:inline-flex;align-items:center;gap:7px;padding:7px 9px;font-size:11px;font-weight:600}.server-status.online{background:rgba(22,163,74,.18);color:#86efac;border:1px solid rgba(34,197,94,.25)}.server-status.offline{background:rgba(220,38,38,.18);color:#fecaca;border:1px solid rgba(248,113,113,.25)}.server-status.checking{background:rgba(59,130,246,.18);color:#bfdbfe;border:1px solid rgba(96,165,250,.25)}.server-info{display:grid;gap:8px;color:#c8d7ee;font-size:12px}.server-info div{display:flex;align-items:center;gap:8px;min-width:0}.server-info i{width:18px;color:#67e8f9}.server-info span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.server-bar{height:9px;margin-top:12px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.08);overflow:hidden}.server-bar-fill{height:100%;width:68%;background:linear-gradient(90deg,#22c55e,#06b6d4);animation:serverMove 2.1s linear infinite}.server-bar-fill.offline-bar{background:linear-gradient(90deg,#ef4444,#f97316)}.server-bar-fill.checking-bar{background:linear-gradient(90deg,#60a5fa,#06b6d4)}@keyframes serverMove{0%{width:22%}50%{width:92%}100%{width:44%}}.server-empty{padding:26px;text-align:center;color:#a7b7d4;background:rgba(255,255,255,.04);border:1px dashed rgba(255,255,255,.14);margin:14px}.server-empty b{display:block;color:#fff;margin-bottom:5px;font-weight:600}

.outstanding-stat{border-color:rgba(245,158,11,.28)!important;background:linear-gradient(145deg,rgba(255,255,255,.92),rgba(255,247,237,.88))!important}
.outstanding-stat .stat-icon{background:linear-gradient(135deg,#f59e0b,#ea580c)!important;box-shadow:0 16px 30px rgba(234,88,12,.22)!important}
.outstanding-banner{display:flex;align-items:flex-start;gap:14px;padding:16px 18px;margin-bottom:16px;background:linear-gradient(135deg,#fff7ed,#fffbeb);border:1px solid #fed7aa;color:#9a3412;box-shadow:0 12px 28px rgba(154,52,18,.08)}
.outstanding-banner i{width:42px;height:42px;display:grid;place-items:center;flex:0 0 42px;background:linear-gradient(135deg,#f59e0b,#ea580c);color:#fff}
.outstanding-banner strong{display:block;font-size:14px;margin-bottom:5px}
.outstanding-banner p{margin:0;font-size:12px;line-height:1.6;color:#9a3412}
.outstanding-age{background:#ffedd5!important;color:#9a3412!important;border:1px solid #fed7aa!important}

.it-popup-backdrop{position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;padding:22px;background:rgba(15,23,42,.56);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)}.it-popup-backdrop.show{display:flex}.it-popup-panel{width:min(94vw,650px);max-height:86vh;overflow:auto;border-radius:32px;background:#fff;box-shadow:0 34px 100px rgba(15,23,42,.36);border:1px solid rgba(255,255,255,.86);animation:itPop .22s ease-out both}.it-popup-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:18px 20px;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff}.it-popup-head-left{display:flex;align-items:center;gap:12px}.it-popup-icon{width:48px;height:48px;border-radius:18px;background:rgba(255,255,255,.18);display:grid;place-items:center;border:1px solid rgba(255,255,255,.28);font-size:20px}.it-popup-head strong{display:block;font-size:18px;line-height:1.2;font-weight:600}.it-popup-head small{display:block;margin-top:4px;color:rgba(255,255,255,.78);font-weight:400}.it-popup-close{width:42px;height:42px;border:0;border-radius:15px;background:rgba(255,255,255,.16);color:#fff;font-size:18px;cursor:pointer}.it-popup-body{padding:18px;display:grid;gap:13px}.it-new-ticket{border:1px solid #e2e8f0;border-radius:24px;padding:14px;background:linear-gradient(180deg,#fff,#f8fbff);box-shadow:0 12px 28px rgba(15,23,42,.06)}.it-new-ticket-main{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:start}.it-new-ticket h3{margin:0;font-size:17px;font-weight:600}.it-new-ticket p{margin:6px 0 0;color:var(--muted);font-size:12px;font-weight:400}.it-new-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin-top:12px}.it-new-meta div{border-radius:16px;background:#fff;border:1px solid #eef2f7;padding:10px}.it-new-meta small{display:block;color:#94a3b8;font-size:10px;text-transform:uppercase;font-weight:500}.it-new-meta b{display:block;margin-top:4px;font-size:12px;font-weight:600;word-break:break-word}.it-new-issue{margin-top:12px;border-radius:18px;background:#f8fafc;border:1px solid #edf2f7;padding:12px;color:#334155;font-size:13px;line-height:1.55;white-space:pre-line}.it-new-photo{display:block;margin-top:12px;border-radius:20px;overflow:hidden;background:#111827}.it-new-photo img{display:block;width:100%;max-height:260px;object-fit:cover}.it-popup-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:12px}.it-popup-actions .btn{min-height:44px;border-radius:15px}.it-popup-empty{padding:28px;text-align:center;color:var(--muted);font-weight:500}@keyframes itPop{from{opacity:0;transform:translateY(12px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}
@media(max-width:1180px){.hero-shell{grid-template-columns:1fr}.chart-grid{grid-template-columns:1fr 1fr}.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.board{grid-template-columns:1fr}.requester-scroll{max-height:320px}}
@media(max-width:900px){.layout{grid-template-columns:1fr}.sidebar{position:fixed;left:0;top:0;bottom:0;height:100vh;width:min(86vw,310px);z-index:90;transform:translateX(-105%)}.sidebar.show{transform:translateX(0)}.sidebar-overlay.show{display:block}.mobile-menu-btn{display:inline-flex}.content{padding:14px}.topbar{align-items:flex-start}.topbar-left{align-items:flex-start}.title h1{font-size:22px}.hero-panel h2{font-size:23px}.chart-grid{grid-template-columns:1fr}.toolbar{grid-template-columns:1fr}.top-actions{margin-left:auto}.top-return{display:none}.user-chip{padding:7px 9px}.running-strip{height:auto;min-height:42px}.running-label{min-height:42px}.stats{grid-template-columns:1fr 1fr}.actions{grid-template-columns:1fr}.meta-grid{grid-template-columns:1fr}.result-head{align-items:flex-start;flex-direction:column}}
@media(max-width:560px){.content{padding:12px}.topbar{border-radius:22px;padding:10px}.stats{grid-template-columns:1fr}.hero-panel{padding:20px;border-radius:26px}.hero-tags span{font-size:11px}.stat-card,.chart-card,.requester-list,.ticket-list,.ticket-card,.result-section{border-radius:24px}.it-notif-bell{width:46px;height:46px}.user-chip div:not(.avatar):not(.profile-avatar-img){display:none}.running-text{font-size:12px}.ticket-head{flex-direction:column}.requester-item{grid-template-columns:42px 1fr}.requester-item .status{grid-column:2}.it-new-ticket-main,.it-new-meta{grid-template-columns:1fr}.it-popup-body{padding:14px}}


/* ===== FIX LOGO SIDEBAR IT.PNG DI DALAM ICON BIRU ===== */
.brand .logo{
    width:50px!important;
    height:50px!important;
    min-width:50px!important;
    flex:0 0 50px!important;
    overflow:hidden!important;
    border-radius:18px!important;
    background:linear-gradient(135deg,var(--primary),var(--primary2))!important;
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
    padding:0!important;
    box-shadow:0 18px 36px rgba(63,109,246,.30)!important;
}
.brand .logo:after{display:none!important;content:none!important}
.brand .logo img{
    width:34px!important;
    height:34px!important;
    max-width:34px!important;
    max-height:34px!important;
    object-fit:contain!important;
    display:block!important;
    border-radius:0!important;
    position:static!important;
}


/* ===== SIDEBAR IT.PNG TANPA BINGKAI BIRU - FINAL FIX ===== */
.brand .logo{
    width:58px!important;
    height:58px!important;
    min-width:58px!important;
    flex:0 0 58px!important;
    overflow:visible!important;
    border-radius:0!important;
    background:transparent!important;
    box-shadow:none!important;
    border:0!important;
    padding:0!important;
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
}
.brand .logo:before,
.brand .logo:after{
    display:none!important;
    content:none!important;
}
.brand .logo img{
    width:54px!important;
    height:54px!important;
    max-width:54px!important;
    max-height:54px!important;
    object-fit:contain!important;
    display:block!important;
    border-radius:0!important;
    background:transparent!important;
    box-shadow:none!important;
}
</style>
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="layout">
    <aside class="sidebar" id="itSidebar">
        <div class="brand"><div class="logo"><img src="img/it.png" alt="IT Support"></div><div><strong>IT Support<br>Minimarket</strong><small>Helpdesk Dashboard</small></div></div>
        <div class="side-title">Menu</div>
        <a class="side-link mode-link <?php echo $mode==='dashboard'?'active':''; ?>" href="it-helpdesk.php"><span><i class="fa-solid fa-gauge-high"></i>Dashboard</span><b><?php echo (int)$counts['ALL']; ?></b></a>
        <a class="side-link mode-link <?php echo $mode==='result'?'active':''; ?>" href="it-helpdesk.php?mode=result"><span><i class="fa-solid fa-file-lines"></i>Result Aktivitas Hari Ini</span><b><?php echo (int)$todayAllCount; ?></b></a>
        <a class="side-link mode-link <?php echo $mode==='outstanding'?'active':''; ?>" href="it-helpdesk.php?mode=outstanding"><span><i class="fa-solid fa-triangle-exclamation"></i>Outstanding</span><b><?php echo (int)$outstandingCount; ?></b></a>
        <a class="side-link mode-link <?php echo $mode==='history'?'active':''; ?>" href="it-helpdesk.php?mode=history"><span><i class="fa-solid fa-clock-rotate-left"></i>Riwayat Permintaan</span><b><?php echo (int)$historyCount; ?></b></a>
        <a class="side-link" href="dashboard.php"><span><i class="fa-solid fa-arrow-left-long"></i>Kembali Webportal</span></a>
        <div class="side-title">Status Ticket Hari Ini</div>
        <?php foreach(['ALL'=>'Semua','PENDING'=>'Pending','PROSES'=>'Diproses','DITUNDA'=>'Ditunda'] as $s=>$label): ?>
            <a class="side-link <?php echo ($mode==='dashboard' && $filter===$s)?'active':''; ?>" href="it-helpdesk.php?status=<?php echo h($s); ?>"><span><i class="fa-solid <?php echo $s==='PROSES'?'fa-spinner':($s==='DITUNDA'?'fa-clock':'fa-inbox'); ?>"></i><?php echo h($label); ?></span><b><?php echo (int)($counts[$s] ?? 0); ?></b></a>
        <?php endforeach; ?>
    </aside>
    <main class="content">
        <div class="topbar">
            <div class="topbar-left">
                <button class="mobile-menu-btn" type="button" id="sidebarToggle" aria-label="Buka sidebar"><i class="fa-solid fa-bars-staggered"></i></button>
                <div class="title">
                    <h1><?php
                        echo $mode === 'history'
                            ? 'Riwayat Permintaan IT'
                            : ($mode === 'outstanding'
                                ? 'Outstanding Permintaan IT'
                                : ($mode === 'result'
                                    ? 'Result Aktivitas Hari Ini'
                                    : 'Dashboard IT Helpdesk'));
                    ?></h1>
                    <p><?php
                        echo $mode === 'outstanding'
                            ? 'Permintaan lewat hari yang belum selesai dan masih wajib ditindaklanjuti Tim IT.'
                            : 'Monitoring request, ticket tracking, dan aktivitas IT Support Minimarket.';
                    ?></p>
                </div>
            </div>
            <div class="top-actions">
                <button type="button" class="it-push-enable-btn" id="itPushEnableBtn" title="Aktifkan Notifikasi HP IT Helpdesk" aria-label="Aktifkan Notifikasi HP IT Helpdesk">
                    <i class="fa-solid fa-mobile-screen-button"></i><span>Notif HP</span>
                </button>
                <button type="button" class="it-notif-bell" id="itNotifBell" aria-label="Notifikasi permintaan IT">
                    <i class="fa-solid fa-bell"></i>
                    <?php if($pendingNotifCount > 0): ?><span><?php echo (int)$pendingNotifCount; ?></span><?php endif; ?>
                </button>
                <div class="user-chip">
                    <?php if(!empty($profilePhoto)): ?><img class="profile-avatar-img" src="<?php echo h($profilePhoto); ?>" alt="Foto profil"><?php else: ?><div class="avatar"><?php echo h(strtoupper(substr($profileName,0,1))); ?></div><?php endif; ?>
                    <div><strong><?php echo h($profileName); ?></strong><br><small><?php echo h($username); ?></small></div>
                </div>
            </div>
        </div>
        <div class="running-strip">
            <div class="running-label"><i class="fa-solid fa-satellite-dish"></i> LIVE SYSTEM</div>
            <div class="running-track"><div class="running-text">IT Helpdesk Minimarket aktif memantau permintaan user secara real-time &nbsp; ticket lewat hari otomatis masuk menu Outstanding dan tetap dapat diproses &nbsp; status langsung tampil di Visit App &nbsp; rekap harian siap diunduh PDF</div></div>
        </div>
        <div class="stats">
            <div class="stat-card fancy-hover"><div class="stat-icon"><i class="fa-solid fa-inbox"></i></div><small>Ticket Aktif Hari Ini</small><strong><?php echo (int)$counts['ALL']; ?></strong><em>antrian aktif</em></div>
            <div class="stat-card fancy-hover"><div class="stat-icon"><i class="fa-solid fa-hourglass-half"></i></div><small>Pending</small><strong><?php echo (int)$counts['PENDING']; ?></strong><em>menunggu respon</em></div>
            <div class="stat-card fancy-hover"><div class="stat-icon"><i class="fa-solid fa-screwdriver-wrench"></i></div><small>Diproses</small><strong><?php echo (int)$counts['PROSES']; ?></strong><em>sedang dikerjakan</em></div>
            <div class="stat-card fancy-hover"><div class="stat-icon"><i class="fa-solid fa-chart-simple"></i></div><small>Total Hari Ini</small><strong><?php echo (int)$todayAllCount; ?></strong><em>request masuk</em></div>
            <div class="stat-card fancy-hover outstanding-stat"><div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div><small>Outstanding</small><strong><?php echo (int)$outstandingCount; ?></strong><em>ticket lewat hari belum selesai</em></div>
        </div>
        <section class="chart-grid">
            <div class="chart-card fancy-hover"><div class="chart-head"><div><small>Permintaan 1 Hari</small><strong><?php echo (int)$todayTotalReq; ?></strong></div><div class="chart-icon"><i class="fa-solid fa-calendar-day"></i></div></div><div class="chart-box"><canvas id="chartToday"></canvas></div></div>
            <div class="chart-card fancy-hover"><div class="chart-head"><div><small>Permintaan 1 Minggu</small><strong><?php echo (int)$weekTotalReq; ?></strong></div><div class="chart-icon"><i class="fa-solid fa-chart-line"></i></div></div><div class="chart-box"><canvas id="chartWeek"></canvas></div></div>
            <div class="chart-card fancy-hover"><div class="chart-head"><div><small>Permintaan 1 Bulan</small><strong><?php echo (int)$monthTotalReq; ?></strong></div><div class="chart-icon"><i class="fa-solid fa-chart-column"></i></div></div><div class="chart-box"><canvas id="chartMonth"></canvas></div></div>
        </section>
        <?php if($mode === 'outstanding'): ?>
        <div class="outstanding-banner">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>
                <strong>Ticket Outstanding Tetap Aktif</strong>
                <p>Semua permintaan sebelum hari ini yang belum berstatus Selesai tetap dapat diproses, diberi nomor ticket, ditunda, diperbarui, dan diselesaikan oleh Tim IT.</p>
            </div>
        </div>
        <?php endif; ?>

        <form class="toolbar" method="GET">
            <input type="hidden" name="mode" value="<?php echo h($mode); ?>">
            <input type="hidden" name="status" value="<?php echo h($filter); ?>">
            <div class="searchbox"><i class="fa-solid fa-magnifying-glass"></i><input type="text" name="q" placeholder="Cari ticket, user, toko, atau catatan issue..." value="<?php echo h($search); ?>"></div>
            <button class="btn" type="submit"><i class="fa-solid fa-filter"></i>Filter</button>
        </form>
        <?php if(!$canManage): ?><div class="ticket-card" style="border-color:#f59e0b;background:#fffbeb"><strong>Akses dashboard masih read-only.</strong><p style="margin:8px 0 0;color:#92400e">Tambahkan username kamu ke daftar IT pada file ini jika perlu memproses ticket.</p></div><?php endif; ?>
        <?php if($mode === 'history'): ?>
        <section class="result-section fancy-hover">
            <div class="result-head">
                <div>
                    <h2>Riwayat Permintaan IT</h2>
                    <p style="margin:6px 0 0;color:#64748b;font-size:12px">Riwayat hanya menampilkan ticket yang sudah selesai. Ticket lama yang belum selesai berada di menu Outstanding.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table class="result-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nomor Ticket</th>
                            <th>User</th>
                            <th>Toko</th>
                            <th>Issue</th>
                            <th>Status</th>
                            <th>Waktu</th>
                            <th>Catatan IT</th>
                            <th>Foto</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if(empty($tickets)): ?>
                        <tr><td colspan="10" style="text-align:center;padding:30px;color:#64748b;font-weight:800">Belum ada riwayat permintaan.</td></tr>
                    <?php endif; ?>
                    <?php foreach($tickets as $i=>$r): ?>
                        <tr>
                            <td><?php echo $i+1; ?></td>
                            <td><b><?php echo h($r['ticket_no'] ?: '-'); ?></b></td>
                            <td><?php echo h($r['requester_name'] ?: $r['requester_username']); ?><br><small><?php echo h($r['requester_username']); ?></small></td>
                            <td><?php echo h($r['store_name']); ?></td>
                            <td><?php echo h($r['issue_note']); ?></td>
                            <td><span class="status <?php echo h(itStatusClass($r['status'])); ?>"><?php echo h(itStatusLabel($r['status'])); ?></span></td>
                            <td><?php echo h(date('d/m/Y H:i', strtotime($r['created_at']))); ?></td>
                            <td><?php echo nl2br(h($r['it_note'] ?: '-')); ?></td>
                            <td><?php if(!empty($r['photo_path'])): ?><a href="<?php echo h($r['photo_path']); ?>" target="_blank"><img class="mini-photo" src="<?php echo h($r['photo_path']); ?>" alt="Foto"></a><?php else: ?>-<?php endif; ?></td>
                            <td>
                                <?php if($canManage): ?>
                                <div class="history-action-wrap">
                                    <button type="button" class="history-icon-btn history-icon-edit" title="Edit ticket"
                                        data-id="<?php echo (int)$r['id']; ?>"
                                        data-store="<?php echo h($r['store_name']); ?>"
                                        data-issue="<?php echo h($r['issue_note']); ?>"
                                        data-status="<?php echo h(strtoupper((string)$r['status'])); ?>"
                                        data-note="<?php echo h($r['it_note'] ?? ''); ?>"
                                        onclick="itOpenEditTicket(this)"><i class="fa-solid fa-pen"></i></button>
                                    <button type="button" class="history-icon-btn" style="background:#16a34a" title="Edit Catatan IT"
                                        data-id="<?php echo (int)$r['id']; ?>"
                                        data-note="<?php echo h($r['it_note'] ?? ''); ?>"
                                        onclick="itOpenEditNote(this)"><i class="fa-solid fa-note-sticky"></i></button>
                                    <form method="POST" style="display:inline" onsubmit="return itConfirmDeleteTicket(event,this)">
                                        <input type="hidden" name="ticket_action" value="delete_ticket">
                                        <input type="hidden" name="ticket_id" value="<?php echo (int)$r['id']; ?>">
                                        <input type="hidden" name="redirect_mode" value="history">
                                        <button type="submit" class="history-icon-btn history-icon-delete" title="Hapus permanen"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </div>
                                <?php else: ?>-
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>
        <?php if($mode === 'result'): ?>
        <section class="result-section fancy-hover">
            <div class="result-head">
                <div><h2>Result Aktivitas IT Support Hari Ini</h2></div>
                <div class="result-actions">
                    <a class="btn" href="it-helpdesk.php?report=today" target="_blank"><i class="fa-solid fa-eye"></i>Preview Rekap</a>
                    <a class="btn dark" href="it-helpdesk.php?download_pdf=today"><i class="fa-solid fa-file-pdf"></i>Download PDF</a>
                </div>
            </div>
            <div class="table-wrap">
                <table class="result-table">
                    <thead><tr><th>No</th><th>Nomor Ticket</th><th>User</th><th>Toko</th><th>Issue</th><th>Status</th><th>Waktu</th><th>Catatan IT</th><th>Foto</th><th>Aksi</th></tr></thead>
                    <tbody>
                    <?php if(empty($todayRecapTickets)): ?><tr><td colspan="10" style="text-align:center;padding:30px;color:#64748b;font-weight:800">Belum ada aktivitas IT hari ini.</td></tr><?php endif; ?>
                    <?php foreach($todayRecapTickets as $i=>$r): ?>
                        <tr>
                            <td><?php echo $i+1; ?></td>
                            <td><b><?php echo h($r['ticket_no'] ?: '-'); ?></b></td>
                            <td><?php echo h($r['requester_name'] ?: $r['requester_username']); ?><br><small><?php echo h($r['requester_username']); ?></small></td>
                            <td><?php echo h($r['store_name']); ?></td>
                            <td><?php echo h($r['issue_note']); ?></td>
                            <td><span class="status <?php echo h(itStatusClass($r['status'])); ?>"><?php echo h(itStatusLabel($r['status'])); ?></span></td>
                            <td><?php echo h(date('d/m/Y H:i', strtotime($r['created_at']))); ?></td>
                            <td><?php echo nl2br(h($r['it_note'] ?: '-')); ?></td>
                            <td><?php if(!empty($r['photo_path'])): ?><a href="<?php echo h($r['photo_path']); ?>" target="_blank"><img class="mini-photo" src="<?php echo h($r['photo_path']); ?>" alt="Foto"></a><?php else: ?>-<?php endif; ?></td>
                            <td>
                                <?php if($canManage): ?>
                                <button type="button" class="history-icon-btn" style="background:#16a34a" title="Edit Catatan IT"
                                    data-id="<?php echo (int)$r['id']; ?>"
                                    data-note="<?php echo h($r['it_note'] ?? ''); ?>"
                                    onclick="itOpenEditNote(this)"><i class="fa-solid fa-note-sticky"></i></button>
                                <form method="POST" style="display:inline" onsubmit="return itConfirmDeleteTicket(event,this)">
                                    <input type="hidden" name="ticket_action" value="delete_ticket">
                                    <input type="hidden" name="ticket_id" value="<?php echo (int)$r['id']; ?>">
                                    <input type="hidden" name="redirect_mode" value="result">
                                    <button type="submit" class="history-icon-btn history-icon-delete" title="Hapus permanen"><i class="fa-solid fa-trash"></i></button>
                                </form>
                                <?php else: ?>-
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>
        <div class="board">
            <section class="requester-list">
                <div class="panel-head"><strong><?php
                    echo $mode === 'history'
                        ? 'Riwayat User / Toko'
                        : ($mode === 'outstanding'
                            ? 'User / Toko Outstanding'
                            : 'User / Toko Permintaan Hari Ini');
                ?></strong></div>
                <div class="requester-scroll">
                    <?php if(empty($tickets)): ?><div class="empty">Tidak ada ticket.</div><?php endif; ?>
                    <?php foreach($tickets as $t): ?>
                        <a class="requester-item <?php echo ((int)$t['id']===$latestTicketId)?'active':''; ?>" href="#ticket-<?php echo (int)$t['id']; ?>">
                            <div class="avatar"><?php echo h(strtoupper(substr($t['requester_name'] ?: $t['requester_username'],0,1))); ?></div>
                            <div><strong><?php echo h($t['requester_name'] ?: $t['requester_username']); ?></strong><small><?php echo h($t['store_name']); ?></small></div>
                            <span class="status <?php echo h(itStatusClass($t['status'])); ?>"><?php echo h(itStatusLabel($t['status'])); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
            <section class="ticket-list">
                <div class="panel-head"><strong><?php
                    echo $mode === 'history'
                        ? 'Riwayat Ticket'
                        : ($mode === 'outstanding'
                            ? 'Daftar Ticket Outstanding'
                            : 'Daftar Ticket Aktif Hari Ini');
                ?></strong></div>
                <?php if(empty($tickets)): ?><div class="empty"><?php
                    echo $mode === 'history'
                        ? 'Belum ada riwayat permintaan.'
                        : ($mode === 'outstanding'
                            ? 'Tidak ada ticket outstanding. Semua permintaan lama sudah selesai.'
                            : 'Belum ada permintaan IT Helpdesk aktif hari ini.');
                ?></div><?php endif; ?>
                <?php foreach($tickets as $t): ?>
                    <article class="ticket-card <?php echo ((int)$t['id']===$latestTicketId)?'highlight':''; ?>" id="ticket-<?php echo (int)$t['id']; ?>">
                        <div class="ticket-head">
                            <div><h3><?php echo h($t['store_name']); ?></h3><p><?php echo h($t['requester_name'] ?: $t['requester_username']); ?> · <?php echo h(date('d M Y H:i', strtotime($t['created_at']))); ?></p></div>
                            <div style="display:flex;align-items:center;justify-content:flex-end;gap:7px;flex-wrap:wrap">
                                <?php
                                $createdDay = date('Y-m-d', strtotime((string)$t['created_at']));
                                $ticketAgeDays = max(0, (int)floor(
                                    (strtotime(date('Y-m-d')) - strtotime($createdDay)) / 86400
                                ));
                                ?>
                                <?php if($ticketAgeDays > 0 && strtoupper((string)$t['status']) !== 'SELESAI'): ?>
                                    <span class="status outstanding-age"><i class="fa-solid fa-triangle-exclamation"></i>Outstanding <?php echo (int)$ticketAgeDays; ?> hari</span>
                                <?php endif; ?>
                                <span class="status <?php echo h(itStatusClass($t['status'])); ?>"><?php echo h(itStatusLabel($t['status'])); ?></span>
                            </div>
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
                            <?php if(!empty($t['ticket_no'])): ?><span class="ticket-no"><i class="fa-solid fa-ticket"></i><?php echo h($t['ticket_no']); ?></span><?php else: ?><span class="status pending"><i class="fa-solid fa-hourglass-half"></i>No ticket belum dikirim</span><?php endif; ?>
                            <span class="status <?php echo h(itStatusClass($t['status'])); ?>"><?php echo h(itStatusUserText($t['status'])); ?></span>
                        </div>
                        <div class="meta-grid"><div class="meta"><small>User</small><b><?php echo h($t['requester_username']); ?></b></div><div class="meta"><small>IT Handler</small><b><?php echo h($t['it_username'] ?: '-'); ?></b></div><div class="meta"><small>Update</small><b><?php echo h(date('d/m H:i', strtotime($t['updated_at']))); ?></b></div></div>
                        <div class="issue"><?php echo h($t['issue_note']); ?></div>
                        <?php if(!empty($t['photo_path'])): ?><a class="photo" href="<?php echo h($t['photo_path']); ?>" target="_blank"><img loading="lazy" src="<?php echo h($t['photo_path']); ?>" alt="Foto issue"></a><?php endif; ?>
                        <div class="it-note-box">
                            <div class="it-note-box-head">
                                <b>Catatan IT</b>
                                <?php if($canManage): ?>
                                <button type="button" class="it-note-edit-btn"
                                    data-id="<?php echo (int)$t['id']; ?>"
                                    data-note="<?php echo h($t['it_note'] ?? ''); ?>"
                                    onclick="itOpenEditNote(this)"><i class="fa-solid fa-pen-to-square"></i>Edit Catatan</button>
                                <?php endif; ?>
                            </div>
                            <div><?php echo !empty($t['it_note']) ? nl2br(h($t['it_note'])) : '<span style="color:#64748b">Belum ada catatan IT.</span>'; ?></div>
                        </div>
                        <div class="actions">
                            <form method="POST" class="note">
                                <input type="hidden" name="ticket_action" value="update_status"><input type="hidden" name="ticket_id" value="<?php echo (int)$t['id']; ?>">
                                <input type="hidden" name="redirect_mode" value="<?php echo h($mode); ?>">
                                <select class="status-select" name="status"><option value="PENDING" <?php echo strtoupper($t['status'])==='PENDING'?'selected':''; ?>>Pending</option><option value="PROSES" <?php echo strtoupper($t['status'])==='PROSES'?'selected':''; ?>>Proses</option><option value="DITUNDA" <?php echo strtoupper($t['status'])==='DITUNDA'?'selected':''; ?>>Ditunda</option><option value="SELESAI" <?php echo strtoupper($t['status'])==='SELESAI'?'selected':''; ?>>Selesai</option></select>
                                <textarea name="it_note" placeholder="Catatan IT jika ada hal yang belum selesai hari ini..."><?php echo h($t['it_note']); ?></textarea>
                                <div class="action-row"><button class="btn" type="submit"><i class="fa-solid fa-rotate"></i>Update Status</button></div>
                            </form>
                            <form method="POST" class="note">
                                <input type="hidden" name="ticket_action" value="send_ticket"><input type="hidden" name="ticket_id" value="<?php echo (int)$t['id']; ?>">
                                <input type="hidden" name="redirect_mode" value="<?php echo h($mode); ?>">
                                <textarea name="it_note" placeholder="Catatan awal saat mengirim nomor ticket..."><?php echo h($t['it_note']); ?></textarea>
                                <div class="action-row"><button class="btn dark" type="submit"><i class="fa-solid fa-ticket"></i>Kirimkan No Ticket</button><button class="btn green" type="submit" onclick="this.form.ticket_action.value='update_status'; var s=document.createElement('input');s.type='hidden';s.name='status';s.value='SELESAI';this.form.appendChild(s);"><i class="fa-solid fa-circle-check"></i>Selesai</button></div>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        </div>
        <?php if($mode === 'dashboard'): ?>
        <section class="server-monitor-section" id="serverMonitoringSection">
            <div class="server-monitor-head">
                <div class="server-title">
                    <i class="fa-solid fa-server"></i>
                    <div>
                        <h2>Monitoring Server Minimarket</h2>
                        <p>Status server dicek realtime via AJAX agar perpindahan menu tetap cepat.</p>
                    </div>
                </div>
                <div class="server-actions">
                    <span class="server-chip"><i class="fa-solid fa-circle-check"></i> Online: <b id="serverOnlineCount"><?php echo (int)$itServerSummary['online']; ?></b></span>
                    <span class="server-chip"><i class="fa-solid fa-triangle-exclamation"></i> Offline: <b id="serverOfflineCount"><?php echo (int)$itServerSummary['offline']; ?></b></span>
                    <span class="server-chip"><i class="fa-regular fa-clock"></i> <b id="serverLiveClock"><?php echo h(date('d M Y H:i:s')); ?></b></span>
                    <button class="server-refresh" type="button" onclick="itUpdateServerStatus()"><i class="fa-solid fa-rotate-right"></i> Refresh</button>
                </div>
            </div>
            <?php if(empty($itServerRows)): ?>
                <div class="server-empty"><b>map.php belum terbaca</b>Pastikan file <code>map.php</code> ada di folder yang sama dan berisi array <code>$map</code>.</div>
            <?php else: ?>
            <div class="server-grid">
                <?php foreach($itServerRows as $i=>$srv): ?>
                    <article class="server-card <?php echo h($srv['storeClass']); ?>" id="serverCard<?php echo (int)$i; ?>">
                        <div class="server-card-head">
                            <strong><?php echo h($srv['nama']); ?></strong>
                            <span class="server-status <?php echo h($srv['class']); ?>" id="serverStatus<?php echo (int)$i; ?>"><i class="fa-solid fa-signal"></i><?php echo h($srv['status']); ?></span>
                        </div>
                        <div class="server-info">
                            <div><i class="fa-solid fa-network-wired"></i><span><?php echo h($srv['ip']); ?>:<?php echo h($srv['port']); ?></span></div>
                            <div><i class="fa-solid fa-database"></i><span><?php echo h($srv['db']); ?></span></div>
                            <div><i class="fa-solid fa-user"></i><span><?php echo h($srv['user']); ?></span></div>
                        </div>
                        <div class="server-bar"><div class="server-bar-fill <?php echo h($srv['barClass']); ?>" id="serverBar<?php echo (int)$i; ?>"></div></div>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </main>
</div>

<div class="it-popup-backdrop" id="itNotifPopup" aria-hidden="true">
    <div class="it-popup-panel" role="dialog" aria-modal="true">
        <div class="it-popup-head">
            <div class="it-popup-head-left"><div class="it-popup-icon"><i class="fa-solid fa-headset"></i></div><div><strong>INFORMASI PENGADUAN</strong><small>Pengajuan baru dari user SQM Visit</small></div></div>
            <button type="button" class="it-popup-close" id="itNotifClose" aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="it-popup-body">
            <?php if(empty($pendingNotifTickets)): ?>
                <div class="it-popup-empty"><i class="fa-solid fa-circle-check" style="font-size:38px;color:#22c55e;margin-bottom:10px"></i><br>Tidak ada permintaan pending.</div>
            <?php endif; ?>
            <?php foreach($pendingNotifTickets as $nt): ?>
                <div class="it-new-ticket" data-ticket-id="<?php echo (int)$nt['id']; ?>">
                    <div class="it-new-ticket-main">
                        <div><h3><?php echo h($nt['requester_name'] ?: $nt['requester_username']); ?></h3><p><?php echo h($nt['store_name']); ?></p></div>
                        <span class="status pending"><i class="fa-solid fa-clock"></i> Pending</span>
                    </div>
                    <div class="it-new-meta">
                        <div><small>Tanggal</small><b><?php echo h(date('d M Y', strtotime($nt['created_at']))); ?></b></div>
                        <div><small>Jam</small><b><?php echo h(date('H:i', strtotime($nt['created_at']))); ?> WIB</b></div>
                    </div>
                    <div class="it-new-issue"><b>Perihal:</b><br><?php echo h($nt['issue_note']); ?></div>
                    <?php if(!empty($nt['photo_path'])): ?><a class="it-new-photo" href="<?php echo h($nt['photo_path']); ?>" target="_blank"><img src="<?php echo h($nt['photo_path']); ?>" alt="Foto issue"></a><?php endif; ?>
                    <div class="it-popup-actions">
                        <form method="POST" style="display:inline-flex">
                            <input type="hidden" name="ticket_action" value="send_ticket">
                            <input type="hidden" name="ticket_id" value="<?php echo (int)$nt['id']; ?>">
                            <input type="hidden" name="redirect_mode" value="dashboard">
                            <input type="hidden" name="it_note" value="Permintaan diterima dan sedang diproses oleh Tim IT.">
                            <button class="btn" type="submit"><i class="fa-solid fa-screwdriver-wrench"></i> Klik untuk Proses</button>
                        </form>
                        <a class="btn light" href="#ticket-<?php echo (int)$nt['id']; ?>"><i class="fa-solid fa-eye"></i> Lihat Data</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
(function(){
    const sidebar=document.getElementById('itSidebar');
    const overlay=document.getElementById('sidebarOverlay');
    const toggle=document.getElementById('sidebarToggle');
    function openSide(){ if(sidebar) sidebar.classList.add('show'); if(overlay) overlay.classList.add('show'); }
    function closeSide(){ if(sidebar) sidebar.classList.remove('show'); if(overlay) overlay.classList.remove('show'); }
    if(toggle) toggle.addEventListener('click',function(){ if(sidebar && sidebar.classList.contains('show')) closeSide(); else openSide(); });
    if(overlay) overlay.addEventListener('click',closeSide);
    document.querySelectorAll('.sidebar a').forEach(function(a){ a.addEventListener('click',function(){ if(window.innerWidth<=900) closeSide(); }); });
})();
</script>
<script>
(function(){
    const chartCfg = (ctx, labels, values, type='line') => new Chart(ctx, {
        type: type,
        data: { labels: labels, datasets: [{ label: 'Permintaan', data: values, fill: type==='line', tension: .35, borderWidth: 3, pointRadius: 3 }] },
        options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{x:{grid:{display:false},ticks:{color:'#64748b',maxRotation:0,autoSkip:true,maxTicksLimit:8}}, y:{beginAtZero:true,grid:{color:'rgba(148,163,184,.20)'},ticks:{precision:0,color:'#64748b'}}} }
    });
    const today=document.getElementById('chartToday'), week=document.getElementById('chartWeek'), month=document.getElementById('chartMonth');
    if(today) chartCfg(today, <?php echo json_encode($chartTodayLabels); ?>, <?php echo json_encode($chartTodayValues); ?>, 'bar');
    if(week) chartCfg(week, <?php echo json_encode($chartWeekLabels); ?>, <?php echo json_encode($chartWeekValues); ?>, 'line');
    if(month) chartCfg(month, <?php echo json_encode($chartMonthLabels); ?>, <?php echo json_encode($chartMonthValues); ?>, 'bar');
})();
</script>
<script>
(function(){
    var popup=document.getElementById('itNotifPopup');
    var bell=document.getElementById('itNotifBell');
    var close=document.getElementById('itNotifClose');
    var pendingCount=<?php echo (int)$pendingNotifCount; ?>;
    var latestId=<?php echo (int)($latestPendingNotif['id'] ?? 0); ?>;
    function openPopup(){ if(popup){ popup.classList.add('show'); popup.setAttribute('aria-hidden','false'); } }
    function closePopup(){ if(popup){ popup.classList.remove('show'); popup.setAttribute('aria-hidden','true'); } }
    if(bell){ if(pendingCount>0) bell.classList.add('has-new'); bell.addEventListener('click',openPopup); }
    if(close) close.addEventListener('click',closePopup);
    if(popup) popup.addEventListener('click',function(e){ if(e.target===popup) closePopup(); });
    document.querySelectorAll('.it-popup-actions a[href^="#ticket-"]').forEach(function(a){
        a.addEventListener('click',function(){ closePopup(); setTimeout(function(){ try{document.querySelector(a.getAttribute('href')).scrollIntoView({behavior:'smooth',block:'start'});}catch(e){} },80); });
    });
    if(pendingCount>0 && latestId>0){
        var key='it-helpdesk-popup-pending-'+latestId;
        if(!localStorage.getItem(key)){
            setTimeout(function(){ openPopup(); localStorage.setItem(key,'1'); }, 650);
        }
    }
})();
</script>


<script>
function itUpdateServerClock(){
    var el=document.getElementById('serverLiveClock');
    if(el) el.textContent=new Date().toLocaleString('id-ID');
}
function itUpdateServerStatus(){
    if(!document.getElementById('serverMonitoringSection')) return;
    fetch('it-helpdesk.php?ajax=server_monitor', {cache:'no-store'})
    .then(function(r){return r.json();})
    .then(function(data){
        if(!data || !data.success || !Array.isArray(data.rows)) return;
        data.rows.forEach(function(server,index){
            var status=document.getElementById('serverStatus'+index);
            var bar=document.getElementById('serverBar'+index);
            var card=document.getElementById('serverCard'+index);
            if(status){ status.innerHTML='<i class="fa-solid fa-signal"></i>'+server.status; status.className='server-status '+server.class; }
            if(bar){ bar.className='server-bar-fill '+server.barClass; }
            if(card){ card.className='server-card '+server.storeClass; }
        });
        var on=document.getElementById('serverOnlineCount'), off=document.getElementById('serverOfflineCount'), clk=document.getElementById('serverLiveClock');
        if(on) on.textContent=(data.summary && typeof data.summary.online!=='undefined') ? data.summary.online : '0';
        if(off) off.textContent=(data.summary && typeof data.summary.offline!=='undefined') ? data.summary.offline : '0';
        if(clk && data.time) clk.textContent=data.time;
    }).catch(function(){});
}
setInterval(itUpdateServerClock,1000);
if(document.getElementById('serverMonitoringSection')){
    setTimeout(itUpdateServerStatus, 650);
    setInterval(itUpdateServerStatus, 30000);
}
</script>


<script>
function itCreateHidden(form, name, value){
    var input=document.createElement('input');
    input.type='hidden';
    input.name=name;
    input.value=value == null ? '' : String(value);
    form.appendChild(input);
}
function itEscapeHtml(value){
    return String(value == null ? '' : value)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}
function itOpenEditNote(btn){
    if(!btn) return false;
    var id=btn.getAttribute('data-id') || '';
    var note=btn.getAttribute('data-note') || '';
    if(!window.Swal){
        var fallback=window.prompt('Edit Catatan IT', note);
        if(fallback===null) return false;
        var f=document.createElement('form');
        f.method='POST'; f.style.display='none';
        itCreateHidden(f,'ticket_action','edit_it_note');
        itCreateHidden(f,'ticket_id',id);
        itCreateHidden(f,'it_note',fallback.trim());
        itCreateHidden(f,'redirect_mode',<?php echo json_encode($mode); ?>);
        document.body.appendChild(f); f.submit();
        return false;
    }
    Swal.fire({
        title:'Edit Catatan IT',
        html:'<div class="it-swal-form"><div><label>Catatan IT</label><textarea id="swalEditItNote" placeholder="Tulis atau perbaiki Catatan IT..."></textarea></div></div>',
        icon:'info',
        width:620,
        showCancelButton:true,
        confirmButtonText:'<i class="fa-solid fa-floppy-disk"></i> Simpan Catatan',
        cancelButtonText:'Batal',
        confirmButtonColor:'#16a34a',
        cancelButtonColor:'#64748b',
        customClass:{popup:'it-swal-rounded'},
        didOpen:function(){
            var el=document.getElementById('swalEditItNote');
            if(el){ el.value=note; el.focus(); el.setSelectionRange(el.value.length,el.value.length); }
        },
        preConfirm:function(){
            var el=document.getElementById('swalEditItNote');
            return el ? el.value.trim() : '';
        }
    }).then(function(result){
        if(!result.isConfirmed) return;
        var form=document.createElement('form');
        form.method='POST'; form.style.display='none';
        itCreateHidden(form,'ticket_action','edit_it_note');
        itCreateHidden(form,'ticket_id',id);
        itCreateHidden(form,'it_note',result.value || '');
        itCreateHidden(form,'redirect_mode',<?php echo json_encode($mode); ?>);
        document.body.appendChild(form);
        form.submit();
    });
    return false;
}
function itOpenEditTicket(btn){
    if(!btn || !window.Swal) return false;
    var id=btn.getAttribute('data-id') || '';
    var store=btn.getAttribute('data-store') || '';
    var issue=btn.getAttribute('data-issue') || '';
    var status=(btn.getAttribute('data-status') || 'PENDING').toUpperCase();
    var note=btn.getAttribute('data-note') || '';
    var statusOptions=['PENDING','PROSES','DITUNDA','SELESAI'].map(function(v){
        var label={PENDING:'Pending',PROSES:'Diproses',DITUNDA:'Ditunda',SELESAI:'Selesai'}[v] || v;
        return '<option value="'+v+'" '+(v===status?'selected':'')+'>'+label+'</option>';
    }).join('');
    Swal.fire({
        title:'Edit Riwayat Ticket',
        html:'<div class="it-swal-form">'+
            '<div><label>Nama Toko</label><input id="swalStore" value="'+itEscapeHtml(store)+'" placeholder="Nama toko"></div>'+
            '<div><label>Status</label><select id="swalStatus">'+statusOptions+'</select></div>'+
            '<div><label>Issue</label><textarea id="swalIssue" placeholder="Catatan issue">'+itEscapeHtml(issue)+'</textarea></div>'+
            '<div><label>Catatan IT</label><textarea id="swalNote" placeholder="Catatan IT">'+itEscapeHtml(note)+'</textarea></div>'+
            '</div>',
        icon:'info',
        width:640,
        showCancelButton:true,
        confirmButtonText:'<i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan',
        cancelButtonText:'Batal',
        confirmButtonColor:'#2563eb',
        cancelButtonColor:'#64748b',
        customClass:{popup:'it-swal-rounded'},
        preConfirm:function(){
            var storeVal=document.getElementById('swalStore').value.trim();
            var issueVal=document.getElementById('swalIssue').value.trim();
            if(!storeVal || !issueVal){
                Swal.showValidationMessage('Nama toko dan issue wajib diisi.');
                return false;
            }
            return {
                store:storeVal,
                status:document.getElementById('swalStatus').value,
                issue:issueVal,
                note:document.getElementById('swalNote').value.trim()
            };
        }
    }).then(function(result){
        if(!result.isConfirmed || !result.value) return;
        var form=document.createElement('form');
        form.method='POST';
        form.style.display='none';
        itCreateHidden(form,'ticket_action','edit_ticket');
        itCreateHidden(form,'ticket_id',id);
        itCreateHidden(form,'store_name',result.value.store);
        itCreateHidden(form,'status',result.value.status);
        itCreateHidden(form,'issue_note',result.value.issue);
        itCreateHidden(form,'it_note',result.value.note);
        itCreateHidden(form,'redirect_mode',<?php echo json_encode($mode); ?>);
        document.body.appendChild(form);
        form.submit();
    });
    return false;
}
function itConfirmDeleteTicket(e,form){
    if(e) e.preventDefault();
    if(!form) return false;
    if(!window.Swal){ if(confirm('Hapus ticket permanen?')) form.submit(); return false; }
    Swal.fire({
        title:'Hapus Ticket Permanen?',
        text:'Data ticket dan foto lokalnya akan dihapus permanen dan tidak bisa dikembalikan.',
        icon:'warning',
        showCancelButton:true,
        confirmButtonText:'<i class="fa-solid fa-trash"></i> Ya, Hapus Permanen',
        cancelButtonText:'Batal',
        confirmButtonColor:'#dc2626',
        cancelButtonColor:'#64748b',
        reverseButtons:true,
        customClass:{popup:'it-swal-rounded'}
    }).then(function(result){
        if(result.isConfirmed){ form.submit(); }
    });
    return false;
}
</script>

<script>
(function(){
    const pageUrl = 'it-helpdesk.php';
    const pushBtn = document.getElementById('itPushEnableBtn');
    const canManage = <?php echo $canManage ? 'true' : 'false'; ?>;

    const KEY_ENABLED = 'it_helpdesk_push_enabled';
    const KEY_DECLINED = 'it_helpdesk_push_declined';
    const KEY_PROMPT_SEEN = 'it_helpdesk_push_realtime_prompt_seen';
    const KEY_ACTIVE_NOTICE = 'it_helpdesk_push_activation_notice_once';

    function itUrlBase64ToUint8Array(base64String){
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
        return outputArray;
    }

    function setPushButton(state, text){
        if(!pushBtn) return;
        pushBtn.classList.remove('active','warning');
        if(state === 'active') pushBtn.classList.add('active');
        if(state === 'warning') pushBtn.classList.add('warning');
        const icon = pushBtn.querySelector('i');
        const span = pushBtn.querySelector('span');
        if(icon){
            icon.className = state === 'active' ? 'fa-solid fa-bell-circle-check' : (state === 'warning' ? 'fa-solid fa-triangle-exclamation' : 'fa-solid fa-mobile-screen-button');
        }
        if(span) span.textContent = text || 'Notif HP';
    }

    async function itGetPushConfig(){
        const res = await fetch(pageUrl + '?ajax=push_config', {cache:'no-store'});
        return await res.json();
    }

    async function itShowActivationNotificationOnce(readyReg){
        if(localStorage.getItem(KEY_ACTIVE_NOTICE) === '1') return;
        try{
            await readyReg.showNotification('IT Helpdesk Aktif', {
                body:'Notifikasi realtime ticket IT baru sudah aktif di perangkat ini.',
                icon:'img/it.png',
                badge:'img/it.png',
                tag:'it-helpdesk-activation-once',
                data:{url:'it-helpdesk.php'}
            });
            localStorage.setItem(KEY_ACTIVE_NOTICE, '1');
        }catch(e){}
    }

    async function itRegisterPushSubscription(showSuccess){
        if(!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)){
            setPushButton('warning','Tidak Support');
            if(window.Swal && showSuccess) Swal.fire({icon:'warning',title:'Browser tidak support',text:'Browser ini belum mendukung push notification.',confirmButtonColor:'#2563eb'});
            return false;
        }

        const cfg = await itGetPushConfig();
        if(!cfg || !cfg.configured || !cfg.publicKey){
            setPushButton('warning','VAPID Error');
            if(window.Swal && showSuccess) Swal.fire({icon:'error',title:'VAPID belum siap',text:(cfg && cfg.message) ? cfg.message : 'Konfigurasi push belum siap.',confirmButtonColor:'#2563eb'});
            return false;
        }

        const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
        if(permission !== 'granted'){
            localStorage.removeItem(KEY_ENABLED);
            localStorage.setItem(KEY_DECLINED, '1');
            setPushButton('warning','Belum Izin');
            if(window.Swal && showSuccess) Swal.fire({icon:'info',title:'Notifikasi belum aktif',text:'Notifikasi realtime tidak akan muncul sebelum izin browser diaktifkan.',confirmButtonColor:'#2563eb'});
            return false;
        }

        const reg = await navigator.serviceWorker.register('sw.js', {scope:'./'});
        const readyReg = await navigator.serviceWorker.ready;
        let sub = await readyReg.pushManager.getSubscription();
        if(!sub){
            sub = await readyReg.pushManager.subscribe({
                userVisibleOnly:true,
                applicationServerKey:itUrlBase64ToUint8Array(cfg.publicKey)
            });
        }

        const saveRes = await fetch(pageUrl + '?ajax=save_push_subscription', {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify(sub)
        });
        const saveJson = await saveRes.json();
        if(saveJson && saveJson.success){
            localStorage.setItem(KEY_ENABLED,'1');
            localStorage.removeItem(KEY_DECLINED);
            localStorage.setItem(KEY_PROMPT_SEEN,'1');
            setPushButton('active','Notif Aktif');
            if(showSuccess && window.Swal){
                Swal.fire({icon:'success',title:'Notifikasi Realtime Aktif',text:'Pemberitahuan ticket IT baru akan masuk ke perangkat ini.',confirmButtonColor:'#16a34a'});
                await itShowActivationNotificationOnce(readyReg);
            }
            return true;
        }

        setPushButton('warning','Gagal Simpan');
        if(window.Swal && showSuccess) Swal.fire({icon:'error',title:'Gagal menyimpan subscription',text:(saveJson && saveJson.message) ? saveJson.message : 'Coba refresh lalu aktifkan lagi.',confirmButtonColor:'#dc2626'});
        return false;
    }

    function showRealtimePromptOnce(){
        if(!canManage || !window.Swal) return;
        if(localStorage.getItem(KEY_ENABLED) === '1') return;
        if(localStorage.getItem(KEY_DECLINED) === '1') return;
        if(localStorage.getItem(KEY_PROMPT_SEEN) === '1') return;

        localStorage.setItem(KEY_PROMPT_SEEN, '1');
        setTimeout(function(){
            Swal.fire({
                icon:'info',
                title:'Aktifkan Notifikasi Realtime?',
                html:'Jika diaktifkan, pengajuan IT baru dari SQM Visit akan langsung masuk ke notifikasi perangkat ini.<br><br><b>Jika pilih Tidak, notifikasi push tidak akan muncul.</b>',
                showCancelButton:true,
                confirmButtonText:'<i class="fa-solid fa-bell"></i> Ya, Aktifkan',
                cancelButtonText:'Tidak',
                confirmButtonColor:'#16a34a',
                cancelButtonColor:'#64748b',
                reverseButtons:true
            }).then(function(r){
                if(r.isConfirmed){
                    itRegisterPushSubscription(true);
                } else {
                    localStorage.setItem(KEY_DECLINED, '1');
                    localStorage.removeItem(KEY_ENABLED);
                    setPushButton('', 'Notif HP');
                }
            });
        }, 800);
    }

    async function itCheckPushState(){
        if(!pushBtn) return;
        if(!('Notification' in window)) { setPushButton('warning','Tidak Support'); return; }

        if(Notification.permission === 'denied') {
            localStorage.removeItem(KEY_ENABLED);
            setPushButton('warning','Diblokir');
            return;
        }

        if(Notification.permission === 'granted' && localStorage.getItem(KEY_ENABLED) === '1'){
            setPushButton('active','Notif Aktif');
            try{ await itRegisterPushSubscription(false); }catch(e){}
            return;
        }

        setPushButton('', 'Notif HP');
        showRealtimePromptOnce();
    }

    let itLastKnownPendingId = <?php echo (int)($latestPendingNotif['id'] ?? 0); ?>;

    async function itCheckNewTicketPush(){
        if(!canManage) return;
        try{
            const res = await fetch(pageUrl + '?ajax=it_push_check&after_id=' + encodeURIComponent(itLastKnownPendingId) + '&ts=' + Date.now(), {cache:'no-store'});
            const data = await res.json();
            if(!data || !data.success) return;

            const latestId = parseInt(data.latest_id || 0);
            const checked = parseInt(data.checked || 0);
            const processed = parseInt(data.processed || 0);

            // Patokan push = notif biasa: hanya bergerak saat ada pending baru yang id-nya lebih besar dari patokan halaman.
            if(latestId > itLastKnownPendingId && (checked > 0 || processed > 0)){
                itLastKnownPendingId = latestId;
                const reloadKey = 'it_helpdesk_notif_biasa_' + latestId;
                if(!sessionStorage.getItem(reloadKey)){
                    sessionStorage.setItem(reloadKey, '1');
                    if(window.Swal){
                        Swal.fire({
                            toast:true,
                            position:'top-end',
                            icon:'info',
                            title:(data.notif_title || 'INFORMASI PENGADUAN'),
                            text:(data.notif_text || 'Ada pengajuan baru dari user SQM Visit. Klik untuk proses.'),
                            showConfirmButton:false,
                            timer:1600,
                            timerProgressBar:true
                        });
                    }
                    setTimeout(function(){ window.location.reload(); }, 1200);
                }
            } else if(latestId > itLastKnownPendingId) {
                // Ada perubahan pending, tapi tidak ada data push baru; tetap update patokan agar tidak loop.
                itLastKnownPendingId = latestId;
            }
        }catch(e){}
    }

    if(pushBtn){
        pushBtn.addEventListener('click', function(){
            localStorage.removeItem(KEY_DECLINED);
            localStorage.removeItem(KEY_PROMPT_SEEN);
            itRegisterPushSubscription(true);
        });
    }
    itCheckPushState();
    setTimeout(itCheckNewTicketPush, 1600);
    setInterval(itCheckNewTicketPush, 5000);
})();
</script>


<?php if($alert['type']): ?>
<script>Swal.fire({icon:<?php echo json_encode($alert['type']); ?>,title:<?php echo json_encode($alert['title']); ?>,text:<?php echo json_encode($alert['text']); ?>,confirmButtonColor:'#4F5EFF'});try{var u=new URL(window.location.href);['sent','updated','edited','deleted'].forEach(function(k){u.searchParams.delete(k);});history.replaceState(null,'',u.pathname+(u.search?u.search:'')+u.hash);}catch(e){}</script>
<?php endif; ?>
</body>
</html>
