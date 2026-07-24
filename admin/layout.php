<?php
/**
 * 后台布局：start / end
 * Vue 3 增强壳层交互与按钮；业务内容仍为 PHP SSR（不进入 Vue 模板）
 */
if (!defined('ROOT_PATH')) {
    require_once __DIR__ . '/../includes/bootstrap.php';
}

function admin_layout_start($title, $page = '')
{
    $siteName = '管理后台';
    $flash = flash_get();
    $adminMenus = [
        ['page' => 'dashboard', 'href' => 'index.php', 'label' => '概览', 'short' => '概', 'icon' => 'home'],
        ['page' => 'settings', 'href' => 'settings.php', 'label' => '站点设置', 'short' => '站', 'icon' => 'settings'],
        ['page' => 'engines', 'href' => 'engines.php', 'label' => '搜索引擎', 'short' => '搜', 'icon' => 'search'],
        ['page' => 'shortcuts', 'href' => 'shortcuts.php', 'label' => '快捷入口', 'short' => '快', 'icon' => 'zap'],
        ['page' => 'sites', 'href' => 'sites.php', 'label' => '自营站点', 'short' => '营', 'icon' => 'globe'],
        ['page' => 'projects', 'href' => 'projects.php', 'label' => '开源项目', 'short' => '项', 'icon' => 'code'],
        ['page' => 'tools', 'href' => 'tools.php', 'label' => '实用工具', 'short' => '工', 'icon' => 'tool'],
        ['page' => 'links', 'href' => 'links.php', 'label' => '友情链接', 'short' => '链', 'icon' => 'link'],
        ['page' => 'hotboards', 'href' => 'hotboards.php', 'label' => '今日热榜', 'short' => '热', 'icon' => 'fire'],
        ['page' => 'messages', 'href' => 'messages.php', 'label' => '留言管理', 'short' => '留', 'icon' => 'chat'],
        ['page' => 'ai', 'href' => 'ai.php', 'label' => 'AI 配置', 'short' => 'AI', 'icon' => 'spark'],
        ['page' => 'smtp', 'href' => 'smtp.php', 'label' => 'SMTP / 登录验证', 'short' => '邮', 'icon' => 'mail'],
        ['page' => 'logs', 'href' => 'logs.php', 'label' => '操作日志', 'short' => '志', 'icon' => 'list'],
        ['page' => 'update', 'href' => 'update.php', 'label' => '系统更新', 'short' => '更', 'icon' => 'refresh'],
        ['page' => 'password', 'href' => 'password.php', 'label' => '修改密码', 'short' => '密', 'icon' => 'lock'],
    ];
    $username = (string) ($_SESSION['admin_username'] ?? '管理员');
    if ($username === '') {
        $userInitial = '管';
    } elseif (function_exists('mb_substr')) {
        $userInitial = mb_substr($username, 0, 1, 'UTF-8');
    } else {
        $userInitial = substr($username, 0, 1);
    }
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($title); ?> - <?php echo e($siteName); ?></title>
    <link rel="stylesheet" href="assets/admin.css">
    <meta name="referrer" content="strict-origin-when-cross-origin">
</head>
<body class="admin-body">
    <script type="application/json" id="admin-boot">
<?php
    echo json_encode([
        'pageTitle' => $title,
        'username' => $username,
        'page' => $page,
        'hasFlash' => (bool) $flash,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
?>
    </script>

    <div class="admin-shell" id="adminShell">
        <header class="admin-topbar">
            <button type="button" class="admin-nav-toggle" id="adminNavToggle"
                    aria-expanded="false" aria-controls="adminSidebar" title="折叠 / 展开菜单">
                <span class="admin-nav-toggle-bars" aria-hidden="true">
                    <span></span><span></span><span></span>
                </span>
                <span class="admin-nav-toggle-label" id="adminNavToggleLabel">菜单</span>
            </button>
            <div class="admin-topbar-title">
                <p class="admin-topbar-kicker">夏尼猫 · 管理后台</p>
                <h1><?php echo e($title); ?></h1>
            </div>
            <div class="admin-topbar-actions">
                <span class="admin-user-chip" title="当前登录">
                    <span class="admin-user-avatar"><?php echo e($userInitial); ?></span>
                    <span class="admin-user-name"><?php echo e($username); ?></span>
                </span>
                <a class="btn btn-ghost btn-sm" href="../index.php" target="_blank" rel="noopener">
                    <span class="btn-ico" aria-hidden="true">↗</span> 前台
                </a>
            </div>
        </header>

        <div class="admin-body-row">
            <aside class="admin-sidebar" id="adminSidebar">
                <div class="admin-brand">
                    <span class="brand-mark">夏</span>
                    <div class="admin-brand-text">
                        <strong>导航管理</strong>
                        <small>Content Admin</small>
                    </div>
                </div>
                <nav class="admin-nav" id="adminNav" aria-label="后台菜单">
                    <?php foreach ($adminMenus as $m): ?>
                        <a href="<?php echo e($m['href']); ?>"
                           class="admin-nav-item <?php echo $page === $m['page'] ? 'active' : ''; ?>"
                           data-short="<?php echo e($m['short']); ?>"
                           data-icon="<?php echo e($m['icon']); ?>"
                           title="<?php echo e($m['label']); ?>">
                            <span class="admin-nav-ico" data-icon="<?php echo e($m['icon']); ?>" aria-hidden="true"></span>
                            <span class="admin-nav-label"><?php echo e($m['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="admin-sidebar-footer">
                    <a class="admin-footer-link" href="../index.php" target="_blank" rel="noopener">查看前台</a>
                    <form method="post" action="logout.php" class="admin-logout-form">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="btn btn-danger-soft btn-block btn-sm">退出登录</button>
                    </form>
                </div>
            </aside>
            <div class="admin-main">
                <div class="admin-content admin-page-enter" id="adminContent">
                    <?php if ($flash): ?>
                        <?php $flashType = (($flash['type'] ?? '') === 'error') ? 'error' : (string) ($flash['type'] ?? 'success'); ?>
                        <div class="alert alert-<?php echo e($flashType); ?>" id="adminFlash" role="alert">
                            <span class="alert-ico" aria-hidden="true"><?php echo $flashType === 'error' ? '!' : '✓'; ?></span>
                            <span class="alert-text"><?php echo e($flash['message']); ?></span>
                            <button type="button" class="alert-close" data-flash-close aria-label="关闭">×</button>
                        </div>
                    <?php endif; ?>
    <?php
}

function admin_layout_end(array $extraScripts = [])
{
    ?>
                </div>
            </div>
        </div>
    </div>
    <div id="admin-vue-root" hidden aria-hidden="true"></div>
    <script src="assets/vendor/vue.global.prod.js"></script>
    <script src="assets/admin-vue.js"></script>
    <?php foreach ($extraScripts as $src): ?>
        <script src="<?php echo e($src); ?>"></script>
    <?php endforeach; ?>
    <script src="assets/admin.js"></script>
</body>
</html>
    <?php
}
