<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/hot_fetcher.php';

// force refetch
@unlink(ROOT_PATH . '/data/cache/52pojie.json');
$src = hot_board_catalog()['52pojie'];
list($items, $t) = hot_fetch_discuz($src);
echo "count=" . count($items) . " time=$t\n";
foreach (array_slice($items, 0, 12) as $it) {
    echo $it['rank'] . '. ' . $it['title'] . ' | ' . $it['url'] . "\n";
}
