<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/page_layout.php';
require_once __DIR__ . '/includes/mailer.php';

$data = load_site_data();
$GLOBALS['data'] = $data;
$ok = false;
$error = '';
$smtpReady = smtp_is_ready();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = '安全校验失败，请刷新后重试';
    } else {
        $name = security_clean_text($_POST['name'] ?? '', 80);
        $website = security_url(trim((string) ($_POST['website'] ?? '')), false);
        $email = security_email($_POST['email'] ?? '');
        $emailCode = security_digits($_POST['email_code'] ?? '', 6);
        $content = security_clean_text($_POST['content'] ?? '', 2000);
        if ($name === '' || $website === '') {
            $error = '请填写网站名称与有效网址（仅 http/https）';
        } elseif ($email === null) {
            $error = '请填写有效的联系邮箱';
        } elseif ($emailCode === null) {
            $error = '请输入 6 位数字邮箱验证码';
        } else {
            $vr = mailer_require_form_email_verified($email, $emailCode, 'apply');
            if (empty($vr['ok'])) {
                $error = $vr['message'] ?? '邮箱验证失败';
            } else {
                $payload = [
                    'type' => 'apply',
                    'name' => $name,
                    'email' => $email,
                    'website' => $website,
                    'content' => $content !== '' ? $content : '申请收录：' . $name,
                    'ip' => security_ip(),
                ];
                $saved = save_message($payload);
                if ($saved) {
                    mailer_clear_form_code('apply');
                    mailer_notify_admin_submission('apply', $payload);
                    $ok = true;
                } else {
                    $error = '提交失败，请稍后重试（验证码仍有效，可直接再提交）';
                }
            }
        }
    }
}

page_layout_start('申请收录', [
    'page_key' => 'apply',
    'icon' => 'bi-plus-circle',
    'tone' => 'teal',
    'lead' => '欢迎优质站点申请友情链接或栏目收录。需验证邮箱后提交，我们会尽快审核。',
]);
?>
<?php if ($ok): ?>
    <div class="subpage-success">
        <div class="subpage-success-icon"><i class="bi bi-check-circle-fill"></i></div>
        <h2>提交成功</h2>
        <p>我们已收到您的收录申请，并已通知管理员。感谢支持！</p>
        <div class="subpage-success-actions">
            <a class="btn-primary-lg" href="index.php"><i class="bi bi-house"></i> 返回首页</a>
            <a class="btn-ghost-lg" href="apply.php">继续申请</a>
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
                    <span class="subpage-step-text">填写站点信息</span>
                </div>
                <div class="subpage-step">
                    <span class="subpage-step-num">2</span>
                    <span class="subpage-step-text">邮箱验证码确认</span>
                </div>
                <div class="subpage-step">
                    <span class="subpage-step-num">3</span>
                    <span class="subpage-step-text">审核通过展示</span>
                </div>
            </div>

            <form method="post" class="subpage-form" id="apply-form">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label for="name"><i class="bi bi-bookmark-star"></i> 网站名称 <span class="req">*</span></label>
                    <input type="text" id="name" name="name" required maxlength="80" value="<?php echo e($_POST['name'] ?? ''); ?>" placeholder="例如：我的工具站">
                </div>
                <div class="form-group">
                    <label for="website"><i class="bi bi-link-45deg"></i> 网站地址 <span class="req">*</span></label>
                    <input type="url" id="website" name="website" required maxlength="500" value="<?php echo e($_POST['website'] ?? ''); ?>" placeholder="https://example.com">
                </div>
                <div class="form-group">
                    <label for="email"><i class="bi bi-envelope"></i> 联系邮箱 <span class="req">*</span></label>
                    <input type="email" id="email" name="email" required maxlength="120" value="<?php echo e($_POST['email'] ?? ''); ?>" placeholder="用于接收验证码，必填">
                </div>
                <div class="form-group">
                    <label for="email_code"><i class="bi bi-shield-check"></i> 邮箱验证码 <span class="req">*</span></label>
                    <div class="email-code-row">
                        <input type="text" id="email_code" name="email_code" required maxlength="8" inputmode="numeric" pattern="[0-9]{6}" value="<?php echo e($_POST['email_code'] ?? ''); ?>" placeholder="6 位数字验证码" autocomplete="one-time-code">
                        <button type="button" class="btn-send-code" id="btn-send-code" data-scope="apply" <?php echo $smtpReady ? '' : 'disabled'; ?>>发送验证码</button>
                    </div>
                    <p class="field-hint" id="code-hint">验证码将发送到上方邮箱，10 分钟内有效</p>
                </div>
                <div class="form-group">
                    <label for="content"><i class="bi bi-text-left"></i> 简介 / 说明</label>
                    <textarea id="content" name="content" rows="4" maxlength="2000" placeholder="网站简介、交换要求、希望展示的位置等"><?php echo e($_POST['content'] ?? ''); ?></textarea>
                </div>
                <button type="submit" class="btn-primary-lg" <?php echo $smtpReady ? '' : 'disabled'; ?>>
                    <i class="bi bi-send"></i> 提交申请
                </button>
            </form>
        </div>

        <aside class="subpage-side">
            <div class="info-panel">
                <h3><i class="bi bi-lightbulb"></i> 申请小贴士</h3>
                <ul>
                    <li>请确保网站可正常访问、内容健康合规</li>
                    <li>必须验证邮箱后才能提交，防止垃圾申请</li>
                    <li>审核通过后将展示在友情链接或相关栏目</li>
                    <li>提交成功后系统会邮件通知管理员</li>
                </ul>
            </div>
            <div class="info-panel info-panel-soft">
                <h3><i class="bi bi-chat-dots"></i> 其他联系</h3>
                <p>有合作或问题也可以先留言，我们会尽快回复。</p>
                <a class="side-link" href="message.php">去在线留言 <i class="bi bi-arrow-right"></i></a>
            </div>
        </aside>
    </div>
    <script src="assets/js/form-email-code.js"></script>
<?php endif; ?>
<?php page_layout_end(); ?>
