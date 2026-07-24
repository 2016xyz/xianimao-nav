<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$boards = load_hot_boards();
echo 'boards=' . count($boards) . PHP_EOL;
foreach ($boards as $b) {
    $n = count($b['items'] ?? []);
    $t = $b['update_time'] ?? '';
    $cache = !empty($b['from_cache']) ? 'cache' : 'live';
    echo ($b['id'] ?? '?') . "\t$n\t$cache\t$t" . PHP_EOL;
    if ($n > 0) {
        echo '  #1 ' . ($b['items'][0]['title'] ?? '') . ' | ' . ($b['items'][0]['heat'] ?? '') . PHP_EOL;
    }
}
