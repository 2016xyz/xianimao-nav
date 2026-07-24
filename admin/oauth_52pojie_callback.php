<?php
/**
 * 吾爱 OAuth 式授权回调：校验 state 并持久化 Cookie
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/oauth_providers.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('error', '请从授权页提交表单');
    redirect('hotboards.php#52pojie-auth');
}

if (!verify_csrf()) {
    flash_set('error', '安全校验失败，请重试');
    redirect('hotboards.php#52pojie-auth');
}

$result = oauth_52pojie_handle_callback([
    'state' => $_POST['state'] ?? '',
    'cookie' => $_POST['cookie'] ?? '',
    'username' => $_POST['username'] ?? '',
]);

if (!empty($result['ok'])) {
    admin_log_write('hotboards_52pojie_oauth', '吾爱 OAuth 授权成功', [
        'module' => 'hotboards',
        'level' => 'success',
        'detail' => ['username' => $result['username'] ?? ''],
    ]);
    flash_set('success', $result['message'] ?? '授权成功');
} else {
    admin_log_write('hotboards_52pojie_oauth_fail', '吾爱 OAuth 授权失败', [
        'module' => 'hotboards',
        'level' => 'error',
        'detail' => ['message' => $result['message'] ?? ''],
    ]);
    flash_set('error', $result['message'] ?? '授权失败');
}
redirect('hotboards.php#52pojie-auth');
