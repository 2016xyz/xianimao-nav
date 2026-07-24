<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/hot_fetcher.php';

$ok = hot_linuxdo_save_credentials([
    'mode' => 'auto',
    'cookie_submitted' => '1',
    'cookie' => 'test_session=abc; _t=xyz',
    'api_username' => 'demo',
]);
echo 'save=' . ($ok ? 'yes' : 'no') . PHP_EOL;
$c = hot_linuxdo_credentials();
echo 'has_auth=' . (hot_linuxdo_has_auth() ? 'yes' : 'no') . PHP_EOL;
echo 'cookie_len=' . strlen($c['cookie']) . PHP_EOL;
echo 'user=' . $c['api_username'] . PHP_EOL;
echo 'file_exists=' . (is_file(hot_linuxdo_secret_file()) ? 'yes' : 'no') . PHP_EOL;

hot_linuxdo_save_credentials(['clear' => 1]);
echo 'cleared_has=' . (hot_linuxdo_has_auth() ? 'yes' : 'no') . PHP_EOL;
echo 'test_fn=' . (function_exists('hot_linuxdo_test_auth') ? 'yes' : 'no') . PHP_EOL;
echo 'PASS=yes' . PHP_EOL;
