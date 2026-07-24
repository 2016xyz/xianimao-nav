<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/hot_fetcher.php';

echo "enabled: " . implode(',', hot_board_enabled_ids()) . "\n";
foreach (hot_board_sources() as $src) {
    $board = hot_fetch_source($src);
    $n = count($board['items'] ?? []);
    $sample = $n > 0 ? ($board['items'][0]['title'] ?? '') : '';
    echo $board['id'] . " items=$n cache=" . (!empty($board['from_cache']) ? '1' : '0') . " err=" . (!empty($board['error']) ? '1' : '0') . " sample=" . substr($sample, 0, 40) . "\n";
}
