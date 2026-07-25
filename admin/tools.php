<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/layout.php';

$data = load_content();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('error', '安全校验失败，请刷新后重试');
        redirect('tools.php');
    }

    $names = is_array($_POST['name'] ?? null) ? $_POST['name'] : [];
    $urls = is_array($_POST['url'] ?? null) ? $_POST['url'] : [];
    $descs = is_array($_POST['desc'] ?? null) ? $_POST['desc'] : [];
    $ids = is_array($_POST['id'] ?? null) ? $_POST['id'] : [];
    $tools = [];
    $count = 0;

    foreach ($names as $i => $name) {
        if ($count >= 100) {
            break;
        }
        $name = security_clean_text($name, 80);
        $url = security_url(trim((string) ($urls[$i] ?? '')), false);
        $desc = security_clean_text($descs[$i] ?? '', 300);
        $id = security_id($ids[$i] ?? '', 64);
        if ($name === '' || $url === '') {
            continue;
        }
        if ($id === null || $id === '') {
            $id = slugify($name);
        }
        $tools[] = [
            'id' => $id,
            'name' => $name,
            'url' => $url,
            'desc' => $desc,
        ];
        $count++;
    }

    $data['tools'] = $tools;
    if (save_content($data)) {
        admin_log_write('tools_save', '保存实用工具（共 ' . count($tools) . ' 项）', [
            'module' => 'tools',
            'level' => 'success',
            'detail' => ['count' => count($tools)],
        ]);
        flash_set('success', '实用工具已保存（共 ' . count($tools) . ' 项）');
    } else {
        admin_log_write('tools_save_fail', '实用工具保存失败', ['module' => 'tools', 'level' => 'error']);
        flash_set('error', '保存失败，请检查数据库连接');
    }
    redirect('tools.php');
}

admin_layout_start('实用工具', 'tools');
$items = $data['tools'] ?? [];
?>
<div class="panel">
    <div class="panel-head">
        <div>
            <h2>实用工具</h2>
            <p class="muted">首页「实用工具」区块，支持添加、修改、删除，可用 ↑↓ 调整显示顺序后保存。</p>
        </div>
        <button type="button" class="btn btn-primary" data-add-row data-template="tool-row-tpl">＋ 添加工具</button>
    </div>

    <form method="post" class="stack-form">
        <?php echo csrf_field(); ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:52px">排序</th>
                        <th style="width:18%">名称</th>
                        <th style="width:28%">链接</th>
                        <th>简介</th>
                        <th style="width:90px">操作</th>
                    </tr>
                </thead>
                <tbody id="rows">
                    <?php if (empty($items)): ?>
                        <tr class="empty-row">
                            <td colspan="5" class="muted" style="text-align:center;padding:28px;">暂无工具，点击右上角添加</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td class="row-order">
                                <button type="button" class="btn btn-secondary btn-sm" data-move-row="up" title="上移">↑</button>
                                <button type="button" class="btn btn-secondary btn-sm" data-move-row="down" title="下移">↓</button>
                            </td>
                            <td>
                                <input type="hidden" name="id[]" value="<?php echo e($item['id'] ?? ''); ?>">
                                <input type="text" name="name[]" value="<?php echo e($item['name'] ?? ''); ?>" required placeholder="工具名称">
                            </td>
                            <td>
                                <input type="url" name="url[]" value="<?php echo e($item['url'] ?? ''); ?>" required placeholder="https://">
                            </td>
                            <td>
                                <input type="text" name="desc[]" value="<?php echo e($item['desc'] ?? ''); ?>" placeholder="简介">
                            </td>
                            <td class="actions">
                                <button type="button" class="btn btn-danger btn-sm" data-remove-row>删除</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">保存全部更改</button>
            <span class="muted">当前 <?php echo count($items); ?> 项</span>
        </div>
    </form>
</div>

<template id="tool-row-tpl">
    <tr>
        <td class="row-order">
            <button type="button" class="btn btn-secondary btn-sm" data-move-row="up" title="上移">↑</button>
            <button type="button" class="btn btn-secondary btn-sm" data-move-row="down" title="下移">↓</button>
        </td>
        <td>
            <input type="hidden" name="id[]" value="">
            <input type="text" name="name[]" value="" required placeholder="工具名称">
        </td>
        <td>
            <input type="url" name="url[]" value="" required placeholder="https://">
        </td>
        <td>
            <input type="text" name="desc[]" value="" placeholder="简介">
        </td>
        <td class="actions">
            <button type="button" class="btn btn-danger btn-sm" data-remove-row>删除</button>
        </td>
    </tr>
</template>
<style>
.row-order { display:flex; flex-direction:column; gap:4px; align-items:center; white-space:nowrap; }
.row-order .btn { min-width: 34px; padding-left: 8px; padding-right: 8px; }
</style>
<?php admin_layout_end(); ?>
