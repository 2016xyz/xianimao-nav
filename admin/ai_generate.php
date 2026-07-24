<?php
/**
 * AJAX：根据站点名称/URL 生成介绍
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_once ROOT_PATH . '/includes/ai.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => '仅支持 POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!verify_csrf()) {
    echo json_encode(['ok' => false, 'message' => '安全校验失败，请刷新页面'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!ai_is_ready()) {
    echo json_encode(['ok' => false, 'message' => '请先在「AI 配置」中启用并填写 URL、Key、模型'], JSON_UNESCAPED_UNICODE);
    exit;
}

$name = security_clean_text($_POST['name'] ?? '', 120);
$urlRaw = trim((string) ($_POST['url'] ?? ''));
$url = $urlRaw !== '' ? security_url($urlRaw, false) : '';
if ($urlRaw !== '' && $url === '') {
    echo json_encode(['ok' => false, 'message' => '链接无效，仅允许 http(s)'], JSON_UNESCAPED_UNICODE);
    exit;
}
$hint = security_clean_text($_POST['hint'] ?? '', 200);
if ($name === '' && $url === '') {
    echo json_encode(['ok' => false, 'message' => '请提供站点名称或链接'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = ai_generate_site_desc($name, $url, $hint);
echo json_encode([
    'ok' => !empty($result['ok']),
    'text' => isset($result['text']) ? security_clean_text($result['text'], 500) : '',
    'message' => security_clean_text($result['message'] ?? '', 300),
], JSON_UNESCAPED_UNICODE);
