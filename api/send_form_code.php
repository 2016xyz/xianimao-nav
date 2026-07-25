<?php
/**
 * 前台：发送申请/留言邮箱验证码（JSON）
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/mailer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => '请使用 POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = (string) ($_POST['csrf_token'] ?? ($_POST['_csrf'] ?? ''));
if (!csrf_verify($token)) {
    echo json_encode(['ok' => false, 'message' => '安全校验失败，请刷新页面'], JSON_UNESCAPED_UNICODE);
    exit;
}

$scope = security_enum((string) ($_POST['scope'] ?? 'message'), ['apply', 'message']);
if ($scope === null) {
    $scope = 'message';
}

if ($scope === 'message') {
    try {
        $data = load_site_data();
        $em = $data['site']['enable_message'] ?? '1';
        if ($em === '0' || $em === 0 || $em === false) {
            echo json_encode(['ok' => false, 'message' => '留言功能已关闭'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => '暂时无法发送验证码，请稍后再试'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$email = security_email($_POST['email'] ?? '');
if ($email === null) {
    echo json_encode(['ok' => false, 'message' => '请填写有效邮箱'], JSON_UNESCAPED_UNICODE);
    exit;
}
$result = mailer_send_form_code($email, $scope);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
exit;
