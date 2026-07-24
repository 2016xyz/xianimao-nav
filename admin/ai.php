<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/layout.php';
require_once ROOT_PATH . '/includes/ai.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('error', '安全校验失败，请刷新后重试');
        redirect('ai.php');
    }

    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        $input = [
            'base_url' => trim((string) ($_POST['base_url'] ?? '')),
            'model' => trim((string) ($_POST['model'] ?? '')),
            'enabled' => !empty($_POST['enabled']),
        ];
        if (!empty($_POST['update_key'])) {
            $input['api_key'] = trim((string) ($_POST['api_key'] ?? ''));
            $input['api_key_submitted'] = '1';
        }
        if (!empty($_POST['clear_key'])) {
            $input['clear_key'] = '1';
            $input['api_key_submitted'] = '1';
            $input['api_key'] = '';
        }
        if (ai_config_save($input)) {
            flash_set('success', 'AI 配置已保存');
        } else {
            flash_set('error', '保存失败，请检查数据库连接与 settings 表写权限');
        }
        redirect('ai.php');
    }

    if ($action === 'fetch_models') {
        // 若本次一并提交了新 key / url，先保存
        $input = [
            'base_url' => trim((string) ($_POST['base_url'] ?? '')),
            'model' => trim((string) ($_POST['model'] ?? '')),
            'enabled' => !empty($_POST['enabled']),
        ];
        if (!empty($_POST['update_key'])) {
            $input['api_key'] = trim((string) ($_POST['api_key'] ?? ''));
            $input['api_key_submitted'] = '1';
        }
        ai_config_save($input);

        $result = ai_fetch_models();
        if (!empty($result['ok'])) {
            flash_set('success', $result['message'] ?? '模型列表已更新');
        } else {
            flash_set('error', $result['message'] ?? '拉取失败');
        }
        redirect('ai.php');
    }

    if ($action === 'test') {
        $result = ai_generate_site_desc('示例工具站', 'https://example.com/', '演示用');
        if (!empty($result['ok'])) {
            flash_set('success', '测试成功：' . ($result['text'] ?? ''));
        } else {
            flash_set('error', $result['message'] ?? '测试失败');
        }
        redirect('ai.php');
    }
}

$cfg = ai_config_load();
$hasKey = $cfg['api_key'] !== '';
$keyHint = $hasKey
    ? (substr($cfg['api_key'], 0, 4) . '…' . substr($cfg['api_key'], -4) . '（' . strlen($cfg['api_key']) . ' 字符）')
    : '';

admin_layout_start('AI 配置', 'ai');
?>
<div class="panel">
    <div class="panel-head">
        <div>
            <h2>AI 接口配置</h2>
            <p class="muted">配置兼容 OpenAI 协议的接口（DeepSeek、OpenAI、通义兼容模式、自建网关等）。填写后可自动拉取模型，并在「自营站点」中一键生成介绍。</p>
        </div>
    </div>

    <div class="switch-bar" style="margin-bottom:14px;">
        <span>
            状态：
            <?php if (ai_is_ready()): ?>
                <strong style="color:#047857;">可用</strong>
                · 模型 <?php echo e($cfg['model']); ?>
            <?php elseif ($hasKey): ?>
                <strong style="color:#b45309;">已配 Key，请选择模型并启用</strong>
            <?php else: ?>
                <strong style="color:#b45309;">未配置</strong>
            <?php endif; ?>
        </span>
        <?php if (!empty($cfg['updated_at'])): ?>
            <span class="muted">更新于 <?php echo e($cfg['updated_at']); ?></span>
        <?php endif; ?>
    </div>

    <form method="post" class="stack-form" id="ai-form">
        <?php echo csrf_field(); ?>

        <label class="switch-label">
            <input type="checkbox" name="enabled" value="1" <?php echo !empty($cfg['enabled']) ? 'checked' : ''; ?>>
            <span>启用 AI 生成（站点编辑页显示「AI 生成介绍」按钮）</span>
        </label>

        <label>
            <span>API Base URL</span>
            <input type="url" name="base_url" required value="<?php echo e($cfg['base_url']); ?>" placeholder="https://api.openai.com/v1 或 https://api.deepseek.com/v1">
        </label>
        <p class="muted" style="margin:-8px 0 12px;font-size:0.88rem;">
            示例：OpenAI <code>https://api.openai.com/v1</code> · DeepSeek <code>https://api.deepseek.com/v1</code> · 需包含 <code>/v1</code>
        </p>

        <label>
            <span>API Key <?php if ($hasKey): ?><em class="muted">当前：<?php echo e($keyHint); ?></em><?php endif; ?></span>
            <input type="password" name="api_key" value="" autocomplete="new-password" placeholder="<?php echo $hasKey ? '已配置，勾选下方后填写新 Key' : 'sk-...'; ?>">
        </label>
        <label class="switch-label">
            <input type="checkbox" name="update_key" value="1">
            <span>更新 API Key（勾选后才用上方内容覆盖）</span>
        </label>
        <label class="switch-label">
            <input type="checkbox" name="clear_key" value="1">
            <span>清除 API Key</span>
        </label>

        <label>
            <span>当前模型</span>
            <?php if (!empty($cfg['models'])): ?>
                <select name="model">
                    <option value="">请选择</option>
                    <?php foreach ($cfg['models'] as $m): ?>
                        <option value="<?php echo e($m); ?>" <?php echo $cfg['model'] === $m ? 'selected' : ''; ?>><?php echo e($m); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input type="text" name="model" value="<?php echo e($cfg['model']); ?>" placeholder="先点「自动获取模型」，或手动填写如 gpt-4o-mini / deepseek-chat">
            <?php endif; ?>
        </label>
        <?php if (!empty($cfg['models'])): ?>
            <p class="muted" style="margin:-8px 0 12px;font-size:0.88rem;">已缓存 <?php echo count($cfg['models']); ?> 个模型，可重新获取刷新。</p>
        <?php endif; ?>

        <div class="form-actions" style="flex-wrap:wrap;gap:8px;">
            <button type="submit" name="action" value="save" class="btn btn-primary">保存配置</button>
            <button type="submit" name="action" value="fetch_models" class="btn btn-secondary" formnovalidate>自动获取模型</button>
            <button type="submit" name="action" value="test" class="btn btn-secondary" formnovalidate>测试生成</button>
        </div>
    </form>
</div>
<?php admin_layout_end(); ?>
