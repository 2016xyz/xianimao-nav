<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/layout.php';
require_login();

$username = $_SESSION['admin_username'] ?? 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('error', '请求无效，请刷新后重试');
        redirect('password.php');
    }

    $old = (string) ($_POST['old_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    if (strlen($old) > 256) {
        $old = substr($old, 0, 256);
    }
    if (strlen($new) > 256) {
        $new = substr($new, 0, 256);
    }

    $admin = load_admin_by_username($username);
    if (!$admin || empty($admin['password_hash']) || !password_verify($old, $admin['password_hash'])) {
        flash_set('error', '当前密码不正确');
        redirect('password.php');
    }
    if (strlen($new) < 8) {
        flash_set('error', '新密码至少 8 位');
        redirect('password.php');
    }
    if ($new !== $confirm) {
        flash_set('error', '两次输入的新密码不一致');
        redirect('password.php');
    }

    try {
        if (update_admin_password($username, password_hash($new, PASSWORD_DEFAULT))) {
            flash_set('success', '密码已修改，请牢记新密码');
        } else {
            flash_set('error', '密码更新失败');
        }
    } catch (Throwable $e) {
        flash_set('error', '密码更新失败，请稍后重试');
    }
    redirect('password.php');
}

admin_layout_start('修改密码', 'password');
?>
<div class="panel">
    <p class="hint">当前登录用户：<?php echo e($username); ?>（保存在 MySQL admins 表）</p>
    <form method="post" class="form-grid" style="max-width:420px">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label for="old_password">当前密码</label>
            <input type="password" id="old_password" name="old_password" required autocomplete="current-password">
        </div>
        <div class="form-group">
            <label for="new_password">新密码</label>
            <input type="password" id="new_password" name="new_password" required minlength="8" maxlength="256" autocomplete="new-password">
        </div>
        <div class="form-group">
            <label for="confirm_password">确认新密码</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8" maxlength="256" autocomplete="new-password">
        </div>
        <div class="btn-row">
            <button type="submit" class="btn btn-primary">更新密码</button>
        </div>
    </form>
</div>
<?php admin_layout_end(); ?>
