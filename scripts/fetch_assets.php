<?php
function fetch($url) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 40,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT => "Mozilla/5.0",
  ]);
  $bin = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, $bin];
}
$base = "g:/导航网/assets";
$dirs = ["$base/css", "$base/js", "$base/images", "$base/vendor"];
foreach ($dirs as $d) if (!is_dir($d)) mkdir($d, 0755, true);

$map = [
  "$base/css/bootstrap.min.css" => "https://www.cccyun.cn/static/css/bootstrap-5.3.8.min.css",
  "$base/css/bootstrap-icons.min.css" => "https://www.cccyun.cn/static/css/bootstrap-icons-1.13.1.min.css",
  "$base/js/jquery.min.js" => "https://www.cccyun.cn/static/js/jquery-3.7.1.min.js",
  "$base/js/bootstrap.bundle.min.js" => "https://www.cccyun.cn/static/js/bootstrap.bundle-5.3.8.min.js",
  "$base/images/background.avif" => "https://www.cccyun.cn/static/images/background.avif",
  "$base/images/baidu.png" => "https://www.cccyun.cn/static/images/baidu.png",
  "$base/images/google.png" => "https://www.cccyun.cn/static/images/google.png",
  "$base/images/sogou.png" => "https://www.cccyun.cn/static/images/sogou.png",
  "$base/images/zhihu.png" => "https://www.cccyun.cn/static/images/zhihu.png",
  "$base/images/github.png" => "https://www.cccyun.cn/static/images/github.png",
  "$base/images/wyy.png" => "https://www.cccyun.cn/static/images/wyy.png",
  "$base/images/douban.png" => "https://www.cccyun.cn/static/images/douban.png",
  "$base/images/bilibili.png" => "https://www.cccyun.cn/static/images/bilibili.png",
  "$base/images/weibo.png" => "https://www.cccyun.cn/static/images/weibo.png",
  "$base/images/52pojie.png" => "https://www.cccyun.cn/static/images/52pojie.png",
];
foreach ($map as $path => $url) {
  [$code, $bin] = fetch($url);
  if ($code === 200 && $bin !== false && strlen($bin) > 100) {
    file_put_contents($path, $bin);
    echo "OK " . basename($path) . " " . strlen($bin) . "\n";
  } else {
    echo "FAIL $code " . basename($path) . "\n";
  }
}
// bootstrap icons fonts - parse css for url
$css = file_get_contents("$base/css/bootstrap-icons.min.css");
if (preg_match_all("/url\\(([^)]+)\\)/", $css, $m)) {
  $fonts = array_unique($m[1]);
  echo "font refs: " . count($fonts) . "\n";
  foreach (array_slice($fonts, 0, 5) as $f) echo "  $f\n";
}
// footer from html
$html = file_get_contents("g:/导航网/data/cache/_ref_cccyun.html");
if (preg_match("/<footer[\\s\\S]*?<\\/footer>/i", $html, $fm)) {
  file_put_contents("g:/导航网/data/cache/ref/footer.html", $fm[0]);
  echo "footer " . strlen($fm[0]) . "\n";
}
