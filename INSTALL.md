# FlintHub 1.0 安装文档

> 本文档覆盖：环境要求、目录结构、安装步骤、目录写权限、Web 服务器配置规则（Apache / Nginx / IIS）、二级目录部署、升级与维护。

---

## 一、环境要求

| 项 | 要求 |
|:---|:---|
| PHP | ≥ 8.0（推荐 8.2+） |
| PDO | 必须加载 `pdo` + `pdo_sqlite` 扩展 |
| SQLite | ≥ 3.33（`ON CONFLICT` / WAL 依赖；兼容基线不要求 `RETURNING`） |
| mbstring | 需要（中文处理） |
| fileinfo | 需要（上传 MIME 检测） |
| curl | 需要（远程拉图） |
| 图像 | `gd` 或 `imagick`（二选一，缩略图） |
| openssl | 需要（邮件 SMTP 密码 AES 加密） |
| MySQL / Redis | **不需要**（零外部依赖，纯 SQLite 分片） |

> `install.php` 会自动检测以上全部环境项，任一项不满足会明确报错。

---

## 二、目录结构

```
flinthub1.0.0/
├── index.php           前端控制器（路由分发入口）
├── config.php          （安装时自动生成到根目录）全局配置 / 数据目录 / 上传 / 邮件 / SMTP 密钥常量
├── version.php         版本单一来源（install.php 与 config.php 共同引用）
├── install.php         Web 安装程序
├── cron_trigger.php    定时任务 Web 触发入口
├── sw.js               PWA Service Worker
├── .htaccess           Apache 伪静态 + 敏感目录防护
├── nginx-server.conf   Nginx 站点完整配置模板
├── web.config          IIS 配置
├── app/                核心应用代码（Core / Helpers / Models / Controllers / SplitDB / Views）
├── assets/             前端静态资源（css / js / lib / img / medals / pwa / uploads）
├── cli/                命令行维护脚本（正式工具）
├── lang/               语言包（zh / en / zh_tw）
├── plugins/            插件目录（一目录一插件）
├── docs/               说明文档（插件开发规范等；其余见根目录 README / INSTALL / CHANGELOG / LICENSE）
├── data/               运行时数据目录（全新安装由 install.php 自动重建，仅含 web.config 防护）
└── protected/          受保护目录（运行产物，全新安装自动重建，仅含防护配置）
```

**运行数据说明**：`config.php` 由 `install.php` 在**全新安装时自动生成**并写入根目录（内含数据目录、上传、邮件等配置与随机生成的 SMTP 加密密钥 `MAIL_PASS_KEY`）。`data/`（SQLite 分片库、外置正文、运行时缓存、锁、日志）与 `protected/`（会话、限流缓存、错误日志）均为**运行时产物**，同样由 `install.php` 的 `Schema::bootstrap()` 自动创建，发行包不携带任何站点数据。

---

## 三、安装步骤

1. **上传文件**：将本目录全部内容上传到站点根目录（或子目录，见第六节）。
2. **配置服务器伪静态**（Apache 用 `.htaccess`，Nginx 用 `nginx-server.conf`，见第五节）。
3. **设置写权限**：确保以下路径**可写**（全新安装时相关文件/目录尚不存在，需父目录可写）：
   - 根目录（安装时会把 `config.php` 自动生成到根目录）
   - `data/`
   - `protected/sessions`、`protected/cache`、`protected/rate_cache`
   - `assets/uploads/`
   - Linux 建议：`chmod -R 755` 目录、`chmod 644` 文件；`data/` 与 `protected/` 建议 `770`。
4. **访问安装向导**：浏览器打开 `http://你的域名/install.php`，跟随三步：
   - 第 1 步：环境检测（PHP 版本 / PDO SQLite / SQLite 版本 / 扩展 / 目录可写）。
   - 第 2 步：SplitDB 数据初始化（自动 `Schema::bootstrap()` 建库建表、创建目录树、写入防护文件）。
   - 第 3 步：创建管理员账号。
5. **安装完成**：`protected/.install_done` 生成。**再次访问 `install.php` 会被拒绝**（判定依据还包括 `config.php` 含 SplitDB 配置、`data/meta/business.sqlite` 是否已存在，杜绝二次初始化覆盖数据）。
6. **登录后台**：访问 `/admin`（账号即第 3 步创建的管理员），配置站点基本设置、发件邮箱、站点模式（门户 / 论坛 / 博客）、插件激活等。

---

## 四、常规维护

- **定时任务**：`cron_trigger.php` 通过 HTTP 触发队列消费与低频维护，建议每 5 分钟调用一次：
  ```bash
  */5 * * * * curl -s "http://your-site/cron_trigger.php?key=YOUR_CRON_KEY"
  ```
  若在 `config.php` 设置了 `CRON_KEY`，请求需携带 `?key=...` 才执行（建议设置为强随机串）。
- **SMTP 密钥**：SMTP 密码以 **AES-256-GCM** 加密存储（兼容旧版 AES-256-CBC 密文），加密密钥为 `config.php` 中的常量 `MAIL_PASS_KEY`（安装时随机生成，一般无需改动，请勿泄露）。SMTP 密码本身在后台「发件设置」中填写即可。
- **提交地址防钓鱼**：部署在反向代理后可配置环境变量 `FLINTHUB_SITE_URL` 或在 `config.php` 定义 `SITE_URL_OVERRIDE` 固定站点域名。

---

## 五、Web 服务器配置规则

### 5.1 Apache（`.htaccess`，随包内置）

确保站点目录开启 `AllowOverride FileInfo Indexes`。内置 `.htaccess` 规则：

1. **敏感目录禁止直访**：`data/` `protected/` `cli/` → 403
2. **插件数据目录禁止直访**：`plugins/[^/]+/data/` → 403
3. **上传目录禁止 PHP 执行**：`assets/uploads/.*\.php` → 403
4. **`/repo/` 仓库路由**（供仓库类插件使用，如代码/资源仓库）：非真实文件转发到前端控制器
5. **常规伪静态**：非真实文件/目录转发到 `index.php/$1`
6. `Options -Indexes`（禁止目录列表）、`AddDefaultCharset UTF-8`、`.webmanifest` MIME 映射

### 5.2 Nginx（`nginx-server.conf`，随包内置）

`nginx-server.conf` 是一份可直接使用的完整站点配置。接入方式（一次性）：

1. 在 `nginx.conf` 的 `http` 块中、`include vhosts/*.conf;` **之前**加一行：
   ```nginx
   include /path/to/your/site/nginx-server.conf;   # 换成你的程序根目录实际路径
   ```
2. 重新加载：
   ```bash
   nginx -t && nginx -s reload
   ```

要点（该文件已内置）：
- `location ~ ^/(data|protected|cli)/` → `deny all; return 403;`（敏感目录）
- `location ~ ^/plugins/[^/]+/data/` → `deny all; return 403;`（插件数据目录）
- `location ~ ^/assets/uploads/.*\.php$` → `deny all; return 403;`（上传目录禁 PHP 执行）
- `location ^~ /repo/` → `try_files $uri /index.php;`（仓库浏览，优先于 `\.php` 规则）
- `location = /assets/pwa/manifest.webmanifest` → `default_type application/manifest+json;`
- `location ^~ /assets/uploads/covers/` → `default_type image/svg+xml;`
- `location /` → 伪静态 `rewrite ^/(.*)$ /index.php/$1 last;`
- `location ~ \.php(.*)$` → `fastcgi_pass` 指向你实际的 PHP-FPM 监听端口（常见 `9000`）

> **切换 PHP 版本**：面板重写 vhost 可能覆盖手写规则，本文件由 nginx 直接读取故不受影响；仅需把 `fastcgi_pass` 改为当前 PHP 版本对应的实际 FastCGI 地址（如 `127.0.0.1:9000` 或 `unix:/run/php-fpm.sock`）后 `nginx -s reload`。

### 5.3 IIS（`web.config`）

随包内置 `web.config`，配置 URL 重写与敏感目录隐藏；`data/`、`protected/` 内也各有 `web.config` 用于禁止直接访问。需安装 IIS URL Rewrite 模块。

---

## 六、二级目录部署

支持将程序部署在任意子目录：

```php
// config.php —— 部署在 /forum 子目录示例
define('BASE_PATH', '/forum');
```

Router、Controller、PageCache、I18n 等全部自动适配 `BASE_PATH`。此时：
- `install.php` 访问路径为 `/forum/install.php`（安装页自动推导 BASE_PATH）。
- `.htaccess` / `nginx-server.conf` 中的 `index.php` 转发规则保持相对，无需改动。

---

## 七、升级

见 [README.md](./README.md) 第三节「安装 / 升级」。要点：

1. 备份 `data/` 与 `protected/`。
2. 采用**完整包覆盖**：下载最新完整包，解压覆盖程序目录（保留 `data/`、`protected/`、`assets/uploads/`、`config.php` 不动）。
3. 视版本提示运行 `cli/` 迁移/重建脚本（如搜索重建、摘要回填）。
4. 清理前台页面缓存；Apache 确认 `.htaccess` 生效；Nginx 执行 `nginx -t && nginx -s reload`。

---

## 八、常见问题（FAQ）

- **`install.php` 提示已安装**：避免二次初始化覆盖数据而设计；确需重装时，请停止站点 → 备份并删除 `data/`、`protected/` 与根目录 `config.php` → 重新访问 `install.php`。
- **Nginx 伪静态 404**：面板重写 vhost 覆盖了手写规则 → 改用随包的 `nginx-server.conf`（见 5.2）。
- **post 502 / PHP 无效**：`fastcgi_pass` 端口与当前 PHP 版本不匹配 → 调整端口。
- **中文乱码**：确认 `AddDefaultCharset UTF-8`（Apache）或 charset 配置（Nginx）；全部文件 UTF-8 无 BOM。
- **SMTP 发信失败**：确认服务器开放对应端口（465 SSL / 587 TLS），并在后台邮件设置填写授权码（非登录密码）。

---

> 若遇前端链路异常，可参照业务维护脚本（`cli/` 下的迁移/重建/诊断工具）逐项排查。