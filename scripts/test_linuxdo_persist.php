<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/hot_fetcher.php';

// 保存
$ok = hot_linuxdo_save_credentials([
    'mode' => 'auto',
    'cookie' => 'persist_test=_t_value; _forum_session=abc123',
    'api_key' => 'test-api-key-persist',
    'api_username' => 'persist_user',
]);
echo 'save=' . ($ok ? 'yes' : 'no') . PHP_EOL;

// 模拟“重新加载”
$c1 = hot_linuxdo_credentials();
echo 'cookie1_len=' . strlen($c1['cookie']) . PHP_EOL;
echo 'key1_len=' . strlen($c1['api_key']) . PHP_EOL;
echo 'user1=' . $c1['api_username'] . PHP_EOL;

// 文件是否存在
echo 'main=' . (is_file(hot_linuxdo_secret_file()) ? 'yes' : 'no') . PHP_EOL;
echo 'bak=' . (is_file(hot_linuxdo_secret_backup_file()) ? 'yes' : 'no') . PHP_EOL;

// 模拟主文件丢失 → 应从备份/DB 恢复
@unlink(hot_linuxdo_secret_file());
$c2 = hot_linuxdo_credentials();
echo 'recover_cookie=' . (strpos($c2['cookie'], 'persist_test') !== false ? 'yes' : 'no') . PHP_EOL;
echo 'main_restored=' . (is_file(hot_linuxdo_secret_file()) ? 'yes' : 'no') . PHP_EOL;

// 空提交应保留
hot_linuxdo_save_credentials([
    'mode' => 'auto',
    'cookie' => '',
    'api_key' => '',
    'api_username' => 'persist_user',
]);
$c3 = hot_linuxdo_credentials();
echo 'keep_after_empty=' . (strpos($c3['cookie'], 'persist_test') !== false ? 'yes' : 'no') . PHP_EOL;

// 清理测试数据
hot_linuxdo_save_credentials(['clear' => 1]);
$c4 = hot_linuxdo_credentials();
echo 'cleared=' . (($c4['cookie'] === '' && $c4['api_key'] === '') ? 'yes' : 'no') . PHP_EOL;
echo 'PASS=yes' . PHP_EOL;
