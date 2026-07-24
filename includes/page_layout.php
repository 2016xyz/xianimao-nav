<?php
/**
 * 前台独立页（申请收录 / 留言 / 关于 / 联系）共用布局
 *
 * @param string $pageTitle 页面标题
 * @param array  $opts      icon|lead|page_key|tone
 */
function page_layout_start($pageTitle, array $opts = [])
{
    if (!isset($GLOBALS['data'])) {
        require_once __DIR__ . '/bootstrap.php';
        $GLOBALS['data'] = load_site_data();
    }
    $data = $GLOBALS['data'];
    $site = $data['site'] ?? [];
    $siteName = $site['name'] ?? '夏尼猫网址导航';
    $vis = footer_builtin_visibility($site);

    $icon = $opts['icon'] ?? 'bi-file-earmark-text';
    $lead = $opts['lead'] ?? '';
    $pageKey = $opts['page_key'] ?? '';
    $tone = $opts['tone'] ?? 'indigo'; // indigo | teal | violet | amber
    $seoTitle = $pageTitle . ' - ' . $siteName;
    $seoDesc = $lead !== '' ? $lead : ($pageTitle . ' - ' . $siteName);

    $navItems = [
        ['key' => 'home', 'href' => 'index.php', 'label' => '首页', 'icon' => 'bi-house', 'show' => true],
        ['key' => 'apply', 'href' => 'apply.php', 'label' => '申请收录', 'icon' => 'bi-plus-circle', 'show' => !empty($vis['apply'])],
        ['key' => 'message', 'href' => 'message.php', 'label' => '在线留言', 'icon' => 'bi-chat-left-text', 'show' => !empty($vis['message'])],
        ['key' => 'about', 'href' => 'about.php', 'label' => '关于我们', 'icon' => 'bi-info-circle', 'show' => !empty($vis['about'])],
        ['key' => 'contact', 'href' => 'contact.php', 'label' => '联系我们', 'icon' => 'bi-envelope', 'show' => !empty($vis['contact'])],
    ];
    ?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    render_seo_head($site, [
        'title' => $seoTitle,
        'description' => $seoDesc,
    ]);
    ?>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/theme-init.js"></script>
</head>
<body class="subpage subpage-tone-<?php echo e($tone); ?>">
<header class="subpage-header">
    <div class="container subpage-header-inner">
        <a class="subpage-brand" href="index.php">
            <span class="subpage-brand-icon" aria-hidden="true"><i class="bi bi-compass"></i></span>
            <span class="subpage-brand-text">
                <span class="subpage-brand-name"><?php echo e($siteName); ?></span>
                <span class="subpage-brand-tag">导航门户</span>
            </span>
        </a>

        <div class="subpage-header-actions">
            <button class="theme-toggle subpage-theme" id="themeToggle" title="切换主题" type="button" aria-label="切换主题">
                <i class="bi bi-sun-fill" id="themeIcon"></i>
            </button>
            <button class="subpage-nav-toggle" id="subpageNavToggle" type="button"
                    aria-expanded="false" aria-controls="subpageNav" aria-label="打开菜单">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>
        </div>

        <nav class="subpage-nav" id="subpageNav" aria-label="页面导航">
            <?php foreach ($navItems as $item): ?>
                <?php if (empty($item['show'])) {
                    continue;
                } ?>
                <a href="<?php echo e($item['href']); ?>"
                   class="<?php echo $pageKey === $item['key'] ? 'is-active' : ''; ?>">
                    <i class="bi <?php echo e($item['icon']); ?>" aria-hidden="true"></i>
                    <span><?php echo e($item['label']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
</header>

<main class="subpage-main">
    <div class="container subpage-shell">
        <div class="subpage-hero">
            <div class="subpage-hero-icon" aria-hidden="true">
                <i class="bi <?php echo e($icon); ?>"></i>
            </div>
            <div class="subpage-hero-text">
                <p class="subpage-kicker"><?php echo e($siteName); ?></p>
                <h1 class="subpage-title"><?php echo e($pageTitle); ?></h1>
                <?php if ($lead !== ''): ?>
                    <p class="subpage-lead"><?php echo e($lead); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="subpage-card">
    <?php
}

function page_layout_end()
{
    $data = $GLOBALS['data'] ?? load_site_data();
    ?>
        </div>
    </div>
</main>
<script src="assets/js/subpage.js"></script>
<?php
    require __DIR__ . '/footer.php';
}
