<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/page_layout.php';
require_once __DIR__ . '/includes/mailer.php';

if (!function_exists('str_len_u')) {
    function str_len_u($str)
    {
        return function_exists('mb_strlen') ? mb_strlen((string) $str, 'UTF-8') : strlen((string) $str);
    }
}

$data = load_site_data();
$GLOBALS['data'] = $data;
$site = $data['site'] ?? [];
$enableMessage = !isset($site['enable_message']) || ($site['enable_message'] !== '0' && $site['enable_message'] !== 0 && $site['enable_message'] !== false);

$ok = false;
$error = '';
$smtpReady = smtp_is_ready();

if (!$enableMessage) {
    page_layout_start('在线留言', [
        'page_key' => 'message',
        'icon' => 'bi-chat-left-text',
        'tone' => 'violet',
        'lead' => '当前留言功能暂未开放。',
    ]);
    ?>
    <div class="subpage-empty">
        <div class="subpage-empty-icon"><i class="bi bi-pause-circle"></i></div>
        <h2>留言已关闭</h2>
        <p>管理员暂时关闭了在线留言。您仍可通过以下方式联系我们。</p>
        <div class="subpage-success-actions">
            <a class="btn-primary-lg" href="contact.php"><i class="bi bi-envelope"></i> 联系我们</a>
            <a class="btn-ghost-lg" href="index.php">返回首页</a>
        </div>
    </div>
    <?php
    page_layout_end();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = '安全校验失败，请刷新后重试';
    } else {
        $name = security_clean_text($_POST['name'] ?? '', 80);
        $contact = security_clean_text($_POST['contact'] ?? '', 120);
        $email = security_email($_POST['email'] ?? '');
        $emailCode = security_digits($_POST['email_code'] ?? '', 6);
        $content = security_clean_text($_POST['content'] ?? '', 2000);
        if ($content === '' || security_strlen($content) < 4) {
            $error = '留言内容过短';
        } elseif ($email === null) {
            $error = '请填写有效的邮箱';
        } elseif ($emailCode === null) {
            $error = '请输入 6 位数字邮箱验证码';
        } else {
            $vr = mailer_require_form_email_verified($email, $emailCode, 'message');
            if (empty($vr['ok'])) {
                $error = $vr['message'] ?? '邮箱验证失败';
            } else {
                $payload = [
                    'type' => 'message',
                    'name' => $name !== '' ? $name : '匿名',
                    'contact' => $contact,
                    'email' => $email,
                    'content' => $content,
                    'ip' => security_ip(),
                ];
                $saved = save_message($payload);
                if ($saved) {
                    mailer_clear_form_code('message');
                    mailer_notify_admin_submission('message', $payload);
                    $ok = true;
                } else {
                    $error = '提交失败，请稍后重试（验证码仍有效，可直接再提交）';
                }
            }
        }
    }
}

page_layout_start('在线留言', [
    'page_key' => 'message',
    'icon' => 'bi-chat-left-text',
    'tone' => 'violet',
    'lead' => '有建议、问题或合作意向，欢迎留言。提交前需验证邮箱，我们会认真阅读每一条消息。',
]);
?>
<?php if ($ok): ?>
    <div class="subpage-success">
        <div class="subpage-success-icon"><i class="bi bi-check-circle-fill"></i></div>
        <h2>留言已送达</h2>
        <p>感谢您的反馈，我们已通知管理员，会尽快查看并回复。</p>
        <div class="subpage-success-actions">
            <a class="btn-primary-lg" href="index.php"><i class="bi bi-house"></i> 返回首页</a>
            <a class="btn-ghost-lg" href="message.php">再写一条</a>
        </div>
    </div>
<?php else: ?>
    <div class="subpage-grid">
        <div class="subpage-main-col">
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?php echo e($error); ?></div>
            <?php endif; ?>
            <?php if (!$smtpReady): ?>
                <div class="alert alert-warning"><i class="bi bi-info-circle"></i> 邮件服务暂未配置完成，暂时无法发送验证码。请稍后重试或联系管理员。</div>
            <?php endif; ?>

            <div class="subpage-steps" aria-hidden="true">
                <div class="subpage-step">
                    <span class="subpage-step-num">1</span>
                    <span class="subpage-step-text">写下你的想法</span>
                </div>
                <div class="subpage-step">
                    <span class="subpage-step-num">2</span>
                    <span class="subpage-step-text">验证邮箱</span>
                </div>
                <div class="subpage-step">
                    <span class="subpage-step-num">3</span>
                    <span class="subpage-step-text">我们尽快回复</span>
                </div>
            </div>

            <form method="post" class="subpage-form" id="message-form">
                <?php echo csrf_field(); ?>
                <div class="form-row">
                    <div class="form-group">
                        <label for="name"><i class="bi bi-person"></i> 昵称</label>
                        <input type="text" id="name" name="name" maxlength="80" value="<?php echo e($_POST['name'] ?? ''); ?>" placeholder="选填，可匿名">
                    </div>
                    <div class="form-group">
                        <label for="contact"><i class="bi bi-phone"></i> 联系方式</label>
                        <input type="text" id="contact" name="contact" maxlength="120" value="<?php echo e($_POST['contact'] ?? ''); ?>" placeholder="QQ / 微信 / 电话">
                    </div>
                </div>
                <div class="form-group">
                    <label for="email"><i class="bi bi-envelope"></i> 邮箱 <span class="req">*</span></label>
                    <input type="email" id="email" name="email" required maxlength="120" value="<?php echo e($_POST['email'] ?? ''); ?>" placeholder="用于接收验证码，必填">
                </div>
                <div class="form-group">
                    <label for="email_code"><i class="bi bi-shield-check"></i> 邮箱验证码 <span class="req">*</span></label>
                    <div class="email-code-row">
                        <input type="text" id="email_code" name="email_code" required maxlength="8" inputmode="numeric" pattern="[0-9]{6}" value="<?php echo e($_POST['email_code'] ?? ''); ?>" placeholder="6 位数字验证码" autocomplete="one-time-code">
                        <button type="button" class="btn-send-code" id="btn-send-code" data-scope="message" <?php echo $smtpReady ? '' : 'disabled'; ?>>发送验证码</button>
                    </div>
                    <p class="field-hint" id="code-hint">验证码将发送到上方邮箱，10 分钟内有效</p>
                </div>
                <div class="form-group">
                    <label for="content"><i class="bi bi-chat-quote"></i> 留言内容 <span class="req">*</span></label>
                    <textarea id="content" name="content" rows="6" required maxlength="3000" placeholder="请描述您的建议、问题或合作意向"><?php echo e($_POST['content'] ?? ''); ?></textarea>
                </div>
                <button type="submit" class="btn-primary-lg" <?php echo $smtpReady ? '' : 'disabled'; ?>>
                    <i class="bi bi-send"></i> 提交留言
                </button>
            </form>
        </div>

        <aside class="subpage-side">
            <div class="info-panel">
                <h3><i class="bi bi-shield-check"></i> 温馨提示</h3>
                <ul>
                    <li>请文明留言，勿发送广告与无关内容</li>
                    <li>需验证邮箱后才能提交，防止垃圾信息</li>
                    <li>提交成功后管理员将收到邮件通知</li>
                </ul>
            </div>
            <div class="info-panel info-panel-soft">
                <h3><i class="bi bi-plus-circle"></i> 站点收录</h3>
                <p>想把网站展示在本站？可提交收录申请。</p>
                <a class="side-link" href="apply.php">申请收录 <i class="bi bi-arrow-right"></i></a>
            </div>
        </aside>
    </div>
    <script src="assets/js/form-email-code.js"></script>
<?php endif; ?>
<?php page_layout_end(); ?>
