# CHANGELOG — FlintHub 1.0.0（发行版）

> 发行基准代码版本：`FLINTHUB_VERSION = V1.0.0`
> 编码规范：全部文件 UTF-8 without BOM + LF；时间统一秒级时间戳；PHP ≥ 8.0 / SQLite ≥ 3.33 兼容基线（无 RETURNING）
>
> 本文件记录 **1.0.0 发行版** 相对前身（SoSite 5.0，MySQL 单库时代）的关键里程碑与构成要点。后续版本更新按序追加。

---

## v1.0.0（2026-09-13）— 首个发行版

### 架构重写：SplitDB 纯 SQLite 分片引擎（取代 MySQL）

- 由 SoSite 5.0（MySQL）迁移为 **SplitDB v2.3 纯 SQLite 分片引擎**，MySQL 依赖归零。
- **季度时序分区 + 哈希分桶**多文件架构；`bucket/active/{季度}/{0..31}.sqlite` 分片桶 + `data/meta/main_index.sqlite` 主索引 + `extern/` 外置正文（`.txt` 存量 + `.bin/.idx` 新格式）。
- **智能扩容**：桶数 32→64→128 平滑升级，`bucketForWrite()` 保证零迁移（历史数据永不搬迁）。
- **归档只读**：`archive_mark.php` 每日标记 + 前台归档横幅（管理员豁免），旧帖不参与回复/编辑。
- **统一 PRAGMA**：WAL + `busy_timeout=5000` + `synchronous=NORMAL`，并发读写均衡。
- 引擎组件（`app/SplitDB/`）：`Schema` / `ShardRouter` / `DBFactory`（LRU 连接池）/ `ExternStorage` / `IDGenerator` / `Queue` / `QueueConsumer` / `ViewCounter` / `SearchIndexStore` / `CountingPDO` / `BucketAutoScaler` / `ExternMerger` / `ExternNamespaceMigrator` / `ExternIdxRepairer`。

### 核心框架（app/Core）

- `Autoloader`（PSR-4 风格，`app\` 与 `Plugin\` 双命名空间，路径穿越防护）/ `Router`（分组、`{param}` 参数、BASP_PATH 适配）/ `Controller`（安全响应头、防开放重定向、管理员二次验证、插件钩子）/ `Database`（业务库 + Session 库物理隔离、SQL 计数）/ `Model`（`$fillable` 白名单、`buildWhere` 安全解析 / `sanitizeOrderBy`）/ `TemplateCompiler`（`{$var}`/`{if}`/`{loop}` 单大括号语法/布局继承/`$this->icon()` 图标系统/`$this->t()` 翻译/分页/`timeAgo`）。

### 业务能力（app/Helpers & app/Models）

- 认证 `Auth` / 权限矩阵 `Permission` / 积分等级 `Points`（`award`/`deduct` 原子扣减、嵌套事务感知、徽章渲染）/ 搜索 `Search` + 中文二元切词 `Segmenter` / 静态页缓存 `PageCache`（访客 3~10ms 命中、语言分键）/ 频率限制 `RateLimiter`（Session + IP 双重、文件锁防穿透、有限退避）/ PoW 工作量证明 `PoW` / 图片验证码 `Captcha` / CSRF `Csrf` / 记住登录 `RememberMe` / 邮件 `Mailer`（SMTP 密码 AES-256-GCM 加密，兼容旧版 AES-256-CBC 密文）/ 体检净化 `Content` / 上传 `Upload`（白名单 + MIME + 强随机名 / 缩略图）/ 审计 `AuditLog` / 主题 `Theme` / 标签 `Tag` / 多语言 `I18n`。
- 模型：`Thread`（1225 行，完全感知分片读写）/ `Post` / `User` / `Category`（统计缓存 + 最新帖快照）/ `Blog` / `BlogCategory` / `BlogComment` / `Message` / `Setting` / `Tag`。

### 安全体系（纵深防御）

- SQL 注入：全量参数化 + `buildWhere` 白名单解析器；XSS：模板自动转义 + `Content::sanitizeHtml` 白标签/白属性。
- CSRF：`hash_equals` 常量时间比较 + 30 分钟轮换 + 一次性消费；文件上传：扩展名白名单 + `finfo` MIME + 随机文件名 + 缩略图像素炸弹防护。
- 路径穿越：Autoloader `realpath` 校验、类名禁止 `..`、语言码白名单清洗；防滥用：限流 + PoW + 验证码；安全响应头全套；审计日志（90 天保留、IP/UA 追踪、可信代理 CIDR 校验 XFF）。
- 敏感目录 Web 防护：`data/` `protected/` `cli/` `plugins/*/data/` 禁止直访（Apache `.htaccess` / Nginx `nginx-server.conf` 双轨随程序走）；`assets/uploads/` 禁 PHP 执行。

### 插件体系（内置 17 个插件，零侵入核心）

- 完整钩子系统（`init_after` → `route_register` → `controller_view_before` → `layout_head_end` → `thread_create_after` … 共 40+ 钩子）、插件独立 SQLite 数据库（`Plugin::db()` 自动建 `data/` + 防下载）、语言包自动合并、权限白名单强制模式（`system:settings`/`route:admin`/`net:fetch`）。
- 内置插件：`announcements` / `content_review` / `daily_checkin` / `draft_saver` / `edit_info` / `floor_reply` / `forum_required_tag` / `friend_links` / `invite` / `medal` / `mod_system` / `post_favorite` / `red_packet` / `seo` / `single_page` / `task_center` / `user_profile`。

### 前端

- 原生 JS + **Alpine.js v3** + **htmx v2**（无第三方重型框架；本地重打包 `assets/lib/fhstate.js` / `fhajax.js`，已补版权头）；`mn-` CSS 系统 + `--mn-*` 主题变量（5 个内置配色 + 暗夜星辰夜间模式）；编辑器（Markdown/富文本）、PoW 前端计算、图片灯箱;PWA（`sw.js` / manifest）。
- 响应式：一套 HTML 同时适配 PC / 移动端；移动端抽屉导航查询论坛/博客分类 + 博客月度归档（`Blog::getArchivesCached()`）。

### 运维 / 工具

- 一键安装 `install.php`（环境检测 → SplitDB 初始化 → 管理员创建，二次运行拒绝）；维护模式。
- `cli/` 20+ 正式脚本：迁移/归档/重建/瘦身/种子/队列消费者等；`cron_trigger.php` 定时消费。
- 多语言：`lang/zh.php` `en.php` `zh_tw.php` 三语，各 **1220 key** 全量对齐，缺 key 回退链永不白屏。

---

### 历史版本索引

> 更多早期迭代（P38~P41 及 SoSite 时代）细节见源码 `docs/` 相关文档。本发行版为**完整包**形态，升级采用**完整包覆盖**（保留 `data/`、`protected/`、`assets/uploads/` 不动），不依赖 `update/` 增量目录。