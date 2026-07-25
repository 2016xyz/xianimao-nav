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
 * 优先 seo_canonical / site_url；反代头走 security_is_https（需 trust_proxy）
 */
function oauth_site_origin()
{
    if (function_exists('setting_get')) {
        foreach (['seo_canonical', 'site_url'] as $key) {
            $canon = trim((string) setting_get($key, ''));
            if ($canon !== '' && preg_match('#^(https?://[a-zA-Z0-9.\-:]+)#', $canon, $m)) {
                return rtrim($m[1], '/');
            }
        }
    }

    $https = function_exists('security_is_https')
        ? security_is_https()
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443'));
    $scheme = $https ? 'https' : 'http';
    $host = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if ($host === '') {
        $host = 'localhost';
    }
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
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'RainbowNav-OAuth/1.0',
        ]);
        if (function_exists('security_curl_set_ssl')) {
            security_curl_set_ssl($ch);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, function_exists('security_ssl_verify_peer') ? security_ssl_verify_peer() : true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, (function_exists('security_ssl_verify_peer') && !security_ssl_verify_peer()) ? 0 : 2);
        }
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
        'ssl' => function_exists('security_stream_ssl_opts')
            ? security_stream_ssl_opts()
            : [
                'verify_peer' => function_exists('security_ssl_verify_peer') ? security_ssl_verify_peer() : true,
                'verify_peer_name' => function_exists('security_ssl_verify_peer') ? security_ssl_verify_peer() : true,
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
    $defaults = ['client_id' => '', 'client_secret' => '', 'redirect_uri' => '', 'updated_at' => ''];

    $blob = secret_blob_get('linuxdo_oauth_app');
    if (!is_array($blob) || $blob === []) {
        $blob = secret_blob_migrate_from_file('linuxdo_oauth_app', oauth_linuxdo_app_file());
    }
    if (is_array($blob) && $blob !== []) {
        $clientId = trim((string) ($blob['client_id'] ?? ''));
        $clientSecret = trim((string) ($blob['client_secret'] ?? ''));
        $redirect = trim((string) ($blob['redirect_uri'] ?? ''));
        if ($redirect === '') {
            $redirect = oauth_linuxdo_callback_url();
        }
        return array_merge($defaults, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirect,
            'updated_at' => (string) ($blob['updated_at'] ?? ''),
        ]);
    }

    // 兼容历史分散 settings / 文件
    $clientId = trim((string) hot_setting_get('linuxdo_oauth_client_id', ''));
    $clientSecret = trim((string) hot_setting_get('linuxdo_oauth_client_secret', ''));
    $redirect = trim((string) hot_setting_get('linuxdo_oauth_redirect_uri', ''));
    if ($redirect === '') {
        $redirect = oauth_linuxdo_callback_url();
    }
    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirect,
        'updated_at' => (string) hot_setting_get('linuxdo_oauth_app_updated_at', ''),
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

    // 主存储：仅 secret_linuxdo_oauth_app（不双写 client_secret 明文）
    $ok = secret_blob_set('linuxdo_oauth_app', $payload);
    $ok = hot_setting_set('linuxdo_oauth_app_updated_at', $payload['updated_at']) && $ok;
    $ok = hot_setting_set('linuxdo_oauth_has_app', ($payload['client_id'] !== '' && $payload['client_secret'] !== '') ? '1' : '0') && $ok;
    return $ok;
}

function oauth_linuxdo_app_ready()
{
    $c = oauth_linuxdo_app_config();
    return $c['client_id'] !== '' && $c['client_secret'] !== '';
}

/**
 * PKCE：生成 code_verifier / code_challenge (S256)
 * @return array{verifier:string,challenge:string}
 */
function oauth_pkce_pair()
{
    // 43–128 字符的高熵 verifier
    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return ['verifier' => $verifier, 'challenge' => $challenge];
}

/**
 * 读取/写入 Linux.do OAuth 令牌（secret 块，不落明文配置）
 * @return array{access_token:string,refresh_token:string,expires_at:int,token_type:string,scope:string,obtained_at:string}
 */
function oauth_linuxdo_token_get()
{
    $empty = [
        'access_token' => '',
        'refresh_token' => '',
        'expires_at' => 0,
        'token_type' => 'Bearer',
        'scope' => '',
        'obtained_at' => '',
    ];
    if (!function_exists('secret_blob_get')) {
        return $empty;
    }
    $blob = secret_blob_get('linuxdo_oauth_token');
    if (!is_array($blob)) {
        return $empty;
    }
    return [
        'access_token' => trim((string) ($blob['access_token'] ?? '')),
        'refresh_token' => trim((string) ($blob['refresh_token'] ?? '')),
        'expires_at' => (int) ($blob['expires_at'] ?? 0),
        'token_type' => trim((string) ($blob['token_type'] ?? 'Bearer')) ?: 'Bearer',
        'scope' => trim((string) ($blob['scope'] ?? '')),
        'obtained_at' => trim((string) ($blob['obtained_at'] ?? '')),
    ];
}

/**
 * @param array $token
 * @return bool
 */
function oauth_linuxdo_token_set(array $token)
{
    if (!function_exists('secret_blob_set')) {
        return false;
    }
    $payload = [
        'access_token' => trim((string) ($token['access_token'] ?? '')),
        'refresh_token' => trim((string) ($token['refresh_token'] ?? '')),
        'expires_at' => (int) ($token['expires_at'] ?? 0),
        'token_type' => trim((string) ($token['token_type'] ?? 'Bearer')) ?: 'Bearer',
        'scope' => trim((string) ($token['scope'] ?? '')),
        'obtained_at' => trim((string) ($token['obtained_at'] ?? date('Y-m-d H:i:s'))),
    ];
    $ok = secret_blob_set('linuxdo_oauth_token', $payload);
    if ($ok) {
        hot_setting_set('linuxdo_oauth_has_token', $payload['access_token'] !== '' ? '1' : '0');
        hot_setting_set('linuxdo_oauth_token_expires_at', $payload['expires_at'] > 0 ? (string) $payload['expires_at'] : '');
        hot_setting_set('linuxdo_oauth_token_at', $payload['obtained_at']);
    }
    return $ok;
}

/**
 * 清除 OAuth 令牌与绑定元数据
 */
function oauth_linuxdo_token_clear()
{
    if (function_exists('secret_blob_set')) {
        secret_blob_set('linuxdo_oauth_token', [
            'access_token' => '',
            'refresh_token' => '',
            'expires_at' => 0,
            'token_type' => 'Bearer',
            'scope' => '',
            'obtained_at' => '',
        ]);
    }
    hot_setting_set('linuxdo_oauth_has_token', '0');
    hot_setting_set('linuxdo_oauth_token_expires_at', '');
    hot_setting_set('linuxdo_oauth_token_at', '');
    hot_setting_set('linuxdo_oauth_username', '');
    hot_setting_set('linuxdo_oauth_user_id', '');
    hot_setting_set('linuxdo_oauth_bound_at', '');
    hot_setting_set('linuxdo_oauth_last_error', '');
    hot_setting_set('linuxdo_oauth_scope', '');
}

/**
 * 从 token 响应解析并持久化
 * @return array{access_token:string,refresh_token:string,expires_at:int,token_type:string,scope:string,obtained_at:string}
 */
function oauth_linuxdo_token_store_from_response(array $tokenJson, array $prev = [])
{
    $access = trim((string) ($tokenJson['access_token'] ?? ''));
    $refresh = trim((string) ($tokenJson['refresh_token'] ?? ''));
    if ($refresh === '' && !empty($prev['refresh_token'])) {
        $refresh = (string) $prev['refresh_token'];
    }
    $expiresIn = (int) ($tokenJson['expires_in'] ?? 0);
    if ($expiresIn <= 0) {
        $expiresIn = 3600;
    }
    // 提前 90 秒视为过期，避免临界失效
    $expiresAt = time() + max(60, $expiresIn - 90);
    $row = [
        'access_token' => $access,
        'refresh_token' => $refresh,
        'expires_at' => $expiresAt,
        'token_type' => trim((string) ($tokenJson['token_type'] ?? 'Bearer')) ?: 'Bearer',
        'scope' => trim((string) ($tokenJson['scope'] ?? ($prev['scope'] ?? ''))),
        'obtained_at' => date('Y-m-d H:i:s'),
    ];
    oauth_linuxdo_token_set($row);
    if ($row['scope'] !== '') {
        hot_setting_set('linuxdo_oauth_scope', $row['scope']);
    }
    return $row;
}

/**
 * 当前 access_token 是否仍有效
 */
function oauth_linuxdo_token_valid(?array $token = null)
{
    if ($token === null) {
        $token = oauth_linuxdo_token_get();
    }
    if (($token['access_token'] ?? '') === '') {
        return false;
    }
    $exp = (int) ($token['expires_at'] ?? 0);
    if ($exp > 0 && $exp <= time()) {
        return false;
    }
    return true;
}

/**
 * 刷新 access_token
 * @return array{ok:bool,message:string,token?:array}
 */
function oauth_linuxdo_refresh_token()
{
    $prev = oauth_linuxdo_token_get();
    if (($prev['refresh_token'] ?? '') === '') {
        return ['ok' => false, 'message' => '无 refresh_token，请重新 OAuth 授权'];
    }
    $app = oauth_linuxdo_app_config();
    if ($app['client_id'] === '' || $app['client_secret'] === '') {
        return ['ok' => false, 'message' => '尚未配置 Client ID / Secret'];
    }

    $tokenRes = oauth_http_post(LINUXDO_OAUTH_TOKEN, [
        'client_id' => $app['client_id'],
        'client_secret' => $app['client_secret'],
        'grant_type' => 'refresh_token',
        'refresh_token' => $prev['refresh_token'],
    ]);
    $tokenJson = json_decode((string) ($tokenRes['body'] ?? ''), true);
    if (!is_array($tokenJson) || empty($tokenJson['access_token'])) {
        $hint = !empty($tokenRes['body']) ? mb_substr_safe($tokenRes['body'], 0, 200) : ($tokenRes['error'] ?? ('HTTP ' . ($tokenRes['code'] ?? 0)));
        hot_setting_set('linuxdo_oauth_last_error', 'refresh_failed:' . $hint);
        return ['ok' => false, 'message' => '刷新 token 失败：' . $hint];
    }
    $row = oauth_linuxdo_token_store_from_response($tokenJson, $prev);
    hot_setting_set('linuxdo_oauth_last_error', '');
    return ['ok' => true, 'message' => 'access_token 已刷新', 'token' => $row];
}

/**
 * 确保有可用 access_token（过期则刷新）
 * @return array{ok:bool,access_token:string,message:string}
 */
function oauth_linuxdo_ensure_access_token()
{
    $token = oauth_linuxdo_token_get();
    if (oauth_linuxdo_token_valid($token)) {
        return ['ok' => true, 'access_token' => $token['access_token'], 'message' => 'ok'];
    }
    if (($token['refresh_token'] ?? '') !== '') {
        $ref = oauth_linuxdo_refresh_token();
        if (!empty($ref['ok'])) {
            $t = $ref['token'] ?? oauth_linuxdo_token_get();
            return ['ok' => true, 'access_token' => (string) ($t['access_token'] ?? ''), 'message' => 'refreshed'];
        }
        return ['ok' => false, 'access_token' => '', 'message' => $ref['message'] ?? '刷新失败'];
    }
    if (($token['access_token'] ?? '') !== '') {
        // 无过期时间时仍尝试使用
        if ((int) ($token['expires_at'] ?? 0) === 0) {
            return ['ok' => true, 'access_token' => $token['access_token'], 'message' => 'no_expiry'];
        }
    }
    return ['ok' => false, 'access_token' => '', 'message' => '无可用 access_token，请重新授权'];
}

/**
 * 用 Bearer 拉取 Connect 用户信息
 * @return array{ok:bool,user?:array,message:string}
 */
function oauth_linuxdo_fetch_user($accessToken)
{
    $accessToken = trim((string) $accessToken);
    if ($accessToken === '') {
        return ['ok' => false, 'message' => 'access_token 为空'];
    }
    $userBody = hot_http_get(LINUXDO_OAUTH_USER, 20, [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
    ]);
    if (!$userBody) {
        return ['ok' => false, 'message' => '获取用户信息失败（空响应）'];
    }
    $user = json_decode($userBody, true);
    if (!is_array($user)) {
        return ['ok' => false, 'message' => '用户信息 JSON 无效：' . mb_substr_safe($userBody, 0, 120)];
    }
    // 兼容嵌套 data
    if (isset($user['data']) && is_array($user['data']) && !isset($user['username']) && !isset($user['api_key'])) {
        $user = array_merge($user, $user['data']);
    }
    return ['ok' => true, 'user' => $user, 'message' => 'ok'];
}

/**
 * 从用户对象提取 username / api_key
 * @return array{username:string,api_key:string,user_id:string}
 */
function oauth_linuxdo_parse_user(array $user)
{
    $username = trim((string) ($user['username'] ?? $user['login'] ?? $user['name'] ?? $user['preferred_username'] ?? ''));
    $apiKey = trim((string) ($user['api_key'] ?? $user['apiKey'] ?? ''));
    if ($apiKey === '' && isset($user['data']) && is_array($user['data'])) {
        $apiKey = trim((string) ($user['data']['api_key'] ?? $user['data']['apiKey'] ?? ''));
    }
    // 部分 Connect 返回 user_api_key / keys
    if ($apiKey === '') {
        $apiKey = trim((string) ($user['user_api_key'] ?? $user['discourse_api_key'] ?? ''));
    }
    if ($apiKey === '' && isset($user['keys']) && is_array($user['keys'])) {
        $apiKey = trim((string) ($user['keys']['api_key'] ?? $user['keys'][0] ?? ''));
    }
    $uid = (string) ($user['id'] ?? $user['sub'] ?? $user['user_id'] ?? '');
    return ['username' => $username, 'api_key' => $apiKey, 'user_id' => $uid];
}

/**
 * OAuth 状态面板数据
 * @return array
 */
function oauth_linuxdo_status()
{
    $app = oauth_linuxdo_app_config();
    $token = oauth_linuxdo_token_get();
    $cred = function_exists('hot_linuxdo_credentials') ? hot_linuxdo_credentials() : ['api_key' => '', 'cookie' => '', 'mode' => 'auto', 'api_username' => ''];
    $hasApi = ($cred['api_key'] ?? '') !== '';
    $hasCookie = ($cred['cookie'] ?? '') !== '';
    $hasToken = ($token['access_token'] ?? '') !== '';
    $tokenValid = oauth_linuxdo_token_valid($token);
    $exp = (int) ($token['expires_at'] ?? 0);
    return [
        'app_ready' => oauth_linuxdo_app_ready(),
        'client_id_set' => $app['client_id'] !== '',
        'client_secret_set' => $app['client_secret'] !== '',
        'redirect_uri' => $app['redirect_uri'],
        'has_token' => $hasToken,
        'token_valid' => $tokenValid,
        'has_refresh' => ($token['refresh_token'] ?? '') !== '',
        'expires_at' => $exp,
        'expires_at_text' => $exp > 0 ? date('Y-m-d H:i:s', $exp) : '',
        'token_obtained_at' => $token['obtained_at'] ?? '',
        'scope' => $token['scope'] !== '' ? $token['scope'] : (string) hot_setting_get('linuxdo_oauth_scope', ''),
        'username' => (string) hot_setting_get('linuxdo_oauth_username', ''),
        'user_id' => (string) hot_setting_get('linuxdo_oauth_user_id', ''),
        'bound_at' => (string) hot_setting_get('linuxdo_oauth_bound_at', ''),
        'has_api_key' => $hasApi,
        'has_cookie' => $hasCookie,
        'auth_ready' => $hasApi || $hasCookie || $tokenValid,
        'last_error' => (string) hot_setting_get('linuxdo_oauth_last_error', ''),
        'pkce' => true,
    ];
}

/**
 * 启动授权：PKCE + state 防伪
 * @return array{ok:bool,url?:string,message?:string}
 */
function oauth_linuxdo_begin()
{
    $app = oauth_linuxdo_app_config();
    if ($app['client_id'] === '' || $app['client_secret'] === '') {
        return ['ok' => false, 'message' => '请先配置 Linux.do Connect 的 Client ID 与 Client Secret'];
    }
    $state = bin2hex(random_bytes(16));
    $pkce = oauth_pkce_pair();
    $_SESSION['oauth_linuxdo_state'] = $state;
    $_SESSION['oauth_linuxdo_started'] = time();
    $_SESSION['oauth_linuxdo_pkce_verifier'] = $pkce['verifier'];
    // 记录期望回调，便于排查
    $_SESSION['oauth_linuxdo_redirect'] = $app['redirect_uri'];

    $params = [
        'client_id' => $app['client_id'],
        'response_type' => 'code',
        'redirect_uri' => $app['redirect_uri'],
        'state' => $state,
        'scope' => 'user',
        'code_challenge' => $pkce['challenge'],
        'code_challenge_method' => 'S256',
    ];
    $q = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    return ['ok' => true, 'url' => LINUXDO_OAUTH_AUTHORIZE . '?' . $q];
}

/**
 * 回调处理：换 token（含 PKCE）→ 存令牌 → 拉用户 → 保存 api_key
 * @return array{ok:bool,message:string,username?:string}
 */
function oauth_linuxdo_handle_callback($code, $state)
{
    $expect = (string) ($_SESSION['oauth_linuxdo_state'] ?? '');
    $started = (int) ($_SESSION['oauth_linuxdo_started'] ?? 0);
    $verifier = (string) ($_SESSION['oauth_linuxdo_pkce_verifier'] ?? '');
    unset(
        $_SESSION['oauth_linuxdo_state'],
        $_SESSION['oauth_linuxdo_started'],
        $_SESSION['oauth_linuxdo_pkce_verifier'],
        $_SESSION['oauth_linuxdo_redirect']
    );

    if ($expect === '' || !hash_equals($expect, (string) $state)) {
        hot_setting_set('linuxdo_oauth_last_error', 'state_mismatch');
        return ['ok' => false, 'message' => 'OAuth state 校验失败，请重新授权'];
    }
    if ($started > 0 && (time() - $started) > 900) {
        hot_setting_set('linuxdo_oauth_last_error', 'state_expired');
        return ['ok' => false, 'message' => '授权会话已超时（15 分钟），请重新发起授权'];
    }
    $code = trim((string) $code);
    if ($code === '') {
        return ['ok' => false, 'message' => '授权码为空'];
    }

    $app = oauth_linuxdo_app_config();
    if ($app['client_id'] === '' || $app['client_secret'] === '') {
        return ['ok' => false, 'message' => '尚未配置 Client ID / Secret'];
    }

    $tokenFields = [
        'client_id' => $app['client_id'],
        'client_secret' => $app['client_secret'],
        'code' => $code,
        'redirect_uri' => $app['redirect_uri'],
        'grant_type' => 'authorization_code',
    ];
    if ($verifier !== '') {
        $tokenFields['code_verifier'] = $verifier;
    }

    $tokenRes = oauth_http_post(LINUXDO_OAUTH_TOKEN, $tokenFields);
    // 若带 PKCE 失败且有 verifier，回退一次无 PKCE（兼容未开启 PKCE 的应用）
    if ((!$tokenRes['ok'] || empty(json_decode((string) $tokenRes['body'], true)['access_token'])) && $verifier !== '') {
        $tokenRes2 = oauth_http_post(LINUXDO_OAUTH_TOKEN, [
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'code' => $code,
            'redirect_uri' => $app['redirect_uri'],
            'grant_type' => 'authorization_code',
        ]);
        // 仅当第二次更好时采用
        $j2 = json_decode((string) ($tokenRes2['body'] ?? ''), true);
        if (is_array($j2) && !empty($j2['access_token'])) {
            $tokenRes = $tokenRes2;
        }
    }

    if (!$tokenRes['ok'] && ($tokenRes['body'] ?? '') === '') {
        hot_setting_set('linuxdo_oauth_last_error', 'token_network:' . ($tokenRes['error'] ?? ''));
        return ['ok' => false, 'message' => '换取 access_token 失败：' . ($tokenRes['error'] ?? '网络错误')];
    }
    $tokenJson = json_decode((string) ($tokenRes['body'] ?? ''), true);
    if (!is_array($tokenJson) || empty($tokenJson['access_token'])) {
        $hint = ($tokenRes['body'] ?? '') !== '' ? mb_substr_safe($tokenRes['body'], 0, 200) : ('HTTP ' . ($tokenRes['code'] ?? 0));
        hot_setting_set('linuxdo_oauth_last_error', 'token_invalid:' . $hint);
        return ['ok' => false, 'message' => '换取 access_token 失败：' . $hint];
    }

    $tokenRow = oauth_linuxdo_token_store_from_response($tokenJson);
    $accessToken = $tokenRow['access_token'];

    $userRes = oauth_linuxdo_fetch_user($accessToken);
    if (empty($userRes['ok'])) {
        hot_setting_set('linuxdo_oauth_last_error', 'userinfo:' . ($userRes['message'] ?? ''));
        return ['ok' => false, 'message' => $userRes['message'] ?? '获取用户信息失败'];
    }
    $user = $userRes['user'];
    $parsed = oauth_linuxdo_parse_user($user);
    $username = $parsed['username'];
    $apiKey = $parsed['api_key'];

    // 无 api_key 时仍保留 OAuth token，热榜可用 Bearer 兜底
    hot_setting_set('linuxdo_oauth_username', $username);
    hot_setting_set('linuxdo_oauth_user_id', $parsed['user_id']);
    hot_setting_set('linuxdo_oauth_bound_at', date('Y-m-d H:i:s'));
    hot_setting_set('linuxdo_oauth_has_token', '1');
    hot_setting_set('linuxdo_oauth_last_error', '');

    $saved = true;
    if ($apiKey !== '') {
        $saved = hot_linuxdo_save_credentials([
            'mode' => 'api',
            'api_key' => $apiKey,
            'api_username' => $username !== '' ? $username : 'system',
            'force_api_key' => 1,
            'cookie' => '',
        ]);
        if (!$saved) {
            return ['ok' => false, 'message' => '已拿到 api_key 但保存失败，请检查写权限（OAuth token 已保存）', 'username' => $username];
        }
        $msg = 'Linux.do OAuth 授权成功：已保存 API Key' . ($username !== '' ? '（' . $username . '）' : '');
    } else {
        // 无 api_key：切换 mode 为 auto，依赖 Bearer 令牌
        hot_linuxdo_save_credentials([
            'mode' => 'auto',
            'api_username' => $username !== '' ? $username : 'system',
            'cookie' => '',
        ]);
        $msg = 'Linux.do OAuth 授权成功：已保存 access_token'
            . ($username !== '' ? '（用户 ' . $username . '）' : '')
            . '；用户信息未含 api_key，抓取将优先使用 Bearer 令牌';
    }

    $f = ROOT_PATH . '/data/cache/linuxdo.json';
    if (is_file($f)) {
        @unlink($f);
    }

    return [
        'ok' => true,
        'message' => $msg,
        'username' => $username,
        'has_api_key' => $apiKey !== '',
        'has_token' => true,
    ];
}

/**
 * 重新拉取用户并同步 api_key（需有效 token）
 * @return array{ok:bool,message:string,username?:string}
 */
function oauth_linuxdo_sync_profile()
{
    $ens = oauth_linuxdo_ensure_access_token();
    if (empty($ens['ok'])) {
        return ['ok' => false, 'message' => $ens['message'] ?? '无可用令牌'];
    }
    $userRes = oauth_linuxdo_fetch_user($ens['access_token']);
    if (empty($userRes['ok'])) {
        return ['ok' => false, 'message' => $userRes['message'] ?? '拉取用户失败'];
    }
    $parsed = oauth_linuxdo_parse_user($userRes['user']);
    if ($parsed['username'] !== '') {
        hot_setting_set('linuxdo_oauth_username', $parsed['username']);
    }
    if ($parsed['user_id'] !== '') {
        hot_setting_set('linuxdo_oauth_user_id', $parsed['user_id']);
    }
    hot_setting_set('linuxdo_oauth_bound_at', date('Y-m-d H:i:s'));
    if ($parsed['api_key'] !== '') {
        hot_linuxdo_save_credentials([
            'mode' => 'api',
            'api_key' => $parsed['api_key'],
            'api_username' => $parsed['username'] !== '' ? $parsed['username'] : 'system',
            'force_api_key' => 1,
        ]);
        return ['ok' => true, 'message' => '已同步用户资料与 API Key', 'username' => $parsed['username']];
    }
    return [
        'ok' => true,
        'message' => '已同步用户资料（仍无 api_key，继续使用 Bearer）',
        'username' => $parsed['username'],
    ];
}

/**
 * 撤销/解绑 OAuth（清 token + 可选清 api）
 * @param bool $clearApiKey
 * @return array{ok:bool,message:string}
 */
function oauth_linuxdo_revoke($clearApiKey = true)
{
    oauth_linuxdo_token_clear();
    if ($clearApiKey) {
        hot_linuxdo_save_credentials(['clear' => 1]);
    }
    $f = ROOT_PATH . '/data/cache/linuxdo.json';
    if (is_file($f)) {
        @unlink($f);
    }
    return ['ok' => true, 'message' => '已解除 Linux.do OAuth 绑定' . ($clearApiKey ? '并清除 API 凭证' : '')];
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
    $cookie = (string) ($data['cookie'] ?? '');
    if (function_exists('hot_normalize_cookie_header')) {
        $cookie = hot_normalize_cookie_header($cookie);
    } else {
        $cookie = trim(str_replace(["\r", "\n", "\0"], '', $cookie));
        if (strncmp($cookie, "\xEF\xBB\xBF", 3) === 0) {
            $cookie = substr($cookie, 3);
        }
    }
    $out = [
        'cookie' => $cookie,
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

    if (function_exists('secret_blob_get')) {
        $blob = secret_blob_get('52pojie_auth');
        if (is_array($blob) && $blob !== []) {
            $candidates[] = hot_52pojie_normalize_cred($blob);
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

    // 旧文件只读迁移
    foreach ([hot_52pojie_secret_file(), hot_52pojie_secret_backup_file()] as $f) {
        $c = hot_52pojie_read_file($f);
        if ($c) {
            $candidates[] = $c;
        }
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

    if ($best['cookie'] !== '' && function_exists('secret_blob_get')) {
        $cur = secret_blob_get('52pojie_auth');
        $curCookie = is_array($cur) ? (string) ($cur['cookie'] ?? '') : '';
        if ($curCookie === '') {
            hot_52pojie_write_db($best);
        }
    }

    return $best;
}

/** @deprecated 密钥不再落盘，委托 DB */
function hot_52pojie_write_files(array $payload)
{
    return hot_52pojie_write_db($payload);
}

function hot_52pojie_write_db(array $payload)
{
    $payload = hot_52pojie_normalize_cred($payload);
    $ok = true;
    if (function_exists('secret_blob_set')) {
        $ok = secret_blob_set('52pojie_auth', $payload) && $ok;
    }
    // 非 Cookie 元数据；Cookie 仅 secret 块（避免 settings 表明文大 Cookie）
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

    $okDb = hot_52pojie_write_db($payload);
    $f = HOT_CACHE_DIR . '/52pojie.json';
    if (is_file($f)) {
        @unlink($f);
    }
    return $okDb;
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
    if (function_exists('hot_normalize_cookie_header')) {
        $cookie = hot_normalize_cookie_header($cookie);
    } else {
        $cookie = trim(str_replace(["\r", "\n", "\0"], '', $cookie));
    }
    if ($cookie === '') {
        return ['ok' => false, 'message' => '请提供登录后的 Cookie'];
    }
    // Discuz 登录态关键字段
    $hasAuth = preg_match('/htVC_\d+_auth=/i', $cookie) === 1 || stripos($cookie, '_auth=') !== false;
    $hasSalt = preg_match('/htVC_\d+_saltkey=/i', $cookie) === 1 || stripos($cookie, 'saltkey=') !== false;
    if (!$hasAuth || !$hasSalt) {
        return [
            'ok' => false,
            'message' => 'Cookie 缺少关键字段（htVC_*_auth / htVC_*_saltkey），请在已登录状态下从 F12 → Network 复制完整 Cookie',
        ];
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
 * 吾爱 HTML 是否明显未登录
 */
function hot_52pojie_html_need_login($html)
{
    $html = (string) $html;
    if ($html === '') {
        return false;
    }
    if (preg_match('/需要先登录|请先登录|您尚未登录|请登录后|登录后才能/u', $html)) {
        return true;
    }
    // 登录表单明显且无退出入口
    $hasLoginForm = (bool) preg_match('/member\.php\?mod=logging&amp;action=login|name="username".*name="password"/is', $html);
    $hasLogout = (bool) preg_match('/action=logout|logging\.php\?action=logout|退出/u', $html);
    return $hasLoginForm && !$hasLogout;
}

/**
 * 吾爱 HTML 是否含登录成功信号
 */
function hot_52pojie_html_logged_in($html)
{
    $html = (string) $html;
    if ($html === '') {
        return false;
    }
    if (preg_match('/action=logout|logging\.php\?action=logout|mod=logging&amp;action=logout/i', $html)) {
        return true;
    }
    if (preg_match('/discuz_uid\s*=\s*[\'"]?[1-9]\d*/i', $html)) {
        return true;
    }
    if (preg_match('/space-uid-([1-9]\d*)\.html/i', $html)) {
        return true;
    }
    if (preg_match('/欢迎您|我的消息|我的帖子|个人资料|积分|用户组/u', $html)
        && !hot_52pojie_html_need_login($html)) {
        return true;
    }
    return false;
}

/**
 * 热榜/列表页是否有帖子内容
 */
function hot_52pojie_html_has_threads($html)
{
    $html = (string) $html;
    if ($html === '') {
        return false;
    }
    if (preg_match('/thread-\d+-/i', $html)) {
        return true;
    }
    if (preg_match('/forum\.php\?mod=viewthread[^"\'>\s]*tid=\d+/i', $html)) {
        return true;
    }
    if (preg_match('/mod=viewthread&(?:amp;)?tid=\d+/i', $html)) {
        return true;
    }
    // 排行榜容器
    if (preg_match('/ranklist|view=heats|今日热门|热门主题/iu', $html)
        && preg_match('/tid=\d+|thread-/i', $html)) {
        return true;
    }
    return false;
}

/**
 * 从吾爱 HTML 提取用户名
 */
function hot_52pojie_extract_username($html)
{
    $html = (string) $html;
    if ($html === '') {
        return '';
    }
    $patterns = [
        '/title="访问我的空间">\s*([^<]+?)\s*</u',
        '/欢迎您[，,\s]*<a[^>]*>\s*([^<]+?)\s*</u',
        '/欢迎您[，,\s]*([^\s<>]{2,30})/u',
        '/id="um"[^>]*>[\s\S]{0,800}?<strong>\s*([^<]+?)\s*<\/strong>/u',
        '/id="um"[^>]*>[\s\S]{0,800}?<a[^>]+href="[^"]*space-uid-\d+\.html"[^>]*>\s*([^<]{2,30})\s*</u',
        '/cite[^>]*>\s*([^<]{2,30})\s*<\/cite>/u',
        '/space-uid-\d+\.html[^>]*>\s*([^<]{2,30})\s*</u',
        '/name="username"\s+value="([^"]{2,30})"/u',
        '/_auth=[^;]+;[\s\S]{0,200}?>([a-zA-Z0-9_\x{4e00}-\x{9fff}]{2,20})</u',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $html, $m)) {
            $username = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
            $username = preg_replace('/\s+/u', ' ', $username) ?? $username;
            $username = trim($username, " \t\n\r\0\x0B\"'");
            $bad = ['登录', '注册', '首页', '退出', '设置', '消息', '帖子', '积分'];
            if ($username === '' || in_array($username, $bad, true)) {
                continue;
            }
            $len = function_exists('mb_strlen') ? mb_strlen($username, 'UTF-8') : strlen($username);
            if ($len >= 2 && $len < 40) {
                return $username;
            }
        }
    }
    return '';
}

/**
 * 测试吾爱 Cookie
 * @return array{ok:bool,message:string,username?:string}
 */
function hot_52pojie_test_auth()
{
    $cred = hot_52pojie_credentials();
    $cookie = function_exists('hot_normalize_cookie_header')
        ? hot_normalize_cookie_header($cred['cookie'] ?? '')
        : trim((string) ($cred['cookie'] ?? ''));
    if ($cookie === '') {
        return ['ok' => false, 'message' => '尚未配置吾爱 Cookie'];
    }

    $hasAuth = preg_match('/htVC_\d+_auth=/i', $cookie) === 1 || preg_match('/(?:^|;\s*)[^;]*_auth=/i', $cookie) === 1;
    $hasSalt = preg_match('/htVC_\d+_saltkey=/i', $cookie) === 1 || stripos($cookie, 'saltkey=') !== false;
    if (!$hasAuth || !$hasSalt) {
        return [
            'ok' => false,
            'message' => 'Cookie 不完整：请从浏览器复制完整 Cookie，需包含 htVC_*_auth 与 htVC_*_saltkey',
        ];
    }

    // Cookie 内 uid 线索（connect_uin / lastcheckfeed=UID|...）
    $cookieUid = '';
    if (preg_match('/htVC_\d+_lastcheckfeed=(\d+)/i', $cookie, $m)) {
        $cookieUid = $m[1];
    } elseif (preg_match('/htVC_\d+_noticonf=(\d+)/i', $cookie, $m)) {
        $cookieUid = $m[1];
    }

    $hdr = [
        'Cookie: ' . $cookie,
        'Referer: https://www.52pojie.cn/',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
    ];

    $urls = [
        'rank' => 'https://www.52pojie.cn/misc.php?mod=ranklist&type=thread&view=heats&orderby=today',
        'home' => 'https://www.52pojie.cn/',
        'space' => 'https://www.52pojie.cn/home.php?mod=space',
        'forum' => 'https://www.52pojie.cn/forum.php',
    ];

    $rank = hot_http_get($urls['rank'], 20, $hdr);
    $home = null;
    if (!$rank) {
        $home = hot_http_get($urls['home'], 15, $hdr);
        if (!$home) {
            return [
                'ok' => false,
                'message' => '无法访问吾爱（服务器需启用 curl/openssl，或站点拦截/网络不通）',
            ];
        }
        // 首页可通时再试论坛入口
        $rank = hot_http_get($urls['forum'], 15, $hdr) ?: $home;
    }

    $rankUtf = function_exists('hot_to_utf8') ? hot_to_utf8($rank, 'GBK') : $rank;
    $rankOk = hot_52pojie_html_has_threads($rankUtf);
    $rankLogged = hot_52pojie_html_logged_in($rankUtf);
    $rankNeedLogin = hot_52pojie_html_need_login($rankUtf);

    $body = hot_http_get($urls['space'], 20, $hdr);
    $username = '';
    $spaceLogged = false;
    $spaceNeedLogin = false;
    if ($body) {
        $body = function_exists('hot_to_utf8') ? hot_to_utf8($body, 'GBK') : $body;
        $username = hot_52pojie_extract_username($body);
        $spaceLogged = hot_52pojie_html_logged_in($body);
        $spaceNeedLogin = hot_52pojie_html_need_login($body);
        if (!$rankOk) {
            $rankOk = hot_52pojie_html_has_threads($body);
        }
    }

    if (($spaceNeedLogin || $rankNeedLogin) && $username === '' && !$rankLogged && !$spaceLogged && !$rankOk) {
        return ['ok' => false, 'message' => 'Cookie 可能无效或已过期，请重新登录吾爱后复制完整 Cookie'];
    }

    if ($username !== '' || $rankOk || $rankLogged || $spaceLogged) {
        $parts = [];
        if ($username !== '') {
            $parts[] = '登录有效：' . $username;
        } elseif ($cookieUid !== '') {
            $parts[] = '登录有效（uid ' . $cookieUid . '）';
        } elseif ($rankLogged || $spaceLogged) {
            $parts[] = '登录态有效';
        } else {
            $parts[] = 'Cookie 结构有效';
        }
        if ($rankOk) {
            $parts[] = '热榜/帖子列表可访问';
        } else {
            $parts[] = '页面可达，抓取时将带 Cookie';
        }
        return [
            'ok' => true,
            'message' => implode('，', $parts),
            'username' => $username,
        ];
    }

    // 页面有响应且 Cookie 字段完整：允许保存，避免因模板改版误报失败
    if (is_string($rank) && strlen($rank) > 200 && !$rankNeedLogin && !$spaceNeedLogin) {
        return [
            'ok' => true,
            'message' => 'Cookie 已接受且站点可访问（页面结构变化，未能解析用户名；抓取仍会带登录态）'
                . ($cookieUid !== '' ? '，uid ' . $cookieUid : ''),
            'username' => $username,
        ];
    }

    return [
        'ok' => false,
        'message' => '未能确认登录态：请重新登录 www.52pojie.cn 后，从 F12 → Network → 任意请求复制完整 Cookie 重试',
    ];
}

function mb_substr_safe($str, $start, $len)
{
    if (function_exists('mb_substr')) {
        return mb_substr((string) $str, $start, $len, 'UTF-8');
    }
    return substr((string) $str, $start, $len);
}
