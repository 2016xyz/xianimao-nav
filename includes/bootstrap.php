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
            // 首页 Hero 背景图（空 = 默认 assets/images/background.avif）
            'hero_bg' => '',
            // SEO
            'seo_title' => '',
            'seo_keywords' => '网址导航,实用工具,开源项目,热榜,搜索聚合,夏尼猫',
            'seo_description' => '夏尼猫网址导航：搜索聚合、今日热榜、实用工具与优质站点一站直达。',
            'seo_author' => '夏尼猫',
            'seo_robots' => 'index,follow',
            'seo_canonical' => '',
            'seo_og_image' => '',
            'seo_baidu_verify' => '',
            'seo_google_verify' => '',
            'seo_bing_verify' => '',
            'seo_head_html' => '',
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
    // SEO 等扩展字段：库中缺失时从 JSON 补齐（升级兼容）
    $extraSiteKeys = [
        'hero_bg',
        'seo_title',
        'seo_keywords',
        'seo_description',
        'seo_author',
        'seo_robots',
        'seo_canonical',
        'seo_og_image',
        'seo_baidu_verify',
        'seo_google_verify',
        'seo_bing_verify',
        'seo_head_html',
    ];
    $needExtraFallback = false;
    foreach ($extraSiteKeys as $sk) {
        if (!array_key_exists($sk, $map) || $map[$sk] === null) {
            $needExtraFallback = true;
            break;
        }
    }
    if ($needExtraFallback) {
        $jf = $needJson();
        foreach ($extraSiteKeys as $sk) {
            if (!array_key_exists($sk, $map) || $map[$sk] === null) {
                if (isset($jf['site'][$sk])) {
                    $map[$sk] = $jf['site'][$sk];
                }
            }
        }
    }
    $footerLinks = [];
    if (!empty($map['footer_links_json'])) {
        $decoded = json_decode((string) $map['footer_links_json'], true);
        if (is_array($decoded)) {
            $footerLinks = $decoded;
        }
    }
    // 在默认值上合并，避免遗漏 SEO 等扩展字段
    $data['site'] = array_merge($data['site'], [
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
        'hero_bg' => $map['hero_bg'] ?? ($data['site']['hero_bg'] ?? ''),
        'seo_title' => $map['seo_title'] ?? ($data['site']['seo_title'] ?? ''),
        'seo_keywords' => $map['seo_keywords'] ?? ($data['site']['seo_keywords'] ?? ''),
        'seo_description' => $map['seo_description'] ?? ($data['site']['seo_description'] ?? ''),
        'seo_author' => $map['seo_author'] ?? ($data['site']['seo_author'] ?? ''),
        'seo_robots' => site_seo_normalize_robots($map['seo_robots'] ?? ($data['site']['seo_robots'] ?? 'index,follow')),
        'seo_canonical' => $map['seo_canonical'] ?? ($data['site']['seo_canonical'] ?? ''),
        'seo_og_image' => $map['seo_og_image'] ?? ($data['site']['seo_og_image'] ?? ''),
        'seo_baidu_verify' => $map['seo_baidu_verify'] ?? ($data['site']['seo_baidu_verify'] ?? ''),
        'seo_google_verify' => $map['seo_google_verify'] ?? ($data['site']['seo_google_verify'] ?? ''),
        'seo_bing_verify' => $map['seo_bing_verify'] ?? ($data['site']['seo_bing_verify'] ?? ''),
        'seo_head_html' => $map['seo_head_html'] ?? ($data['site']['seo_head_html'] ?? ''),
    ]);

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
            'id' => 'p' . $r['id'],
            'name' => $r['name'],
            'desc' => $r['description'],
            'url' => $r['url'],
            'tag' => $r['tag'] ?? '',
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
            'hero_bg' => $site['hero_bg'] ?? '',
            'seo_title' => $site['seo_title'] ?? '',
            'seo_keywords' => $site['seo_keywords'] ?? '',
            'seo_description' => $site['seo_description'] ?? '',
            'seo_author' => $site['seo_author'] ?? '',
            'seo_robots' => site_seo_normalize_robots($site['seo_robots'] ?? 'index,follow'),
            'seo_canonical' => $site['seo_canonical'] ?? '',
            'seo_og_image' => $site['seo_og_image'] ?? '',
            'seo_baidu_verify' => $site['seo_baidu_verify'] ?? '',
            'seo_google_verify' => $site['seo_google_verify'] ?? '',
            'seo_bing_verify' => $site['seo_bing_verify'] ?? '',
            'seo_head_html' => $site['seo_head_html'] ?? '',
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
 * 后台操作日志：允许的 level
 * @return string[]
 */
function admin_log_levels()
{
    return ['info', 'success', 'warning', 'error'];
}

/**
 * 写入后台操作 / 审计日志（失败静默，不影响主流程）
 *
 * @param string $action  机器可读动作，如 login_ok / settings_save
 * @param string $message 人类可读摘要
 * @param array  $opts    module, level, detail(array|string), username, admin_id
 * @return bool
 */
function admin_log_write($action, $message, array $opts = [])
{
    $action = security_clean_text((string) $action, 64);
    if ($action === '') {
        $action = 'unknown';
    }
    $message = security_clean_text((string) $message, 500);
    if ($message === '') {
        $message = $action;
    }
    $module = security_clean_text((string) ($opts['module'] ?? 'system'), 40);
    if ($module === '') {
        $module = 'system';
    }
    $level = security_enum((string) ($opts['level'] ?? 'info'), admin_log_levels()) ?: 'info';

    $username = (string) ($opts['username'] ?? ($_SESSION['admin_username'] ?? ''));
    $username = security_clean_text($username, 64);
    $adminId = isset($opts['admin_id'])
        ? (int) $opts['admin_id']
        : (int) ($_SESSION['admin_id'] ?? 0);
    if ($adminId < 0) {
        $adminId = 0;
    }

    $detail = $opts['detail'] ?? '';
    if (is_array($detail)) {
        // 避免把密钥类字段写入日志
        $redactKeys = ['password', 'pass', 'api_key', 'client_secret', 'cookie', 'secret', 'token', 'auth'];
        $safe = [];
        foreach ($detail as $k => $v) {
            $lk = strtolower((string) $k);
            $redact = false;
            foreach ($redactKeys as $rk) {
                if ($lk === $rk || strpos($lk, $rk) !== false) {
                    $redact = true;
                    break;
                }
            }
            if ($redact) {
                $safe[$k] = '[redacted]';
            } elseif (is_scalar($v) || $v === null) {
                $safe[$k] = $v;
            } else {
                $safe[$k] = '[object]';
            }
        }
        $detail = json_encode($safe, JSON_UNESCAPED_UNICODE);
        if ($detail === false) {
            $detail = '';
        }
    } else {
        $detail = security_clean_text((string) $detail, 2000);
    }
    if (function_exists('mb_strlen') && mb_strlen($detail, 'UTF-8') > 4000) {
        $detail = mb_substr($detail, 0, 4000, 'UTF-8');
    } elseif (strlen($detail) > 4000) {
        $detail = substr($detail, 0, 4000);
    }

    $ip = security_ip();
    $ua = security_clean_text((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 255);
    $createdAt = date('Y-m-d H:i:s');

    $row = [
        'admin_id' => $adminId,
        'username' => $username,
        'action' => $action,
        'module' => $module,
        'level' => $level,
        'message' => $message,
        'detail' => $detail,
        'ip' => $ip,
        'user_agent' => $ua,
        'created_at' => $createdAt,
    ];

    try {
        ensure_extra_tables();
        $stmt = db()->prepare(
            'INSERT INTO admin_logs (admin_id, username, action, module, level, message, detail, ip, user_agent, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        return $stmt->execute([
            $row['admin_id'],
            $row['username'],
            $row['action'],
            $row['module'],
            $row['level'],
            $row['message'],
            $row['detail'],
            $row['ip'],
            $row['user_agent'],
            $row['created_at'],
        ]);
    } catch (Throwable $e) {
        // JSON 文件回退（数据库不可用时）
        $file = ROOT_PATH . '/data/admin_logs.json';
        $list = [];
        if (is_file($file)) {
            $tmp = json_decode((string) file_get_contents($file), true);
            if (is_array($tmp)) {
                $list = $tmp;
            }
        }
        $row['id'] = 'L' . time() . mt_rand(100, 999);
        $list[] = $row;
        // 最多保留 2000 条
        if (count($list) > 2000) {
            $list = array_slice($list, -2000);
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return @file_put_contents(
            $file,
            json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n",
            LOCK_EX
        ) !== false;
    }
}

/**
 * 查询后台日志
 *
 * @param array $filters module, action, level, username, q, date_from, date_to
 * @param int   $page
 * @param int   $perPage
 * @return array{items:array,total:int,page:int,per_page:int,pages:int}
 */
function admin_log_list(array $filters = [], $page = 1, $perPage = 50)
{
    $page = max(1, (int) $page);
    $perPage = security_sql_limit($perPage, 50, 100);
    $offset = ($page - 1) * $perPage;

    $module = security_clean_text((string) ($filters['module'] ?? ''), 40);
    $action = security_clean_text((string) ($filters['action'] ?? ''), 64);
    $level = security_enum((string) ($filters['level'] ?? ''), admin_log_levels());
    $username = security_clean_text((string) ($filters['username'] ?? ''), 64);
    $q = security_clean_text((string) ($filters['q'] ?? ''), 100);
    $dateFrom = (string) ($filters['date_from'] ?? '');
    $dateTo = (string) ($filters['date_to'] ?? '');
    if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateFrom = '';
    }
    if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $dateTo = '';
    }

    $where = [];
    $params = [];
    if ($module !== '') {
        $where[] = 'module = ?';
        $params[] = $module;
    }
    if ($action !== '') {
        $where[] = 'action = ?';
        $params[] = $action;
    }
    if ($level !== null && $level !== '') {
        $where[] = 'level = ?';
        $params[] = $level;
    }
    if ($username !== '') {
        $where[] = 'username = ?';
        $params[] = $username;
    }
    if ($q !== '') {
        $where[] = '(message LIKE ? OR action LIKE ? OR detail LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($dateFrom !== '') {
        $where[] = 'created_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $where[] = 'created_at <= ?';
        $params[] = $dateTo . ' 23:59:59';
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    try {
        ensure_extra_tables();
        $pdo = db();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM admin_logs ' . $whereSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $sql = 'SELECT * FROM admin_logs ' . $whereSql . ' ORDER BY id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        $file = ROOT_PATH . '/data/admin_logs.json';
        $list = [];
        if (is_file($file)) {
            $tmp = json_decode((string) file_get_contents($file), true);
            if (is_array($tmp)) {
                $list = $tmp;
            }
        }
        $list = array_values(array_filter($list, static function ($r) use ($module, $action, $level, $username, $q, $dateFrom, $dateTo) {
            if ($module !== '' && ($r['module'] ?? '') !== $module) {
                return false;
            }
            if ($action !== '' && ($r['action'] ?? '') !== $action) {
                return false;
            }
            if ($level !== null && $level !== '' && ($r['level'] ?? '') !== $level) {
                return false;
            }
            if ($username !== '' && ($r['username'] ?? '') !== $username) {
                return false;
            }
            if ($q !== '') {
                $hay = ($r['message'] ?? '') . ' ' . ($r['action'] ?? '') . ' ' . ($r['detail'] ?? '');
                if (function_exists('mb_stripos')) {
                    if (mb_stripos($hay, $q, 0, 'UTF-8') === false) {
                        return false;
                    }
                } elseif (stripos($hay, $q) === false) {
                    return false;
                }
            }
            $ca = (string) ($r['created_at'] ?? '');
            if ($dateFrom !== '' && $ca < $dateFrom . ' 00:00:00') {
                return false;
            }
            if ($dateTo !== '' && $ca > $dateTo . ' 23:59:59') {
                return false;
            }
            return true;
        }));
        usort($list, static function ($a, $b) {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });
        $total = count($list);
        $items = array_slice($list, $offset, $perPage);
    }

    $pages = $total > 0 ? (int) ceil($total / $perPage) : 1;
    if ($page > $pages) {
        $page = $pages;
    }

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'pages' => $pages,
    ];
}

/**
 * 清空后台日志（可选保留最近 N 天）
 *
 * @param int $keepDays 0 = 全部清空；>0 仅删除更早的
 * @return array{ok:bool,deleted:int,message:string}
 */
function admin_log_clear($keepDays = 0)
{
    $keepDays = (int) $keepDays;
    if ($keepDays < 0) {
        $keepDays = 0;
    }
    if ($keepDays > 3650) {
        $keepDays = 3650;
    }

    try {
        ensure_extra_tables();
        $pdo = db();
        if ($keepDays > 0) {
            $cutoff = date('Y-m-d H:i:s', time() - $keepDays * 86400);
            $stmt = $pdo->prepare('DELETE FROM admin_logs WHERE created_at < ?');
            $stmt->execute([$cutoff]);
            $deleted = $stmt->rowCount();
            return ['ok' => true, 'deleted' => $deleted, 'message' => '已删除 ' . $deleted . ' 条 ' . $keepDays . ' 天前的日志'];
        }
        $deleted = (int) $pdo->exec('DELETE FROM admin_logs');
        return ['ok' => true, 'deleted' => $deleted, 'message' => '已清空全部日志（' . $deleted . ' 条）'];
    } catch (Throwable $e) {
        $file = ROOT_PATH . '/data/admin_logs.json';
        if (!is_file($file)) {
            return ['ok' => true, 'deleted' => 0, 'message' => '无日志可清理'];
        }
        if ($keepDays <= 0) {
            @unlink($file);
            return ['ok' => true, 'deleted' => 0, 'message' => '已清空全部日志'];
        }
        $list = json_decode((string) file_get_contents($file), true);
        if (!is_array($list)) {
            return ['ok' => true, 'deleted' => 0, 'message' => '无日志可清理'];
        }
        $cutoff = date('Y-m-d H:i:s', time() - $keepDays * 86400);
        $new = [];
        $deleted = 0;
        foreach ($list as $r) {
            if ((string) ($r['created_at'] ?? '') < $cutoff) {
                $deleted++;
            } else {
                $new[] = $r;
            }
        }
        @file_put_contents($file, json_encode($new, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n", LOCK_EX);
        return ['ok' => true, 'deleted' => $deleted, 'message' => '已删除 ' . $deleted . ' 条 ' . $keepDays . ' 天前的日志'];
    }
}

/**
 * 运行时配置 / 密钥：统一读写 settings 表（DB 主存储）
 * 旧版 site_extra.json / 其它 JSON 仅只读回退；新写入不再落盘密钥。
 *
 * @return array 请求内缓存（引用）
 */
function &setting_request_cache()
{
    static $cache = [];
    return $cache;
}

function setting_get($key, $default = '')
{
    $key = (string) $key;
    if ($key === '') {
        return $default;
    }
    $cache = &setting_request_cache();
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        if (function_exists('ensure_extra_tables')) {
            ensure_extra_tables();
        }
        $stmt = db()->prepare('SELECT svalue FROM settings WHERE skey = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row && array_key_exists('svalue', $row)) {
            $cache[$key] = $row['svalue'];
            return $cache[$key];
        }
    } catch (Throwable $e) {
        // 安装前或表不存在
    }
    // 遗留 site_extra.json 只读回退
    $file = ROOT_PATH . '/config/site_extra.json';
    if (is_file($file)) {
        $json = json_decode((string) file_get_contents($file), true);
        if (is_array($json) && array_key_exists($key, $json)) {
            $cache[$key] = $json[$key];
            return $cache[$key];
        }
    }
    return $default;
}

function setting_set($key, $value)
{
    $key = (string) $key;
    if ($key === '' || strlen($key) > 64) {
        return false;
    }
    $value = (string) $value;
    try {
        if (function_exists('ensure_extra_tables')) {
            ensure_extra_tables();
        }
        $stmt = db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
        $ok = $stmt->execute([$key, $value]);
        if ($ok) {
            $cache = &setting_request_cache();
            $cache[$key] = $value;
        }
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

function setting_bool($key, $default = false)
{
    $v = setting_get($key, $default ? '1' : '0');
    return $v === '1' || $v === 1 || $v === true || $v === 'true' || $v === 'yes' || $v === 'on';
}

/**
 * 密钥 JSON 块：DB 键 secret_*，文件仅迁移
 *
 * @return array|null 解析后的数组；无数据 null
 */
function secret_blob_get($name)
{
    $name = preg_replace('/[^a-z0-9_\-]/i', '', (string) $name);
    if ($name === '') {
        return null;
    }
    $raw = setting_get('secret_' . $name, '');
    if ($raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
    }
    return null;
}

/**
 * @param array $data 将 JSON 编码写入 settings
 */
function secret_blob_set($name, array $data)
{
    $name = preg_replace('/[^a-z0-9_\-]/i', '', (string) $name);
    if ($name === '') {
        return false;
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    return setting_set('secret_' . $name, $json);
}

/**
 * 从旧 JSON 文件迁移到 DB（仅当 DB 中尚无 secret 块时）
 *
 * @return array|null 迁移或读到的数据
 */
function secret_blob_migrate_from_file($name, $filePath)
{
    $existing = secret_blob_get($name);
    if (is_array($existing) && $existing !== []) {
        return $existing;
    }
    if (!is_file($filePath) || !is_readable($filePath)) {
        return $existing;
    }
    $j = json_decode((string) file_get_contents($filePath), true);
    if (!is_array($j) || $j === []) {
        return $existing;
    }
    secret_blob_set($name, $j);
    return $j;
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

/**
 * 默认首页 Hero 背景图（相对站点根）
 */
function site_hero_bg_default()
{
    return 'assets/images/background.avif';
}

/**
 * 校验/规范化 Hero 背景：本地 uploads 路径或 http(s) 图片 URL
 *
 * @return string 合法路径/URL，非法则空串
 */
function site_hero_bg_normalize($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    // 本地上传：assets/images/uploads/hero-*.{ext}
    if (preg_match('#^assets/images/uploads/hero-[a-zA-Z0-9_\-]+\.(jpe?g|png|gif|webp|avif)$#i', $value)) {
        return $value;
    }
    // 内置默认图
    if ($value === site_hero_bg_default() || $value === 'assets/images/background.avif') {
        return site_hero_bg_default();
    }
    $url = safe_http_url($value, false);
    if ($url === '') {
        return '';
    }
    // 拒绝危险扩展 / 非图片感 URL 仍允许常见 CDN 图（无扩展也可）
    if (preg_match('#\.(php|phtml|php\d*|js|html?|svg|xml|exe|sh)(\?|$)#i', $url)) {
        return '';
    }
    return $url;
}

/**
 * 解析前台实际使用的 Hero 背景 URL（始终有值）
 */
function site_hero_bg_url(array $site)
{
    $raw = site_hero_bg_normalize($site['hero_bg'] ?? '');
    if ($raw === '') {
        return site_hero_bg_default();
    }
    return $raw;
}

/**
 * 是否为本地上传的 Hero 图
 */
function site_hero_bg_is_upload($path)
{
    $path = (string) $path;
    return (bool) preg_match('#^assets/images/uploads/hero-[a-zA-Z0-9_\-]+\.(jpe?g|png|gif|webp|avif)$#i', $path);
}

/**
 * 删除本地 Hero 上传文件（安全路径校验）
 */
function site_hero_bg_delete_file($relativePath)
{
    $relativePath = site_hero_bg_normalize($relativePath);
    if (!site_hero_bg_is_upload($relativePath)) {
        return false;
    }
    $full = ROOT_PATH . '/' . str_replace(['\\', '..'], ['/', ''], $relativePath);
    $realBase = realpath(ROOT_PATH . '/assets/images/uploads');
    if ($realBase === false) {
        return false;
    }
    if (!is_file($full)) {
        return false;
    }
    $realFile = realpath($full);
    if ($realFile === false || strpos($realFile, $realBase) !== 0) {
        return false;
    }
    return @unlink($realFile);
}

/**
 * 处理后台 Hero 背景上传
 *
 * @param array $file $_FILES['hero_bg_file']
 * @return array{ok:bool,path?:string,error?:string}
 */
function site_hero_bg_handle_upload(array $file)
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => ''];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => '图片上传失败（错误码 ' . (int) $file['error'] . '）'];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => '无效的上传文件'];
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        return ['ok' => false, 'error' => '背景图需小于 5MB'];
    }

    $ext = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $tmp) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
        ];
        if (!isset($mimeMap[$mime])) {
            return ['ok' => false, 'error' => '仅支持 JPG / PNG / GIF / WebP / AVIF'];
        }
        $ext = $mimeMap[$mime];
    } else {
        $orig = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = ['jpg' => true, 'jpeg' => true, 'png' => true, 'gif' => true, 'webp' => true, 'avif' => true];
        if (!isset($allowed[$orig])) {
            return ['ok' => false, 'error' => '仅支持 JPG / PNG / GIF / WebP / AVIF'];
        }
        $ext = $orig === 'jpeg' ? 'jpg' : $orig;
    }

    // 二次校验：getimagesize（avif 部分环境可能不支持，失败时仅依赖 mime）
    if (function_exists('getimagesize') && $ext !== 'avif') {
        $info = @getimagesize($tmp);
        if ($info === false) {
            return ['ok' => false, 'error' => '无法识别为有效图片'];
        }
    }

    $dir = ROOT_PATH . '/assets/images/uploads';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'error' => '无法创建上传目录 assets/images/uploads'];
    }
    if (!is_writable($dir)) {
        return ['ok' => false, 'error' => '上传目录不可写'];
    }

    $name = 'hero-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!@move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'error' => '保存上传文件失败'];
    }
    @chmod($dest, 0644);

    return ['ok' => true, 'path' => 'assets/images/uploads/' . $name];
}

/**
 * 规范化 SEO robots 指令
 */
function site_seo_normalize_robots($robots)
{
    $raw = strtolower(trim((string) $robots));
    if ($raw === '') {
        return 'index,follow';
    }
    $raw = str_replace([' ', ';'], ['', ','], $raw);
    $parts = array_filter(array_map('trim', explode(',', $raw)));
    $allowed = [
        'index' => true,
        'noindex' => true,
        'follow' => true,
        'nofollow' => true,
        'none' => true,
        'noarchive' => true,
        'nosnippet' => true,
        'noimageindex' => true,
    ];
    $out = [];
    foreach ($parts as $p) {
        if (isset($allowed[$p])) {
            $out[$p] = $p;
        }
    }
    if (!$out) {
        return 'index,follow';
    }
    return implode(',', array_values($out));
}

/**
 * 当前请求的绝对 URL（用于 canonical 默认）
 */
function site_request_absolute_url()
{
    $https = function_exists('security_is_https') ? security_is_https() : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !preg_match('/^[a-zA-Z0-9.\-:\[\]]+$/', $host)) {
        return '';
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    // 去掉 fragment / 危险字符
    $uri = str_replace(["\0", "\r", "\n"], '', $uri);
    if ($uri === '' || $uri[0] !== '/') {
        $uri = '/' . ltrim($uri, '/');
    }
    return $scheme . '://' . $host . $uri;
}

/**
 * 解析站点 SEO 元信息（可被页面覆盖）
 *
 * @param array $site site 配置
 * @param array $overrides title|description|keywords|canonical|og_type|noindex
 * @return array
 */
function site_seo_meta(array $site, array $overrides = [])
{
    $name = trim((string) ($site['name'] ?? '夏尼猫网址导航'));
    $subtitle = trim((string) ($site['subtitle'] ?? ''));
    $seoTitle = trim((string) ($site['seo_title'] ?? ''));
    $seoDesc = trim((string) ($site['seo_description'] ?? ''));
    $seoKw = trim((string) ($site['seo_keywords'] ?? ''));
    $author = trim((string) ($site['seo_author'] ?? ''));
    $robots = site_seo_normalize_robots($site['seo_robots'] ?? 'index,follow');
    $canonicalCfg = trim((string) ($site['seo_canonical'] ?? ''));
    $ogImage = trim((string) ($site['seo_og_image'] ?? ''));

    if (!empty($overrides['noindex'])) {
        $robots = 'noindex,nofollow';
    }

    $pageTitle = isset($overrides['title']) ? trim((string) $overrides['title']) : '';
    if ($pageTitle !== '') {
        $title = $pageTitle;
    } elseif ($seoTitle !== '') {
        $title = $seoTitle;
    } else {
        $title = $subtitle !== '' ? ($name . ' - ' . $subtitle) : ($name . ' - 上网从这里开始');
    }

    $description = isset($overrides['description']) ? trim((string) $overrides['description']) : '';
    if ($description === '') {
        $description = $seoDesc !== '' ? $seoDesc : $subtitle;
    }
    if (function_exists('mb_substr')) {
        $description = mb_substr($description, 0, 320, 'UTF-8');
    } else {
        $description = substr($description, 0, 320);
    }

    $keywords = isset($overrides['keywords']) ? trim((string) $overrides['keywords']) : $seoKw;
    if (function_exists('mb_substr')) {
        $keywords = mb_substr($keywords, 0, 500, 'UTF-8');
    } else {
        $keywords = substr($keywords, 0, 500);
    }

    // canonical：优先页面覆盖 → 首选域名改写当前路径 → 当前绝对 URL → 配置的 canonical
    $canonical = isset($overrides['canonical']) ? trim((string) $overrides['canonical']) : '';
    if ($canonical === '') {
        $reqUrl = site_request_absolute_url();
        $preferredOrigin = '';
        if ($canonicalCfg !== '') {
            $cfgParts = parse_url($canonicalCfg);
            if (is_array($cfgParts) && !empty($cfgParts['scheme']) && !empty($cfgParts['host'])) {
                $preferredOrigin = strtolower($cfgParts['scheme']) . '://' . $cfgParts['host'];
                if (!empty($cfgParts['port'])) {
                    $preferredOrigin .= ':' . (int) $cfgParts['port'];
                }
            }
        }
        if ($reqUrl !== '') {
            $reqParts = parse_url($reqUrl);
            $path = is_array($reqParts) ? (string) ($reqParts['path'] ?? '/') : '/';
            if ($path === '') {
                $path = '/';
            }
            // 首页 index.php 规范为站点根
            if (preg_match('#/index\.php/?$#i', $path)) {
                $path = preg_replace('#/index\.php/?$#i', '/', $path);
            }
            if ($preferredOrigin !== '') {
                $canonical = $preferredOrigin . $path;
            } else {
                $scheme = is_array($reqParts) ? (string) ($reqParts['scheme'] ?? 'https') : 'https';
                $host = is_array($reqParts) ? (string) ($reqParts['host'] ?? '') : '';
                $port = is_array($reqParts) && !empty($reqParts['port']) ? (':' . (int) $reqParts['port']) : '';
                $canonical = $host !== '' ? ($scheme . '://' . $host . $port . $path) : $reqUrl;
            }
        } else {
            $canonical = $canonicalCfg;
        }
    }
    $canonical = safe_http_url($canonical, false);
    $ogImage = safe_http_url($ogImage, false);

    $ogType = trim((string) ($overrides['og_type'] ?? 'website'));
    if (!in_array($ogType, ['website', 'article', 'profile'], true)) {
        $ogType = 'website';
    }

    return [
        'title' => $title,
        'description' => $description,
        'keywords' => $keywords,
        'author' => $author,
        'robots' => $robots,
        'canonical' => $canonical,
        'og_image' => $ogImage,
        'og_type' => $ogType,
        'site_name' => $name,
        'baidu_verify' => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($site['seo_baidu_verify'] ?? '')),
        'google_verify' => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($site['seo_google_verify'] ?? '')),
        'bing_verify' => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($site['seo_bing_verify'] ?? '')),
        'head_html' => (string) ($site['seo_head_html'] ?? ''),
    ];
}

/**
 * 输出前台 <head> 内 SEO 标签（已转义）
 *
 * @param array $site
 * @param array $overrides
 */
function render_seo_head(array $site, array $overrides = [])
{
    $m = site_seo_meta($site, $overrides);
    echo '<title>' . e($m['title']) . "</title>\n";
    if ($m['description'] !== '') {
        echo '    <meta name="description" content="' . e($m['description']) . "\">\n";
    }
    if ($m['keywords'] !== '') {
        echo '    <meta name="keywords" content="' . e($m['keywords']) . "\">\n";
    }
    if ($m['author'] !== '') {
        echo '    <meta name="author" content="' . e($m['author']) . "\">\n";
    }
    echo '    <meta name="robots" content="' . e($m['robots']) . "\">\n";
    if ($m['canonical'] !== '') {
        echo '    <link rel="canonical" href="' . e($m['canonical']) . "\">\n";
    }
    // Open Graph
    echo '    <meta property="og:type" content="' . e($m['og_type']) . "\">\n";
    echo '    <meta property="og:site_name" content="' . e($m['site_name']) . "\">\n";
    echo '    <meta property="og:title" content="' . e($m['title']) . "\">\n";
    if ($m['description'] !== '') {
        echo '    <meta property="og:description" content="' . e($m['description']) . "\">\n";
    }
    if ($m['canonical'] !== '') {
        echo '    <meta property="og:url" content="' . e($m['canonical']) . "\">\n";
    }
    if ($m['og_image'] !== '') {
        echo '    <meta property="og:image" content="' . e($m['og_image']) . "\">\n";
    }
    // Twitter
    echo '    <meta name="twitter:card" content="' . e($m['og_image'] !== '' ? 'summary_large_image' : 'summary') . "\">\n";
    echo '    <meta name="twitter:title" content="' . e($m['title']) . "\">\n";
    if ($m['description'] !== '') {
        echo '    <meta name="twitter:description" content="' . e($m['description']) . "\">\n";
    }
    if ($m['og_image'] !== '') {
        echo '    <meta name="twitter:image" content="' . e($m['og_image']) . "\">\n";
    }
    if ($m['baidu_verify'] !== '') {
        echo '    <meta name="baidu-site-verification" content="' . e($m['baidu_verify']) . "\">\n";
    }
    if ($m['google_verify'] !== '') {
        echo '    <meta name="google-site-verification" content="' . e($m['google_verify']) . "\">\n";
    }
    if ($m['bing_verify'] !== '') {
        echo '    <meta name="msvalidate.01" content="' . e($m['bing_verify']) . "\">\n";
    }
    $extra = sanitize_admin_html($m['head_html']);
    // 仅允许 meta/link，强制剥离 script / 事件
    $extra = preg_replace('#<\s*script\b[^>]*>.*?<\s*/\s*script\s*>#is', '', $extra);
    $extra = preg_replace('#<\s*/?\s*script\b[^>]*>#i', '', $extra);
    $extra = preg_replace('#\son\w+\s*=\s*(\'[^\']*\'|"[^"]*"|[^\s>]+)#i', '', $extra);
    $extra = trim(strip_tags($extra, '<meta><link>'));
    if ($extra !== '') {
        echo '    ' . $extra . "\n";
    }
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
