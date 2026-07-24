<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/layout.php';

$data = load_content();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('error', '安全校验失败，请刷新后重试');
        redirect('settings.php');
    }

    $data['site']['name'] = security_clean_text($_POST['name'] ?? '', 80);
    $data['site']['subtitle'] = security_clean_text($_POST['subtitle'] ?? '', 200);
    $data['site']['footer'] = security_clean_text($_POST['footer'] ?? '', 300);
    $data['site']['footer_extra'] = security_clean_text($_POST['footer_extra'] ?? '', 1000);
    $data['site']['footer_show_apply'] = !empty($_POST['footer_show_apply']) ? '1' : '0';
    $data['site']['footer_show_message'] = !empty($_POST['footer_show_message']) ? '1' : '0';
    $data['site']['footer_show_about'] = !empty($_POST['footer_show_about']) ? '1' : '0';
    $data['site']['footer_show_contact'] = !empty($_POST['footer_show_contact']) ? '1' : '0';

    $flNames = is_array($_POST['footer_link_name'] ?? null) ? $_POST['footer_link_name'] : [];
    $flUrls = is_array($_POST['footer_link_url'] ?? null) ? $_POST['footer_link_url'] : [];
    $footerLinks = [];
    $n = min(30, max(count($flNames), count($flUrls)));
    for ($i = 0; $i < $n; $i++) {
        $nm = security_clean_text($flNames[$i] ?? '', 80);
        $u = security_url(trim((string) ($flUrls[$i] ?? '')), true);
        if ($nm === '' || $u === '') {
            continue;
        }
        $footerLinks[] = ['name' => $nm, 'url' => $u];
    }
    $data['site']['footer_links'] = $footerLinks;

    $data['site']['show_friend_links'] = !empty($_POST['show_friend_links']) ? '1' : '0';
    $data['site']['enable_message'] = !empty($_POST['enable_message']) ? '1' : '0';
    $data['site']['about_html'] = security_sanitize_html($_POST['about_html'] ?? '', 20000);
    $data['site']['contact_html'] = security_sanitize_html($_POST['contact_html'] ?? '', 20000);
    $ce = security_email($_POST['contact_email'] ?? '', 120);
    $data['site']['contact_email'] = $ce !== null ? $ce : security_clean_text($_POST['contact_email'] ?? '', 120);

    if ($data['site']['name'] === '') {
        flash_set('error', '站点名称不能为空');
        redirect('settings.php');
    }

    if (save_content($data)) {
        flash_set('success', '站点设置已保存');
    } else {
        flash_set('error', '保存失败，请检查数据库连接或 data 目录写权限');
    }
    redirect('settings.php');
}

admin_layout_start('站点设置', 'settings');
$site = $data['site'] ?? [];
$showLinks = !isset($site['show_friend_links']) || $site['show_friend_links'] !== '0';
$enableMsg = !isset($site['enable_message']) || $site['enable_message'] !== '0';
$flagOn = static function ($key) use ($site) {
    return !isset($site[$key]) || $site[$key] !== '0';
};
$footerLinks = normalize_footer_links($site['footer_links'] ?? []);
if (empty($footerLinks)) {
    $footerLinks = [['name' => '', 'url' => '']];
}
?>
<div class="panel">
    <h2>站点设置</h2>
    <form method="post" class="stack-form" id="settings-form">
        <?php echo csrf_field(); ?>
        <label>
            <span>站点名称</span>
            <input type="text" name="name" required value="<?php echo e($site['name'] ?? ''); ?>">
        </label>
        <label>
            <span>副标题</span>
            <input type="text" name="subtitle" value="<?php echo e($site['subtitle'] ?? ''); ?>">
        </label>

        <fieldset class="switch-fieldset">
            <legend>功能开关</legend>
            <label class="switch-label">
                <input type="checkbox" name="show_friend_links" value="1" <?php echo $showLinks ? 'checked' : ''; ?>>
                <span>前台显示「友情链接」</span>
            </label>
            <label class="switch-label">
                <input type="checkbox" name="enable_message" value="1" <?php echo $enableMsg ? 'checked' : ''; ?>>
                <span>允许「在线留言」</span>
            </label>
        </fieldset>

        <fieldset class="switch-fieldset footer-edit-block">
            <legend>页脚内容</legend>
            <label>
                <span>版权 / 主文案</span>
                <input type="text" name="footer" value="<?php echo e($site['footer'] ?? ''); ?>" placeholder="© 站点名称 · 仅供学习演示">
            </label>
            <label>
                <span>附加说明（支持换行，显示在版权下方）</span>
                <textarea name="footer_extra" rows="3" placeholder="例如：ICP备案号、免责声明等"><?php echo e($site['footer_extra'] ?? ''); ?></textarea>
            </label>

            <p class="muted" style="margin:8px 0 6px;">内置链接显示</p>
            <label class="switch-label">
                <input type="checkbox" name="footer_show_apply" value="1" <?php echo $flagOn('footer_show_apply') ? 'checked' : ''; ?>>
                <span>申请收录</span>
            </label>
            <label class="switch-label">
                <input type="checkbox" name="footer_show_message" value="1" <?php echo $flagOn('footer_show_message') ? 'checked' : ''; ?>>
                <span>在线留言（还需开启上方「允许在线留言」）</span>
            </label>
            <label class="switch-label">
                <input type="checkbox" name="footer_show_about" value="1" <?php echo $flagOn('footer_show_about') ? 'checked' : ''; ?>>
                <span>关于我们</span>
            </label>
            <label class="switch-label">
                <input type="checkbox" name="footer_show_contact" value="1" <?php echo $flagOn('footer_show_contact') ? 'checked' : ''; ?>>
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

        <button type="submit" class="btn btn-primary">保存设置</button>
    </form>
</div>
<style>
.footer-edit-block label > span { display:block; margin-bottom:6px; font-weight:600; }
.footer-edit-block input[type="text"],
.footer-edit-block textarea { width:100%; max-width:720px; }
#footer-links-table input { width:100%; min-width:120px; }
</style>
<?php admin_layout_end(['assets/settings-footer.js']); ?>
