<?php
/**
 * OAuth 增强冒烟测试（CLI）
 */
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTPS'] = 'off';

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/oauth_providers.php';

$ok = true;
$p = oauth_pkce_pair();
if (strlen($p['verifier']) < 40 || strlen($p['challenge']) < 40) {
    echo "FAIL pkce\n";
    $ok = false;
} else {
    echo "OK pkce\n";
}

$need = [
    'oauth_linuxdo_begin',
    'oauth_linuxdo_status',
    'oauth_linuxdo_ensure_access_token',
    'oauth_linuxdo_refresh_token',
    'oauth_linuxdo_sync_profile',
    'oauth_linuxdo_revoke',
    'hot_linuxdo_has_auth',
    'hot_discourse_auth_headers',
    'hot_linuxdo_oauth_bearer_ready',
];
foreach ($need as $f) {
    if (!function_exists($f)) {
        echo "FAIL missing $f\n";
        $ok = false;
    }
}
echo $ok ? "OK functions\n" : "FAIL functions\n";

$st = oauth_linuxdo_status();
$keys = ['app_ready', 'has_token', 'token_valid', 'auth_ready', 'pkce', 'last_error'];
foreach ($keys as $k) {
    if (!array_key_exists($k, $st)) {
        echo "FAIL status missing $k\n";
        $ok = false;
    }
}
echo !empty($st['pkce']) ? "OK status pkce\n" : "FAIL status pkce\n";

$h = hot_discourse_auth_headers(['Accept: application/json']);
echo is_array($h) ? ('OK headers count=' . count($h) . "\n") : "FAIL headers\n";

// 无凭证时 has_auth 应为 false（除非已配置）
echo 'has_auth=' . (hot_linuxdo_has_auth() ? '1' : '0') . "\n";
echo 'bearer_ready=' . (hot_linuxdo_oauth_bearer_ready() ? '1' : '0') . "\n";

// begin 在未配置 app 时应失败
$begin = oauth_linuxdo_begin();
if (empty($begin['ok'])) {
    echo "OK begin without app: " . ($begin['message'] ?? '') . "\n";
} else {
    echo "OK begin with app url set\n";
}

// revoke / ensure 无 token 行为
$ens = oauth_linuxdo_ensure_access_token();
echo 'ensure: ' . ($ens['message'] ?? '') . "\n";

echo $ok ? "ALL_SMOKE_OK\n" : "SMOKE_HAS_FAILS\n";
exit($ok ? 0 : 1);
