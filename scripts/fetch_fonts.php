<?php
function fetch($url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
    ]);
    $bin = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $bin];
}

$dir = 'g:/导航网/assets/css/fonts';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$map = [
    'bootstrap-icons.woff2' => 'https://www.cccyun.cn/static/css/fonts/bootstrap-icons.woff2?e34853135f9e39acf64315236852cd5a',
    'bootstrap-icons.woff' => 'https://www.cccyun.cn/static/css/fonts/bootstrap-icons.woff?e34853135f9e39acf64315236852cd5a',
];

foreach ($map as $name => $url) {
    [$code, $bin] = fetch($url);
    if ($code === 200 && $bin && strlen($bin) > 1000) {
        file_put_contents($dir . '/' . $name, $bin);
        echo "OK $name " . strlen($bin) . "\n";
    } else {
        echo "FAIL $name $code\n";
    }
}

// 修正 bootstrap-icons css 中字体路径（去掉 query）
$cssPath = 'g:/导航网/assets/css/bootstrap-icons.min.css';
$css = file_get_contents($cssPath);
$css = preg_replace(
    '/url\("fonts\/bootstrap-icons\.woff2\?[^"]+"\)/',
    'url("fonts/bootstrap-icons.woff2")',
    $css
);
$css = preg_replace(
    '/url\("fonts\/bootstrap-icons\.woff\?[^"]+"\)/',
    'url("fonts/bootstrap-icons.woff")',
    $css
);
file_put_contents($cssPath, $css);
echo "css fonts path fixed\n";
