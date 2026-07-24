<?php
/**
 * 吾爱破解 OAuth 式授权引导页
 * 说明：52pojie 无开放 OAuth API，采用标准授权码 state 流程 + 登录 Cookie 换取服务端凭证
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/oauth_providers.php';
require_once __DIR__ . '/layout.php';
require_login();

$step = (string) ($_GET['step'] ?? 'start');
$state = (string) ($_GET['state'] ?? ($_SESSION['52pojie_oauth_state'] ?? ''));

if ($step === 'start') {
    $url = oauth_52pojie_start();
    // 统一走 start 生成 state；本页相对路径跳转 authorize
    if (preg_match('/[?&]state=([^&]+)/', $url, $m)) {
        redirect('oauth_52pojie.php?step=authorize&state=' . rawurlencode(rawurldecode($m[1])));
    }
    flash_set('error', '无法发起吾爱授权会话');
    redirect('hotboards.php#52pojie-auth');
}

// authorize 步骤：校验 state + 15 分钟超时
$stateAt = (int) ($_SESSION['52pojie_oauth_state_at'] ?? 0);
if ($stateAt > 0 && (time() - $stateAt) > 900) {
    unset($_SESSION['52pojie_oauth_state'], $_SESSION['52pojie_oauth_state_at']);
    flash_set('error', '授权会话已超时（15 分钟），请重新发起');
    redirect('hotboards.php#52pojie-auth');
}
if ($state === '' || empty($_SESSION['52pojie_oauth_state']) || !hash_equals((string) $_SESSION['52pojie_oauth_state'], $state)) {
    flash_set('error', '授权会话无效，请从热榜设置重新发起');
    redirect('hotboards.php#52pojie-auth');
}

$callbackUrl = oauth_52pojie_callback_url();
$loginUrl = 'https://www.52pojie.cn/member.php?mod=logging&action=login';

admin_layout_start('吾爱 OAuth 授权', 'hotboards');
?>
<div class="panel">
    <div class="panel-head">
        <div>
            <h2>吾爱破解 · OAuth 式授权</h2>
            <p class="muted">
                吾爱官方未提供开放平台 OAuth。本流程使用相同的 <strong>state 防伪 + 授权码会话</strong>，
                在你浏览器登录后把 Cookie 安全提交到本站服务端（仅管理员可见，不暴露给前台访客）。
            </p>
        </div>
    </div>

    <div class="oauth-steps">
        <div class="oauth-step">
            <span class="n">1</span>
            <div>
                <strong>在浏览器登录吾爱</strong>
                <p class="muted">新窗口打开并完成登录，保持登录状态。</p>
                <a class="btn btn-primary" href="<?php echo e($loginUrl); ?>" target="_blank" rel="noopener">打开吾爱登录页</a>
            </div>
        </div>
        <div class="oauth-step">
            <span class="n">2</span>
            <div>
                <strong>复制登录 Cookie</strong>
                <p class="muted">F12 → Network → 任意 52pojie 请求 → Request Headers 中的 <code>Cookie</code> 整段复制。</p>
                <p class="muted">常见字段含 <code>htVC_2132_auth</code>、<code>htVC_2132_saltkey</code> 等。</p>
            </div>
        </div>
        <div class="oauth-step">
            <span class="n">3</span>
            <div>
                <strong>提交授权（换取服务端凭证）</strong>
                <form method="post" action="oauth_52pojie_callback.php" class="stack-form" style="margin-top:10px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="state" value="<?php echo e($state); ?>">
                    <label>
                        <span>用户名（可选，便于后台识别）</span>
                        <input type="text" name="username" maxlength="80" placeholder="你的吾爱用户名">
                    </label>
                    <label>
                        <span>Cookie <span style="color:#dc2626">*</span></span>
                        <textarea name="cookie" rows="5" required placeholder="粘贴完整 Cookie 字符串"></textarea>
                    </label>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">完成授权并保存</button>
                        <a class="btn btn-secondary" href="hotboards.php#52pojie-auth">取消</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <p class="muted" style="margin-top:16px;font-size:0.88rem;">
        回调地址（本站）：<code><?php echo e($callbackUrl); ?></code><br>
        state：<code><?php echo e($state); ?></code>（15 分钟内有效）
    </p>
</div>
<style>
.oauth-steps { display:flex; flex-direction:column; gap:18px; }
.oauth-step { display:flex; gap:14px; align-items:flex-start; padding:14px; border:1px solid #e2e8f0; border-radius:12px; background:#f8fafc; }
.oauth-step .n { width:32px; height:32px; border-radius:50%; background:linear-gradient(135deg,#5b6cff,#7c3aed); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; flex-shrink:0; }
.oauth-step p { margin:6px 0 10px; }
.oauth-step code { background:#e2e8f0; padding:1px 6px; border-radius:4px; font-size:0.85em; }
.stack-form label { display:block; margin-bottom:12px; }
.stack-form label > span { display:block; margin-bottom:6px; font-weight:600; }
.stack-form input, .stack-form textarea { width:100%; max-width:720px; padding:10px 12px; border:1px solid #e2e8f0; border-radius:8px; font:inherit; }
</style>
<?php admin_layout_end(); ?>
