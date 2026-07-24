<?php
/**
 * 热榜站点凭证：Linux.do Connect OAuth2 + 吾爱（Discuz 无官方 OAuth，授权码式 Cookie 授权）
 */

if (!defined('ROOT_PATH')) {
    require_once __DIR__ . '/bootstrap.php';
}
require_once __DIR__ . '/hot_fetcher.php';

/** Linux DO Connect 端点 */
define('LINUXDO_OAUTH_AUTHORIZE', 'https://connect.linux.do/oauth2/authorize');
define('LINUXDO_OAUTH_TOKEN', 'https://connect.linux.do/oauth2/token');
define('LINUXDO_OAUTH_USER', 'https://connect.linux.do/api/user');

/**
 * 当前站点 origin（用于拼回调地址）
 */
function oauth_site_origin()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

/**
 * 管理后台相对路径前缀（/admin/ 或 /xxx/admin/）
 */
function oauth_admin_base_path()
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/oauth_linuxdo.php'));
    $dir = rtrim(dirname($script), '/');
    if ($dir === '' || $dir === '.') {
        return '/admin';
    }
    // 若当前在 admin 下
    if (substr($dir, -6) === '/admin' || $dir === '/admin') {
        return $dir;
    }
    return $dir;
}

function oauth_linuxdo_callback_url()
{
    return oauth_site_origin() . oauth_admin_base_path() . '/oauth_linuxdo_callback.php';
}

function oauth_52pojie_callback_url()
{
    return oauth_site_origin() . oauth_admin_base_path() . '/oauth_52pojie_callback.php';
}

/**
 * HTTP POST application/x-www-form-urlencoded
 * @return array{ok:bool,body:string,code:int,error?:string}
 */
function oauth_http_post($url, array $fields, $timeout = 20, array $headers = [])
{
    $body = http_build_query($fields, '', '&');
    $headers = array_merge([
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ], $headers);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'RainbowNav-OAuth/1.0',
        ]);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($errno !== 0) {
            return ['ok' => false, 'body' => '', 'code' => 0, 'error' => $err];
        }
        return ['ok' => $code >= 200 && $code < 300, 'body' => (string) $resp, 'code' => $code];
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    if ($resp === false) {
        return ['ok' => false, 'body' => '', 'code' => $code, 'error' => 'request failed'];
    }
    return ['ok' => $code >= 200 && $code < 300, 'body' => (string) $resp, 'code' => $code];
}

/* ===================== Linux.do Connect OAuth App 配置 ===================== */

function oauth_linuxdo_app_file()
{
    return ROOT_PATH . '/config/linuxdo_oauth_app.json';
}

/**
 * @return array{client_id:string,client_secret:string,redirect_uri:string,updated_at:string}
 */
function oauth_linuxdo_app_config()
{
    $file = oauth_linuxdo_app_file();
    $fromFile = [];
    if (is_file($file) && is_readable($file)) {
        $j = json_decode((string) file_get_contents($file), true);
        if (is_array($j)) {
            $fromFile = $j;
        }
    }
    $clientId = trim((string) ($fromFile['client_id'] ?? hot_setting_get('linuxdo_oauth_client_id', '')));
    $clientSecret = trim((string) ($fromFile['client_secret'] ?? hot_setting_get('linuxdo_oauth_client_secret', '')));
    $redirect = trim((string) ($fromFile['redirect_uri'] ?? hot_setting_get('linuxdo_oauth_redirect_uri', '')));
    if ($redirect === '') {
        $redirect = oauth_linuxdo_callback_url();
    }
    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirect,
        'updated_at' => (string) ($fromFile['updated_at'] ?? hot_setting_get('linuxdo_oauth_app_updated_at', '')),
    ];
}

function oauth_linuxdo_save_app(array $input)
{
    $prev = oauth_linuxdo_app_config();
    $clientId = trim((string) ($input['client_id'] ?? ''));
    $clientSecret = trim((string) ($input['client_secret'] ?? ''));
    $redirect = trim((string) ($input['redirect_uri'] ?? ''));

    if ($clientId === '') {
        $clientId = $prev['client_id'];
    }
    // secret 留空则保留
    if ($clientSecret === '') {
        $clientSecret = $prev['client_secret'];
    }
    if ($redirect === '') {
        $redirect = oauth_linuxdo_callback_url();
    }

    $payload = [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirect,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    $dir = dirname(oauth_linuxdo_app_file());
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $okFile = @file_put_contents(oauth_linuxdo_app_file(), $json, LOCK_EX) !== false;
    if ($okFile) {
        @chmod(oauth_linuxdo_app_file(), 0600);
    }
    hot_setting_set('linuxdo_oauth_client_id', $clientId);
    hot_setting_set('linuxdo_oauth_client_secret', $clientSecret);
    hot_setting_set('linuxdo_oauth_redirect_uri', $redirect);
    hot_setting_set('linuxdo_oauth_app_updated_at', $payload['updated_at']);
    return $okFile;
}

function oauth_linuxdo_app_ready()
{
    $c = oauth_linuxdo_app_config();
    return $c['client_id'] !== '' && $c['client_secret'] !== '';
}

/**
 * 发起 Linux.do OAuth 授权
 * @return string|null 授权 URL；失败 null
 */
function oauth_linuxdo_build_authorize_url()
{
    $app = oauth_linuxdo_app_config();
    if ($app['client_id'] === '' || $app['client_secret'] === '') {
        return null;
    }
    $state = bin2hex(random_bytes(16));
    $_SESSION['linuxdo_oauth_state'] = $state;
    $_SESSION['linuxdo_oauth_state_at'] = time();

    $q = http_build_query([
        'client_id' => $app['client_id'],
        'redirect_uri' => $app['redirect_uri'],
        'response_type' => 'code',
        'scope' => 'user',
        'state' => $state,
    ], '', '&');
    return LINUXDO_OAUTH_AUTHORIZE . '?' . $q;
}

/**
 * 用 code 换 token + 用户信息，并持久化 api_key
 * @return array{ok:bool,message:string,username?:string}
 */
function oauth_linuxdo_handle_callback($code, $state)
{
    $code = trim((string) $code);
    $state = trim((string) $state);
    $expect = (string) ($_SESSION['linuxdo_oauth_state'] ?? '');
    $stateAt = (int) ($_SESSION['linuxdo_oauth_state_at'] ?? 0);
    unset($_SESSION['linuxdo_oauth_state'], $_SESSION['linuxdo_oauth_state_at']);

    if ($code === '') {
        return ['ok' => false, 'message' => '缺少授权码 code'];
    }
    if ($expect === '' || !hash_equals($expect, $state)) {
        return ['ok' => false, 'message' => 'state 校验失败，请重新发起授权'];
    }
    if ($stateAt > 0 && (time() - $stateAt) > 900) {
        return ['ok' => false, 'message' => '授权已超时，请重试'];
    }

    $app = oauth_linuxdo_app_config();
    if ($app['client_id'] === '' || $app['client_secret'] === '') {
        return ['ok' => false, 'message' => '尚未配置 Client ID / Secret'];
    }

    $tokenRes = oauth_http_post(LINUXDO_OAUTH_TOKEN, [
        'client_id' => $app['client_id'],
        'client_secret' => $app['client_secret'],
        'code' => $code,
        'redirect_uri' => $app['redirect_uri'],
        'grant_type' => 'authorization_code',
    ]);
    if (!$tokenRes['ok'] && $tokenRes['body'] === '') {
        return ['ok' => false, 'message' => '换取 access_token 失败：' . ($tokenRes['error'] ?? '网络错误')];
    }
    $tokenJson = json_decode($tokenRes['body'], true);
    if (!is_array($tokenJson) || empty($tokenJson['access_token'])) {
        $hint = $tokenRes['body'] !== '' ? mb_substr_safe($tokenRes['body'], 0, 200) : ('HTTP ' . $tokenRes['code']);
        return ['ok' => false, 'message' => '换取 access_token 失败：' . $hint];
    }
    $accessToken = (string) $tokenJson['access_token'];

    $userBody = hot_http_get(LINUXDO_OAUTH_USER, 20, [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
    ]);
    $user = $userBody ? json_decode($userBody, true) : null;
    if (!is_array($user)) {
        return ['ok' => false, 'message' => '获取用户信息失败，access_token 可能无效'];
    }

    $username = trim((string) ($user['username'] ?? $user['login'] ?? $user['name'] ?? ''));
    $apiKey = trim((string) ($user['api_key'] ?? ''));
    if ($apiKey === '') {
        // 部分实现把 key 放在 data 下
        $apiKey = trim((string) ($user['data']['api_key'] ?? ''));
    }
    if ($apiKey === '') {
        // 仍保存 access_token 作为兜底（部分接口可用 Bearer，但 Discourse 热榜用 Api-Key）
        return [
            'ok' => false,
            'message' => '用户信息中未返回 api_key。请确认 Connect 应用已获准，或改用 Cookie/后台 API Key。用户：' . ($username !== '' ? $username : '未知'),
        ];
    }

    $saved = hot_linuxdo_save_credentials([
        'mode' => 'api',
        'api_key' => $apiKey,
        'api_username' => $username !== '' ? $username : 'system',
        'force_api_key' => 1,
        // 不碰 cookie
        'cookie' => '',
    ]);
    // 额外存 OAuth 元数据
    hot_setting_set('linuxdo_oauth_username', $username);
    hot_setting_set('linuxdo_oauth_user_id', (string) ($user['id'] ?? $user['sub'] ?? ''));
    hot_setting_set('linuxdo_oauth_bound_at', date('Y-m-d H:i:s'));
    hot_setting_set('linuxdo_oauth_has_token', '1');

    if (!$saved) {
        return ['ok' => false, 'message' => '已拿到 api_key 但保存失败，请检查写权限'];
    }

    // 清缓存
    $f = ROOT_PATH . '/data/cache/linuxdo.json';
    if (is_file($f)) {
        @unlink($f);
    }

    return [
        'ok' => true,
        'message' => 'Linux.do OAuth 授权成功，已保存 API Key',
        'username' => $username,
    ];
}

/* ===================== 吾爱破解：授权式 Cookie（无官方 OAuth） ===================== */

function hot_52pojie_secret_file()
{
    return ROOT_PATH . '/config/52pojie_auth.json';
}

function hot_52pojie_secret_backup_file()
{
    return ROOT_PATH . '/data/52pojie_auth.json';
}

/**
 * @return array{cookie:string,username:string,mode:string,updated_at:string,source:string}
 */
function hot_52pojie_normalize_cred(array $data)
{
    $out = [
        'cookie' => trim((string) ($data['cookie'] ?? '')),
        'username' => trim((string) ($data['username'] ?? '')),
        'mode' => trim((string) ($data['mode'] ?? 'auto')),
        'updated_at' => trim((string) ($data['updated_at'] ?? '')),
        'source' => trim((string) ($data['source'] ?? '')),
    ];
    if (!in_array($out['mode'], ['auto', 'cookie', 'none'], true)) {
        $out['mode'] = 'auto';
    }
    return $out;
}

function hot_52pojie_read_file($path)
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $j = json_decode((string) file_get_contents($path), true);
    return is_array($j) ? hot_52pojie_normalize_cred($j) : null;
}

function hot_52pojie_credentials()
{
    $candidates = [];
    foreach ([hot_52pojie_secret_file(), hot_52pojie_secret_backup_file()] as $f) {
        $c = hot_52pojie_read_file($f);
        if ($c) {
            $candidates[] = $c;
        }
    }
    $dbCookie = (string) hot_setting_get('52pojie_cookie', '');
    $dbUser = (string) hot_setting_get('52pojie_username', '');
    $dbMode = (string) hot_setting_get('52pojie_auth_mode', '');
    $dbAt = (string) hot_setting_get('52pojie_auth_updated_at', '');
    $dbSrc = (string) hot_setting_get('52pojie_auth_source', '');
    if ($dbCookie !== '' || $dbMode !== '') {
        $candidates[] = hot_52pojie_normalize_cred([
            'cookie' => $dbCookie,
            'username' => $dbUser,
            'mode' => $dbMode !== '' ? $dbMode : 'auto',
            'updated_at' => $dbAt,
            'source' => $dbSrc,
        ]);
    }

    $best = hot_52pojie_normalize_cred([]);
    $bestScore = -1;
    foreach ($candidates as $c) {
        $has = $c['cookie'] !== '' ? 1 : 0;
        $ts = $c['updated_at'] !== '' ? (int) strtotime($c['updated_at']) : 0;
        $score = $has * 10000000000 + $ts;
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $c;
        }
    }
    return $best;
}

function hot_52pojie_write_files(array $payload)
{
    $payload = hot_52pojie_normalize_cred($payload);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    $ok = false;
    foreach ([hot_52pojie_secret_file(), hot_52pojie_secret_backup_file()] as $file) {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (@file_put_contents($file, $json, LOCK_EX) !== false) {
            $ok = true;
            @chmod($file, 0600);
        }
    }
    return $ok;
}

function hot_52pojie_write_db(array $payload)
{
    $payload = hot_52pojie_normalize_cred($payload);
    $ok = true;
    $ok = hot_setting_set('52pojie_cookie', $payload['cookie']) && $ok;
    $ok = hot_setting_set('52pojie_username', $payload['username']) && $ok;
    $ok = hot_setting_set('52pojie_auth_mode', $payload['mode']) && $ok;
    $ok = hot_setting_set('52pojie_auth_updated_at', $payload['updated_at']) && $ok;
    $ok = hot_setting_set('52pojie_auth_source', $payload['source']) && $ok;
    $ok = hot_setting_set('52pojie_has_cookie', $payload['cookie'] !== '' ? '1' : '0') && $ok;
    return $ok;
}

function hot_52pojie_has_auth()
{
    $c = hot_52pojie_credentials();
    if ($c['mode'] === 'none') {
        return false;
    }
    return $c['cookie'] !== '';
}

/**
 * 保存吾爱凭证
 */
function hot_52pojie_save_credentials(array $input)
{
    $prev = hot_52pojie_credentials();
    $mode = trim((string) ($input['mode'] ?? $prev['mode'] ?? 'auto'));
    if (!in_array($mode, ['auto', 'cookie', 'none'], true)) {
        $mode = 'auto';
    }

    if (!empty($input['clear'])) {
        $payload = hot_52pojie_normalize_cred([
            'cookie' => '',
            'username' => '',
            'mode' => 'none',
            'updated_at' => date('Y-m-d H:i:s'),
            'source' => '',
        ]);
    } else {
        $newCookie = trim((string) ($input['cookie'] ?? ''));
        if (!empty($input['force_cookie']) || $newCookie !== '') {
            $cookie = $newCookie !== '' ? $newCookie : (string) ($input['cookie'] ?? '');
            if (!empty($input['force_cookie'])) {
                $cookie = $newCookie;
            }
        } else {
            $cookie = $prev['cookie'];
        }
        // 简化：有 force 或非空则用新值
        if (!empty($input['force_cookie'])) {
            $cookie = trim((string) ($input['cookie'] ?? ''));
        } elseif ($newCookie !== '') {
            $cookie = $newCookie;
        } else {
            $cookie = $prev['cookie'];
        }
        $username = trim((string) ($input['username'] ?? ''));
        if ($username === '') {
            $username = $prev['username'];
        }
        $source = trim((string) ($input['source'] ?? $prev['source'] ?? 'manual'));
        if ($mode === 'none' && $cookie !== '') {
            $mode = 'auto';
        }
        $payload = hot_52pojie_normalize_cred([
            'cookie' => $cookie,
            'username' => $username,
            'mode' => $mode,
            'updated_at' => date('Y-m-d H:i:s'),
            'source' => $source,
        ]);
    }

    $okFile = hot_52pojie_write_files($payload);
    $okDb = hot_52pojie_write_db($payload);
    $f = HOT_CACHE_DIR . '/52pojie.json';
    if (is_file($f)) {
        @unlink($f);
    }
    return $okFile || $okDb;
}

/**
 * 吾爱 OAuth 式授权：生成 state，返回授权引导页 URL
 */
function oauth_52pojie_start()
{
    $state = bin2hex(random_bytes(16));
    $_SESSION['52pojie_oauth_state'] = $state;
    $_SESSION['52pojie_oauth_state_at'] = time();
    return oauth_admin_base_path() . '/oauth_52pojie.php?step=authorize&state=' . urlencode($state);
}

/**
 * 吾爱授权回调：校验 state + 保存 cookie
 * @return array{ok:bool,message:string,username?:string}
 */
function oauth_52pojie_handle_callback(array $input)
{
    $state = trim((string) ($input['state'] ?? ''));
    $cookie = trim((string) ($input['cookie'] ?? ''));
    $username = trim((string) ($input['username'] ?? ''));
    $expect = (string) ($_SESSION['52pojie_oauth_state'] ?? '');
    $stateAt = (int) ($_SESSION['52pojie_oauth_state_at'] ?? 0);
    unset($_SESSION['52pojie_oauth_state'], $_SESSION['52pojie_oauth_state_at']);

    if ($expect === '' || !hash_equals($expect, $state)) {
        return ['ok' => false, 'message' => 'state 校验失败，请重新发起授权'];
    }
    if ($stateAt > 0 && (time() - $stateAt) > 900) {
        return ['ok' => false, 'message' => '授权已超时，请重试'];
    }
    if ($cookie === '') {
        return ['ok' => false, 'message' => '请提供登录后的 Cookie'];
    }
    // 简单校验：Discuz 常见 cookie 字段
    if (stripos($cookie, 'htVC_') === false && stripos($cookie, 'auth') === false && stripos($cookie, 'sid') === false) {
        // 仍允许保存，仅提示
    }

    $saved = hot_52pojie_save_credentials([
        'cookie' => $cookie,
        'username' => $username,
        'mode' => 'cookie',
        'force_cookie' => 1,
        'source' => 'oauth',
    ]);
    if (!$saved) {
        return ['ok' => false, 'message' => '凭证保存失败'];
    }

    // 轻量探测
    $test = hot_52pojie_test_auth();
    $msg = '吾爱授权成功，Cookie 已持久化';
    if (!empty($test['username'])) {
        $msg .= '（用户：' . $test['username'] . '）';
        hot_52pojie_save_credentials([
            'cookie' => $cookie,
            'username' => $test['username'],
            'mode' => 'cookie',
            'force_cookie' => 1,
            'source' => 'oauth',
        ]);
    } elseif (empty($test['ok'])) {
        $msg .= '；探测未确认登录态，抓取时仍会尝试带 Cookie';
    }

    return ['ok' => true, 'message' => $msg, 'username' => $test['username'] ?? $username];
}

/**
 * 测试吾爱 Cookie
 * @return array{ok:bool,message:string,username?:string}
 */
function hot_52pojie_test_auth()
{
    $cred = hot_52pojie_credentials();
    if ($cred['cookie'] === '') {
        return ['ok' => false, 'message' => '尚未配置吾爱 Cookie'];
    }
    $url = 'https://www.52pojie.cn/home.php?mod=space';
    $body = hot_http_get($url, 15, [
        'Cookie: ' . $cred['cookie'],
        'Referer: https://www.52pojie.cn/',
        'Accept: text/html',
    ]);
    if (!$body) {
        return ['ok' => false, 'message' => '无法访问吾爱（网络或拦截）'];
    }
    $body = hot_to_utf8($body, 'GBK');
    $username = '';
    if (preg_match('/title="访问我的空间">([^<]+)</u', $body, $m)) {
        $username = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    } elseif (preg_match('/欢迎您，?\s*<[^>]+>([^<]+)/u', $body, $m)) {
        $username = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    }
    if (strpos($body, '需要先登录') !== false || strpos($body, 'member.php?mod=logging') !== false && $username === '') {
        // 仍可能部分页面能访问
        if ($username === '') {
            return ['ok' => false, 'message' => 'Cookie 可能无效或已过期'];
        }
    }
    // 再试热榜页
    $rank = hot_http_get(
        'https://www.52pojie.cn/misc.php?mod=ranklist&type=thread&view=heats&orderby=today',
        15,
        ['Cookie: ' . $cred['cookie'], 'Referer: https://www.52pojie.cn/', 'Accept: text/html']
    );
    $rankOk = $rank && stripos($rank, 'thread-') !== false;

    if ($username !== '' || $rankOk) {
        return [
            'ok' => true,
            'message' => $username !== ''
                ? ('登录有效：' . $username . ($rankOk ? '，热榜页可访问' : ''))
                : 'Cookie 可用，已能访问热榜页',
            'username' => $username,
        ];
    }
    return ['ok' => false, 'message' => '未能确认登录态，请重新授权'];
}

function mb_substr_safe($str, $start, $len)
{
    if (function_exists('mb_substr')) {
        return mb_substr((string) $str, $start, $len, 'UTF-8');
    }
    return substr((string) $str, $start, $len);
}
