<?php
function fetch($url) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT => "Mozilla/5.0",
  ]);
  $html = curl_exec($ch);
  curl_close($ch);
  return $html;
}
$dir = "g:/导航网/data/cache/ref/";
if (!is_dir($dir)) mkdir($dir, 0755, true);
foreach ([
  "home.css" => "https://www.cccyun.cn/static/css/home.css",
  "home.js" => "https://www.cccyun.cn/static/js/home.js?v=1001",
] as $name => $url) {
  $c = fetch($url);
  file_put_contents($dir . $name, $c);
  echo "$name " . strlen($c) . "\n";
}
$html = file_get_contents("g:/导航网/data/cache/_ref_cccyun.html");
// strip scripts content for structure - print body without hot list items maybe
// extract key structural sections by regex
$patterns = [
  "hero" => "/class=\"hero-section[\\s\\S]*?<\\/section>/i",
  "search" => "/class=\"search-[\\s\\S]{0,3000}/i",
];
// print lines with structural markers
$keep = [];
foreach (preg_split("/\r?\n/", $html) as $i => $line) {
  if (preg_match("/hero|search-|hot-|section-|site-card|nav-category|theme-toggle|container|footer|navbar|col-|row |id=\"|class=\"section/i", $line)) {
    $keep[] = ($i+1) . ":" . substr(trim($line), 0, 200);
  }
}
file_put_contents($dir . "structure.txt", implode("\n", $keep));
echo "structure lines: " . count($keep) . "\n";
// also save truncated html without long lists - keep first 150 lines and last 80
$lines = preg_split("/\r?\n/", $html);
$out = array_merge(array_slice($lines, 0, 180), ["<!-- ... -->"], array_slice($lines, -100));
file_put_contents($dir . "html_head_tail.html", implode("\n", $out));
echo "done\n";
