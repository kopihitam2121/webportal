<?php
session_start();

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'=>time()-42000,
        'path'=>$params['path'] ?: '/',
        'domain'=>$params['domain'] ?? '',
        'secure'=>(bool)($params['secure'] ?? false),
        'httponly'=>(bool)($params['httponly'] ?? true),
        'samesite'=>'Strict'
    ]);
}

setcookie('WEBPORTAL_REMEMBER','',time()-42000,'/');

session_destroy();

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Location: login.php?logout=1');
exit;
?>
