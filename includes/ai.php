<?php
/**
 * AI 配置与调用（OpenAI 兼容接口：DeepSeek / OpenAI / 通义兼容模式 / 自建等）
 */

if (!defined('ROOT_PATH')) {
    require_once __DIR__ . '/bootstrap.php';
}

function ai_config_file()
{
    return ROOT_PATH . '/config/ai_config.json';
}

/**
 * @return array{base_url:string,api_key:string,model:string,enabled:bool,models:array,updated_at:string}
 */
function ai_config_load()
{
    $defaults = [
        'base_url' => 'https://api.openai.com/v1',
        'api_key' => '',
        'model' => '',
        'enabled' => false,
        'models' => [],
        'updated_at' => '',
    ];
    $file = ai_config_file();
    if (!is_file($file)) {
        return $defaults;
    }
    $json = json_decode((string) file_get_contents($file), true);
    if (!is_array($json)) {
        return $defaults;
    }
    $cfg = array_merge($defaults, $json);
    $cfg['base_url'] = rtrim(trim((string) $cfg['base_url']), '/');
    $cfg['api_key'] = trim((string) $cfg['api_key']);
    $cfg['model'] = trim((string) $cfg['model']);
    $cfg['enabled'] = !empty($cfg['enabled']);
    if (!is_array($cfg['models'])) {
        $cfg['models'] = [];
    }
    return $cfg;
}

function ai_config_save(array $input)
{
    $prev = ai_config_load();
    $base = trim((string) ($input['base_url'] ?? $prev['base_url']));
    $base = rtrim($base, '/');
    if ($base === '') {
        $base = 'https://api.openai.com/v1';
    }
    // 允许用户填到根路径，自动补 /v1
    if (!preg_match('#/v1$#i', $base) && stripos($base, 'openai.com') !== false) {
        $base .= '/v1';
    }

    $apiKey = $prev['api_key'];
    if (!empty($input['api_key_submitted'])) {
        $apiKey = trim((string) ($input['api_key'] ?? ''));
    }
    if (!empty($input['clear_key'])) {
        $apiKey = '';
    }

    $model = trim((string) ($input['model'] ?? $prev['model']));
    $enabled = !empty($input['enabled']);
    $models = $prev['models'];
    if (isset($input['models']) && is_array($input['models'])) {
        $models = array_values(array_filter(array_map('strval', $input['models'])));
    }

    $payload = [
        'base_url' => $base,
        'api_key' => $apiKey,
        'model' => $model,
        'enabled' => $enabled,
        'models' => $models,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    $file = ai_config_file();
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ok = @file_put_contents(
        $file,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n",
        LOCK_EX
    ) !== false;
    if ($ok) {
        @chmod($file, 0600);
    }
    return $ok;
}

function ai_is_ready()
{
    $cfg = ai_config_load();
    return $cfg['enabled'] && $cfg['api_key'] !== '' && $cfg['base_url'] !== '' && $cfg['model'] !== '';
}

/**
 * HTTP 请求
 * @return array{ok:bool,code:int,body:string,error?:string}
 */
function ai_http($method, $url, $apiKey, $jsonBody = null, $timeout = 45)
{
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'NavSite-AI/1.0',
        ];
        if ($jsonBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = is_string($jsonBody) ? $jsonBody : json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0) {
            return ['ok' => false, 'code' => 0, 'body' => '', 'error' => $err ?: ('curl ' . $errno)];
        }
        return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'body' => (string) $body];
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $jsonBody === null ? '' : (is_string($jsonBody) ? $jsonBody : json_encode($jsonBody, JSON_UNESCAPED_UNICODE)),
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    return [
        'ok' => $body !== false && $code >= 200 && $code < 300,
        'code' => $code,
        'body' => $body === false ? '' : (string) $body,
        'error' => $body === false ? 'request failed' : '',
    ];
}

/**
 * 拉取可用模型列表
 * @return array{ok:bool,models?:string[],message:string}
 */
function ai_fetch_models()
{
    $cfg = ai_config_load();
    if ($cfg['api_key'] === '' || $cfg['base_url'] === '') {
        return ['ok' => false, 'message' => '请先填写 API Base URL 与 API Key'];
    }

    $url = $cfg['base_url'] . '/models';
    $res = ai_http('GET', $url, $cfg['api_key'], null, 30);
    if (!$res['ok']) {
        $hint = $res['body'] !== '' ? mb_substr_ai($res['body'], 0, 200) : ($res['error'] ?? '');
        return ['ok' => false, 'message' => '拉取模型失败 HTTP ' . $res['code'] . ($hint !== '' ? '：' . $hint : '')];
    }

    $json = json_decode($res['body'], true);
    $models = [];
    if (is_array($json)) {
        $list = $json['data'] ?? $json['models'] ?? null;
        if (is_array($list)) {
            foreach ($list as $row) {
                if (is_string($row)) {
                    $models[] = $row;
                } elseif (is_array($row) && !empty($row['id'])) {
                    $models[] = (string) $row['id'];
                }
            }
        }
    }
    $models = array_values(array_unique(array_filter($models)));
    sort($models);

    if (empty($models)) {
        return ['ok' => false, 'message' => '接口未返回模型列表，请检查 Base URL 是否为 OpenAI 兼容 /v1 地址'];
    }

    ai_config_save([
        'base_url' => $cfg['base_url'],
        'model' => $cfg['model'] !== '' && in_array($cfg['model'], $models, true) ? $cfg['model'] : $models[0],
        'enabled' => $cfg['enabled'],
        'models' => $models,
        // 不传 api_key_submitted，保留原 key
    ]);

    return ['ok' => true, 'models' => $models, 'message' => '已获取 ' . count($models) . ' 个模型'];
}

/**
 * Chat Completions
 * @return array{ok:bool,text?:string,message:string}
 */
function ai_chat($system, $user, $maxTokens = 300)
{
    $cfg = ai_config_load();
    if ($cfg['api_key'] === '' || $cfg['base_url'] === '') {
        return ['ok' => false, 'message' => 'AI 未配置 API Key 或 Base URL'];
    }
    if ($cfg['model'] === '') {
        return ['ok' => false, 'message' => '请先选择模型'];
    }

    $url = $cfg['base_url'] . '/chat/completions';
    $payload = [
        'model' => $cfg['model'],
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
        'temperature' => 0.7,
        'max_tokens' => (int) $maxTokens,
    ];
    $res = ai_http('POST', $url, $cfg['api_key'], $payload, 60);
    if (!$res['ok']) {
        $hint = $res['body'] !== '' ? mb_substr_ai($res['body'], 0, 240) : ($res['error'] ?? '');
        return ['ok' => false, 'message' => '生成失败 HTTP ' . $res['code'] . ($hint !== '' ? '：' . $hint : '')];
    }
    $json = json_decode($res['body'], true);
    $text = '';
    if (is_array($json)) {
        $text = (string) ($json['choices'][0]['message']['content'] ?? $json['choices'][0]['text'] ?? '');
    }
    $text = trim($text);
    // 去掉可能的引号包裹
    if ((str_starts_with_ai($text, '"') && str_ends_with_ai($text, '"'))
        || (str_starts_with_ai($text, '「') && str_ends_with_ai($text, '」'))) {
        $text = trim(mb_substr_ai($text, 1, mb_strlen_ai($text) - 2));
    }
    if ($text === '') {
        return ['ok' => false, 'message' => '模型未返回有效内容'];
    }
    return ['ok' => true, 'text' => $text, 'message' => 'ok'];
}

/**
 * 根据站点名称与 URL 生成简介
 */
function ai_generate_site_desc($name, $url, $hint = '')
{
    $name = trim((string) $name);
    $url = trim((string) $url);
    if ($name === '' && $url === '') {
        return ['ok' => false, 'message' => '请先填写站点名称或链接'];
    }

    $system = '你是中文导航站文案助手。根据站点名称与网址，写一句简洁的站点介绍。'
        . '要求：中文；不超过 60 个字；不要加引号、不要换行、不要前后缀说明；客观实用，突出站点用途。';
    $user = "站点名称：{$name}\n网址：{$url}";
    if (trim((string) $hint) !== '') {
        $user .= "\n补充说明：" . trim((string) $hint);
    }
    $user .= "\n请只输出介绍正文。";

    $result = ai_chat($system, $user, 180);
    if (!$result['ok']) {
        return $result;
    }
    $text = preg_replace('/\s+/u', ' ', $result['text']);
    $text = trim((string) $text);
    if (mb_strlen_ai($text) > 120) {
        $text = mb_substr_ai($text, 0, 120);
    }
    return ['ok' => true, 'text' => $text, 'message' => '生成成功'];
}

function mb_substr_ai($str, $start, $len = null)
{
    if (function_exists('mb_substr')) {
        return $len === null
            ? mb_substr((string) $str, $start, null, 'UTF-8')
            : mb_substr((string) $str, $start, $len, 'UTF-8');
    }
    return $len === null ? substr((string) $str, $start) : substr((string) $str, $start, $len);
}

function mb_strlen_ai($str)
{
    return function_exists('mb_strlen') ? mb_strlen((string) $str, 'UTF-8') : strlen((string) $str);
}

function str_starts_with_ai($haystack, $needle)
{
    $haystack = (string) $haystack;
    $needle = (string) $needle;
    if ($needle === '') {
        return true;
    }
    return substr($haystack, 0, strlen($needle)) === $needle;
}

function str_ends_with_ai($haystack, $needle)
{
    $haystack = (string) $haystack;
    $needle = (string) $needle;
    if ($needle === '') {
        return true;
    }
    return substr($haystack, -strlen($needle)) === $needle;
}
