<?php
/**
 * 将站名/联系邮箱同步写入 settings（已安装 DB 或 JSON 回退）
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

$keys = [
    'site_name' => '夏尼猫网址导航',
    'site_subtitle' => '实用工具与优质站点聚合',
    'site_footer' => '© ' . date('Y') . ' 夏尼猫网址导航',
    'about_html' => '<p>夏尼猫网址导航汇集实用工具、开源项目与优质站点，帮助你更快找到需要的资源。</p>',
    'contact_html' => '<p>如有合作、建议或问题，欢迎通过邮件 <a href="mailto:i@2016xlx.cn">i@2016xlx.cn</a> 或留言联系我们。</p>',
    'contact_email' => 'i@2016xlx.cn',
];

foreach ($keys as $k => $v) {
    $ok = setting_set($k, $v);
    echo $k . ' => ' . ($ok ? 'ok' : 'fail') . "\n";
}

// 同步 content.json，避免未装 DB 时读到旧缓存
$contentFile = ROOT_PATH . '/data/content.json';
if (is_file($contentFile) && is_writable($contentFile)) {
    $j = json_decode((string) file_get_contents($contentFile), true);
    if (!is_array($j)) {
        $j = [];
    }
    if (!isset($j['site']) || !is_array($j['site'])) {
        $j['site'] = [];
    }
    $j['site']['name'] = $keys['site_name'];
    $j['site']['subtitle'] = $keys['site_subtitle'];
    $j['site']['footer'] = $keys['site_footer'];
    $j['site']['about_html'] = $keys['about_html'];
    $j['site']['contact_html'] = $keys['contact_html'];
    $j['site']['contact_email'] = $keys['contact_email'];
    file_put_contents($contentFile, json_encode($j, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    echo "content.json => ok\n";
}

$d = load_site_data();
echo 'name=' . ($d['site']['name'] ?? '') . "\n";
echo 'email=' . ($d['site']['contact_email'] ?? '') . "\n";

