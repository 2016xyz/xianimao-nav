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

echo $fail === 0 ? "\nALL_CHECKS_PASSED\n" : "\nFAILED=$fail\n";
exit($fail === 0 ? 0 : 1);
