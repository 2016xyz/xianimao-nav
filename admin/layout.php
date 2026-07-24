<?php
/**
 * 后台布局：start / end
 * 使用前需定义 $adminTitle、$adminPage（当前菜单标识）
 */
if (!defined('ROOT_PATH')) {
    require_once __DIR__ . '/../includes/bootstrap.php';
}

function admin_layout_start($title, $page = '')
{
    $siteName = '管理后台';
    $flash = flash_get();
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
    <div class="admin-shell">
        <aside class="admin-sidebar">
            <div class="admin-brand">
                <span class="brand-mark">彩</span>
                <div>
                    <strong>导航管理</strong>
                    <small>内容后台</small>
                </div>
            </div>
            <nav class="admin-nav">
                <a href="index.php" class="<?php echo $page === 'dashboard' ? 'active' : ''; ?>">概览</a>
                <a href="settings.php" class="<?php echo $page === 'settings' ? 'active' : ''; ?>">站点设置</a>
                <a href="engines.php" class="<?php echo $page === 'engines' ? 'active' : ''; ?>">搜索引擎</a>
                <a href="shortcuts.php" class="<?php echo $page === 'shortcuts' ? 'active' : ''; ?>">快捷入口</a>
                <a href="sites.php" class="<?php echo $page === 'sites' ? 'active' : ''; ?>">自营站点</a>
                <a href="projects.php" class="<?php echo $page === 'projects' ? 'active' : ''; ?>">开源项目</a>
                <a href="tools.php" class="<?php echo $page === 'tools' ? 'active' : ''; ?>">实用工具</a>
                <a href="links.php" class="<?php echo $page === 'links' ? 'active' : ''; ?>">友情链接</a>
                <a href="hotboards.php" class="<?php echo $page === 'hotboards' ? 'active' : ''; ?>">今日热榜</a>
                <a href="messages.php" class="<?php echo $page === 'messages' ? 'active' : ''; ?>">留言管理</a>
                <a href="ai.php" class="<?php echo $page === 'ai' ? 'active' : ''; ?>">AI 配置</a>
                <a href="smtp.php" class="<?php echo $page === 'smtp' ? 'active' : ''; ?>">SMTP / 登录验证</a>
                <a href="password.php" class="<?php echo $page === 'password' ? 'active' : ''; ?>">修改密码</a>
            </nav>
            <div class="admin-sidebar-footer">
                <a href="../index.php" target="_blank" rel="noopener">查看前台</a>
                <form method="post" action="logout.php" style="display:inline;margin:0;">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="admin-logout-btn">退出登录</button>
                </form>
            </div>
        </aside>
        <div class="admin-main">
            <header class="admin-topbar">
                <h1><?php echo e($title); ?></h1>
            </header>
            <div class="admin-content">
                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo e($flash['type']); ?>">
                        <?php echo e($flash['message']); ?>
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
    <?php foreach ($extraScripts as $src): ?>
        <script src="<?php echo e($src); ?>"></script>
    <?php endforeach; ?>
    <script src="assets/admin.js"></script>
</body>
</html>
    <?php
}
