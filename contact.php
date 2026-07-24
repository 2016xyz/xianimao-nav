<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/page_layout.php';

$data = load_site_data();
$GLOBALS['data'] = $data;
$site = $data['site'] ?? [];
$contact = trim((string) ($site['contact_html'] ?? ''));
$email = trim((string) ($site['contact_email'] ?? ''));
$enableMessage = !isset($site['enable_message']) || ($site['enable_message'] !== '0' && $site['enable_message'] !== 0);

if ($contact === '') {
    $contact = '如有合作、反馈或商务事宜，请通过在线留言联系我们。';
}

page_layout_start('联系我们', [
    'page_key' => 'contact',
    'icon' => 'bi-envelope-open',
    'tone' => 'amber',
    'lead' => '合作、反馈与商务咨询，欢迎通过以下方式联系。',
]);
?>
<div class="contact-layout">
    <div class="subpage-prose contact-intro">
        <?php
        $contactSafe = sanitize_admin_html($contact);
        if (strip_tags($contactSafe) !== $contactSafe) {
            echo $contactSafe;
        } else {
            echo nl2br(e($contactSafe !== '' ? $contactSafe : $contact));
        }
        ?>
    </div>

    <div class="contact-cards">
        <?php if ($email !== ''): ?>
            <a class="contact-card" href="mailto:<?php echo e($email); ?>">
                <div class="contact-card-icon email"><i class="bi bi-envelope-fill"></i></div>
                <div class="contact-card-body">
                    <h3>邮箱联系</h3>
                    <p><?php echo e($email); ?></p>
                    <span class="contact-card-cta">发送邮件 <i class="bi bi-arrow-right"></i></span>
                </div>
            </a>
        <?php else: ?>
            <div class="contact-card contact-card-static">
                <div class="contact-card-icon email"><i class="bi bi-envelope-fill"></i></div>
                <div class="contact-card-body">
                    <h3>邮箱联系</h3>
                    <p>管理员尚未配置公开邮箱，请优先使用在线留言。</p>
                    <span class="contact-card-cta muted">待配置</span>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($enableMessage): ?>
            <a class="contact-card" href="message.php">
                <div class="contact-card-icon message"><i class="bi bi-chat-dots-fill"></i></div>
                <div class="contact-card-body">
                    <h3>在线留言</h3>
                    <p>填写表单，我们会尽快查看回复</p>
                    <span class="contact-card-cta">去留言 <i class="bi bi-arrow-right"></i></span>
                </div>
            </a>
        <?php endif; ?>

        <a class="contact-card" href="apply.php">
            <div class="contact-card-icon apply"><i class="bi bi-plus-circle-fill"></i></div>
            <div class="contact-card-body">
                <h3>申请收录</h3>
                <p>提交站点信息，申请友链或栏目展示</p>
                <span class="contact-card-cta">去申请 <i class="bi bi-arrow-right"></i></span>
            </div>
        </a>

        <a class="contact-card" href="about.php">
            <div class="contact-card-icon about"><i class="bi bi-info-circle-fill"></i></div>
            <div class="contact-card-body">
                <h3>关于我们</h3>
                <p>了解本站定位、内容方向与愿景</p>
                <span class="contact-card-cta">去了解 <i class="bi bi-arrow-right"></i></span>
            </div>
        </a>
    </div>

    <div class="info-panel info-panel-soft contact-note">
        <h3><i class="bi bi-clock-history"></i> 响应说明</h3>
        <p>我们会尽量在工作日尽快回复。若事项紧急，请在留言中注明，并留下有效联系方式。</p>
    </div>
</div>
<?php page_layout_end(); ?>
