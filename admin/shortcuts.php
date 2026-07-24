<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/layout.php';

$content = load_content();
$list = $content['shortcuts'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('error', '请求无效，请刷新后重试');
        redirect('shortcuts.php');
    }

    $action = $_POST['action'] ?? '';
    $index = isset($_POST['index']) ? (int) $_POST['index'] : -1;

    if ($action === 'save') {
        $name = security_clean_text($_POST['name'] ?? '', 80);
        $url = security_url(trim((string) ($_POST['url'] ?? '')), false);
        $type = security_enum((string) ($_POST['type'] ?? 'search'), ['link', 'search']) ?? 'search';
        if ($name === '' || $url === '') {
            flash_set('error', '名称与链接不能为空，且 URL 须为 http(s)');
            redirect('shortcuts.php');
        }
        $item = ['name' => $name, 'url' => $url, 'type' => $type];
        if ($index >= 0 && isset($list[$index])) {
            $list[$index] = $item;
            flash_set('success', '快捷入口已更新');
        } else {
            $list[] = $item;
            flash_set('success', '快捷入口已添加');
        }
    } elseif ($action === 'delete' && isset($list[$index])) {
        array_splice($list, $index, 1);
        flash_set('success', '已删除');
    } elseif ($action === 'move' && isset($list[$index])) {
        $list = move_list_item($list, $index, $_POST['direction'] ?? '');
        flash_set('success', '排序已更新');
    }

    $content['shortcuts'] = array_values($list);
    if (!save_content($content)) {
        flash_set('error', '保存失败，请检查数据库连接');
        admin_log_write('shortcuts_save_fail', '快捷入口保存失败', ['module' => 'shortcuts', 'level' => 'error', 'detail' => ['action' => $action]]);
    } else {
        $labels = ['save' => '保存快捷入口', 'delete' => '删除快捷入口', 'move' => '调整快捷入口排序'];
        admin_log_write('shortcuts_' . ($action ?: 'save'), $labels[$action] ?? '更新快捷入口', [
            'module' => 'shortcuts',
            'level' => 'success',
            'detail' => ['action' => $action, 'count' => count($list)],
        ]);
    }
    redirect('shortcuts.php');
}

admin_layout_start('快捷入口', 'shortcuts');
?>
<div class="panel">
    <div class="toolbar">
        <h2>快捷入口列表</h2>
        <button type="button" class="btn btn-primary" data-open-modal="modal-add">添加入口</button>
    </div>
    <p class="hint">类型为「搜索」时，会把搜索框关键词填入 URL 的 <code>{q}</code>；类型为「外链」则直接打开链接。</p>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:48px">#</th>
                    <th>名称</th>
                    <th>类型</th>
                    <th>链接 / URL 模板</th>
                    <th style="width:220px">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($list)): ?>
                <tr><td colspan="5" class="empty">暂无数据</td></tr>
            <?php else: ?>
                <?php foreach ($list as $i => $item): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><?php echo e($item['name'] ?? ''); ?></td>
                    <td><span class="tag"><?php echo ($item['type'] ?? '') === 'link' ? '外链' : '搜索'; ?></span></td>
                    <td class="cell-muted"><?php echo e($item['url'] ?? ''); ?></td>
                    <td>
                        <div class="actions">
                            <button type="button" class="btn btn-secondary btn-sm" data-open-modal="modal-edit-<?php echo $i; ?>">编辑</button>
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
                            <form method="post" style="display:inline" data-confirm="确定删除？">
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
        <h3>添加快捷入口</h3>
        <form method="post" class="form-grid">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="index" value="-1">
            <div class="form-group">
                <label>名称</label>
                <input type="text" name="name" required maxlength="40">
            </div>
            <div class="form-group">
                <label>类型</label>
                <select name="type">
                    <option value="search">搜索（使用 {q}）</option>
                    <option value="link">外链</option>
                </select>
            </div>
            <div class="form-group">
                <label>链接 / URL 模板</label>
                <input type="text" name="url" required maxlength="500" placeholder="https://... 或带 {q}">
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
        <h3>编辑快捷入口</h3>
        <form method="post" class="form-grid">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="index" value="<?php echo $i; ?>">
            <div class="form-group">
                <label>名称</label>
                <input type="text" name="name" required maxlength="40" value="<?php echo e($item['name'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>类型</label>
                <select name="type">
                    <option value="search" <?php echo ($item['type'] ?? '') !== 'link' ? 'selected' : ''; ?>>搜索（使用 {q}）</option>
                    <option value="link" <?php echo ($item['type'] ?? '') === 'link' ? 'selected' : ''; ?>>外链</option>
                </select>
            </div>
            <div class="form-group">
                <label>链接 / URL 模板</label>
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
