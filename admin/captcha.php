<?php
/**
 * 图形验证码输出
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/captcha.php';

// IP 限流：每分钟最多 30 次，防止刷爆
$ip = function_exists('security_client_ip') ? security_client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$bucket = 'captcha_' . hash('sha256', $ip !== '' ? $ip : 'unknown');
$dir = ROOT_PATH . '/data/cache';
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
$file = $dir . '/' . $bucket . '.json';
$now = time();
$st = ['t' => $now, 'n' => 0];
if (is_file($file)) {
    $j = json_decode((string) @file_get_contents($file), true);
    if (is_array($j) && isset($j['t'], $j['n']) && ($now - (int) $j['t']) < 60) {
        $st = ['t' => (int) $j['t'], 'n' => (int) $j['n']];
    }
}
$st['n']++;
@file_put_contents($file, json_encode($st), LOCK_EX);
if ($st['n'] > 30) {
    http_response_code(429);
    header('Content-Type: text/plain; charset=utf-8');
    header('Retry-After: 60');
    echo 'Too Many Requests';
    exit;
}

// 已登录也可刷新（测试），不强制未登录
captcha_output_image(128, 46);
exit;
