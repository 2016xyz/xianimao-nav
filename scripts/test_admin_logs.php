<?php
/**
 * 后台日志功能自检（CLI）
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

assert_true(function_exists('admin_log_write'), 'admin_log_write exists');
assert_true(function_exists('admin_log_list'), 'admin_log_list exists');
assert_true(function_exists('admin_log_clear'), 'admin_log_clear exists');

$ok = admin_log_write('test_write', '后台日志功能自检', [
    'module' => 'system',
    'level' => 'info',
    'username' => 'cli',
    'admin_id' => 0,
    'detail' => ['password' => 'secret', 'foo' => 'bar'],
]);
assert_true($ok, 'admin_log_write returns true');

$list = admin_log_list(['q' => '后台日志功能自检'], 1, 5);
assert_true($list['total'] >= 1, 'list finds test log total=' . $list['total']);
assert_true(!empty($list['items'][0]), 'list has items');

if (!empty($list['items'][0])) {
    $d = (string) ($list['items'][0]['detail'] ?? '');
    assert_true(strpos($d, 'secret') === false, 'password redacted (no secret)');
    assert_true(strpos($d, '[redacted]') !== false, 'password redacted marker present');
    assert_true(strpos($d, 'bar') !== false, 'non-secret detail kept');
}

echo $fail === 0 ? "ALL PASS\n" : "FAILED $fail\n";
exit($fail === 0 ? 0 : 1);
