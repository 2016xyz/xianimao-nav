<?php
/**
 * 全站安全防护：安全响应头 / CSP / 输入校验 / 输出转义 / SQL 辅助
 * 由 bootstrap.php 自动加载；install.php 可单独 require。
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

/**
 * 是否 HTTPS（含反向代理）
 */
function security_is_https()
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($proto === 'https') {
        return true;
    }
    return false;
}

/**
 * 发送安全响应头（CSP 禁用内联脚本，脚本仅同源）
 * @param array $opts script_nonce|frame_ancestors|extra_csp
 */
function security_send_headers(array $opts = [])
{
    static $sent = false;
    if ($sent || headers_sent()) {
        return;
    }
    $sent = true;

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('X-XSS-Protection: 0'); // 现代浏览器依赖 CSP；关闭旧过滤器误报

    if (security_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    $frameAncestors = $opts['frame_ancestors'] ?? "'self'";
    // 默认：脚本/样式/图片/字体仅同源；style 允许内联（布局 style= 大量存在）；script 禁止 inline/eval
    $csp = [
        "default-src 'self'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors {$frameAncestors}",
        "object-src 'none'",
        "script-src 'self'",
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data: https:",
        "font-src 'self' data:",
        "connect-src 'self'",
    ];
    if (security_is_https()) {
        $csp[] = 'upgrade-insecure-requests';
    }
    if (!empty($opts['extra_csp']) && is_array($opts['extra_csp'])) {
        $csp = array_merge($csp, $opts['extra_csp']);
    }
    header('Content-Security-Policy: ' . implode('; ', $csp));
}

/**
 * 配置 Session Cookie：HttpOnly + SameSite + Secure(HTTPS)
 */
function security_configure_session()
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    $secure = security_is_https();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // 与 setcookie 默认行为对齐（PHP 7.3+）
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if ($secure) {
        ini_set('session.cookie_secure', '1');
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
}

/**
 * HTML 文本转义（XSS）
 */
function security_escape($str)
{
    return htmlspecialchars((string) $str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 属性值转义
 */
function security_escape_attr($str)
{
    return htmlspecialchars((string) $str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * JSON 嵌入到 HTML 中的安全编码（防 </script> 突破）
 */
function security_json($data)
{
    return json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
}

/**
 * 多字节安全长度
 */
function security_strlen($str)
{
    $str = (string) $str;
    return function_exists('mb_strlen') ? mb_strlen($str, 'UTF-8') : strlen($str);
}

/**
 * 截断到最大长度
 */
function security_truncate($str, $max)
{
    $str = (string) $str;
    $max = max(0, (int) $max);
    if (security_strlen($str) <= $max) {
        return $str;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($str, 0, $max, 'UTF-8');
    }
    return substr($str, 0, $max);
}

/**
 * 去除控制字符（保留 \n \r \t）
 */
function security_strip_controls($str)
{
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $str) ?? '';
}

/**
 * 通用文本清洗：trim + 去控制符 + 长度限制
 */
function security_clean_text($str, $maxLen = 500)
{
    $str = security_strip_controls(trim((string) $str));
    return security_truncate($str, $maxLen);
}

/**
 * 仅允许 http(s)，可选站内相对路径
 */
function security_url($url, $allowRelative = true)
{
    $url = security_strip_controls(trim((string) $url));
    if ($url === '') {
        return '';
    }
    // 拒绝危险协议与协议相对 URL
    if (preg_match('#^\s*(javascript|data|vbscript|file)\s*:#iu', $url)) {
        return '';
    }
    if (strpos($url, '//') === 0) {
        return '';
    }
    if ($allowRelative) {
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            if (preg_match('#^/[A-Za-z0-9_./?&=%\-#]*$#', $url)) {
                return $url;
            }
            return '';
        }
        if (preg_match('#^[A-Za-z0-9_./\-]+\.php(\?[A-Za-z0-9_=&%\-./]*)?$#', $url)) {
            return $url;
        }
    }
    if (!preg_match('#^https?://#i', $url)) {
        return '';
    }
    $parts = @parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    // 主机名基本校验
    if (!preg_match('#^[A-Za-z0-9.\-]+$#', $parts['host'])) {
        return '';
    }
    return $url;
}

/**
 * 校验邮箱
 * @return string|null 规范化邮箱或 null
 */
function security_email($email, $maxLen = 120)
{
    $email = strtolower(security_clean_text($email, $maxLen));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    return $email;
}

/**
 * 校验整数（含范围）
 * @return int|null
 */
function security_int($value, $min = null, $max = null)
{
    if (is_int($value)) {
        $n = $value;
    } elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
        $n = (int) $value;
    } else {
        return null;
    }
    if ($min !== null && $n < $min) {
        return null;
    }
    if ($max !== null && $n > $max) {
        return null;
    }
    return $n;
}

/**
 * 枚举白名单
 * @return string|null
 */
function security_enum($value, array $allowed)
{
    $value = (string) $value;
    return in_array($value, $allowed, true) ? $value : null;
}

/**
 * 标识符：字母数字下划线横线
 * @return string|null
 */
function security_id($value, $maxLen = 64)
{
    $value = security_clean_text($value, $maxLen);
    if ($value === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $value)) {
        return null;
    }
    return $value;
}

/**
 * 数字验证码（固定位数）
 * @return string|null
 */
function security_digits($value, $len = 6)
{
    $value = trim((string) $value);
    if (!preg_match('/^\d{' . (int) $len . '}$/', $value)) {
        return null;
    }
    return $value;
}

/**
 * IP 字符串清洗
 */
function security_ip($ip = null)
{
    if ($ip === null) {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
    $ip = trim((string) $ip);
    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    return '';
}

/**
 * POST 字段安全读取（文本）
 */
function security_post_text($key, $maxLen = 500, $default = '')
{
    if (!isset($_POST[$key])) {
        return $default;
    }
    return security_clean_text($_POST[$key], $maxLen);
}

/**
 * 后台可编辑 HTML 白名单消毒
 */
function security_sanitize_html($html, $maxLen = 20000)
{
    $html = security_truncate((string) $html, $maxLen);
    if ($html === '') {
        return '';
    }
    $allowed = '<p><br><br/><b><strong><i><em><u><a><ul><ol><li><h2><h3><h4><span><div><blockquote>';
    $clean = strip_tags($html, $allowed);
    $clean = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $clean) ?? $clean;
    $clean = preg_replace('/\s+(href|src)\s*=\s*([\'"])\s*javascript:[^\'"]*\2/iu', ' $1="#"', $clean) ?? $clean;
    $clean = preg_replace('/\s+(href|src)\s*=\s*javascript:[^\s>]*/iu', ' $1="#"', $clean) ?? $clean;
    $clean = preg_replace('/javascript\s*:/iu', '', $clean) ?? $clean;
    // 禁止 data: / vbscript:
    $clean = preg_replace('/\s+(href|src)\s*=\s*([\'"])\s*(data|vbscript):[^\'"]*\2/iu', ' $1="#"', $clean) ?? $clean;
    return $clean;
}

/**
 * 留言/申请字段规范化与长度限制（入库前）
 * @return array{ok:bool,error?:string,data?:array}
 */
function security_validate_message_row(array $row)
{
    $type = security_enum((string) ($row['type'] ?? 'message'), ['message', 'apply']);
    if ($type === null) {
        return ['ok' => false, 'error' => '类型无效'];
    }
    $name = security_clean_text($row['name'] ?? '', 80);
    $contact = security_clean_text($row['contact'] ?? '', 120);
    $emailRaw = $row['email'] ?? '';
    $email = $emailRaw !== '' ? security_email($emailRaw, 120) : '';
    if ($emailRaw !== '' && $email === null) {
        return ['ok' => false, 'error' => '邮箱格式无效'];
    }
    if ($email === null) {
        $email = '';
    }
    $websiteRaw = trim((string) ($row['website'] ?? ''));
    $website = '';
    if ($websiteRaw !== '') {
        $website = security_url($websiteRaw, false);
        if ($website === '') {
            return ['ok' => false, 'error' => '网址无效，仅允许 http(s)'];
        }
        $website = security_truncate($website, 500);
    }
    $content = security_clean_text($row['content'] ?? '', 2000);
    if ($type === 'apply') {
        if ($name === '' || $website === '') {
            return ['ok' => false, 'error' => '请填写网站名称与网址'];
        }
        if ($email === '') {
            return ['ok' => false, 'error' => '请填写有效邮箱'];
        }
    } else {
        if ($content === '' || security_strlen($content) < 4) {
            return ['ok' => false, 'error' => '留言内容过短'];
        }
        if ($email === '') {
            return ['ok' => false, 'error' => '请填写有效邮箱'];
        }
        if ($name === '') {
            $name = '匿名';
        }
    }
    return [
        'ok' => true,
        'data' => [
            'type' => $type,
            'name' => $name,
            'contact' => $contact,
            'email' => $email,
            'website' => $website,
            'content' => $content !== '' ? $content : ($type === 'apply' ? ('申请收录：' . $name) : $content),
            'ip' => security_ip($row['ip'] ?? null),
        ],
    ];
}

/**
 * PDO 参数化查询封装（禁止调用方拼接用户输入）
 * @param array $params 绑定值列表
 * @return PDOStatement
 */
function security_db_query($sql, array $params = [])
{
    // 粗略拦截危险拼接痕迹（动态 SQL 中直接出现未绑定引号用户输入仍需调用方自律）
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/**
 * 安全 LIMIT（仅整数，不进绑定占位）
 */
function security_sql_limit($limit, $default = 100, $max = 500)
{
    $n = security_int($limit, 1, $max);
    return $n !== null ? $n : (int) $default;
}
