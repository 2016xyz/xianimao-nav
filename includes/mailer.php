<?php
/**
 * SMTP 配置与发送（纯 PHP socket，无需 PHPMailer）
 */

if (!defined('ROOT_PATH')) {
    require_once __DIR__ . '/bootstrap.php';
}

function smtp_config_file()
{
    return ROOT_PATH . '/config/smtp.json';
}

/**
 * @return array{
 *   enabled:string,host:string,port:int,encryption:string,user:string,pass:string,
 *   from_email:string,from_name:string,login_email_verify:string,login_email:string,
 *   updated_at:string
 * }
 */
function smtp_config()
{
    $defaults = [
        'enabled' => '0',
        'host' => '',
        'port' => 465,
        'encryption' => 'ssl', // none|ssl|tls
        'user' => '',
        'pass' => '',
        'from_email' => '',
        'from_name' => '',
        'login_email_verify' => '0',
        'login_email' => '',
        'updated_at' => '',
    ];

    $fromFile = [];
    $file = smtp_config_file();
    if (is_file($file) && is_readable($file)) {
        $j = json_decode((string) file_get_contents($file), true);
        if (is_array($j)) {
            $fromFile = $j;
        }
    }

    $cfg = $defaults;
    foreach ($defaults as $k => $v) {
        if (array_key_exists($k, $fromFile)) {
            $cfg[$k] = $fromFile[$k];
        } else {
            $dbv = setting_get('smtp_' . $k, '');
            if ($dbv !== '' || in_array($k, ['enabled', 'login_email_verify'], true)) {
                if ($dbv !== '') {
                    $cfg[$k] = $dbv;
                } else {
                    // 布尔开关从独立 key 读
                    $alt = setting_get($k === 'login_email_verify' ? 'login_email_verify' : ('smtp_' . $k), '');
                    if ($alt !== '') {
                        $cfg[$k] = $alt;
                    }
                }
            }
        }
    }
    // 兼容 settings 分散存储
    $cfg['enabled'] = (string) (setting_get('smtp_enabled', $cfg['enabled']));
    $cfg['host'] = (string) (setting_get('smtp_host', $cfg['host']) ?: $cfg['host']);
    $cfg['port'] = (int) (setting_get('smtp_port', (string) $cfg['port']) ?: $cfg['port']);
    $cfg['encryption'] = (string) (setting_get('smtp_encryption', $cfg['encryption']) ?: $cfg['encryption']);
    $cfg['user'] = (string) (setting_get('smtp_user', $cfg['user']) ?: $cfg['user']);
    $passDb = setting_get('smtp_pass', '');
    if ($passDb !== '') {
        $cfg['pass'] = $passDb;
    }
    $cfg['from_email'] = (string) (setting_get('smtp_from_email', $cfg['from_email']) ?: $cfg['from_email']);
    $cfg['from_name'] = (string) (setting_get('smtp_from_name', $cfg['from_name']) ?: $cfg['from_name']);
    $cfg['login_email_verify'] = setting_bool('login_email_verify', false) ? '1' : (string) (setting_get('login_email_verify', $cfg['login_email_verify']));
    $cfg['login_email'] = (string) (setting_get('login_email', $cfg['login_email']) ?: $cfg['login_email']);
    $cfg['port'] = (int) $cfg['port'] > 0 ? (int) $cfg['port'] : 465;
    if (!in_array($cfg['encryption'], ['none', 'ssl', 'tls'], true)) {
        $cfg['encryption'] = 'ssl';
    }
    return $cfg;
}

function smtp_save_config(array $input)
{
    $prev = smtp_config();
    $cfg = [
        'enabled' => !empty($input['enabled']) ? '1' : '0',
        'host' => trim((string) ($input['host'] ?? '')),
        'port' => (int) ($input['port'] ?? 465),
        'encryption' => trim((string) ($input['encryption'] ?? 'ssl')),
        'user' => trim((string) ($input['user'] ?? '')),
        'pass' => trim((string) ($input['pass'] ?? '')),
        'from_email' => trim((string) ($input['from_email'] ?? '')),
        'from_name' => trim((string) ($input['from_name'] ?? '')),
        'login_email_verify' => !empty($input['login_email_verify']) ? '1' : '0',
        'login_email' => trim((string) ($input['login_email'] ?? '')),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    if ($cfg['pass'] === '') {
        $cfg['pass'] = $prev['pass'];
    }
    if (!in_array($cfg['encryption'], ['none', 'ssl', 'tls'], true)) {
        $cfg['encryption'] = 'ssl';
    }
    if ($cfg['port'] <= 0) {
        $cfg['port'] = 465;
    }

    $json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    $dir = dirname(smtp_config_file());
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $okFile = @file_put_contents(smtp_config_file(), $json, LOCK_EX) !== false;
    if ($okFile) {
        @chmod(smtp_config_file(), 0600);
    }

    foreach ([
        'smtp_enabled' => $cfg['enabled'],
        'smtp_host' => $cfg['host'],
        'smtp_port' => (string) $cfg['port'],
        'smtp_encryption' => $cfg['encryption'],
        'smtp_user' => $cfg['user'],
        'smtp_pass' => $cfg['pass'],
        'smtp_from_email' => $cfg['from_email'],
        'smtp_from_name' => $cfg['from_name'],
        'login_email_verify' => $cfg['login_email_verify'],
        'login_email' => $cfg['login_email'],
    ] as $k => $v) {
        setting_set($k, $v);
    }
    return $okFile;
}

function smtp_is_ready()
{
    $c = smtp_config();
    if ($c['enabled'] !== '1' || $c['host'] === '' || $c['from_email'] === '') {
        return false;
    }
    // 多数 SMTP 需要认证；若配置了 user 则要求 pass，未配置 user 视为匿名/本地中继
    if ($c['user'] !== '' && $c['pass'] === '') {
        return false;
    }
    return true;
}

function smtp_login_verify_enabled()
{
    // 优先读独立开关（settings / smtp.json 合并后）
    if (function_exists('setting_bool')) {
        // 若 key 不存在，用 smtp_config 结果
        $raw = setting_get('login_email_verify', null);
        if ($raw !== null && $raw !== '') {
            return $raw === '1' || $raw === 1 || $raw === true || $raw === 'true' || $raw === 'on';
        }
    }
    $c = smtp_config();
    return (string) $c['login_email_verify'] === '1';
}

/**
 * 读取一行 SMTP 响应
 */
function smtp_read($fp, $timeout = 20)
{
    stream_set_timeout($fp, $timeout);
    $data = '';
    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) {
            break;
        }
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $data;
}

function smtp_cmd($fp, $cmd, $expectPrefix = null)
{
    if ($cmd !== null) {
        fwrite($fp, $cmd . "\r\n");
    }
    $resp = smtp_read($fp);
    if ($expectPrefix !== null) {
        $ok = false;
        foreach ((array) $expectPrefix as $p) {
            if (strpos($resp, (string) $p) === 0) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            throw new RuntimeException(trim($resp) !== '' ? trim($resp) : 'SMTP 无响应');
        }
    }
    return $resp;
}

/**
 * 发送邮件
 * @return array{ok:bool,message:string}
 */
function mailer_send($to, $subject, $htmlBody, $textBody = '')
{
    $cfg = smtp_config();
    if ($cfg['enabled'] !== '1') {
        return ['ok' => false, 'message' => 'SMTP 未启用'];
    }
    if ($cfg['host'] === '' || $cfg['from_email'] === '') {
        return ['ok' => false, 'message' => 'SMTP 主机或发件人未配置'];
    }
    $to = trim((string) $to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => '收件人邮箱无效'];
    }

    $fromEmail = $cfg['from_email'];
    $fromName = $cfg['from_name'] !== '' ? $cfg['from_name'] : '导航管理后台';
    $subject = (string) $subject;
    $htmlBody = (string) $htmlBody;
    if ($textBody === '') {
        $textBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody)), ENT_QUOTES, 'UTF-8'));
    }

    $boundary = 'b_' . bin2hex(random_bytes(8));
    $date = date('r');
    $messageId = '<' . bin2hex(random_bytes(12)) . '@' . preg_replace('/^.*@/', '', $fromEmail) . '>';

    $headers = [
        'Date: ' . $date,
        'From: ' . mailer_encode_address($fromName, $fromEmail),
        'To: ' . $to,
        'Subject: ' . mailer_encode_header($subject),
        'Message-ID: ' . $messageId,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer: RainbowNav-SMTP/1.0',
    ];

    $body = '';
    $body .= '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($textBody)) . "\r\n";
    $body .= '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
    $body .= '--' . $boundary . "--\r\n";

    $raw = implode("\r\n", $headers) . "\r\n\r\n" . $body;

    try {
        $remote = $cfg['host'];
        $port = (int) $cfg['port'];
        $enc = $cfg['encryption'];
        $transport = ($enc === 'ssl') ? ('ssl://' . $remote) : $remote;
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            $transport . ':' . $port,
            $errno,
            $errstr,
            20,
            STREAM_CLIENT_CONNECT,
            stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ])
        );
        if (!$fp) {
            return ['ok' => false, 'message' => '无法连接 SMTP：' . $errstr . ' (' . $errno . ')'];
        }
        stream_set_timeout($fp, 25);

        smtp_cmd($fp, null, ['220']);
        $ehloHost = 'localhost';
        if (!empty($_SERVER['HTTP_HOST'])) {
            $ehloHost = preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']);
        }
        try {
            smtp_cmd($fp, 'EHLO ' . $ehloHost, ['250']);
        } catch (Throwable $e) {
            smtp_cmd($fp, 'HELO ' . $ehloHost, ['250']);
        }

        if ($enc === 'tls') {
            smtp_cmd($fp, 'STARTTLS', ['220']);
            $crypto = stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$crypto) {
                fclose($fp);
                return ['ok' => false, 'message' => 'STARTTLS 协商失败'];
            }
            smtp_cmd($fp, 'EHLO ' . $ehloHost, ['250']);
        }

        if ($cfg['user'] !== '') {
            smtp_cmd($fp, 'AUTH LOGIN', ['334']);
            smtp_cmd($fp, base64_encode($cfg['user']), ['334']);
            smtp_cmd($fp, base64_encode($cfg['pass']), ['235']);
        }

        smtp_cmd($fp, 'MAIL FROM:<' . $fromEmail . '>', ['250']);
        smtp_cmd($fp, 'RCPT TO:<' . $to . '>', ['250', '251']);
        smtp_cmd($fp, 'DATA', ['354']);
        // 点转义
        $data = preg_replace('/^\./m', '..', $raw);
        fwrite($fp, $data . "\r\n.\r\n");
        smtp_cmd($fp, null, ['250']);
        try {
            smtp_cmd($fp, 'QUIT', ['221']);
        } catch (Throwable $e) {
            // ignore
        }
        fclose($fp);
        return ['ok' => true, 'message' => '邮件已发送'];
    } catch (Throwable $e) {
        if (isset($fp) && is_resource($fp)) {
            fclose($fp);
        }
        return ['ok' => false, 'message' => '发送失败：' . $e->getMessage()];
    }
}

function mailer_encode_header($str)
{
    if (preg_match('/[^\x20-\x7E]/', $str)) {
        return '=?UTF-8?B?' . base64_encode($str) . '?=';
    }
    return $str;
}

function mailer_encode_address($name, $email)
{
    $name = trim((string) $name);
    $email = trim((string) $email);
    if ($name === '') {
        return $email;
    }
    return mailer_encode_header($name) . ' <' . $email . '>';
}

/**
 * 登录邮箱验证码 HTML 模板
 */
function mailer_login_code_html($code, $username, $siteName = '')
{
    $siteName = $siteName !== '' ? $siteName : '导航管理后台';
    $code = htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8');
    $username = htmlspecialchars((string) $username, ENT_QUOTES, 'UTF-8');
    $siteName = htmlspecialchars((string) $siteName, ENT_QUOTES, 'UTF-8');
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>登录验证码</title>
</head>
<body style="margin:0;padding:0;background:#f0f4ff;font-family:'Segoe UI','PingFang SC','Microsoft YaHei',sans-serif;color:#1e293b;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:linear-gradient(160deg,#eef2ff 0%,#f0f9ff 50%,#f8fafc 100%);padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" style="max-width:520px;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 16px 48px rgba(79,70,229,0.12);border:1px solid #e2e8f0;">
          <tr>
            <td style="background:linear-gradient(135deg,#4338ca 0%,#5b6cff 45%,#0d9488 100%);padding:28px 32px;color:#fff;">
              <div style="font-size:13px;opacity:0.9;letter-spacing:0.08em;text-transform:uppercase;">Security Code</div>
              <div style="font-size:22px;font-weight:700;margin-top:6px;">{$siteName}</div>
              <div style="font-size:14px;opacity:0.92;margin-top:8px;">后台登录邮箱验证码</div>
            </td>
          </tr>
          <tr>
            <td style="padding:32px 32px 12px;">
              <p style="margin:0 0 12px;font-size:15px;line-height:1.7;color:#334155;">
                你好，管理员 <strong style="color:#1e293b;">{$username}</strong>：
              </p>
              <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#475569;">
                你正在登录管理后台，请使用以下验证码完成二次验证。验证码 <strong>10 分钟</strong> 内有效，请勿泄露给他人。
              </p>
              <div style="text-align:center;margin:24px 0;">
                <div style="display:inline-block;background:linear-gradient(180deg,#f8fafc,#eef2ff);border:1px dashed #a5b4fc;border-radius:14px;padding:18px 28px;">
                  <div style="font-size:12px;color:#64748b;letter-spacing:0.12em;margin-bottom:8px;">LOGIN CODE</div>
                  <div style="font-size:36px;font-weight:800;letter-spacing:0.28em;color:#4338ca;font-family:Consolas,Monaco,monospace;">{$code}</div>
                </div>
              </div>
              <p style="margin:0;font-size:13px;line-height:1.7;color:#94a3b8;">
                如非本人操作，请立即修改后台密码，并检查 SMTP / 账号安全设置。
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:16px 32px 28px;">
              <div style="height:1px;background:#e2e8f0;margin-bottom:16px;"></div>
              <p style="margin:0;font-size:12px;color:#94a3b8;line-height:1.6;text-align:center;">
                此邮件由系统自动发送，请勿直接回复<br>
                © {$year} {$siteName}
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

/**
 * 发起登录邮箱验证码
 * @return array{ok:bool,message:string}
 */
function mailer_send_login_code($username)
{
    $cfg = smtp_config();
    $to = trim($cfg['login_email']);
    if ($to === '') {
        $to = trim($cfg['from_email']);
    }
    if ($to === '') {
        return ['ok' => false, 'message' => '未配置接收验证码的邮箱（login_email / 发件人邮箱）'];
    }
    if (!smtp_is_ready()) {
        return ['ok' => false, 'message' => '请先在后台启用并配置 SMTP'];
    }

    $code = (string) random_int(100000, 999999);
    $_SESSION['login_email_code'] = $code;
    $_SESSION['login_email_code_at'] = time();
    $_SESSION['login_email_code_user'] = $username;
    $_SESSION['login_email_code_tries'] = 0;

    $siteName = '';
    try {
        $content = load_content();
        $siteName = (string) ($content['site']['name'] ?? '');
    } catch (Throwable $e) {
        $siteName = '导航管理后台';
    }
    if ($siteName === '') {
        $siteName = '导航管理后台';
    }

    $html = mailer_login_code_html($code, $username, $siteName);
    $subject = '【' . $siteName . '】后台登录验证码 ' . $code;
    $text = "你的后台登录验证码是：{$code}，10 分钟内有效。用户：{$username}";

    $result = mailer_send($to, $subject, $html, $text);
    if (!empty($result['ok'])) {
        // 脱敏提示
        $masked = mailer_mask_email($to);
        return ['ok' => true, 'message' => '验证码已发送至 ' . $masked];
    }
    unset($_SESSION['login_email_code'], $_SESSION['login_email_code_at'], $_SESSION['login_email_code_user']);
    return $result;
}

function mailer_mask_email($email)
{
    $email = (string) $email;
    if (strpos($email, '@') === false) {
        return '***';
    }
    $parts = explode('@', $email, 2);
    $name = $parts[0];
    $domain = $parts[1];
    $n = strlen($name);
    if ($n <= 2) {
        $name = substr($name, 0, 1) . '*';
    } else {
        $name = substr($name, 0, 1) . str_repeat('*', min(4, $n - 2)) . substr($name, -1);
    }
    return $name . '@' . $domain;
}

/**
 * 校验登录邮箱验证码
 */
function mailer_verify_login_code($username, $code)
{
    $expect = (string) ($_SESSION['login_email_code'] ?? '');
    $at = (int) ($_SESSION['login_email_code_at'] ?? 0);
    $user = (string) ($_SESSION['login_email_code_user'] ?? '');
    $tries = (int) ($_SESSION['login_email_code_tries'] ?? 0);
    $code = trim((string) $code);

    if ($expect === '' || $user === '') {
        return ['ok' => false, 'message' => '请先获取邮箱验证码'];
    }
    if (!hash_equals($user, (string) $username)) {
        return ['ok' => false, 'message' => '用户与验证码不匹配，请重新登录'];
    }
    if ($at > 0 && (time() - $at) > 600) {
        unset($_SESSION['login_email_code'], $_SESSION['login_email_code_at'], $_SESSION['login_email_code_user'], $_SESSION['login_email_code_tries']);
        return ['ok' => false, 'message' => '验证码已过期，请重新获取'];
    }
    if ($tries >= 8) {
        unset($_SESSION['login_email_code'], $_SESSION['login_email_code_at'], $_SESSION['login_email_code_user'], $_SESSION['login_email_code_tries']);
        return ['ok' => false, 'message' => '尝试次数过多，请重新登录'];
    }
    if ($code === '' || !hash_equals($expect, $code)) {
        $_SESSION['login_email_code_tries'] = $tries + 1;
        return ['ok' => false, 'message' => '邮箱验证码错误'];
    }
    unset($_SESSION['login_email_code'], $_SESSION['login_email_code_at'], $_SESSION['login_email_code_user'], $_SESSION['login_email_code_tries']);
    return ['ok' => true, 'message' => '验证成功'];
}

/* ===================== 前台表单邮箱验证码 + 管理员通知 ===================== */

/**
 * 前台发码 IP 限流：每 IP 每小时最多 20 次
 * @param bool $record 是否记录一次（仅发送成功时为 true；false 仅检查）
 * @return array{ok:bool,message?:string}
 */
function mailer_form_code_ip_throttle($record = false)
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $ip = preg_replace('/[^0-9a-fA-F:.\-]/', '', $ip) ?: 'unknown';
    $dir = ROOT_PATH . '/data/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/form_code_ip_' . hash('sha256', $ip) . '.json';
    $now = time();
    $window = 3600;
    $max = 20;
    $hits = [];
    if (is_file($file) && is_readable($file)) {
        $j = json_decode((string) file_get_contents($file), true);
        if (is_array($j) && !empty($j['hits']) && is_array($j['hits'])) {
            foreach ($j['hits'] as $t) {
                $t = (int) $t;
                if ($t > 0 && ($now - $t) < $window) {
                    $hits[] = $t;
                }
            }
        }
    }
    if (count($hits) >= $max) {
        return ['ok' => false, 'message' => '发送次数过多，请稍后再试'];
    }
    if ($record) {
        $hits[] = $now;
        @file_put_contents($file, json_encode(['hits' => $hits], JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    return ['ok' => true];
}

function mailer_site_name()
{
    try {
        $content = load_content();
        $n = trim((string) ($content['site']['name'] ?? ''));
        if ($n !== '') {
            return $n;
        }
    } catch (Throwable $e) {
    }
    return '夏尼猫网址导航';
}

function mailer_admin_inbox()
{
    $cfg = smtp_config();
    $to = trim((string) ($cfg['login_email'] ?? ''));
    if ($to === '') {
        $to = trim((string) ($cfg['from_email'] ?? ''));
    }
    try {
        $content = load_content();
        $ce = trim((string) ($content['site']['contact_email'] ?? ''));
        if ($to === '' && $ce !== '') {
            $to = $ce;
        }
    } catch (Throwable $e) {
    }
    return $to;
}

/**
 * 前台验证码 HTML
 */
function mailer_form_code_html($code, $purpose, $siteName = '')
{
    $siteName = $siteName !== '' ? $siteName : mailer_site_name();
    $code = htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8');
    $purpose = htmlspecialchars((string) $purpose, ENT_QUOTES, 'UTF-8');
    $siteName = htmlspecialchars((string) $siteName, ENT_QUOTES, 'UTF-8');
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>邮箱验证码</title></head>
<body style="margin:0;padding:0;background:#f0f9ff;font-family:'Segoe UI','PingFang SC','Microsoft YaHei',sans-serif;color:#1e293b;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:linear-gradient(160deg,#ecfeff 0%,#eef2ff 55%,#f8fafc 100%);padding:32px 16px;">
    <tr><td align="center">
      <table role="presentation" width="100%" style="max-width:520px;background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 16px 48px rgba(13,148,136,0.12);border:1px solid #e2e8f0;">
        <tr>
          <td style="background:linear-gradient(135deg,#0f766e 0%,#0d9488 48%,#5b6cff 100%);padding:28px 32px;color:#fff;">
            <div style="font-size:13px;opacity:0.9;letter-spacing:0.08em;text-transform:uppercase;">Verify Email</div>
            <div style="font-size:22px;font-weight:700;margin-top:6px;">{$siteName}</div>
            <div style="font-size:14px;opacity:0.92;margin-top:8px;">{$purpose}</div>
          </td>
        </tr>
        <tr>
          <td style="padding:32px 32px 12px;">
            <p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#475569;">
              你正在进行 <strong style="color:#0f766e;">{$purpose}</strong>，请使用以下验证码完成邮箱验证。验证码 <strong>10 分钟</strong> 内有效。
            </p>
            <div style="text-align:center;margin:24px 0;">
              <div style="display:inline-block;background:linear-gradient(180deg,#f8fafc,#ecfeff);border:1px dashed #5eead4;border-radius:14px;padding:18px 28px;">
                <div style="font-size:12px;color:#64748b;letter-spacing:0.12em;margin-bottom:8px;">EMAIL CODE</div>
                <div style="font-size:36px;font-weight:800;letter-spacing:0.28em;color:#0f766e;font-family:Consolas,Monaco,monospace;">{$code}</div>
              </div>
            </div>
            <p style="margin:0;font-size:13px;line-height:1.7;color:#94a3b8;">如非本人操作，请忽略本邮件。</p>
          </td>
        </tr>
        <tr>
          <td style="padding:16px 32px 28px;">
            <div style="height:1px;background:#e2e8f0;margin-bottom:16px;"></div>
            <p style="margin:0;font-size:12px;color:#94a3b8;line-height:1.6;text-align:center;">此邮件由系统自动发送<br>© {$year} {$siteName}</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

/**
 * 向用户邮箱发送前台表单验证码
 * @param string $scope apply|message
 */
function mailer_send_form_code($email, $scope = 'message')
{
    $email = strtolower(trim((string) $email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => '请输入有效的邮箱地址'];
    }
    if (!in_array($scope, ['apply', 'message'], true)) {
        $scope = 'message';
    }
    if (!smtp_is_ready()) {
        return ['ok' => false, 'message' => '邮件服务暂不可用，请稍后再试或联系管理员配置 SMTP'];
    }

    $rateKey = 'form_code_send_' . $scope;
    $last = (int) ($_SESSION[$rateKey] ?? 0);
    if ($last > 0 && (time() - $last) < 60) {
        $wait = 60 - (time() - $last);
        return ['ok' => false, 'message' => '发送过于频繁，请 ' . $wait . ' 秒后再试'];
    }

    // IP 级限流预检（防换 Session 刷邮件；成功发送后再记一次）
    $ipLimit = mailer_form_code_ip_throttle(false);
    if (empty($ipLimit['ok'])) {
        return $ipLimit;
    }

    $code = (string) random_int(100000, 999999);
    $prefix = 'form_email_' . $scope;
    $_SESSION[$prefix . '_code'] = $code;
    $_SESSION[$prefix . '_email'] = $email;
    $_SESSION[$prefix . '_at'] = time();
    $_SESSION[$prefix . '_tries'] = 0;
    $_SESSION[$prefix . '_ok'] = 0;

    $purpose = $scope === 'apply' ? '申请收录邮箱验证' : '在线留言邮箱验证';
    $siteName = mailer_site_name();
    $html = mailer_form_code_html($code, $purpose, $siteName);
    $subject = '【' . $siteName . '】' . $purpose . ' ' . $code;
    $text = "验证码：{$code}，10 分钟内有效。用途：{$purpose}";

    $result = mailer_send($email, $subject, $html, $text);
    if (!empty($result['ok'])) {
        $_SESSION[$rateKey] = time();
        mailer_form_code_ip_throttle(true);
        return ['ok' => true, 'message' => '验证码已发送至 ' . mailer_mask_email($email)];
    }
    unset($_SESSION[$prefix . '_code'], $_SESSION[$prefix . '_email'], $_SESSION[$prefix . '_at']);
    return $result;
}

/**
 * 校验前台表单邮箱验证码（成功后标记已验证，提交时再 consume）
 * @param bool $consume 是否立即清除（提交成功时建议 true）
 */
function mailer_verify_form_code($email, $code, $scope = 'message', $consume = false)
{
    $email = strtolower(trim((string) $email));
    $code = trim((string) $code);
    if (!in_array($scope, ['apply', 'message'], true)) {
        $scope = 'message';
    }
    $prefix = 'form_email_' . $scope;
    $expect = (string) ($_SESSION[$prefix . '_code'] ?? '');
    $expectEmail = (string) ($_SESSION[$prefix . '_email'] ?? '');
    $at = (int) ($_SESSION[$prefix . '_at'] ?? 0);
    $tries = (int) ($_SESSION[$prefix . '_tries'] ?? 0);

    if ($expect === '' || $expectEmail === '') {
        return ['ok' => false, 'message' => '请先获取邮箱验证码'];
    }
    if ($email === '' || !hash_equals($expectEmail, $email)) {
        return ['ok' => false, 'message' => '邮箱与验证码不匹配，请使用获取验证码时的邮箱'];
    }
    if ($at > 0 && (time() - $at) > 600) {
        mailer_clear_form_code($scope);
        return ['ok' => false, 'message' => '验证码已过期，请重新获取'];
    }
    if ($tries >= 10) {
        mailer_clear_form_code($scope);
        return ['ok' => false, 'message' => '尝试次数过多，请重新获取验证码'];
    }
    if ($code === '' || !hash_equals($expect, $code)) {
        $_SESSION[$prefix . '_tries'] = $tries + 1;
        return ['ok' => false, 'message' => '邮箱验证码错误'];
    }

    $_SESSION[$prefix . '_ok'] = 1;
    $_SESSION[$prefix . '_ok_email'] = $email;
    $_SESSION[$prefix . '_ok_at'] = time();
    if ($consume) {
        mailer_clear_form_code($scope);
    }
    return ['ok' => true, 'message' => '验证成功'];
}

/**
 * 提交前：要求该邮箱近期已成功验证过验证码
 */
function mailer_require_form_email_verified($email, $code, $scope = 'message')
{
    // 校验成功即消费验证码，防止同一验证码在有效期内重复提交
    return mailer_verify_form_code($email, $code, $scope, true);
}

function mailer_clear_form_code($scope)
{
    $prefix = 'form_email_' . $scope;
    foreach (['_code', '_email', '_at', '_tries', '_ok', '_ok_email', '_ok_at'] as $s) {
        unset($_SESSION[$prefix . $s]);
    }
}

/**
 * 通知管理员：新留言 / 新收录申请
 */
function mailer_notify_admin_submission($type, array $data)
{
    $to = mailer_admin_inbox();
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => '未配置管理员收件邮箱'];
    }
    if (!smtp_is_ready()) {
        return ['ok' => false, 'message' => 'SMTP 未就绪'];
    }

    $siteName = mailer_site_name();
    $isApply = ($type === 'apply');
    $title = $isApply ? '新的收录申请' : '新的在线留言';
    $name = htmlspecialchars((string) ($data['name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars((string) ($data['email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $contact = htmlspecialchars((string) ($data['contact'] ?? ''), ENT_QUOTES, 'UTF-8');
    $website = htmlspecialchars((string) ($data['website'] ?? ''), ENT_QUOTES, 'UTF-8');
    $content = nl2br(htmlspecialchars((string) ($data['content'] ?? ''), ENT_QUOTES, 'UTF-8'));
    $ip = htmlspecialchars((string) ($data['ip'] ?? ''), ENT_QUOTES, 'UTF-8');
    $time = htmlspecialchars(date('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8');
    $siteEsc = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
    $year = date('Y');
    $accent = $isApply ? '#0d9488' : '#7c3aed';
    $grad = $isApply
        ? 'linear-gradient(135deg,#0f766e 0%,#0d9488 50%,#5b6cff 100%)'
        : 'linear-gradient(135deg,#6d28d9 0%,#7c3aed 50%,#5b6cff 100%)';

    $rows = '';
    $rows .= mailer_notify_row('类型', $isApply ? '申请收录' : '在线留言');
    if ($name !== '') {
        $rows .= mailer_notify_row('名称 / 昵称', $name);
    }
    if ($email !== '') {
        $rows .= mailer_notify_row('邮箱', $email);
    }
    if ($contact !== '') {
        $rows .= mailer_notify_row('联系方式', $contact);
    }
    if ($website !== '') {
        $rows .= mailer_notify_row('网站', '<a href="' . $website . '" style="color:' . $accent . ';">' . $website . '</a>');
    }
    $rows .= mailer_notify_row('内容', $content !== '' ? $content : '—');
    $rows .= mailer_notify_row('IP', $ip !== '' ? $ip : '—');
    $rows .= mailer_notify_row('时间', $time);

    $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head><meta charset="UTF-8"><title>{$title}</title></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI','PingFang SC','Microsoft YaHei',sans-serif;">
  <table width="100%" cellspacing="0" cellpadding="0" style="padding:28px 16px;">
    <tr><td align="center">
      <table width="100%" style="max-width:560px;background:#fff;border-radius:18px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 12px 40px rgba(15,23,42,0.08);">
        <tr><td style="background:{$grad};padding:24px 28px;color:#fff;">
          <div style="font-size:13px;opacity:0.9;">{$siteEsc}</div>
          <div style="font-size:20px;font-weight:700;margin-top:4px;">{$title}</div>
        </td></tr>
        <tr><td style="padding:8px 8px 20px;">
          <table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">{$rows}</table>
          <p style="margin:16px 20px 0;font-size:12px;color:#94a3b8;line-height:1.6;">请登录管理后台「留言管理」查看并处理。</p>
        </td></tr>
        <tr><td style="padding:0 28px 22px;text-align:center;font-size:12px;color:#94a3b8;">© {$year} {$siteEsc}</td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    $subject = '【' . $siteName . '】' . $title . ($name !== '' ? ' - ' . html_entity_decode(strip_tags($name), ENT_QUOTES, 'UTF-8') : '');
    $text = $title . "\n名称：{$data['name']}\n邮箱：{$data['email']}\n网站：{$data['website']}\n内容：{$data['content']}\n时间：{$time}";
    return mailer_send($to, $subject, $html, $text);
}

function mailer_notify_row($label, $value)
{
    $label = htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8');
    // $value 可为已转义 HTML
    return '<tr>'
        . '<td style="padding:12px 20px;width:100px;vertical-align:top;font-size:13px;color:#64748b;border-bottom:1px solid #f1f5f9;">' . $label . '</td>'
        . '<td style="padding:12px 20px;vertical-align:top;font-size:14px;color:#1e293b;border-bottom:1px solid #f1f5f9;line-height:1.6;word-break:break-all;">' . $value . '</td>'
        . '</tr>';
}



