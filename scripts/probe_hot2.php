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
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
    ]);
    $b = curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$c, $b === false ? '' : $b];
}

$urls = [
    'https://www.52pojie.cn/forum.php?mod=guide&view=hot',
    'https://www.52pojie.cn/misc.php?mod=ranklist&type=thread&view=heats&orderby=today',
    'https://rsshub.app/52pojie/digest',
    'https://api.vvhan.com/api/hotlist/wbHot',
    'https://api.vvhan.com/api/hotlist/zhihuHot',
    'https://api.vvhan.com/api/hotlist?type=baidu',
    'https://api.pearktrue.cn/api/dailyhot/?title=吾爱破解',
    'https://api.pearktrue.cn/api/dailyhot/?title=V2EX',
];
foreach ($urls as $u) {
    list($c, $b) = g($u);
    echo substr($u, 0, 70) . " http=$c len=" . strlen($b) . ' head=' . substr(preg_replace('/\s+/', ' ', $b), 0, 100) . "\n";
}
