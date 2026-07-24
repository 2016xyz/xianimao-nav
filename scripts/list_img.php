<?php
function fetch($url) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>0,CURLOPT_USERAGENT=>"Mozilla/5.0"]);
  $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return [$c,$b];
}
// optional icons for baidu/douyin/tieba if missing - skip if not on ref
$dir = "g:/导航网/assets/images";
// ensure douyin/tieba placeholders not required
echo "images:\n";
foreach (scandir($dir) as $f) {
  if ($f === "." || $f === "..") continue;
  echo "  $f " . filesize("$dir/$f") . "\n";
}
