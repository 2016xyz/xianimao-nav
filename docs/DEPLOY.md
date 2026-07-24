# 部署指南

## 1. 环境要求

| 组件 | 要求 |
|------|------|
| PHP | ≥ 7.4（推荐 8.1+） |
| 扩展 | `pdo`、`pdo_mysql`、`json`、`mbstring`、`openssl`、`curl`；推荐 `gd` |
| MySQL | 5.7+ / 8.0+，字符集 `utf8mb4` |
| Web | Apache 2.4+ 或 Nginx 1.18+ |
| 权限 | `config/`、`data/` 可写 |

## 2. 获取代码

```bash
git clone https://github.com/<OWNER>/xianimao-nav.git
cd xianimao-nav
```

生产环境建议固定到某个 tag / commit，而不是直接跟踪开发分支。

## 3. Web 服务器配置

### Nginx 示例

```nginx
server {
    listen 80;
    server_name nav.example.com;
    root /var/www/xianimao-nav;
    index index.php;

    # 禁止直接访问敏感目录
    location ~ ^/(config|data|includes|scripts)/ {
        deny all;
        return 404;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;  # 按实际修改
    }

    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff2?|avif)$ {
        expires 7d;
        access_log off;
    }
}
```

HTTPS 请使用 certbot 或证书服务，并开启强制跳转。

### Apache 示例

确保开启 `mod_rewrite`、`AllowOverride`。可在站点根目录使用：

```apache
<Directory "/var/www/xianimao-nav">
    AllowOverride All
    Require all granted
</Directory>
```

项目内 `config/.htaccess`、`data/.htaccess` 用于拒绝直接访问配置与数据文件（Apache）。

## 4. 安装流程

1. 浏览器打开 `https://你的域名/install.php`
2. 确认环境检测全部通过
3. 填写 MySQL 连接信息（可勾选自动创建数据库，视账号权限而定）
4. 设置管理员用户名与密码（建议 ≥ 8 位）
5. 安装成功后访问前台与 `/admin/login.php`

生成文件（**勿提交到 Git**）：

- `config/database.php`
- `config/install.lock`

## 5. 上线后必做

1. **SMTP**：后台配置发信，否则邮箱验证码 / 管理员通知不可用  
2. **改默认密码**：若使用演示账号请立即修改  
3. **限制 install.php**：安装完成后删除或仅内网可访问  
4. **备份**：定期备份 MySQL 与 `data/`、`config/`（密钥）  
5. **HTTPS**：启用后 Session Cookie 的 `Secure` 与 HSTS 才会生效  

## 6. 目录权限建议（Linux）

```bash
# 以 www-data 为例
chown -R www-data:www-data config data
chmod 750 config data
# 密钥文件建议 600
find config -name '*.json' -o -name 'database.php' | xargs -r chmod 600
```

## 7. 常见问题

| 现象 | 处理 |
|------|------|
| 一直跳转 install.php | 检查 `config/database.php` 与 `install.lock` 是否存在且可读 |
| 留言 / 验证码失败 | 检查 SMTP 是否 `enabled`、host/from 是否完整 |
| 热榜为空 | 检查出站网络、curl、缓存目录 `data/cache` 可写 |
| 500 空白页 | 查看 PHP-FPM / Apache 错误日志，确认扩展已加载 |
| 后台验证码不显示 | 安装 `gd` 扩展，或使用 SVG 回退路径 |

## 8. 更新升级

```bash
cd /var/www/xianimao-nav
git pull
# 如有 schema 变更，对照 includes/schema_extra.sql 手动执行
# 保持 config/database.php 与密钥文件不被覆盖
```

程序启动时会尝试执行 `schema_extra.sql` 做轻量升级；重大变更请阅读版本说明。
