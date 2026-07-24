# 安全设计说明

本文描述夏尼猫网址导航的安全机制与运维建议。公开仓库**不会**包含真实数据库密码、SMTP、OAuth、AI Key 等密钥。

## 1. 已实现的防护

### 1.1 浏览器 / 传输

| 项 | 说明 |
|----|------|
| CSP | `script-src 'self'`，禁止内联脚本与 eval；`object-src 'none'`；`frame-ancestors 'self'` |
| Cookie | `HttpOnly`、`SameSite=Lax`、HTTPS 时 `Secure`；`session.use_strict_mode` |
| 其它头 | `X-Content-Type-Options: nosniff`、`X-Frame-Options: SAMEORIGIN`、`Referrer-Policy`、`Permissions-Policy`、HSTS（HTTPS） |
| 无内联脚本 | 业务 PHP 使用外链 JS（`assets/`、`admin/assets/`） |

### 1.2 XSS

- 输出：`e()` / `security_escape`  
- 后台 HTML：`security_sanitize_html`（标签白名单 + 去事件属性 / `javascript:`）  
- URL：`security_url` / `safe_http_url`，拦截 `javascript:`、`data:`、协议相对 `//`  
- 前台 `window.open` 仅允许 `http(s)`  

### 1.3 SQL 注入

- PDO 预处理，`ATTR_EMULATE_PREPARES => false`  
- 业务写路径使用 `prepare` + 绑定参数  
- `LIMIT` 仅使用 `security_sql_limit` 校验后的整数  
- DSN 主机 / 库名 / charset 清洗  

### 1.4 CSRF 与会话

- 状态变更接口：`csrf_token` + `hash_equals`  
- 安装向导：独立 `install_csrf`  
- 登录成功：`session_regenerate_id(true)`  
- 退出：POST + CSRF  

### 1.5 注入与滥发

- 接口级长度 / 类型 / 枚举限制（`security_*`）  
- 表单邮箱验证码：Session 冷却 + IP 文件限流；校验成功即消费  
- 图形验证码：失败次数上限  
- 开放重定向：`redirect()` 拒绝外链  

### 1.6 安装安全

- **已安装禁止**通过 `?force=` 公开重装  
- 安装 POST 需 CSRF  

## 2. 密钥与 .gitignore

以下路径默认忽略，请勿提交：

- `config/database.php`、`config/install.lock`  
- `config/smtp.json`、`config/ai_config.json`  
- `config/*_auth.json`、`config/linuxdo_oauth_app.json`  
- `config/auth.php`、`config/site_extra.json`、`config/hot_config.json`  
- `data/messages.json`、`data/cache/*`、`.env`  

示例配置：`config/database.example.php`。

## 3. 运维清单

- [ ] 使用强管理员密码，定期更换  
- [ ] 全站 HTTPS  
- [ ] 安装完成后移除或限制 `install.php`  
- [ ] 限制 `config/`、`data/` 的 Web 直接访问（Nginx deny）  
- [ ] SMTP 使用专用邮箱与应用专用密码  
- [ ] 定期备份数据库与密钥文件  
- [ ] 生产关闭 `display_errors`，日志写入安全路径  
- [ ] 关注依赖与 PHP 安全更新  

## 4. 已知权衡

| 项 | 说明 |
|----|------|
| `style-src 'unsafe-inline'` | 页面存在内联 style，若收紧需全面迁到 CSS 类 |
| 出站 SSL 校验 | 部分抓取/OAuth 为兼容环境可能关闭证书校验，生产可按需收紧 |
| 留言双通道 | DB 故障时写 JSON，恢复后不自动合并 |
| 热榜爬取 | 遵守目标站条款与频率，避免滥用 |

## 5. 自检

```bash
php scripts/security_verify.php
```

期望输出：`ALL_SECURITY_CHECKS_PASSED`。

## 6. 漏洞反馈

若发现安全问题，请通过仓库 Issues（可标注 private / security）或项目联系邮箱 `i@2016xlx.cn` 报告，避免公开披露细节直至修复。
