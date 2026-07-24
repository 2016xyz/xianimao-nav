<?php
function g($u, $headers = [])
{
    $ch = curl_init($u);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => array_merge([
            'Accept: application/json,text/html,*/*',
            'Accept-Language: zh-CN,zh;q=0.9',
        ], $headers),
    ]);
    $b = curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$c, $b === false ? '' : $b];
}

$urls = [
    'https://rsshub.app/discourse/posts/linux.do',
    'https://rsshub.app/discourse/posts/meta.discourse.org',
    'https://r.jina.ai/http://linux.do/latest',
    'https://r.jina.ai/https://linux.do/latest.json',
    'https://api.allorigins.win/raw?url=' . rawurlencode('https://linux.do/latest.json'),
    'https://corsproxy.io/?' . rawurlencode('https://linux.do/latest.json'),
];
foreach ($urls as $u) {
    list($c, $b) = g($u);
    $n = 0;
    $sample = '';
    if (strpos($b, '{') !== false) {
        $j = json_decode(substr($b, strpos($b, '{')), true);
        if (isset($j['topic_list']['topics'])) {
            $n = count($j['topic_list']['topics']);
            $sample = $j['topic_list']['topics'][0]['title'] ?? '';
        } elseif (isset($j['contents'])) {
            $inner = json_decode($j['contents'], true);
            if (isset($inner['topic_list']['topics'])) {
                $n = count($inner['topic_list']['topics']);
                $sample = $inner['topic_list']['topics'][0]['title'] ?? '';
            }
        }
    } elseif (strpos($b, '<item>') !== false || strpos($b, '<entry>') !== false) {
        preg_match_all('/<title>(?:<!\[CDATA\[)?([^<\]]+)/u', $b, $m);
        $n = count($m[1] ?? []);
        $sample = $m[1][1] ?? ($m[1][0] ?? '');
    } else {
        // jina markdown-ish
        if (preg_match_all('/^\s*#{1,3}\s+(.+)$/mu', $b, $m)) {
            $n = count($m[1]);
            $sample = $m[1][0] ?? '';
        } elseif (preg_match_all('/\[([^\]]{6,80})\]\((https?:\/\/linux\.do\/t\/[^)]+)\)/u', $b, $m)) {
            $n = count($m[1]);
            $sample = $m[1][0] ?? '';
        }
    }
    echo substr($u, 0, 70) . " http=$c len=" . strlen($b) . " items=$n sample=" . substr($sample, 0, 40) . "\n";
}
