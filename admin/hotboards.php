<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/layout.php';
require_once ROOT_PATH . '/includes/hot_fetcher.php';
require_once ROOT_PATH . '/includes/oauth_providers.php';

$catalog = hot_board_catalog();
$enabled = hot_board_enabled_ids();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('error', '安全校验失败，请刷新后重试');
        redirect('hotboards.php');
    }

    $action = $_POST['action'] ?? 'save_boards';

    // —— Linux.do OAuth 应用配置 ——
    if ($action === 'save_linuxdo_oauth_app') {
        oauth_linuxdo_save_app([
            'client_id' => $_POST['linuxdo_client_id'] ?? '',
            'client_secret' => $_POST['linuxdo_client_secret'] ?? '',
            'redirect_uri' => $_POST['linuxdo_redirect_uri'] ?? '',
        ]);
        admin_log_write('hotboards_linuxdo_oauth_app', '保存 Linux.do OAuth 应用配置', [
            'module' => 'hotboards',
            'level' => 'warning',
        ]);
        flash_set('success', 'Linux.do Connect 应用配置已保存');
        redirect('hotboards.php#linuxdo-auth');
    }

    // —— Linux.do 手动凭证兜底 ——
    if ($action === 'save_linuxdo') {
        $input = [
            'mode' => $_POST['linuxdo_auth_mode'] ?? 'auto',
            'api_username' => trim((string) ($_POST['linuxdo_api_username'] ?? '')),
            'cookie' => (string) ($_POST['linuxdo_cookie'] ?? ''),
            'api_key' => (string) ($_POST['linuxdo_api_key'] ?? ''),
            'clear' => !empty($_POST['clear_linuxdo']),
            'force_cookie' => !empty($_POST['update_cookie']),
            'force_api_key' => !empty($_POST['update_api_key']),
            'cookie_submitted' => !empty($_POST['update_cookie']),
            'api_key_submitted' => !empty($_POST['update_api_key']),
        ];
        if (hot_linuxdo_save_credentials($input)) {
            if (!empty($_POST['clear_linuxdo'])) {
                admin_log_write('hotboards_linuxdo_clear', '清除 Linux.do 登录凭证', [
                    'module' => 'hotboards',
                    'level' => 'warning',
                ]);
                flash_set('success', '已清除 Linux.do 登录凭证');
            } else {
                admin_log_write('hotboards_linuxdo_cred', '保存 Linux.do 登录凭证', [
                    'module' => 'hotboards',
                    'level' => 'warning',
                ]);
                flash_set('success', 'Linux.do 凭证已保存');
            }
        } else {
            flash_set('error', 'Linux.do 配置保存失败，请检查 config/data 目录写权限');
        }
        redirect('hotboards.php#linuxdo-auth');
    }

    if ($action === 'test_linuxdo') {
        $result = hot_linuxdo_test_auth();
        if (!empty($result['ok'])) {
            $extra = '';
            if (!empty($result['sample'])) {
                $extra = '；示例：' . mb_substr_admin($result['sample'], 0, 40);
            }
            flash_set('success', ($result['message'] ?? '测试成功') . $extra);
        } else {
            flash_set('error', $result['message'] ?? '测试失败');
        }
        redirect('hotboards.php#linuxdo-auth');
    }

    if ($action === 'refresh_linuxdo') {
        $f = ROOT_PATH . '/data/cache/linuxdo.json';
        if (is_file($f)) {
            @unlink($f);
        }
        $catalog = hot_board_catalog();
        $source = $catalog['linuxdo'] ?? null;
        if ($source) {
            $board = hot_fetch_source($source);
            $n = count($board['items'] ?? []);
            $authHint = hot_linuxdo_has_auth() ? '（已配置登录态）' : '（公开模式）';
            if ($n > 0) {
                flash_set('success', '已刷新 Linux.do 热榜 ' . $n . ' 条' . $authHint);
            } else {
                flash_set('error', '刷新失败，未获取到条目。请检查网络或 OAuth 凭证');
            }
        } else {
            flash_set('error', '未找到 linuxdo 源配置');
        }
        redirect('hotboards.php#linuxdo-auth');
    }

    // —— 吾爱 ——
    if ($action === 'clear_52pojie') {
        hot_52pojie_save_credentials(['clear' => 1]);
        admin_log_write('hotboards_52pojie_clear', '清除吾爱登录凭证', [
            'module' => 'hotboards',
            'level' => 'warning',
        ]);
        flash_set('success', '已清除吾爱登录凭证');
        redirect('hotboards.php#52pojie-auth');
    }
    if ($action === 'test_52pojie') {
        $result = hot_52pojie_test_auth();
        if (!empty($result['ok'])) {
            flash_set('success', $result['message'] ?? '测试成功');
        } else {
            flash_set('error', $result['message'] ?? '测试失败');
        }
        redirect('hotboards.php#52pojie-auth');
    }
    if ($action === 'refresh_52pojie') {
        $f = ROOT_PATH . '/data/cache/52pojie.json';
        if (is_file($f)) {
            @unlink($f);
        }
        $source = $catalog['52pojie'] ?? null;
        if ($source) {
            $board = hot_fetch_source($source);
            $n = count($board['items'] ?? []);
            $authHint = hot_52pojie_has_auth() ? '（已授权）' : '（公开）';
            if ($n > 0) {
                flash_set('success', '已刷新吾爱热榜 ' . $n . ' 条' . $authHint);
            } else {
                flash_set('error', '刷新失败，未获取到条目');
            }
        } else {
            flash_set('error', '未找到 52pojie 源配置');
        }
        redirect('hotboards.php#52pojie-auth');
    }

    // —— 启用列表 ——
    $selected = $_POST['enabled'] ?? [];
    if (!is_array($selected)) {
        $selected = [];
    }

    $order = $_POST['order'] ?? [];
    if (!is_array($order)) {
        $order = [];
    }

    $ordered = [];
    foreach ($order as $id) {
        $id = (string) $id;
        if (in_array($id, $selected, true) && isset($catalog[$id]) && !in_array($id, $ordered, true)) {
            $ordered[] = $id;
        }
    }
    foreach ($selected as $id) {
        $id = (string) $id;
        if (isset($catalog[$id]) && !in_array($id, $ordered, true)) {
            $ordered[] = $id;
        }
    }

    if (empty($ordered)) {
        flash_set('error', '请至少选择一个热榜来源');
        redirect('hotboards.php');
    }

    if (hot_board_save_enabled_ids($ordered)) {
        foreach ($ordered as $id) {
            $f = ROOT_PATH . '/data/cache/' . $id . '.json';
            if (is_file($f)) {
                @unlink($f);
            }
        }
        admin_log_write('hotboards_save', '保存热榜显示配置（共 ' . count($ordered) . ' 个）', [
            'module' => 'hotboards',
            'level' => 'success',
            'detail' => ['enabled' => $ordered],
        ]);
        flash_set('success', '热榜显示配置已保存（共 ' . count($ordered) . ' 个）');
    } else {
        admin_log_write('hotboards_save_fail', '热榜配置保存失败', ['module' => 'hotboards', 'level' => 'error']);
        flash_set('error', '保存失败，请检查数据库或 config 目录写权限');
    }
    redirect('hotboards.php');
}

function mb_substr_admin($str, $start, $len)
{
    if (function_exists('mb_substr')) {
        return mb_substr((string) $str, $start, $len, 'UTF-8');
    }
    return substr((string) $str, $start, $len);
}

$cred = hot_linuxdo_credentials();
$hasCookie = $cred['cookie'] !== '';
$hasApi = $cred['api_key'] !== '';
$authUpdated = (string) (isset($cred['updated_at']) ? $cred['updated_at'] : hot_setting_get('linuxdo_auth_updated_at', ''));
$lastFetchRaw = (string) hot_setting_get('linuxdo_last_fetch', '');
$lastFetch = $lastFetchRaw !== '' ? json_decode($lastFetchRaw, true) : null;
if (!is_array($lastFetch)) {
    $lastFetch = null;
}
$oauthApp = oauth_linuxdo_app_config();
$oauthBoundUser = (string) hot_setting_get('linuxdo_oauth_username', '');
$oauthBoundAt = (string) hot_setting_get('linuxdo_oauth_bound_at', '');
$callbackHint = oauth_linuxdo_callback_url();

$cred52 = hot_52pojie_credentials();
$has52 = $cred52['cookie'] !== '';
$auth52Updated = (string) ($cred52['updated_at'] ?? '');
$last52Raw = (string) hot_setting_get('52pojie_last_fetch', '');
$last52 = $last52Raw !== '' ? json_decode($last52Raw, true) : null;
if (!is_array($last52)) {
    $last52 = null;
}
$cookieHint52 = '';
if ($has52) {
    $c = $cred52['cookie'];
    $cookieHint52 = strlen($c) > 24
        ? (substr($c, 0, 8) . '…' . substr($c, -6) . '（' . strlen($c) . ' 字符）')
        : '已配置（' . strlen($c) . ' 字符）';
}

// Cookie 脱敏展示（不回显完整密钥）
$cookieHint = '';
if ($hasCookie) {
    $c = $cred['cookie'];
    $cookieHint = strlen($c) > 24
        ? (substr($c, 0, 8) . '…' . substr($c, -6) . '（' . strlen($c) . ' 字符）')
        : '已配置（' . strlen($c) . ' 字符）';
}

admin_layout_start('今日热榜', 'hotboards');
?>
<div class="panel">
    <div class="panel-head">
        <div>
            <h2>今日热榜来源</h2>
            <p class="muted">勾选前台要显示的热榜网站，可用上下箭头调整顺序。数据为实时抓取 + 本地缓存，不在此编辑条目内容。</p>
        </div>
    </div>

    <form method="post" class="stack-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_boards">

        <div class="table-wrap">
            <table class="data-table hot-config-table">
                <thead>
                    <tr>
                        <th style="width:70px">显示</th>
                        <th style="width:90px">顺序</th>
                        <th>名称</th>
                        <th>数据来源</th>
                        <th>官网</th>
                    </tr>
                </thead>
                <tbody id="hot-rows">
                    <?php
                    $orderedIds = $enabled;
                    foreach (array_keys($catalog) as $id) {
                        if (!in_array($id, $orderedIds, true)) {
                            $orderedIds[] = $id;
                        }
                    }
                    foreach ($orderedIds as $id):
                        $meta = $catalog[$id];
                        $isOn = in_array($id, $enabled, true);
                        $providerLabel = [
                            'ikunpay' => '聚合接口',
                            'discourse' => 'Discourse API',
                            'v2ex' => 'V2EX API',
                            'discuz' => '页面解析',
                        ][$meta['provider'] ?? ''] ?? ($meta['provider'] ?? '');
                        ?>
                        <tr data-id="<?php echo e($id); ?>" class="<?php echo $isOn ? 'row-on' : 'row-off'; ?>">
                            <td>
                                <input type="hidden" name="order[]" value="<?php echo e($id); ?>">
                                <label class="check-wrap">
                                    <input type="checkbox" name="enabled[]" value="<?php echo e($id); ?>" <?php echo $isOn ? 'checked' : ''; ?>>
                                    <span>启用</span>
                                </label>
                            </td>
                            <td>
                                <div class="order-btns">
                                    <button type="button" class="btn btn-secondary btn-sm" data-move="up" title="上移">↑</button>
                                    <button type="button" class="btn btn-secondary btn-sm" data-move="down" title="下移">↓</button>
                                </div>
                            </td>
                            <td>
                                <strong><?php echo e($meta['short'] ?? $meta['name']); ?></strong>
                                <div class="cell-muted"><?php echo e($meta['name']); ?></div>
                                <?php if ($id === 'linuxdo'): ?>
                                    <div class="cell-muted" style="margin-top:4px;">
                                        <?php if ($hasCookie || $hasApi): ?>
                                            <span class="tag" style="background:#ecfdf5;color:#047857;">已配置登录态</span>
                                        <?php else: ?>
                                            <span class="tag">公开模式</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><span class="tag"><?php echo e($providerLabel); ?></span></td>
                            <td class="cell-muted">
                                <a href="<?php echo e($meta['fallback_url'] ?? '#'); ?>" target="_blank" rel="noopener">
                                    <?php echo e($meta['fallback_url'] ?? ''); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">保存配置</button>
            <span class="muted">默认：微博、吾爱破解、B站、百度、Linux.do</span>
        </div>
    </form>
</div>

<div class="panel" id="linuxdo-auth" style="margin-top:20px;">
    <div class="panel-head">
        <div>
            <h2>Linux.do · OAuth 授权</h2>
            <p class="muted">
                使用官方 <a href="https://connect.linux.do/" target="_blank" rel="noopener">Linux DO Connect</a> OAuth2 授权，
                自动获取用户 <code>api_key</code> 用于拉取登录后热榜。凭证仅存服务端。
            </p>
        </div>
    </div>

    <div class="switch-bar" style="margin-bottom:14px;flex-wrap:wrap;gap:10px;">
        <span>
            凭证：
            <?php if ($hasApi || $hasCookie): ?>
                <strong style="color:#047857;">已就绪</strong>
                <?php if ($hasApi): ?> API Key<?php endif; ?>
                <?php if ($hasCookie): ?> Cookie<?php endif; ?>
            <?php else: ?>
                <strong style="color:#b45309;">未授权</strong>
            <?php endif; ?>
        </span>
        <?php if ($oauthBoundUser !== ''): ?>
            <span class="muted">OAuth 用户：<?php echo e($oauthBoundUser); ?><?php if ($oauthBoundAt !== ''): ?> · <?php echo e($oauthBoundAt); ?><?php endif; ?></span>
        <?php endif; ?>
        <?php if ($authUpdated !== ''): ?>
            <span class="muted">更新于 <?php echo e($authUpdated); ?></span>
        <?php endif; ?>
        <?php if ($lastFetch): ?>
            <span class="muted">
                上次抓取：<?php echo e($lastFetch['at'] ?? ''); ?>
                · <?php echo !empty($lastFetch['auth']) ? '登录态' : '公开'; ?>
                · <?php echo (int) ($lastFetch['count'] ?? 0); ?> 条
            </span>
        <?php endif; ?>
    </div>

    <h3 class="auth-subhead">1. 配置 Connect 应用</h3>
    <form method="post" class="stack-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_linuxdo_oauth_app">
        <label>
            <span>Client ID</span>
            <input type="text" name="linuxdo_client_id" value="<?php echo e($oauthApp['client_id']); ?>" placeholder="在 connect.linux.do 申请后获得" autocomplete="off">
        </label>
        <label>
            <span>Client Secret <?php if ($oauthApp['client_secret'] !== ''): ?><em class="muted">已保存，留空不修改</em><?php endif; ?></span>
            <input type="password" name="linuxdo_client_secret" value="" autocomplete="off" placeholder="<?php echo $oauthApp['client_secret'] !== '' ? '已配置，填写则覆盖' : 'Client Secret'; ?>">
        </label>
        <label>
            <span>回调地址 Redirect URI</span>
            <input type="text" name="linuxdo_redirect_uri" value="<?php echo e($oauthApp['redirect_uri'] !== '' ? $oauthApp['redirect_uri'] : $callbackHint); ?>">
        </label>
        <p class="muted" style="margin:0 0 12px;font-size:0.88rem;line-height:1.6;">
            在 <a href="https://connect.linux.do/" target="_blank" rel="noopener">connect.linux.do</a> → 我的应用接入 → 申请新接入，
            回调填：<code><?php echo e($callbackHint); ?></code>
        </p>
        <div class="form-actions">
            <button type="submit" class="btn btn-secondary">保存应用配置</button>
        </div>
    </form>

    <h3 class="auth-subhead">2. 一键 OAuth 授权</h3>
    <div class="form-actions" style="flex-wrap:wrap;gap:8px;margin-bottom:16px;">
        <?php if (oauth_linuxdo_app_ready()): ?>
            <a class="btn btn-primary" href="oauth_linuxdo.php">使用 Linux.do 账号授权</a>
        <?php else: ?>
            <button type="button" class="btn btn-primary" disabled title="请先保存 Client ID / Secret">使用 Linux.do 账号授权</button>
        <?php endif; ?>
        <form method="post" style="display:inline;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="test_linuxdo">
            <button type="submit" class="btn btn-secondary">测试登录 / 拉取</button>
        </form>
        <form method="post" style="display:inline;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="refresh_linuxdo">
            <button type="submit" class="btn btn-secondary">刷新前台缓存</button>
        </form>
        <form method="post" style="display:inline;" onsubmit="return confirm('确定清除 Linux.do 全部登录凭证？');">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_linuxdo">
            <input type="hidden" name="clear_linuxdo" value="1">
            <button type="submit" class="btn btn-danger">清除凭证</button>
        </form>
    </div>

    <details class="auth-advanced">
        <summary>高级：手动 Cookie / API Key（OAuth 不可用时兜底）</summary>
        <form method="post" class="stack-form" style="margin-top:12px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_linuxdo">
            <label>
                <span>鉴权方式</span>
                <select name="linuxdo_auth_mode">
                    <option value="auto" <?php echo $cred['mode'] === 'auto' ? 'selected' : ''; ?>>自动</option>
                    <option value="cookie" <?php echo $cred['mode'] === 'cookie' ? 'selected' : ''; ?>>仅 Cookie</option>
                    <option value="api" <?php echo $cred['mode'] === 'api' ? 'selected' : ''; ?>>仅 API Key</option>
                    <option value="none" <?php echo $cred['mode'] === 'none' ? 'selected' : ''; ?>>强制公开</option>
                </select>
            </label>
            <label>
                <span>Cookie <?php if ($hasCookie): ?><em class="muted">当前：<?php echo e($cookieHint); ?></em><?php endif; ?></span>
                <textarea name="linuxdo_cookie" rows="3" placeholder="可选：浏览器 Cookie 整段"></textarea>
            </label>
            <label class="switch-label"><input type="checkbox" name="update_cookie" value="1"> <span>更新 Cookie</span></label>
            <label>
                <span>API Key</span>
                <input type="password" name="linuxdo_api_key" value="" autocomplete="off" placeholder="<?php echo $hasApi ? '已配置' : 'Api-Key'; ?>">
            </label>
            <label class="switch-label"><input type="checkbox" name="update_api_key" value="1"> <span>更新 API Key</span></label>
            <label>
                <span>API Username</span>
                <input type="text" name="linuxdo_api_username" value="<?php echo e($cred['api_username'] !== 'system' || $hasApi ? $cred['api_username'] : ''); ?>">
            </label>
            <div class="form-actions">
                <button type="submit" class="btn btn-secondary">保存手动凭证</button>
            </div>
        </form>
    </details>
</div>

<div class="panel" id="52pojie-auth" style="margin-top:20px;">
    <div class="panel-head">
        <div>
            <h2>吾爱破解 · OAuth 式授权</h2>
            <p class="muted">
                吾爱无官方开放平台 OAuth。采用与 OAuth 相同的 <strong>state 防伪授权会话</strong>，
                登录后换取 Cookie 凭证供服务端抓取热榜（仅管理员后台操作）。
            </p>
        </div>
    </div>

    <div class="switch-bar" style="margin-bottom:14px;flex-wrap:wrap;gap:10px;">
        <span>
            凭证：
            <?php if ($has52): ?>
                <strong style="color:#047857;">已授权</strong>
                <?php if ($cred52['username'] !== ''): ?> · <?php echo e($cred52['username']); ?><?php endif; ?>
                <?php if ($cookieHint52 !== ''): ?> · <?php echo e($cookieHint52); ?><?php endif; ?>
            <?php else: ?>
                <strong style="color:#b45309;">未授权</strong>（公开解析）
            <?php endif; ?>
        </span>
        <?php if ($auth52Updated !== ''): ?>
            <span class="muted">更新于 <?php echo e($auth52Updated); ?></span>
        <?php endif; ?>
        <?php if ($last52): ?>
            <span class="muted">
                上次抓取：<?php echo e($last52['at'] ?? ''); ?>
                · <?php echo !empty($last52['auth']) ? '登录态' : '公开'; ?>
                · <?php echo (int) ($last52['count'] ?? 0); ?> 条
            </span>
        <?php endif; ?>
        <?php if (($cred52['source'] ?? '') !== ''): ?>
            <span class="muted">来源：<?php echo e($cred52['source']); ?></span>
        <?php endif; ?>
    </div>

    <div class="form-actions" style="flex-wrap:wrap;gap:8px;">
        <a class="btn btn-primary" href="oauth_52pojie.php?step=start">开始 OAuth 授权</a>
        <form method="post" style="display:inline;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="test_52pojie">
            <button type="submit" class="btn btn-secondary">测试登录</button>
        </form>
        <form method="post" style="display:inline;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="refresh_52pojie">
            <button type="submit" class="btn btn-secondary">刷新前台缓存</button>
        </form>
        <form method="post" style="display:inline;" onsubmit="return confirm('确定清除吾爱登录凭证？');">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="clear_52pojie">
            <button type="submit" class="btn btn-danger">清除凭证</button>
        </form>
    </div>

    <div class="muted" style="margin-top:16px;line-height:1.7;font-size:0.9rem;">
        <strong>流程：</strong>
        点击「开始 OAuth 授权」→ 登录吾爱 → 粘贴 Cookie 完成授权 → 服务端持久化 → 热榜抓取自动带 Cookie。
    </div>
</div>

<style>
.hot-config-table .check-wrap { display:inline-flex; align-items:center; gap:6px; cursor:pointer; user-select:none; }
.hot-config-table .order-btns { display:flex; gap:4px; }
.hot-config-table tr.row-off { opacity: 0.72; }
.hot-config-table tr.row-on td { background: #f8faff; }
#linuxdo-auth code, #52pojie-auth code { background:#f1f5f9; padding:1px 6px; border-radius:4px; font-size:0.85em; }
#linuxdo-auth select,
#linuxdo-auth textarea,
#linuxdo-auth input[type="text"],
#linuxdo-auth input[type="password"] {
    width: 100%;
    max-width: 720px;
    padding: 10px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font: inherit;
}
#linuxdo-auth label > span { display:block; margin-bottom:6px; font-weight:600; }
#linuxdo-auth .stack-form label { display:block; margin-bottom:14px; }
.auth-subhead { font-size:1rem; margin:18px 0 10px; font-weight:750; color:#1e293b; }
.auth-advanced { margin-top:8px; border:1px dashed #cbd5e1; border-radius:10px; padding:10px 14px; background:#f8fafc; }
.auth-advanced summary { cursor:pointer; font-weight:650; color:#475569; }
</style>
<?php admin_layout_end(['assets/hotboards.js']); ?>
