# 代码审查与修复清单

审查日期：2026-07-24  
范围：全站 PHP / 后台 / includes / 前台入口（静态分析 + 针对性修复）

## 1. 完整性

| 项 | 结论 |
|----|------|
| 入口 bootstrap | 前台 / 后台 / API 均 require；`install.php` 有意不加载 bootstrap |
| 重复函数 | 已修复历史 `setting_get` 双重定义；当前 Web 路径无 redeclare |
| 未实现函数 | 生产路径调用均有定义 |
| 密钥 API | `setting_*` / `secret_blob_*` 统一；旧 JSON 只读迁移 |

## 2. 逻辑性

| 问题 | 处理 |
|------|------|
| 开源项目保存丢失 `tag` | 表单增加标签列；`load_content` 补 `id`/`tag` |
| `projects.php` 错误 delete 分支 | 移除；统一前端删行 + 整表保存 |
| SMTP blob 被旧分散 key 覆盖 enabled | 有 blob 时不再用分散 key 覆盖 |
| 密钥双写 / 落盘 | 主存 `secret_*`；Cookie/密码不再写分散 settings；52pojie 不再写文件 |

## 3. 正确性 / 安全

| 问题 | 处理 |
|------|------|
| 存储型 XSS（about/contact HTML） | 收紧 `security_sanitize_html`：去 style/on*、危险协议、href 白名单 |
| 出站 HTTPS 关证书校验 | 默认开启；`security_ssl_verify_peer()`，可用 settings `ssl_verify_peer=0` 关闭 |
| AI 保存失败文案误导 | 改为提示数据库 / settings 写权限 |
| 审查断言 | `scripts/review_verify.php` 增加 SEO/消毒/TLS/密钥 API 检查 |

## 4. 未改或可接受项

- CLI `scripts/probe_*.php` 仍可能关闭 TLS（仅运维探测，不进 Web）
- 管理员账号密码仍在 `admins` 表（正确）
- `config/database.php` 仍为本地连库文件
- 未跑完整 PHPUnit（环境无全局 `php` CLI 时依赖静态 + `review_verify`）

## 5. 回归建议

```bash
php scripts/review_verify.php
# 浏览器：后台保存站点设置 / 开源项目标签 / 关于页 HTML / SMTP 开关
```

自签证书环境若出站失败，在 settings 写入：`ssl_verify_peer` = `0`（仅建议内网调试）。
