<?php
$h = file_get_contents(__DIR__ . '/../data/cache/_ref_cccyun.html');
foreach (['实用工具', '友情链接', 'site-card-sm', 'nav-category'] as $kw) {
    $pos = mb_strpos($h, $kw);
    echo "$kw pos=" . var_export($pos, true) . "\n";
}
$pos = strpos($h, '实用');
if ($pos !== false) {
    echo substr($h, max(0, $pos - 100), 3000) . "\n";
}
