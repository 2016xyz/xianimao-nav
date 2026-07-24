<?php
/**
 * 真实热榜拉取与缓存
 * 支持：ikunpay 聚合 / Discourse(linux.do) / V2EX API / Discuz 页面解析(吾爱)
 * 展示平台由后台配置（settings.hot_boards_enabled）
 */

if (!defined('ROOT_PATH')) {
    require_once __DIR__ . '/bootstrap.php';
}

if (!defined('HOT_CACHE_DIR')) {
    define('HOT_CACHE_DIR', ROOT_PATH . '/data/cache');
}
if (!defined('HOT_CACHE_TTL')) {
    define('HOT_CACHE_TTL', 600);
}

/**
 * 全部可选热榜目录（id 固定）
 * provider: ikunpay | discourse | v2ex | discuz
 */
function hot_board_catalog()
{
    return [
        'weibo' => [
            'id' => 'weibo',
            'name' => '微博热搜榜',
            'short' => '微博',
            'label' => '热搜榜',
            'logo' => 'assets/images/weibo.png',
            'provider' => 'ikunpay',
            'type' => 'weibo',
            'limit' => 50,
            'fallback_url' => 'https://s.weibo.com/top/summary',
            'default_enabled' => true,
        ],
        '52pojie' => [
            'id' => '52pojie',
            'name' => '吾爱破解今日热帖',
            'short' => '吾爱破解',
            'label' => '今日热帖',
            'logo' => 'assets/images/52pojie.png',
            'provider' => 'discuz',
            'fetch_url' => 'https://www.52pojie.cn/misc.php?mod=ranklist&type=thread&view=heats&orderby=today',
            'base_url' => 'https://www.52pojie.cn/',
            'charset' => 'GBK',
            'limit' => 30,
            'fallback_url' => 'https://www.52pojie.cn/misc.php?mod=ranklist&type=thread&view=heats&orderby=today',
            'default_enabled' => true,
        ],
        'bilibili' => [
            'id' => 'bilibili',
            'name' => '哔哩哔哩全站日榜',
            'short' => '哔哩哔哩',
            'label' => '全站日榜',
            'logo' => 'assets/images/bilibili.png',
            'provider' => 'ikunpay',
            'type' => 'biliall',
            'limit' => 50,
            'fallback_url' => 'https://www.bilibili.com/v/popular/rank/all',
            'default_enabled' => true,
        ],
        'baidu' => [
            'id' => 'baidu',
            'name' => '百度热点',
            'short' => '百度',
            'label' => '热点',
            'logo' => 'assets/images/baidu.png',
            'provider' => 'ikunpay',
            'type' => 'baidu',
            'limit' => 30,
            'fallback_url' => 'https://top.baidu.com/board?tab=realtime',
            'default_enabled' => true,
        ],
        'linuxdo' => [
            'id' => 'linuxdo',
            'name' => 'Linux.do 热门',
            'short' => 'Linux.do',
            'label' => '最新/热门',
            'logo' => '',
            'provider' => 'discourse',
            'endpoints' => [
                'https://linux.do/latest.json',
                'https://linux.do/top.json',
                'https://linux.do/hot.json',
            ],
            'site' => 'https://linux.do',
            'limit' => 30,
            'fallback_url' => 'https://linux.do/',
            'default_enabled' => true,
        ],
        'v2ex' => [
            'id' => 'v2ex',
            'name' => 'V2EX 今日热议',
            'short' => 'V2EX',
            'label' => '热议',
            'logo' => '',
            'provider' => 'v2ex',
            'fetch_url' => 'https://www.v2ex.com/api/topics/hot.json',
            'limit' => 20,
            'fallback_url' => 'https://www.v2ex.com/?tab=hot',
            'default_enabled' => false,
        ],
        'douyin' => [
            'id' => 'douyin',
            'name' => '抖音热搜',
            'short' => '抖音',
            'label' => '热搜',
            'logo' => 'assets/images/douyin.png',
            'provider' => 'ikunpay',
            'type' => 'douyin',
            'limit' => 30,
            'fallback_url' => 'https://www.douyin.com/',
            'default_enabled' => false,
        ],
        'tieba' => [
            'id' => 'tieba',
            'name' => '百度贴吧热议',
            'short' => '贴吧',
            'label' => '热议',
            'logo' => 'assets/images/tieba.png',
            'provider' => 'ikunpay',
            'type' => 'tieba',
            'limit' => 20,
            'fallback_url' => 'https://tieba.baidu.com/hottopic/browse/topicList',
            'default_enabled' => false,
        ],
        'sspai' => [
            'id' => 'sspai',
            'name' => '少数派热榜',
            'short' => '少数派',
            'label' => '热榜',
            'logo' => '',
            'provider' => 'ikunpay',
            'type' => 'sspai',
            'limit' => 30,
            'fallback_url' => 'https://sspai.com/',
            'default_enabled' => false,
        ],
        'zhihu' => [
            'id' => 'zhihu',
            'name' => '知乎热榜',
            'short' => '知乎',
            'label' => '热榜',
            'logo' => 'assets/images/zhihu.png',
            'provider' => 'ikunpay',
            'type' => 'zhihu',
            'limit' => 30,
            'fallback_url' => 'https://www.zhihu.com/hot',
            'default_enabled' => false,
        ],
    ];
}

/**
 * 默认启用顺序
 */
function hot_board_default_enabled_ids()
{
    $ids = [];
    foreach (hot_board_catalog() as $id => $meta) {
        if (!empty($meta['default_enabled'])) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/**
 * 读取后台配置的启用列表（有序）
 * @return string[]
 */
function hot_board_enabled_ids()
{
    $raw = hot_setting_get('hot_boards_enabled', '');
    if ($raw !== '' && $raw !== null) {
        $list = null;
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $list = $decoded;
        } elseif (is_string($raw) && strpos($raw, ',') !== false) {
            // 兼容旧版逗号分隔种子
            $list = array_map('trim', explode(',', $raw));
        } elseif (is_string($raw) && $raw !== '') {
            $list = [trim($raw)];
        }
        if (is_array($list)) {
            $catalog = hot_board_catalog();
            $ids = [];
            foreach ($list as $id) {
                $id = (string) $id;
                if (isset($catalog[$id]) && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
            if (!empty($ids)) {
                return $ids;
            }
        }
    }
    return hot_board_default_enabled_ids();
}

/**
 * 保存启用列表
 */
function hot_board_save_enabled_ids(array $ids)
{
    $catalog = hot_board_catalog();
    $clean = [];
    foreach ($ids as $id) {
        $id = (string) $id;
        if (isset($catalog[$id]) && !in_array($id, $clean, true)) {
            $clean[] = $id;
        }
    }
    return hot_setting_set('hot_boards_enabled', json_encode($clean, JSON_UNESCAPED_UNICODE));
}

/**
 * 当前启用的 source 配置列表
 */
function hot_board_sources()
{
    $catalog = hot_board_catalog();
    $sources = [];
    foreach (hot_board_enabled_ids() as $id) {
        if (isset($catalog[$id])) {
            $sources[] = $catalog[$id];
        }
    }
    // 至少保留一个，避免前台空白
    if (empty($sources)) {
        foreach (hot_board_default_enabled_ids() as $id) {
            if (isset($catalog[$id])) {
                $sources[] = $catalog[$id];
            }
        }
    }
    return $sources;
}

/**
 * settings 读写：DB 主存储；旧 hot_config.json 仅只读回退（非密钥）
 */
function hot_setting_get($key, $default = '')
{
    if (function_exists('setting_get')) {
        // setting_get 命中 DB 会返回值；未命中返回 default。
        // 用独特哨兵区分「未设置」与「空字符串」。
        $sentinel = "\0__hot_missing__\0";
        $v = setting_get($key, $sentinel);
        if ($v !== $sentinel) {
            return $v;
        }
    } else {
        try {
            $stmt = db()->prepare('SELECT svalue FROM settings WHERE skey = ? LIMIT 1');
            $stmt->execute([(string) $key]);
            $row = $stmt->fetch();
            if ($row && array_key_exists('svalue', $row)) {
                return $row['svalue'];
            }
        } catch (Throwable $e) {
            // fallthrough
        }
    }

    $file = ROOT_PATH . '/config/hot_config.json';
    if (is_file($file)) {
        $json = json_decode((string) file_get_contents($file), true);
        if (is_array($json) && array_key_exists($key, $json)) {
            return $json[$key];
        }
    }
    return $default;
}

function hot_setting_set($key, $value)
{
    if (function_exists('setting_set')) {
        return setting_set($key, $value);
    }
    try {
        $stmt = db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
        return $stmt->execute([(string) $key, (string) $value]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Linux.do 凭证文件（主存储，服务端持久化）
 */
function hot_linuxdo_secret_file()
{
    return ROOT_PATH . '/config/linuxdo_auth.json';
}

/**
 * 数据目录备份文件（防止 config 被误删）
 */
function hot_linuxdo_secret_backup_file()
{
    return ROOT_PATH . '/data/linuxdo_auth.json';
}

/**
 * 规范化凭证结构
 * @return array{cookie:string,api_key:string,api_username:string,mode:string,updated_at:string}
 */
function hot_linuxdo_normalize_cred(array $data)
{
    $out = [
        'cookie' => trim((string) ($data['cookie'] ?? '')),
        'api_key' => trim((string) ($data['api_key'] ?? '')),
        'api_username' => trim((string) ($data['api_username'] ?? '')),
        'mode' => trim((string) ($data['mode'] ?? 'auto')),
        'updated_at' => trim((string) ($data['updated_at'] ?? '')),
    ];
    if ($out['api_username'] === '') {
        $out['api_username'] = 'system';
    }
    if (!in_array($out['mode'], ['auto', 'cookie', 'api', 'none'], true)) {
        $out['mode'] = 'auto';
    }
    return $out;
}

/**
 * 从单个 JSON 文件读凭证
 */
function hot_linuxdo_read_file($path)
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $json = json_decode((string) file_get_contents($path), true);
    if (!is_array($json)) {
        return null;
    }
    return hot_linuxdo_normalize_cred($json);
}

/**
 * 从数据库 settings 读凭证（持久化第二副本）
 */
function hot_linuxdo_read_db()
{
    $cookie = (string) hot_setting_get('linuxdo_cookie', '');
    $apiKey = (string) hot_setting_get('linuxdo_api_key', '');
    $apiUser = (string) hot_setting_get('linuxdo_api_username', '');
    $mode = (string) hot_setting_get('linuxdo_auth_mode', '');
    $updated = (string) hot_setting_get('linuxdo_auth_updated_at', '');
    // 无任何密钥字段则视为无 DB 副本
    if ($cookie === '' && $apiKey === '' && $mode === '') {
        return null;
    }
    return hot_linuxdo_normalize_cred([
        'cookie' => $cookie,
        'api_key' => $apiKey,
        'api_username' => $apiUser,
        'mode' => $mode !== '' ? $mode : 'auto',
        'updated_at' => $updated,
    ]);
}

/**
 * 合并多源凭证：优先「有密钥」的，再比 updated_at
 * @return array{cookie:string,api_key:string,api_username:string,mode:string,updated_at:string}
 */
function hot_linuxdo_pick_best(array $candidates)
{
    $best = null;
    $bestScore = -1;
    foreach ($candidates as $c) {
        if (!is_array($c)) {
            continue;
        }
        $c = hot_linuxdo_normalize_cred($c);
        $hasSecret = ($c['cookie'] !== '' || $c['api_key'] !== '') ? 1 : 0;
        $ts = $c['updated_at'] !== '' ? (int) strtotime($c['updated_at']) : 0;
        $score = $hasSecret * 10000000000 + $ts;
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $c;
        }
    }
    return $best ?: hot_linuxdo_normalize_cred([]);
}

/**
 * Linux.do / Discourse 登录凭证（后台配置，仅服务端使用）
 * 主存储：数据库 settings / secret_linuxdo_auth；旧 JSON 仅迁移读取
 * @return array{cookie:string,api_key:string,api_username:string,mode:string,updated_at:string}
 */
function hot_linuxdo_credentials()
{
    $candidates = [];

    // DB secret 块
    if (function_exists('secret_blob_get')) {
        $blob = secret_blob_get('linuxdo_auth');
        if (is_array($blob) && $blob !== []) {
            $candidates[] = hot_linuxdo_normalize_cred($blob);
        }
    }

    $db = hot_linuxdo_read_db();
    if ($db) {
        $candidates[] = $db;
    }

    // 旧文件只读迁移（不回写磁盘）
    foreach ([hot_linuxdo_secret_file(), hot_linuxdo_secret_backup_file()] as $f) {
        $c = hot_linuxdo_read_file($f);
        if ($c) {
            $candidates[] = $c;
        }
    }

    $cred = hot_linuxdo_pick_best($candidates);

    // 若 DB 无完整密钥但其它源有，写回数据库
    if (($cred['cookie'] !== '' || $cred['api_key'] !== '')) {
        $dbEmpty = !$db || ($db['cookie'] === '' && $db['api_key'] === '');
        $blobEmpty = true;
        if (function_exists('secret_blob_get')) {
            $b = secret_blob_get('linuxdo_auth');
            $blobEmpty = !is_array($b) || (($b['cookie'] ?? '') === '' && ($b['api_key'] ?? '') === '');
        }
        if ($dbEmpty || $blobEmpty) {
            hot_linuxdo_write_db($cred);
        }
    }

    return $cred;
}

/**
 * @deprecated 密钥不再落盘；保留空实现兼容旧调用
 */
function hot_linuxdo_write_files(array $payload)
{
    return hot_linuxdo_write_db($payload);
}

/**
 * 写入数据库（主存储）
 */
function hot_linuxdo_write_db(array $payload)
{
    $payload = hot_linuxdo_normalize_cred($payload);
    $ok = true;
    if (function_exists('secret_blob_set')) {
        $ok = secret_blob_set('linuxdo_auth', $payload) && $ok;
    }
    // 仅写非敏感元数据；cookie/api_key 只在 secret 块
    $ok = hot_setting_set('linuxdo_api_username', $payload['api_username']) && $ok;
    $ok = hot_setting_set('linuxdo_auth_mode', $payload['mode']) && $ok;
    $ok = hot_setting_set('linuxdo_auth_updated_at', $payload['updated_at']) && $ok;
    $ok = hot_setting_set('linuxdo_has_cookie', $payload['cookie'] !== '' ? '1' : '0') && $ok;
    $ok = hot_setting_set('linuxdo_has_api', $payload['api_key'] !== '' ? '1' : '0') && $ok;
    return $ok;
}

/**
 * 确保已加载 OAuth 模块（避免与 oauth_providers 循环依赖时重复 require）
 */
function hot_linuxdo_load_oauth()
{
    if (function_exists('oauth_linuxdo_ensure_access_token')) {
        return true;
    }
    $f = __DIR__ . '/oauth_providers.php';
    if (is_file($f)) {
        require_once $f;
    }
    return function_exists('oauth_linuxdo_ensure_access_token');
}

/**
 * 是否存在可用的 OAuth Bearer（有效 access_token 或可刷新）
 */
function hot_linuxdo_oauth_bearer_ready()
{
    if (!hot_linuxdo_load_oauth()) {
        return false;
    }
    if (!function_exists('oauth_linuxdo_token_get') || !function_exists('oauth_linuxdo_token_valid')) {
        return false;
    }
    $token = oauth_linuxdo_token_get();
    if (oauth_linuxdo_token_valid($token)) {
        return true;
    }
    if (($token['refresh_token'] ?? '') !== '') {
        return true;
    }
    // 无过期时间的 access_token 仍可尝试
    return ($token['access_token'] ?? '') !== '' && (int) ($token['expires_at'] ?? 0) === 0;
}

/**
 * 是否已配置 Linux.do 登录凭证（Cookie / API Key / OAuth Bearer）
 */
function hot_linuxdo_has_auth()
{
    $c = hot_linuxdo_credentials();
    if ($c['mode'] === 'none') {
        return false;
    }
    if ($c['mode'] === 'cookie') {
        return $c['cookie'] !== '';
    }
    if ($c['mode'] === 'api') {
        return $c['api_key'] !== '' || hot_linuxdo_oauth_bearer_ready();
    }
    return $c['cookie'] !== '' || $c['api_key'] !== '' || hot_linuxdo_oauth_bearer_ready();
}

/**
 * 保存 Linux.do 凭证（持久化到文件 + 数据库）
 *
 * 规则：
 * - 提交的 Cookie / API Key 非空 → 覆盖保存
 * - 提交为空 → 保留原值（避免表单空框误清空）
 * - clear=1 → 清空全部
 */
function hot_linuxdo_save_credentials(array $input)
{
    $prev = hot_linuxdo_credentials();
    $mode = trim((string) ($input['mode'] ?? $prev['mode'] ?? 'auto'));
    if (!in_array($mode, ['auto', 'cookie', 'api', 'none'], true)) {
        $mode = 'auto';
    }

    $clear = !empty($input['clear']);
    if ($clear) {
        $cookie = '';
        $apiKey = '';
        $apiUser = '';
        $mode = 'none';
    } else {
        $newCookie = trim((string) ($input['cookie'] ?? ''));
        $newKey = trim((string) ($input['api_key'] ?? ''));
        // 显式提交标志 或 有新内容 → 更新；空且未强制 → 保留
        if (!empty($input['cookie_submitted']) || !empty($input['force_cookie'])) {
            $cookie = $newCookie;
        } elseif ($newCookie !== '') {
            $cookie = $newCookie;
        } else {
            $cookie = $prev['cookie'];
        }
        if (!empty($input['api_key_submitted']) || !empty($input['force_api_key'])) {
            $apiKey = $newKey;
        } elseif ($newKey !== '') {
            $apiKey = $newKey;
        } else {
            $apiKey = $prev['api_key'];
        }
        $apiUser = trim((string) ($input['api_username'] ?? ''));
        if ($apiUser === '') {
            $apiUser = $prev['api_username'] !== '' ? $prev['api_username'] : 'system';
        }
        // 有密钥时自动从 none 切回 auto
        if ($mode === 'none' && ($cookie !== '' || $apiKey !== '')) {
            $mode = 'auto';
        }
    }

    $payload = hot_linuxdo_normalize_cred([
        'cookie' => $cookie,
        'api_key' => $apiKey,
        'api_username' => $apiUser,
        'mode' => $mode,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $okDb = hot_linuxdo_write_db($payload);

    // 清缓存，使登录态立刻参与抓取
    $f = HOT_CACHE_DIR . '/linuxdo.json';
    if (is_file($f)) {
        @unlink($f);
    }
    return $okDb;
}

/**
 * 为 Discourse 请求附加鉴权头
 * 优先级（auto）：Cookie → Api-Key → OAuth Bearer（过期自动 refresh）
 */
function hot_discourse_auth_headers(array $baseHeaders = [], $forceMode = null)
{
    $cred = hot_linuxdo_credentials();
    $mode = $forceMode ?: $cred['mode'];
    $headers = $baseHeaders;

    $useCookie = false;
    $useApi = false;
    $useBearer = false;
    if ($mode === 'cookie') {
        $useCookie = $cred['cookie'] !== '';
    } elseif ($mode === 'api') {
        $useApi = $cred['api_key'] !== '';
        // 无 API Key 时用 OAuth Bearer 兜底
        if (!$useApi && hot_linuxdo_oauth_bearer_ready()) {
            $useBearer = true;
        }
    } elseif ($mode === 'auto') {
        // 优先 Cookie（更接近浏览器登录后可见内容），其次 API Key，再 OAuth Bearer
        if ($cred['cookie'] !== '') {
            $useCookie = true;
        } elseif ($cred['api_key'] !== '') {
            $useApi = true;
        } elseif (hot_linuxdo_oauth_bearer_ready()) {
            $useBearer = true;
        }
    }

    if ($useCookie) {
        $headers[] = 'Cookie: ' . $cred['cookie'];
    }
    if ($useApi) {
        $headers[] = 'Api-Key: ' . $cred['api_key'];
        $headers[] = 'Api-Username: ' . ($cred['api_username'] !== '' ? $cred['api_username'] : 'system');
    }
    if ($useBearer && hot_linuxdo_load_oauth()) {
        $ens = oauth_linuxdo_ensure_access_token();
        if (!empty($ens['ok']) && ($ens['access_token'] ?? '') !== '') {
            $headers[] = 'Authorization: Bearer ' . $ens['access_token'];
            // Discourse 部分接口同时识别 Api-Username
            $uname = trim((string) ($cred['api_username'] ?? ''));
            if ($uname === '' || $uname === 'system') {
                $uname = (string) hot_setting_get('linuxdo_oauth_username', '');
            }
            if ($uname !== '') {
                $headers[] = 'Api-Username: ' . $uname;
            }
        }
    }
    return $headers;
}

/**
 * 测试 Linux.do 登录态：请求 session 或 latest.json
 * @return array{ok:bool,message:string,username?:string,topics?:int,via?:string}
 */
function hot_linuxdo_test_auth()
{
    $site = 'https://linux.do';
    $cred = hot_linuxdo_credentials();
    $hasBearer = hot_linuxdo_oauth_bearer_ready();
    if ($cred['cookie'] === '' && $cred['api_key'] === '' && !$hasBearer) {
        return ['ok' => false, 'message' => '尚未配置 Cookie、API Key 或 OAuth 令牌，请先完成授权'];
    }

    // 预刷新 OAuth 令牌，确保 headers 带上有效 Bearer
    $authVia = '';
    if ($cred['cookie'] !== '' && ($cred['mode'] === 'auto' || $cred['mode'] === 'cookie')) {
        $authVia = 'cookie';
    } elseif ($cred['api_key'] !== '' && ($cred['mode'] === 'auto' || $cred['mode'] === 'api')) {
        $authVia = 'api_key';
    } elseif ($hasBearer) {
        $authVia = 'oauth_bearer';
        if (hot_linuxdo_load_oauth()) {
            $ens = oauth_linuxdo_ensure_access_token();
            if (empty($ens['ok'])) {
                return ['ok' => false, 'message' => 'OAuth 令牌不可用：' . ($ens['message'] ?? '请重新授权')];
            }
        }
    }

    $headers = hot_discourse_auth_headers([
        'Accept: application/json',
        'X-Requested-With: XMLHttpRequest',
        'Referer: ' . $site . '/',
    ]);

    // 1) 会话探测
    $sessionBody = hot_http_get($site . '/session/current.json', 15, $headers);
    $username = '';
    if ($sessionBody && stripos($sessionBody, 'Just a moment') === false) {
        $sj = json_decode($sessionBody, true);
        if (is_array($sj)) {
            $username = (string) ($sj['current_user']['username'] ?? $sj['username'] ?? '');
        }
    }
    if ($username === '') {
        $username = (string) hot_setting_get('linuxdo_oauth_username', '');
    }

    // 2) 拉取热门/最新
    $endpoints = [
        $site . '/top.json?period=daily',
        $site . '/hot.json',
        $site . '/latest.json',
    ];
    $topics = 0;
    $via = '';
    $sample = '';
    foreach ($endpoints as $ep) {
        $body = hot_http_get($ep, 15, $headers);
        if (!$body || stripos($body, 'Just a moment') !== false) {
            continue;
        }
        $json = hot_extract_discourse_json($body);
        if ($json && !empty($json['topic_list']['topics'])) {
            $topics = count($json['topic_list']['topics']);
            $via = $ep;
            $sample = (string) ($json['topic_list']['topics'][0]['title'] ?? '');
            break;
        }
    }

    // 直连被 CF 拦时，尝试 jina 公开数据
    if ($topics === 0) {
        $proxy = 'https://r.jina.ai/https://linux.do/latest.json';
        $body = hot_http_get($proxy, 25, ['Accept: application/json,text/plain,*/*', 'X-Respond-With: text']);
        $json = hot_extract_discourse_json($body);
        if ($json && !empty($json['topic_list']['topics'])) {
            $topics = count($json['topic_list']['topics']);
            $via = 'proxy:jina (公开数据，未走登录态)';
            $sample = (string) ($json['topic_list']['topics'][0]['title'] ?? '');
            if ($username === '' || $authVia === '') {
                return [
                    'ok' => true,
                    'message' => '直连被拦截，已用公开代理拿到 ' . $topics . ' 条；登录态未生效（请检查 Cookie/OAuth 是否有效）',
                    'username' => $username,
                    'topics' => $topics,
                    'via' => $via,
                    'sample' => $sample,
                    'auth_active' => false,
                    'auth_via' => $authVia,
                ];
            }
        }
    }

    if ($topics > 0) {
        $authActive = $username !== '' || in_array($authVia, ['cookie', 'api_key', 'oauth_bearer'], true);
        $viaLabel = [
            'cookie' => 'Cookie',
            'api_key' => 'API Key',
            'oauth_bearer' => 'OAuth Bearer',
        ][$authVia] ?? $authVia;
        $msg = $username !== ''
            ? ('登录有效：用户 ' . $username . '，成功拉取 ' . $topics . ' 条')
            : ('已拉取 ' . $topics . ' 条（未识别到 current_user）');
        if ($viaLabel !== '') {
            $msg .= ' · 鉴权：' . $viaLabel;
        }
        return [
            'ok' => true,
            'message' => $msg,
            'username' => $username,
            'topics' => $topics,
            'via' => $via,
            'sample' => $sample,
            'auth_active' => $authActive,
            'auth_via' => $authVia,
        ];
    }

    return [
        'ok' => false,
        'message' => '无法访问 linux.do（Cloudflare 拦截或凭证无效）。请重新 OAuth 授权或更新 Cookie。',
        'username' => $username,
        'topics' => 0,
        'auth_via' => $authVia,
    ];
}

/**
 * HTTP GET
 * @return string|null
 */
function hot_http_get($url, $timeout = 12, array $headers = [])
{
    $defaultHeaders = [
        'Accept: application/json,text/html,application/xhtml+xml,*/*;q=0.8',
        'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
    ];
    $headers = array_merge($defaultHeaders, $headers);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => function_exists('security_ssl_verify_peer') ? security_ssl_verify_peer() : true,
            CURLOPT_SSL_VERIFYHOST => (function_exists('security_ssl_verify_peer') && !security_ssl_verify_peer()) ? 0 : 2,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno === 0 && $body !== false && $body !== '' && $code < 400) {
            return $body;
        }
        // 部分站点 403 仍可能有 body，交上层判断
        if ($errno === 0 && $body !== false && $body !== '') {
            return $body;
        }
    }

    $headerStr = implode("\r\n", $headers) . "\r\n";
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'header' => $headerStr . "User-Agent: Mozilla/5.0\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => function_exists('security_ssl_verify_peer') ? security_ssl_verify_peer() : true,
            'verify_peer_name' => function_exists('security_ssl_verify_peer') ? security_ssl_verify_peer() : true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return ($body === false || $body === '') ? null : $body;
}

function hot_to_utf8($text, $charset = '')
{
    $text = (string) $text;
    if ($text === '') {
        return '';
    }
    if ($charset !== '' && strtoupper($charset) !== 'UTF-8') {
        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($text, 'UTF-8', $charset . ',UTF-8');
            if ($converted !== false && $converted !== '') {
                return $converted;
            }
        }
        $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
        if ($converted !== false && $converted !== '') {
            return $converted;
        }
    }
    return $text;
}

function hot_format_heat($heat)
{
    if (is_numeric($heat)) {
        $n = (float) $heat;
        if ($n >= 10000) {
            return rtrim(rtrim(number_format($n / 10000, 1, '.', ''), '0'), '.') . '万';
        }
        return (string) (int) $n;
    }
    $heat = trim((string) $heat);
    return $heat !== '' ? $heat : '—';
}

function hot_normalize_item(array $row, $rank, $sourceId)
{
    $title = trim((string) ($row['title'] ?? $row['name'] ?? $row['word'] ?? ''));
    if ($title === '') {
        return null;
    }

    $heat = $row['hot'] ?? $row['heat'] ?? $row['index'] ?? $row['hotScore'] ?? $row['views'] ?? $row['reply_count'] ?? '';
    $heat = hot_format_heat($heat);

    $url = $row['url'] ?? $row['mobilUrl'] ?? $row['link'] ?? '';
    $url = is_string($url) ? trim($url) : '';
    if ($url === '' || $url === 'null') {
        $url = hot_build_fallback_url($sourceId, $title);
    } elseif (function_exists('security_url')) {
        $safe = security_url($url, false);
        $url = $safe !== '' ? $safe : hot_build_fallback_url($sourceId, $title);
    }

    return [
        'rank' => (int) $rank,
        'title' => $title,
        'heat' => $heat,
        'url' => $url,
    ];
}

function hot_build_fallback_url($sourceId, $title)
{
    $q = rawurlencode($title);
    switch ($sourceId) {
        case 'weibo':
            return 'https://s.weibo.com/weibo?q=' . $q;
        case 'zhihu':
            return 'https://www.zhihu.com/search?type=content&q=' . $q;
        case 'baidu':
            return 'https://www.baidu.com/s?wd=' . $q;
        case 'bilibili':
            return 'https://search.bilibili.com/all?keyword=' . $q;
        case 'douyin':
            return 'https://www.douyin.com/search/' . $q;
        case 'tieba':
            return 'https://www.baidu.com/s?wd=' . $q . '+site%3Atieba.baidu.com';
        case '52pojie':
            return 'https://www.52pojie.cn/search.php?mod=forum&searchid=&orderby=lastpost&ascdesc=desc&searchsubmit=yes&kw=' . $q;
        case 'linuxdo':
            return 'https://linux.do/search?q=' . $q;
        case 'v2ex':
            return 'https://www.v2ex.com/?tab=hot';
        case 'sspai':
            return 'https://sspai.com/search/post/' . $q;
        default:
            return 'https://www.baidu.com/s?wd=' . $q;
    }
}

function hot_cache_read($id)
{
    $file = HOT_CACHE_DIR . '/' . $id . '.json';
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data) || empty($data['items']) || empty($data['time'])) {
        return null;
    }
    if (time() - (int) $data['time'] > HOT_CACHE_TTL) {
        return null;
    }
    return $data;
}

function hot_cache_write($id, array $items, $updateTime = '')
{
    if (!is_dir(HOT_CACHE_DIR)) {
        @mkdir(HOT_CACHE_DIR, 0755, true);
    }
    $payload = [
        'time' => time(),
        'update_time' => $updateTime,
        'items' => $items,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    return file_put_contents(HOT_CACHE_DIR . '/' . $id . '.json', $json . "\n", LOCK_EX) !== false;
}

function hot_cache_stale($id)
{
    $file = HOT_CACHE_DIR . '/' . $id . '.json';
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data) || empty($data['items'])) {
        return null;
    }
    return $data;
}

function hot_parse_ikunpay_body($body, $sourceId, $limit)
{
    $items = [];
    $updateTime = '';
    if (!$body) {
        return [$items, $updateTime];
    }

    $jsonStart = strpos($body, '{');
    if ($jsonStart !== false && $jsonStart > 0) {
        $body = substr($body, $jsonStart);
    }
    $json = json_decode($body, true);
    if (!is_array($json) || empty($json['data']) || !is_array($json['data'])) {
        return [$items, $updateTime];
    }

    $updateTime = (string) ($json['update_time'] ?? '');
    $rank = 1;
    foreach ($json['data'] as $row) {
        if ($rank > $limit) {
            break;
        }
        if (!is_array($row)) {
            continue;
        }
        $item = hot_normalize_item($row, $rank, $sourceId);
        if ($item) {
            $items[] = $item;
            $rank++;
        }
    }
    return [$items, $updateTime];
}

function hot_fetch_ikunpay(array $source)
{
    $id = $source['id'];
    $limit = (int) ($source['limit'] ?? 30);
    $types = $source['types'] ?? [];
    if (empty($types) && !empty($source['type'])) {
        $types = [$source['type']];
    }

    $items = [];
    $updateTime = '';
    foreach ($types as $type) {
        $url = 'https://api.ikunpay.com/api/jhrs?type=' . rawurlencode($type);
        $body = hot_http_get($url);
        list($items, $updateTime) = hot_parse_ikunpay_body($body, $id, $limit);
        if (!empty($items)) {
            break;
        }
    }
    return [$items, $updateTime];
}

/**
 * 从文本中提取 Discourse latest/top JSON
 */
function hot_extract_discourse_json($body)
{
    if (!$body) {
        return null;
    }
    // jina.ai 等代理可能在前后包一层说明
    if (preg_match('/\{[\s\S]*"topic_list"[\s\S]*\}/u', $body, $m)) {
        $json = json_decode($m[0], true);
        if (is_array($json) && !empty($json['topic_list']['topics'])) {
            return $json;
        }
    }
    $jsonStart = strpos($body, '{');
    if ($jsonStart !== false) {
        $json = json_decode(substr($body, $jsonStart), true);
        if (is_array($json) && !empty($json['topic_list']['topics'])) {
            return $json;
        }
    }
    return null;
}

/**
 * 从 Discourse topics 列表构建热榜条目
 */
function hot_discourse_topics_to_items(array $topics, $site, $id, $limit)
{
    $items = [];
    $rank = 1;
    foreach ($topics as $topic) {
        if ($rank > $limit) {
            break;
        }
        if (!is_array($topic) || empty($topic['title'])) {
            continue;
        }
        // 置顶公告可按需跳过 pinned_globally 且非热门
        $slug = $topic['slug'] ?? '';
        $tid = $topic['id'] ?? '';
        $url = $site . '/t/' . rawurlencode((string) $slug) . '/' . $tid;
        $heat = $topic['like_count'] ?? $topic['views'] ?? $topic['posts_count'] ?? '';
        $item = hot_normalize_item([
            'title' => $topic['title'],
            'url' => $url,
            'heat' => $heat,
        ], $rank, $id);
        if ($item) {
            $items[] = $item;
            $rank++;
        }
    }
    return $items;
}

/**
 * Discourse 论坛（linux.do 等）
 * 优先使用后台配置的 Cookie / API Key 拉取「登录后」可见热榜；
 * 失败时再走公开接口与 r.jina.ai 代理兜底。
 */
function hot_fetch_discourse(array $source)
{
    $id = $source['id'];
    $limit = (int) ($source['limit'] ?? 30);
    $site = rtrim($source['site'] ?? 'https://linux.do', '/');
    $isLinuxDo = ($id === 'linuxdo' || stripos($site, 'linux.do') !== false);
    $hasAuth = $isLinuxDo && hot_linuxdo_has_auth();

    // 登录态优先「日榜/热门」，更接近前台热榜；公开模式兼容 latest
    if ($hasAuth) {
        $endpoints = [
            $site . '/top.json?period=daily',
            $site . '/top.json?period=weekly',
            $site . '/hot.json',
            $site . '/latest.json',
            $site . '/top.json',
        ];
    } else {
        $endpoints = $source['endpoints'] ?? [
            $site . '/latest.json',
            $site . '/top.json',
            $site . '/hot.json',
        ];
    }

    $baseHeaders = [
        'Accept: application/json',
        'X-Requested-With: XMLHttpRequest',
        'Referer: ' . $site . '/',
    ];
    if ($hasAuth) {
        $baseHeaders = hot_discourse_auth_headers($baseHeaders);
    }

    // 第一轮：带登录凭证直连（不走代理，代理无法带登录态）
    if ($hasAuth) {
        foreach ($endpoints as $endpoint) {
            $body = hot_http_get($endpoint, 18, $baseHeaders);
            if (!$body || stripos($body, 'Just a moment') !== false) {
                continue;
            }
            $json = hot_extract_discourse_json($body);
            if (!$json || empty($json['topic_list']['topics'])) {
                continue;
            }
            $items = hot_discourse_topics_to_items($json['topic_list']['topics'], $site, $id, $limit);
            if (!empty($items)) {
                // 标记缓存元数据：登录热榜
                hot_setting_set('linuxdo_last_fetch', json_encode([
                    'at' => date('Y-m-d H:i:s'),
                    'auth' => true,
                    'endpoint' => $endpoint,
                    'count' => count($items),
                ], JSON_UNESCAPED_UNICODE));
                return [$items, date('Y-m-d H:i:s')];
            }
        }
    }

    // 第二轮：公开直连 + jina 代理
    $tryUrls = [];
    foreach ($endpoints as $endpoint) {
        $tryUrls[] = $endpoint;
        $tryUrls[] = 'https://r.jina.ai/' . $endpoint;
        $tryUrls[] = 'https://r.jina.ai/http://' . preg_replace('#^https?://#', '', $endpoint);
    }

    $publicHeaders = [
        'Accept: application/json',
        'X-Requested-With: XMLHttpRequest',
        'Referer: ' . $site . '/',
    ];

    foreach ($tryUrls as $endpoint) {
        $isProxy = strpos($endpoint, 'r.jina.ai') !== false;
        $reqHeaders = $isProxy
            ? ['Accept: application/json,text/plain,*/*', 'X-Respond-With: text']
            : $publicHeaders;
        $body = hot_http_get($endpoint, $isProxy ? 25 : 15, $reqHeaders);
        if (!$body || stripos($body, 'Just a moment') !== false) {
            continue;
        }
        $json = hot_extract_discourse_json($body);
        if (!$json || empty($json['topic_list']['topics'])) {
            continue;
        }
        $items = hot_discourse_topics_to_items($json['topic_list']['topics'], $site, $id, $limit);
        if (!empty($items)) {
            if ($isLinuxDo) {
                hot_setting_set('linuxdo_last_fetch', json_encode([
                    'at' => date('Y-m-d H:i:s'),
                    'auth' => false,
                    'endpoint' => $endpoint,
                    'count' => count($items),
                ], JSON_UNESCAPED_UNICODE));
            }
            return [$items, date('Y-m-d H:i:s')];
        }
    }
    return [[], ''];
}

/**
 * V2EX 官方 API
 */
function hot_fetch_v2ex(array $source)
{
    $id = $source['id'];
    $limit = (int) ($source['limit'] ?? 20);
    $url = $source['fetch_url'] ?? 'https://www.v2ex.com/api/topics/hot.json';
    $body = hot_http_get($url, 12, ['Accept: application/json']);
    if (!$body) {
        return [[], ''];
    }
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return [[], ''];
    }
    $items = [];
    $rank = 1;
    foreach ($json as $row) {
        if ($rank > $limit) {
            break;
        }
        if (!is_array($row) || empty($row['title'])) {
            continue;
        }
        $item = hot_normalize_item([
            'title' => $row['title'],
            'url' => $row['url'] ?? '',
            'heat' => $row['replies'] ?? '',
        ], $rank, $id);
        if ($item) {
            $items[] = $item;
            $rank++;
        }
    }
    return [$items, date('Y-m-d H:i:s')];
}

/**
 * Discuz 排行榜页面解析（吾爱破解）
 * 若后台已通过 OAuth 式授权保存 Cookie，则带登录态请求
 */
function hot_fetch_discuz(array $source)
{
    $id = $source['id'];
    $limit = (int) ($source['limit'] ?? 30);
    $url = $source['fetch_url'] ?? '';
    $base = rtrim($source['base_url'] ?? 'https://www.52pojie.cn/', '/') . '/';
    $charset = $source['charset'] ?? 'GBK';
    if ($url === '') {
        return [[], ''];
    }

    if (!function_exists('hot_52pojie_has_auth')) {
        $oauthFile = __DIR__ . '/oauth_providers.php';
        if (is_file($oauthFile)) {
            require_once $oauthFile;
        }
    }

    $headers = [
        'Accept: text/html,application/xhtml+xml',
        'Referer: ' . $base,
    ];
    $usedAuth = false;
    if ($id === '52pojie' && function_exists('hot_52pojie_has_auth') && hot_52pojie_has_auth()) {
        $cred = hot_52pojie_credentials();
        if (!empty($cred['cookie'])) {
            $headers[] = 'Cookie: ' . $cred['cookie'];
            $usedAuth = true;
        }
    }

    $body = hot_http_get($url, 15, $headers);
    if (!$body) {
        return [[], ''];
    }
    $body = hot_to_utf8($body, $charset);

    $matches = [];
    if (!preg_match_all('/<a href="(thread-\d+-[^"]+\.html)"[^>]*>([^<]+)<\/a>/u', $body, $m, PREG_SET_ORDER)) {
        return [[], ''];
    }

    // 常驻教程/版务贴（非今日热帖主体）
    $blockTitles = [
        'Windows破解入门',
        'Android逆向入门',
        '【清理未活跃会员】',
        '撤销悬赏',
        '【网络诊断修复工具】',
    ];

    $seen = [];
    $items = [];
    $rank = 1;
    foreach ($m as $row) {
        if ($rank > $limit) {
            break;
        }
        $href = html_entity_decode(trim($row[1]), ENT_QUOTES, 'UTF-8');
        $title = html_entity_decode(trim($row[2]), ENT_QUOTES, 'UTF-8');
        $title = trim(preg_replace('/\s+/u', ' ', $title));
        if ($title === '' || preg_match('/^\d+$/u', $title) || mb_strlen_safe($title) < 6) {
            continue;
        }
        if (in_array($title, $blockTitles, true)) {
            continue;
        }
        if (isset($seen[$href]) || isset($seen[$title])) {
            continue;
        }
        $seen[$href] = true;
        $seen[$title] = true;
        $full = (strpos($href, 'http') === 0) ? $href : ($base . ltrim($href, '/'));
        $item = hot_normalize_item([
            'title' => $title,
            'url' => $full,
            'heat' => '热',
        ], $rank, $id);
        if ($item) {
            $items[] = $item;
            $rank++;
        }
    }
    if ($id === '52pojie' && !empty($items) && function_exists('hot_setting_set')) {
        hot_setting_set('52pojie_last_fetch', json_encode([
            'at' => date('Y-m-d H:i:s'),
            'auth' => !empty($usedAuth),
            'count' => count($items),
        ], JSON_UNESCAPED_UNICODE));
    }
    return [$items, date('Y-m-d H:i:s')];
}

function mb_strlen_safe($str)
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($str, 'UTF-8');
    }
    return strlen($str);
}

/**
 * 拉取单个平台
 */
function hot_fetch_source(array $source)
{
    $id = $source['id'];
    $cached = hot_cache_read($id);
    if ($cached) {
        return [
            'id' => $id,
            'name' => $source['name'],
            'short' => $source['short'] ?? $source['name'],
            'label' => $source['label'] ?? '',
            'logo' => $source['logo'] ?? '',
            'items' => $cached['items'],
            'update_time' => $cached['update_time'] ?? '',
            'from_cache' => true,
            'fallback_url' => $source['fallback_url'] ?? '',
        ];
    }

    $provider = $source['provider'] ?? 'ikunpay';
    $items = [];
    $updateTime = '';

    try {
        switch ($provider) {
            case 'discourse':
                list($items, $updateTime) = hot_fetch_discourse($source);
                break;
            case 'v2ex':
                list($items, $updateTime) = hot_fetch_v2ex($source);
                break;
            case 'discuz':
                list($items, $updateTime) = hot_fetch_discuz($source);
                break;
            case 'ikunpay':
            default:
                list($items, $updateTime) = hot_fetch_ikunpay($source);
                break;
        }
    } catch (Throwable $e) {
        $items = [];
    }

    if (!empty($items)) {
        hot_cache_write($id, $items, $updateTime);
        return [
            'id' => $id,
            'name' => $source['name'],
            'short' => $source['short'] ?? $source['name'],
            'label' => $source['label'] ?? '',
            'logo' => $source['logo'] ?? '',
            'items' => $items,
            'update_time' => $updateTime,
            'from_cache' => false,
            'fallback_url' => $source['fallback_url'] ?? '',
        ];
    }

    $stale = hot_cache_stale($id);
    if ($stale) {
        return [
            'id' => $id,
            'name' => $source['name'],
            'short' => $source['short'] ?? $source['name'],
            'label' => $source['label'] ?? '',
            'logo' => $source['logo'] ?? '',
            'items' => $stale['items'],
            'update_time' => $stale['update_time'] ?? '',
            'from_cache' => true,
            'stale' => true,
            'fallback_url' => $source['fallback_url'] ?? '',
        ];
    }

    return [
        'id' => $id,
        'name' => $source['name'],
        'short' => $source['short'] ?? $source['name'],
        'label' => $source['label'] ?? '',
        'logo' => $source['logo'] ?? '',
        'items' => [],
        'update_time' => '',
        'error' => true,
        'fallback_url' => $source['fallback_url'] ?? '',
    ];
}

/**
 * 拉取全部已启用热榜
 */
function load_hot_boards_live()
{
    $boards = [];
    foreach (hot_board_sources() as $source) {
        $boards[] = hot_fetch_source($source);
    }
    return $boards;
}
