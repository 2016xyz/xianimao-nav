<?php
if (!isset($data)) {
    require_once __DIR__ . '/bootstrap.php';
    $data = load_site_data();
}
$site = $data['site'] ?? [];
$footerText = $site['footer'] ?? ('© ' . date('Y') . ' 夏尼猫网址导航 All Rights Reserved.');
$footerExtra = trim((string) ($site['footer_extra'] ?? ''));
$footerVis = footer_builtin_visibility($site);
$footerLinks = normalize_footer_links($site['footer_links'] ?? []);
$hasFooterLinks = $footerVis['apply'] || $footerVis['message'] || $footerVis['about'] || $footerVis['contact'] || !empty($footerLinks);
?>
<footer class="site-footer">
    <div class="container">
        <?php if ($hasFooterLinks): ?>
        <div class="footer-links">
            <?php if ($footerVis['apply']): ?>
                <a href="apply.php"><i class="bi bi-plus-circle"></i> 申请收录</a>
            <?php endif; ?>
            <?php if ($footerVis['message']): ?>
                <a href="message.php"><i class="bi bi-chat-left-text"></i> 在线留言</a>
            <?php endif; ?>
            <?php if ($footerVis['about']): ?>
                <a href="about.php"><i class="bi bi-info-circle"></i> 关于我们</a>
            <?php endif; ?>
            <?php if ($footerVis['contact']): ?>
                <a href="contact.php"><i class="bi bi-envelope"></i> 联系我们</a>
            <?php endif; ?>
            <?php foreach ($footerLinks as $fl): ?>
                <?php
                $fname = trim((string) ($fl['name'] ?? ''));
                $furl = trim((string) ($fl['url'] ?? ''));
                if ($fname === '' || $furl === '') {
                    continue;
                }
                $external = preg_match('#^https?://#i', $furl);
                ?>
                <a href="<?php echo e($furl); ?>"<?php echo $external ? ' target="_blank" rel="noopener"' : ''; ?>>
                    <i class="bi bi-link-45deg"></i> <?php echo e($fname); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="footer-copy">
            <p><?php echo e($footerText); ?></p>
            <?php if ($footerExtra !== ''): ?>
                <p class="footer-extra"><?php echo nl2br(e($footerExtra)); ?></p>
            <?php endif; ?>
        </div>
    </div>
</footer>

<button class="back-to-top" id="backToTop" title="回到顶部" type="button">
    <i class="bi bi-chevron-up"></i>
</button>

<script src="assets/js/jquery.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/main.js"></script>
</body>
</html>
