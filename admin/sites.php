<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/layout.php';
require_once ROOT_PATH . '/includes/ai.php';

$data = load_content();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('error', '安全校验失败，请刷新后重试');
        redirect('sites.php');
    }
    $names = is_array($_POST['name'] ?? null) ? $_POST['name'] : [];
    $descs = is_array($_POST['desc'] ?? null) ? $_POST['desc'] : [];
    $urls = is_array($_POST['url'] ?? null) ? $_POST['url'] : [];
    $tags = is_array($_POST['tag'] ?? null) ? $_POST['tag'] : [];
    $rows = [];
    $n = min(100, max(count($names), count($urls)));
    for ($i = 0; $i < $n; $i++) {
        $name = security_clean_text($names[$i] ?? '', 80);
        $urlRaw = trim((string) ($urls[$i] ?? ''));
        if ($name === '' && $urlRaw === '') {
            continue;
        }
        $safeUrl = security_url($urlRaw, false);
        if ($safeUrl === '') {
            continue;
        }
        $rows[] = [
            'name' => $name,
            'desc' => security_clean_text($descs[$i] ?? '', 300),
            'url' => $safeUrl,
            'tag' => security_clean_text($tags[$i] ?? '', 40),
        ];
    }
    $data['sites'] = $rows;
    if (save_content($data)) {
        admin_log_write('sites_save', '保存自营站点（共 ' . count($rows) . ' 项）', [
            'module' => 'sites',
            'level' => 'success',
            'detail' => ['count' => count($rows)],
        ]);
        flash_set('success', '自营站点已保存');
    } else {
        admin_log_write('sites_save_fail', '自营站点保存失败', ['module' => 'sites', 'level' => 'error']);
        flash_set('error', '保存失败');
    }
    redirect('sites.php');
}

$items = $data['sites'] ?? [];
if (empty($items)) {
    $items = [['name' => '', 'desc' => '', 'url' => '', 'tag' => '']];
}
$aiReady = ai_is_ready();
$aiCfg = ai_config_load();

admin_layout_start('自营站点', 'sites');
?>
<div class="panel">
    <div class="panel-head">
        <div>
            <h2>自营站点</h2>
            <p class="muted">管理首页「自营站点」列表。填写名称与链接后，可点击「AI 生成介绍」自动写简介（需先在 AI 配置中启用）。</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php if ($aiReady): ?>
                <span class="tag" style="background:#ecfdf5;color:#047857;align-self:center;">AI 已就绪 · <?php echo e($aiCfg['model']); ?></span>
            <?php else: ?>
                <a class="btn btn-secondary" href="ai.php">配置 AI</a>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary" data-add-row data-template="row-template" data-rows="rows">添加一行</button>
        </div>
    </div>
    <form method="post" class="stack-form" id="sites-form">
        <?php echo csrf_field(); ?>
        <div class="table-wrap">
            <table class="data-table" id="editable-table">
                <thead>
                    <tr>
                        <th>名称</th>
                        <th>介绍</th>
                        <th>链接</th>
                        <th>标签</th>
                        <th style="width:160px">操作</th>
                    </tr>
                </thead>
                <tbody id="rows">
                    <?php foreach ($items as $row): ?>
                        <tr>
                            <td><input type="text" name="name[]" value="<?php echo e($row['name'] ?? ''); ?>" placeholder="站点名称"></td>
                            <td>
                                <div class="desc-cell">
                                    <input type="text" name="desc[]" value="<?php echo e($row['desc'] ?? ''); ?>" placeholder="一句话介绍" class="desc-input">
                                </div>
                            </td>
                            <td><input type="url" name="url[]" value="<?php echo e($row['url'] ?? ''); ?>" placeholder="https://"></td>
                            <td><input type="text" name="tag[]" value="<?php echo e($row['tag'] ?? ''); ?>" placeholder="博客"></td>
                            <td class="row-actions">
                                <button type="button" class="btn btn-secondary btn-sm btn-ai-desc" title="AI 生成介绍" <?php echo $aiReady ? '' : 'disabled'; ?>>AI 生成</button>
                                <button type="button" class="btn btn-danger btn-sm btn-remove-row" data-remove-row>删除</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <template id="row-template">
            <tr>
                <td><input type="text" name="name[]" value="" placeholder="站点名称"></td>
                <td>
                    <div class="desc-cell">
                        <input type="text" name="desc[]" value="" placeholder="一句话介绍" class="desc-input">
                    </div>
                </td>
                <td><input type="url" name="url[]" value="" placeholder="https://"></td>
                <td><input type="text" name="tag[]" value="" placeholder="博客"></td>
                <td class="row-actions">
                    <button type="button" class="btn btn-secondary btn-sm btn-ai-desc" title="AI 生成介绍" <?php echo $aiReady ? '' : 'disabled'; ?>>AI 生成</button>
                    <button type="button" class="btn btn-danger btn-sm btn-remove-row" data-remove-row>删除</button>
                </td>
            </tr>
        </template>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">保存</button>
            <?php if (!$aiReady): ?>
                <span class="muted">AI 未就绪：请先到 <a href="ai.php">AI 配置</a> 填写 URL / Key 并获取模型</span>
            <?php endif; ?>
        </div>
    </form>
</div>
<style>
.row-actions { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
.desc-cell { min-width: 180px; }
#editable-table input { width: 100%; min-width: 90px; }
.btn-ai-desc.is-loading { opacity: 0.7; pointer-events: none; }
</style>
<div id="nav-ai-config" hidden
     data-ready="<?php echo $aiReady ? '1' : '0'; ?>"
     data-csrf="<?php echo e(csrf_token()); ?>"
     data-endpoint="ai_generate.php"></div>
<?php admin_layout_end(['assets/sites-ai-config.js']); ?>
