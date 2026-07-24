<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/layout.php';
require_login();

$content = load_content();
$hotBoards = load_hot_boards();
$hotCount = 0;
foreach ($hotBoards as $b) {
    $hotCount += count($b['items'] ?? []);
}

admin_layout_start('概览', 'dashboard');
?>
<div class="stats">
    <div class="stat-card">
        <div class="label">搜索引擎</div>
        <div class="value"><?php echo count($content['engines'] ?? []); ?></div>
    </div>
    <div class="stat-card">
        <div class="label">快捷入口</div>
        <div class="value"><?php echo count($content['shortcuts'] ?? []); ?></div>
    </div>
    <div class="stat-card">
        <div class="label">自营站点</div>
        <div class="value"><?php echo count($content['sites'] ?? []); ?></div>
    </div>
    <div class="stat-card">
        <div class="label">开源项目</div>
        <div class="value"><?php echo count($content['projects'] ?? []); ?></div>
    </div>
</div>

<div class="panel">
    <h2>站点信息</h2>
    <p><strong>名称：</strong><?php echo e($content['site']['name'] ?? ''); ?></p>
    <p><strong>副标题：</strong><?php echo e($content['site']['subtitle'] ?? ''); ?></p>
    <p class="hint" style="margin-bottom:0">今日热榜为<strong>实时接口</strong>数据（本地缓存约 10 分钟，当前缓存条目约 <?php echo (int) $hotCount; ?> 条），不在后台管理范围内。平台列表见 includes/hot_fetcher.php。</p>
    <div class="btn-row">
        <a class="btn btn-primary" href="settings.php">编辑站点设置</a>
        <a class="btn btn-secondary" href="../index.php" target="_blank" rel="noopener">打开前台</a>
    </div>
</div>

<div class="panel">
    <h2>快捷管理</h2>
    <div class="btn-row">
        <a class="btn btn-secondary" href="engines.php">搜索引擎</a>
        <a class="btn btn-secondary" href="shortcuts.php">快捷入口</a>
        <a class="btn btn-secondary" href="sites.php">自营站点</a>
        <a class="btn btn-secondary" href="projects.php">开源项目</a>
        <a class="btn btn-secondary" href="password.php">修改密码</a>
    </div>
</div>
<?php admin_layout_end(); ?>
