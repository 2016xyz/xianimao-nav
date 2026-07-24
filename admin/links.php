<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/layout.php';

$data = load_content();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('error', '安全校验失败，请刷新后重试');
        redirect('links.php');
    }

    $action = $_POST['action'] ?? 'save';
    if ($action === 'toggle_show') {
        $show = !empty($_POST['show_friend_links']) ? '1' : '0';
        $data['site']['show_friend_links'] = $show;
        if (save_content($data)) {
            flash_set('success', $show === '1' ? '已开启前台友情链接显示' : '已关闭前台友情链接显示');
        } else {
            flash_set('error', '保存失败');
        }
        redirect('links.php');
    }

    $names = $_POST['name'] ?? [];
    $urls = $_POST['url'] ?? [];
    $descs = $_POST['desc'] ?? [];
    $ids = $_POST['id'] ?? [];
    $links = [];

    if (is_array($names)) {
        foreach ($names as $i => $name) {
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
            $links[] = [
                'id' => $id,
                'name' => $name,
                'url' => $url,
                'desc' => $desc,
            ];
        }
    }

    $data['links'] = $links;
    $data['site']['show_friend_links'] = !empty($_POST['show_friend_links']) ? '1' : '0';
    if (save_content($data)) {
        flash_set('success', '友情链接已保存（共 ' . count($links) . ' 项）');
    } else {
        flash_set('error', '保存失败，请检查数据库连接');
    }
    redirect('links.php');
}

admin_layout_start('友情链接', 'links');
$items = $data['links'] ?? [];
$show = !isset($data['site']['show_friend_links']) || $data['site']['show_friend_links'] !== '0';
?>
<div class="panel">
    <div class="panel-head">
        <div>
            <h2>友情链接</h2>
            <p class="muted">管理友链列表，并可控制前台是否显示「友情链接」整块区域。</p>
        </div>
        <button type="button" class="btn btn-primary" data-add-row data-template="link-row-tpl">＋ 添加友链</button>
    </div>

    <form method="post" class="stack-form">
        <?php echo csrf_field(); ?>
        <div class="switch-bar">
            <label class="switch-label">
                <input type="checkbox" name="show_friend_links" value="1" <?php echo $show ? 'checked' : ''; ?>>
                <span>前台显示友情链接</span>
            </label>
            <span class="muted">关闭后前台不展示该区块，列表数据仍保留</span>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:18%">名称</th>
                        <th style="width:28%">链接</th>
                        <th>简介</th>
                        <th style="width:90px">操作</th>
                    </tr>
                </thead>
                <tbody id="rows">
                    <?php if (empty($items)): ?>
                        <tr class="empty-row">
                            <td colspan="4" class="muted" style="text-align:center;padding:28px;">暂无友链，点击右上角添加</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <input type="hidden" name="id[]" value="<?php echo e($item['id'] ?? ''); ?>">
                                <input type="text" name="name[]" value="<?php echo e($item['name'] ?? ''); ?>" required>
                            </td>
                            <td>
                                <input type="url" name="url[]" value="<?php echo e($item['url'] ?? ''); ?>" required>
                            </td>
                            <td>
                                <input type="text" name="desc[]" value="<?php echo e($item['desc'] ?? ''); ?>">
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
            <span class="muted">当前 <?php echo count($items); ?> 项 · 显示状态：<?php echo $show ? '开启' : '关闭'; ?></span>
        </div>
    </form>
</div>

<template id="link-row-tpl">
    <tr>
        <td>
            <input type="hidden" name="id[]" value="">
            <input type="text" name="name[]" value="" required placeholder="站点名称">
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
<?php admin_layout_end(); ?>
