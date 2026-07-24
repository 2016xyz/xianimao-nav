<?php
function g($u)
{
    $ch = curl_init($u);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json,text/html,*/*',
            'Accept-Language: zh-CN,zh;q=0.9',
        ],
    ]);
    $b = curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$c, $b === false ? '' : $b];
}

$urls = [
    'https://api-hot.imsyy.top/linuxdo',
    'https://api-hot.imsyy.top/52pojie',
    'https://api-hot.imsyy.top/v2ex',
    'https://api-hot.imsyy.top/zhihu',
    'https://api.pearktrue.cn/api/dailyhot?title=LinuxDo',
    'https://api.pearktrue.cn/api/dailyhot?title=吾爱破解',
    'https://api.pearktrue.cn/api/dailyhot?title=V2EX',
    'https://moe.d5j.co/dailyhot?title=LinuxDo',
];
foreach ($urls as $u) {
    list($c, $b) = g($u);
    $jStart = strpos($b, '{');
    $j = $jStart !== false ? json_decode(substr($b, $jStart), true) : null;
    $n = 0;
    $sample = '';
    if (is_array($j)) {
        $data = $j['data'] ?? $j['list'] ?? null;
        if (is_array($data)) {
            $n = count($data);
            if ($n > 0) {
                $sample = $data[0]['title'] ?? $data[0]['name'] ?? '';
            }
        }
    }
    echo substr($u, 0, 65) . " http=$c items=$n sample=" . substr((string) $sample, 0, 40) . "\n";
}

// parse 52pojie ranklist sample
list($c, $html) = g('https://www.52pojie.cn/misc.php?mod=ranklist&type=thread&view=heats&orderby=today');
echo "52 ranklist http=$c\n";
if (preg_match_all('/<a[^>]+href="(thread-\d+-[^"]+|forum\.php\?mod=viewthread[^"]+)"[^>]*>([^<]{4,})<\/a>/i', $html, $m, PREG_SET_ORDER)) {
    echo "matches=" . count($m) . "\n";
    for ($i = 0; $i < min(5, count($m)); $i++) {
        echo "  " . trim(html_entity_decode($m[$i][2])) . "\n";
    }
} else {
    // try other pattern
    if (preg_match_all('/viewthread&tid=(\d+)[^"]*"[^>]*>([^<]{4,80})<\/a>/i', $html, $m2, PREG_SET_ORDER)) {
        echo "tid matches=" . count($m2) . "\n";
        for ($i = 0; $i < min(5, count($m2)); $i++) {
            echo "  " . trim(html_entity_decode($m2[$i][2])) . "\n";
        }
    } else {
        echo "no match, snippet:\n" . substr(preg_replace('/\s+/', ' ', $html), 2000, 500) . "\n";
    }
}
