<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('error', '安全校验失败');
        redirect('messages.php');
    }
    $action = security_enum((string) ($_POST['action'] ?? ''), ['toggle_message', 'status', 'delete']);
    if ($action === null) {
        flash_set('error', '无效操作');
        redirect('messages.php');
    }

    if ($action === 'toggle_message') {
        $data = load_content();
        $data['site']['enable_message'] = !empty($_POST['enable_message']) ? '1' : '0';
        if (save_content($data)) {
            flash_set('success', !empty($_POST['enable_message']) ? '已开启在线留言' : '已关闭在线留言');
        } else {
            flash_set('error', '保存失败');
        }
        redirect('messages.php');
    }

    // 兼容数字 id 与 JSON 字符串 id（如 m123456）
    $idRaw = security_clean_text($_POST['id'] ?? '', 64);
    $id = ctype_digit($idRaw) ? (int) $idRaw : $idRaw;

    if ($action === 'status' && $idRaw !== '') {
        $status = security_enum((string) ($_POST['status'] ?? 'read'), ['pending', 'read', 'done']);
        if ($status === null) {
            flash_set('error', '状态无效');
            redirect('messages.php');
        }
        if (update_message_status($id, $status)) {
            flash_set('success', '状态已更新');
        } else {
            flash_set('error', '更新失败');
        }
        redirect('messages.php');
    }

    if ($action === 'delete' && $idRaw !== '') {
        if (delete_message($id)) {
            flash_set('success', '已删除');
        } else {
            flash_set('error', '删除失败');
        }
        redirect('messages.php');
    }

    redirect('messages.php');
}

$filter = security_enum((string) ($_GET['type'] ?? ''), ['message', 'apply']);
$filter = $filter !== null ? $filter : '';
$list = list_messages($filter !== '' ? $filter : null);
$data = load_content();
$enable = !isset($data['site']['enable_message']) || $data['site']['enable_message'] !== '0';

admin_layout_start('留言管理', 'messages');
?>
<div class="panel">
    <div class="panel-head">
        <div>
            <h2>留言与收录申请</h2>
            <p class="muted">查看前台提交的在线留言与申请收录；可关闭在线留言入口。</p>
        </div>
    </div>

    <form method="post" class="switch-bar" style="margin-bottom:16px;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="toggle_message">
        <label class="switch-label">
            <input type="checkbox" name="enable_message" value="1" <?php echo $enable ? 'checked' : ''; ?> data-auto-submit>
            <span>允许前台在线留言</span>
        </label>
        <span class="muted">关闭后页脚不显示「在线留言」，访问 message.php 将提示已关闭</span>
    </form>

    <div class="filter-tabs" style="margin-bottom:12px;">
        <a class="btn btn-sm <?php echo $filter === '' ? 'btn-primary' : 'btn-secondary'; ?>" href="messages.php">全部</a>
        <a class="btn btn-sm <?php echo $filter === 'message' ? 'btn-primary' : 'btn-secondary'; ?>" href="messages.php?type=message">留言</a>
        <a class="btn btn-sm <?php echo $filter === 'apply' ? 'btn-primary' : 'btn-secondary'; ?>" href="messages.php?type=apply">收录申请</a>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:90px">类型</th>
                    <th style="width:100px">昵称</th>
                    <th>内容 / 站点</th>
                    <th style="width:140px">联系</th>
                    <th style="width:140px">时间</th>
                    <th style="width:80px">状态</th>
                    <th style="width:160px">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($list)): ?>
                    <tr><td colspan="7" class="muted" style="text-align:center;padding:28px;">暂无记录</td></tr>
                <?php endif; ?>
                <?php foreach ($list as $row): ?>
                    <?php
                    $type = $row['type'] ?? 'message';
                    $typeLabel = $type === 'apply' ? '收录' : '留言';
                    $status = $row['status'] ?? 'pending';
                    $statusLabel = ['pending' => '待处理', 'read' => '已读', 'done' => '已完成'][$status] ?? $status;
                    $body = $type === 'apply'
                        ? (($row['website'] ?? '') . ' · ' . ($row['content'] ?? ''))
                        : ($row['content'] ?? '');
                    ?>
                    <tr>
                        <td><span class="tag"><?php echo e($typeLabel); ?></span></td>
                        <td><?php echo e($row['name'] ?? ''); ?></td>
                        <td class="cell-muted" style="max-width:280px;word-break:break-all;"><?php echo e($body); ?></td>
                        <td class="cell-muted">
                            <?php echo e(trim(($row['contact'] ?? '') . ' ' . ($row['email'] ?? ''))); ?>
                        </td>
                        <td class="cell-muted"><?php echo e($row['created_at'] ?? ''); ?></td>
                        <td><?php echo e($statusLabel); ?></td>
                        <td class="actions">
                            <?php if (!empty($row['id'])): ?>
                                <form method="post" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="id" value="<?php echo e($row['id']); ?>">
                                    <input type="hidden" name="status" value="read">
                                    <button type="submit" class="btn btn-secondary btn-sm">已读</button>
                                </form>
                                <form method="post" style="display:inline;" data-confirm="确定删除？">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo e($row['id']); ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">删除</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php admin_layout_end(); ?>
