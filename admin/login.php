<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/captcha.php';
require_once ROOT_PATH . '/includes/mailer.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = '';
$info = '';
$step = 'password'; // password | email
$usernamePrefill = '';
$emailVerifyOn = smtp_login_verify_enabled();
$siteName = '导航管理后台';
try {
    $content = load_content();
    if (!empty($content['site']['name'])) {
        $siteName = (string) $content['site']['name'];
    }
} catch (Throwable $e) {
    // ignore
}

// 恢复邮箱验证步骤会话
if (!empty($_SESSION['login_pending_user']) && !empty($_SESSION['login_pending_at'])) {
    if ((time() - (int) $_SESSION['login_pending_at']) <= 900) {
        $step = 'email';
        $usernamePrefill = (string) $_SESSION['login_pending_user'];
    } else {
        unset($_SESSION['login_pending_user'], $_SESSION['login_pending_at'], $_SESSION['login_pending_id']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = '请求无效，请刷新后重试';
    } else {
        $action = (string) ($_POST['action'] ?? 'login');

        // —— 发送 / 重发邮箱验证码 ——
        if ($action === 'send_email_code') {
            $u = (string) ($_SESSION['login_pending_user'] ?? '');
            if ($u === '') {
                $error = '请先完成账号密码验证';
                $step = 'password';
            } elseif (!smtp_login_verify_enabled()) {
                $error = '邮箱验证码登录未开启';
                $step = 'password';
            } else {
                // 简单限流：60 秒内不可重复发送
                $lastSend = (int) ($_SESSION['login_email_last_send'] ?? 0);
                if ($lastSend > 0 && (time() - $lastSend) < 60) {
                    $wait = 60 - (time() - $lastSend);
                    $error = '发送过于频繁，请 ' . $wait . ' 秒后再试';
                    $step = 'email';
                    $usernamePrefill = $u;
                } else {
                    $res = mailer_send_login_code($u);
                    if (!empty($res['ok'])) {
                        $_SESSION['login_email_last_send'] = time();
                        $info = $res['message'];
                    } else {
                        $error = $res['message'] ?? '验证码发送失败';
                    }
                    $step = 'email';
                    $usernamePrefill = $u;
                }
            }
        } elseif ($action === 'verify_email') {
            $u = (string) ($_SESSION['login_pending_user'] ?? '');
            $code = security_digits($_POST['email_code'] ?? '', 6) ?? '';
            $captcha = security_clean_text($_POST['captcha'] ?? '', 12);
            if ($u === '') {
                $error = '会话已失效，请重新登录';
                $step = 'password';
            } elseif (!captcha_verify($captcha)) {
                $error = '图形验证码错误或已过期';
                $step = 'email';
                $usernamePrefill = $u;
            } else {
                $vr = mailer_verify_login_code($u, $code);
                if (!empty($vr['ok'])) {
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_username'] = $u;
                    $_SESSION['admin_id'] = (int) ($_SESSION['login_pending_id'] ?? 0);
                    unset(
                        $_SESSION['login_pending_user'],
                        $_SESSION['login_pending_at'],
                        $_SESSION['login_pending_id'],
                        $_SESSION['login_email_last_send']
                    );
                    session_regenerate_id(true);
                    redirect('index.php');
                }
                $error = $vr['message'] ?? '邮箱验证失败';
                $step = 'email';
                $usernamePrefill = $u;
            }
        } elseif ($action === 'cancel_email') {
            unset(
                $_SESSION['login_pending_user'],
                $_SESSION['login_pending_at'],
                $_SESSION['login_pending_id'],
                $_SESSION['login_email_code'],
                $_SESSION['login_email_code_at'],
                $_SESSION['login_email_code_user'],
                $_SESSION['login_email_last_send']
            );
            $step = 'password';
            $info = '已取消，请重新登录';
        } else {
            // 账号密码 + 图形验证码
            $username = security_clean_text($_POST['username'] ?? '', 64);
            $password = (string) ($_POST['password'] ?? '');
            if (strlen($password) > 256) {
                $password = substr($password, 0, 256);
            }
            $captcha = security_clean_text($_POST['captcha'] ?? '', 12);
            $usernamePrefill = $username;

            if (!captcha_verify($captcha)) {
                $error = '图形验证码错误或已过期';
            } else {
                $admin = load_admin_by_username($username);
                $ok = $admin && !empty($admin['password_hash']) && password_verify($password, $admin['password_hash']);
                if (!$ok) {
                    $error = '用户名或密码错误';
                } elseif ($emailVerifyOn) {
                    if (!smtp_is_ready()) {
                        $error = '已开启邮箱验证码登录，但 SMTP 未配置完成，请联系管理员';
                    } else {
                        $_SESSION['login_pending_user'] = $admin['username'];
                        $_SESSION['login_pending_id'] = (int) $admin['id'];
                        $_SESSION['login_pending_at'] = time();
                        $res = mailer_send_login_code($admin['username']);
                        $step = 'email';
                        $usernamePrefill = $admin['username'];
                        if (!empty($res['ok'])) {
                            $_SESSION['login_email_last_send'] = time();
                            $info = $res['message'];
                        } else {
                            $error = '密码正确，但验证码发送失败：' . ($res['message'] ?? '');
                        }
                    }
                } else {
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_username'] = $admin['username'];
                    $_SESSION['admin_id'] = (int) $admin['id'];
                    session_regenerate_id(true);
                    redirect('index.php');
                }
            }
        }
    }
}

$maskEmail = '';
if ($step === 'email') {
    $cfg = smtp_config();
    $to = $cfg['login_email'] !== '' ? $cfg['login_email'] : $cfg['from_email'];
    $maskEmail = $to !== '' ? mailer_mask_email($to) : '';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>后台登录 - <?php echo e($siteName); ?></title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="login-page">
    <div class="login-bg" aria-hidden="true">
        <span class="orb orb-a"></span>
        <span class="orb orb-b"></span>
        <span class="orb orb-c"></span>
        <span class="grid-overlay"></span>
    </div>

    <div class="login-shell">
        <section class="login-hero">
            <div class="login-brand">
                <span class="login-brand-mark">彩</span>
                <div>
                    <strong><?php echo e($siteName); ?></strong>
                    <small>Admin Console</small>
                </div>
            </div>
            <h2 class="login-hero-title">安全、清晰的内容管理后台</h2>
            <p class="login-hero-desc">管理站点、热榜、工具与友链。登录已加强图形验证码<?php echo $emailVerifyOn ? '与邮箱二次验证' : ''; ?>，守护你的控制台。</p>
            <ul class="login-hero-points">
                <li><span class="dot"></span>图形验证码防暴力尝试</li>
                <li><span class="dot"></span><?php echo $emailVerifyOn ? '邮箱验证码二次校验已开启' : '可按需开启邮箱验证码登录'; ?></li>
                <li><span class="dot"></span>SMTP 可在后台灵活配置</li>
            </ul>
        </section>

        <div class="login-card">
            <div class="login-card-head">
                <h1><?php echo $step === 'email' ? '邮箱验证' : '欢迎回来'; ?></h1>
                <p class="sub">
                    <?php if ($step === 'email'): ?>
                        已验证账号 <strong><?php echo e($usernamePrefill); ?></strong>，请输入发至
                        <?php echo $maskEmail !== '' ? '<strong>' . e($maskEmail) . '</strong>' : '管理员邮箱'; ?>
                        的验证码
                    <?php else: ?>
                        登录管理后台，继续编辑你的导航内容
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><span class="alert-ico">!</span><?php echo e($error); ?></div>
            <?php endif; ?>
            <?php if ($info): ?>
                <div class="alert alert-success"><span class="alert-ico">✓</span><?php echo e($info); ?></div>
            <?php endif; ?>

            <?php if ($step === 'email'): ?>
                <form method="post" class="form-grid login-form" autocomplete="off">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="verify_email">
                    <div class="form-group">
                        <label for="email_code">邮箱验证码</label>
                        <input type="text" id="email_code" name="email_code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="6 位数字" autofocus>
                    </div>
                    <div class="form-group">
                        <label for="captcha">图形验证码</label>
                        <div class="captcha-row">
                            <input type="text" id="captcha" name="captcha" required maxlength="8" placeholder="输入图中字符" autocomplete="off">
                            <button type="button" class="captcha-img-btn" title="点击刷新" aria-label="刷新验证码">
                                <img src="captcha.php?t=<?php echo time(); ?>" alt="验证码" id="captcha-img" width="128" height="46">
                            </button>
                        </div>
                    </div>
                    <div class="btn-row login-actions">
                        <button type="submit" class="btn btn-primary btn-block">验证并登录</button>
                    </div>
                </form>
                <div class="login-extra-actions">
                    <form method="post" class="inline-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="send_email_code">
                        <button type="submit" class="link-btn">重新发送验证码</button>
                    </form>
                    <form method="post" class="inline-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="cancel_email">
                        <button type="submit" class="link-btn muted">返回账号登录</button>
                    </form>
                </div>
            <?php else: ?>
                <form method="post" class="form-grid login-form" autocomplete="on">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="login">
                    <div class="form-group">
                        <label for="username">用户名</label>
                        <div class="input-with-ico">
                            <span class="ico" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5Z" fill="currentColor"/></svg>
                            </span>
                            <input type="text" id="username" name="username" required autocomplete="username" value="<?php echo e($usernamePrefill); ?>" placeholder="管理员用户名">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="password">密码</label>
                        <div class="input-with-ico">
                            <span class="ico" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M17 9h-1V7a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2Zm-7-2a2 2 0 0 1 4 0v2h-4V7Zm3 8.7V18h-2v-2.3a1.5 1.5 0 1 1 2 0Z" fill="currentColor"/></svg>
                            </span>
                            <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="登录密码">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="captcha">图形验证码</label>
                        <div class="captcha-row">
                            <input type="text" id="captcha" name="captcha" required maxlength="8" placeholder="不区分大小写" autocomplete="off">
                            <button type="button" class="captcha-img-btn" title="点击刷新" aria-label="刷新验证码">
                                <img src="captcha.php?t=<?php echo time(); ?>" alt="验证码" id="captcha-img" width="128" height="46">
                            </button>
                        </div>
                    </div>
                    <?php if ($emailVerifyOn): ?>
                        <p class="login-tip"><span class="tip-dot"></span>已开启邮箱验证码，密码正确后将发送至管理员邮箱</p>
                    <?php endif; ?>
                    <div class="btn-row login-actions">
                        <button type="submit" class="btn btn-primary btn-block">登录</button>
                        <a class="btn btn-secondary btn-block" href="../index.php">返回前台</a>
                    </div>
                </form>
            <?php endif; ?>

            <p class="hint login-foot">账号在安装时创建 · 可在后台修改密码与 SMTP</p>
        </div>
    </div>

    <script src="assets/login-captcha.js"></script>
</body>
</html>
