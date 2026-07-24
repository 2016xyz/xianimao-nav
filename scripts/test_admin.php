<?php
/**
 * 简易后台接口自检（无 curl）
 */
$base = 'http://127.0.0.1:8080';
$cookieFile = tempnam(sys_get_temp_dir(), 'ck');
$cookies = [];

function parse_set_cookie($headers, &$cookies)
{
    foreach ($headers as $h) {
        if (stripos($h, 'Set-Cookie:') === 0) {
            $line = trim(substr($h, 11));
            $part = explode(';', $line)[0];
            $kv = explode('=', $part, 2);
            if (count($kv) === 2) {
                $cookies[$kv[0]] = $kv[1];
            }
        }
    }
}

function cookie_header($cookies)
{
    $parts = [];
    foreach ($cookies as $k => $v) {
        $parts[] = $k . '=' . $v;
    }
    return implode('; ', $parts);
}

function req($url, &$cookies, $post = null)
{
    $headers = ['Accept: text/html'];
    if ($cookies) {
        $headers[] = 'Cookie: ' . cookie_header($cookies);
    }
    $opts = [
        'http' => [
            'method' => $post === null ? 'GET' : 'POST',
            'header' => implode("\r\n", $headers) . ($post !== null ? "\r\nContent-Type: application/x-www-form-urlencoded" : ''),
            'content' => $post !== null ? http_build_query($post) : null,
            'ignore_errors' => true,
            'follow_location' => 0,
        ],
    ];
    $ctx = stream_context_create($opts);
    $body = file_get_contents($url, false, $ctx);
    $respHeaders = $http_response_header ?? [];
    parse_set_cookie($respHeaders, $cookies);
    $code = 0;
    if (!empty($respHeaders[0]) && preg_match('/\s(\d{3})\s/', $respHeaders[0], $m)) {
        $code = (int) $m[1];
    }
    return [$code, $respHeaders, $body === false ? '' : $body];
}

// 1. 登录页
[, , $loginHtml] = req($base . '/admin/login.php', $cookies);
if (!preg_match('/name="csrf_token" value="([^"]+)"/', $loginHtml, $m)) {
    fwrite(STDERR, "CSRF not found\n");
    exit(1);
}
echo "csrf ok\n";

// 2. 登录
[$code] = req($base . '/admin/login.php', $cookies, [
    'csrf_token' => $m[1],
    'username' => 'admin',
    'password' => 'admin123',
]);
echo "login status: $code\n";

// 3. 后台首页
[$code, , $body] = req($base . '/admin/index.php', $cookies);
echo "dashboard: $code\n";
echo (strpos($body, '概览') !== false || strpos($body, '搜索引擎') !== false) ? "dashboard content ok\n" : "dashboard content missing\n";

// 4. 前台
[$code, , $front] = req($base . '/', $cookies);
echo "front: $code\n";
echo (strpos($front, '今日热榜') !== false) ? "front hot ok\n" : "front hot missing\n";
echo (strpos($front, '自营站点') !== false) ? "front sites ok\n" : "front sites missing\n";

// 5. 保存再还原站点设置
[, , $settingsHtml] = req($base . '/admin/settings.php', $cookies);
if (!preg_match('/name="csrf_token" value="([^"]+)"/', $settingsHtml, $m2)) {
    echo "settings page may redirect, body len=" . strlen($settingsHtml) . "\n";
} else {
    [$code] = req($base . '/admin/settings.php', $cookies, [
        'csrf_token' => $m2[1],
        'name' => '夏尼猫网址导航',
        'subtitle' => '实用工具与优质站点聚合',
        'footer' => '© 夏尼猫网址导航 · 仅供学习演示',
    ]);
    echo "settings save: $code\n";
}

// 6. 内容文件可读
$json = file_get_contents(dirname(__DIR__) . '/data/content.json');
$data = json_decode($json, true);
echo is_array($data) && isset($data['engines']) ? "content.json ok engines=" . count($data['engines']) . "\n" : "content.json bad\n";

echo "all done\n";
