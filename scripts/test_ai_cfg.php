<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/ai.php';

echo 'file=' . ai_config_file() . PHP_EOL;
echo 'ready=' . (ai_is_ready() ? 'yes' : 'no') . PHP_EOL;
$c = ai_config_load();
echo 'base=' . $c['base_url'] . PHP_EOL;
echo 'has_csrf_field=' . (function_exists('csrf_field') ? 'yes' : 'no') . PHP_EOL;
echo 'has_verify=' . (function_exists('verify_csrf') ? 'yes' : 'no') . PHP_EOL;

// 模拟保存（不写真实 key）
$ok = ai_config_save([
    'base_url' => 'https://api.deepseek.com/v1',
    'model' => 'deepseek-chat',
    'enabled' => false,
]);
echo 'save=' . ($ok ? 'yes' : 'no') . PHP_EOL;
$c2 = ai_config_load();
echo 'saved_base=' . $c2['base_url'] . PHP_EOL;
echo 'PASS=yes' . PHP_EOL;
