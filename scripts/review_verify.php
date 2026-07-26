<?php
/**
 * 代码审查修复后的快速验证（CLI）
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
require $root . '/includes/bootstrap.php';
require $root . '/includes/mailer.php';
require $root . '/includes/hot_fetcher.php';
require $root . '/includes/captcha.php';

$fail = 0;
function assert_true($cond, $msg)
{
    global $fail;
    if ($cond) {
        echo "PASS $msg\n";
    } else {
        echo "FAIL $msg\n";
        $fail++;
    }
}

// safe_http_url
$cases = [
    ['https://a.com', true],
    ['http://a.com/x', true],
    ['javascript:alert(1)', false],
    ['data:text/html,x', false],
    ['//evil.com', false],
    ['apply.php', true],
    ['/path/ok', true],
];
foreach ($cases as [$u, $ok]) {
    $r = safe_http_url($u, true);
    $pass = $ok ? ($r !== '') : ($r === '');
    assert_true($pass, "safe_http_url($u) got=[$r]");
}

// sanitize_admin_html
$xss = sanitize_admin_html('<p onclick=alert(1)>hi</p><script>x</script><a href="javascript:alert(1)">a</a>');
assert_true(
    strpos($xss, '<script') === false
    && stripos($xss, 'javascript:') === false
    && stripos($xss, 'onclick') === false,
    'sanitize_admin_html strips XSS vectors: ' . $xss
);

// functions
assert_true(function_exists('smtp_is_ready'), 'smtp_is_ready exists');
assert_true(function_exists('mailer_form_code_ip_throttle'), 'mailer_form_code_ip_throttle exists');
assert_true(function_exists('mailer_require_form_email_verified'), 'mailer_require_form_email_verified exists');

// hot boards
$ids = hot_board_enabled_ids();
assert_true(is_array($ids) && count($ids) > 0, 'hot_board_enabled_ids non-empty: ' . implode(',', $ids));

// captcha tries
captcha_create(4);
$_SESSION['admin_captcha_code'] = 'ABCD';
$_SESSION['admin_captcha_code_at'] = time();
$_SESSION['admin_captcha_code_tries'] = 0;
assert_true(!captcha_verify('WRONG', false), 'captcha rejects wrong code');
assert_true((int) ($_SESSION['admin_captcha_code_tries'] ?? 0) === 1, 'captcha tries increments');

// site data
$d = load_site_data();
assert_true(($d['site']['name'] ?? '') === '夏尼猫网址导航', 'site name rebranded');
assert_true(($d['site']['contact_email'] ?? '') === 'i@2016xlx.cn', 'contact email updated');

// SEO helpers
assert_true(function_exists('site_seo_meta') && function_exists('render_seo_head'), 'SEO helpers exist');
assert_true(site_seo_normalize_robots('INDEX, FOLLOW') === 'index,follow', 'robots normalize');
assert_true(site_seo_normalize_robots('javascript:alert(1)') === 'index,follow', 'robots reject junk');
$seo = site_seo_meta($d['site'] ?? [], []);
assert_true(($seo['title'] ?? '') !== '', 'seo title non-empty');
assert_true(isset($seo['keywords'], $seo['description'], $seo['robots']), 'seo meta keys present');
assert_true(array_key_exists('seo_title', $d['site'] ?? []), 'site has seo_title field');
assert_true(array_key_exists('seo_description', $d['site'] ?? []), 'site has seo_description field');

// 密钥 / settings 统一 API（无重复定义）
assert_true(function_exists('setting_get') && function_exists('setting_set'), 'setting_get/set exist once');
assert_true(function_exists('secret_blob_get') && function_exists('secret_blob_set'), 'secret_blob helpers');
assert_true(function_exists('security_ssl_verify_peer'), 'ssl verify helper');
assert_true(security_ssl_verify_peer() === true, 'ssl verify peer defaults on');

// HTML 消毒：去 style / 危险协议 / 约束 href
$dirty = '<p onclick="alert(1)" style="color:red">x</p><a href="javascript:alert(1)">y</a><a href="https://example.com" target="_blank">z</a>';
$clean = security_sanitize_html($dirty);
assert_true(strpos($clean, 'onclick') === false, 'sanitize strips onclick');
assert_true(strpos($clean, 'style=') === false, 'sanitize strips style');
assert_true(stripos($clean, 'javascript:') === false, 'sanitize strips javascript:');
assert_true(strpos($clean, 'https://example.com') !== false, 'sanitize keeps https href');

// Hero / projects 字段
assert_true(function_exists('site_hero_bg_url'), 'hero bg helper');
assert_true(function_exists('site_hero_bg_normalize'), 'hero normalize');
if (!empty($d['projects'][0])) {
    assert_true(array_key_exists('name', $d['projects'][0]), 'project has name');
}

// 出站 TLS 默认校验：includes 内不应再硬编码关闭
foreach (['ai.php', 'hot_fetcher.php', 'oauth_providers.php', 'mailer.php'] as $inc) {
    $srcInc = (string) file_get_contents($root . '/includes/' . $inc);
    assert_true(strpos($srcInc, "CURLOPT_SSL_VERIFYPEER => false") === false, $inc . ' no hard SSL_VERIFYPEER false');
    assert_true(strpos($srcInc, "'verify_peer' => false") === false, $inc . ' no hard verify_peer false');
}

// smtp_save_config return not constant true (inspect source)
$src = file_get_contents($root . '/includes/mailer.php');
assert_true(strpos($src, 'return $okFile || true;') === false, 'smtp_save no longer always true');
$src2 = file_get_contents($root . '/includes/oauth_providers.php');
assert_true(strpos($src2, 'return $okFile || true;') === false, 'oauth save no longer always true');

// install force removed
$inst = file_get_contents($root . '/install.php');
assert_true(strpos($inst, "empty(\$_GET['force'])") === false, 'install force bypass removed');
assert_true(strpos($inst, 'install_verify_csrf') !== false, 'install has CSRF');

// logout POST
$lo = file_get_contents($root . '/admin/logout.php');
assert_true(strpos($lo, 'REQUEST_METHOD') !== false && strpos($lo, 'verify_csrf') !== false, 'logout CSRF POST');

assert_true(defined('APP_VERSION') && APP_VERSION !== '', 'APP_VERSION defined');
assert_true(asset_url('assets/__not_exists__.css') === 'assets/__not_exists__.css?v=' . rawurlencode((string) APP_VERSION), 'asset_url falls back to APP_VERSION');

$secSrc = (string) file_get_contents($root . '/includes/security.php');
assert_true(strpos($secSrc, 'function security_ssl_verify_peer') === false, 'security.php no duplicate ssl helper');
$rfSsl = new ReflectionFunction('security_ssl_verify_peer');
assert_true(basename((string) $rfSsl->getFileName()) === 'security_ssl.php', 'ssl helper resolves to security_ssl.php');

assert_true(security_safe_redirect_target('hotboards.php#52pojie-auth') === 'hotboards.php#52pojie-auth', 'redirect keeps anchor');
assert_true(security_safe_redirect_target('../config/database.php') === 'index.php', 'redirect rejects traversal');
assert_true(security_safe_redirect_target('%2e%2e/config/x.php') === 'index.php', 'redirect rejects encoded traversal');
assert_true(security_safe_redirect_target('https://evil.com/') === 'index.php', 'redirect rejects absolute');
$hardSrc = (string) file_get_contents($root . '/includes/security_hardening.php');
assert_true(strpos($hardSrc, '%\-#]+$~') !== false, 'hardening redirect regex allows anchor');

assert_true(security_login_store_path() === security_login_lock_file(), 'login store path unified');

$hb = (string) file_get_contents($root . '/admin/hotboards.php');
assert_true(strpos($hb, "value=\"save_52pojie_cookie\"") !== false, 'hotboards cookie form exists');
assert_true(strpos($hb, "\$action === 'save_52pojie_cookie'") !== false, 'hotboards handles save_52pojie_cookie');
assert_true(function_exists('hot_52pojie_save_credentials'), 'hot_52pojie_save_credentials exists');

$apiSrc = (string) file_get_contents($root . '/api/send_form_code.php');
assert_true(strpos($apiSrc, 'http_response_code(405)') !== false, 'api 405 on wrong method');
assert_true(strpos($apiSrc, 'http_response_code(403)') !== false, 'api 403 on csrf fail');
assert_true(strpos($apiSrc, '429') !== false && strpos($apiSrc, 'rate_limited') !== false, 'api 429 on rate limit');

$mailSrc = (string) file_get_contents($root . '/includes/mailer.php');
assert_true(strpos($mailSrc, '{$data[\'name\']}') === false, 'mailer text body uses null-safe access');
assert_true(strpos($mailSrc, "'rate_limited' => true") !== false, 'mailer flags rate limit');

$instSrc = (string) file_get_contents($root . '/includes/install.php');
assert_true(substr_count($instSrc, '@unlink(DB_CONFIG_FILE)') >= 3, 'install rolls back db config on failure');

$vueSrc = (string) file_get_contents($root . '/admin/assets/admin-vue.js');
assert_true(strpos($vueSrc, 'bootEl.content') !== false, 'admin-vue reads template content');

$scSrc = (string) file_get_contents($root . '/admin/shortcuts.php');
assert_true(strpos($scSrc, '未知操作或目标不存在') !== false, 'shortcuts rejects unknown action');

echo $fail === 0 ? "\nALL_CHECKS_PASSED\n" : "\nFAILED=$fail\n";
exit($fail === 0 ? 0 : 1);
