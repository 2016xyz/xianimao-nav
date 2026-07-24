<?php
/**
 * 系统更新模块自检（CLI，不执行覆盖）
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

require dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
function assert_true($c, $m)
{
    global $fail;
    if ($c) {
        echo "PASS $m\n";
    } else {
        echo "FAIL $m\n";
        $fail++;
    }
}

assert_true(function_exists('updater_local_version'), 'updater_local_version');
assert_true(function_exists('updater_fetch_remote'), 'updater_fetch_remote');
assert_true(function_exists('updater_apply'), 'updater_apply');

$local = updater_local_version();
assert_true(!empty($local['version']), 'local version=' . ($local['version'] ?? ''));
assert_true(!empty($local['repo']), 'local repo=' . ($local['repo'] ?? ''));

assert_true(updater_is_protected_path('config/database.php'), 'protect database.php');
assert_true(updater_is_protected_path('data/cache/foo.json'), 'protect cache');
assert_true(updater_is_protected_path('assets/images/uploads/a.png'), 'protect uploads');
assert_true(!updater_is_protected_path('includes/bootstrap.php'), 'allow bootstrap');
assert_true(updater_is_allowed_package_path('includes/updater.php'), 'allow updater.php');
assert_true(!updater_is_allowed_package_path('config/database.php'), 'deny package database.php');
assert_true(!updater_is_allowed_package_path('../evil.php'), 'deny traversal');

$env = updater_env_check();
assert_true(isset($env['ok']), 'env check structure');
foreach ($env['items'] as $it) {
    echo '  env: ' . $it['name'] . ' => ' . (!empty($it['ok']) ? 'ok' : 'no') . ' (' . $it['detail'] . ")\n";
}

// 网络检测（可能因网络失败，不计入硬失败）
$remote = updater_fetch_remote(true);
if (!empty($remote['ok'])) {
    echo 'PASS remote fetch version=' . ($remote['version'] ?? '') . ' commit=' . ($remote['commit'] ?? '') . "\n";
    echo '  update_available=' . (!empty($remote['update_available']) ? 'yes' : 'no') . "\n";
} else {
    echo 'WARN remote fetch failed: ' . ($remote['message'] ?? '') . " (网络环境可忽略)\n";
}

assert_true(updater_version_compare('1.2.0', '1.1.0') > 0, 'version compare');
assert_true(updater_version_compare('1.1.0', '1.1.0') === 0, 'version equal');

echo $fail === 0 ? "ALL PASS\n" : "FAILED $fail\n";
exit($fail === 0 ? 0 : 1);
