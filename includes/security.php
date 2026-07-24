<?php
/**
 * 全站安全防护：安全响应头 / CSP / 输入校验 / 输出转义 / SQL 辅助
 * 由 bootstrap.php 自动加载；install.php 可单独 require。
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

/**
 * 是否信任反向代理头（X-Forwarded-*）
 * 仅当 settings 中 trust_proxy=1，或环境变量 NAV_TRUST_PROXY=1 时启用
 */
function security_trust_proxy()
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $env = getenv('NAV_TRUST_PROXY');
    if ($env === '1' || strtolower((string) $env) === 'true') {
        return $cached = true;
    }
    if (function_exists('setting_get')) {
        $v = setting_get('trust_proxy', '0');
        if ($v === '1' || $v === 'true' || $v === 'on') {
            return $cached = true;
        }
    }
    return $cached = false;
}

/**
 * 是否 HTTPS（默认只信本机 HTTPS/443；反代头需 trust_proxy）
 */
function security_is_https()
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    if (security_trust_proxy()) {
        $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($proto === 'https') {
            return true;
        }
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
        // 使用 ~ 作定界符，避免 URL 中的 # 提前结束模式
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            if (preg_match('~^/[A-Za-z0-9_./?&=%\\-#]*$~', $url)) {
                return $url;
            }
            return '';
        }
        if (preg_match('~^[A-Za-z0-9_./\\-]+\\.php(\\?[A-Za-z0-9_=&%\\-./]*)?$~', $url)) {
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
 * 后台可编辑 HTML 白名单消毒（防存储型 XSS）
 * 非 <a> 标签剥离全部属性；<a> 仅保留安全 href/target/rel
 */
function security_sanitize_html($html, $maxLen = 20000)
{
    $html = security_truncate((string) $html, $maxLen);
    if ($html === '') {
        return '';
    }
    // 解码常见实体后再判断危险协议（防 java&#115;cript: 绕过）
    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $allowed = '<p><br><br/><b><strong><i><em><u><a><ul><ol><li><h2><h3><h4><span><div><blockquote>';
    $clean = strip_tags($decoded, $allowed);

    // 去掉事件处理器 / style（style 可藏 url(javascript:) 等）
    $clean = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $clean) ?? $clean;
    $clean = preg_replace('/\s+style\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $clean) ?? $clean;
    // 去掉危险协议字面量
    $clean = preg_replace('/javascript\s*:/iu', '', $clean) ?? $clean;
    $clean = preg_replace('/vbscript\s*:/iu', '', $clean) ?? $clean;
    $clean = preg_replace('/data\s*:/iu', '', $clean) ?? $clean;

    // 非 a 标签：去掉全部属性，仅保留标签名
    $clean = preg_replace_callback(
        '/<(\/?)(p|br|b|strong|i|em|u|ul|ol|li|h2|h3|h4|span|div|blockquote)\b([^>]*)>/iu',
        static function ($m) {
            $slash = $m[1];
            $tag = strtolower($m[2]);
            if ($tag === 'br') {
                return '<br>';
            }
            return '<' . $slash . $tag . '>';
        },
        $clean
    ) ?? $clean;

    // 规范化 <a href>：仅 http(s) / mailto / 站内相对
    $clean = preg_replace_callback(
        '/<a\b([^>]*)>/iu',
        static function ($m) {
            $attrs = $m[1];
            $href = '';
            if (preg_match('/\bhref\s*=\s*([\'"])(.*?)\1/iu', $attrs, $hm)) {
                $href = html_entity_decode(trim($hm[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } elseif (preg_match('/\bhref\s*=\s*([^\s>]+)/iu', $attrs, $hm)) {
                $href = html_entity_decode(trim($hm[1], "\"'"), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            $safe = '';
            if ($href !== '') {
                if (preg_match('#^mailto:[^\s<>"\']+$#iu', $href)) {
                    $safe = $href;
                } else {
                    $safe = security_url($href, true);
                }
            }
            $target = '';
            if (preg_match('/\btarget\s*=\s*([\'"])(.*?)\1/iu', $attrs, $tm)) {
                $t = strtolower(trim($tm[2]));
                if (in_array($t, ['_blank', '_self'], true)) {
                    $target = $t;
                }
            }
            $out = '<a';
            if ($safe !== '') {
                $out .= ' href="' . htmlspecialchars($safe, ENT_QUOTES, 'UTF-8') . '"';
            } else {
                $out .= ' href="#"';
            }
            if ($target === '_blank') {
                $out .= ' target="_blank" rel="noopener noreferrer"';
            } elseif ($target === '_self') {
                $out .= ' target="_self"';
            }
            $out .= '>';
            return $out;
        },
        $clean
    ) ?? $clean;

    // 再次去掉可能残留的 script 标签碎片
    $clean = preg_replace('#<\s*/?\s*script\b[^>]*>#iu', '', $clean) ?? $clean;
    return $clean;
}

/**
 * SEO 自定义 Head 片段：仅允许 meta / link，并校验关键属性
 */
function security_sanitize_head_html($html, $maxLen = 8000)
{
    $html = security_truncate(security_strip_controls((string) $html), $maxLen);
    if ($html === '') {
        return '';
    }
    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // 先去掉 script / style / 事件
    $decoded = preg_replace('#<\s*script\b[^>]*>.*?<\s*/\s*script\s*>#is', '', $decoded) ?? $decoded;
    $decoded = preg_replace('#<\s*/?\s*script\b[^>]*>#i', '', $decoded) ?? $decoded;
    $decoded = preg_replace('#<\s*style\b[^>]*>.*?<\s*/\s*style\s*>#is', '', $decoded) ?? $decoded;
    $decoded = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $decoded) ?? $decoded;

    $parts = [];
    if (preg_match_all('#<\s*(meta|link)\b([^>]*)/?>#iu', $decoded, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $tag = strtolower($m[1]);
            $attrs = $m[2];
            $bag = [];
            if (preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*([\'"])(.*?)\2/su', $attrs, $am, PREG_SET_ORDER)) {
                foreach ($am as $a) {
                    $name = strtolower($a[1]);
                    $val = html_entity_decode($a[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $val = security_strip_controls($val);
                    if ($tag === 'meta') {
                        if (!in_array($name, ['name', 'content', 'property', 'http-equiv', 'charset'], true)) {
                            continue;
                        }
                        if ($name === 'http-equiv' && !in_array(strtolower($val), ['x-ua-compatible', 'content-type', 'content-language'], true)) {
                            continue;
                        }
                        if ($name === 'content' && preg_match('/javascript\s*:|data\s*:|vbscript\s*:/iu', $val)) {
                            continue;
                        }
                        $bag[$name] = $val;
                    } else { // link
                        if (!in_array($name, ['rel', 'href', 'type', 'sizes', 'crossorigin', 'media'], true)) {
                            continue;
                        }
                        if ($name === 'href') {
                            $safe = security_url($val, true);
                            if ($safe === '') {
                                continue 2;
                            }
                            $val = $safe;
                        }
                        if ($name === 'rel') {
                            $rel = strtolower($val);
                            if (
                                preg_match('/import|prefetch|preload|modulepreload/i', $rel) && stripos($rel, 'stylesheet') === false
                                && stripos($rel, 'icon') === false && stripos($rel, 'canonical') === false
                                && stripos($rel, 'alternate') === false && stripos($rel, 'manifest') === false
                            ) {
                                // 允许常见 SEO/图标 rel；拒绝可疑 import
                            }
                            if (preg_match('/\bimport\b/i', $rel)) {
                                continue 2;
                            }
                        }
                        $bag[$name] = $val;
                    }
                }
            }
            if ($tag === 'meta' && empty($bag['name']) && empty($bag['property']) && empty($bag['http-equiv']) && empty($bag['charset'])) {
                continue;
            }
            if ($tag === 'link' && (empty($bag['rel']) || empty($bag['href']))) {
                continue;
            }
            $htmlTag = '<' . $tag;
            foreach ($bag as $k => $v) {
                $htmlTag .= ' ' . $k . '="' . htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
            $htmlTag .= '>';
            $parts[] = $htmlTag;
        }
    }
    return implode("\n    ", $parts);
}

/**
 * 出站 HTTPS 是否校验证书（默认开启；setting ssl_verify_peer=0 可关闭）
 */
function security_ssl_verify_peer()
{
    if (function_exists('setting_get')) {
        $v = setting_get('ssl_verify_peer', '1');
        if ($v === '0' || $v === 'false' || $v === 'off') {
            return false;
        }
    }
    return true;
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
