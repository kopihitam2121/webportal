<?php
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');

session_start();
require_once 'db.php';

mysqli_set_charset($conn, 'utf8mb4');
ensureRememberColumns($conn);

function setWebportalCookie($name, $value, $expires)
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    setcookie($name, $value, [
        'expires' => $expires,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

if (file_exists(__DIR__ . '/maintenance.flag')) {
    include '#.php';
    exit;
}

function ensureRememberColumns($conn)
{
    if (!$conn) return;

    $checkToken = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'remember_token'");
    if ($checkToken && mysqli_num_rows($checkToken) == 0) {
        @mysqli_query($conn, "ALTER TABLE users ADD COLUMN remember_token VARCHAR(128) NULL");
    }

    $checkExpiry = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'remember_expiry'");
    if ($checkExpiry && mysqli_num_rows($checkExpiry) == 0) {
        @mysqli_query($conn, "ALTER TABLE users ADD COLUMN remember_expiry DATETIME NULL");
    }
}

function clearRememberCookie()
{
    setWebportalCookie("remember_user", "", time() - 3600);
    setWebportalCookie("remember_token", "", time() - 3600);
}


function getUserIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
}


function security_check_bruteforce($conn, $username){

    if(!$conn) return [
        'blocked'=>false,
        'remaining'=>5,
        'seconds'=>0
    ];

    $ip=getUserIP();
    $username=trim((string)$username);

    $stmt=mysqli_prepare(
        $conn,
        "SELECT COUNT(*) total,
        TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) elapsed
        FROM login_attempts
        WHERE (username=? OR ip_address=?)
        AND status='FAILED'
        AND created_at > DATE_SUB(NOW(),INTERVAL 10 MINUTE)"
    );

    if(!$stmt) return [
        'blocked'=>false,
        'remaining'=>5,
        'seconds'=>0
    ];

    mysqli_stmt_bind_param($stmt,"ss",$username,$ip);
    mysqli_stmt_execute($stmt);

    $result=mysqli_stmt_get_result($stmt);
    $data=mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    $total=(int)($data['total'] ?? 0);

    if($total>=5){

        $elapsed=(int)($data['elapsed'] ?? 0);
        $seconds=max(0,600-$elapsed);

        return [
            'blocked'=>true,
            'remaining'=>0,
            'seconds'=>$seconds
        ];
    }

    return [
        'blocked'=>false,
        'remaining'=>max(0,5-$total),
        'seconds'=>0
    ];
}


function write_login_attempt($conn, $username, $status='FAILED'){

    if(!$conn) return;

    $username=trim((string)$username);
    if($username==='') $username='UNKNOWN';

    $ip=getUserIP();

    $stmt=mysqli_prepare(
        $conn,
        "INSERT INTO login_attempts(username,ip_address,status)
         VALUES(?,?,?)"
    );

    if($stmt){

        mysqli_stmt_bind_param(
            $stmt,
            "sss",
            $username,
            $ip,
            $status
        );

        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function getUserDevice() {
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    $os = 'Unknown OS';
    $browser = 'Unknown Browser';

    if (strpos($ua, 'windows') !== false) $os = 'Windows';
    elseif (strpos($ua, 'android') !== false) $os = 'Android';
    elseif (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false) $os = 'iPhone/iPad';
    elseif (strpos($ua, 'mac') !== false) $os = 'MacOS';
    elseif (strpos($ua, 'linux') !== false) $os = 'Linux';

    if (strpos($ua, 'edg') !== false) $browser = 'Edge';
    elseif (strpos($ua, 'chrome') !== false) $browser = 'Chrome';
    elseif (strpos($ua, 'firefox') !== false) $browser = 'Firefox';
    elseif (strpos($ua, 'safari') !== false) $browser = 'Safari';
    elseif (strpos($ua, 'opera') !== false) $browser = 'Opera';

    return "$os - $browser";
}

function bio_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function bio_base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function bio_base64url_decode($data) {
    $data = strtr((string)$data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode($data);
}

function bio_random_challenge() {
    return bio_base64url_encode(random_bytes(32));
}

function bio_origin() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function bio_rp_id() {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return preg_replace('/:\d+$/', '', $host);
}

function bio_ensure_table($conn) {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS user_webauthn_credentials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        username VARCHAR(100) NOT NULL,
        credential_id TEXT NOT NULL,
        public_key_pem LONGTEXT NOT NULL,
        sign_count BIGINT NOT NULL DEFAULT 0,
        device_name VARCHAR(180) DEFAULT NULL,
        user_agent TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME DEFAULT NULL,
        UNIQUE KEY uniq_user_credential (user_id, credential_id(255))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}


function server_monitor_ensure_login_activity($conn) {
    if (!$conn) return;
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS login_activity (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        username VARCHAR(100) NOT NULL,
        ip_address VARCHAR(80) DEFAULT NULL,
        user_agent TEXT DEFAULT NULL,
        device VARCHAR(180) DEFAULT NULL,
        login_method VARCHAR(40) DEFAULT 'PASSWORD',
        status VARCHAR(40) DEFAULT 'SUCCESS',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function server_monitor_write_login_activity($conn, $user, $method = 'PASSWORD', $status = 'SUCCESS') {
    if (!$conn || empty($user['username'])) return;

    server_monitor_ensure_login_activity($conn);

    $uid = isset($user['id']) ? (int)$user['id'] : null;
    $username = (string)$user['username'];
    $ip = getUserIP();
    $uaRaw = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $device = getUserDevice();
    $method = strtoupper(trim((string)$method));
    if ($method === '') $method = 'PASSWORD';
    $status = strtoupper(trim((string)$status));
    if ($status === '') $status = 'SUCCESS';

    $stmt = mysqli_prepare($conn, "INSERT INTO login_activity (user_id, username, ip_address, user_agent, device, login_method, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'issssss', $uid, $username, $ip, $uaRaw, $device, $method, $status);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function server_monitor_write_failed_login($conn, $usernameAttempt, $reason = 'FAILED') {
    if (!$conn) return;

    server_monitor_ensure_login_activity($conn);

    $uid = null;
    $usernameAttempt = trim((string)$usernameAttempt);
    if ($usernameAttempt === '') $usernameAttempt = 'UNKNOWN';

    $ip = getUserIP();
    $uaRaw = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $device = getUserDevice();
    $method = 'PASSWORD';
    $status = strtoupper(trim((string)$reason));
    if ($status === '') $status = 'FAILED';

    $stmt = mysqli_prepare($conn, "INSERT INTO login_activity (user_id, username, ip_address, user_agent, device, login_method, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'issssss', $uid, $usernameAttempt, $ip, $uaRaw, $device, $method, $status);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}


function issueRememberLoginToken($conn, $user, $days = 180)
{
    if (!$conn || empty($user['id']) || empty($user['username'])) return false;

    ensureRememberColumns($conn);

    $days = max(1, min(365, (int)$days));
    $token = bin2hex(random_bytes(32));
    $expiry = date('Y-m-d H:i:s', time() + (86400 * $days));
    $uid = (int)$user['id'];

    $stmt = mysqli_prepare($conn, "UPDATE users SET remember_token = ?, remember_expiry = ? WHERE id = ?");
    if (!$stmt) return false;

    mysqli_stmt_bind_param($stmt, 'ssi', $token, $expiry, $uid);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if (!$ok) return false;

    setWebportalCookie("remember_user", $user['username'], time() + (86400 * $days));
    setWebportalCookie("remember_token", $token, time() + (86400 * $days));

    return true;
}


function bio_login_user($conn, $user) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(true);
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
    $_SESSION['role'] = $user['role'] ?? 'user';

    $now = date("Y-m-d H:i:s");
    $uid = (int)$user['id'];
    $uname = mysqli_real_escape_string($conn, $user['username']);
    $ip_address = mysqli_real_escape_string($conn, getUserIP());
    $user_agent = mysqli_real_escape_string($conn, getUserDevice());

    $checkCols = mysqli_query($conn, "SHOW COLUMNS FROM log_user LIKE 'ip_address'");
    if ($checkCols && mysqli_num_rows($checkCols) == 0) {
        mysqli_query($conn, "ALTER TABLE log_user ADD COLUMN ip_address VARCHAR(45) NULL");
    }

    $checkCols2 = mysqli_query($conn, "SHOW COLUMNS FROM log_user LIKE 'user_agent'");
    if ($checkCols2 && mysqli_num_rows($checkCols2) == 0) {
        mysqli_query($conn, "ALTER TABLE log_user ADD COLUMN user_agent TEXT NULL");
    }

    $log_sql = "INSERT INTO log_user (user_id, username, waktu_login, ip_address, user_agent) 
                VALUES ('$uid', '$uname', '$now', '$ip_address', '$user_agent')";
    mysqli_query($conn, $log_sql);

    $_SESSION['log_id'] = mysqli_insert_id($conn);
}

class SimpleCborReader {
    private $data;
    private $pos = 0;
    public function __construct($data) { $this->data = $data; }
    private function read($n) {
        $out = substr($this->data, $this->pos, $n);
        $this->pos += $n;
        return $out;
    }
    private function readUint($add) {
        if ($add < 24) return $add;
        if ($add == 24) return ord($this->read(1));
        if ($add == 25) { $v = unpack('n', $this->read(2)); return $v[1]; }
        if ($add == 26) { $v = unpack('N', $this->read(4)); return $v[1]; }
        if ($add == 27) { $p = unpack('N2', $this->read(8)); return ($p[1] << 32) + $p[2]; }
        throw new Exception('CBOR integer tidak valid');
    }
    public function decode() {
        $ib = ord($this->read(1));
        $major = $ib >> 5;
        $add = $ib & 31;
        if ($major == 0) return $this->readUint($add);
        if ($major == 1) return -1 - $this->readUint($add);
        if ($major == 2) return $this->read($this->readUint($add));
        if ($major == 3) return $this->read($this->readUint($add));
        if ($major == 4) {
            $len = $this->readUint($add);
            $arr = [];
            for ($i = 0; $i < $len; $i++) $arr[] = $this->decode();
            return $arr;
        }
        if ($major == 5) {
            $len = $this->readUint($add);
            $map = [];
            for ($i = 0; $i < $len; $i++) {
                $k = $this->decode();
                $v = $this->decode();
                $map[$k] = $v;
            }
            return $map;
        }
        if ($major == 7) {
            if ($add == 20) return false;
            if ($add == 21) return true;
            if ($add == 22) return null;
        }
        throw new Exception('CBOR type belum didukung');
    }
}

function bio_asn1_len($len) {
    if ($len < 128) return chr($len);
    $bytes = '';
    while ($len > 0) {
        $bytes = chr($len & 0xff) . $bytes;
        $len >>= 8;
    }
    return chr(0x80 | strlen($bytes)) . $bytes;
}

function bio_asn1_seq($data) { return "\x30" . bio_asn1_len(strlen($data)) . $data; }
function bio_asn1_int($data) { return "\x02" . bio_asn1_len(strlen($data)) . $data; }
function bio_asn1_bitstr($data) { return "\x03" . bio_asn1_len(strlen($data) + 1) . "\x00" . $data; }
function bio_asn1_octstr($data) { return "\x04" . bio_asn1_len(strlen($data)) . $data; }
function bio_asn1_oid($data) { return "\x06" . bio_asn1_len(strlen($data)) . $data; }

function bio_pem_from_cose($cose) {
    if (!isset($cose[1], $cose[3])) throw new Exception('COSE key tidak lengkap');
    $kty = $cose[1];
    $alg = $cose[3];

    // ES256 / P-256
    if ((int)$kty === 2 && (int)$alg === -7) {
        $x = $cose[-2] ?? null;
        $y = $cose[-3] ?? null;
        if (!$x || !$y || strlen($x) !== 32 || strlen($y) !== 32) throw new Exception('P-256 public key tidak valid');

        $ecPublicKeyOid = "\x2A\x86\x48\xCE\x3D\x02\x01";
        $prime256v1Oid = "\x2A\x86\x48\xCE\x3D\x03\x01\x07";
        $algId = bio_asn1_seq(bio_asn1_oid($ecPublicKeyOid) . bio_asn1_oid($prime256v1Oid));
        $point = "\x04" . $x . $y;
        $spki = bio_asn1_seq($algId . bio_asn1_bitstr($point));
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    // RS256
    if ((int)$kty === 3 && (int)$alg === -257) {
        $n = $cose[-1] ?? null;
        $e = $cose[-2] ?? null;
        if (!$n || !$e) throw new Exception('RSA public key tidak valid');
        if (ord($n[0]) > 0x7f) $n = "\x00" . $n;
        if (ord($e[0]) > 0x7f) $e = "\x00" . $e;

        $rsaEncryptionOid = "\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01";
        $algId = bio_asn1_seq(bio_asn1_oid($rsaEncryptionOid) . "\x05\x00");
        $rsaPub = bio_asn1_seq(bio_asn1_int($n) . bio_asn1_int($e));
        $spki = bio_asn1_seq($algId . bio_asn1_bitstr($rsaPub));
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    throw new Exception('Algoritma biometrik belum didukung browser ini');
}

function bio_parse_auth_data($authData) {
    if (strlen($authData) < 37) throw new Exception('Authenticator data tidak valid');
    $rpIdHash = substr($authData, 0, 32);
    $flags = ord($authData[32]);
    $signCountBytes = substr($authData, 33, 4);
    $up = ($flags & 0x01) !== 0;
    $uv = ($flags & 0x04) !== 0;
    $at = ($flags & 0x40) !== 0;
    $signCount = unpack('N', $signCountBytes)[1];

    $out = [
        'rpIdHash' => $rpIdHash,
        'flags' => $flags,
        'up' => $up,
        'uv' => $uv,
        'at' => $at,
        'signCount' => $signCount,
    ];

    if ($at) {
        $pos = 37;
        $aaguid = substr($authData, $pos, 16); $pos += 16;
        $credLen = unpack('n', substr($authData, $pos, 2))[1]; $pos += 2;
        $credId = substr($authData, $pos, $credLen); $pos += $credLen;
        $coseRaw = substr($authData, $pos);
        $reader = new SimpleCborReader($coseRaw);
        $cose = $reader->decode();
        $out['aaguid'] = $aaguid;
        $out['credentialId'] = $credId;
        $out['cose'] = $cose;
    }

    return $out;
}

/*
|--------------------------------------------------------------------------
| WEBAUTHN / PASSKEY ENDPOINTS
|--------------------------------------------------------------------------
*/
if (isset($_GET['webauthn'])) {
    bio_ensure_table($conn);
    $action = $_GET['webauthn'];

    if ($action === 'register_options') {
        if (!isset($_SESSION['user_id'])) bio_json(['ok' => false, 'message' => 'Login manual dulu untuk aktifkan biometrik.'], 401);

        $uid = (int)$_SESSION['user_id'];
        $usernameSession = $_SESSION['username'] ?? '';
        $namaSession = $_SESSION['nama_lengkap'] ?? $usernameSession;
        $challenge = bio_random_challenge();
        $_SESSION['webauthn_register_challenge'] = $challenge;

        $existing = [];
        $q = mysqli_query($conn, "SELECT credential_id FROM user_webauthn_credentials WHERE user_id='$uid'");
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) {
                $existing[] = ['type' => 'public-key', 'id' => $r['credential_id']];
            }
        }

        bio_json([
            'ok' => true,
            'publicKey' => [
                'challenge' => $challenge,
                'rp' => ['name' => 'Web Portal Minimarket', 'id' => bio_rp_id()],
                'user' => [
                    'id' => bio_base64url_encode((string)$uid),
                    'name' => $usernameSession,
                    'displayName' => $namaSession
                ],
                'pubKeyCredParams' => [
                    ['type' => 'public-key', 'alg' => -7],
                    ['type' => 'public-key', 'alg' => -257]
                ],
                'timeout' => 60000,
                'attestation' => 'none',
                'excludeCredentials' => $existing,
                'authenticatorSelection' => [
                    'userVerification' => 'required',
                    'residentKey' => 'preferred',
                    'requireResidentKey' => false
                ]
            ]
        ]);
    }

    if ($action === 'register_verify') {
        if (!isset($_SESSION['user_id'])) bio_json(['ok' => false, 'message' => 'Session habis. Login ulang dulu.'], 401);
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!$data) bio_json(['ok' => false, 'message' => 'Payload tidak valid'], 400);

        try {
            $clientDataJSON = bio_base64url_decode($data['response']['clientDataJSON'] ?? '');
            $attestationObject = bio_base64url_decode($data['response']['attestationObject'] ?? '');
            $client = json_decode($clientDataJSON, true);

            if (($client['type'] ?? '') !== 'webauthn.create') throw new Exception('Tipe registrasi tidak valid');
            if (!hash_equals($_SESSION['webauthn_register_challenge'] ?? '', $client['challenge'] ?? '')) throw new Exception('Challenge tidak cocok');
            if (($client['origin'] ?? '') !== bio_origin()) throw new Exception('Origin tidak cocok');

            $reader = new SimpleCborReader($attestationObject);
            $attObj = $reader->decode();
            $authData = $attObj['authData'] ?? null;
            if (!$authData) throw new Exception('Auth data kosong');

            $parsed = bio_parse_auth_data($authData);
            if (!$parsed['up'] || !$parsed['uv']) throw new Exception('Verifikasi user biometrik gagal');
            if (!hash_equals(hash('sha256', bio_rp_id(), true), $parsed['rpIdHash'])) throw new Exception('RP ID tidak cocok');

            $credentialId = bio_base64url_encode($parsed['credentialId']);
            $publicKeyPem = bio_pem_from_cose($parsed['cose']);
            $signCount = (int)$parsed['signCount'];

            $uid = (int)$_SESSION['user_id'];
            $uname = mysqli_real_escape_string($conn, $_SESSION['username'] ?? '');
            $credentialEsc = mysqli_real_escape_string($conn, $credentialId);
            $pemEsc = mysqli_real_escape_string($conn, $publicKeyPem);
            $deviceName = mysqli_real_escape_string($conn, trim($data['device_name'] ?? getUserDevice()));
            $ua = mysqli_real_escape_string($conn, $_SERVER['HTTP_USER_AGENT'] ?? '');

            mysqli_query($conn, "INSERT INTO user_webauthn_credentials 
                (user_id, username, credential_id, public_key_pem, sign_count, device_name, user_agent)
                VALUES ('$uid', '$uname', '$credentialEsc', '$pemEsc', '$signCount', '$deviceName', '$ua')
                ON DUPLICATE KEY UPDATE public_key_pem=VALUES(public_key_pem), sign_count=VALUES(sign_count), device_name=VALUES(device_name), user_agent=VALUES(user_agent)");

            unset($_SESSION['webauthn_register_challenge'], $_SESSION['biometric_setup_after_login']);
            bio_json(['ok' => true, 'message' => 'Biometrik berhasil diaktifkan di device ini.']);
        } catch (Exception $e) {
            bio_json(['ok' => false, 'message' => $e->getMessage()], 400);
        }
    }

    if ($action === 'login_options') {
        $challenge = bio_random_challenge();
        $_SESSION['webauthn_login_challenge'] = $challenge;

        $allowCredentials = [];
        $qCred = mysqli_query($conn, "SELECT credential_id FROM user_webauthn_credentials ORDER BY last_used_at DESC, created_at DESC LIMIT 30");
        if ($qCred) {
            while ($cred = mysqli_fetch_assoc($qCred)) {
                if (!empty($cred['credential_id'])) {
                    $allowCredentials[] = [
                        'type' => 'public-key',
                        'id' => $cred['credential_id']
                    ];
                }
            }
        }

        bio_json([
            'ok' => true,
            'has_credentials' => count($allowCredentials) > 0,
            'publicKey' => [
                'challenge' => $challenge,
                'timeout' => 60000,
                'rpId' => bio_rp_id(),
                'userVerification' => 'required',
                'allowCredentials' => $allowCredentials
            ]
        ]);
    }

    if ($action === 'login_verify') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!$data) bio_json(['ok' => false, 'message' => 'Payload tidak valid'], 400);

        try {
            $credentialId = $data['id'] ?? '';
            if ($credentialId === '') throw new Exception('Credential kosong');

            $credentialEsc = mysqli_real_escape_string($conn, $credentialId);
            $q = mysqli_query($conn, "SELECT b.*, u.* 
                                      FROM user_webauthn_credentials b
                                      JOIN users u ON u.id = b.user_id
                                      WHERE b.credential_id = '$credentialEsc'
                                      LIMIT 1");
            if (!$q || mysqli_num_rows($q) !== 1) throw new Exception('Biometrik belum terdaftar di device ini');

            $row = mysqli_fetch_assoc($q);
            if (isset($row['status']) && (int)$row['status'] === 0) throw new Exception('Akun anda telah dinonaktifkan');

            $clientDataJSON = bio_base64url_decode($data['response']['clientDataJSON'] ?? '');
            $authenticatorData = bio_base64url_decode($data['response']['authenticatorData'] ?? '');
            $signature = bio_base64url_decode($data['response']['signature'] ?? '');
            $client = json_decode($clientDataJSON, true);

            if (($client['type'] ?? '') !== 'webauthn.get') throw new Exception('Tipe login biometrik tidak valid');
            if (!hash_equals($_SESSION['webauthn_login_challenge'] ?? '', $client['challenge'] ?? '')) throw new Exception('Challenge login tidak cocok');
            if (($client['origin'] ?? '') !== bio_origin()) throw new Exception('Origin login tidak cocok');

            $parsed = bio_parse_auth_data($authenticatorData);
            if (!$parsed['up'] || !$parsed['uv']) throw new Exception('Verifikasi user biometrik gagal');
            if (!hash_equals(hash('sha256', bio_rp_id(), true), $parsed['rpIdHash'])) throw new Exception('RP ID login tidak cocok');

            $verifyData = $authenticatorData . hash('sha256', $clientDataJSON, true);
            $publicKey = openssl_pkey_get_public($row['public_key_pem']);
            if (!$publicKey) throw new Exception('Public key credential tidak valid');

            $verifyOk = openssl_verify($verifyData, $signature, $publicKey, OPENSSL_ALGO_SHA256);
            if ($verifyOk !== 1) throw new Exception('Signature biometrik tidak valid');

            $newSignCount = (int)$parsed['signCount'];
            $credDbId = (int)$row['id'];
            mysqli_query($conn, "UPDATE user_webauthn_credentials SET sign_count='$newSignCount', last_used_at=NOW() WHERE id='$credDbId'");

            $_SESSION['__login_method'] = 'BIOMETRIC';
            bio_login_user($conn, $row);
            // REMEMBER LOGIN DISABLED - SESSION ONLY

            unset($_SESSION['webauthn_login_challenge']);
            bio_json(['ok' => true, 'redirect' => 'dashboard.php']);
        } catch (Exception $e) {
            bio_json(['ok' => false, 'message' => $e->getMessage()], 400);
        }
    }

    bio_json(['ok' => false, 'message' => 'Endpoint biometrik tidak ditemukan'], 404);
}

/*
|--------------------------------------------------------------------------
| AUTO LOGIN DARI COOKIE (REMEMBER ME)
|--------------------------------------------------------------------------
*/
if (false && !isset($_SESSION['user_id']) && isset($_COOKIE['remember_user']) && isset($_COOKIE['remember_token'])) {
    $cookie_username = mysqli_real_escape_string($conn, trim((string)$_COOKIE['remember_user']));
    $cookie_token = (string)$_COOKIE['remember_token'];

    $sql_remember = "SELECT *
                     FROM users
                     WHERE username = '$cookie_username'
                       AND remember_token IS NOT NULL
                       AND remember_expiry IS NOT NULL
                       AND remember_expiry > NOW()
                     LIMIT 1";
    $result_remember = mysqli_query($conn, $sql_remember);

    if ($result_remember && mysqli_num_rows($result_remember) === 1) {
        $user_remember = mysqli_fetch_assoc($result_remember);

        if (hash_equals((string)$user_remember['remember_token'], $cookie_token)) {
            if (isset($user_remember['status']) && (int)$user_remember['status'] === 0) {
                clearRememberCookie();
            } else {
                $_SESSION['__login_method'] = 'REMEMBER_COOKIE';
                bio_login_user($conn, $user_remember);
                server_monitor_write_login_activity($conn, $user_remember, 'REMEMBER_COOKIE', 'SUCCESS');

                header("Location: dashboard.php");
                exit;
            }
        } else {
            clearRememberCookie();
        }
    } else {
        clearRememberCookie();
    }
}

/*
|--------------------------------------------------------------------------
| PROSES LOGIN MANUAL
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $security = security_check_bruteforce($conn, $_POST['username'] ?? '');

    if($security['blocked']){

        $error = "Kesempatan login telah habis. Login dikunci sementara.";

        $lockSeconds = $security['seconds'];

    } else {

    $username = mysqli_real_escape_string($conn, trim($_POST['username'] ?? ''));
    $password = $_POST['password'] ?? '';
    $remember = 0; // SESSION LOGIN ONLY
    $enableBiometric = (isset($_POST['enable_biometric']) && (($_POST['biometric_device_mode'] ?? '') === '1')) ? 1 : 0;

    $sql = "SELECT * FROM users WHERE username = '$username' LIMIT 1";
    $result = mysqli_query($conn, $sql);

    if ($result && mysqli_num_rows($result) === 1) {
        $user = mysqli_fetch_assoc($result);

        if (password_verify($password, $user['password'])) {
            if (isset($user['status']) && (int)$user['status'] === 0) {
                server_monitor_write_failed_login($conn, $user['username'], 'ACCOUNT_DISABLED');
                $nonaktif = true;
            } else {
                $_SESSION['__login_method'] = 'PASSWORD';
                bio_login_user($conn, $user);

                // AUTO REMEMBER LOGIN TOKEN
                // User cukup login 1x di device/browser ini. Token berlaku 180 hari selama cookie tidak dihapus.
                // REMEMBER LOGIN DISABLED - SESSION ONLY

                if ($enableBiometric) {
                    $_SESSION['biometric_setup_after_login'] = 1;
                    header("Location: login.php?setup_biometric=1");
                    exit;
                }

                header("Location: dashboard.php");
                exit;
            }
        } else {
            server_monitor_write_failed_login($conn, $username, 'FAILED_PASSWORD');
            write_login_attempt($conn, $username, 'FAILED');
            $attemptInfo = security_check_bruteforce($conn, $username);
            $error = "Username atau password salah.
Kesempatan login anda tersisa " . $attemptInfo['remaining'] . "x lagi.";
        }
    } else {
        server_monitor_write_failed_login($conn, $username, 'FAILED_USERNAME');
        write_login_attempt($conn, $username, 'FAILED');
        $attemptInfo = security_check_bruteforce($conn, $username);
        $error = "Username atau password salah.
Kesempatan login anda tersisa " . $attemptInfo['remaining'] . "x lagi.";
    }
    }
}

$autoSetupBiometric = isset($_GET['setup_biometric']) && isset($_SESSION['user_id']) && !empty($_SESSION['biometric_setup_after_login']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Web Portal Login</title>
<link rel="icon" type="image/png" href="img/srt2.png" />
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
:root {
    --primary: #10b981;
    --primary-2: #20c997;
    --primary-dark: #059669;
    --ink: #111827;
    --muted: #6b7280;
    --line: rgba(15, 23, 42, .12);
    --card: rgba(255, 255, 255, .92);
    --shadow: 0 28px 80px rgba(15, 23, 42, .24);
}

* {
    box-sizing: border-box;
}

html,
body {
    min-height: 100%;
}

body {
    margin: 0;
    padding: 28px 16px;
    font-family: "Segoe UI", Arial, sans-serif;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    flex-direction: column;
    background: #0f172a;
    position: relative;
    overflow-x: hidden;
}

body::before {
    content: "";
    position: fixed;
    inset: 0;
    background:
        radial-gradient(circle at 50% 24%, rgba(255, 255, 255, .34), transparent 34%),
        linear-gradient(180deg, rgba(255, 255, 255, .05), rgba(15, 23, 42, .20));
    pointer-events: none;
    z-index: 0;
}

.login-box,
footer {
    position: relative;
    z-index: 1;
}

.login-box {
    background: var(--card);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    padding: 38px 40px 34px;
    border-radius: 20px;
    box-shadow: var(--shadow);
    width: 460px;
    max-width: 94%;
    text-align: center;
    border: 1px solid rgba(255, 255, 255, .72);
    overflow: hidden;
    animation: loginRise .55s ease both;
}

.login-box::before {
    content: "";
    position: absolute;
    inset: 0;
    background:
        radial-gradient(circle at 10% -12%, rgba(16, 185, 129, .14), transparent 28%),
        radial-gradient(circle at 110% 18%, rgba(59, 130, 246, .13), transparent 25%);
    pointer-events: none;
}

.login-box > * {
    position: relative;
    z-index: 1;
}

.login-box .logo {
    width: 76px;
    margin-bottom: 12px;
    filter: drop-shadow(0 8px 18px rgba(15, 23, 42, .12));
}

.login-box h2 {
    margin: 0 0 8px;
    color: #1f2937;
    font-weight: 800;
    letter-spacing: -.55px;
    font-size: 29px;
    line-height: 1.16;
}

.subtitle {
    margin-bottom: 24px;
    color: var(--muted);
    font-size: 15px;
}

.input-group {
    position: relative;
    margin-bottom: 14px;
    width: 100%;
}

.input-group i.left-icon {
    position: absolute;
    top: 50%;
    left: 16px;
    transform: translateY(-50%);
    color: #8b95a5;
    font-size: 15px;
    z-index: 2;
}

.input-group input {
    width: 100%;
    height: 51px;
    padding: 0 48px 0 48px;
    border: 1px solid var(--line);
    border-radius: 13px;
    font-size: 15px;
    box-sizing: border-box;
    outline: none;
    background: rgba(255, 255, 255, .88);
    color: var(--ink);
    transition: border .22s ease, box-shadow .22s ease, background .22s ease;
}

.input-group input::placeholder {
    color: #747c8a;
}

.input-group input:focus {
    border-color: rgba(16, 185, 129, .72);
    box-shadow: 0 0 0 4px rgba(16, 185, 129, .13);
    background: #fff;
}

.toggle-password {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    color: #7b8494;
    padding: 6px;
    font-size: 15px;
    z-index: 2;
}

.remember-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-top: -2px;
    margin-bottom: 10px;
    font-size: 13px;
}

.remember-row span {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: #667085 !important;
    text-align: left;
}

.remember-me {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #555;
}

.remember-me input[type="checkbox"],
.biometric-row input[type="checkbox"] {
    width: 17px;
    height: 17px;
    accent-color: var(--primary);
    cursor: pointer;
}

.biometric-row {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #555;
    font-size: 13px;
    margin: 0 0 16px;
    text-align: left;
}

.forgot-link {
    color: var(--primary-dark);
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
}

.forgot-link:hover {
    text-decoration: underline;
}

button {
    width: 100%;
    padding: 13px;
    min-height: 51px;
    background: linear-gradient(135deg, #24c69a, #0ea983);
    border: none;
    color: white;
    border-radius: 13px;
    font-weight: 800;
    cursor: pointer;
    transition: transform .22s ease, box-shadow .22s ease, background .22s ease;
    font-size: 15px;
    letter-spacing: .1px;
}

button:hover {
    transform: translateY(-1px);
    box-shadow: 0 14px 26px rgba(14, 169, 131, .28);
}

.btn-biometric-login {
    margin-top: 11px;
    background: linear-gradient(135deg, #172033, #111827);
    box-shadow: 0 12px 22px rgba(17, 24, 39, .18);
}

.btn-biometric-login:hover {
    background: linear-gradient(135deg, #1d293d, #101827);
    box-shadow: 0 14px 26px rgba(17, 24, 39, .22);
}

.error {
    color: #b91c1c;
    margin-bottom: 14px;
    font-size: 13px;
    background: rgba(254, 226, 226, .86);
    border: 1px solid rgba(248, 113, 113, .34);
    padding: 11px 13px;
    border-radius: 12px;
    text-align: left;
}

#loading {
    display: none;
    font-size: 13px;
    color: var(--primary-dark);
    margin-top: 10px;
    font-weight: 600;
}

.policy-links {
    margin-top: 19px;
    font-size: 12.5px;
    color: #777;
}

.policy-links a {
    color: #667085;
    text-decoration: none;
    font-weight: 500;
}

.policy-links a:hover {
    color: var(--primary-dark);
    text-decoration: underline;
}

footer {
    width: min(448px, 94%);
    margin-top: 18px;
    padding: 13px 16px 15px;
    font-size: 12px;
    color: rgba(255, 255, 255, .92);
    text-shadow: none;
    text-align: center;
    border-radius: 18px;
    background: rgba(15, 23, 42, .27);
    border: 1px solid rgba(255, 255, 255, .22);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    box-shadow: 0 16px 34px rgba(15, 23, 42, .16);
    animation: loginRise .62s ease both;
}

footer .division {
    font-size: 13px;
    font-weight: 700;
    color: rgba(255, 255, 255, .94);
    margin-bottom: 4px;
}

footer .copyright {
    font-size: 12px;
    color: rgba(255, 255, 255, .82);
    letter-spacing: 0.2px;
    margin-bottom: 10px;
}

.brand-logos {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 9px;
    flex-wrap: wrap;
    margin-top: 8px;
}

.brand-logos img {
    width: 30px;
    height: 30px;
    object-fit: contain;
    background: rgba(255, 255, 255, .90);
    border-radius: 8px;
    padding: 3px;
    filter: none;
    box-shadow: 0 6px 12px rgba(15, 23, 42, .16);
}

.modal {
    display: none;
    position: fixed;
    z-index: 9999;
    inset: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background: rgba(15, 23, 42, .58);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    padding: 20px;
}

.modal-content {
    background: #fff;
    margin: 6vh auto;
    padding: 0;
    border-radius: 20px;
    width: min(800px, 100%);
    box-shadow: 0 28px 70px rgba(15, 23, 42, .30);
    max-height: 82vh;
    overflow-y: auto;
    text-align: left;
    border: 1px solid rgba(15, 23, 42, .10);
    animation: modalIn .22s ease both;
}

.modal h3 {
    margin: 0;
    color: #111827;
    font-size: 19px;
    padding: 19px 24px;
    border-bottom: 1px solid #eef2f7;
    position: sticky;
    top: 0;
    background: #fff;
    z-index: 1;
}

.modal p {
    padding: 20px 24px 24px;
    margin: 0;
    color: #334155;
    font-size: 14px;
    line-height: 1.72;
}

.close {
    color: #475569;
    float: right;
    font-size: 20px;
    cursor: pointer;
    background: #f1f5f9;
    width: 36px;
    height: 36px;
    border-radius: 11px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin: 13px 16px 0 0;
    position: sticky;
    top: 13px;
    z-index: 2;
}

.close:hover {
    color: #0f172a;
    background: #e2e8f0;
}

@keyframes loginRise {
    from {
        opacity: 0;
        transform: translateY(14px) scale(.985);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

@keyframes modalIn {
    from {
        opacity: 0;
        transform: translateY(10px) scale(.98);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

@media (max-width: 560px) {
    body {
        padding: 22px 14px;
        justify-content: flex-start;
        overflow-y: auto;
    }

    .login-box {
        width: 100%;
        padding: 30px 22px 28px;
        border-radius: 18px;
    }

    .login-box h2 {
        font-size: 25px;
    }

    .remember-row {
        align-items: flex-start;
        flex-direction: column;
    }

    .forgot-link {
        margin-left: 25px;
    }

    footer {
        width: 100%;
    }
}

/* ============================================================
   FINAL BACKGROUND FOTO SLIDER - NAMA FILE SESUAI FOLDER IMG
   Pastikan file ini ada:
   img/view.jpg
   img/padang1.png
   img/soetta1.png
   img/takeoff.png
============================================================ */
html,
body {
    background: #0f172a !important;
}

.login-bg-slider {
    position: fixed !important;
    inset: 0 !important;
    z-index: 0 !important;
    overflow: hidden !important;
    background: #0f172a !important;
}

.login-bg-slider .bg-slide {
    position: absolute !important;
    inset: 0 !important;
    background-position: center center !important;
    background-size: cover !important;
    background-repeat: no-repeat !important;
    opacity: 0;
    animation: loginBgFadeOnly 32s infinite ease-in-out !important;
    transform: none !important;
    will-change: opacity;
}

.login-bg-slider .bg-slide::after {
    content: "";
    position: absolute;
    inset: 0;
    background:
        linear-gradient(120deg, rgba(15, 23, 42, .44), rgba(15, 23, 42, .15), rgba(2, 132, 199, .14)),
        radial-gradient(circle at 50% 22%, rgba(255,255,255,.17), transparent 34%),
        linear-gradient(180deg, rgba(255,255,255,.02), rgba(15,23,42,.20));
}

.login-bg-slider .bg-slide-1 {
    background-image: url('img/view.jpg') !important;
    animation-delay: 0s !important;
}

.login-bg-slider .bg-slide-2 {
    background-image: url('img/padang1.png') !important;
    animation-delay: 8s !important;
}

.login-bg-slider .bg-slide-3 {
    background-image: url('img/soetta1.png') !important;
    animation-delay: 16s !important;
}

.login-bg-slider .bg-slide-4 {
    background-image: url('img/takeoff.png') !important;
    animation-delay: 24s !important;
}

.login-bg-overlay {
    position: fixed !important;
    inset: 0 !important;
    z-index: 1 !important;
    pointer-events: none !important;
    background:
        radial-gradient(circle at 50% 18%, rgba(255,255,255,.08), transparent 28%),
        linear-gradient(180deg, rgba(255,255,255,.03), rgba(15,23,42,.12));
}

.login-box,
footer {
    position: relative !important;
    z-index: 5 !important;
}

@keyframes loginBgFadeOnly {
    0% { opacity: 0; }
    7% { opacity: 1; }
    25% { opacity: 1; }
    32% { opacity: 0; }
    100% { opacity: 0; }
}

@media (prefers-reduced-motion: reduce) {
    .login-bg-slider .bg-slide {
        animation: none !important;
        opacity: 0;
    }

    .login-bg-slider .bg-slide-1 {
        opacity: 1;
    }
}

</style>
</head>
<body>

<div class="login-bg-slider" aria-hidden="true">
    <div class="bg-slide bg-slide-1"></div>
    <div class="bg-slide bg-slide-2"></div>
    <div class="bg-slide bg-slide-3"></div>
    <div class="bg-slide bg-slide-4"></div>
</div>
<div class="login-bg-overlay" aria-hidden="true"></div>

<div class="login-box">
    <img src="img/srt4.png" class="logo" alt="Logo">
    <h2>Web Portal Minimarket</h2>
    <div class="subtitle">Silakan masuk ke akun Anda</div>

    <?php if (isset($error)) echo "<div class='error'>" . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . "</div>"; ?>
<?php if(isset($lockSeconds) && $lockSeconds>0): ?>
<script>
let lockSeconds = <?= (int)$lockSeconds ?>;

function updateLockTimer(){
    const box=document.querySelector('.error');

    if(box){
        let min=Math.floor(lockSeconds/60);
        let sec=lockSeconds%60;

        box.innerHTML =
        "Kesempatan login telah habis.<br>" +
        "Login dikunci sementara.<br>" +
        "Silakan tunggu: <b>" +
        String(min).padStart(2,'0') + ":" +
        String(sec).padStart(2,'0') +
        "</b>";
    }

    if(lockSeconds<=0){
        location.reload();
        return;
    }

    lockSeconds--;
}

updateLockTimer();
setInterval(updateLockTimer,1000);
</script>
<?php endif; ?>


    <form method="POST" action="" onsubmit="showLoading()">
        <div class="input-group">
            <i class="fa fa-user left-icon"></i>
            <input type="text" name="username" placeholder="Username" required>
        </div>

        <div class="input-group">
            <i class="fa fa-lock left-icon"></i>
            <input type="password" name="password" id="password" placeholder="Password" required>
            <i class="fa fa-eye toggle-password" onclick="togglePassword()"></i>
        </div>

        <div class="remember-row">
            <span style="font-size:12px;color:#6b7280;">
                <i class="fa fa-shield"></i> Login otomatis aktif di device ini
            </span>

            <a href="forgot_password.php" class="forgot-link">Lupa password?</a>
        </div>

        <input type="hidden" name="biometric_device_mode" id="biometric_device_mode" value="0">

        <div id="biometric-device-only" style="display:none;">
            <label class="biometric-row">
                <input type="checkbox" name="enable_biometric" id="enable_biometric" value="1">
                <span><i class="fa fa-fingerprint"></i> Aktifkan Biometrik / Face ID di device ini</span>
            </label>
        </div>

        <button type="submit">Login</button>

        <div id="biometric-login-device-only" style="display:none;">
            <button type="button" class="btn-biometric-login" onclick="loginBiometric()">
                <i class="fa fa-fingerprint"></i> Login dengan Biometrik
            </button>
        </div>

        <div id="loading">
            <i class="fa fa-spinner fa-spin"></i> Authentication...
        </div>
    </form>

    <div class="policy-links">
        <a href="#" onclick="openModal('privacy')">Kebijakan Privasi</a> |
        <a href="#" onclick="openModal('terms')">Syarat & Ketentuan</a>
    </div>
</div>

<footer>
    <div class="division">Divisi Minimarket</div>
    <div class="copyright">&copy; <?= date("Y") ?> SRTCorporationgroup, All Rights Reserved.</div>
    <div class="brand-logos">
        <img src="img/papimart.png" alt="Papimart">
        <img src="img/pointone.png" alt="Point One">
        <img src="img/aby.png" alt="ABY">
        <img src="img/lattestory.png" alt="Latte Story">
        <img src="img/urban.png" alt="Urban">
        <img src="img/papicoffee.png" alt="Papi Coffee">
        <img src="img/mmart.png" alt="M mart">
        <img src="img/ls.png" alt="The Latte Story">
    </div>
</footer>

<div id="modal-privacy" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('privacy')">&times;</span>
        <h3>Kebijakan Privasi</h3>
        <p> Web Portal Minimarket menghargai privasi pengguna. Kami mengumpulkan data pribadi seperti nama, email, nomor telepon, username, dan informasi login (IP, perangkat, browser) untuk keperluan autentikasi, keamanan, dan pemberian layanan. 
        Data ini disimpan aman, password terenkripsi, dan tidak dibagikan ke pihak ketiga tanpa izin, kecuali untuk kepatuhan hukum atau penyediaan layanan yang diperlukan.
        Pengguna memiliki hak untuk mengakses, memperbarui, atau meminta penghapusan data pribadi sesuai kebijakan.</p>
    </div>
</div>

<div id="modal-terms" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('terms')">&times;</span>
        <h3>Syarat & Ketentuan</h3>
        <p>Dengan menggunakan portal ini, pengguna menyetujui aturan berikut: akun bersifat pribadi dan tidak boleh dibagikan, menjaga kerahasiaan login, menggunakan portal sesuai hukum, portal dapat menonaktifkan akun jika melanggar ketentuan, portal dapat mengubah layanan kapan saja dengan pemberitahuan.
        Portal menyediakan opsi login manual maupun biometrik. Cookie login otomatis dapat digunakan untuk kenyamanan, dan pengguna tetap bertanggung jawab atas keamanan akun.
</p>
    </div>
</div>

<?php if (isset($nonaktif) && $nonaktif): ?>
<script>
Swal.fire({
    icon: 'warning',
    title: 'Akun anda telah dinonaktifkan',
    html: '<small>Harap menghubungi Super Admin</small>',
    confirmButtonColor: '#3085d6',
    confirmButtonText: 'Oke'
});
</script>
<?php endif; ?>

<script>
const shouldSetupBiometric = <?php echo $autoSetupBiometric ? 'true' : 'false'; ?>;

/*
 * Biometrik hanya ditampilkan di device mobile/tablet.
 * Desktop browser maupun PWA desktop tetap menggunakan login biasa.
 */
function isBiometricDeviceMode() {
    const ua = navigator.userAgent || navigator.vendor || window.opera || '';
    const platform = navigator.platform || '';

    const android = /Android/i.test(ua);
    const ios = /iPhone|iPad|iPod/i.test(ua);
    const mobileUa = /Mobi|Mobile/i.test(ua);

    // iPadOS modern kadang melaporkan dirinya sebagai Mac.
    const ipadOS = platform === 'MacIntel' && navigator.maxTouchPoints > 1;

    return android || ios || mobileUa || ipadOS;
}

function applyBiometricDeviceVisibility() {
    const deviceMode = isBiometricDeviceMode();
    const setupWrap = document.getElementById('biometric-device-only');
    const loginWrap = document.getElementById('biometric-login-device-only');
    const marker = document.getElementById('biometric_device_mode');
    const checkbox = document.getElementById('enable_biometric');

    if (setupWrap) setupWrap.style.display = deviceMode ? 'block' : 'none';
    if (loginWrap) loginWrap.style.display = deviceMode ? 'block' : 'none';
    if (marker) marker.value = deviceMode ? '1' : '0';

    if (!deviceMode && checkbox) checkbox.checked = false;

    return deviceMode;
}

function isBiometricSupported() {
    return !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);
}

function showBiometricNotSupported() {
    Swal.fire({
        icon: 'warning',
        title: 'Device anda belum support biometrik',
        text: 'Silakan login menggunakan username dan password.',
        confirmButtonText: 'Oke'
    });
}


<?php if(isset($lockSeconds) && $lockSeconds > 0): ?>
<script>
let lockTime = <?= (int)$lockSeconds ?>;

function runLockCountdown(){

    let box=document.querySelector('.error');

    if(box){

        let m=Math.floor(lockTime/60);
        let s=lockTime%60;

        box.innerHTML =
        "Kesempatan login telah habis.
"+
        "Login dikunci sementara.
Sisa waktu: "+
        m+" menit "+
        String(s).padStart(2,'0')+
        " detik.";

    }

    if(lockTime<=0){
        location.reload();
        return;
    }

    lockTime--;
}

setInterval(runLockCountdown,1000);
runLockCountdown();
</script>
<?php endif; ?>

function showLoading() {
    document.getElementById("loading").style.display = "block";
}
function togglePassword() {
    const input = document.getElementById("password");
    input.type = input.type === "password" ? "text" : "password";
}
function openModal(type) {
    document.getElementById("modal-" + type).style.display = "block";
}
function closeModal(type) {
    document.getElementById("modal-" + type).style.display = "none";
}
window.onclick = function(event) {
    const privacy = document.getElementById("modal-privacy");
    const terms = document.getElementById("modal-terms");
    if (event.target == privacy) privacy.style.display = "none";
    if (event.target == terms) terms.style.display = "none";
}

function b64urlToBuffer(base64url) {
    const pad = '='.repeat((4 - base64url.length % 4) % 4);
    const base64 = (base64url + pad).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const buffer = new ArrayBuffer(raw.length);
    const bytes = new Uint8Array(buffer);
    for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
    return buffer;
}

function bufferToB64url(buffer) {
    const bytes = new Uint8Array(buffer);
    let str = '';
    for (let i = 0; i < bytes.byteLength; i++) str += String.fromCharCode(bytes[i]);
    return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}

function credentialToJSON(cred) {
    const obj = {
        id: cred.id,
        rawId: bufferToB64url(cred.rawId),
        type: cred.type,
        response: {}
    };
    if (cred.response.clientDataJSON) obj.response.clientDataJSON = bufferToB64url(cred.response.clientDataJSON);
    if (cred.response.attestationObject) obj.response.attestationObject = bufferToB64url(cred.response.attestationObject);
    if (cred.response.authenticatorData) obj.response.authenticatorData = bufferToB64url(cred.response.authenticatorData);
    if (cred.response.signature) obj.response.signature = bufferToB64url(cred.response.signature);
    if (cred.response.userHandle) obj.response.userHandle = bufferToB64url(cred.response.userHandle);
    return obj;
}

async function registerBiometric() {
    if (!isBiometricDeviceMode()) {
        // Desktop / PWA desktop tidak menggunakan fitur biometrik.
        if (shouldSetupBiometric) window.location.href = 'dashboard.php';
        return;
    }

    if (!isBiometricSupported()) {
        showBiometricNotSupported();
        return;
    }

    try {
        if (window.PublicKeyCredential && PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) {
            const available = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
            if (!available) {
                showBiometricNotSupported();
                return;
            }
        }

        Swal.fire({
            title: 'Mengaktifkan Biometrik',
            text: 'Ikuti instruksi Face ID / sidik jari di device Anda.',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        const optRes = await fetch('login.php?webauthn=register_options', { cache: 'no-store' });
        const optData = await optRes.json();
        if (!optData.ok) throw new Error(optData.message || 'Gagal membuat opsi biometrik');

        const publicKey = optData.publicKey;
        publicKey.challenge = b64urlToBuffer(publicKey.challenge);
        publicKey.user.id = b64urlToBuffer(publicKey.user.id);
        if (publicKey.excludeCredentials) {
            publicKey.excludeCredentials = publicKey.excludeCredentials.map(c => ({
                ...c,
                id: b64urlToBuffer(c.id)
            }));
        }

        const credential = await navigator.credentials.create({ publicKey });
        const payload = credentialToJSON(credential);
        payload.device_name = navigator.platform || 'Device Biometrik';

        const verifyRes = await fetch('login.php?webauthn=register_verify', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const verifyData = await verifyRes.json();

        if (!verifyData.ok) throw new Error(verifyData.message || 'Gagal verifikasi biometrik');

        Swal.fire({
            icon: 'success',
            title: 'Biometrik Aktif',
            text: 'Device ini sudah bisa login menggunakan Face ID / sidik jari.',
            confirmButtonText: 'Masuk Dashboard'
        }).then(() => {
            window.location.href = 'dashboard.php';
        });
    } catch (err) {
        Swal.fire({
            icon: 'error',
            title: 'Gagal Aktifkan Biometrik',
            text: err.message || 'Proses dibatalkan atau gagal.'
        }).then(() => {
            window.location.href = 'dashboard.php';
        });
    }
}

async function loginBiometric() {
    if (!isBiometricDeviceMode()) return;

    if (!isBiometricSupported()) {
        showBiometricNotSupported();
        return;
    }

    try {
        if (window.PublicKeyCredential && PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) {
            const available = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
            if (!available) {
                showBiometricNotSupported();
                return;
            }
        }

        Swal.fire({
            title: 'Login Biometrik',
            text: 'Gunakan Face ID / sidik jari untuk masuk.',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        const optRes = await fetch('login.php?webauthn=login_options', { cache: 'no-store' });
        const optData = await optRes.json();
        if (!optData.ok) throw new Error(optData.message || 'Gagal membuat opsi login');

        if (optData.has_credentials === false) {
            throw new Error('Biometrik belum terdaftar di device ini. Login manual dulu, lalu aktifkan biometrik.');
        }

        const publicKey = optData.publicKey;
        publicKey.challenge = b64urlToBuffer(publicKey.challenge);

        if (publicKey.allowCredentials) {
            publicKey.allowCredentials = publicKey.allowCredentials.map(c => ({
                ...c,
                id: b64urlToBuffer(c.id)
            }));
        }

        const assertion = await navigator.credentials.get({ publicKey });
        const payload = credentialToJSON(assertion);

        const verifyRes = await fetch('login.php?webauthn=login_verify', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const verifyData = await verifyRes.json();

        if (!verifyData.ok) throw new Error(verifyData.message || 'Login biometrik gagal');

        window.location.href = verifyData.redirect || 'dashboard.php';
    } catch (err) {
        Swal.fire({
            icon: 'error',
            title: 'Login Biometrik Gagal',
            text: err.message || 'Biometrik dibatalkan atau gagal.'
        });
    }
}

document.addEventListener('DOMContentLoaded', applyBiometricDeviceVisibility);

if (shouldSetupBiometric) {
    window.addEventListener('load', function() {
        applyBiometricDeviceVisibility();

        if (isBiometricDeviceMode()) {
            registerBiometric();
        } else {
            // Jika redirect setup biometrik terbuka di desktop/PWA desktop, langsung lanjut dashboard.
            window.location.href = 'dashboard.php';
        }
    });
}
</script>
</body>
</html>
