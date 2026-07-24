<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/layout.php';
require_once ROOT_PATH . '/includes/mailer.php';

$cfg = smtp_config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('error', '安全校验失败，请刷新后重试');
        redirect('smtp.php');
    }

    $action = (string) ($_POST['action'] ?? 'save');

    $smtpInput = static function () {
        $port = security_int($_POST['port'] ?? 465, 1, 65535);
        $enc = security_enum((string) ($_POST['encryption'] ?? 'ssl'), ['ssl', 'tls', 'none', '']);
        $from = security_email($_POST['from_email'] ?? '');
        $loginMail = security_email($_POST['login_email'] ?? '');
        return [
            'enabled' => !empty($_POST['enabled']),
            'host' => security_clean_text($_POST['host'] ?? '', 200),
            'port' => $port !== null ? $port : 465,
            'encryption' => $enc !== null && $enc !== '' ? $enc : 'ssl',
            'user' => security_clean_text($_POST['user'] ?? '', 200),
            'pass' => (string) ($_POST['pass'] ?? ''),
            'from_email' => $from !== null ? $from : security_clean_text($_POST['from_email'] ?? '', 120),
            'from_name' => security_clean_text($_POST['from_name'] ?? '', 80),
            'login_email_verify' => !empty($_POST['login_email_verify']),
            'login_email' => $loginMail !== null ? $loginMail : security_clean_text($_POST['login_email'] ?? '', 120),
        ];
    };

    if ($action === 'save') {
        $input = $smtpInput();
        $ok = smtp_save_config($input);
        admin_log_write($ok ? 'smtp_save' : 'smtp_save_fail', $ok ? '保存 SMTP / 登录验证配置' : '保存 SMTP 配置失败', [
            'module' => 'smtp',
            'level' => $ok ? 'success' : 'error',
            'detail' => [
                'enabled' => !empty($input['enabled']),
                'host' => $input['host'] ?? '',
                'login_email_verify' => !empty($input['login_email_verify']),
            ],
        ]);
        flash_set($ok ? 'success' : 'error', $ok ? 'SMTP / 登录验证配置已保存' : '保存失败，请检查目录写权限');
        redirect('smtp.php');
    }

    if ($action === 'test') {
        // 先把当前表单也存一份（密码留空则用旧值）
        smtp_save_config($smtpInput());
        $cfg = smtp_config();
        $to = security_email($_POST['test_to'] ?? '');
        if ($to === null) {
            $to = $cfg['login_email'] !== '' ? $cfg['login_email'] : $cfg['from_email'];
        }
        $siteName = '导航管理后台';
        try {
            $content = load_content();
            if (!empty($content['site']['name'])) {
                $siteName = (string) $content['site']['name'];
            }
        } catch (Throwable $e) {
        }
        $html = mailer_login_code_html('123456', $_SESSION['admin_username'] ?? 'admin', $siteName);
        $html = str_replace('123456', 'TEST' . random_int(100, 999), $html);
        $result = mailer_send($to, '【' . $siteName . '】SMTP 测试邮件', $html, '这是一封 SMTP 测试邮件。');
        admin_log_write(
            !empty($result['ok']) ? 'smtp_test' : 'smtp_test_fail',
            !empty($result['ok']) ? ('发送 SMTP 测试邮件至 ' . $to) : ('SMTP 测试失败：' . ($result['message'] ?? '')),
            [
                'module' => 'smtp',
                'level' => !empty($result['ok']) ? 'info' : 'error',
                'detail' => ['to' => $to],
            ]
        );
        if (!empty($result['ok'])) {
            flash_set('success', '测试邮件已发送至 ' . $to);
        } else {
            flash_set('error', $result['message'] ?? '发送失败');
        }
        redirect('smtp.php');
    }
}

admin_layout_start('SMTP / 登录验证', 'smtp');
$hasPass = $cfg['pass'] !== '';
?>
<div class="panel">
    <div class="panel-head">
        <div>
            <h2>SMTP 邮件与登录验证</h2>
            <p class="muted">配置 SMTP 协议发送邮件；可开启「登录时邮箱验证码」。验证码邮件为精美 HTML 模板。</p>
        </div>
    </div>

    <form method="post" class="stack-form" id="smtp-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save">

        <fieldset class="switch-fieldset">
            <legend>开关</legend>
            <label class="switch-label">
                <input type="checkbox" name="enabled" value="1" <?php echo $cfg['enabled'] === '1' ? 'checked' : ''; ?>>
                <span>启用 SMTP</span>
            </label>
            <label class="switch-label">
                <input type="checkbox" name="login_email_verify" value="1" <?php echo $cfg['login_email_verify'] === '1' ? 'checked' : ''; ?>>
                <span>登录时需要邮箱验证码（可随时关闭）</span>
            </label>
        </fieldset>

        <h3 class="auth-subhead" style="margin-top:8px;">SMTP 服务器</h3>
        <label>
            <span>SMTP 主机</span>
            <input type="text" name="host" value="<?php echo e($cfg['host']); ?>" placeholder="例如 smtp.qq.com / smtp.163.com / smtp.exmail.qq.com">
        </label>
        <div class="form-row-2">
            <label>
                <span>端口</span>
                <input type="number" name="port" value="<?php echo (int) $cfg['port']; ?>" min="1" max="65535" placeholder="465 / 587 / 25">
            </label>
            <label>
                <span>加密方式</span>
                <select name="encryption">
                    <option value="ssl" <?php echo $cfg['encryption'] === 'ssl' ? 'selected' : ''; ?>>SSL（常用 465）</option>
                    <option value="tls" <?php echo $cfg['encryption'] === 'tls' ? 'selected' : ''; ?>>STARTTLS（常用 587）</option>
                    <option value="none" <?php echo $cfg['encryption'] === 'none' ? 'selected' : ''; ?>>无加密（不推荐）</option>
                </select>
            </label>
        </div>
        <label>
            <span>SMTP 用户名</span>
            <input type="text" name="user" value="<?php echo e($cfg['user']); ?>" placeholder="通常是完整邮箱" autocomplete="off">
        </label>
        <label>
            <span>SMTP 密码 / 授权码 <?php if ($hasPass): ?><em class="muted">已保存，留空不修改</em><?php endif; ?></span>
            <input type="password" name="pass" value="" placeholder="<?php echo $hasPass ? '已配置，填写则覆盖' : '邮箱授权码或密码'; ?>" autocomplete="new-password">
        </label>

        <h3 class="auth-subhead">发件人</h3>
        <label>
            <span>发件人邮箱</span>
            <input type="email" name="from_email" value="<?php echo e($cfg['from_email']); ?>" placeholder="noreply@example.com">
        </label>
        <label>
            <span>发件人名称</span>
            <input type="text" name="from_name" value="<?php echo e($cfg['from_name']); ?>" placeholder="站点名称或「导航管理后台」">
        </label>

        <h3 class="auth-subhead">登录验证码收件箱</h3>
        <label>
            <span>接收登录验证码的邮箱</span>
            <input type="email" name="login_email" value="<?php echo e($cfg['login_email']); ?>" placeholder="留空则使用发件人邮箱">
        </label>
        <p class="muted" style="margin-top:-6px;font-size:0.88rem;">开启「登录邮箱验证码」后，账号密码正确才会发信；验证码 10 分钟有效。</p>

        <div class="form-actions" style="flex-wrap:wrap;gap:8px;">
            <button type="submit" class="btn btn-primary">保存配置</button>
        </div>
    </form>

    <hr style="border:none;border-top:1px solid var(--border);margin:24px 0;">

    <form method="post" class="stack-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="test">
        <!-- 测试时同步当前表单字段（从主表单复制较繁琐，这里用已保存配置 + 可选收件人） -->
        <input type="hidden" name="enabled" value="<?php echo $cfg['enabled'] === '1' ? '1' : ''; ?>">
        <input type="hidden" name="host" value="<?php echo e($cfg['host']); ?>">
        <input type="hidden" name="port" value="<?php echo (int) $cfg['port']; ?>">
        <input type="hidden" name="encryption" value="<?php echo e($cfg['encryption']); ?>">
        <input type="hidden" name="user" value="<?php echo e($cfg['user']); ?>">
        <input type="hidden" name="pass" value="">
        <input type="hidden" name="from_email" value="<?php echo e($cfg['from_email']); ?>">
        <input type="hidden" name="from_name" value="<?php echo e($cfg['from_name']); ?>">
        <input type="hidden" name="login_email" value="<?php echo e($cfg['login_email']); ?>">
        <?php if ($cfg['login_email_verify'] === '1'): ?>
            <input type="hidden" name="login_email_verify" value="1">
        <?php endif; ?>

        <label>
            <span>测试收件人</span>
            <input type="email" name="test_to" value="<?php echo e($cfg['login_email'] !== '' ? $cfg['login_email'] : $cfg['from_email']); ?>" placeholder="test@example.com">
        </label>
        <div class="form-actions">
            <button type="submit" class="btn btn-secondary">发送测试邮件（HTML）</button>
        </div>
    </form>
</div>
<style>
.form-row-2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
@media (max-width: 640px) { .form-row-2 { grid-template-columns:1fr; } }
.auth-subhead { font-size:1rem; margin:18px 0 10px; font-weight:750; color:#1e293b; }
</style>
<?php admin_layout_end(); ?>
