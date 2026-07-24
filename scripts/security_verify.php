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

// XSS 输出转义
assert_true(e('<script>x</script>') === '&lt;script&gt;x&lt;/script&gt;', 'e() escapes tags');

// URL 白名单
assert_true(security_url('https://ok.com/a') === 'https://ok.com/a', 'https ok');
assert_true(security_url('javascript:alert(1)') === '', 'js blocked');
assert_true(security_url('data:text/html,x') === '', 'data blocked');
assert_true(security_url('//evil.com') === '', 'protocol-relative blocked');

// HTML 消毒
$xss = security_sanitize_html('<p onclick=alert(1)>hi</p><script>x</script><a href="javascript:alert(1)">a</a>');
assert_true(
    strpos($xss, '<script') === false && stripos($xss, 'onclick') === false && stripos($xss, 'javascript:') === false,
    'sanitize html'
);

// 邮箱 / 数字码
assert_true(security_email('a@b.com') === 'a@b.com', 'email ok');
assert_true(security_email('not-mail') === null, 'email bad');
assert_true(security_digits('123456', 6) === '123456', 'digits ok');
assert_true(security_digits('12ab56', 6) === null, 'digits bad');

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
