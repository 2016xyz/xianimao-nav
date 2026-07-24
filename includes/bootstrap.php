<?php
/**
 * 公共引导：安装检测、会话、数据库内容读写
 */

define('ROOT_PATH', dirname(__DIR__));
define('HOT_BOARDS_FILE', ROOT_PATH . '/config/hot_boards.php');
define('AUTH_FILE', ROOT_PATH . '/config/auth.php'); // 兼容旧文件，已改用数据库 admins 表

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/install.php';
require_once __DIR__ . '/db.php';

// Session Cookie 加固 + 安全响应头（CSP / XSS / 点击劫持等）
security_configure_session();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
security_send_headers();

// 未安装则跳转安装页（安装程序自身除外）
require_installed_or_redirect();

/**
 * 默认内容结构
 */
function default_content()
{
    return [
        'site' => [
            'name' => '夏尼猫网址导航',
            'subtitle' => '实用工具与优质站点聚合',
            'footer' => '© ' . date('Y') . ' 夏尼猫网址导航',
            'footer_extra' => '',
            'footer_show_apply' => '1',
            'footer_show_message' => '1',
            'footer_show_about' => '1',
            'footer_show_contact' => '1',
            'footer_links' => [],
            'show_friend_links' => '1',
            'enable_message' => '1',
            'about_html' => '<p>夏尼猫网址导航汇集实用工具、开源项目与优质站点，帮助你更快找到需要的资源。</p>',
            'contact_html' => '<p>如有合作、建议或问题，欢迎通过邮件 <a href="mailto:i@2016xlx.cn">i@2016xlx.cn</a> 或留言联系我们。</p>',
            'contact_email' => 'i@2016xlx.cn',
        ],
        'engines' => [],
        'shortcuts' => [],
        'sites' => [],
        'projects' => [],
        'tools' => [],
        'links' => [],
    ];
}

/**
 * 从旧 JSON 读取（数据库不可用时的回退）
 */
function load_content_from_json()
{
    $file = ROOT_PATH . '/data/content.json';
    $data = default_content();
    if (!is_file($file)) {
        return $data;
    }
    $json = json_decode(file_get_contents($file), true);
    if (!is_array($json)) {
        return $data;
    }
    if (!empty($json['site']) && is_array($json['site'])) {
        $data['site'] = array_merge($data['site'], $json['site']);
    }
    foreach (['engines', 'shortcuts', 'sites', 'projects', 'tools', 'links'] as $key) {
        if (!empty($json[$key]) && is_array($json[$key])) {
            $data[$key] = $json[$key];
        }
    }
    return $data;
}

/**
 * 确保扩展表存在（已安装站点升级兼容）
 */
function ensure_extra_tables()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();
        $file = ROOT_PATH . '/includes/schema_extra.sql';
        if (!is_file($file)) {
            return;
        }
        $sql = file_get_contents($file);
        $parts = preg_split('/;\s*[\r\n]+/', $sql);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || strpos($part, '--') === 0) {
                continue;
            }
            $pdo->exec($part);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * 读写 settings 单项
 */
function setting_get($key, $default = '')
{
    try {
        ensure_extra_tables();
        $stmt = db()->prepare('SELECT svalue FROM settings WHERE skey = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row && array_key_exists('svalue', $row)) {
            return $row['svalue'];
        }
    } catch (Throwable $e) {
        // fallthrough
    }
    $file = ROOT_PATH . '/config/site_extra.json';
    if (is_file($file)) {
        $json = json_decode(file_get_contents($file), true);
        if (is_array($json) && array_key_exists($key, $json)) {
            return $json[$key];
        }
    }
    return $default;
}

function setting_set($key, $value)
{
    $okDb = false;
    try {
        ensure_extra_tables();
        $stmt = db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
        $okDb = $stmt->execute([$key, (string) $value]);
    } catch (Throwable $e) {
        $okDb = false;
    }

    $file = ROOT_PATH . '/config/site_extra.json';
    $json = [];
    if (is_file($file)) {
        $tmp = json_decode(file_get_contents($file), true);
        if (is_array($tmp)) {
            $json = $tmp;
        }
    }
    $json[$key] = (string) $value;
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $okFile = @file_put_contents($file, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n", LOCK_EX) !== false;
    return $okDb || $okFile;
}

function setting_bool($key, $default = true)
{
    $v = setting_get($key, $default ? '1' : '0');
    return $v === '1' || $v === 1 || $v === true || $v === 'true' || $v === 'on';
}

/**
 * 从数据库读取可管理内容
 */
function load_content()
{
    try {
        $pdo = db();
        ensure_extra_tables();
    } catch (Throwable $e) {
        return load_content_from_json();
    }

    $data = default_content();
    $jsonFallback = null;
    $needJson = static function () use (&$jsonFallback) {
        if ($jsonFallback === null) {
            $jsonFallback = load_content_from_json();
        }
        return $jsonFallback;
    };

    // settings
    $rows = $pdo->query('SELECT skey, svalue FROM settings')->fetchAll();
    $map = [];
    foreach ($rows as $r) {
        $map[$r['skey']] = $r['svalue'];
    }
    // 库中无站点配置时合并 JSON，避免双写分裂导致「内容丢失」
    if (empty($map['site_name'])) {
        $jf = $needJson();
        if (!empty($jf['site']['name'])) {
            $map['site_name'] = $map['site_name'] ?? $jf['site']['name'];
            $map['site_subtitle'] = $map['site_subtitle'] ?? ($jf['site']['subtitle'] ?? '');
            $map['site_footer'] = $map['site_footer'] ?? ($jf['site']['footer'] ?? '');
            $map['about_html'] = $map['about_html'] ?? ($jf['site']['about_html'] ?? '');
            $map['contact_html'] = $map['contact_html'] ?? ($jf['site']['contact_html'] ?? '');
            $map['contact_email'] = $map['contact_email'] ?? ($jf['site']['contact_email'] ?? '');
        }
    }
    $footerLinks = [];
    if (!empty($map['footer_links_json'])) {
        $decoded = json_decode((string) $map['footer_links_json'], true);
        if (is_array($decoded)) {
            $footerLinks = $decoded;
        }
    }
    $data['site'] = [
        'name' => $map['site_name'] ?? $data['site']['name'],
        'subtitle' => $map['site_subtitle'] ?? $data['site']['subtitle'],
        'footer' => $map['site_footer'] ?? $data['site']['footer'],
        'footer_extra' => $map['footer_extra'] ?? ($data['site']['footer_extra'] ?? ''),
        'footer_show_apply' => $map['footer_show_apply'] ?? ($data['site']['footer_show_apply'] ?? '1'),
        'footer_show_message' => $map['footer_show_message'] ?? ($data['site']['footer_show_message'] ?? '1'),
        'footer_show_about' => $map['footer_show_about'] ?? ($data['site']['footer_show_about'] ?? '1'),
        'footer_show_contact' => $map['footer_show_contact'] ?? ($data['site']['footer_show_contact'] ?? '1'),
        'footer_links' => $footerLinks,
        'show_friend_links' => $map['show_friend_links'] ?? ($data['site']['show_friend_links'] ?? '1'),
        'enable_message' => $map['enable_message'] ?? ($data['site']['enable_message'] ?? '1'),
        'about_html' => $map['about_html'] ?? ($data['site']['about_html'] ?? ''),
        'contact_html' => $map['contact_html'] ?? ($data['site']['contact_html'] ?? ''),
        'contact_email' => $map['contact_email'] ?? ($data['site']['contact_email'] ?? ''),
    ];

    // engines
    $engines = $pdo->query('SELECT id, slug, name, url, sort_order FROM engines ORDER BY sort_order ASC, id ASC')->fetchAll();
    $data['engines'] = array_map(static function ($r) {
        return [
            'db_id' => (int) $r['id'],
            'id' => $r['slug'] !== '' ? $r['slug'] : ('e' . $r['id']),
            'name' => $r['name'],
            'url' => $r['url'],
        ];
    }, $engines);

    // shortcuts
    $shortcuts = $pdo->query('SELECT id, name, url, type, sort_order FROM shortcuts ORDER BY sort_order ASC, id ASC')->fetchAll();
    $data['shortcuts'] = array_map(static function ($r) {
        return [
            'db_id' => (int) $r['id'],
            'name' => $r['name'],
            'url' => $r['url'],
            'type' => $r['type'] ?: 'search',
        ];
    }, $shortcuts);

    // sites
    $sites = $pdo->query('SELECT id, name, description, url, tag, sort_order FROM sites ORDER BY sort_order ASC, id ASC')->fetchAll();
    $data['sites'] = array_map(static function ($r) {
        return [
            'db_id' => (int) $r['id'],
            'name' => $r['name'],
            'desc' => $r['description'],
            'url' => $r['url'],
            'tag' => $r['tag'],
        ];
    }, $sites);

    // projects
    $projects = $pdo->query('SELECT id, name, description, url, tag, sort_order FROM projects ORDER BY sort_order ASC, id ASC')->fetchAll();
    $data['projects'] = array_map(static function ($r) {
        return [
            'db_id' => (int) $r['id'],
            'name' => $r['name'],
            'desc' => $r['description'],
            'url' => $r['url'],
            'tag' => $r['tag'],
        ];
    }, $projects);

    // tools
    try {
        $tools = $pdo->query('SELECT id, name, description, url, sort_order FROM tools ORDER BY sort_order ASC, id ASC')->fetchAll();
        $data['tools'] = array_map(static function ($r) {
            return [
                'db_id' => (int) $r['id'],
                'id' => 't' . $r['id'],
                'name' => $r['name'],
                'desc' => $r['description'],
                'url' => $r['url'],
            ];
        }, $tools);
    } catch (Throwable $e) {
        $data['tools'] = [];
    }

    // friend links
    try {
        $links = $pdo->query('SELECT id, name, description, url, sort_order FROM links ORDER BY sort_order ASC, id ASC')->fetchAll();
        $data['links'] = array_map(static function ($r) {
            return [
                'db_id' => (int) $r['id'],
                'id' => 'l' . $r['id'],
                'name' => $r['name'],
                'desc' => $r['description'],
                'url' => $r['url'],
            ];
        }, $links);
    } catch (Throwable $e) {
        $data['links'] = [];
    }

    // 列表表为空时回退 JSON（仅填充空数组，不覆盖库中已有数据）
    $listKeys = ['engines', 'shortcuts', 'sites', 'projects', 'tools', 'links'];
    $emptyAll = true;
    foreach ($listKeys as $k) {
        if (!empty($data[$k])) {
            $emptyAll = false;
            break;
        }
    }
    if ($emptyAll) {
        $jf = $needJson();
        foreach ($listKeys as $k) {
            if (!empty($jf[$k]) && is_array($jf[$k])) {
                $data[$k] = $jf[$k];
            }
        }
    }

    return $data;
}

/**
 * 仅允许 http(s) 或站内相对路径（委托 security_url）
 */
function safe_http_url($url, $allowRelative = true)
{
    return security_url($url, $allowRelative);
}

/**
 * 规范化页脚自定义链接
 * @return array<int,array{name:string,url:string}>
 */
function normalize_footer_links($list)
{
    if (!is_array($list)) {
        return [];
    }
    $out = [];
    foreach ($list as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = security_clean_text($row['name'] ?? '', 80);
        $url = security_url(trim((string) ($row['url'] ?? '')), true);
        if ($name === '' || $url === '') {
            continue;
        }
        $out[] = ['name' => $name, 'url' => $url];
    }
    return $out;
}

/**
 * 消毒后台可编辑 HTML（委托 security_sanitize_html）
 */
function sanitize_admin_html($html)
{
    return security_sanitize_html($html);
}

/**
 * 页脚内置链接是否显示（结合全局留言开关）
 * @return array{apply:bool,message:bool,about:bool,contact:bool}
 */
function footer_builtin_visibility(array $site)
{
    $enableMessage = !isset($site['enable_message']) || ($site['enable_message'] !== '0' && $site['enable_message'] !== 0 && $site['enable_message'] !== false);
    $flag = static function ($key) use ($site) {
        return !isset($site[$key]) || ($site[$key] !== '0' && $site[$key] !== 0 && $site[$key] !== false);
    };
    return [
        'apply' => $flag('footer_show_apply'),
        'message' => $enableMessage && $flag('footer_show_message'),
        'about' => $flag('footer_show_about'),
        'contact' => $flag('footer_show_contact'),
    ];
}

/**
 * 将内容写入 content.json（无库或失败时的回退）
 */
function save_content_to_json(array $data)
{
    // 规范化 site 开关字段
    if (!isset($data['site']) || !is_array($data['site'])) {
        $data['site'] = default_content()['site'];
    }
    $data['site']['show_friend_links'] = !empty($data['site']['show_friend_links']) && $data['site']['show_friend_links'] !== '0' ? '1' : '0';
    $data['site']['enable_message'] = !empty($data['site']['enable_message']) && $data['site']['enable_message'] !== '0' ? '1' : '0';
    foreach (['footer_show_apply', 'footer_show_message', 'footer_show_about', 'footer_show_contact'] as $fk) {
        $data['site'][$fk] = !empty($data['site'][$fk]) && $data['site'][$fk] !== '0' ? '1' : '0';
    }
    if (!isset($data['site']['footer_extra'])) {
        $data['site']['footer_extra'] = '';
    }
    $data['site']['footer_links'] = normalize_footer_links($data['site']['footer_links'] ?? []);
    foreach (['engines', 'shortcuts', 'sites', 'projects', 'tools', 'links'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
    }
    $file = ROOT_PATH . '/data/content.json';
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return @file_put_contents(
        $file,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n",
        LOCK_EX
    ) !== false;
}

function save_content(array $data)
{
    try {
        $pdo = db();
    } catch (Throwable $e) {
        return save_content_to_json($data);
    }

    try {
        $pdo->beginTransaction();

        ensure_extra_tables();

        // settings
        $site = $data['site'] ?? [];
        $settings = [
            'site_name' => $site['name'] ?? '',
            'site_subtitle' => $site['subtitle'] ?? '',
            'site_footer' => $site['footer'] ?? '',
            'footer_extra' => $site['footer_extra'] ?? '',
            'footer_show_apply' => !empty($site['footer_show_apply']) && $site['footer_show_apply'] !== '0' ? '1' : '0',
            'footer_show_message' => !empty($site['footer_show_message']) && $site['footer_show_message'] !== '0' ? '1' : '0',
            'footer_show_about' => !empty($site['footer_show_about']) && $site['footer_show_about'] !== '0' ? '1' : '0',
            'footer_show_contact' => !empty($site['footer_show_contact']) && $site['footer_show_contact'] !== '0' ? '1' : '0',
            'footer_links_json' => json_encode(normalize_footer_links($site['footer_links'] ?? []), JSON_UNESCAPED_UNICODE),
            'show_friend_links' => !empty($site['show_friend_links']) && $site['show_friend_links'] !== '0' ? '1' : '0',
            'enable_message' => !empty($site['enable_message']) && $site['enable_message'] !== '0' ? '1' : '0',
            'about_html' => $site['about_html'] ?? '',
            'contact_html' => $site['contact_html'] ?? '',
            'contact_email' => $site['contact_email'] ?? '',
        ];
        $stmt = $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
        foreach ($settings as $k => $v) {
            $stmt->execute([$k, $v]);
        }

        // engines：清空后按顺序写入（保持简单一致）
        $pdo->exec('DELETE FROM engines');
        $es = $pdo->prepare('INSERT INTO engines (slug, name, url, sort_order) VALUES (?,?,?,?)');
        foreach (array_values($data['engines'] ?? []) as $i => $row) {
            $slug = $row['id'] ?? slugify($row['name'] ?? '');
            $es->execute([$slug, $row['name'] ?? '', $row['url'] ?? '', $i]);
        }

        $pdo->exec('DELETE FROM shortcuts');
        $ss = $pdo->prepare('INSERT INTO shortcuts (name, url, type, sort_order) VALUES (?,?,?,?)');
        foreach (array_values($data['shortcuts'] ?? []) as $i => $row) {
            $type = ($row['type'] ?? '') === 'link' ? 'link' : 'search';
            $ss->execute([$row['name'] ?? '', $row['url'] ?? '', $type, $i]);
        }

        $pdo->exec('DELETE FROM sites');
        $si = $pdo->prepare('INSERT INTO sites (name, description, url, tag, sort_order) VALUES (?,?,?,?,?)');
        foreach (array_values($data['sites'] ?? []) as $i => $row) {
            $si->execute([
                $row['name'] ?? '',
                $row['desc'] ?? '',
                $row['url'] ?? '',
                $row['tag'] ?? '',
                $i,
            ]);
        }

        $pdo->exec('DELETE FROM projects');
        $ps = $pdo->prepare('INSERT INTO projects (name, description, url, tag, sort_order) VALUES (?,?,?,?,?)');
        foreach (array_values($data['projects'] ?? []) as $i => $row) {
            $ps->execute([
                $row['name'] ?? '',
                $row['desc'] ?? '',
                $row['url'] ?? '',
                $row['tag'] ?? '',
                $i,
            ]);
        }

        $pdo->exec('DELETE FROM tools');
        $ts = $pdo->prepare('INSERT INTO tools (name, description, url, sort_order) VALUES (?,?,?,?)');
        foreach (array_values($data['tools'] ?? []) as $i => $row) {
            $ts->execute([
                $row['name'] ?? '',
                $row['desc'] ?? '',
                $row['url'] ?? '',
                $i,
            ]);
        }

        $pdo->exec('DELETE FROM links');
        $ls = $pdo->prepare('INSERT INTO links (name, description, url, sort_order) VALUES (?,?,?,?)');
        foreach (array_values($data['links'] ?? []) as $i => $row) {
            $ls->execute([
                $row['name'] ?? '',
                $row['desc'] ?? '',
                $row['url'] ?? '',
                $i,
            ]);
        }

        $pdo->commit();
        // 同步一份 JSON 作为回退
        save_content_to_json($data);
        return true;
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return save_content_to_json($data);
    }
}

/**
 * 保存留言 / 收录申请
 * type: message | apply
 */
function save_message(array $row)
{
    $type = $row['type'] ?? 'message';
    $payload = [
        'type' => in_array($type, ['message', 'apply'], true) ? $type : 'message',
        'name' => trim((string) ($row['name'] ?? '')),
        'contact' => trim((string) ($row['contact'] ?? '')),
        'email' => trim((string) ($row['email'] ?? '')),
        'website' => trim((string) ($row['website'] ?? '')),
        'content' => trim((string) ($row['content'] ?? '')),
        'status' => 'pending',
        'ip' => (string) ($row['ip'] ?? ''),
        'created_at' => date('Y-m-d H:i:s'),
    ];

    try {
        ensure_extra_tables();
        $stmt = db()->prepare(
            'INSERT INTO messages (type, name, contact, email, website, content, status, ip, created_at)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        return $stmt->execute([
            $payload['type'],
            $payload['name'],
            $payload['contact'],
            $payload['email'],
            $payload['website'],
            $payload['content'],
            $payload['status'],
            $payload['ip'],
            $payload['created_at'],
        ]);
    } catch (Throwable $e) {
        $file = ROOT_PATH . '/data/messages.json';
        $list = [];
        if (is_file($file)) {
            $tmp = json_decode(file_get_contents($file), true);
            if (is_array($tmp)) {
                $list = $tmp;
            }
        }
        $payload['id'] = 'm' . time() . mt_rand(100, 999);
        $list[] = $payload;
        return @file_put_contents($file, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n", LOCK_EX) !== false;
    }
}

/**
 * 列出留言
 */
function list_messages($type = null, $limit = 200)
{
    $limit = security_sql_limit($limit, 200, 500);
    $typeFilter = null;
    if ($type !== null && $type !== '') {
        $typeFilter = security_enum((string) $type, ['message', 'apply']);
        if ($typeFilter === null) {
            return [];
        }
    }
    try {
        ensure_extra_tables();
        if ($typeFilter !== null) {
            // LIMIT 仅使用已校验整数，禁止用户字符串拼接
            $stmt = db()->prepare('SELECT * FROM messages WHERE type = ? ORDER BY id DESC LIMIT ' . $limit);
            $stmt->execute([$typeFilter]);
        } else {
            $stmt = db()->query('SELECT * FROM messages ORDER BY id DESC LIMIT ' . $limit);
        }
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        $file = ROOT_PATH . '/data/messages.json';
        if (!is_file($file)) {
            return [];
        }
        $list = json_decode(file_get_contents($file), true);
        if (!is_array($list)) {
            return [];
        }
        if ($typeFilter !== null) {
            $list = array_values(array_filter($list, static function ($r) use ($typeFilter) {
                return ($r['type'] ?? '') === $typeFilter;
            }));
        }
        usort($list, static function ($a, $b) {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });
        return array_slice($list, 0, $limit);
    }
}

function update_message_status($id, $status)
{
    $status = security_enum((string) $status, ['pending', 'read', 'done']);
    if ($status === null) {
        $status = 'read';
    }
    try {
        $stmt = db()->prepare('UPDATE messages SET status = ? WHERE id = ?');
        return $stmt->execute([$status, (int) $id]);
    } catch (Throwable $e) {
        $file = ROOT_PATH . '/data/messages.json';
        if (!is_file($file)) {
            return false;
        }
        $list = json_decode(file_get_contents($file), true);
        if (!is_array($list)) {
            return false;
        }
        $ok = false;
        foreach ($list as &$row) {
            if ((string) ($row['id'] ?? '') === (string) $id) {
                $row['status'] = $status;
                $ok = true;
                break;
            }
        }
        unset($row);
        if (!$ok) {
            return false;
        }
        return @file_put_contents($file, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n", LOCK_EX) !== false;
    }
}

function delete_message($id)
{
    try {
        $stmt = db()->prepare('DELETE FROM messages WHERE id = ?');
        return $stmt->execute([(int) $id]);
    } catch (Throwable $e) {
        $file = ROOT_PATH . '/data/messages.json';
        if (!is_file($file)) {
            return false;
        }
        $list = json_decode(file_get_contents($file), true);
        if (!is_array($list)) {
            return false;
        }
        $new = array_values(array_filter($list, static function ($row) use ($id) {
            return (string) ($row['id'] ?? '') !== (string) $id;
        }));
        return @file_put_contents($file, json_encode($new, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n", LOCK_EX) !== false;
    }
}

/**
 * 读取管理员（数据库）
 * @return array|null
 */
function load_admin_by_username($username)
{
    try {
        $stmt = db()->prepare('SELECT id, username, password_hash FROM admins WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * 更新管理员密码
 */
function update_admin_password($username, $newHash)
{
    $stmt = db()->prepare('UPDATE admins SET password_hash = ? WHERE username = ?');
    return $stmt->execute([$newHash, $username]);
}

/**
 * 读取热榜：真实接口 + 本地缓存
 */
function load_hot_boards()
{
    require_once __DIR__ . '/hot_fetcher.php';
    $live = load_hot_boards_live();
    $hasData = false;
    foreach ($live as $board) {
        if (!empty($board['items'])) {
            $hasData = true;
            break;
        }
    }
    if ($hasData) {
        return $live;
    }

    if (is_file(HOT_BOARDS_FILE)) {
        $boards = require HOT_BOARDS_FILE;
        return is_array($boards) ? $boards : [];
    }
    return $live;
}

/**
 * 组装前台完整数据
 */
function load_site_data()
{
    $content = load_content();
    $content['hot_boards'] = load_hot_boards();
    return $content;
}

function e($str)
{
    return security_escape($str);
}

function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf()
{
    $token = $_POST['csrf_token'] ?? ($_POST['_csrf'] ?? '');
    $session = $_SESSION['csrf_token'] ?? '';
    return $token !== '' && $session !== '' && hash_equals($session, $token);
}

/** 兼容别名 */
function csrf_verify($token = null)
{
    if ($token === null) {
        return verify_csrf();
    }
    $session = $_SESSION['csrf_token'] ?? '';
    return $token !== '' && $session !== '' && hash_equals($session, (string) $token);
}

function is_logged_in()
{
    return !empty($_SESSION['admin_logged_in']);
}

function require_login()
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function flash_set($type, $message)
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get()
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function redirect($url)
{
    $url = (string) $url;
    // 仅允许相对路径或站内 .php，防止开放重定向
    if (preg_match('#^(https?:)?//#i', $url) || strpos($url, "\n") !== false || strpos($url, "\r") !== false) {
        $url = 'index.php';
    }
    header('Location: ' . $url);
    exit;
}

function slugify($text)
{
    $text = strtolower(trim((string) $text));
    $text = preg_replace('/[^a-z0-9]+/', '_', $text);
    $text = trim($text, '_');
    return $text !== '' ? $text : 'item_' . substr(md5(uniqid('', true)), 0, 6);
}

/**
 * 站点 favicon（与参考站相同服务）
 */
function site_favicon_url($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return 'assets/images/github.png';
    }
    // 提取站点根用于 favicon 服务
    $parts = parse_url($url);
    if (!empty($parts['scheme']) && !empty($parts['host'])) {
        $base = $parts['scheme'] . '://' . $parts['host'] . '/';
        return 'https://favicon.cccyun.cc/' . $base;
    }
    return 'https://favicon.cccyun.cc/' . $url;
}

/**
 * 搜索 Tab 本地图标
 */
function search_tab_icon($key)
{
    $key = strtolower(trim((string) $key));
    $map = [
        'baidu' => 'baidu.png',
        '百度' => 'baidu.png',
        'google' => 'google.png',
        '谷歌' => 'google.png',
        'sogou' => 'sogou.png',
        '搜狗' => 'sogou.png',
        'zhihu' => 'zhihu.png',
        '知乎' => 'zhihu.png',
        'github' => 'github.png',
        '音乐' => 'wyy.png',
        'wyy' => 'wyy.png',
        'netease' => 'wyy.png',
        '图书' => 'douban.png',
        'douban' => 'douban.png',
        '豆瓣' => 'douban.png',
        '影视' => 'bilibili.png',
        'bilibili' => 'bilibili.png',
        'b站' => 'bilibili.png',
    ];
    $file = $map[$key] ?? null;
    if (!$file) {
        foreach ($map as $k => $f) {
            if ($k !== '' && (strpos($key, $k) !== false || strpos($k, $key) !== false)) {
                $file = $f;
                break;
            }
        }
    }
    if ($file && is_file(ROOT_PATH . '/assets/images/' . $file)) {
        return 'assets/images/' . $file;
    }
    return '';
}

function move_list_item(array $list, $index, $direction)
{
    $index = (int) $index;
    $count = count($list);
    if ($index < 0 || $index >= $count) {
        return $list;
    }
    $newIndex = $direction === 'up' ? $index - 1 : $index + 1;
    if ($newIndex < 0 || $newIndex >= $count) {
        return $list;
    }
    $tmp = $list[$index];
    $list[$index] = $list[$newIndex];
    $list[$newIndex] = $tmp;
    return $list;
}
