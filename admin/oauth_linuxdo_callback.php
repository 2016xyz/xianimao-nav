<?php
/**
 * Linux.do Connect OAuth2 回调
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/oauth_providers.php';
require_login();

if (isset($_GET['error'])) {
    $err = trim((string) $_GET['error']);
    $desc = trim((string) ($_GET['error_description'] ?? ''));
    flash_set('error', 'Linux.do 授权被拒绝：' . $err . ($desc !== '' ? ' — ' . $desc : ''));
    redirect('hotboards.php#linuxdo-auth');
}

$code = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');
$result = oauth_linuxdo_handle_callback($code, $state);

if (!empty($result['ok'])) {
    $u = !empty($result['username']) ? ('（' . $result['username'] . '）') : '';
    admin_log_write('hotboards_linuxdo_oauth', 'Linux.do OAuth 授权成功' . $u, [
        'module' => 'hotboards',
        'level' => 'success',
        'detail' => ['username' => $result['username'] ?? ''],
    ]);
    flash_set('success', ($result['message'] ?? '授权成功') . $u);
} else {
    admin_log_write('hotboards_linuxdo_oauth_fail', 'Linux.do OAuth 授权失败', [
        'module' => 'hotboards',
        'level' => 'error',
        'detail' => ['message' => $result['message'] ?? ''],
    ]);
    flash_set('error', $result['message'] ?? '授权失败');
}
redirect('hotboards.php#linuxdo-auth');
