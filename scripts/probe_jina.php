<?php
$ch = curl_init('https://r.jina.ai/https://linux.do/latest.json');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT => 'Mozilla/5.0',
    CURLOPT_HTTPHEADER => ['Accept: application/json,text/plain,*/*'],
]);
$b = curl_exec($ch);
curl_close($ch);
file_put_contents('g:/导航网/data/cache/_jina_linux.txt', $b);
echo "len=" . strlen($b) . "\n";
echo "head=" . substr($b, 0, 300) . "\n";
// try extract json
if (preg_match('/\{[\s\S]*"topic_list"[\s\S]*\}/u', $b, $m)) {
    $j = json_decode($m[0], true);
    echo "json topics=" . (isset($j['topic_list']['topics']) ? count($j['topic_list']['topics']) : 0) . "\n";
    if (!empty($j['topic_list']['topics'][0]['title'])) {
        echo "t0=" . $j['topic_list']['topics'][0]['title'] . "\n";
    }
} else {
    // markdown links
    if (preg_match_all('/\[([^\]]{4,120})\]\((https:\/\/linux\.do\/t\/[^)\s]+)\)/u', $b, $m, PREG_SET_ORDER)) {
        echo "md links=" . count($m) . "\n";
        for ($i = 0; $i < min(5, count($m)); $i++) {
            echo "  " . $m[$i][1] . "\n";
        }
    }
}
