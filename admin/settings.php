<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/layout.php';
require_login();

$content = load_content();
$site = $content['site'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('error', '无效请求，请重试');
        redirect('settings.php');
    }
    $footerLinks = [];
    $names = $_POST['footer_link_name'] ?? [];
    $urls = $_POST['footer_link_url'] ?? [];
    if (is_array($names) && is_array($urls)) {
        $n = min(count($names), count($urls), 30);
        for ($i = 0; $i < $n; $i++) {
            $footerLinks[] = [
                'name' => $names[$i] ?? '',
                'url' => $urls[$i] ?? '',
            ];
        }
    }
    $footerLinks = normalize_footer_links($footerLinks);

    $seoRobots = site_seo_normalize_robots($_POST['seo_robots'] ?? 'index,follow');
    $seoCanonical = safe_http_url(trim((string) ($_POST['seo_canonical'] ?? '')), false);
    $seoOgImage = safe_http_url(trim((string) ($_POST['seo_og_image'] ?? '')), false);
    $seoBaidu = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($_POST['seo_baidu_verify'] ?? ''));
    $seoGoogle = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($_POST['seo_google_verify'] ?? ''));
    $seoBing = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($_POST['seo_bing_verify'] ?? ''));

    // Hero 背景：优先上传 → 外链 URL → 保留原值；可恢复默认
    $prevHero = site_hero_bg_normalize($site['hero_bg'] ?? '');
    $heroBg = $prevHero;
    $resetHero = !empty($_POST['hero_bg_reset']);
    $uploadResult = site_hero_bg_handle_upload($_FILES['hero_bg_file'] ?? []);
    if (!$uploadResult['ok']) {
        flash_set('error', $uploadResult['error'] ?? '背景图上传失败');
        redirect('settings.php');
    }
    $uploadedPath = (string) ($uploadResult['path'] ?? '');
    if ($resetHero) {
        if (site_hero_bg_is_upload($prevHero)) {
            site_hero_bg_delete_file($prevHero);
        }
        $heroBg = '';
    } elseif ($uploadedPath !== '') {
        if (site_hero_bg_is_upload($prevHero) && $prevHero !== $uploadedPath) {
            site_hero_bg_delete_file($prevHero);
        }
        $heroBg = $uploadedPath;
    } else {
        $urlInput = trim((string) ($_POST['hero_bg_url'] ?? ''));
        if ($urlInput !== '') {
            $norm = site_hero_bg_normalize($urlInput);
            if ($norm === '') {
                flash_set('error', '背景图外链无效，请使用 http(s) 图片地址');
                redirect('settings.php');
            }
            if (site_hero_bg_is_upload($prevHero) && !site_hero_bg_is_upload($norm)) {
                site_hero_bg_delete_file($prevHero);
            }
            $heroBg = $norm;
        }
        // 未改 URL、未上传：保留 $prevHero
    }

    // 合并写入，保留 site 上其它扩展字段，避免整表覆盖丢失
    $content['site'] = array_merge(is_array($site) ? $site : [], [
        'name' => security_clean_text($_POST['name'] ?? '', 80),
        'subtitle' => security_clean_text($_POST['subtitle'] ?? '', 200),
        'hero_bg' => $heroBg,
        'footer' => security_clean_text($_POST['footer'] ?? '', 300),
        'footer_extra' => security_clean_text($_POST['footer_extra'] ?? '', 500),
        'footer_show_apply' => !empty($_POST['footer_show_apply']) ? '1' : '0',
        'footer_show_message' => !empty($_POST['footer_show_message']) ? '1' : '0',
        'footer_show_about' => !empty($_POST['footer_show_about']) ? '1' : '0',
        'footer_show_contact' => !empty($_POST['footer_show_contact']) ? '1' : '0',
        'footer_links' => $footerLinks,
        'show_friend_links' => !empty($_POST['show_friend_links']) ? '1' : '0',
        'enable_message' => !empty($_POST['enable_message']) ? '1' : '0',
        'about_html' => sanitize_admin_html($_POST['about_html'] ?? ''),
        'contact_html' => sanitize_admin_html($_POST['contact_html'] ?? ''),
        'contact_email' => security_email($_POST['contact_email'] ?? '') ?: security_clean_text($_POST['contact_email'] ?? '', 120),
        'seo_title' => security_clean_text($_POST['seo_title'] ?? '', 120),
        'seo_keywords' => security_clean_text($_POST['seo_keywords'] ?? '', 500),
        'seo_description' => security_clean_text($_POST['seo_description'] ?? '', 320),
        'seo_author' => security_clean_text($_POST['seo_author'] ?? '', 80),
        'seo_robots' => $seoRobots,
        'seo_canonical' => $seoCanonical,
        'seo_og_image' => $seoOgImage,
        'seo_baidu_verify' => $seoBaidu,
        'seo_google_verify' => $seoGoogle,
        'seo_bing_verify' => $seoBing,
        'seo_head_html' => sanitize_admin_html($_POST['seo_head_html'] ?? ''),
    ]);
    if (save_content($content)) {
        flash_set('success', '站点设置已保存');
    } else {
        flash_set('error', '保存失败，请检查 data 目录写权限');
    }
    redirect('settings.php');
}

$footerLinks = normalize_footer_links($site['footer_links'] ?? []);
$heroPreview = site_hero_bg_url($site);
$heroStored = site_hero_bg_normalize($site['hero_bg'] ?? '');
$heroUrlField = (strpos($heroStored, 'http://') === 0 || strpos($heroStored, 'https://') === 0) ? $heroStored : '';
// 后台在 /admin 下，本地资源需加 ../；外链原样
$heroPreviewCss = (strpos($heroPreview, 'http://') === 0 || strpos($heroPreview, 'https://') === 0)
    ? $heroPreview
    : ('../' . ltrim($heroPreview, '/'));
admin_layout_start('站点设置', 'settings');
?>
<div class="panel">
    <form method="post" class="form-grid" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <label>
            <span>站点名称</span>
            <input type="text" name="name" value="<?php echo e($site['name'] ?? ''); ?>" required maxlength="80">
        </label>
        <label>
            <span>副标题 / 简介</span>
            <input type="text" name="subtitle" value="<?php echo e($site['subtitle'] ?? ''); ?>" maxlength="200">
        </label>

        <fieldset class="footer-edit-block hero-bg-block" style="border:1px solid var(--border);border-radius:10px;padding:14px 16px;margin:4px 0 8px;grid-column:1/-1;">
            <legend style="padding:0 8px;font-size:0.95rem;font-weight:700;">首页顶部背景图</legend>
            <p class="muted" style="margin:0 0 12px;font-size:0.88rem;">显示在首页标题与副标题背后的大图。可上传本地图片，或填写外链；勾选「恢复默认」可还原内置图。</p>
            <div class="hero-bg-preview-wrap" style="margin-bottom:12px;">
                <div class="hero-bg-preview" style="max-width:420px;height:120px;border-radius:10px;background:url('<?php echo e($heroPreviewCss); ?>') center/cover no-repeat;border:1px solid var(--border);box-shadow:inset 0 0 0 1px rgba(0,0,0,.04);"></div>
                <p class="muted" style="margin:6px 0 0;font-size:0.82rem;">当前：<?php echo e($heroStored !== '' ? $heroStored : '默认 ' . site_hero_bg_default()); ?></p>
            </div>
            <label>
                <span>上传新背景（JPG / PNG / GIF / WebP / AVIF，≤5MB）</span>
                <input type="file" name="hero_bg_file" accept="image/jpeg,image/png,image/gif,image/webp,image/avif,.jpg,.jpeg,.png,.gif,.webp,.avif">
            </label>
            <label>
                <span>或使用外链图片 URL（与上传二选一，上传优先）</span>
                <input type="url" name="hero_bg_url" value="<?php echo e($heroUrlField); ?>" placeholder="https://example.com/banner.jpg" maxlength="500">
            </label>
            <label class="checkbox-label" style="display:flex;align-items:center;gap:8px;margin-top:4px;">
                <input type="checkbox" name="hero_bg_reset" value="1">
                <span>恢复默认背景图（清除自定义）</span>
            </label>
        </fieldset>

        <label>
            <span>页脚版权文字</span>
            <input type="text" name="footer" value="<?php echo e($site['footer'] ?? ''); ?>" maxlength="300">
        </label>
        <label>
            <span>页脚附加说明（可选）</span>
            <input type="text" name="footer_extra" value="<?php echo e($site['footer_extra'] ?? ''); ?>" placeholder="例如：备案号说明、运营主体等" maxlength="500">
        </label>

        <fieldset class="footer-edit-block" style="border:1px solid var(--border);border-radius:10px;padding:14px 16px;margin:4px 0 8px;">
            <legend style="padding:0 8px;font-size:0.95rem;font-weight:700;">页脚链接显示</legend>
            <p class="muted" style="margin:0 0 12px;font-size:0.88rem;">控制前台页脚「申请收录 / 在线留言 / 关于我们 / 联系我们」是否显示，以及自定义链接。</p>
            <label class="checkbox-label" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                <input type="checkbox" name="footer_show_apply" value="1" <?php echo empty($site['footer_show_apply']) || $site['footer_show_apply'] === '1' ? 'checked' : ''; ?>>
                <span>申请收录</span>
            </label>
            <label class="checkbox-label" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                <input type="checkbox" name="footer_show_message" value="1" <?php echo empty($site['footer_show_message']) || $site['footer_show_message'] === '1' ? 'checked' : ''; ?>>
                <span>在线留言</span>
            </label>
            <label class="checkbox-label" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                <input type="checkbox" name="footer_show_about" value="1" <?php echo empty($site['footer_show_about']) || $site['footer_show_about'] === '1' ? 'checked' : ''; ?>>
                <span>关于我们</span>
            </label>
            <label class="checkbox-label" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                <input type="checkbox" name="footer_show_contact" value="1" <?php echo empty($site['footer_show_contact']) || $site['footer_show_contact'] === '1' ? 'checked' : ''; ?>>
                <span>联系我们</span>
            </label>

            <div style="margin-top:14px;">
                <div class="panel-head" style="padding:0;margin-bottom:8px;">
                    <div>
                        <strong>自定义页脚链接</strong>
                        <p class="muted" style="margin:4px 0 0;font-size:0.88rem;">可添加备案查询、隐私政策等外链或站内路径</p>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" id="add-footer-link">添加链接</button>
                </div>
                <div class="table-wrap">
                    <table class="data-table" id="footer-links-table">
                        <thead>
                            <tr>
                                <th>名称</th>
                                <th>链接</th>
                                <th style="width:80px">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($footerLinks as $fl): ?>
                                <tr>
                                    <td><input type="text" name="footer_link_name[]" value="<?php echo e($fl['name'] ?? ''); ?>" placeholder="例如：隐私政策"></td>
                                    <td><input type="text" name="footer_link_url[]" value="<?php echo e($fl['url'] ?? ''); ?>" placeholder="https:// 或 about.php"></td>
                                    <td><button type="button" class="btn btn-danger btn-sm btn-remove-footer-link">删除</button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <template id="footer-link-template">
                    <tr>
                        <td><input type="text" name="footer_link_name[]" value="" placeholder="例如：隐私政策"></td>
                        <td><input type="text" name="footer_link_url[]" value="" placeholder="https:// 或 about.php"></td>
                        <td><button type="button" class="btn btn-danger btn-sm btn-remove-footer-link">删除</button></td>
                    </tr>
                </template>
            </div>
        </fieldset>

        <fieldset class="footer-edit-block" style="border:1px solid var(--border);border-radius:10px;padding:14px 16px;margin:4px 0 8px;">
            <legend style="padding:0 8px;font-size:0.95rem;font-weight:700;">SEO 搜索引擎优化</legend>
            <p class="muted" style="margin:0 0 12px;font-size:0.88rem;">用于首页与全站默认的标题、描述、关键词、索引策略与站长验证。子页面会在标题中自动附加页面名。</p>
            <label>
                <span>SEO 标题（留空则用「站点名 - 副标题」）</span>
                <input type="text" name="seo_title" value="<?php echo e($site['seo_title'] ?? ''); ?>" maxlength="120" placeholder="例如：夏尼猫网址导航 - 实用工具与热榜聚合">
            </label>
            <label>
                <span>SEO 关键词（英文逗号分隔）</span>
                <input type="text" name="seo_keywords" value="<?php echo e($site['seo_keywords'] ?? ''); ?>" maxlength="500" placeholder="网址导航,热榜,实用工具">
            </label>
            <label>
                <span>SEO 描述（建议 80–160 字）</span>
                <textarea name="seo_description" rows="3" maxlength="320" placeholder="一句话介绍站点价值，便于搜索摘要展示"><?php echo e($site['seo_description'] ?? ''); ?></textarea>
            </label>
            <label>
                <span>作者 / 运营主体</span>
                <input type="text" name="seo_author" value="<?php echo e($site['seo_author'] ?? ''); ?>" maxlength="80" placeholder="夏尼猫">
            </label>
            <label>
                <span>Robots 指令</span>
                <select name="seo_robots">
                    <?php
                    $robotsVal = site_seo_normalize_robots($site['seo_robots'] ?? 'index,follow');
                    $robotsOpts = [
                        'index,follow' => 'index,follow（允许收录与跟踪链接）',
                        'index,nofollow' => 'index,nofollow（收录但不跟踪外链）',
                        'noindex,follow' => 'noindex,follow（不收录但跟踪）',
                        'noindex,nofollow' => 'noindex,nofollow（不收录）',
                        'none' => 'none（等同 noindex,nofollow）',
                    ];
                    foreach ($robotsOpts as $val => $label):
                        ?>
                        <option value="<?php echo e($val); ?>" <?php echo $robotsVal === $val ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>首选域名（可选，https 站点根；会改写各页 canonical 的主机名）</span>
                <input type="url" name="seo_canonical" value="<?php echo e($site['seo_canonical'] ?? ''); ?>" placeholder="https://www.example.com/">
            </label>
            <label>
                <span>OG / 分享图 URL（可选）</span>
                <input type="url" name="seo_og_image" value="<?php echo e($site['seo_og_image'] ?? ''); ?>" placeholder="https://www.example.com/og.png">
            </label>
            <div class="form-grid two" style="grid-column:1/-1;">
                <label>
                    <span>百度站长验证码</span>
                    <input type="text" name="seo_baidu_verify" value="<?php echo e($site['seo_baidu_verify'] ?? ''); ?>" maxlength="64" placeholder="baidu-site-verification 内容">
                </label>
                <label>
                    <span>Google 站长验证码</span>
                    <input type="text" name="seo_google_verify" value="<?php echo e($site['seo_google_verify'] ?? ''); ?>" maxlength="100" placeholder="google-site-verification 内容">
                </label>
                <label>
                    <span>Bing 站长验证码</span>
                    <input type="text" name="seo_bing_verify" value="<?php echo e($site['seo_bing_verify'] ?? ''); ?>" maxlength="100" placeholder="msvalidate.01 内容">
                </label>
            </div>
            <label>
                <span>自定义 Head 片段（可选，仅 meta/link，禁止脚本）</span>
                <textarea name="seo_head_html" rows="3" placeholder='例如：&lt;meta name="theme-color" content="#4f46e5"&gt;'><?php echo e($site['seo_head_html'] ?? ''); ?></textarea>
            </label>
        </fieldset>

        <label class="checkbox-label">
            <input type="checkbox" name="show_friend_links" value="1" <?php echo empty($site['show_friend_links']) || $site['show_friend_links'] === '1' ? 'checked' : ''; ?>>
            <span>首页显示友情链接模块</span>
        </label>
        <label class="checkbox-label">
            <input type="checkbox" name="enable_message" value="1" <?php echo empty($site['enable_message']) || $site['enable_message'] === '1' ? 'checked' : ''; ?>>
            <span>启用在线留言</span>
        </label>
        <label>
            <span>关于我们（纯文本或简单 HTML）</span>
            <textarea name="about_html" rows="5"><?php echo e($site['about_html'] ?? ''); ?></textarea>
        </label>
        <label>
            <span>联系我们说明</span>
            <textarea name="contact_html" rows="4"><?php echo e($site['contact_html'] ?? ''); ?></textarea>
        </label>
        <label>
            <span>联系邮箱</span>
            <input type="email" name="contact_email" value="<?php echo e($site['contact_email'] ?? ''); ?>" placeholder="i@2016xlx.cn">
        </label>

        <div class="btn-row">
            <button type="submit" class="btn btn-primary">保存设置</button>
            <a class="btn btn-ghost" href="../index.php" target="_blank" rel="noopener">预览前台</a>
        </div>
    </form>
</div>
<style>
.footer-edit-block label > span { display:block; margin-bottom:6px; font-weight:600; }
.footer-edit-block input[type="text"],
.footer-edit-block input[type="url"],
.footer-edit-block input[type="file"],
.footer-edit-block textarea,
.footer-edit-block select { width:100%; max-width:720px; }
#footer-links-table input { width:100%; min-width:120px; }
.hero-bg-block .hero-bg-preview { background-color: #e2e8f0; }
</style>
<?php admin_layout_end(['assets/settings-footer.js']); ?>
