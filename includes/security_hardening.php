<?php
/**
 * 全站安全加固：URL 再校验、登录锁定、同源 CSRF、安全重定向、内容消毒
 * 由 bootstrap.php 在 security.php 之后加载。
 */



if (!function_exists('security_login_store_path')) {
    function security_login_store_path()
    {
        return security_login_lock_file();
    }
}

if (!function_exists('security_login_store_load')) {
    function security_login_store_load()
    {
        return security_login_lock_load();
    }
}

if (!function_exists('security_login_store_save')) {
    function security_login_store_save(array $data)
    {
        return security_login_lock_save($data);
    }
}
if (!function_exists('security_client_ip')) {
    /**
     * 客户端 IP（默认 REMOTE_ADDR；仅 trust_proxy 时解析代理头）
     */
    function security_client_ip()
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP)) {
            if (!function_exists('security_trust_proxy') || !security_trust_proxy()) {
                return $remote;
            }
            $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($xff !== '') {
                $parts = array_map('trim', explode(',', $xff));
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
}

// 覆盖 security_ip：无参时走 client_ip（若原函数已加载则无法重定义，故仅在缺失时定义）
if (!function_exists('security_ip')) {
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
}

if (!function_exists('security_search_url')) {
    /**
     * 搜索引擎 URL（允许 {q}/{query} 占位符）
     */
    function security_search_url($url)
    {
        $url = function_exists('security_strip_controls')
            ? security_strip_controls(trim((string) $url))
            : trim((string) $url);
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
}

if (!function_exists('security_href')) {
    /**
     * 输出到 href / data-url 前的安全 URL（失败返回 #）
     */
    function security_href($url, $allowRelative = true, $fallback = '#')
    {
        $safe = function_exists('security_url') ? security_url($url, $allowRelative) : '';
        return $safe !== '' ? $safe : $fallback;
    }
}

if (!function_exists('security_request_same_origin')) {
    /**
     * 校验请求是否像同源提交（有 Origin/Referer 则必须同源）
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
            $hostNoPort = preg_replace('/:\d+$/', '', $host);
            $hNoPort = preg_replace('/:\d+$/', '', $h);
            return hash_equals($host, $h) || hash_equals((string) $hostNoPort, (string) $hNoPort);
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
}

if (!function_exists('security_login_guard')) {
    /**
     * @return array{ok:bool,message:string,remain:int}
     */
    function security_login_guard($username)
    {
        $username = strtolower(function_exists('security_clean_text') ? security_clean_text($username, 80) : substr((string) $username, 0, 80));
        $ip = function_exists('security_client_ip') ? security_client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $keyUser = 'login_fail_u_' . hash('sha256', $username !== '' ? $username : '-');
        $keyIp = 'login_fail_ip_' . hash('sha256', $ip !== '' ? $ip : 'unknown');
        $now = time();
        $maxUser = 8;
        $store = security_login_store_load();

        foreach ([['k' => $keyUser, 'label' => '该账号'], ['k' => $keyIp, 'label' => '当前 IP']] as $row) {
            $st = $store[$row['k']] ?? null;
            if (!is_array($st)) {
                // 兼容旧会话数据
                $st = $_SESSION[$row['k']] ?? null;
            }
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
        }
        $stU = $store[$keyUser] ?? ($_SESSION[$keyUser] ?? ['count' => 0]);
        $remain = max(0, $maxUser - (int) ($stU['count'] ?? 0));
        return ['ok' => true, 'message' => '', 'remain' => $remain];
    }
}

if (!function_exists('security_login_fail')) {
    function security_login_fail($username)
    {
        $username = strtolower(function_exists('security_clean_text') ? security_clean_text($username, 80) : substr((string) $username, 0, 80));
        $ip = function_exists('security_client_ip') ? security_client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $now = time();
        $lockSec = 900;
        $specs = [
            ['k' => 'login_fail_u_' . hash('sha256', $username !== '' ? $username : '-'), 'max' => 8],
            ['k' => 'login_fail_ip_' . hash('sha256', $ip !== '' ? $ip : 'unknown'), 'max' => 25],
        ];
        $store = security_login_store_load();
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
            // 同步会话（可选加速）
            $_SESSION[$sp['k']] = $st;
        }
        security_login_store_save($store);
    }
}

if (!function_exists('security_login_success')) {
    function security_login_success($username = '')
    {
        $username = strtolower(function_exists('security_clean_text') ? security_clean_text($username, 80) : substr((string) $username, 0, 80));
        $ip = function_exists('security_client_ip') ? security_client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ku = 'login_fail_u_' . hash('sha256', $username !== '' ? $username : '-');
        $ki = 'login_fail_ip_' . hash('sha256', $ip !== '' ? $ip : 'unknown');
        unset($_SESSION[$ku], $_SESSION[$ki]);
        $store = security_login_store_load();
        unset($store[$ku], $store[$ki]);
        security_login_store_save($store);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

if (!function_exists('security_safe_redirect_target')) {
    function security_safe_redirect_target($url, $fallback = 'index.php')
    {
        $url = trim((string) $url);
        $fallback = $fallback !== '' ? $fallback : 'index.php';
        if ($url === '') {
            return $fallback;
        }
        if (preg_match('#^(https?:)?//#i', $url) || strpos($url, '\\') !== false) {
            return $fallback;
        }
        if (preg_match('#[\x00-\x1f\x7f]#', $url)) {
            return $fallback;
        }
        // 使用 ~ 作定界符，避免与路径中的 # 冲突
        if (!preg_match('~^[a-zA-Z0-9_./?&=%\-#]+$~', $url)) {
            return $fallback;
        }
        $decodedUrl = rawurldecode($url);
        if (strpos($url, '..') !== false || strpos($decodedUrl, '..') !== false) {
            return $fallback;
        }
        if (preg_match('#(^|/)(config|data|includes|scripts)(/|$)#i', $url)) {
            return $fallback;
        }
        return $url;
    }
}

if (!function_exists('security_sanitize_site_content')) {
    /**
     * 消毒站点内容中的外链与文本（加载 JSON/DB 后统一调用）
     * @param array $data
     * @return array
     */
    function security_sanitize_site_content(array $data)
    {
        $listKeys = [
            'engines' => true,
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
                if ($isSearch || (($row['type'] ?? '') === 'search')) {
                    $url = security_search_url($urlRaw);
                    if ($url === '' && function_exists('security_url')) {
                        $url = security_url($urlRaw, false);
                    }
                } else {
                    $url = function_exists('security_url') ? security_url($urlRaw, false) : '';
                }
                if ($url === '') {
                    continue;
                }
                $row['url'] = $url;
                if (isset($row['name']) && function_exists('security_clean_text')) {
                    $row['name'] = security_clean_text($row['name'], 120);
                }
                if (isset($row['desc']) && function_exists('security_clean_text')) {
                    $row['desc'] = security_clean_text($row['desc'], 500);
                }
                if (isset($row['tag']) && function_exists('security_clean_text')) {
                    $row['tag'] = security_clean_text($row['tag'], 40);
                }
                $clean[] = $row;
            }
            $data[$key] = $clean;
        }
        if (!empty($data['site']) && is_array($data['site'])) {
            foreach (['name', 'subtitle', 'footer', 'footer_extra', 'seo_title', 'seo_keywords', 'seo_description', 'seo_author'] as $sk) {
                if (isset($data['site'][$sk]) && function_exists('security_clean_text')) {
                    $max = ($sk === 'seo_description' || $sk === 'footer_extra') ? 2000 : 300;
                    $data['site'][$sk] = security_clean_text($data['site'][$sk], $max);
                }
            }
            if (isset($data['site']['seo_canonical']) && function_exists('security_url')) {
                $data['site']['seo_canonical'] = security_url((string) $data['site']['seo_canonical'], true);
            }
            if (isset($data['site']['seo_og_image']) && function_exists('security_url')) {
                $data['site']['seo_og_image'] = security_url((string) $data['site']['seo_og_image'], true);
            }
            if (isset($data['site']['hero_bg'])) {
                $hb = (string) $data['site']['hero_bg'];
                if ($hb !== '' && !preg_match('#^assets/images/uploads/[a-zA-Z0-9._-]+$#', $hb) && function_exists('security_url')) {
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
                    $n = function_exists('security_clean_text') ? security_clean_text($item['name'] ?? '', 80) : trim((string) ($item['name'] ?? ''));
                    $u = function_exists('security_url') ? security_url(trim((string) ($item['url'] ?? '')), true) : '';
                    if ($n !== '' && $u !== '') {
                        $fl[] = ['name' => $n, 'url' => $u];
                    }
                }
                $data['site']['footer_links'] = $fl;
            }
            if (isset($data['site']['about_html']) && function_exists('security_sanitize_html')) {
                $data['site']['about_html'] = security_sanitize_html($data['site']['about_html']);
            }
            if (isset($data['site']['contact_html']) && function_exists('security_sanitize_html')) {
                $data['site']['contact_html'] = security_sanitize_html($data['site']['contact_html']);
            }
            if (isset($data['site']['seo_head_html']) && function_exists('security_sanitize_head_html')) {
                $data['site']['seo_head_html'] = security_sanitize_head_html($data['site']['seo_head_html']);
            }
        }
        return $data;
    }
}
