<?php
/**
 * 验证实用工具 / 友情链接 / 页脚页面
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

chdir(dirname(__DIR__));
require_once __DIR__ . '/../includes/bootstrap.php';

$content = load_content();
$site = $content['site'] ?? [];
$tools = $content['tools'] ?? [];
$links = $content['links'] ?? [];

echo "site.name=" . ($site['name'] ?? '') . "\n";
echo "show_friend_links=" . ($site['show_friend_links'] ?? 'n/a') . "\n";
echo "enable_message=" . ($site['enable_message'] ?? 'n/a') . "\n";
echo "tools_count=" . count($tools) . "\n";
echo "links_count=" . count($links) . "\n";
if ($tools) {
    echo "first_tool=" . ($tools[0]['name'] ?? '') . "\n";
}
if ($links) {
    echo "first_link=" . ($links[0]['name'] ?? '') . "\n";
}

// 渲染首页片段（不拉热榜网络）
ob_start();
$_SERVER['REQUEST_METHOD'] = 'GET';
// 仅检查 load 路径
$data = $content;
$data['hot_boards'] = [];
$toolsOk = !empty($data['tools']);
$linksOk = !empty($data['links']);
$flagsOk = isset($site['show_friend_links'], $site['enable_message']);
$files = [
    'apply.php', 'message.php', 'about.php', 'contact.php',
    'admin/tools.php', 'admin/links.php', 'admin/messages.php',
];
$missing = [];
foreach ($files as $f) {
    if (!is_file(ROOT_PATH . '/' . $f)) {
        $missing[] = $f;
    }
}

echo "tools_ok=" . ($toolsOk ? 'yes' : 'no') . "\n";
echo "links_ok=" . ($linksOk ? 'yes' : 'no') . "\n";
echo "flags_ok=" . ($flagsOk ? 'yes' : 'no') . "\n";
echo "missing_files=" . (empty($missing) ? 'none' : implode(',', $missing)) . "\n";
echo "PASS=" . ($toolsOk && $linksOk && $flagsOk && empty($missing) ? 'yes' : 'no') . "\n";
