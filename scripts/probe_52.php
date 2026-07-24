<?php
$ch = curl_init('https://www.52pojie.cn/misc.php?mod=ranklist&type=thread&view=heats&orderby=today');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
]);
$html = curl_exec($ch);
curl_close($ch);
if ($html === false) {
    die("fail\n");
}
// detect/convert encoding
if (function_exists('mb_convert_encoding')) {
    $html = mb_convert_encoding($html, 'UTF-8', 'GBK,GB2312,UTF-8');
} else {
    $html = iconv('GBK', 'UTF-8//IGNORE', $html);
}
file_put_contents('g:/导航网/data/cache/_52.html', $html);
// common discuz ranklist patterns
$patterns = [
    '/<a href="(thread-\d+-[^"]+\.html)"[^>]*>([^<]+)<\/a>/u',
    '/<a href="(forum\.php\?mod=viewthread&amp;tid=\d+[^"]*)"[^>]*>([^<]+)<\/a>/u',
    '/href="([^"]*tid=\d+[^"]*)"[^>]*>([^<]{6,100})<\/a>/u',
];
foreach ($patterns as $i => $p) {
    if (preg_match_all($p, $html, $m, PREG_SET_ORDER)) {
        echo "pattern $i count=" . count($m) . "\n";
        for ($j = 0; $j < min(8, count($m)); $j++) {
            echo '  ' . html_entity_decode(trim($m[$j][2]), ENT_QUOTES, 'UTF-8') . ' | ' . html_entity_decode($m[$j][1], ENT_QUOTES, 'UTF-8') . "\n";
        }
    } else {
        echo "pattern $i no\n";
    }
}
