# 夏尼猫网址导航

纯 PHP 实现的现代化网址导航门户：搜索聚合、真实热榜、实用工具、友链、申请收录 / 在线留言、完整后台与可视化安装向导。

**仓库**：[https://github.com/2016xyz/xianimao-nav](https://github.com/2016xyz/xianimao-nav)（公开 · MIT）

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A57.4-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B%20%2F%208.0-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![GitHub](https://img.shields.io/badge/GitHub-2016xyz%2Fxianimao--nav-181717?logo=github)](https://github.com/2016xyz/xianimao-nav)

---

## 功能概览

| 模块 | 说明 |
|------|------|
| 前台导航 | 搜索引擎切换、快捷入口、自营站点、项目展示、实用工具、友情链接 |
| 今日热榜 | 多源真实抓取（微博、吾爱破解、B 站、百度、Linux.do 等），可后台开关与排序，带缓存 |
| 子页面 | 申请收录、在线留言、关于我们、联系我们（可配置页脚显示） |
| 邮箱验证码 | 收录 / 留言须邮箱 + 验证码后方可提交，成功后邮件通知管理员 |
| 后台管理 | 站点设置、引擎 / 快捷方式 / 站点 / 项目 / 工具 / 友链、热榜、留言、SMTP、AI、改密 |
| OAuth 凭证 | Linux.do Connect OAuth2；吾爱破解 Cookie 授权流（用于登录态热榜） |
| 安装向导 | 环境检测、数据库测试、一键建表与种子数据、安装锁 |
| 安全防护 | CSP、HttpOnly Cookie、CSRF、参数化 SQL、输入强校验、HTML 消毒、危险 URL 拦截 |

## 技术栈

- **后端**：PHP ≥ 7.4，PDO MySQL（`ATTR_EMULATE_PREPARES=false`）
- **前端**：服务端渲染 + Bootstrap 5 / jQuery（本地静态资源）
- **存储**：MySQL 为主，`content.json` / 文件配置为回退与密钥落盘
- **邮件**：原生 SMTP（SSL / TLS / none）+ HTML 邮件模板
- **安全**：见 [docs/SECURITY.md](docs/SECURITY.md)

## 目录结构

```
.
├── admin/              # 后台（登录、CRUD、SMTP、OAuth、AI…）
├── api/                # 前台 AJAX（如发送表单验证码）
├── assets/             # 前台 CSS / JS / 图片
├── config/             # 配置（密钥类文件已 gitignore）
│   └── database.example.php
├── data/               # 内容 JSON、缓存目录
├── docs/               # 部署 / 架构 / 安全文档
├── includes/           # 核心库（bootstrap、mailer、security、热榜…）
├── scripts/            # 运维与自检脚本
├── install.php         # 安装向导
└── index.php           # 前台首页
```

## 快速开始

### 环境要求

- PHP ≥ 7.4，扩展：`pdo`、`pdo_mysql`、`json`、`mbstring`、`openssl`、`curl`；可选 `gd`（图形验证码）
- MySQL 5.7+ / 8.0+（`utf8mb4`）
- Web 服务器：Apache / Nginx，文档根指向项目根目录

### 安装步骤

1. **克隆仓库**

```bash
git clone https://github.com/2016xyz/xianimao-nav.git
cd xianimao-nav
```

2. **配置 Web**  
   将站点根目录指到本项目；确保 `config/`、`data/` 对 PHP 进程可写。

3. **打开安装向导**

```
http://你的域名/install.php
```

按提示填写数据库与管理员账号，完成安装。成功后会生成 `config/database.php` 与 `config/install.lock`。

4. **登录后台**

```
http://你的域名/admin/login.php
```

5. **（推荐）配置 SMTP**  
   后台 → SMTP 设置：启用发信后，登录邮箱验证码、留言 / 收录验证码与管理员通知才能真正发信。

> 安装完成后建议限制或删除 `install.php` 的公网访问（项目已禁止已安装后的公开重装）。

### 本地 PHP 内置服务器（开发）

```bash
php -S 127.0.0.1:8080 -t .
# 浏览器访问 http://127.0.0.1:8080/install.php
```

### 手动数据库配置

若不用向导，可复制示例文件：

```bash
# Windows
copy config\database.example.php config\database.php

# Linux / macOS
cp config/database.example.php config/database.php
```

导入 `includes/schema.sql` 与 `includes/schema_extra.sql`，并自行写入 `config/install.lock`。

## 后台能力摘要

- **站点设置**：站名、页脚、关于 / 联系 HTML、友链开关、留言开关等  
- **内容管理**：搜索引擎、快捷入口、自营站点（支持 AI 生成介绍）、项目、工具、友链  
- **热榜**：启用源、排序；Linux.do / 吾爱凭证（OAuth 或 Cookie）  
- **留言**：查看申请收录与在线留言、改状态、删除  
- **SMTP**：发信开关、加密、登录二次邮箱验证码  
- **AI**：兼容 OpenAI 风格 API，后台可测连通并拉模型列表  

默认品牌与联系邮箱（种子 / 默认内容）：

- 站点名：**夏尼猫网址导航**
- 邮箱：`i@2016xlx.cn`

## 安全与自检

```bash
# 安全模块与输出转义 / URL 白名单等
php scripts/security_verify.php

# 功能与品牌等检查（如有）
php scripts/review_verify.php
```

更多说明：

- [docs/DEPLOY.md](docs/DEPLOY.md) — 部署与 Nginx / Apache 示例  
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — 架构与数据流  
- [docs/SECURITY.md](docs/SECURITY.md) — 安全设计与运维清单  
- [CONTRIBUTING.md](CONTRIBUTING.md) — 贡献指南  

## 截图建议

可在本仓库 Issues / Wiki 中补充前台首页、热榜、后台登录与 SMTP 配置截图。

## 文档索引

| 文档 | 内容 |
|------|------|
| [README.md](README.md) | 功能概览、快速开始 |
| [docs/DEPLOY.md](docs/DEPLOY.md) | 生产部署、Nginx/Apache、权限与运维 |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | 目录、请求流、数据存储 |
| [docs/SECURITY.md](docs/SECURITY.md) | CSP/CSRF/SQL/XSS 与密钥清单 |
| [CONTRIBUTING.md](CONTRIBUTING.md) | 贡献约定 |
| [LICENSE](LICENSE) | MIT |

## 许可证

本项目采用 [MIT License](LICENSE)。

## 致谢

- 界面与交互参考了公开导航站的产品形态，实现为独立 PHP 代码库  
- 热榜数据来自各平台公开页面 / 接口，请遵守对方服务条款与访问频率限制  
- 作者站点：[www.2016xlx.cn](https://www.2016xlx.cn) · 联系：`i@2016xlx.cn`
