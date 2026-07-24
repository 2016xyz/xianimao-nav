<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('error', '安全校验失败，请刷新后重试');
        redirect('logs.php');
    }
    $action = security_enum((string) ($_POST['action'] ?? ''), ['clear']);
    if ($action === 'clear') {
        $keep = security_enum((string) ($_POST['keep_days'] ?? '0'), ['0', '7', '30', '90']);
        $keepDays = $keep !== null ? (int) $keep : 0;
        $result = admin_log_clear($keepDays);
        admin_log_write('logs_clear', $result['message'] ?? '清理日志', [
            'module' => 'logs',
            'level' => 'warning',
            'detail' => ['keep_days' => $keepDays, 'deleted' => $result['deleted'] ?? 0],
        ]);
        flash_set(!empty($result['ok']) ? 'success' : 'error', $result['message'] ?? '操作完成');
        redirect('logs.php');
    }
    flash_set('error', '无效操作');
    redirect('logs.php');
}

$filters = [
    'module' => security_clean_text((string) ($_GET['module'] ?? ''), 40),
    'level' => (string) ($_GET['level'] ?? ''),
    'username' => security_clean_text((string) ($_GET['username'] ?? ''), 64),
    'q' => security_clean_text((string) ($_GET['q'] ?? ''), 100),
    'date_from' => (string) ($_GET['date_from'] ?? ''),
    'date_to' => (string) ($_GET['date_to'] ?? ''),
];
if ($filters['level'] !== '' && security_enum($filters['level'], admin_log_levels()) === null) {
    $filters['level'] = '';
}
if ($filters['date_from'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
    $filters['date_from'] = '';
}
if ($filters['date_to'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
    $filters['date_to'] = '';
}

$page = security_int($_GET['page'] ?? 1, 1, 10000);
if ($page === null) {
    $page = 1;
}

$result = admin_log_list($filters, $page, 50);
$items = $result['items'];
$total = $result['total'];
$pages = $result['pages'];
$curPage = $result['page'];

$moduleLabels = [
    'auth' => '登录认证',
    'settings' => '站点设置',
    'engines' => '搜索引擎',
    'shortcuts' => '快捷入口',
    'sites' => '自营站点',
    'projects' => '开源项目',
    'tools' => '实用工具',
    'links' => '友情链接',
    'hotboards' => '今日热榜',
    'messages' => '留言管理',
    'ai' => 'AI 配置',
    'smtp' => 'SMTP',
    'password' => '密码',
    'logs' => '操作日志',
    'system' => '系统',
];
$levelLabels = [
    'info' => '信息',
    'success' => '成功',
    'warning' => '警告',
    'error' => '错误',
];

$qs = static function (array $extra = []) use ($filters, $curPage) {
    $params = array_merge([
        'module' => $filters['module'],
        'level' => $filters['level'],
        'username' => $filters['username'],
        'q' => $filters['q'],
        'date_from' => $filters['date_from'],
        'date_to' => $filters['date_to'],
        'page' => $curPage,
    ], $extra);
    $params = array_filter($params, static function ($v) {
        return $v !== '' && $v !== null;
    });
    return 'logs.php' . ($params ? ('?' . http_build_query($params)) : '');
};

admin_layout_start('操作日志', 'logs');
?>
<div class="panel">
    <div class="panel-head">
        <div>
            <h2>操作日志</h2>
            <p class="muted">记录后台登录、配置变更与敏感操作，便于审计与排障。密钥类字段已脱敏。</p>
        </div>
        <div class="toolbar" style="gap:8px;">
            <span class="tag">共 <?php echo (int) $total; ?> 条</span>
        </div>
    </div>

    <form method="get" class="form-grid" style="margin-bottom:16px;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));align-items:end;">
        <div class="form-group">
            <label for="f_module">模块</label>
            <select id="f_module" name="module">
                <option value="">全部</option>
                <?php foreach ($moduleLabels as $mk => $ml): ?>
                    <option value="<?php echo e($mk); ?>" <?php echo $filters['module'] === $mk ? 'selected' : ''; ?>><?php echo e($ml); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="f_level">级别</label>
            <select id="f_level" name="level">
                <option value="">全部</option>
                <?php foreach ($levelLabels as $lk => $ll): ?>
                    <option value="<?php echo e($lk); ?>" <?php echo $filters['level'] === $lk ? 'selected' : ''; ?>><?php echo e($ll); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="f_user">用户名</label>
            <input type="text" id="f_user" name="username" value="<?php echo e($filters['username']); ?>" maxlength="64" placeholder="管理员">
        </div>
        <div class="form-group">
            <label for="f_q">关键词</label>
            <input type="text" id="f_q" name="q" value="<?php echo e($filters['q']); ?>" maxlength="100" placeholder="动作 / 摘要">
        </div>
        <div class="form-group">
            <label for="f_from">开始日期</label>
            <input type="date" id="f_from" name="date_from" value="<?php echo e($filters['date_from']); ?>">
        </div>
        <div class="form-group">
            <label for="f_to">结束日期</label>
            <input type="date" id="f_to" name="date_to" value="<?php echo e($filters['date_to']); ?>">
        </div>
        <div class="form-group" style="display:flex;gap:8px;flex-wrap:wrap;">
            <button type="submit" class="btn btn-primary btn-sm">筛选</button>
            <a class="btn btn-secondary btn-sm" href="logs.php">重置</a>
        </div>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:70px">ID</th>
                    <th style="width:150px">时间</th>
                    <th style="width:100px">用户</th>
                    <th style="width:90px">模块</th>
                    <th style="width:70px">级别</th>
                    <th style="width:120px">动作</th>
                    <th>摘要</th>
                    <th style="width:120px">IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="8" class="muted" style="text-align:center;padding:28px;">暂无日志</td></tr>
                <?php endif; ?>
                <?php foreach ($items as $row): ?>
                    <?php
                    $lv = $row['level'] ?? 'info';
                    $lvClass = in_array($lv, ['info', 'success', 'warning', 'error'], true) ? $lv : 'info';
                    $mod = $row['module'] ?? '';
                    $modLabel = $moduleLabels[$mod] ?? $mod;
                    $detail = trim((string) ($row['detail'] ?? ''));
                    ?>
                    <tr>
                        <td class="cell-muted"><?php echo e((string) ($row['id'] ?? '')); ?></td>
                        <td class="cell-muted" style="white-space:nowrap;"><?php echo e($row['created_at'] ?? ''); ?></td>
                        <td><?php
                            $uname = (string) ($row['username'] ?? '');
                            echo $uname !== '' ? e($uname) : '<span class="muted">—</span>';
                        ?></td>
                        <td><span class="tag"><?php echo e($modLabel); ?></span></td>
                        <td><span class="log-level log-level-<?php echo e($lvClass); ?>"><?php echo e($levelLabels[$lvClass] ?? $lv); ?></span></td>
                        <td><code style="font-size:12px;"><?php echo e($row['action'] ?? ''); ?></code></td>
                        <td style="max-width:320px;word-break:break-all;">
                            <?php echo e($row['message'] ?? ''); ?>
                            <?php if ($detail !== ''): ?>
                                <details class="log-detail">
                                    <summary class="muted" style="cursor:pointer;font-size:12px;">详情</summary>
                                    <pre class="log-detail-pre"><?php echo e($detail); ?></pre>
                                </details>
                            <?php endif; ?>
                        </td>
                        <td class="cell-muted" style="font-size:12px;"><?php echo e($row['ip'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="filter-tabs" style="margin-top:14px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
            <?php if ($curPage > 1): ?>
                <a class="btn btn-sm btn-secondary" href="<?php echo e($qs(['page' => $curPage - 1])); ?>">上一页</a>
            <?php endif; ?>
            <span class="muted" style="padding:0 8px;">第 <?php echo (int) $curPage; ?> / <?php echo (int) $pages; ?> 页</span>
            <?php if ($curPage < $pages): ?>
                <a class="btn btn-sm btn-secondary" href="<?php echo e($qs(['page' => $curPage + 1])); ?>">下一页</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="panel" style="margin-top:20px;padding:16px;border:1px dashed var(--border, #e2e8f0);">
        <h3 style="margin:0 0 8px;font-size:15px;">清理日志</h3>
        <p class="muted" style="margin:0 0 12px;">清理操作本身会写入一条警告日志。建议定期清理，避免表无限增长。</p>
        <form method="post" class="btn-row" style="flex-wrap:wrap;gap:8px;" data-confirm="确定清理所选范围的日志？此操作不可恢复。">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="clear">
            <button type="submit" name="keep_days" value="90" class="btn btn-secondary btn-sm">删除 90 天前</button>
            <button type="submit" name="keep_days" value="30" class="btn btn-secondary btn-sm">删除 30 天前</button>
            <button type="submit" name="keep_days" value="7" class="btn btn-secondary btn-sm">删除 7 天前</button>
            <button type="submit" name="keep_days" value="0" class="btn btn-danger-soft btn-sm">清空全部</button>
        </form>
    </div>
</div>
<style>
.log-level {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
}
.log-level-info { background: #e2e8f0; color: #334155; }
.log-level-success { background: #dcfce7; color: #166534; }
.log-level-warning { background: #fef3c7; color: #92400e; }
.log-level-error { background: #fee2e2; color: #991b1b; }
.log-detail { margin-top: 4px; }
.log-detail-pre {
  margin: 6px 0 0;
  padding: 8px 10px;
  background: #f8fafc;
  border-radius: 8px;
  font-size: 11px;
  line-height: 1.45;
  white-space: pre-wrap;
  word-break: break-all;
  max-height: 160px;
  overflow: auto;
}
</style>
<?php admin_layout_end(); ?>
