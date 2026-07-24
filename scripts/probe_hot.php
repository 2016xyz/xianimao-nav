<?php
function g($u)
{
    $ch = curl_init($u);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $b = curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$c, $b === false ? '' : $b];
}

$types = ['52pojie', 'pojie', '52', 'tieba', 'douyin', 'toutiao', 'thepaper', 'cls', 'xueqiu', 'linux', 'v2ex', 'hupu', 'douban', 'ithome', 'sspai', 'weibo', 'baidu', 'zhihu', 'biliall'];
foreach ($types as $t) {
    list($c, $b) = g('https://api.ikunpay.com/api/jhrs?type=' . $t);
    $jsonStart = strpos($b, '{');
    if ($jsonStart !== false && $jsonStart > 0) {
        $b = substr($b, $jsonStart);
    }
    $j = json_decode($b, true);
    $n = (is_array($j) && !empty($j['data']) && is_array($j['data'])) ? count($j['data']) : 0;
    $title = '';
    if ($n > 0 && is_array($j['data'][0])) {
        $title = $j['data'][0]['title'] ?? $j['data'][0]['name'] ?? '';
    }
    echo "$t http=$c items=$n sample=" . substr((string) $title, 0, 40) . "\n";
}

echo "--- discourse ---\n";
foreach (['https://linux.do/hot.json', 'https://linux.do/top.json', 'https://linux.do/latest.json', 'https://linux.do/top/weekly.json', 'https://v2ex.com/api/topics/hot.json'] as $u) {
    list($c, $b) = g($u);
    $j = json_decode($b, true);
    if (isset($j['topic_list']['topics']) && is_array($j['topic_list']['topics'])) {
        $n = count($j['topic_list']['topics']);
        $t0 = $j['topic_list']['topics'][0]['title'] ?? '';
        echo basename(parse_url($u, PHP_URL_PATH)) . " http=$c topics=$n sample=" . substr((string) $t0, 0, 40) . "\n";
    } elseif (is_array($j) && isset($j[0]['title'])) {
        echo basename(parse_url($u, PHP_URL_PATH)) . " http=$c items=" . count($j) . " sample=" . substr($j[0]['title'], 0, 40) . "\n";
    } else {
        echo basename(parse_url($u, PHP_URL_PATH)) . " http=$c len=" . strlen($b) . " head=" . substr(preg_replace('/\s+/', ' ', $b), 0, 80) . "\n";
    }
}
