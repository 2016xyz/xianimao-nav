<?php
/**
 * 系统在线更新：检测 GitHub 新版本、下载 zip、安全覆盖安装文件
 * 不覆盖：config/database.php、install.lock、用户上传、运行时缓存与密钥文件
 */

if (!defined('ROOT_PATH')) {
    require_once __DIR__ . '/bootstrap.php';
}

/**
 * 默认本地版本
 * @return array
 */
function updater_default_version()
{
    return [
        'name' => '网址导航',
        'version' => '0.0.0',
        'build' => '',
        'commit' => '',
        'channel' => 'master',
        'repo' => '2016xyz/xianimao-nav',
        'repo_url' => 'https://github.com/2016xyz/xianimao-nav',
    ];
}

/**
 * 读取本地版本
 * @return array
 */
function updater_local_version()
{
    $defaults = updater_default_version();
    $file = ROOT_PATH . '/config/version.php';
    if (!is_file($file)) {
        return $defaults;
    }
    $data = include $file;
    if (!is_array($data)) {
        return $defaults;
    }
    return array_merge($defaults, $data);
}

/**
 * 写入本地版本文件
 * @param array $data
 * @return bool
 */
function updater_write_version(array $data)
{
    $cur = updater_local_version();
    $merged = array_merge($cur, $data);
    $export = var_export([
        'name' => (string) ($merged['name'] ?? (function_exists('site_brand_default_name') ? site_brand_default_name() : '网址导航')),
        'version' => (string) ($merged['version'] ?? '0.0.0'),
        'build' => (string) ($merged['build'] ?? ''),
        'commit' => (string) ($merged['commit'] ?? ''),
        'channel' => (string) ($merged['channel'] ?? 'master'),
        'repo' => (string) ($merged['repo'] ?? '2016xyz/xianimao-nav'),
        'repo_url' => (string) ($merged['repo_url'] ?? 'https://github.com/2016xyz/xianimao-nav'),
    ], true);
    $php = "<?php\n/**\n * 本地程序版本信息（更新系统读写；勿放密钥）\n */\nreturn " . $export . ";\n";
    $file = ROOT_PATH . '/config/version.php';
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return @file_put_contents($file, $php, LOCK_EX) !== false;
}

/**
 * 解析仓库 owner/name
 * @return array{0:string,1:string}|null
 */
function updater_parse_repo($repo)
{
    $repo = trim((string) $repo);
    if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
        return null;
    }
    $parts = explode('/', $repo, 2);
    return [$parts[0], $parts[1]];
}

/**
 * HTTP GET（优先 curl）
 * @return array{ok:bool,body:?string,code:int,error:string}
 */
function updater_http_get($url, $timeout = 25, array $headers = [])
{
    $url = (string) $url;
    if (!preg_match('#^https://#i', $url)) {
        return ['ok' => false, 'body' => null, 'code' => 0, 'error' => '仅允许 HTTPS'];
    }
    $defaultHeaders = [
        'Accept: application/vnd.github+json, application/json, */*',
        'User-Agent: xianimao-nav-updater/1.0',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    $headers = array_merge($defaultHeaders, $headers);
    $verify = function_exists('security_ssl_verify_peer') ? security_ssl_verify_peer() : true;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if (function_exists('security_curl_set_ssl')) {
            security_curl_set_ssl($ch);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);
        }
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0) {
            return ['ok' => false, 'body' => null, 'code' => $code, 'error' => $err !== '' ? $err : ('curl error ' . $errno)];
        }
        if ($code >= 400) {
            return ['ok' => false, 'body' => is_string($body) ? $body : null, 'code' => $code, 'error' => 'HTTP ' . $code];
        }
        return ['ok' => true, 'body' => is_string($body) ? $body : '', 'code' => $code, 'error' => ''];
    }

    $headerStr = implode("\r\n", $headers) . "\r\n";
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'header' => $headerStr,
            'ignore_errors' => true,
        ],
        'ssl' => function_exists('security_stream_ssl_opts')
            ? security_stream_ssl_opts()
            : [
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
            ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    if ($body === false) {
        return ['ok' => false, 'body' => null, 'code' => $code, 'error' => '请求失败'];
    }
    if ($code >= 400) {
        return ['ok' => false, 'body' => $body, 'code' => $code, 'error' => 'HTTP ' . $code];
    }
    return ['ok' => true, 'body' => $body, 'code' => $code, 'error' => ''];
}

/**
 * 下载二进制到文件
 * @return array{ok:bool,error:string,bytes:int}
 */
function updater_http_download($url, $destPath, $timeout = 180)
{
    $url = (string) $url;
    if (!preg_match('#^https://#i', $url)) {
        return ['ok' => false, 'error' => '仅允许 HTTPS 下载', 'bytes' => 0];
    }
    $dir = dirname($destPath);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'error' => '无法创建临时目录', 'bytes' => 0];
    }
    $verify = function_exists('security_ssl_verify_peer') ? security_ssl_verify_peer() : true;
    $headers = [
        'Accept: application/octet-stream, application/zip, */*',
        'User-Agent: xianimao-nav-updater/1.0',
    ];

    if (function_exists('curl_init')) {
        $fp = @fopen($destPath, 'wb');
        if (!$fp) {
            return ['ok' => false, 'error' => '无法写入临时文件', 'bytes' => 0];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if (function_exists('security_curl_set_ssl')) {
            security_curl_set_ssl($ch);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);
        }
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if ($ok === false || $errno !== 0) {
            @unlink($destPath);
            return ['ok' => false, 'error' => $err !== '' ? $err : '下载失败', 'bytes' => 0];
        }
        if ($code >= 400) {
            @unlink($destPath);
            return ['ok' => false, 'error' => '下载 HTTP ' . $code, 'bytes' => 0];
        }
        $bytes = is_file($destPath) ? (int) filesize($destPath) : 0;
        if ($bytes < 1000) {
            @unlink($destPath);
            return ['ok' => false, 'error' => '下载文件过小，可能失败', 'bytes' => $bytes];
        }
        return ['ok' => true, 'error' => '', 'bytes' => $bytes];
    }

    $res = updater_http_get($url, $timeout, $headers);
    if (empty($res['ok']) || $res['body'] === null || $res['body'] === '') {
        return ['ok' => false, 'error' => $res['error'] ?? '下载失败', 'bytes' => 0];
    }
    $bytes = strlen($res['body']);
    if ($bytes < 1000) {
        return ['ok' => false, 'error' => '下载文件过小，可能失败', 'bytes' => $bytes];
    }
    if (@file_put_contents($destPath, $res['body'], LOCK_EX) === false) {
        return ['ok' => false, 'error' => '写入临时文件失败', 'bytes' => 0];
    }
    return ['ok' => true, 'error' => '', 'bytes' => $bytes];
}

/**
 * 规范化版本号比较用
 */
function updater_normalize_version($v)
{
    $v = trim((string) $v);
    $v = ltrim($v, 'vV');
    if ($v === '') {
        return '0.0.0';
    }
    // 去掉 -beta 等后缀参与主版本比较
    if (preg_match('/^([0-9]+(?:\.[0-9]+)*)/', $v, $m)) {
        return $m[1];
    }
    return $v;
}

/**
 * 比较版本：1 = a>b, 0 相等, -1 = a<b
 */
function updater_version_compare($a, $b)
{
    return version_compare(updater_normalize_version($a), updater_normalize_version($b));
}

/**
 * 临时目录
 */
function updater_tmp_dir()
{
    $dir = ROOT_PATH . '/data/cache/update';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * 清理更新临时文件
 */
function updater_cleanup_tmp()
{
    $dir = updater_tmp_dir();
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $path = $file->getPathname();
        if ($file->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
}

/**
 * 不应被更新包覆盖的相对路径规则
 * @return string[]
 */
function updater_protected_paths()
{
    return [
        'config/database.php',
        'config/install.lock',
        'config/auth.php',
        'config/smtp.json',
        'config/ai_config.json',
        'config/linuxdo_auth.json',
        'config/linuxdo_oauth_app.json',
        'config/52pojie_auth.json',
        'config/hot_config.json',
        'config/site_extra.json',
        'data/content.json',
        'data/messages.json',
        'data/admin_logs.json',
        'data/linuxdo_auth.json',
        'data/52pojie_auth.json',
        'data/cache',
        'assets/images/uploads',
        '.env',
        '.git',
    ];
}

/**
 * 路径是否受保护（不可覆盖）
 */
function updater_is_protected_path($rel)
{
    $rel = str_replace('\\', '/', (string) $rel);
    $rel = ltrim($rel, '/');
    if ($rel === '' || $rel === '.' || strpos($rel, '..') !== false) {
        return true;
    }
    // 隐藏的本地环境文件
    if (preg_match('#(^|/)\.env(\.|$)#', $rel)) {
        return true;
    }
    foreach (updater_protected_paths() as $p) {
        $p = str_replace('\\', '/', $p);
        if ($rel === $p || strpos($rel, $p . '/') === 0) {
            return true;
        }
    }
    // data/cache 下一切
    if (strpos($rel, 'data/cache/') === 0) {
        return true;
    }
    return false;
}

/**
 * 相对路径是否允许从更新包安装
 */
function updater_is_allowed_package_path($rel)
{
    $rel = str_replace('\\', '/', (string) $rel);
    $rel = ltrim($rel, '/');
    if ($rel === '' || strpos($rel, '..') !== false) {
        return false;
    }
    if (updater_is_protected_path($rel)) {
        return false;
    }
    // 仅允许常见源码目录，防止 zip 污染
    $allowPrefixes = [
        'admin/',
        'api/',
        'assets/',
        'config/',
        'docs/',
        'includes/',
    ];
    $allowFiles = [
        'index.php',
        'install.php',
        'about.php',
        'apply.php',
        'contact.php',
        'message.php',
        'README.md',
        'LICENSE',
        'CONTRIBUTING.md',
        '.gitignore',
        '.env.example',
        'config/version.php',
        'config/database.example.php',
        'config/data.php',
        'config/hot_boards.php',
        'config/.htaccess',
        'data/.htaccess',
    ];
    if (in_array($rel, $allowFiles, true)) {
        return true;
    }
    foreach ($allowPrefixes as $pre) {
        if (strpos($rel, $pre) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * 环境能力检测
 * @return array{ok:bool,items:array}
 */
function updater_env_check()
{
    $items = [];
    $zipOk = class_exists('ZipArchive');
    $items[] = [
        'name' => 'PHP ZipArchive',
        'ok' => $zipOk,
        'detail' => $zipOk ? '可用' : '未启用 zip 扩展，无法在线解压更新包',
    ];
    $curlOk = function_exists('curl_init') || ini_get('allow_url_fopen');
    $items[] = [
        'name' => 'HTTP 客户端',
        'ok' => (bool) $curlOk,
        'detail' => function_exists('curl_init') ? 'curl' : (ini_get('allow_url_fopen') ? 'allow_url_fopen' : '不可用'),
    ];
    $tmp = updater_tmp_dir();
    $tmpOk = is_dir($tmp) && is_writable($tmp);
    $items[] = [
        'name' => '临时目录可写',
        'ok' => $tmpOk,
        'detail' => $tmp,
    ];
    $rootOk = is_writable(ROOT_PATH);
    $items[] = [
        'name' => '站点根目录可写',
        'ok' => $rootOk,
        'detail' => $rootOk ? ROOT_PATH : '请给站点根目录写权限以便覆盖程序文件',
    ];
    $ok = true;
    foreach ($items as $it) {
        if (empty($it['ok'])) {
            $ok = false;
            break;
        }
    }
    return ['ok' => $ok, 'items' => $items];
}

/**
 * 从 GitHub 获取远程最新信息
 * channel: master | release
 * @return array
 */
function updater_fetch_remote($force = false)
{
    $local = updater_local_version();
    $cacheFile = updater_tmp_dir() . '/remote_meta.json';
    if (!$force && is_file($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['ok'])) {
            $cached['from_cache'] = true;
            return $cached;
        }
    }

    $repo = updater_parse_repo($local['repo'] ?? '');
    if ($repo === null) {
        return ['ok' => false, 'message' => '仓库配置无效', 'local' => $local];
    }
    list($owner, $name) = $repo;
    $channel = ($local['channel'] ?? 'master') === 'release' ? 'release' : 'master';

    if ($channel === 'release') {
        $api = "https://api.github.com/repos/{$owner}/{$name}/releases/latest";
        $res = updater_http_get($api, 20);
        if (empty($res['ok'])) {
            // 无 release 时回退 master
            $channel = 'master';
        } else {
            $json = json_decode((string) $res['body'], true);
            if (!is_array($json) || empty($json['tag_name'])) {
                $channel = 'master';
            } else {
                $tag = (string) $json['tag_name'];
                $sha = '';
                if (!empty($json['target_commitish']) && preg_match('/^[a-f0-9]{7,40}$/i', (string) $json['target_commitish'])) {
                    $sha = (string) $json['target_commitish'];
                }
                $zip = isset($json['zipball_url']) ? (string) $json['zipball_url'] : "https://github.com/{$owner}/{$name}/archive/refs/tags/" . rawurlencode($tag) . '.zip';
                $remote = [
                    'ok' => true,
                    'channel' => 'release',
                    'version' => ltrim($tag, 'vV'),
                    'tag' => $tag,
                    'commit' => $sha,
                    'sha' => $sha,
                    'published_at' => (string) ($json['published_at'] ?? ''),
                    'name' => (string) ($json['name'] ?? $tag),
                    'body' => (string) ($json['body'] ?? ''),
                    'html_url' => (string) ($json['html_url'] ?? ($local['repo_url'] ?? '')),
                    'zip_url' => $zip,
                    'checked_at' => date('Y-m-d H:i:s'),
                    'local' => $local,
                    'from_cache' => false,
                ];
                $remote['update_available'] = updater_is_update_available($local, $remote);
                @file_put_contents($cacheFile, json_encode($remote, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
                return $remote;
            }
        }
    }

    // master / 默认分支最新提交
    $api = "https://api.github.com/repos/{$owner}/{$name}/commits/master";
    $res = updater_http_get($api, 20);
    if (empty($res['ok'])) {
        // 尝试 main
        $apiMain = "https://api.github.com/repos/{$owner}/{$name}/commits/main";
        $res = updater_http_get($apiMain, 20);
        $branch = 'main';
        if (empty($res['ok'])) {
            return [
                'ok' => false,
                'message' => '无法连接 GitHub：' . ($res['error'] ?? '未知错误'),
                'local' => $local,
                'code' => $res['code'] ?? 0,
            ];
        }
    } else {
        $branch = 'master';
    }

    $json = json_decode((string) $res['body'], true);
    if (!is_array($json) || empty($json['sha'])) {
        return ['ok' => false, 'message' => 'GitHub 返回数据无效', 'local' => $local];
    }
    $sha = (string) $json['sha'];
    $short = substr($sha, 0, 7);
    $msg = '';
    if (!empty($json['commit']['message'])) {
        $msg = (string) $json['commit']['message'];
        if (function_exists('mb_substr')) {
            $msg = mb_substr($msg, 0, 500, 'UTF-8');
        } else {
            $msg = substr($msg, 0, 500);
        }
    }
    $date = (string) ($json['commit']['committer']['date'] ?? $json['commit']['author']['date'] ?? '');

    // 尝试从仓库 config/version.php 读取远程版本声明（raw）
    $remoteVersion = $local['version'] ?? '0.0.0';
    $remoteDeclaredCommit = '';
    $remoteBuild = '';
    $rawUrl = "https://raw.githubusercontent.com/{$owner}/{$name}/{$branch}/config/version.php";
    $rawRes = updater_http_get($rawUrl, 15);
    if (!empty($rawRes['ok']) && is_string($rawRes['body']) && strpos($rawRes['body'], 'version') !== false) {
        if (preg_match("/'version'\\s*=>\\s*'([^']+)'/", $rawRes['body'], $m)
            || preg_match('/"version"\\s*=>\\s*"([^"]+)"/', $rawRes['body'], $m)) {
            $remoteVersion = $m[1];
        }
        if (preg_match("/'commit'\\s*=>\\s*'([^']*)'/", $rawRes['body'], $m)
            || preg_match('/"commit"\\s*=>\\s*"([^"]*)"/', $rawRes['body'], $m)) {
            $remoteDeclaredCommit = strtolower(trim((string) $m[1]));
        }
        if (preg_match("/'build'\\s*=>\\s*'([^']*)'/", $rawRes['body'], $m)
            || preg_match('/"build"\\s*=>\\s*"([^"]*)"/', $rawRes['body'], $m)) {
            $remoteBuild = trim((string) $m[1]);
        }
    }

    // 更新比较优先使用 version.php 声明的 commit；HEAD 仅作展示与下载锚点
    $compareCommit = $remoteDeclaredCommit !== '' ? $remoteDeclaredCommit : $short;
    $zip = "https://github.com/{$owner}/{$name}/archive/refs/heads/{$branch}.zip";
    $remote = [
        'ok' => true,
        'channel' => 'branch',
        'branch' => $branch,
        'version' => $remoteVersion,
        'build' => $remoteBuild,
        'tag' => '',
        'commit' => $compareCommit,
        'head_commit' => $short,
        'declared_commit' => $remoteDeclaredCommit,
        'sha' => $sha,
        'published_at' => $date,
        'name' => '分支 ' . $branch . ' @ ' . $short,
        'body' => $msg,
        'html_url' => "https://github.com/{$owner}/{$name}/commit/{$sha}",
        'zip_url' => $zip,
        'checked_at' => date('Y-m-d H:i:s'),
        'local' => $local,
        'from_cache' => false,
    ];
    $remote['update_available'] = updater_is_update_available($local, $remote);
    @file_put_contents($cacheFile, json_encode($remote, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    return $remote;
}

/**
 * 是否有可用更新
 */
function updater_is_update_available(array $local, array $remote)
{
    $localVersion = (string) ($local['version'] ?? '0.0.0');
    $remoteVersion = (string) ($remote['version'] ?? '0.0.0');
    $vc = updater_version_compare($remoteVersion, $localVersion);
    if ($vc > 0) {
        return true;
    }
    if ($vc < 0) {
        // 远程版本号更低：不提示更新（防止回退）
        return false;
    }

    // 版本号相同：比较 version.php 声明的 commit（避免「仅同步 version 的 chore 提交」造成永久可更新）
    $localCommit = strtolower(trim((string) ($local['commit'] ?? '')));
    $remoteCommit = strtolower(trim((string) (
        $remote['declared_commit']
        ?? $remote['commit']
        ?? $remote['sha']
        ?? ''
    )));
    if ($localCommit === '' || $remoteCommit === '') {
        // 无 commit 时同版本视为已是最新
        return false;
    }
    $lc = substr($localCommit, 0, 7);
    $rc = substr($remoteCommit, 0, 7);
    return $lc !== $rc;
}

/**
 * 递归删除目录
 */
function updater_rrmdir($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $path = $file->getPathname();
        if ($file->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * 定位 zip 内项目根（GitHub archive 通常为 repo-branch/）
 * @return string|null 绝对路径
 */
function updater_find_package_root($extractDir)
{
    $extractDir = rtrim(str_replace('\\', '/', $extractDir), '/');
    if (is_file($extractDir . '/includes/bootstrap.php') || is_file($extractDir . '/index.php')) {
        return $extractDir;
    }
    $entries = @scandir($extractDir);
    if (!is_array($entries)) {
        return null;
    }
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = $extractDir . '/' . $e;
        if (is_dir($p) && (is_file($p . '/includes/bootstrap.php') || is_file($p . '/index.php'))) {
            return $p;
        }
    }
    return null;
}

/**
 * 安全解压 Zip：拒绝 .. / 绝对路径 / 逃逸 extractDir 的条目
 * @param ZipArchive $zip
 * @param string $extractDir
 * @return bool
 */
function updater_safe_extract(ZipArchive $zip, $extractDir)
{
    $extractDir = rtrim(str_replace('\\', '/', $extractDir), '/');
    if ($extractDir === '' || !is_dir($extractDir)) {
        return false;
    }
    // 规范化基路径（Windows 下 realpath 可能为反斜杠）
    $baseReal = realpath($extractDir);
    if ($baseReal === false) {
        return false;
    }
    $baseReal = rtrim(str_replace('\\', '/', $baseReal), '/');

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat) || empty($stat['name'])) {
            return false;
        }
        $name = str_replace('\\', '/', (string) $stat['name']);
        // 拒绝绝对路径、盘符、空名、.. 段
        if ($name === '' || $name[0] === '/' || preg_match('#^[A-Za-z]:#', $name)) {
            return false;
        }
        if (strpos($name, "\0") !== false) {
            return false;
        }
        $parts = explode('/', $name);
        foreach ($parts as $p) {
            if ($p === '..') {
                return false;
            }
        }
        // 目标路径必须落在 extractDir 下（写前用逻辑规范化校验，防 ZIP Slip）
        $target = $extractDir . '/' . $name;
        // 规范化：折叠 ./ 与多余斜杠，拒绝逃逸
        $norm = str_replace('\\', '/', $target);
        $segments = [];
        foreach (explode('/', $norm) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                return false;
            }
            $segments[] = $seg;
        }
        // Windows 盘符段保留
        $logical = (preg_match('#^[A-Za-z]:#', $extractDir) ? '' : '/') . implode('/', $segments);
        // 以 base 前缀约束（大小写不敏感比较 Windows）
        $baseCmp = strtolower($baseReal);
        $logCmp = strtolower(str_replace('\\', '/', $logical));
        // 还原 target 为 extractDir + name 的安全拼接
        $safeRel = [];
        foreach (explode('/', $name) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                return false;
            }
            $safeRel[] = $seg;
        }
        $target = $extractDir . '/' . implode('/', $safeRel);
        $isDir = substr($name, -1) === '/';
        if ($isDir) {
            if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
                return false;
            }
            $dirReal = realpath($target);
            if ($dirReal === false) {
                return false;
            }
            $dirReal = str_replace('\\', '/', $dirReal);
            if (strpos($dirReal, $baseReal . '/') !== 0 && $dirReal !== $baseReal) {
                return false;
            }
            continue;
        }
        $parent = dirname($target);
        if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
            return false;
        }
        $parentReal = realpath($parent);
        if ($parentReal === false) {
            return false;
        }
        $parentReal = str_replace('\\', '/', $parentReal);
        if (strpos($parentReal, $baseReal . '/') !== 0 && $parentReal !== $baseReal) {
            return false;
        }
        $content = $zip->getFromIndex($i);
        if ($content === false) {
            return false;
        }
        if (@file_put_contents($target, $content) === false) {
            return false;
        }
        // 写后 realpath 校验
        $writtenReal = realpath($target);
        if ($writtenReal === false) {
            @unlink($target);
            return false;
        }
        $writtenReal = str_replace('\\', '/', $writtenReal);
        if (strpos($writtenReal, $baseReal . '/') !== 0 && $writtenReal !== $baseReal) {
            @unlink($target);
            return false;
        }
    }
    return true;
}

/**
 * 复制更新包文件到站点（跳过保护路径）
 * @return array{ok:bool,copied:int,skipped:int,errors:string[],message:string}
 */
function updater_copy_package($packageRoot)
{
    $packageRoot = rtrim(str_replace('\\', '/', $packageRoot), '/');
    $copied = 0;
    $skipped = 0;
    $errors = [];

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($packageRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $file) {
        $full = str_replace('\\', '/', $file->getPathname());
        $rel = substr($full, strlen($packageRoot) + 1);
        if ($rel === false || $rel === '') {
            continue;
        }
        if (!updater_is_allowed_package_path($rel)) {
            $skipped++;
            continue;
        }
        $dest = ROOT_PATH . '/' . $rel;
        if ($file->isDir()) {
            if (!is_dir($dest) && !@mkdir($dest, 0755, true)) {
                $errors[] = '无法创建目录：' . $rel;
            }
            continue;
        }
        $destDir = dirname($dest);
        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
            $errors[] = '无法创建目录：' . dirname($rel);
            continue;
        }
        if (!@copy($full, $dest)) {
            // 尝试先删再写
            if (is_file($dest)) {
                @unlink($dest);
            }
            if (!@copy($full, $dest)) {
                $errors[] = '复制失败：' . $rel;
                continue;
            }
        }
        $copied++;
    }

    return [
        'ok' => $copied > 0 && count($errors) === 0,
        'copied' => $copied,
        'skipped' => $skipped,
        'errors' => $errors,
        'message' => $copied > 0
            ? ('已更新 ' . $copied . ' 个文件，跳过 ' . $skipped . ' 个')
            : '未复制任何文件',
    ];
}

/**
 * 更新后跑 schema 升级
 */
function updater_post_upgrade()
{
    $notes = [];
    try {
        if (function_exists('ensure_extra_tables')) {
            ensure_extra_tables();
            $notes[] = '已执行 ensure_extra_tables（扩展表）';
        }
    } catch (Throwable $e) {
        $notes[] = 'ensure_extra_tables 跳过：' . $e->getMessage();
    }
    return $notes;
}

/**
 * 本地安装所需环境（不依赖外网 / HTTP）
 * @return array{ok:bool,message:string,items:array}
 */
function updater_local_env_check()
{
    $items = [];
    $zipOk = class_exists('ZipArchive');
    $items[] = [
        'name' => 'PHP ZipArchive',
        'ok' => $zipOk,
        'detail' => $zipOk ? '可用' : '未启用 zip 扩展，无法解压更新包',
    ];
    $tmp = updater_tmp_dir();
    $tmpOk = is_dir($tmp) && is_writable($tmp);
    $items[] = [
        'name' => '临时目录可写',
        'ok' => $tmpOk,
        'detail' => $tmp,
    ];
    $rootOk = is_writable(ROOT_PATH);
    $items[] = [
        'name' => '站点根目录可写',
        'ok' => $rootOk,
        'detail' => $rootOk ? ROOT_PATH : '请给站点根目录写权限以便覆盖程序文件',
    ];
    $ok = true;
    $fail = [];
    foreach ($items as $it) {
        if (empty($it['ok'])) {
            $ok = false;
            $fail[] = $it['name'] . '：' . ($it['detail'] ?? '');
        }
    }
    return [
        'ok' => $ok,
        'message' => $ok ? '本地安装环境就绪' : ('环境不满足：' . implode('；', $fail)),
        'items' => $items,
    ];
}

/**
 * 从本地 zip 安装更新包（在线下载与后台导入共用）
 * @param string $zipFile 已落盘的 zip 绝对路径
 * @param array $meta 可选版本元数据 version/commit/sha/channel/source
 * @param bool $deleteZip 完成后是否删除 zip
 * @return array{ok:bool,message:string,detail:array}
 */
function updater_install_from_zip($zipFile, array $meta = [], $deleteZip = true)
{
    @set_time_limit(300);
    $zipFile = (string) $zipFile;
    if ($zipFile === '' || !is_file($zipFile)) {
        return ['ok' => false, 'message' => '更新包文件不存在', 'detail' => []];
    }
    if (!class_exists('ZipArchive')) {
        if ($deleteZip) {
            @unlink($zipFile);
        }
        return ['ok' => false, 'message' => '服务器未启用 ZipArchive 扩展', 'detail' => []];
    }

    $size = @filesize($zipFile);
    if ($size === false || $size < 64) {
        if ($deleteZip) {
            @unlink($zipFile);
        }
        return ['ok' => false, 'message' => '更新包过小或无法读取', 'detail' => ['size' => $size]];
    }
    // 最大 80MB，防止异常上传
    if ($size > 80 * 1024 * 1024) {
        if ($deleteZip) {
            @unlink($zipFile);
        }
        return ['ok' => false, 'message' => '更新包过大（超过 80MB）', 'detail' => ['size' => $size]];
    }

    $tmp = updater_tmp_dir();
    $extractDir = $tmp . '/extract_' . date('YmdHis') . '_' . mt_rand(1000, 9999);

    $zip = new ZipArchive();
    $zopen = $zip->open($zipFile);
    if ($zopen !== true) {
        if ($deleteZip) {
            @unlink($zipFile);
        }
        return ['ok' => false, 'message' => '无法打开更新包（Zip 错误码 ' . $zopen . '）', 'detail' => []];
    }
    if (!@mkdir($extractDir, 0755, true) && !is_dir($extractDir)) {
        $zip->close();
        if ($deleteZip) {
            @unlink($zipFile);
        }
        return ['ok' => false, 'message' => '无法创建解压目录', 'detail' => []];
    }
    // Zip Slip 防护：先校验所有条目路径，再安全解压
    $extractOk = updater_safe_extract($zip, $extractDir);
    $zip->close();
    if (!$extractOk) {
        updater_rrmdir($extractDir);
        if ($deleteZip) {
            @unlink($zipFile);
        }
        return ['ok' => false, 'message' => '解压更新包失败（含非法路径或写入错误）', 'detail' => []];
    }

    $root = updater_find_package_root($extractDir);
    if ($root === null) {
        updater_rrmdir($extractDir);
        if ($deleteZip) {
            @unlink($zipFile);
        }
        return ['ok' => false, 'message' => '更新包结构无效（找不到项目根）', 'detail' => []];
    }

    // 简单完整性：必须含 bootstrap
    if (!is_file($root . '/includes/bootstrap.php')) {
        updater_rrmdir($extractDir);
        if ($deleteZip) {
            @unlink($zipFile);
        }
        return ['ok' => false, 'message' => '更新包不完整：缺少 includes/bootstrap.php', 'detail' => []];
    }

    $copy = updater_copy_package($root);
    $notes = updater_post_upgrade();

    // 更新本地版本号
    $local = updater_local_version();
    $newVer = [
        'version' => (string) ($meta['version'] ?? $local['version'] ?? '0.0.0'),
        'commit' => (string) ($meta['commit'] ?? ''),
        'build' => date('Ymd'),
    ];
    if ($newVer['commit'] === '' && !empty($meta['sha'])) {
        $newVer['commit'] = substr((string) $meta['sha'], 0, 7);
    }
    // 若包内带 version.php 以包为准
    $pkgVerFile = $root . '/config/version.php';
    if (is_file($pkgVerFile)) {
        $pv = include $pkgVerFile;
        if (is_array($pv)) {
            if (!empty($pv['version'])) {
                $newVer['version'] = (string) $pv['version'];
            }
            if (!empty($pv['name'])) {
                $newVer['name'] = (string) $pv['name'];
            }
            if (!empty($pv['commit']) && $newVer['commit'] === '') {
                $newVer['commit'] = (string) $pv['commit'];
            }
        }
    }
    if ($newVer['commit'] === '') {
        $newVer['commit'] = 'import-' . date('YmdHis');
    }
    updater_write_version($newVer);

    // 清理
    updater_rrmdir($extractDir);
    if ($deleteZip) {
        @unlink($zipFile);
    }
    // 清远程检测缓存
    $cacheMeta = updater_tmp_dir() . '/remote_meta.json';
    if (is_file($cacheMeta)) {
        @unlink($cacheMeta);
    }

    $ok = !empty($copy['ok']) && empty($copy['errors']);
    $msg = $copy['message'] ?? '';
    if (!empty($copy['errors'])) {
        $msg .= '；部分错误：' . implode('；', array_slice($copy['errors'], 0, 5));
    }
    $source = (string) ($meta['source'] ?? 'package');
    if ($ok) {
        $msg = ($source === 'upload' ? '导入更新完成。' : '系统更新完成。') . $msg;
    } else {
        $msg = '更新未完全成功。' . $msg;
    }

    return [
        'ok' => $ok,
        'message' => $msg,
        'detail' => [
            'copied' => $copy['copied'] ?? 0,
            'skipped' => $copy['skipped'] ?? 0,
            'errors' => $copy['errors'] ?? [],
            'version' => $newVer,
            'notes' => $notes,
            'source' => $source,
            'remote' => [
                'version' => $meta['version'] ?? ($newVer['version'] ?? ''),
                'commit' => $meta['commit'] ?? ($newVer['commit'] ?? ''),
                'channel' => $meta['channel'] ?? '',
            ],
        ],
    ];
}

/**
 * 执行在线更新
 * @param array|null $remote 可选，缺省则重新检测
 * @return array{ok:bool,message:string,detail:array}
 */
function updater_apply(?array $remote = null)
{
    @set_time_limit(300);
    $env = updater_env_check();
    if (empty($env['ok'])) {
        $fail = [];
        foreach ($env['items'] as $it) {
            if (empty($it['ok'])) {
                $fail[] = $it['name'] . '：' . ($it['detail'] ?? '');
            }
        }
        return ['ok' => false, 'message' => '环境不满足更新条件：' . implode('；', $fail), 'detail' => ['env' => $env]];
    }

    if ($remote === null || empty($remote['ok'])) {
        $remote = updater_fetch_remote(true);
    }
    if (empty($remote['ok'])) {
        return ['ok' => false, 'message' => $remote['message'] ?? '无法获取远程版本', 'detail' => $remote];
    }
    if (empty($remote['update_available'])) {
        return ['ok' => false, 'message' => '当前已是最新版本，无需更新', 'detail' => $remote];
    }

    $zipUrl = (string) ($remote['zip_url'] ?? '');
    if ($zipUrl === '' || !preg_match('#^https://#i', $zipUrl)) {
        return ['ok' => false, 'message' => '无效的更新包地址', 'detail' => $remote];
    }

    $tmp = updater_tmp_dir();
    $zipFile = $tmp . '/package_' . date('YmdHis') . '.zip';

    $dl = updater_http_download($zipUrl, $zipFile, 180);
    if (empty($dl['ok'])) {
        return ['ok' => false, 'message' => '下载更新包失败：' . ($dl['error'] ?? ''), 'detail' => $dl];
    }

    $meta = [
        'version' => $remote['version'] ?? '',
        'commit' => $remote['commit'] ?? '',
        'sha' => $remote['sha'] ?? '',
        'channel' => $remote['channel'] ?? '',
        'source' => 'github',
    ];
    return updater_install_from_zip($zipFile, $meta, true);
}

/**
 * 后台导入本地 zip 更新包
 * @param array $file $_FILES['package'] 结构
 * @return array{ok:bool,message:string,detail:array}
 */
function updater_apply_from_upload(array $file)
{
    @set_time_limit(300);
    $env = updater_local_env_check();
    if (empty($env['ok'])) {
        return ['ok' => false, 'message' => $env['message'], 'detail' => ['env' => $env]];
    }

    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        $map = [
            UPLOAD_ERR_INI_SIZE => '文件超过 php.ini 限制（upload_max_filesize）',
            UPLOAD_ERR_FORM_SIZE => '文件超过表单限制',
            UPLOAD_ERR_PARTIAL => '文件仅部分上传',
            UPLOAD_ERR_NO_FILE => '未选择更新包文件',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '无法写入临时文件',
            UPLOAD_ERR_EXTENSION => '扩展阻止了文件上传',
        ];
        return ['ok' => false, 'message' => $map[$err] ?? ('上传失败，错误码 ' . $err), 'detail' => ['error' => $err]];
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $origName = (string) ($file['name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['ok' => false, 'message' => '无效的上传文件', 'detail' => []];
    }
    if ($size <= 0 || $size > 80 * 1024 * 1024) {
        return ['ok' => false, 'message' => '更新包大小无效（需大于 0 且不超过 80MB）', 'detail' => ['size' => $size]];
    }

    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext !== 'zip') {
        return ['ok' => false, 'message' => '仅支持 .zip 更新包', 'detail' => ['name' => $origName]];
    }

    // 简单魔数校验 PK\x03\x04 / PK\x05\x06 / PK\x07\x08
    $fh = @fopen($tmpName, 'rb');
    $magic = $fh ? (string) fread($fh, 4) : '';
    if ($fh) {
        fclose($fh);
    }
    if ($magic === '' || strpos($magic, 'PK') !== 0) {
        return ['ok' => false, 'message' => '文件不是有效的 ZIP 包', 'detail' => []];
    }

    $dest = updater_tmp_dir() . '/upload_' . date('YmdHis') . '_' . mt_rand(1000, 9999) . '.zip';
    if (!@move_uploaded_file($tmpName, $dest)) {
        // 回退：部分环境 is_uploaded_file 通过但 move 失败时尝试 copy
        if (!@copy($tmpName, $dest)) {
            return ['ok' => false, 'message' => '无法保存上传的更新包到临时目录', 'detail' => []];
        }
        @unlink($tmpName);
    }

    $meta = [
        'source' => 'upload',
        'version' => '',
        'commit' => '',
        'channel' => 'import',
        'original_name' => $origName,
    ];
    return updater_install_from_zip($dest, $meta, true);
}

/**
 * 手动同步通道（master / release）
 */
function updater_set_channel($channel)
{
    $channel = $channel === 'release' ? 'release' : 'master';
    return updater_write_version(['channel' => $channel]);
}
