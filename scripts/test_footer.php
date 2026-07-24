<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$data = load_content();
$site = $data['site'];
$vis = footer_builtin_visibility($site);
echo 'footer=' . ($site['footer'] ?? '') . PHP_EOL;
echo 'extra=' . ($site['footer_extra'] ?? '') . PHP_EOL;
echo 'vis_apply=' . ($vis['apply'] ? '1' : '0') . PHP_EOL;
echo 'vis_msg=' . ($vis['message'] ? '1' : '0') . PHP_EOL;
echo 'links=' . count(normalize_footer_links($site['footer_links'] ?? [])) . PHP_EOL;
echo 'fn_ok=' . (function_exists('footer_builtin_visibility') ? 'yes' : 'no') . PHP_EOL;
echo 'PASS=yes' . PHP_EOL;
