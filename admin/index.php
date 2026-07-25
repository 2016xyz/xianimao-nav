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

$stats = [
    ['label' => '搜索引擎', 'value' => count($content['engines'] ?? []), 'href' => 'engines.php', 'tone' => 'indigo', 'icon' => 'search'],
    ['label' => '快捷入口', 'value' => count($content['shortcuts'] ?? []), 'href' => 'shortcuts.php', 'tone' => 'teal', 'icon' => 'zap'],
    ['label' => '自营站点', 'value' => count($content['sites'] ?? []), 'href' => 'sites.php', 'tone' => 'violet', 'icon' => 'globe'],
    ['label' => '开源项目', 'value' => count($content['projects'] ?? []), 'href' => 'projects.php', 'tone' => 'amber', 'icon' => 'code'],
    ['label' => '实用工具', 'value' => count($content['tools'] ?? []), 'href' => 'tools.php', 'tone' => 'sky', 'icon' => 'tool'],
    ['label' => '友情链接', 'value' => count($content['links'] ?? []), 'href' => 'links.php', 'tone' => 'rose', 'icon' => 'link'],
];

admin_layout_start('概览', 'dashboard');
?>
<div class="dash-hero panel panel-accent">
    <div class="dash-hero-text">
        <p class="dash-kicker">Dashboard</p>
        <h2 class="dash-title"><?php echo e(site_brand_name($content['site'] ?? null)); ?></h2>
        <p class="dash-sub muted"><?php echo e($content['site']['subtitle'] ?? '管理站点内容、热榜授权与系统配置'); ?></p>
        <div class="btn-row">
            <a class="btn btn-primary" href="settings.php"><span class="btn-ico">✎</span> 编辑站点设置</a>
            <a class="btn btn-secondary" href="../index.php" target="_blank" rel="noopener"><span class="btn-ico">↗</span> 打开前台</a>
            <a class="btn btn-soft" href="hotboards.php"><span class="btn-ico">🔥</span> 热榜授权</a>
        </div>
    </div>
    <div class="dash-hero-meta">
        <div class="dash-meta-card">
            <span class="dash-meta-label">热榜缓存条目</span>
            <strong class="dash-meta-value"><?php echo (int) $hotCount; ?></strong>
            <span class="dash-meta-hint">约 10 分钟本地缓存</span>
        </div>
    </div>
</div>

<div class="stats stats-vue">
    <?php foreach ($stats as $s): ?>
        <a class="stat-card tone-<?php echo e($s['tone']); ?>" href="<?php echo e($s['href']); ?>">
            <div class="stat-card-top">
                <span class="stat-icon" data-icon="<?php echo e($s['icon']); ?>" aria-hidden="true"></span>
                <span class="stat-arrow" aria-hidden="true">→</span>
            </div>
            <div class="label"><?php echo e($s['label']); ?></div>
            <div class="value"><?php echo (int) $s['value']; ?></div>
        </a>
    <?php endforeach; ?>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>快捷管理</h2>
            <p class="muted">常用入口一键直达</p>
        </div>
    </div>
    <div class="btn-row dash-quick">
        <a class="btn btn-secondary" href="engines.php"><span class="btn-ico" aria-hidden="true">⌕</span> 搜索引擎</a>
        <a class="btn btn-secondary" href="shortcuts.php"><span class="btn-ico" aria-hidden="true">⚡</span> 快捷入口</a>
        <a class="btn btn-secondary" href="sites.php"><span class="btn-ico" aria-hidden="true">◎</span> 自营站点</a>
        <a class="btn btn-secondary" href="projects.php"><span class="btn-ico" aria-hidden="true">&lt;/&gt;</span> 开源项目</a>
        <a class="btn btn-secondary" href="tools.php"><span class="btn-ico" aria-hidden="true">⚒</span> 实用工具</a>
        <a class="btn btn-secondary" href="links.php"><span class="btn-ico" aria-hidden="true">⛓</span> 友情链接</a>
        <a class="btn btn-soft" href="messages.php"><span class="btn-ico" aria-hidden="true">💬</span> 留言管理</a>
        <a class="btn btn-soft" href="smtp.php"><span class="btn-ico" aria-hidden="true">✉</span> SMTP</a>
        <a class="btn btn-soft" href="ai.php"><span class="btn-ico" aria-hidden="true">✦</span> AI 配置</a>
        <a class="btn btn-ghost" href="password.php"><span class="btn-ico" aria-hidden="true">🔒</span> 修改密码</a>
        <a class="btn btn-ghost" href="logs.php"><span class="btn-ico" aria-hidden="true">☰</span> 操作日志</a>
        <a class="btn btn-ghost" href="update.php"><span class="btn-ico" aria-hidden="true">↻</span> 系统更新</a>
    </div>
</div>

<div class="panel panel-soft">
    <h2>说明</h2>
    <p class="hint" style="margin-bottom:0">今日热榜为<strong>实时接口</strong>数据（本地缓存约 10 分钟），平台列表见 <code>includes/hot_fetcher.php</code>。后台 UI 由 Vue 3 增强交互，业务逻辑仍为 PHP 服务端渲染。</p>
</div>
<?php admin_layout_end(); ?>
