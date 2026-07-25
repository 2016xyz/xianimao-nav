<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/page_layout.php';

$data = load_site_data();
$GLOBALS['data'] = $data;
$site = $data['site'] ?? [];
$siteName = function_exists('site_brand_name') ? site_brand_name($site) : ($site['name'] ?? '网址导航');
$subtitle = trim((string) ($site['subtitle'] ?? ''));
$about = trim((string) ($site['about_html'] ?? ''));
if ($about === '') {
    $about = '<p>' . e($siteName) . '汇集实用工具、开源项目与优质站点，帮助你更快找到需要的资源。</p>';
}

page_layout_start('关于我们', [
    'page_key' => 'about',
    'icon' => 'bi-info-circle',
    'tone' => 'indigo',
    'lead' => $subtitle !== '' ? $subtitle : '了解本站定位、内容与愿景。',
]);
?>
<div class="about-layout">
    <div class="about-intro">
        <div class="about-badge"><i class="bi bi-stars"></i> <?php echo e($siteName); ?></div>
        <div class="subpage-prose about-prose">
            <?php
            $aboutSafe = sanitize_admin_html($about);
            if (strip_tags($aboutSafe) !== $aboutSafe) {
                echo $aboutSafe;
            } else {
                echo nl2br(e($aboutSafe !== '' ? $aboutSafe : $about));
            }
            ?>
        </div>
    </div>

    <div class="feature-grid">
        <div class="feature-card">
            <div class="feature-icon teal"><i class="bi bi-collection"></i></div>
            <h3>精选导航</h3>
            <p>汇集自营站点、开源项目与实用工具，一站直达，减少无效搜索。</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon violet"><i class="bi bi-fire"></i></div>
            <h3>今日热榜</h3>
            <p>同步多平台热点，用清爽卡片快速扫到当下关注点。</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon amber"><i class="bi bi-link-45deg"></i></div>
            <h3>友链共建</h3>
            <p>欢迎优质站点申请收录，一起扩大互相发现的半径。</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon indigo"><i class="bi bi-heart"></i></div>
            <h3>持续打磨</h3>
            <p>注重信任感与阅读舒适度，让导航更安心、更好用。</p>
        </div>
    </div>

    <div class="about-cta-row">
        <a class="btn-primary-lg" href="apply.php"><i class="bi bi-plus-circle"></i> 申请收录</a>
        <a class="btn-ghost-lg" href="message.php"><i class="bi bi-chat-left-text"></i> 在线留言</a>
        <a class="btn-ghost-lg" href="contact.php"><i class="bi bi-envelope"></i> 联系我们</a>
    </div>

    <div class="about-footer-meta">
        <span><i class="bi bi-globe2"></i> <?php echo e($siteName); ?></span>
        <a class="side-link" href="index.php">返回首页 <i class="bi bi-arrow-right"></i></a>
    </div>
</div>
<?php page_layout_end(); ?>
