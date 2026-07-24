<?php
require_once __DIR__ . '/../includes/bootstrap.php';

// 仅接受 POST + CSRF，防止跨站强制登出
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    header('Location: index.php');
    exit;
}

if (is_logged_in()) {
    admin_log_write('logout', '退出登录', [
        'module' => 'auth',
        'level' => 'info',
    ]);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: login.php');
exit;
