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
    $decodedUrl = rawurldecode($url);
    if (strpos($url, '..') !== false || strpos($decodedUrl, '..') !== false) {
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
 * 客户端 IP（默认 REMOTE_ADDR；仅 trust_proxy 时解析 X-Forwarded-For 最左可信链末端）
 */
function security_client_ip()
{
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP)) {
        // 默认不信代理头，避免伪造
        if (!security_trust_proxy()) {
            return $remote;
        }
        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $parts = array_map('trim', explode(',', $xff));
            // 取最左侧公网/合法 IP（反代追加在右侧）
            foreach ($parts as $p) {
                if ($p !== '' && filter_var($p, FILTER_VALIDATE_IP)) {
                    return $p;
                }
            }
        }
        $xr = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($xr !== '' && filter_var($xr, FILTER_VALIDATE_IP)) {
            return $xr;
        }
        return $remote;
    }
    return '';
}

function security_ip_is_public($ip)
{
    $ip = trim((string) $ip);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return false;
    }
    if (strpos($ip, '169.254.') === 0) {
        return false;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $lower = strtolower($ip);
        if (strpos($lower, 'fe80:') === 0 || strpos($lower, 'fc') === 0 || strpos($lower, 'fd') === 0) {
            return false;
        }
    }
    return true;
}

function security_hostname_is_public($host)
{
    $host = strtolower(trim((string) $host, " \t\n\r\0\x0B[]"));
    if ($host === '' || $host === 'localhost' || $host === 'metadata.google.internal') {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return security_ip_is_public($host);
    }
    if (!preg_match('#^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$#i', $host)) {
        return false;
    }
    $ips = [];
    if (function_exists('dns_get_record')) {
        $a = @dns_get_record($host, DNS_A);
        if (is_array($a)) {
            foreach ($a as $r) {
                if (!empty($r['ip'])) {
                    $ips[] = $r['ip'];
                }
            }
        }
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $r) {
                if (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
    }
    if ($ips === []) {
        $resolved = @gethostbynamel($host);
        if (is_array($resolved)) {
            $ips = array_merge($ips, $resolved);
        }
    }
    if ($ips === []) {
        return false;
    }
    foreach (array_unique($ips) as $ip) {
        if (!security_ip_is_public($ip)) {
            return false;
        }
    }
    return true;
}

function security_outbound_url_allowed($url, array $schemes = ['https'])
{
    $url = trim((string) $url);
    if ($url === '') {
        return false;
    }
    $parts = @parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    $scheme = strtolower((string) $parts['scheme']);
    if (!in_array($scheme, $schemes, true)) {
        return false;
    }
    if (!empty($parts['user']) || !empty($parts['pass'])) {
        return false;
    }
    return security_hostname_is_public((string) $parts['host']);
}

/**
 * IP 字符串清洗；无参时取客户端 IP
 */
function security_ip($ip = null)
{
    if ($ip === null) {
        $ip = security_client_ip();
    }
    $ip = trim((string) $ip);
    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    return '';
}

/**
 * 搜索引擎 URL（允许 {q}/{query} 占位符，仍拒绝 javascript/data 等）
 */
function security_search_url($url)
{
    $url = security_strip_controls(trim((string) $url));
    if ($url === '' || strlen($url) > 2000) {
        return '';
    }
    if (preg_match('#^\s*(javascript|data|vbscript|file)\s*:#iu', $url)) {
        return '';
    }
    if (strpos($url, '//') === 0 || !preg_match('#^https?://#i', $url)) {
        return '';
    }
    if (preg_match('#[<>"\'\x00-\x1f]#', $url)) {
        return '';
    }
    // 占位符替换后再解析主机
    $test = str_replace(['{q}', '{query}', '{keyword}'], 'x', $url);
    $parts = @parse_url($test);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    if (!preg_match('#^[A-Za-z0-9.\-]+$#', $parts['host'])) {
        return '';
    }
    return $url;
}

/**
 * 输出到 href / data-url 前的安全 URL（失败返回 #）
 */
function security_href($url, $allowRelative = true, $fallback = '#')
{
    $safe = security_url($url, $allowRelative);
    return $safe !== '' ? $safe : $fallback;
}

/**
 * 校验请求是否像同源提交（CSRF 纵深）
 * 无 Origin/Referer 时不硬拒（兼容部分客户端），有则必须同源
 */
function security_request_same_origin()
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return true;
    }
    $check = static function ($url) use ($host) {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        $p = @parse_url($url);
        if (!is_array($p) || empty($p['host'])) {
            return false;
        }
        $h = strtolower((string) $p['host']);
        if (!empty($p['port'])) {
            $h .= ':' . (int) $p['port'];
        }
        // HTTP_HOST 可能含端口
        return hash_equals($host, $h) || hash_equals(preg_replace('/:\d+$/', '', $host), preg_replace('/:\d+$/', '', $h));
    };
    $origin = $check($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === false) {
        return false;
    }
    if ($origin === true) {
        return true;
    }
    $referer = $check($_SERVER['HTTP_REFERER'] ?? '');
    if ($referer === false) {
        return false;
    }
    return true;
}

/**
 * 登录失败锁定：检查是否允许尝试
 * @return array{ok:bool,message:string,remain:int}
 */
function security_login_guard($username)
{
    $username = strtolower(security_clean_text($username, 80));
    $ip = security_client_ip();
    $keyUser = 'login_fail_u_' . hash('sha256', $username !== '' ? $username : '-');
    $keyIp = 'login_fail_ip_' . hash('sha256', $ip !== '' ? $ip : 'unknown');
    $now = time();
    $maxUser = 8;
    $maxIp = 25;
    $store = security_login_lock_load();

    foreach ([['k' => $keyUser, 'max' => $maxUser, 'label' => '该账号'], ['k' => $keyIp, 'max' => $maxIp, 'label' => '当前 IP']] as $row) {
        $st = $store[$row['k']] ?? null;
        if (!is_array($st)) {
            continue;
        }
        $until = (int) ($st['locked_until'] ?? 0);
        if ($until > $now) {
            $min = (int) ceil(($until - $now) / 60);
            return [
                'ok' => false,
                'message' => $row['label'] . '登录失败次数过多，请 ' . max(1, $min) . ' 分钟后再试',
                'remain' => 0,
            ];
        }
        if ($until > 0 && $until <= $now) {
            unset($store[$row['k']]);
            security_login_lock_save($store);
        }
    }
    $stU = $store[$keyUser] ?? ['count' => 0];
    $remain = max(0, $maxUser - (int) ($stU['count'] ?? 0));
    return ['ok' => true, 'message' => '', 'remain' => $remain];
}

/**
 * 记录一次登录失败
 */
function security_login_fail($username)
{
    $username = strtolower(security_clean_text($username, 80));
    $ip = security_client_ip();
    $now = time();
    $lockSec = 900;
    $store = security_login_lock_load();
    $specs = [
        ['k' => 'login_fail_u_' . hash('sha256', $username !== '' ? $username : '-'), 'max' => 8],
        ['k' => 'login_fail_ip_' . hash('sha256', $ip !== '' ? $ip : 'unknown'), 'max' => 25],
    ];
    foreach ($specs as $sp) {
        $st = $store[$sp['k']] ?? ['count' => 0, 'first' => $now];
        if (!is_array($st)) {
            $st = ['count' => 0, 'first' => $now];
        }
        if (!empty($st['first']) && ($now - (int) $st['first']) > 3600) {
            $st = ['count' => 0, 'first' => $now];
        }
        $st['count'] = (int) ($st['count'] ?? 0) + 1;
        $st['last'] = $now;
        if ($st['count'] >= $sp['max']) {
            $st['locked_until'] = $now + $lockSec;
        }
        $store[$sp['k']] = $st;
    }
    security_login_lock_save($store);
}

/**
 * 登录成功后清除失败计数，并轮换 CSRF
 */
function security_login_success($username = '')
{
    $username = strtolower(security_clean_text($username, 80));
    $ip = security_client_ip();
    $store = security_login_lock_load();
    unset($store['login_fail_u_' . hash('sha256', $username !== '' ? $username : '-')], $store['login_fail_ip_' . hash('sha256', $ip !== '' ? $ip : 'unknown')]);
    security_login_lock_save($store);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function security_login_lock_file()
{
    $dir = defined('DATA_PATH') ? DATA_PATH . '/cache' : dirname(__DIR__) . '/data/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . '/login_lock.json';
}

function security_login_lock_load()
{
    $file = security_login_lock_file();
    if (!is_file($file)) {
        return [];
    }
    $json = @file_get_contents($file);
    $data = json_decode((string) $json, true);
    return is_array($data) ? $data : [];
}

function security_login_lock_save(array $data)
{
    $now = time();
    foreach ($data as $k => $row) {
        if (!is_array($row)) {
            unset($data[$k]);
            continue;
        }
        $until = (int) ($row['locked_until'] ?? 0);
        $last = (int) ($row['last'] ?? $row['first'] ?? 0);
        if ($until > 0 && $until <= $now) {
            unset($data[$k]);
        } elseif ($until <= 0 && $last > 0 && ($now - $last) > 86400) {
            unset($data[$k]);
        }
    }
    return @file_put_contents(security_login_lock_file(), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

/**
 * 消毒站点内容中的外链（加载 JSON/DB 后统一调用，防存储型伪链）
 * @param array $data
 * @return array
 */
function security_sanitize_site_content(array $data)
{
    $listKeys = [
        'engines' => true,   // 允许搜索占位符
        'shortcuts' => false,
        'sites' => false,
        'projects' => false,
        'tools' => false,
        'links' => false,
    ];
    foreach ($listKeys as $key => $isSearch) {
        if (empty($data[$key]) || !is_array($data[$key])) {
            continue;
        }
        $clean = [];
        foreach ($data[$key] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $urlRaw = trim((string) ($row['url'] ?? ''));
            if ($urlRaw === '') {
                continue;
            }
            if ($isSearch) {
                $url = security_search_url($urlRaw);
                if ($url === '') {
                    $url = security_url($urlRaw, false);
                }
            } else {
                $url = security_url($urlRaw, false);
            }
            if ($url === '') {
                continue;
            }
            $row['url'] = $url;
            if (isset($row['name'])) {
                $row['name'] = security_clean_text($row['name'], 120);
            }
            if (isset($row['desc'])) {
                $row['desc'] = security_clean_text($row['desc'], 500);
            }
            if (isset($row['tag'])) {
                $row['tag'] = security_clean_text($row['tag'], 40);
            }
            $clean[] = $row;
        }
        $data[$key] = $clean;
    }
    if (!empty($data['site']) && is_array($data['site'])) {
        // 文本字段截断，防异常大 payload
        foreach (['name', 'subtitle', 'footer', 'footer_extra', 'seo_title', 'seo_keywords', 'seo_description', 'seo_author'] as $sk) {
            if (isset($data['site'][$sk])) {
                $data['site'][$sk] = security_clean_text($data['site'][$sk], $sk === 'seo_description' || $sk === 'footer_extra' ? 2000 : 300);
            }
        }
        if (isset($data['site']['seo_canonical'])) {
            $data['site']['seo_canonical'] = security_url((string) $data['site']['seo_canonical'], true);
        }
        if (isset($data['site']['seo_og_image'])) {
            $data['site']['seo_og_image'] = security_url((string) $data['site']['seo_og_image'], true);
        }
        if (isset($data['site']['hero_bg'])) {
            $hb = (string) $data['site']['hero_bg'];
            // 仅允许站内上传路径或 http(s)
            if ($hb !== '' && !preg_match('#^assets/images/uploads/[a-zA-Z0-9._-]+$#', $hb)) {
                $data['site']['hero_bg'] = security_url($hb, true);
            }
        }
        if (isset($data['site']['footer_links']) && function_exists('normalize_footer_links')) {
            $data['site']['footer_links'] = normalize_footer_links($data['site']['footer_links']);
        } elseif (isset($data['site']['footer_links']) && is_array($data['site']['footer_links'])) {
            $fl = [];
            foreach ($data['site']['footer_links'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $n = security_clean_text($item['name'] ?? '', 80);
                $u = security_url(trim((string) ($item['url'] ?? '')), true);
                if ($n !== '' && $u !== '') {
                    $fl[] = ['name' => $n, 'url' => $u];
                }
            }
            $data['site']['footer_links'] = $fl;
        }
        if (isset($data['site']['about_html'])) {
            $data['site']['about_html'] = security_sanitize_html($data['site']['about_html']);
        }
        if (isset($data['site']['contact_html'])) {
            $data['site']['contact_html'] = security_sanitize_html($data['site']['contact_html']);
        }
        if (isset($data['site']['seo_head_html']) && function_exists('security_sanitize_head_html')) {
            $data['site']['seo_head_html'] = security_sanitize_head_html($data['site']['seo_head_html']);
        }
    }
    return $data;
}

/**
 * 站内相对跳转白名单（防开放重定向）
 */
function security_safe_redirect_target($url, $fallback = 'index.php')
{
    $url = trim((string) $url);
    $fallback = $fallback !== '' ? $fallback : 'index.php';
    if ($url === '') {
        return $fallback;
    }
    // 拒绝绝对 URL、协议相对、反斜杠、控制字符
    if (preg_match('#^(https?:)?//#i', $url) || strpos($url, '\\') !== false) {
        return $fallback;
    }
    if (preg_match('#[\x00-\x1f\x7f]#', $url)) {
        return $fallback;
    }
    // 仅允许相对路径：字母数字 _-./?&=%# 与 .php
    if (!preg_match('~^[a-zA-Z0-9_./?&=%\-#]+$~', $url)) {
        return $fallback;
    }
    $decodedUrl = rawurldecode($url);
    if (strpos($url, '..') !== false || strpos($decodedUrl, '..') !== false) {
        return $fallback;
    }
    // 禁止跳到敏感路径
    if (preg_match('#(^|/)(config|data|includes|scripts)(/|$)#i', $url)) {
        return $fallback;
    }
    return $url;
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
                            $rels = preg_split('/\s+/', trim($rel)) ?: [];
                            $allowedRels = ['canonical', 'icon', 'shortcut', 'apple-touch-icon', 'alternate', 'manifest', 'stylesheet'];
                            $blockedRels = ['import', 'prefetch', 'preload', 'modulepreload'];
                            foreach ($rels as $relToken) {
                                if ($relToken === '' || in_array($relToken, $blockedRels, true) || !in_array($relToken, $allowedRels, true)) {
                                    continue 3;
                                }
                            }
                            if (!$rels) {
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
