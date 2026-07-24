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
    flash_set('success', $result['message'] ?? '授权成功');
} else {
    flash_set('error', $result['message'] ?? '授权失败');
}
redirect('hotboards.php#52pojie-auth');
