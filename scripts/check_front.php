<?php
$h = file_get_contents(__DIR__ . '/../data/front_sample.html');
echo 'len=' . strlen($h) . PHP_EOL;
foreach (['微博热搜', '百度热点', '哔哩哔哩', '抖音热搜', '贴吧', '更新于', '左眼跳财', '树恨你', 'hot-list'] as $k) {
    echo $k . ':' . (strpos($h, $k) !== false ? 'yes' : 'no') . PHP_EOL;
}
// 缓存文件
$dir = __DIR__ . '/../data/cache';
foreach (glob($dir . '/*.json') ?: [] as $f) {
    $j = json_decode(file_get_contents($f), true);
    echo basename($f) . ' items=' . count($j['items'] ?? []) . PHP_EOL;
}
