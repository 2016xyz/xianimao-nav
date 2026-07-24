<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/layout.php';

$local = updater_local_version();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('error', '安全校验失败，请刷新后重试');
        redirect('update.php');
    }
    $action = security_enum((string) ($_POST['action'] ?? ''), [
        'check',
        'apply',
        'set_channel',
        'cleanup',
    ]);

    if ($action === 'check') {
        $remote = updater_fetch_remote(true);
        if (!empty($remote['ok'])) {
            $hint = !empty($remote['update_available'])
                ? ('发现新版本：' . ($remote['version'] ?? '') . ' / ' . ($remote['commit'] ?? ''))
                : '当前已是最新版本';
            admin_log_write('update_check', $hint, [
                'module' => 'update',
                'level' => !empty($remote['update_available']) ? 'warning' : 'info',
                'detail' => [
                    'local_version' => $local['version'] ?? '',
                    'local_commit' => $local['commit'] ?? '',
                    'remote_version' => $remote['version'] ?? '',
                    'remote_commit' => $remote['commit'] ?? '',
                    'update_available' => !empty($remote['update_available']),
                ],
            ]);
            flash_set('success', $hint);
        } else {
            admin_log_write('update_check_fail', '检测更新失败：' . ($remote['message'] ?? ''), [
                'module' => 'update',
                'level' => 'error',
            ]);
            flash_set('error', $remote['message'] ?? '检测失败');
        }
        redirect('update.php');
    }

    if ($action === 'set_channel') {
        $ch = security_enum((string) ($_POST['channel'] ?? 'master'), ['master', 'release']);
        if ($ch === null) {
            $ch = 'master';
        }
        if (updater_set_channel($ch)) {
            // 清缓存
            $meta = updater_tmp_dir() . '/remote_meta.json';
            if (is_file($meta)) {
                @unlink($meta);
            }
            admin_log_write('update_channel', '切换更新通道为 ' . $ch, [
                'module' => 'update',
                'level' => 'info',
                'detail' => ['channel' => $ch],
            ]);
            flash_set('success', '更新通道已设为 ' . ($ch === 'release' ? 'GitHub Release' : 'master 分支'));
        } else {
            flash_set('error', '保存通道失败，请检查 config 目录写权限');
        }
        redirect('update.php');
    }

    if ($action === 'cleanup') {
        updater_cleanup_tmp();
        admin_log_write('update_cleanup', '清理更新临时文件', [
            'module' => 'update',
            'level' => 'info',
        ]);
        flash_set('success', '已清理更新临时文件');
        redirect('update.php');
    }

    if ($action === 'apply') {
        $confirm = (string) ($_POST['confirm_text'] ?? '');
        if ($confirm !== 'UPDATE') {
            flash_set('error', '请在确认框输入 UPDATE 后再执行在线更新');
            redirect('update.php');
        }
        $remote = updater_fetch_remote(true);
        $result = updater_apply($remote);
        admin_log_write(
            !empty($result['ok']) ? 'update_apply' : 'update_apply_fail',
            $result['message'] ?? '执行系统更新',
            [
                'module' => 'update',
                'level' => !empty($result['ok']) ? 'warning' : 'error',
                'detail' => [
                    'ok' => !empty($result['ok']),
                    'copied' => $result['detail']['copied'] ?? 0,
                    'version' => $result['detail']['version'] ?? null,
                ],
            ]
        );
        flash_set(!empty($result['ok']) ? 'success' : 'error', $result['message'] ?? '更新结束');
        redirect('update.php');
    }

    flash_set('error', '无效操作');
    redirect('update.php');
}

$env = updater_env_check();
$remote = updater_fetch_remote(false);
$local = updater_local_version(); // 可能被其它请求改写，再读一次

$channelLabel = ($local['channel'] ?? 'master') === 'release' ? 'GitHub Release' : 'master 分支';
$hasUpdate = !empty($remote['ok']) && !empty($remote['update_available']);

admin_layout_start('系统更新', 'update');
?>
<div class="panel">
    <div class="panel-head">
        <div>
            <h2>系统更新</h2>
            <p class="muted">从 GitHub 官方仓库检测并应用程序更新。数据库配置、上传文件与运行时数据不会被覆盖。</p>
        </div>
        <div class="toolbar" style="gap:8px;flex-wrap:wrap;">
            <span class="tag">v<?php echo e($local['version'] ?? '0.0.0'); ?></span>
            <?php if (!empty($local['commit'])): ?>
                <span class="tag"><?php echo e($local['commit']); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-grid" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;margin-bottom:18px;">
        <div class="stat-card" style="padding:14px 16px;border:1px solid var(--border,#e2e8f0);border-radius:12px;background:#fff;">
            <div class="muted" style="font-size:12px;">本地版本</div>
            <div style="font-size:20px;font-weight:700;margin-top:4px;">v<?php echo e($local['version'] ?? ''); ?></div>
            <div class="muted" style="font-size:12px;margin-top:6px;">
                build <?php echo e($local['build'] ?? '—'); ?>
                · commit <?php echo e($local['commit'] !== '' ? $local['commit'] : '—'); ?>
            </div>
        </div>
        <div class="stat-card" style="padding:14px 16px;border:1px solid var(--border,#e2e8f0);border-radius:12px;background:#fff;">
            <div class="muted" style="font-size:12px;">远程版本</div>
            <?php if (!empty($remote['ok'])): ?>
                <div style="font-size:20px;font-weight:700;margin-top:4px;">v<?php echo e($remote['version'] ?? ''); ?></div>
                <div class="muted" style="font-size:12px;margin-top:6px;">
                    <?php echo e($remote['name'] ?? ''); ?>
                    · <?php echo e($remote['commit'] ?? ''); ?>
                </div>
            <?php else: ?>
                <div style="font-size:16px;font-weight:600;margin-top:8px;color:#b45309;">检测失败</div>
                <div class="muted" style="font-size:12px;margin-top:6px;"><?php echo e($remote['message'] ?? '无法连接更新源'); ?></div>
            <?php endif; ?>
        </div>
        <div class="stat-card" style="padding:14px 16px;border:1px solid var(--border,#e2e8f0);border-radius:12px;background:#fff;">
            <div class="muted" style="font-size:12px;">更新状态</div>
            <?php if ($hasUpdate): ?>
                <div style="font-size:18px;font-weight:700;margin-top:6px;color:#b45309;">有可用更新</div>
            <?php elseif (!empty($remote['ok'])): ?>
                <div style="font-size:18px;font-weight:700;margin-top:6px;color:#166534;">已是最新</div>
            <?php else: ?>
                <div style="font-size:18px;font-weight:700;margin-top:6px;color:#64748b;">未知</div>
            <?php endif; ?>
            <div class="muted" style="font-size:12px;margin-top:6px;">
                通道：<?php echo e($channelLabel); ?>
                <?php if (!empty($remote['checked_at'])): ?>
                    · 检测于 <?php echo e($remote['checked_at']); ?>
                    <?php if (!empty($remote['from_cache'])): ?>（缓存）<?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="stat-card" style="padding:14px 16px;border:1px solid var(--border,#e2e8f0);border-radius:12px;background:#fff;">
            <div class="muted" style="font-size:12px;">仓库</div>
            <div style="font-size:14px;font-weight:600;margin-top:8px;word-break:break-all;">
                <?php if (!empty($local['repo_url'])): ?>
                    <a href="<?php echo e($local['repo_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo e($local['repo'] ?? ''); ?></a>
                <?php else: ?>
                    <?php echo e($local['repo'] ?? ''); ?>
                <?php endif; ?>
            </div>
            <?php if (!empty($remote['html_url'])): ?>
                <div class="muted" style="font-size:12px;margin-top:8px;">
                    <a href="<?php echo e($remote['html_url']); ?>" target="_blank" rel="noopener noreferrer">查看远程变更</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="btn-row" style="flex-wrap:wrap;gap:8px;margin-bottom:20px;">
        <form method="post" style="display:inline;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="check">
            <button type="submit" class="btn btn-primary">立即检测更新</button>
        </form>
        <form method="post" style="display:inline;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="cleanup">
            <button type="submit" class="btn btn-secondary">清理临时文件</button>
        </form>
    </div>

    <?php if (!empty($remote['ok']) && trim((string) ($remote['body'] ?? '')) !== ''): ?>
        <div class="panel" style="margin-bottom:18px;padding:14px 16px;border:1px solid var(--border,#e2e8f0);border-radius:12px;">
            <h3 style="margin:0 0 8px;font-size:15px;">远程说明 / 最近提交</h3>
            <pre style="margin:0;white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.5;max-height:180px;overflow:auto;background:#f8fafc;padding:10px 12px;border-radius:8px;"><?php echo e($remote['body']); ?></pre>
        </div>
    <?php endif; ?>

    <div class="panel" style="margin-bottom:18px;padding:16px;border:1px solid var(--border,#e2e8f0);border-radius:12px;">
        <h3 style="margin:0 0 10px;font-size:15px;">更新通道</h3>
        <p class="muted" style="margin:0 0 12px;">master：始终跟随仓库主分支最新提交；Release：使用 GitHub 最新正式发行版（无 release 时自动回退 master）。</p>
        <form method="post" class="btn-row" style="flex-wrap:wrap;gap:8px;align-items:center;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="set_channel">
            <label style="display:flex;align-items:center;gap:6px;font-size:14px;">
                <input type="radio" name="channel" value="master" <?php echo ($local['channel'] ?? '') !== 'release' ? 'checked' : ''; ?>>
                master 分支
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:14px;">
                <input type="radio" name="channel" value="release" <?php echo ($local['channel'] ?? '') === 'release' ? 'checked' : ''; ?>>
                GitHub Release
            </label>
            <button type="submit" class="btn btn-secondary btn-sm">保存通道</button>
        </form>
    </div>

    <div class="panel" style="margin-bottom:18px;padding:16px;border:1px solid var(--border,#e2e8f0);border-radius:12px;">
        <h3 style="margin:0 0 10px;font-size:15px;">环境检查</h3>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>项目</th>
                        <th style="width:90px">状态</th>
                        <th>说明</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($env['items'] as $it): ?>
                        <tr>
                            <td><?php echo e($it['name'] ?? ''); ?></td>
                            <td>
                                <?php if (!empty($it['ok'])): ?>
                                    <span class="log-level log-level-success">通过</span>
                                <?php else: ?>
                                    <span class="log-level log-level-error">失败</span>
                                <?php endif; ?>
                            </td>
                            <td class="cell-muted" style="font-size:12px;word-break:break-all;"><?php echo e($it['detail'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel" style="padding:16px;border:1px dashed #f59e0b;border-radius:12px;background:#fffbeb;">
        <h3 style="margin:0 0 8px;font-size:15px;color:#92400e;">在线更新</h3>
        <ul class="muted" style="margin:0 0 14px;padding-left:18px;font-size:13px;line-height:1.6;">
            <li>将覆盖程序代码（admin / includes / assets 等），请确认站点目录可写。</li>
            <li><strong>不会覆盖</strong>：数据库配置、install.lock、站点内容、留言、上传图片、缓存与密钥凭证文件。</li>
            <li>更新后会自动执行表结构补齐（ensure_extra_tables）。建议更新前自行备份数据库与站点。</li>
            <li>服务器需能访问 GitHub（api.github.com / github.com）。</li>
        </ul>
        <form method="post" data-confirm="确定从 GitHub 下载并覆盖程序文件？请确认已备份。">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="apply">
            <div class="form-group" style="max-width:320px;margin-bottom:12px;">
                <label for="confirm_text">确认操作（请输入 UPDATE）</label>
                <input type="text" id="confirm_text" name="confirm_text" maxlength="20" placeholder="UPDATE" autocomplete="off" <?php echo ($hasUpdate && !empty($env['ok'])) ? '' : 'disabled'; ?>>
            </div>
            <button type="submit" class="btn btn-danger-soft" <?php echo ($hasUpdate && !empty($env['ok'])) ? '' : 'disabled'; ?>>
                <?php echo $hasUpdate ? '下载并应用更新' : '暂无可用更新'; ?>
            </button>
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
.log-level-success { background: #dcfce7; color: #166534; }
.log-level-error { background: #fee2e2; color: #991b1b; }
</style>
<?php admin_layout_end(); ?>
