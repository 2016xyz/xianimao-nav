# 技术架构

## 1. 总体架构

```text
浏览器
  │  SSR HTML + 本地 CSS/JS
  ▼
PHP 应用（前台 / admin / api / install）
  │
  ├─ includes/bootstrap.php   会话、安全头、内容加载、鉴权
  ├─ includes/security.php    CSP / 校验 / 转义 / URL 白名单
  ├─ includes/db.php          PDO 单例
  ├─ includes/mailer.php      SMTP + 验证码 + 通知
  ├─ includes/hot_fetcher.php 热榜抓取与缓存
  ├─ includes/oauth_providers.php  Linux.do / 吾爱凭证
  └─ includes/ai.php          AI 描述生成
  │
  ├─ MySQL（主存储）
  └─ 文件（JSON 回退、密钥、缓存）
```

## 2. 请求生命周期

1. 入口 PHP `require includes/bootstrap.php`（install 仅加载 security + install）  
2. `security_configure_session` + `session_start` + `security_send_headers`  
3. 未安装则跳转 `install.php`  
4. 业务逻辑：CSRF 校验 → `security_*` 输入校验 → 参数化 SQL / 文件写入  
5. 模板输出：统一 `e()` 转义；可信 HTML 经 `sanitize_admin_html`  

## 3. 数据模型（简）

| 表 / 文件 | 用途 |
|-----------|------|
| `settings` | 站点键值配置 |
| `engines` / `shortcuts` / `sites` / `projects` / `tools` / `links` | 前台列表内容 |
| `messages` | 留言与申请收录 |
| `admins` | 管理员账号 |
| `data/content.json` | DB 不可用时的内容回退 |
| `config/*.json` | SMTP、AI、OAuth 等敏感配置（gitignore） |
| `data/cache/` | 热榜缓存、发码 IP 限流等 |

`load_content()`：优先读库；库空或缺站点名时合并 JSON，降低「双写分裂」导致内容丢失。

## 4. 前台功能流

### 4.1 搜索

首页引擎列表来自 `engines`；`main.js` 拼接查询词并用 `http(s)` 白名单打开新窗口。

### 4.2 热榜

`hot_fetcher` 按启用源抓取 → 写入缓存 → 前台渲染。Linux.do / 吾爱可使用后台授权凭证提升成功率。

### 4.3 申请收录 / 在线留言

```text
用户填邮箱 → api/send_form_code.php（CSRF + 限流）
  → mailer_send_form_code → SMTP
用户提交表单 → 验证码一次性消费 → save_message
  → mailer_notify_admin_submission
```

## 5. 后台鉴权

- 登录：用户名密码 + 图形验证码；可选邮箱二次验证码  
- 会话：`admin_logged_in` + `session_regenerate_id`  
- 写操作：`verify_csrf()` + `require_login()`  
- 退出：POST + CSRF（防跨站强制登出）  

## 6. 邮件

原生 socket SMTP，支持 SSL / TLS / none。HTML 模板在 `mailer.php`。  
`smtp_is_ready()`：启用 + host + from_email；若配置了 user 则要求 pass。

## 7. 安全边界

详见 [SECURITY.md](SECURITY.md)。原则：

- 不信任任何客户端输入  
- 不拼接用户输入进 SQL  
- 不渲染未消毒 HTML  
- 不接受非 http(s) 外链作为可点击 URL  

## 8. 扩展建议

- 增加热榜源：在 `hot_board_catalog()` 注册并实现 fetch 函数  
- 增加后台菜单：`admin/layout.php` 导航 + 新 PHP 页  
- 多管理员 / RBAC：扩展 `admins` 表与 `require_login`  
