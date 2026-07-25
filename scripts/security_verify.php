<?php
/**
 * 全站安全防护验证（CLI）
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
require $root . '/includes/bootstrap.php';

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

// 模块存在
assert_true(function_exists('security_send_headers'), 'security_send_headers');
assert_true(function_exists('security_url'), 'security_url');
assert_true(function_exists('security_email'), 'security_email');
assert_true(function_exists('security_sanitize_html'), 'security_sanitize_html');
assert_true(function_exists('security_validate_message_row'), 'security_validate_message_row');
assert_true(function_exists('security_db_query'), 'security_db_query');
assert_true(function_exists('security_outbound_url_allowed'), 'security_outbound_url_allowed');

// XSS 输出转义
assert_true(e('<script>x</script>') === '&lt;script&gt;x&lt;/script&gt;', 'e() escapes tags');

// URL 白名单
assert_true(security_url('https://ok.com/a') === 'https://ok.com/a', 'https ok');
assert_true(security_url('javascript:alert(1)') === '', 'js blocked');
assert_true(security_url('data:text/html,x') === '', 'data blocked');
assert_true(security_url('//evil.com') === '', 'protocol-relative blocked');
assert_true(function_exists('security_href') && security_href('javascript:alert(1)') === '#', 'href fallback');
assert_true(function_exists('security_search_url') && security_search_url('https://www.baidu.com/s?wd={q}') !== '', 'search url placeholder');
assert_true(function_exists('security_search_url') && security_search_url('javascript:x') === '', 'search url blocks js');
assert_true(function_exists('security_safe_redirect_target') && security_safe_redirect_target('//evil.com') === 'index.php', 'redirect blocks //');
assert_true(function_exists('security_safe_redirect_target') && security_safe_redirect_target('admin/index.php') === 'admin/index.php', 'redirect allows relative');
assert_true(security_url('/assets/../config/database.php') === '', 'relative traversal blocked');
assert_true(!security_outbound_url_allowed('http://127.0.0.1/admin', ['http', 'https']), 'outbound private ip blocked');
assert_true(function_exists('security_sanitize_site_content'), 'sanitize site content exists');
$dirty = security_sanitize_site_content([
    'sites' => [
        ['name' => 'bad', 'url' => 'javascript:alert(1)'],
        ['name' => 'ok', 'url' => 'https://example.com'],
    ],
]);
assert_true(count($dirty['sites']) === 1 && $dirty['sites'][0]['url'] === 'https://example.com', 'sanitize drops js site url');

// HTML 消毒
$xss = security_sanitize_html('<p onclick=alert(1)>hi</p><script>x</script><a href="javascript:alert(1)">a</a>');
assert_true(
    strpos($xss, '<script') === false && stripos($xss, 'onclick') === false && stripos($xss, 'javascript:') === false,
    'sanitize html'
);
$head = security_sanitize_head_html('<meta name="x" content="ok"><script>alert(1)</script><link rel="canonical" href="javascript:alert(1)"><iframe src="https://example.com"></iframe>');
assert_true(stripos($head, '<script') === false && stripos($head, '<iframe') === false && stripos($head, 'javascript:') === false, 'sanitize head html');
assert_true(asset_url('assets/js/main.js') !== '' && strpos(asset_url('assets/js/main.js'), '?v=') !== false, 'asset url versioned');

// 邮箱 / 数字码
assert_true(security_email('a@b.com') === 'a@b.com', 'email ok');
assert_true(security_email('not-mail') === null, 'email bad');
assert_true(security_digits('123456', 6) === '123456', 'digits ok');
assert_true(security_digits('12ab56', 6) === null, 'digits bad');
assert_true(strpos(file_get_contents($root . '/includes/install.php'), "root' && \$db['password'] === ''") !== false, 'install rejects root empty password');

// 留言校验
$v = security_validate_message_row([
    'type' => 'message',
    'name' => '测试',
    'email' => 'user@example.com',
    'content' => '你好世界留言内容',
]);
assert_true(!empty($v['ok']), 'message row ok');
$v2 = security_validate_message_row([
    'type' => 'apply',
    'name' => '站',
    'email' => 'user@example.com',
    'website' => 'javascript:alert(1)',
]);
assert_true(empty($v2['ok']), 'apply rejects js url');

// 无内联 script 于业务 PHP
$phpFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$inlineHits = [];
foreach ($phpFiles as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $path = $f->getPathname();
    if (strpos($path, DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR) !== false) {
        continue;
    }
    if (strpos($path, DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR) !== false) {
        continue;
    }
    $c = file_get_contents($path);
    // 业务页不应出现可执行内联 <script>（允许 src= 外链；允许 type=application/json 数据岛）
    if (preg_match('/<script(?![^>]*(?:\bsrc=|type\s*=\s*[\'"]application\/(?:ld\+)?json[\'"]))[^>]*>/i', $c)) {
        $inlineHits[] = $path;
    }
}
assert_true(count($inlineHits) === 0, 'no inline <script> in app php: ' . implode(', ', $inlineHits));

// CSP / cookie 代码存在
$sec = file_get_contents($root . '/includes/security.php');
assert_true(strpos($sec, 'Content-Security-Policy') !== false, 'CSP header defined');
assert_true(strpos($sec, "script-src 'self'") !== false, "CSP script-src 'self'");
assert_true(strpos($sec, 'httponly') !== false || strpos($sec, 'HttpOnly') !== false, 'HttpOnly cookie');
assert_true(strpos($sec, 'samesite') !== false || strpos($sec, 'SameSite') !== false, 'SameSite cookie');

// PDO emulate prepares off
$db = file_get_contents($root . '/includes/db.php');
assert_true(strpos($db, 'ATTR_EMULATE_PREPARES => false') !== false, 'real prepared statements');

echo $fail === 0 ? "\nALL_SECURITY_CHECKS_PASSED\n" : "\nFAILED=$fail\n";
exit($fail === 0 ? 0 : 1);
