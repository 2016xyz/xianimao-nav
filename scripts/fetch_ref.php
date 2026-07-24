<?php
$ch = curl_init("https://www.cccyun.cn/");
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_TIMEOUT => 20,
  CURLOPT_SSL_VERIFYPEER => false,
  CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
]);
$html = curl_exec($ch);
$err = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($html === false) { fwrite(STDERR, $err); exit(1); }
file_put_contents("g:/导航网/data/cache/_ref_cccyun.html", $html);
echo "HTTP $code len=" . strlen($html) . "\n";
// extract css links and style blocks
if (preg_match_all("/href=[\"']([^\"']+\.css[^\"']*)[\"']/i", $html, $m)) {
  echo "CSS:\n";
  foreach (array_unique($m[1]) as $u) echo "  $u\n";
}
if (preg_match_all("/src=[\"']([^\"']+\.js[^\"']*)[\"']/i", $html, $m)) {
  echo "JS:\n";
  foreach (array_unique($m[1]) as $u) echo "  $u\n";
}
// print first 200 lines of structure-ish tags
$lines = preg_split("/\r?\n/", $html);
echo "LINES=" . count($lines) . "\n";
// class names sample
if (preg_match_all("/class=[\"']([^\"']+)[\"']/", $html, $m)) {
  $classes = [];
  foreach ($m[1] as $c) {
    foreach (preg_split("/\s+/", $c) as $p) {
      if ($p !== "") $classes[$p] = ($classes[$p] ?? 0) + 1;
    }
  }
  arsort($classes);
  echo "TOP CLASSES:\n";
  $i=0; foreach ($classes as $k=>$v) { echo "  $k ($v)\n"; if (++$i>=40) break; }
}
