<?php
/**
 * 夏尼猫网址导航 - 首页
 */
require_once __DIR__ . '/includes/bootstrap.php';
$data = load_site_data();
$site = $data['site'] ?? [];
$engines = $data['engines'] ?? [];
$shortcuts = $data['shortcuts'] ?? [];
$hotBoards = $data['hot_boards'] ?? [];
$sites = $data['sites'] ?? [];
$projects = $data['projects'] ?? [];
$tools = $data['tools'] ?? [];
$links = $data['links'] ?? [];
$showFriendLinks = !isset($site['show_friend_links']) || ($site['show_friend_links'] !== '0' && $site['show_friend_links'] !== 0 && $site['show_friend_links'] !== false);

// 搜索 Tab：引擎 + 快捷搜索（与参考站一致：百度/谷歌/搜狗/知乎/Github/音乐/图书/影视）
$searchTabs = [];
foreach ($engines as $engine) {
    $searchTabs[] = [
        'name' => $engine['name'] ?? '',
        'url' => $engine['url'] ?? '',
        'icon' => search_tab_icon($engine['id'] ?? $engine['name'] ?? ''),
        'placeholder' => '输入关键词，使用' . ($engine['name'] ?? '搜索') . '搜索',
    ];
}
foreach ($shortcuts as $sc) {
    if (($sc['type'] ?? 'search') !== 'search') {
        continue;
    }
    $searchTabs[] = [
        'name' => $sc['name'] ?? '',
        'url' => $sc['url'] ?? '',
        'icon' => search_tab_icon($sc['name'] ?? ''),
        'placeholder' => '输入关键词，搜索' . ($sc['name'] ?? ''),
    ];
}

// 热榜展示元信息（与目录一致，并兼容接口返回字段）
$hotMetaMap = [];
if (function_exists('hot_board_catalog')) {
    foreach (hot_board_catalog() as $id => $meta) {
        $hotMetaMap[$id] = [
            'short' => $meta['short'] ?? $meta['name'] ?? $id,
            'label' => $meta['label'] ?? '',
            'logo' => $meta['logo'] ?? '',
        ];
    }
} else {
    $hotMetaMap = [
        'weibo' => ['short' => '微博', 'label' => '热搜榜', 'logo' => 'assets/images/weibo.png'],
        '52pojie' => ['short' => '吾爱破解', 'label' => '今日热帖', 'logo' => 'assets/images/52pojie.png'],
        'baidu' => ['short' => '百度', 'label' => '热点', 'logo' => 'assets/images/baidu.png'],
        'bilibili' => ['short' => '哔哩哔哩', 'label' => '全站日榜', 'logo' => 'assets/images/bilibili.png'],
        'linuxdo' => ['short' => 'Linux.do', 'label' => '最新/热门', 'logo' => ''],
        'v2ex' => ['short' => 'V2EX', 'label' => '热议', 'logo' => ''],
        'zhihu' => ['short' => '知乎', 'label' => '热榜', 'logo' => 'assets/images/zhihu.png'],
    ];
}

require __DIR__ . '/includes/header.php';
?>

<!-- 顶部背景区域 -->
<?php $heroBgUrl = site_hero_bg_url($site); ?>
<header class="hero-section" style="--hero-bg-image: url('<?php echo e($heroBgUrl); ?>');">
    <div class="hero-overlay"></div>
    <button class="theme-toggle" id="themeToggle" title="切换白天/黑夜模式" type="button">
        <i class="bi bi-sun-fill" id="themeIcon"></i>
    </button>
    <div class="container position-relative" style="z-index:2;">
        <h1 class="hero-title"><?php echo e($site['name'] ?? '夏尼猫网址导航'); ?></h1>
        <p class="hero-subtitle"><?php echo e($site['subtitle'] ?? '实用工具与优质站点聚合'); ?></p>

        <div class="search-box mx-auto">
            <div class="search-tabs" id="searchTabs">
                <?php foreach ($searchTabs as $i => $tab): ?>
                    <button
                        type="button"
                        class="search-tab<?php echo $i === 0 ? ' active' : ''; ?>"
                        data-url="<?php echo e($tab['url']); ?>"
                        data-placeholder="<?php echo e($tab['placeholder']); ?>"
                    >
                        <?php if (!empty($tab['icon'])): ?>
                            <img class="search-tab-icon" src="<?php echo e($tab['icon']); ?>" alt="">
                        <?php endif; ?>
                        <?php echo e($tab['name']); ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <form class="search-form" id="searchForm">
                <div class="input-group">
                    <input
                        type="text"
                        class="form-control search-input"
                        id="searchInput"
                        placeholder="<?php echo e($searchTabs[0]['placeholder'] ?? '输入关键词搜索'); ?>"
                        autocomplete="off"
                    >
                    <button class="btn search-btn" type="submit">
                        <i class="bi bi-search"></i> <span>搜索</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</header>

<!-- 今日热榜：横向多卡片 -->
<section class="hot-section py-1">
    <div class="container">
        <h2 class="section-title"><i class="bi bi-fire"></i> 今日热榜</h2>
        <div class="hot-list-wrapper" id="hotListWrapper">
            <div class="hot-list-scroll">
                <?php foreach ($hotBoards as $board): ?>
                    <?php
                    $bid = $board['id'] ?? '';
                    $meta = $hotMetaMap[$bid] ?? [];
                    $short = $board['short'] ?? ($meta['short'] ?? ($board['name'] ?? $bid));
                    $label = $board['label'] ?? ($meta['label'] ?? '');
                    $logo = $board['logo'] ?? ($meta['logo'] ?? '');
                    if ($logo === '' && is_file(__DIR__ . '/assets/images/' . $bid . '.png')) {
                        $logo = 'assets/images/' . $bid . '.png';
                    }
                    // linux.do / v2ex 用 favicon 服务
                    if ($logo === '' && in_array($bid, ['linuxdo', 'v2ex', 'sspai'], true)) {
                        $fall = $board['fallback_url'] ?? '';
                        if ($fall !== '') {
                            $logo = site_favicon_url($fall);
                        }
                    }
                    $targetId = 'hotList_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $bid);
                    ?>
                    <div class="hot-card" data-board="<?php echo e($bid); ?>" data-target="<?php echo e($targetId); ?>">
                        <div class="hot-card-header">
                            <?php if ($logo !== ''): ?>
                                <img class="hot-card-logo" src="<?php echo e($logo); ?>" alt="" onerror="this.style.display='none'">
                            <?php else: ?>
                                <i class="bi bi-fire hot-card-logo" style="font-size:1rem;width:auto;height:auto;"></i>
                            <?php endif; ?>
                            <span class="hot-card-name"><?php echo e($short); ?></span>
                            <?php if ($label !== ''): ?>
                                <span class="hot-card-label"><?php echo e($label); ?></span>
                            <?php endif; ?>
                            <button class="hot-refresh-btn" type="button" title="刷新"><i class="bi bi-arrow-clockwise"></i></button>
                        </div>
                        <div class="hot-card-body" id="<?php echo e($targetId); ?>">
                            <?php if (empty($board['items'])): ?>
                                <div class="hot-empty">
                                    暂时无法获取
                                    <?php if (!empty($board['fallback_url'])): ?>
                                        · <a href="<?php echo e($board['fallback_url']); ?>" target="_blank" rel="noopener noreferrer">官网</a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <?php foreach ($board['items'] as $item): ?>
                                    <?php
                                    $rank = (int) ($item['rank'] ?? 0);
                                    $rankClass = $rank >= 1 && $rank <= 3 ? ' hot-rank top' . $rank : ' hot-rank';
                                    ?>
                                    <div class="hot-item" data-url="<?php echo e($item['url'] ?? '#'); ?>">
                                        <span class="<?php echo trim($rankClass); ?>"><?php echo $rank; ?></span>
                                        <span class="hot-text"><?php echo e($item['title'] ?? ''); ?></span>
                                        <span class="hot-heat"><?php echo e($item['heat'] ?? ''); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- 网站导航分类 -->
<section class="nav-section">
    <div class="container">
        <?php if (!empty($sites)): ?>
        <div class="nav-category sites-category">
            <h2 class="section-title">
                <i class="bi bi-stars" style="color:var(--teal)"></i>
                自营站点
                <span class="title-badge"><?php echo count($sites); ?> 个站点</span>
            </h2>
            <div class="sites-grid">
                <?php foreach ($sites as $siteItem): ?>
                    <a
                        href="<?php echo e($siteItem['url'] ?? '#'); ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="site-card site-card-feature"
                        data-bs-toggle="tooltip"
                        data-bs-placement="top"
                        title="<?php echo e($siteItem['desc'] ?? ''); ?>"
                    >
                        <div class="card-top">
                            <div class="icon-wrap">
                                <img
                                    class="site-icon"
                                    src="<?php echo e(site_favicon_url($siteItem['url'] ?? '')); ?>"
                                    alt=""
                                    loading="lazy"
                                    onerror="this.src='assets/images/github.png'"
                                >
                            </div>
                            <div class="site-info">
                                <div class="site-name"><?php echo e($siteItem['name'] ?? ''); ?></div>
                            </div>
                        </div>
                        <div class="site-desc"><?php echo e($siteItem['desc'] ?? '精彩站点，点击访问'); ?></div>
                        <div class="card-footer">
                            <?php if (!empty($siteItem['tag'])): ?>
                                <span class="card-tag"><?php echo e($siteItem['tag']); ?></span>
                            <?php else: ?>
                                <span class="card-tag-spacer"></span>
                            <?php endif; ?>
                            <i class="bi bi-arrow-up-right card-arrow" aria-hidden="true"></i>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($projects)): ?>
        <div class="nav-category projects-category">
            <h2 class="section-title">
                <i class="bi bi-github" style="color:var(--violet)"></i>
                开源项目
                <span class="title-badge"><?php echo count($projects); ?> 个项目</span>
            </h2>
            <div class="projects-grid">
                <?php foreach ($projects as $project): ?>
                    <a
                        href="<?php echo e($project['url'] ?? '#'); ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="site-card site-card-project"
                        data-bs-toggle="tooltip"
                        data-bs-placement="top"
                        title="<?php echo e($project['desc'] ?? ''); ?>"
                    >
                        <div class="card-top">
                            <div class="icon-wrap">
                                <img
                                    class="site-icon"
                                    src="<?php echo e(site_favicon_url($project['url'] ?? '')); ?>"
                                    alt=""
                                    loading="lazy"
                                    onerror="this.src='assets/images/github.png'"
                                >
                            </div>
                            <div class="site-info">
                                <div class="site-name"><?php echo e($project['name'] ?? ''); ?></div>
                            </div>
                        </div>
                        <div class="site-desc"><?php echo e($project['desc'] ?? '开源项目，欢迎 Star'); ?></div>
                        <div class="card-footer">
                            <span class="card-tag"><i class="bi bi-code-slash"></i> Open Source</span>
                            <i class="bi bi-arrow-up-right card-arrow" aria-hidden="true"></i>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($tools)): ?>
        <div class="nav-category tools-category">
            <h2 class="section-title">
                <i class="bi bi-tools" style="color:var(--accent)"></i>
                实用工具
                <span class="title-badge"><?php echo count($tools); ?> 个工具</span>
            </h2>
            <div class="tools-grid">
                <?php foreach ($tools as $tool): ?>
                    <a
                        href="<?php echo e($tool['url'] ?? '#'); ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="site-card site-card-sm"
                        data-bs-toggle="tooltip"
                        data-bs-placement="top"
                        title="<?php echo e($tool['desc'] ?? ''); ?>"
                    >
                        <img
                            class="site-icon"
                            src="<?php echo e(site_favicon_url($tool['url'] ?? '')); ?>"
                            alt=""
                            loading="lazy"
                            onerror="this.style.visibility='hidden'"
                        >
                        <span class="site-name"><?php echo e($tool['name'] ?? ''); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($showFriendLinks && !empty($links)): ?>
        <div class="nav-category links-category">
            <h2 class="section-title">
                <i class="bi bi-link-45deg" style="color:#0ea5e9"></i>
                友情链接
                <span class="title-badge"><?php echo count($links); ?> 个链接</span>
            </h2>
            <div class="links-grid">
                <?php foreach ($links as $link): ?>
                    <a
                        href="<?php echo e($link['url'] ?? '#'); ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="site-card site-card-sm"
                        data-bs-toggle="tooltip"
                        data-bs-placement="top"
                        title="<?php echo e($link['desc'] ?? ''); ?>"
                    >
                        <img
                            class="site-icon"
                            src="<?php echo e(site_favicon_url($link['url'] ?? '')); ?>"
                            alt=""
                            loading="lazy"
                            onerror="this.style.visibility='hidden'"
                        >
                        <span class="site-name"><?php echo e($link['name'] ?? ''); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
