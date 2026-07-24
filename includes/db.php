<?php
/**
 * PDO 数据库连接
 */

/**
 * @return PDO
 */
function db()
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_file(DB_CONFIG_FILE)) {
        throw new RuntimeException('数据库未配置，请先完成安装');
    }

    $cfg = require DB_CONFIG_FILE;
    $host = $cfg['host'] ?? '127.0.0.1';
    $port = (int) ($cfg['port'] ?? 3306);
    $dbname = $cfg['database'] ?? '';
    $user = $cfg['username'] ?? '';
    $pass = $cfg['password'] ?? '';
    $charset = $cfg['charset'] ?? 'utf8mb4';

    // 连接参数来自配置文件（非用户请求）；charset 白名单防 DSN 注入
    $charset = preg_match('/^[a-zA-Z0-9_]+$/', (string) $charset) ? $charset : 'utf8mb4';
    $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', (string) $host) ?: '127.0.0.1';
    $dbname = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $dbname);
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbname, $charset);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false, // 真预编译，降低 SQL 注入面
    ]);

    return $pdo;
}
