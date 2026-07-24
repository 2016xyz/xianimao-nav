<?php
/**
 * 安装检测与环境检查
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

define('DB_CONFIG_FILE', ROOT_PATH . '/config/database.php');
define('INSTALL_LOCK_FILE', ROOT_PATH . '/config/install.lock');

function is_installed()
{
    return is_file(INSTALL_LOCK_FILE) && is_file(DB_CONFIG_FILE);
}

/**
 * 当前请求是否为安装相关页面
 */
function is_install_request()
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $base = basename($script);
    return $base === 'install.php' || strpos($script, '/install/') !== false;
}

/**
 * 未安装则跳转到安装页（安装页自身除外）
 */
function require_installed_or_redirect()
{
    if (is_installed() || is_install_request()) {
        return;
    }

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $dir = str_replace('\\', '/', dirname($script));
    // 后台目录 /admin/*
    if (substr($dir, -6) === '/admin') {
        $target = '../install.php';
    } else {
        $target = 'install.php';
    }

    header('Location: ' . $target);
    exit;
}

/**
 * 环境检测项
 * @return array{ok:bool,items:array}
 */
function install_check_environment()
{
    $items = [];

    $phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
    $items[] = [
        'name' => 'PHP 版本 ≥ 7.4',
        'ok' => $phpOk,
        'detail' => '当前：' . PHP_VERSION,
    ];

    $pdoOk = extension_loaded('pdo');
    $items[] = [
        'name' => 'PHP 扩展 PDO',
        'ok' => $pdoOk,
        'detail' => $pdoOk ? '已启用' : '未启用，请在 php.ini 中开启 extension=pdo',
    ];

    $mysqlOk = extension_loaded('pdo_mysql');
    $items[] = [
        'name' => 'PHP 扩展 pdo_mysql',
        'ok' => $mysqlOk,
        'detail' => $mysqlOk ? '已启用' : '未启用，请开启 extension=pdo_mysql',
    ];

    $jsonOk = extension_loaded('json');
    $items[] = [
        'name' => 'PHP 扩展 json',
        'ok' => $jsonOk,
        'detail' => $jsonOk ? '已启用' : '未启用',
    ];

    $configDir = ROOT_PATH . '/config';
    $configWritable = is_dir($configDir) && is_writable($configDir);
    $items[] = [
        'name' => 'config 目录可写',
        'ok' => $configWritable,
        'detail' => $configWritable ? $configDir : '请给 config 目录写权限',
    ];

    $dataDir = ROOT_PATH . '/data';
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0755, true);
    }
    $dataWritable = is_dir($dataDir) && is_writable($dataDir);
    $items[] = [
        'name' => 'data 目录可写（热榜缓存）',
        'ok' => $dataWritable,
        'detail' => $dataWritable ? $dataDir : '请给 data 目录写权限',
    ];

    $ok = true;
    foreach ($items as $item) {
        if (!$item['ok']) {
            $ok = false;
            break;
        }
    }

    return ['ok' => $ok, 'items' => $items];
}

/**
 * 测试数据库连接（可选创建库）
 * @return array{ok:bool,message:string,pdo?:PDO}
 */
function install_test_db(array $cfg, $createDatabase = false)
{
    $host = $cfg['host'] ?? '127.0.0.1';
    $port = (int) ($cfg['port'] ?? 3306);
    $user = $cfg['username'] ?? 'root';
    $pass = $cfg['password'] ?? '';
    $dbname = $cfg['database'] ?? '';
    $charset = $cfg['charset'] ?? 'utf8mb4';

    if ($dbname === '') {
        return ['ok' => false, 'message' => '数据库名不能为空'];
    }

    try {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset);
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        if ($createDatabase) {
            $safeDb = str_replace('`', '``', $dbname);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDb}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }

        $pdo->exec('USE `' . str_replace('`', '``', $dbname) . '`');
        return ['ok' => true, 'message' => '连接成功', 'pdo' => $pdo];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 执行建表 SQL
 */
function install_run_schema(PDO $pdo)
{
    $sqlFile = ROOT_PATH . '/includes/schema.sql';
    if (!is_file($sqlFile)) {
        throw new RuntimeException('找不到 schema.sql');
    }
    $sql = file_get_contents($sqlFile);
    // 按分号拆分，忽略空语句
    $parts = preg_split('/;\s*[\r\n]+/', $sql);
    foreach ($parts as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || strpos($stmt, '--') === 0) {
            continue;
        }
        $pdo->exec($stmt);
    }
}

/**
 * 写入默认种子数据
 */
function install_seed(PDO $pdo, array $site, $adminUser, $adminPassHash)
{
    // settings
    $settings = [
        'site_name' => $site['name'] ?? '夏尼猫网址导航',
        'site_subtitle' => $site['subtitle'] ?? '实用工具与优质站点聚合',
        'site_footer' => $site['footer'] ?? ('© ' . date('Y') . ' 夏尼猫网址导航'),
        'show_friend_links' => '1',
        'enable_message' => '1',
        'about_html' => '<p>夏尼猫网址导航汇集实用工具、开源项目与优质站点，帮助你更快找到需要的资源。</p>',
        'contact_html' => '<p>如有合作、建议或问题，欢迎通过邮件 <a href="mailto:i@2016xlx.cn">i@2016xlx.cn</a> 或留言联系我们。</p>',
        'contact_email' => 'i@2016xlx.cn',
        'hot_boards_enabled' => json_encode(['weibo', '52pojie', 'bilibili', 'baidu', 'linuxdo'], JSON_UNESCAPED_UNICODE),
    ];
    $stmt = $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    foreach ($settings as $k => $v) {
        $stmt->execute([$k, $v]);
    }

    // admin
    $pdo->prepare('DELETE FROM admins')->execute();
    $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')->execute([
        $adminUser,
        $adminPassHash,
    ]);

    // engines
    $engines = [
        ['baidu', '百度', 'https://www.baidu.com/s?wd={q}'],
        ['google', '谷歌', 'https://www.google.com/search?q={q}'],
        ['sogou', '搜狗', 'https://www.sogou.com/web?query={q}'],
        ['zhihu', '知乎', 'https://www.zhihu.com/search?type=content&q={q}'],
        ['github', 'Github', 'https://github.com/search?q={q}'],
    ];
    $pdo->exec('DELETE FROM engines');
    $es = $pdo->prepare('INSERT INTO engines (slug, name, url, sort_order) VALUES (?,?,?,?)');
    foreach ($engines as $i => $row) {
        $es->execute([$row[0], $row[1], $row[2], $i]);
    }

    // shortcuts（搜索快捷，与参考站一致）
    $shortcuts = [
        ['音乐', 'https://music.163.com/#/search/m/?s={q}', 'search'],
        ['图书', 'https://search.douban.com/book/subject_search?search_text={q}', 'search'],
        ['影视', 'https://search.bilibili.com/all?keyword={q}', 'search'],
    ];
    $pdo->exec('DELETE FROM shortcuts');
    $ss = $pdo->prepare('INSERT INTO shortcuts (name, url, type, sort_order) VALUES (?,?,?,?)');
    foreach ($shortcuts as $i => $row) {
        $ss->execute([$row[0], $row[1], $row[2], $i]);
    }

    // sites
    $sites = [
        ['缤纷彩虹天地', '彩虹博客，致力于互联网资源的共享，包括程序源码、各种教程、软件、影视、音乐、电子书、新闻等', 'https://blog.cccyun.cn/', '博客'],
        ['彩虹云主机', '提供免备案CDN，免备案空间，全光纤网络，BGP智能多线，目前有美国、香港等节点', 'https://www.cccyun.net/', '主机'],
        ['彩虹工具网', '包含站长工具、实用工具、开发工具、娱乐工具，备案查询、IP查询、Whois、编码解码、图床等', 'https://tool.cccyun.cc/', '工具'],
        ['彩虹聚合登录', '社会化账号聚合登录系统，支持微信、微博、QQ、百度等多种帐号一站式登录', 'https://u.cccyun.cc/', '登录'],
        ['彩虹分布式云任务', '免费的自动签到平台', 'https://mz.qqzzz.net/www/', '任务'],
        ['彩虹免费监控网', '提供免费的网址定时访问功能', 'https://cron.qqzzz.net/', '监控'],
        ['彩虹外链网盘', '文件上传与分享，支持任意格式，可生成下载直连，方便分享', 'https://cccimg.com/', '网盘'],
        ['彩虹易支付系统', '彩虹易支付系统授权站点，可自助购买授权', 'https://pay.cccyun.cc/', '支付'],
        ['彩虹聚合登录系统', '聚合登录系统授权站点，可自助购买授权', 'https://www.clogin.cc/', '授权'],
        ['彩虹云任务系统', '云任务系统授权站点，可查询、购买授权', 'https://auth.cccyun.cc/', '授权'],
        ['Kangle一键脚本', '一键安装 Kangle+Easypanel+MySQL+PHP，支持 PHP5.3~8.4、MYSQL5.6~8.0', 'https://kangle.sh/', '脚本'],
    ];
    $pdo->exec('DELETE FROM sites');
    $si = $pdo->prepare('INSERT INTO sites (name, description, url, tag, sort_order) VALUES (?,?,?,?,?)');
    foreach ($sites as $i => $row) {
        $si->execute([$row[0], $row[1], $row[2], $row[3], $i]);
    }

    // projects
    $projects = [
        ['彩虹聚合DNS管理系统', '在单一网站内管理多个平台的域名解析，支持容灾切换、定时切换、CF优选IP、SSL证书申请与自动部署', 'https://github.com/netcccyun/dnsmgr', 'DNS'],
        ['微信公众号多域名回调系统', '微信公众号多域名回调、消息事件转发，以及支付宝开放平台多域名回调', 'https://github.com/netcccyun/wxredirect', '微信'],
    ];
    $pdo->exec('DELETE FROM projects');
    $ps = $pdo->prepare('INSERT INTO projects (name, description, url, tag, sort_order) VALUES (?,?,?,?,?)');
    foreach ($projects as $i => $row) {
        $ps->execute([$row[0], $row[1], $row[2], $row[3], $i]);
    }

    // tools 实用工具
    $tools = [
        ['IP 查询', '查询 IP 归属地与基础网络信息', 'https://tool.cccyun.cc/ip'],
        ['WHOIS 查询', '域名 Whois / 注册信息查询', 'https://tool.cccyun.cc/whois'],
        ['备案查询', '网站 ICP 备案信息查询', 'https://tool.cccyun.cc/beian'],
        ['短链生成', '生成短链接，方便分享', 'https://tool.cccyun.cc/'],
        ['Base64 编解码', '常用编码解码工具', 'https://tool.cccyun.cc/'],
        ['MD5 加密', '文本 MD5 / 哈希计算', 'https://tool.cccyun.cc/'],
        ['JSON 格式化', 'JSON 美化与校验', 'https://tool.cccyun.cc/'],
        ['时间戳转换', 'Unix 时间戳与日期互转', 'https://tool.cccyun.cc/'],
    ];
    try {
        $pdo->exec('DELETE FROM tools');
        $ts = $pdo->prepare('INSERT INTO tools (name, description, url, sort_order) VALUES (?,?,?,?)');
        foreach ($tools as $i => $row) {
            $ts->execute([$row[0], $row[1], $row[2], $i]);
        }
    } catch (Throwable $e) {
        // 旧库无表时忽略（ensure_extra 会补）
    }

    // links 友情链接
    $links = [
        ['GitHub', '全球最大开源代码托管平台', 'https://github.com/'],
        ['V2EX', '创意工作者社区', 'https://www.v2ex.com/'],
        ['Linux.do', '新的理想型社区', 'https://linux.do/'],
        ['吾爱破解', '软件安全与逆向交流', 'https://www.52pojie.cn/'],
    ];
    try {
        $pdo->exec('DELETE FROM links');
        $ls = $pdo->prepare('INSERT INTO links (name, description, url, sort_order) VALUES (?,?,?,?)');
        foreach ($links as $i => $row) {
            $ls->execute([$row[0], $row[1], $row[2], $i]);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * 写入 database.php 与 install.lock
 */
function install_write_config(array $dbCfg)
{
    $export = var_export([
        'host' => $dbCfg['host'],
        'port' => (int) $dbCfg['port'],
        'database' => $dbCfg['database'],
        'username' => $dbCfg['username'],
        'password' => $dbCfg['password'],
        'charset' => $dbCfg['charset'] ?? 'utf8mb4',
    ], true);

    $php = "<?php\n/**\n * 数据库配置（由安装程序生成，请勿手动删除 install.lock 后随意覆盖）\n */\nreturn {$export};\n";

    if (file_put_contents(DB_CONFIG_FILE, $php, LOCK_EX) === false) {
        throw new RuntimeException('无法写入 config/database.php，请检查 config 目录写权限');
    }

    $lock = "installed_at=" . date('Y-m-d H:i:s') . "\n";
    if (file_put_contents(INSTALL_LOCK_FILE, $lock, LOCK_EX) === false) {
        throw new RuntimeException('无法写入 config/install.lock');
    }
}

/**
 * 完整安装流程
 * @return array{ok:bool,message:string}
 */
function install_run(array $input)
{
    $db = [
        'host' => trim($input['db_host'] ?? '127.0.0.1'),
        'port' => (int) ($input['db_port'] ?? 3306),
        'database' => trim($input['db_name'] ?? ''),
        'username' => trim($input['db_user'] ?? ''),
        'password' => (string) ($input['db_pass'] ?? ''),
        'charset' => 'utf8mb4',
    ];

    $site = [
        'name' => trim($input['site_name'] ?? '夏尼猫网址导航'),
        'subtitle' => trim($input['site_subtitle'] ?? '实用工具与优质站点聚合'),
        'footer' => trim($input['site_footer'] ?? ('© ' . date('Y') . ' 夏尼猫网址导航')),
    ];

    $adminUser = trim($input['admin_user'] ?? 'admin');
    $adminPass = (string) ($input['admin_pass'] ?? '');
    $adminPass2 = (string) ($input['admin_pass2'] ?? '');

    if ($db['database'] === '' || $db['username'] === '') {
        return ['ok' => false, 'message' => '请填写数据库名与用户名'];
    }
    if ($site['name'] === '') {
        return ['ok' => false, 'message' => '站点名称不能为空'];
    }
    if ($adminUser === '' || strlen($adminPass) < 6) {
        return ['ok' => false, 'message' => '管理员用户名不能为空，密码至少 6 位'];
    }
    if ($adminPass !== $adminPass2) {
        return ['ok' => false, 'message' => '两次输入的管理员密码不一致'];
    }

    $env = install_check_environment();
    if (!$env['ok']) {
        return ['ok' => false, 'message' => '环境检测未通过，请先解决红色项'];
    }

    $createDb = !empty($input['create_database']);
    $test = install_test_db($db, $createDb);
    if (!$test['ok']) {
        return ['ok' => false, 'message' => '数据库连接失败：' . $test['message']];
    }

    /** @var PDO $pdo */
    $pdo = $test['pdo'];

    // MySQL 的 DDL 会隐式提交，建表与种子数据分开执行
    try {
        install_run_schema($pdo);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => '创建数据表失败：' . $e->getMessage()];
    }

    try {
        $pdo->beginTransaction();
        install_seed($pdo, $site, $adminUser, password_hash($adminPass, PASSWORD_DEFAULT));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => '初始化数据失败：' . $e->getMessage()];
    }

    try {
        install_write_config($db);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }

    return ['ok' => true, 'message' => '安装成功'];
}
