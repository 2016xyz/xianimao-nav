<?php
/**
 * 系统安装向导
 */
define('ROOT_PATH', __DIR__);
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/install.php';

security_configure_session();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
security_send_headers();

// 已安装禁止公开重装（防止 ?force=1 未授权接管）
if (is_installed()) {
    header('Location: index.php');
    exit;
}

function h($s)
{
    return security_escape($s);
}

function install_csrf_token()
{
    if (empty($_SESSION['install_csrf'])) {
        $_SESSION['install_csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['install_csrf'];
}

function install_verify_csrf()
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    $expect = (string) ($_SESSION['install_csrf'] ?? '');
    return $token !== '' && $expect !== '' && hash_equals($expect, $token);
}

$env = install_check_environment();
$step = (int) ($_GET['step'] ?? 1);
if ($step < 1 || $step > 3) {
    $step = 1;
}

$error = '';
$success = '';
$ajax = isset($_GET['ajax']) || (isset($_POST['ajax']) && $_POST['ajax'] === '1');

// AJAX：测试数据库连接
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_db') {
    header('Content-Type: application/json; charset=utf-8');
    if (!install_verify_csrf()) {
        echo json_encode(['ok' => false, 'message' => '安全校验失败，请刷新页面'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $cfg = [
        'host' => trim($_POST['db_host'] ?? '127.0.0.1'),
        'port' => (int) ($_POST['db_port'] ?? 3306),
        'database' => trim($_POST['db_name'] ?? ''),
        'username' => trim($_POST['db_user'] ?? ''),
        'password' => (string) ($_POST['db_pass'] ?? ''),
        'charset' => 'utf8mb4',
    ];
    $create = !empty($_POST['create_database']);
    $result = install_test_db($cfg, $create);
    echo json_encode([
        'ok' => $result['ok'],
        'message' => $result['message'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 正式安装
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install') {
    if (!install_verify_csrf()) {
        $error = '安全校验失败，请刷新页面后重试';
        $step = 2;
    } elseif (!$env['ok']) {
        $error = '环境检测未通过，请先解决全部问题后再安装';
        $step = 1;
    } else {
        $result = install_run($_POST);
        if ($result['ok']) {
            $success = $result['message'];
            $step = 3;
        } else {
            $error = $result['message'];
            $step = 2;
        }
    }
}

$installCsrf = install_csrf_token();

// 环境通过后默认进入第 2 步
if ($step === 1 && $env['ok'] && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    // 停留在步骤 1 展示结果，用户点下一步
}

$old = [
    'db_host' => $_POST['db_host'] ?? '127.0.0.1',
    'db_port' => $_POST['db_port'] ?? '3306',
    'db_name' => $_POST['db_name'] ?? 'nav_site',
    'db_user' => $_POST['db_user'] ?? 'root',
    // 失败重试不回显数据库密码，避免出现在 HTML 源码
    'db_pass' => '',
    'create_database' => isset($_POST['create_database']) ? 1 : (empty($_POST) ? 1 : 0),
    'site_name' => $_POST['site_name'] ?? '夏尼猫网址导航',
    'site_subtitle' => $_POST['site_subtitle'] ?? '实用工具与优质站点聚合',
    'site_footer' => $_POST['site_footer'] ?? ('© ' . date('Y') . ' 夏尼猫网址导航'),
    'admin_user' => $_POST['admin_user'] ?? 'admin',
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统安装 - 夏尼猫网址导航</title>
    <style>
        :root {
            --bg: #f0f2f7;
            --panel: #fff;
            --text: #1e293b;
            --muted: #64748b;
            --border: #e2e8f0;
            --primary: #4f46e5;
            --ok: #059669;
            --err: #dc2626;
            --ok-bg: #ecfdf5;
            --err-bg: #fef2f2;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(99,102,241,.16), transparent 42%),
                radial-gradient(circle at bottom right, rgba(14,165,233,.12), transparent 42%),
                var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding: 32px 16px 48px;
        }
        .wrap { max-width: 720px; margin: 0 auto; }
        .brand { text-align: center; margin-bottom: 24px; }
        .brand h1 {
            margin: 0 0 8px;
            font-size: 1.6rem;
            background: linear-gradient(90deg,#a855f7,#3b82f6,#06b6d4,#22c55e);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .brand p { margin: 0; color: var(--muted); font-size: .9rem; }
        .steps {
            display: flex; gap: 8px; margin-bottom: 18px;
        }
        .step-pill {
            flex: 1; text-align: center; padding: 10px 8px;
            border-radius: 10px; background: #fff; border: 1px solid var(--border);
            font-size: .85rem; color: var(--muted);
        }
        .step-pill.active {
            border-color: var(--primary); color: var(--primary); font-weight: 600;
            background: #eef2ff;
        }
        .step-pill.done { color: var(--ok); border-color: #a7f3d0; }
        .card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(15,23,42,.06);
            padding: 24px;
        }
        .card h2 { margin: 0 0 8px; font-size: 1.15rem; }
        .hint { color: var(--muted); font-size: .88rem; margin: 0 0 18px; line-height: 1.55; }
        .alert {
            padding: 12px 14px; border-radius: 10px; margin-bottom: 16px; font-size: .9rem;
        }
        .alert-error { background: var(--err-bg); color: var(--err); border: 1px solid #fecaca; }
        .alert-success { background: var(--ok-bg); color: var(--ok); border: 1px solid #a7f3d0; }
        .check-list { list-style: none; margin: 0; padding: 0; }
        .check-list li {
            display: flex; justify-content: space-between; gap: 12px;
            padding: 12px 0; border-bottom: 1px solid var(--border); font-size: .9rem;
        }
        .check-list li:last-child { border-bottom: none; }
        .badge {
            flex-shrink: 0; font-size: .75rem; font-weight: 700;
            padding: 2px 8px; border-radius: 999px;
        }
        .badge-ok { background: var(--ok-bg); color: var(--ok); }
        .badge-err { background: var(--err-bg); color: var(--err); }
        .detail { color: var(--muted); font-size: .8rem; margin-top: 4px; }
        .form-grid { display: grid; gap: 14px; }
        .form-grid.two { grid-template-columns: 1fr 1fr; gap: 14px; }
        label { display: block; font-size: .85rem; font-weight: 600; margin-bottom: 6px; }
        input[type=text], input[type=password], input[type=number] {
            width: 100%; padding: 10px 12px; border: 1px solid var(--border);
            border-radius: 8px; font: inherit;
        }
        input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px #eef2ff; }
        .check-row { display: flex; align-items: center; gap: 8px; font-size: .9rem; }
        .btns { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 20px; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 10px 18px; border-radius: 8px; border: 1px solid transparent;
            font: inherit; font-weight: 600; font-size: .9rem; cursor: pointer; text-decoration: none;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-secondary { background: #fff; color: var(--text); border-color: var(--border); }
        .btn:disabled { opacity: .55; cursor: not-allowed; }
        .section-title {
            margin: 18px 0 10px; font-size: .95rem; color: var(--muted);
            border-top: 1px solid var(--border); padding-top: 16px;
        }
        .section-title:first-of-type { border-top: none; margin-top: 0; padding-top: 0; }
        #testResult { font-size: .85rem; margin-top: 8px; min-height: 1.2em; }
        .done-box { text-align: center; padding: 12px 0 8px; }
        .done-box .icon { font-size: 2.4rem; margin-bottom: 8px; }
        @media (max-width: 640px) {
            .form-grid.two { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <h1>夏尼猫网址导航</h1>
        <p>系统安装向导 · 需 MySQL 数据库</p>
    </div>

    <div class="steps">
        <div class="step-pill <?php echo $step === 1 ? 'active' : ($step > 1 ? 'done' : ''); ?>">1. 环境检测</div>
        <div class="step-pill <?php echo $step === 2 ? 'active' : ($step > 2 ? 'done' : ''); ?>">2. 配置安装</div>
        <div class="step-pill <?php echo $step === 3 ? 'active' : ''; ?>">3. 完成</div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo h($error); ?></div>
    <?php endif; ?>
    <?php if ($success && $step === 3): ?>
        <div class="alert alert-success"><?php echo h($success); ?></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
    <div class="card">
        <h2>环境检测</h2>
        <p class="hint">安装前会检测 PHP 版本、PDO/MySQL 扩展与目录写权限。若本机未安装 MySQL 服务，请先安装并启动 MySQL，再进行下一步。</p>
        <ul class="check-list">
            <?php foreach ($env['items'] as $item): ?>
                <li>
                    <div>
                        <div><?php echo h($item['name']); ?></div>
                        <div class="detail"><?php echo h($item['detail']); ?></div>
                    </div>
                    <span class="badge <?php echo $item['ok'] ? 'badge-ok' : 'badge-err'; ?>">
                        <?php echo $item['ok'] ? '通过' : '未通过'; ?>
                    </span>
                </li>
            <?php endforeach; ?>
            <li>
                <div>
                    <div>MySQL 服务</div>
                    <div class="detail">需本机或远程已安装并可连接的 MySQL / MariaDB（下一步填写连接信息测试）</div>
                </div>
                <span class="badge badge-ok">待配置</span>
            </li>
        </ul>
        <div class="btns">
            <a class="btn btn-secondary" href="install.php?step=1">重新检测</a>
            <?php if ($env['ok']): ?>
                <a class="btn btn-primary" href="install.php?step=2">下一步：配置数据库</a>
            <?php else: ?>
                <button class="btn btn-primary" type="button" disabled>请先解决环境问题</button>
            <?php endif; ?>
        </div>
    </div>
    <?php elseif ($step === 2): ?>
    <div class="card">
        <h2>数据库与站点配置</h2>
        <p class="hint">请填写可连接的 MySQL 信息。若账号有建库权限，可勾选「自动创建数据库」。</p>
        <form method="post" id="installForm" class="form-grid">
            <input type="hidden" name="action" value="install">
            <input type="hidden" name="csrf_token" value="<?php echo h($installCsrf); ?>">

            <div class="section-title">数据库</div>
            <div class="form-grid two">
                <div>
                    <label for="db_host">主机</label>
                    <input type="text" id="db_host" name="db_host" required value="<?php echo h($old['db_host']); ?>">
                </div>
                <div>
                    <label for="db_port">端口</label>
                    <input type="number" id="db_port" name="db_port" required value="<?php echo h($old['db_port']); ?>">
                </div>
            </div>
            <div>
                <label for="db_name">数据库名</label>
                <input type="text" id="db_name" name="db_name" required value="<?php echo h($old['db_name']); ?>">
            </div>
            <div class="form-grid two">
                <div>
                    <label for="db_user">用户名</label>
                    <input type="text" id="db_user" name="db_user" required value="<?php echo h($old['db_user']); ?>">
                </div>
                <div>
                    <label for="db_pass">密码</label>
                    <input type="password" id="db_pass" name="db_pass" value="<?php echo h($old['db_pass']); ?>" autocomplete="new-password">
                </div>
            </div>
            <label class="check-row">
                <input type="checkbox" name="create_database" value="1" <?php echo $old['create_database'] ? 'checked' : ''; ?>>
                自动创建数据库（需要账号具备 CREATE 权限）
            </label>
            <div>
                <button type="button" class="btn btn-secondary" id="btnTestDb">测试数据库连接</button>
                <div id="testResult"></div>
            </div>

            <div class="section-title">站点信息</div>
            <div>
                <label for="site_name">站点名称</label>
                <input type="text" id="site_name" name="site_name" required value="<?php echo h($old['site_name']); ?>">
            </div>
            <div>
                <label for="site_subtitle">副标题</label>
                <input type="text" id="site_subtitle" name="site_subtitle" value="<?php echo h($old['site_subtitle']); ?>">
            </div>
            <div>
                <label for="site_footer">页脚文字</label>
                <input type="text" id="site_footer" name="site_footer" value="<?php echo h($old['site_footer']); ?>">
            </div>

            <div class="section-title">管理员账号</div>
            <div>
                <label for="admin_user">用户名</label>
                <input type="text" id="admin_user" name="admin_user" required value="<?php echo h($old['admin_user']); ?>">
            </div>
            <div class="form-grid two">
                <div>
                    <label for="admin_pass">密码</label>
                    <input type="password" id="admin_pass" name="admin_pass" required minlength="8" autocomplete="new-password">
                </div>
                <div>
                    <label for="admin_pass2">确认密码</label>
                    <input type="password" id="admin_pass2" name="admin_pass2" required minlength="8" autocomplete="new-password">
                </div>
            </div>

            <div class="btns">
                <a class="btn btn-secondary" href="install.php?step=1">上一步</a>
                <button type="submit" class="btn btn-primary">开始安装</button>
            </div>
        </form>
    </div>
    <script src="assets/js/install-test-db.js"></script>
    <?php else: ?>
    <div class="card done-box">
        <div class="icon">✓</div>
        <h2>安装完成</h2>
        <p class="hint">系统已写入数据库配置与初始数据。请尽快登录后台修改默认密码，并妥善保管 config/database.php 与 install.lock。</p>
        <div class="btns" style="justify-content:center">
            <a class="btn btn-primary" href="index.php">打开前台</a>
            <a class="btn btn-secondary" href="admin/login.php">进入后台</a>
        </div>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
