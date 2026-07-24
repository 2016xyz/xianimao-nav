<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/layout.php';

$data = load_content();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf'] ?? '')) {
        flash_set('error', '安全校验失败，请刷新后重试');
        redirect('projects.php');
    }

    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        $deleteId = trim((string) ($_POST['id'] ?? ''));
        $data['projects'] = array_values(array_filter($data['projects'] ?? [], static function ($item) use ($deleteId) {
            return ($item['id'] ?? '') !== $deleteId;
        }));
        if (save_content($data)) {
            flash_set('success', '已删除该开源项目');
        } else {
            flash_set('error', '删除失败，请检查数据库连接');
        }
        redirect('projects.php');
    }

    $names = $_POST['name'] ?? [];
    $urls = $_POST['url'] ?? [];
    $descs = $_POST['desc'] ?? [];
    $ids = $_POST['id'] ?? [];
    $projects = [];

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
            $projects[] = [
                'id' => $id,
                'name' => $name,
                'url' => $url,
                'desc' => $desc,
            ];
        }
    }

    $data['projects'] = $projects;
    if (save_content($data)) {
        flash_set('success', '开源项目已保存（共 ' . count($projects) . ' 项）');
    } else {
        flash_set('error', '保存失败，请检查数据库连接');
    }
    redirect('projects.php');
}

admin_layout_start('开源项目', 'projects');
$items = $data['projects'] ?? [];
?>
<div class="panel">
    <div class="panel-head">
        <div>
            <h2>开源项目管理</h2>
            <p class="muted">前台「开源项目」区块展示，支持添加、修改、删除。删除请点行末「删除」后保存。</p>
        </div>
        <button type="button" class="btn btn-primary" data-add-row data-template="project-row-tpl">＋ 添加项目</button>
    </div>

    <form method="post" class="stack-form">
        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="save">

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:16%">名称</th>
                        <th style="width:24%">链接</th>
                        <th style="width:12%">标签</th>
                        <th>简介</th>
                        <th style="width:90px">操作</th>
                    </tr>
                </thead>
                <tbody id="rows">
                    <?php if (empty($items)): ?>
                        <tr class="empty-row">
                            <td colspan="5" class="muted" style="text-align:center;padding:28px;">
                                暂无项目，点击右上角「添加项目」开始
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <input type="hidden" name="id[]" value="<?php echo e($item['id'] ?? ''); ?>">
                                <input type="text" name="name[]" value="<?php echo e($item['name'] ?? ''); ?>" required placeholder="项目名称">
                            </td>
                            <td>
                                <input type="url" name="url[]" value="<?php echo e($item['url'] ?? ''); ?>" required placeholder="https://github.com/...">
                            </td>
                            <td>
                                <input type="text" name="tag[]" value="<?php echo e($item['tag'] ?? ''); ?>" placeholder="标签" maxlength="40">
                            </td>
                            <td>
                                <input type="text" name="desc[]" value="<?php echo e($item['desc'] ?? ''); ?>" placeholder="项目简介">
                            </td>
                            <td class="actions">
                                <button type="button" class="btn btn-danger btn-sm" data-remove-row data-confirm="确定删除这一行？（需再点保存）">删除</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">保存全部更改</button>
            <span class="muted">当前 <?php echo count($items); ?> 个项目</span>
        </div>
    </form>
</div>

<template id="project-row-tpl">
    <tr>
        <td>
            <input type="hidden" name="id[]" value="">
            <input type="text" name="name[]" value="" required placeholder="项目名称">
        </td>
        <td>
            <input type="url" name="url[]" value="" required placeholder="https://github.com/...">
        </td>
        <td>
            <input type="text" name="tag[]" value="" placeholder="标签" maxlength="40">
        </td>
        <td>
            <input type="text" name="desc[]" value="" placeholder="项目简介">
        </td>
        <td class="actions">
            <button type="button" class="btn btn-danger btn-sm" data-remove-row>删除</button>
        </td>
    </tr>
</template>
<?php admin_layout_end(); ?>
