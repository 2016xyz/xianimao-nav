<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/layout.php';

$content = load_content();
$list = $content['engines'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('error', '请求无效，请刷新后重试');
        redirect('engines.php');
    }

    $action = security_enum((string) ($_POST['action'] ?? ''), ['save', 'delete', 'move']);
    $index = security_int($_POST['index'] ?? -1, -1, 9999);
    if ($index === null) {
        $index = -1;
    }

    if ($action === 'save') {
        $name = security_clean_text($_POST['name'] ?? '', 80);
        $url = security_url(trim((string) ($_POST['url'] ?? '')), false);
        $id = security_id($_POST['id'] ?? '', 64);
        if ($name === '' || $url === '') {
            flash_set('error', '名称与搜索 URL 不能为空，且 URL 须为 http(s)');
            redirect('engines.php');
        }
        if ($id === null || $id === '') {
            $id = slugify($name);
        }
        $item = ['id' => $id, 'name' => $name, 'url' => $url];
        if ($index >= 0 && isset($list[$index])) {
            $list[$index] = $item;
            flash_set('success', '搜索引擎已更新');
        } else {
            $list[] = $item;
            flash_set('success', '搜索引擎已添加');
        }
    } elseif ($action === 'delete' && isset($list[$index])) {
        array_splice($list, $index, 1);
        flash_set('success', '已删除');
    } elseif ($action === 'move' && isset($list[$index])) {
        $dir = security_enum((string) ($_POST['direction'] ?? ''), ['up', 'down']) ?? '';
        $list = move_list_item($list, $index, $dir);
        flash_set('success', '排序已更新');
    }

    $content['engines'] = array_values($list);
    if (!save_content($content)) {
        flash_set('error', '保存失败，请检查数据库连接');
        admin_log_write('engines_save_fail', '搜索引擎保存失败', ['module' => 'engines', 'level' => 'error', 'detail' => ['action' => $action]]);
    } else {
        $labels = ['save' => '保存搜索引擎', 'delete' => '删除搜索引擎', 'move' => '调整搜索引擎排序'];
        admin_log_write('engines_' . ($action ?: 'save'), $labels[$action] ?? '更新搜索引擎', [
            'module' => 'engines',
            'level' => 'success',
            'detail' => ['action' => $action, 'count' => count($list)],
        ]);
    }
    redirect('engines.php');
}

admin_layout_start('搜索引擎', 'engines');
?>
<div class="panel">
    <div class="toolbar">
        <h2>搜索引擎列表</h2>
        <button type="button" class="btn btn-primary" data-open-modal="modal-add">添加引擎</button>
    </div>
    <p class="hint">URL 中使用 <code>{q}</code> 作为关键词占位符，例如：https://www.baidu.com/s?wd={q}</p>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:48px">#</th>
                    <th>名称</th>
                    <th>ID</th>
                    <th>搜索 URL</th>
                    <th style="width:220px">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($list)): ?>
                <tr><td colspan="5" class="empty">暂无数据，请添加</td></tr>
            <?php else: ?>
                <?php foreach ($list as $i => $item): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><?php echo e($item['name'] ?? ''); ?></td>
                    <td class="cell-muted"><?php echo e($item['id'] ?? ''); ?></td>
                    <td class="cell-muted"><?php echo e($item['url'] ?? ''); ?></td>
                    <td>
                        <div class="actions">
                            <button type="button" class="btn btn-secondary btn-sm"
                                data-open-modal="modal-edit-<?php echo $i; ?>">编辑</button>
                            <form method="post" style="display:inline">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="move">
                                <input type="hidden" name="index" value="<?php echo $i; ?>">
                                <input type="hidden" name="direction" value="up">
                                <button type="submit" class="btn btn-secondary btn-sm" <?php echo $i === 0 ? 'disabled' : ''; ?>>上移</button>
                            </form>
                            <form method="post" style="display:inline">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="move">
                                <input type="hidden" name="index" value="<?php echo $i; ?>">
                                <input type="hidden" name="direction" value="down">
                                <button type="submit" class="btn btn-secondary btn-sm" <?php echo $i === count($list) - 1 ? 'disabled' : ''; ?>>下移</button>
                            </form>
                            <form method="post" style="display:inline" data-confirm="确定删除该搜索引擎？">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="index" value="<?php echo $i; ?>">
                                <button type="submit" class="btn btn-danger btn-sm">删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-backdrop" id="modal-add">
    <div class="modal">
        <h3>添加搜索引擎</h3>
        <form method="post" class="form-grid">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="index" value="-1">
            <div class="form-group">
                <label>名称</label>
                <input type="text" name="name" required maxlength="40" placeholder="如：百度">
            </div>
            <div class="form-group">
                <label>ID <span class="field-hint">可选，留空自动生成</span></label>
                <input type="text" name="id" maxlength="40" placeholder="baidu">
            </div>
            <div class="form-group">
                <label>搜索 URL</label>
                <input type="text" name="url" required maxlength="500" placeholder="https://www.baidu.com/s?wd={q}">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-close-modal>取消</button>
                <button type="submit" class="btn btn-primary">保存</button>
            </div>
        </form>
    </div>
</div>

<?php foreach ($list as $i => $item): ?>
<div class="modal-backdrop" id="modal-edit-<?php echo $i; ?>">
    <div class="modal">
        <h3>编辑搜索引擎</h3>
        <form method="post" class="form-grid">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="index" value="<?php echo $i; ?>">
            <div class="form-group">
                <label>名称</label>
                <input type="text" name="name" required maxlength="40" value="<?php echo e($item['name'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>ID</label>
                <input type="text" name="id" maxlength="40" value="<?php echo e($item['id'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>搜索 URL</label>
                <input type="text" name="url" required maxlength="500" value="<?php echo e($item['url'] ?? ''); ?>">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-close-modal>取消</button>
                <button type="submit" class="btn btn-primary">保存</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>
<?php admin_layout_end(); ?>
