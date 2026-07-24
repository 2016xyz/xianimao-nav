<?php
/**
 * 发起 Linux.do Connect OAuth2 授权
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/oauth_providers.php';
require_login();

if (!oauth_linuxdo_app_ready()) {
    flash_set('error', '请先填写 Linux.do Connect 的 Client ID 与 Client Secret，再到 connect.linux.do 申请应用并配置回调地址');
    redirect('hotboards.php#linuxdo-auth');
}

$url = oauth_linuxdo_build_authorize_url();
if ($url === null) {
    flash_set('error', '无法生成授权链接');
    redirect('hotboards.php#linuxdo-auth');
}

header('Location: ' . $url);
exit;
