# 贡献指南

感谢关注 **夏尼猫网址导航**。欢迎提交 Issue 与 Pull Request。

## 开发环境

1. 克隆仓库并按 [README.md](README.md) / [docs/DEPLOY.md](docs/DEPLOY.md) 完成本地安装  
2. PHP ≥ 7.4，MySQL 可用  
3. 修改代码后可运行：

```bash
php scripts/security_verify.php
php -l path/to/file.php
```

## 代码约定

- 用户输入一律经 `security_*` 校验后再入库 / 使用  
- SQL 使用预处理，禁止拼接用户字符串  
- 页面输出使用 `e()`；后台 HTML 使用 `sanitize_admin_html` / `security_sanitize_html`  
- 新增前端逻辑放外链 JS，勿写内联 `<script>`（违反 CSP）  
- 后台页面脚本：`admin_layout_end(['assets/your.js'])`  
- 密钥与本地配置写入 `config/*.json` 等已 ignore 路径，勿提交  

## 提交说明

- 使用清晰的中文或英文 commit message，说明「为什么」  
- 一个 PR 尽量聚焦一个主题  
- 安全相关修复请在描述中说明影响面  

## 行为准则

- 尊重他人，不提交恶意代码或故意降低安全性的改动  
- 热榜与外部接口调用保持合理频率  

## 许可证

贡献代码默认同意以项目 [MIT License](LICENSE) 授权。
