<?php
/**
 * FlintHub 1.0 (SplitDB) — 数据库骨架（Schema::bootstrap）
 * 建库骨架：
 *   1. 建完整目录树（meta / task_queue / bucket active+archive / extern / lock / log / runtime）
 *   2. 建 meta 库并幂等建表：
 *        business.sqlite      → 全部非分片业务表（用户/版块/帖子/回复/私信/附件/博客/设置/在线/标签/投票/积分/权限/审计/密码重置）
 *        main_index.sqlite    → topic_index + reply_index（帖子主索引，分片后启用）
 *        meta/search/search_{YYYYQn}.sqlite → 自研倒排索引表（SearchIndexStore，季度分文件）
 *        sessions.sqlite      → 独立会话库（与业务库隔离，避免写锁互相影响）
 *        global_id.sqlite     → id_generator（IDGenerator::initTable）
 *        meta/task_queue/     → queue_0~2.sqlite（Queue::initTable）
 *
 * 全部 DDL 为 SQLite 语法；全部 CREATE TABLE IF NOT EXISTS，幂等可重复执行。
 * 桶分片文件不预建：首次写入时 ShardRouter::ensureBucketDir 自动创建（零启动成本）。
 *
 * @file app/SplitDB/Schema.php
 * @package app\SplitDB
 */

namespace app\SplitDB;

use PDO;

class Schema
{
    // meta 库相对路径（相对 DATA_PATH）
    public const BUSINESS     = 'meta/business.sqlite';
    public const MAIN_INDEX   = 'meta/main_index.sqlite';
    public const SESSIONS     = 'meta/sessions.sqlite';
    public const GLOBAL_ID    = 'meta/global_id.sqlite';
    public const TASK_QUEUE_DIR = 'meta/task_queue';

    /**
     * 完整引导：建目录树 + 建全部 meta 库与表（幂等）
     *
     * @return array<string,string> 生成的目录/库清单（诊断用）
     */
    public static function bootstrap(?string $dataPath = null): array
    {
        $root = rtrim($dataPath ?: ShardRouter::dataPath(), '/\\');
        $created = [];

        // ---------- 1. 目录树 ----------
        $quarter = ShardRouter::quarter();
        $dirs = [
            'meta',
            self::TASK_QUEUE_DIR,
            SearchIndexStore::DIR_REL,     // 搜索索引季度分文件（search_{YYYYQn}.sqlite）
            'bucket/active/' . $quarter,   // 当前季度分片目录
            'bucket/archive',              // 18 个月以上冷数据
            'extern',
            'lock',
            'log',
            'runtime',
        ];
        foreach ($dirs as $dir) {
            $full = $root . '/' . $dir;
            if (!is_dir($full)) {
                @mkdir($full, 0755, true);
                $created[] = $full;
            }
        }

        // ---------- 1.5 敏感目录 web 访问防护 ----------
        // data/ 为 SQLite 分片库（密码哈希/会话/帖子数据），需防 HTTP 直接下载。
        // 本目录由安装时动态创建，静态 .htaccess 无法随代码分发 → 必须在 bootstrap 内生成
        // （Apache 生效，Nginx 由 nginx-server.conf 的 location deny 兜底）。幂等：已存在则跳过。
        self::ensureProtectionFiles($root);

        // ---------- 2. meta 库建表（幂等） ----------
        self::ensureBusiness($root);
        self::ensureMainIndex($root);
        self::ensureSessions($root);
        self::ensureGlobalId($root);
        for ($q = 0; $q < Queue::QUEUE_COUNT; $q++) {
            Queue::getQueueDb($q, $root);
        }

        // ---------- 3. 默认数据种子（幂等，缺失才插入） ----------
        self::seedDefaults($root);

        return $created;
    }

    /**
     * 数据根目录 web 访问防护（幂等）
     *
     * data/ 下 SQLite 分片库（business/main_index/sessions/global_id/队列）与 extern 正文
     * 需防 HTTP 直接下载（含密码哈希/会话/全量数据）。
     * 在 data/ 根生成两类防护文件（Apache/IIS 双覆盖，均已存在则跳过）：
     *   - .htaccess      → Apache（Require all denied，2.2/2.4+ 兼容）
     *   - web.config     → IIS / Win 主机（requestFiltering hiddenSegments 返回 404）
     * Nginx 环境由 nginx-server.conf 的 `location ~ ^/(data|protected)/` deny 规则兜底。
     */
    private static function ensureProtectionFiles(string $root): void
    {
        // --- Apache: .htaccess ---
        $ht = $root . '/.htaccess';
        if (!file_exists($ht)) {
            $content = "# FlintHub — 禁止直接访问数据目录（Apache）\n"
                . "# data/ 下为 SQLite 分片库（含用户密码哈希/会话/帖子数据），一律拒绝 HTTP 直访\n"
                . "<IfModule mod_authz_core.c>\n"
                . "    Require all denied\n"
                . "</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n"
                . "    Order allow,deny\n"
                . "    Deny from all\n"
                . "</IfModule>\n"
                . "<FilesMatch \".*\">\n"
                . "    <IfModule mod_authz_core.c>\n"
                . "        Require all denied\n"
                . "    </IfModule>\n"
                . "    <IfModule !mod_authz_core.c>\n"
                . "        Order allow,deny\n"
                . "        Deny from all\n"
                . "    </IfModule>\n"
                . "</FilesMatch>\n";
            // 防护文件写入失败必须发声（安全控制 fail-silent = 数据目录裸奔无感知）
            if (@file_put_contents($ht, $content, LOCK_EX) === false) {
                \error_log('Schema: data/ .htaccess 防护文件写入失败（Apache 下 data 目录可能可直访）: ' . $ht);
            }
        }

        // --- IIS / Win 主机: web.config（hiddenSegments 命中即 404，不暴露目录存在性） ---
        $wc = $root . '/web.config';
        if (!file_exists($wc)) {
            $content = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                . "<!--\n"
                . "  FlintHub — IIS (Windows) 禁止直接访问数据目录\n"
                . "  data/ 下为 SQLite 分片库（密码哈希/会话/帖子数据）\n"
                . "  仅 IIS 生效；Apache 由同目录 .htaccess、Nginx 由 nginx-server.conf 兜底\n"
                . "-->\n"
                . "<configuration>\n"
                . "  <system.webServer>\n"
                . "    <security>\n"
                . "      <requestFiltering>\n"
                . "        <hiddenSegments>\n"
                . "          <add segment=\"data\" />\n"
                . "        </hiddenSegments>\n"
                . "      </requestFiltering>\n"
                . "    </security>\n"
                . "  </system.webServer>\n"
                . "</configuration>\n";
            // 防护文件写入失败必须发声（IIS 下 data 目录可能可直访）
            if (@file_put_contents($wc, $content, LOCK_EX) === false) {
                \error_log('Schema: data/ web.config 防护文件写入失败（IIS 下 data 目录可能可直访）: ' . $wc);
            }
        }
    }

    /**
     * 默认数据种子（幂等，仅缺失时插入）：
     *   用户组 1~4（普通会员/VIP/版主/管理员，与旧 init.php 对齐）
     *   等级配置 1~11 级（与 Points 默认一致）
     *   站点默认设置（site_name/posts_per_page 等）
     *   默认博客分类 4 类
     */
    public static function seedDefaults(?string $dataPath = null): void
    {
        $db = self::businessDb($dataPath);

        // 用户组（固定 id，保证 group_id 语义：4 = 管理员）
        $groups = [
            [1, '普通会员', '#666', 1],
            [2, 'VIP会员', '#f39c12', 0],
            [3, '版主', '#27ae60', 0],
            [4, '管理员', '#e74c3c', 0],
        ];
        $insGroup = $db->prepare('INSERT OR IGNORE INTO user_groups (id, name, color, is_default) VALUES (:id, :name, :color, :def)');
        foreach ($groups as $g) {
            $insGroup->execute([':id' => $g[0], ':name' => $g[1], ':color' => $g[2], ':def' => $g[3]]);
        }

        // 等级配置（11 级，与 Points::ensureTable 默认一致）
        $levels = [
            [1, '新手小白', 0, '🌰', '#999'], [2, '初入论坛', 10, '🌱', '#4caf50'], [3, '小有名气', 40, '🌲', '#2196f3'],
            [4, '积极会员', 90, '🌳', '#9c27b0'], [5, '认证粉丝', 160, '🌴', '#ff9800'], [6, '社区明星', 250, '🌟', '#f44336'],
            [7, '评论家', 360, '🎯', '#e91e63'], [8, '社区助手', 490, '🏆', '#673ab7'], [9, '版主级别', 640, '👑', '#3f51b5'],
            [10, '社区顾问', 810, '💡', '#ff5722'], [11, '社区元老', 1000, '👑', '#ff6f00'],
        ];
        $insLevel = $db->prepare('INSERT OR IGNORE INTO level_config (level, title, required_points, icon, color) VALUES (:l, :t, :p, :i, :c)');
        foreach ($levels as $lv) {
            $insLevel->execute([':l' => $lv[0], ':t' => $lv[1], ':p' => $lv[2], ':i' => $lv[3], ':c' => $lv[4]]);
        }

        // 站点默认设置
        $defaults = [
            'site_name' => defined('DEFAULT_SITE_NAME') ? DEFAULT_SITE_NAME : 'FlintHub 精品论坛',
            'site_description' => '一个简洁现代的 PHP 论坛 + 博客系统（SplitDB）',
            'posts_per_page' => defined('POSTS_PER_PAGE') ? (string)POSTS_PER_PAGE : '20',
            'threads_per_page' => defined('THREADS_PER_PAGE') ? (string)THREADS_PER_PAGE : '20',
            'blogs_per_page' => '12',
            'allow_registration' => '1',
            'allow_attachments' => '1',
            // 自适应验证码防线：PoW 难度（1-8，越高越难）
            'pow_difficulty' => '4',
            // 队列任务处理模式：sync=同步直写 / cron=Cron 定时触发 / cli=CLI 常驻消费
            'queue_mode' => 'sync',
            // SplitDB 智能扩容（新装即带默认值，避免首次访问设置页触发全量桶扫描/读取走 DB 回源）：
            // 单桶最大安全主题帖数（后台白名单 3万/5万/8万/10万 之一）；自动扩容开关默认关闭，阈值兼容保留
            'bucket_safe_capacity'  => '50000',
            'auto_expand_enabled'   => '0',
            'auto_expand_threshold' => '0',
        ];
        $insSetting = $db->prepare('INSERT OR IGNORE INTO settings ("key", value) VALUES (:k, :v)');
        foreach ($defaults as $k => $v) {
            $insSetting->execute([':k' => $k, ':v' => $v]);
        }

        // 默认博客分类（固定 id）
        $blogCats = [
            [1, '技术分享', '技术文章和教程', 1],
            [2, '生活随笔', '生活感悟和随笔', 2],
            [3, '学习笔记', '学习心得和笔记', 3],
            [4, '项目经验', '项目开发经验分享', 4],
        ];
        $insBlogCat = $db->prepare('INSERT OR IGNORE INTO blog_categories (id, name, description, sort_order) VALUES (:id, :name, :desc, :sort)');
        foreach ($blogCats as $bc) {
            $insBlogCat->execute([':id' => $bc[0], ':name' => $bc[1], ':desc' => $bc[2], ':sort' => $bc[3]]);
        }

        // 默认论坛版块分类（固定 id=1，便于安装后立即测试发帖；后台可改名/删除）
        $forumCats = [
            [1, '默认版块', '默认论坛分类，安装后可直接在此发帖测试（可在后台重命名或删除）', 1],
        ];
        $insForumCat = $db->prepare('INSERT OR IGNORE INTO categories (id, name, description, sort_order, icon, show_icon) VALUES (:id, :name, :desc, :sort, :icon, :show)');
        foreach ($forumCats as $fc) {
            $insForumCat->execute([':id' => $fc[0], ':name' => $fc[1], ':desc' => $fc[2], ':sort' => $fc[3], ':icon' => 'forum', ':show' => 1]);
        }
    }

    // ==================== 各库连接入口 ====================

    public static function businessDb(?string $dataPath = null): PDO
    {
        $root = rtrim($dataPath ?: ShardRouter::dataPath(), '/\\');
        return DBFactory::getConnection($root . '/' . self::BUSINESS);
    }

    public static function mainIndexDb(?string $dataPath = null): PDO
    {
        $root = rtrim($dataPath ?: ShardRouter::dataPath(), '/\\');
        return DBFactory::getConnection($root . '/' . self::MAIN_INDEX);
    }

    public static function sessionDb(?string $dataPath = null): PDO
    {
        $root = rtrim($dataPath ?: ShardRouter::dataPath(), '/\\');
        return DBFactory::getConnection($root . '/' . self::SESSIONS);
    }

    public static function globalIdDb(?string $dataPath = null): PDO
    {
        $root = rtrim($dataPath ?: ShardRouter::dataPath(), '/\\');
        return DBFactory::getConnection($root . '/' . self::GLOBAL_ID);
    }

    // ==================== 建表（幂等） ====================

    /**
     * business.sqlite：全部非分片业务表（SQLite DDL）
     */
    private static function ensureBusiness(string $root): void
    {
        $db = DBFactory::getConnection($root . '/' . self::BUSINESS);

        // 用户（含权限组 / 邮箱验证列，Schema 一次性建全）
        $db->exec(
            "CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY,
                username TEXT UNIQUE NOT NULL,
                password TEXT NOT NULL,
                email TEXT UNIQUE NOT NULL,
                avatar TEXT,
                signature TEXT,
                role TEXT DEFAULT 'user',
                status TEXT DEFAULT 'active',
                group_id INTEGER DEFAULT 1,
                post_count INTEGER DEFAULT 0,
                points INTEGER DEFAULT 0,
                level INTEGER DEFAULT 1,
                theme TEXT DEFAULT '',
                hide_right_sidebar INTEGER DEFAULT NULL,
                night_theme_auto INTEGER DEFAULT NULL,
                remember_token TEXT,
                last_active_at TEXT,
                last_login TEXT,
                email_verified INTEGER DEFAULT 0,
                email_verify_token TEXT,
                email_verify_token_sent_at TEXT,
                created_at TEXT
            )"
        );
        $db->exec('CREATE INDEX IF NOT EXISTS idx_users_username ON users (username)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_users_email ON users (email)');
        // 后台首页 latestUsers / 后台用户列表按 created_at 排序 → 加索引免排序
        $db->exec('CREATE INDEX IF NOT EXISTS idx_users_created ON users (created_at)');

        // 版块分类
        $db->exec(
            "CREATE TABLE IF NOT EXISTS categories (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                description TEXT,
                sort_order INTEGER DEFAULT 0,
                icon TEXT DEFAULT 'forum',
                show_icon INTEGER DEFAULT 1,
                created_at TEXT
            )"
        );

        // 帖子（过渡期仍在 business 库；分片后迁桶，本表退役）
        $db->exec(
            "CREATE TABLE IF NOT EXISTS threads (
                id INTEGER PRIMARY KEY,
                category_id INTEGER NOT NULL DEFAULT 0,
                user_id INTEGER NOT NULL DEFAULT 0,
                title TEXT NOT NULL,
                content TEXT,
                view_count INTEGER DEFAULT 0,
                reply_count INTEGER DEFAULT 0,
                is_pinned INTEGER DEFAULT 0,
                is_highlighted INTEGER DEFAULT 0,
                color TEXT DEFAULT '',
                reply_to_view INTEGER DEFAULT 0,
                last_reply_at TEXT,
                deleted_at TEXT,
                created_at TEXT
            )"
        );
        $db->exec('CREATE INDEX IF NOT EXISTS idx_threads_category ON threads (category_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_threads_created ON threads (created_at)');

        // 回复（同上，分片后退役）
        $db->exec(
            "CREATE TABLE IF NOT EXISTS posts (
                id INTEGER PRIMARY KEY,
                thread_id INTEGER NOT NULL DEFAULT 0,
                user_id INTEGER NOT NULL DEFAULT 0,
                content TEXT,
                created_at TEXT,
                deleted_at TEXT
            )"
        );
        $db->exec('CREATE INDEX IF NOT EXISTS idx_posts_thread ON posts (thread_id)');

        // 私信
        $db->exec(
            "CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY,
                sender_id INTEGER NOT NULL,
                receiver_id INTEGER NOT NULL,
                subject TEXT NOT NULL,
                content TEXT NOT NULL,
                is_read INTEGER DEFAULT 0,
                created_at TEXT
            )"
        );
        $db->exec('CREATE INDEX IF NOT EXISTS idx_messages_receiver ON messages (receiver_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_messages_sender ON messages (sender_id)');

        // 附件
        $db->exec(
            "CREATE TABLE IF NOT EXISTS attachments (
                id INTEGER PRIMARY KEY,
                thread_id INTEGER,
                post_id INTEGER,
                user_id INTEGER,
                filename TEXT NOT NULL,
                original_name TEXT NOT NULL,
                file_size INTEGER DEFAULT 0,
                mime_type TEXT,
                created_at TEXT
            )"
        );
        $db->exec('CREATE INDEX IF NOT EXISTS idx_attachments_thread ON attachments (thread_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_attachments_post ON attachments (post_id)');

        // 博客分类 / 博客 / 博客评论
        $db->exec(
            "CREATE TABLE IF NOT EXISTS blog_categories (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                description TEXT,
                sort_order INTEGER DEFAULT 0,
                created_at TEXT
            )"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS blogs (
                id INTEGER PRIMARY KEY,
                category_id INTEGER,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                content TEXT,
                cover_image TEXT DEFAULT '',
                view_count INTEGER DEFAULT 0,
                comment_count INTEGER DEFAULT 0,
                updated_at TEXT,
                created_at TEXT
            )"
        );
        $db->exec('CREATE INDEX IF NOT EXISTS idx_blogs_user ON blogs (user_id)');
        // 个人中心/博客管理按用户查博客列表（user_id + 时间倒序）→ 复合索引免排序
        $db->exec('CREATE INDEX IF NOT EXISTS idx_blogs_user_created ON blogs (user_id, created_at DESC)');
        $db->exec(
            "CREATE TABLE IF NOT EXISTS blog_comments (
                id INTEGER PRIMARY KEY,
                blog_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                content TEXT NOT NULL,
                created_at TEXT
            )"
        );
        $db->exec('CREATE INDEX IF NOT EXISTS idx_blog_comments_blog ON blog_comments (blog_id)');

        // KV 设置
        $db->exec(
            "CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT
            )"
        );

        // 在线用户
        $db->exec(
            "CREATE TABLE IF NOT EXISTS online_users (
                user_id INTEGER PRIMARY KEY,
                last_activity TEXT
            )"
        );
        // getOnlineCount/getOnlineData 均按 last_activity >= 阈值过滤 → 加索引免全表扫描
        $db->exec('CREATE INDEX IF NOT EXISTS idx_online_users_last ON online_users (last_activity)');

        // 密码重置
        $db->exec(
            "CREATE TABLE IF NOT EXISTS password_resets (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                token TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT,
                used INTEGER DEFAULT 0
            )"
        );
        $db->exec('CREATE INDEX IF NOT EXISTS idx_password_resets_token ON password_resets (token)');

        // 积分日志
        $db->exec(
            "CREATE TABLE IF NOT EXISTS points_log (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                points INTEGER DEFAULT 0,
                reason TEXT DEFAULT '',
                related_id INTEGER DEFAULT 0,
                related_type TEXT DEFAULT '',
                created_at TEXT
            )"
        );
        $db->exec('CREATE INDEX IF NOT EXISTS idx_points_log_user ON points_log (user_id)');
        // 积分日志按 reason 聚合/查询（后台积分明细按原因筛选）→ 独立索引
        $db->exec('CREATE INDEX IF NOT EXISTS idx_points_log_reason ON points_log (reason)');

        // 回复可见标记
        $db->exec(
            "CREATE TABLE IF NOT EXISTS viewed_replies (
                id INTEGER PRIMARY KEY,
                thread_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                created_at TEXT
            )"
        );
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uk_viewed_replies ON viewed_replies (thread_id, user_id)');

        // 等级配置
        $db->exec(
            "CREATE TABLE IF NOT EXISTS level_config (
                level INTEGER PRIMARY KEY,
                title TEXT NOT NULL,
                required_points INTEGER DEFAULT 0,
                icon TEXT DEFAULT '',
                color TEXT DEFAULT '#999'
            )"
        );

        // 用户组
        $db->exec(
            "CREATE TABLE IF NOT EXISTS user_groups (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                color TEXT DEFAULT '',
                is_default INTEGER DEFAULT 0
            )"
        );

        // 版块权限矩阵
        $db->exec(
            "CREATE TABLE IF NOT EXISTS category_permissions (
                id INTEGER PRIMARY KEY,
                category_id INTEGER NOT NULL,
                group_id INTEGER NOT NULL,
                can_view INTEGER DEFAULT 1,
                can_post INTEGER DEFAULT 0,
                can_reply INTEGER DEFAULT 0,
                can_attach INTEGER DEFAULT 1
            )"
        );
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uk_category_permissions ON category_permissions (category_id, group_id)');

        // 标签 / 帖子标签关联
        $db->exec(
            "CREATE TABLE IF NOT EXISTS tags (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                created_at TEXT
            )"
        );
        $db->exec('CREATE INDEX IF NOT EXISTS idx_tags_name ON tags (name)');
        $db->exec(
            "CREATE TABLE IF NOT EXISTS thread_tags (
                id INTEGER PRIMARY KEY,
                thread_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL
            )"
        );
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uk_thread_tags ON thread_tags (thread_id, tag_id)');

        // 投票（帖子/回复）
        $db->exec(
            "CREATE TABLE IF NOT EXISTS thread_votes (
                id INTEGER PRIMARY KEY,
                thread_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                vote INTEGER DEFAULT 0,
                created_at TEXT
            )"
        );
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uk_thread_votes ON thread_votes (thread_id, user_id)');
        $db->exec(
            "CREATE TABLE IF NOT EXISTS post_votes (
                id INTEGER PRIMARY KEY,
                post_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                vote INTEGER DEFAULT 0,
                created_at TEXT
            )"
        );
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uk_post_votes ON post_votes (post_id, user_id)');

        // 通知中心（内置化：原插件独立库迁入核心 business 库）
        $db->exec(
            "CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                title TEXT NOT NULL,
                link TEXT,
                actor_name TEXT NOT NULL DEFAULT '',
                summary TEXT,
                related_id INTEGER NOT NULL DEFAULT 0,
                related_type TEXT NOT NULL DEFAULT '',
                is_read INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )"
        );
        $db->exec('CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications (user_id, is_read, created_at)');

        // 审计日志
        $db->exec(
            "CREATE TABLE IF NOT EXISTS audit_logs (
                id INTEGER PRIMARY KEY,
                user_id INTEGER DEFAULT 0,
                username TEXT DEFAULT '',
                action TEXT NOT NULL,
                target_type TEXT DEFAULT '',
                target_id INTEGER DEFAULT 0,
                detail TEXT DEFAULT '',
                ip TEXT DEFAULT '',
                user_agent TEXT DEFAULT '',
                created_at TEXT DEFAULT (datetime('now','localtime'))
            )"
        );
        $db->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_action ON audit_logs (action)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_created ON audit_logs (created_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_target ON audit_logs (target_type, target_id)');

        // 自研倒排索引已剥离 business.sqlite → 季度分文件
        // （app/SplitDB/SearchIndexStore.php：data/meta/search/search_{YYYYQn}.sqlite）

        // 幂等补列：老库升级时自动 ALTER 补齐缺失列（CREATE TABLE IF NOT EXISTS 不作用于已存在表）
        self::ensureColumnPatches($db);
    }

    /**
     * 幂等补列：对已存在表 ALTER 补齐缺失列（老库升级路径；bootstrap 与 Database 初始化都会调用）
     * 说明：CREATE TABLE IF NOT EXISTS 只建新表，已存在表缺列只能 ALTER——本方法按需补齐，可重复执行。
     * @param \PDO $db business.sqlite 连接
     */
    public static function ensureColumnPatches(\PDO $db): void
    {
        $columnPatches = [
            'categories' => [
                'icon'      => "TEXT DEFAULT 'forum'",
                'show_icon' => 'INTEGER DEFAULT 1',
            ],
            'blogs' => [
                'cover_image'   => "TEXT DEFAULT ''",
                'view_count'    => 'INTEGER DEFAULT 0',
                'comment_count' => 'INTEGER DEFAULT 0',
                'updated_at'    => 'TEXT',
            ],
            // 用户级界面偏好：NULL=跟随站点默认（后台隐藏右栏/夜间主题开关）
            'users' => [
                'hide_right_sidebar' => 'INTEGER DEFAULT NULL',
                'night_theme_auto'   => 'INTEGER DEFAULT NULL',
                // 列表内容模式（用户级）：NULL=默认开；list_excerpt_len 摘要长度 50~80，NULL=默认 80
                'list_excerpt'       => 'INTEGER DEFAULT NULL',
                'list_excerpt_len'   => 'INTEGER DEFAULT NULL',
                // 首页模式（用户级）：NULL/follow=跟随站点默认，portal=官网介绍，community=社区首页
                'home_mode'          => 'TEXT DEFAULT NULL',
            ],
        ];

        // 每请求固定税修复：补列探测 + audit_logs 回填只需在「首次/升级」执行一次——
        // 以补列清单哈希为键落盘标记（data/runtime/），命中即跳过，消除每请求
        // 4×PRAGMA table_info + audit_logs UPDATE 抢写锁的固定税；补列清单变更 → 哈希变 → 自动重跑。
        $runtimeDir = rtrim(ShardRouter::dataPath(), '/\\') . '/runtime';
        $marker = $runtimeDir . '/schema_patches_' . md5((string)json_encode($columnPatches)) . '.ok';
        if (is_file($marker)) {
            return;
        }

        foreach ($columnPatches as $table => $cols) {
            $existing = [];
            $stmt = $db->query("PRAGMA table_info({$table})");
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                $existing[$c['name']] = true;
            }
            foreach ($cols as $col => $def) {
                if (!isset($existing[$col])) {
                    // 升级瞬间多请求并发进入（文件标记未落盘前）会同时探测到缺列并发 ALTER，
                    // 后者抛 "duplicate column name"——捕获并跳过（该列已被并发请求补上），防止 500。
                    try {
                        $db->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
                    } catch (\PDOException $e) {
                        if (stripos($e->getMessage(), 'duplicate column') === false) {
                            throw $e;
                        }
                        \error_log('Schema: 并发 ALTER duplicate column 已忽略（' . $table . '.' . $col . '）: ' . $e->getMessage());
                    }
                }
            }
        }

        // 审计日志时间修复：历史 audit_logs 行 created_at 为 NULL（建表无默认值 + 写入漏列），
        // 真实写入时刻已无法恢复，幂等回填为升级时刻本地时间（此后由 AuditLog::log 显式写入）。
        // 放本方法内且仅在标记缺失（首次运行/升级）时执行：老库初始化只走 ensureColumnPatches，
        // 不重跑 bootstrap；标记落盘后每请求由上方文件标记短路，不再抢 audit_logs 写锁。
        try {
            $db->exec("UPDATE audit_logs SET created_at = " . $db->quote(date('Y-m-d H:i:s')) . " WHERE created_at IS NULL OR created_at = ''");
        } catch (\Throwable $e) {
            \error_log('Schema backfill audit_logs created_at failed: ' . $e->getMessage());
        }

        // 写标记（失败必发声：异常环境下将退化为每请求探测，功能不受影响但恢复旧固定税）
        if (!is_dir($runtimeDir) && !@mkdir($runtimeDir, 0755, true)) {
            \error_log('Schema: 创建补列标记目录失败（将每请求重复探测）: ' . $runtimeDir);
            return;
        }
        if (@file_put_contents($marker, date('c'), LOCK_EX) === false) {
            \error_log('Schema: 写补列标记失败（将每请求重复探测）: ' . $marker);
        }
    }

    /**
     * main_index.sqlite：帖子主索引（分片后启用）
     * 扩展：reply_index — 回复定位索引（回复存储于父帖所在桶，索引记录其桶路径，
     *        保证按回复 ID 读取 O(1) 且 getByThread 单桶读取）
     */
    private static function ensureMainIndex(string $root): void
    {
        $db = DBFactory::getConnection($root . '/' . self::MAIN_INDEX);
        $db->exec(
            "CREATE TABLE IF NOT EXISTS topic_index (
                id INTEGER PRIMARY KEY,
                uid INTEGER,
                title TEXT,
                create_time INTEGER,
                last_reply_time INTEGER,
                view_count INTEGER DEFAULT 0,
                reply_count INTEGER DEFAULT 0,
                status INTEGER DEFAULT 0,
                category_id INTEGER DEFAULT 0,
                is_pinned INTEGER DEFAULT 0,
                is_highlighted INTEGER DEFAULT 0,
                color TEXT DEFAULT '',
                reply_to_view INTEGER DEFAULT 0,
                deleted_at INTEGER,
                bucket_path TEXT,
                is_archived INTEGER DEFAULT 0,
                excerpt TEXT DEFAULT '',
                excerpt_images TEXT DEFAULT ''
            )"
        );
        // 存量库幂等迁移：topic_index 缺列时 ALTER TABLE 补齐
        // （默认值不动存量行；重复执行由列探测保证幂等）
        $cols = $db->query('PRAGMA table_info(topic_index)')->fetchAll(\PDO::FETCH_COLUMN, 1);
        foreach (['is_archived' => 'INTEGER DEFAULT 0', 'excerpt' => "TEXT DEFAULT ''", 'excerpt_images' => "TEXT DEFAULT ''"] as $col => $def) {
            if (!in_array($col, $cols, true)) {
                $db->exec("ALTER TABLE topic_index ADD COLUMN {$col} {$def}");
            }
        }
        // 归档标记索引：供 cli/archive_mark.php 每日扫描
        // (is_archived=0 AND deleted_at IS NULL AND create_time < cut) 使用；
        // 首列 is_archived，列表查询（不引用该列）不会被规划器选中
        $db->exec('CREATE INDEX IF NOT EXISTS idx_topic_index_archive ON topic_index (is_archived, create_time)');
        // 列表查询主索引：论坛列表/版块列表按 (置顶 DESC, 最后回复 DESC, 创建时间 DESC) 天然有序，LIMIT 免排序
        $db->exec('CREATE INDEX IF NOT EXISTS idx_topic_index_list ON topic_index (is_pinned DESC, last_reply_time DESC, create_time DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_topic_index_create_time ON topic_index (create_time DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_topic_index_category ON topic_index (category_id)');
        // 个人中心/个人主页按 uid 查帖子（getThreads/getStats）→ 新增 uid 复合索引：
        // 查询形态 WHERE uid=? AND status=0 AND deleted_at IS NULL ORDER BY create_time DESC,id DESC LIMIT n，
        // 索引前缀 (uid,status,deleted_at) 过滤 + 尾部 create_time DESC,id DESC 免排序。
        // 安全：索引首列是 uid，列表查询（只按 status/deleted_at/category_id 过滤）不会被规划器选中
        $db->exec('CREATE INDEX IF NOT EXISTS idx_topic_index_uid ON topic_index (uid, status, deleted_at, create_time DESC, id DESC)');
        // 曾为 getCategoryStats() 添加覆盖索引 idx_topic_index_stats(status, deleted_at, category_id, reply_count)，
        // 但 SQLite 规划器会为列表查询误选该索引 → 大表 TEMP B-TREE 排序回归。
        // 结论：统计查询已用永久缓存（Category::getCategoryStats，发帖/回帖主动失效），
        // 不再需要此覆盖索引；列表查询继续走 idx_topic_index_list（天然有序，LIMIT 免排序）。

        $db->exec(
            "CREATE TABLE IF NOT EXISTS reply_index (
                id INTEGER PRIMARY KEY,
                pid INTEGER,
                uid INTEGER,
                create_time INTEGER,
                update_time INTEGER,
                status INTEGER DEFAULT 0,
                bucket_path TEXT
            )"
        );
        $db->exec('CREATE INDEX IF NOT EXISTS idx_reply_index_pid ON reply_index (pid)');
        // getStats 按 uid 统计回复数（reply_index COUNT WHERE uid=? AND status=0）→ 新增 (uid, status) 索引。
        // 顺序红线：必须位于 reply_index 建表之后（曾插在 CREATE TABLE 之前导致全新安装 fatal）
        $db->exec('CREATE INDEX IF NOT EXISTS idx_reply_index_uid ON reply_index (uid, status)');
    }

    /**
     * sessions.sqlite：独立会话库（与业务库隔离）
     */
    private static function ensureSessions(string $root): void
    {
        $db = DBFactory::getConnection($root . '/' . self::SESSIONS);
        $db->exec(
            "CREATE TABLE IF NOT EXISTS sessions (
                id TEXT PRIMARY KEY,
                data TEXT,
                expires INTEGER NOT NULL
            )"
        );
        $db->exec('CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions (expires)');
    }

    // ==================== 桶分片库 ====================

    /**
     * 获取桶分片连接（不存在则建库 + 建 topic/reply 表，幂等）
     *
     * @param string $quarter 季度标识（如 2026Q3）
     * @param int    $bucket  桶编号（0 ~ BUCKET_SIZE-1）
     */
    public static function bucketDb(string $quarter, int $bucket, ?string $dataPath = null): PDO
    {
        $abs = ShardRouter::ensureBucketDir($quarter, $bucket, $dataPath);
        $db = DBFactory::getConnection($abs);
        self::ensureBucketTables($db);
        return $db;
    }

    /**
     * 桶内表结构：
     *   topic — 主帖真相源（含路由所需的分类/置顶/颜色/回复可见/计数等）
     *   reply — 回复真相源（正文外置 extern）
     * 时间统一为秒级时间戳 INTEGER，读取时模型层转字符串。
     */
    private static function ensureBucketTables(PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS topic (
                id INTEGER PRIMARY KEY,
                uid INTEGER,
                category_id INTEGER DEFAULT 0,
                title TEXT,
                create_time INTEGER,
                update_time INTEGER,
                last_reply_time INTEGER,
                view_count INTEGER DEFAULT 0,
                reply_count INTEGER DEFAULT 0,
                status INTEGER DEFAULT 0,
                is_pinned INTEGER DEFAULT 0,
                is_highlighted INTEGER DEFAULT 0,
                color TEXT DEFAULT '',
                reply_to_view INTEGER DEFAULT 0,
                deleted_at INTEGER,
                extern_path TEXT,
                excerpt TEXT DEFAULT '',
                excerpt_images TEXT DEFAULT ''
            )"
        );
        // 存量桶幂等补列：老桶表已存在时 CREATE TABLE IF NOT EXISTS 不生效 → ALTER 补齐摘要列
        // （默认空不动存量行；重复执行由列探测保证幂等）
        $bcols = $db->query('PRAGMA table_info(topic)')->fetchAll(\PDO::FETCH_COLUMN, 1);
        foreach (['excerpt' => "TEXT DEFAULT ''", 'excerpt_images' => "TEXT DEFAULT ''"] as $bcol => $bdef) {
            if (!in_array($bcol, $bcols, true)) {
                $db->exec("ALTER TABLE topic ADD COLUMN {$bcol} {$bdef}");
            }
        }
        $db->exec('CREATE INDEX IF NOT EXISTS idx_topic_category ON topic (category_id)');
        $db->exec(
            "CREATE TABLE IF NOT EXISTS reply (
                id INTEGER PRIMARY KEY,
                pid INTEGER,
                uid INTEGER,
                create_time INTEGER,
                update_time INTEGER,
                status INTEGER DEFAULT 0,
                deleted_at INTEGER,
                extern_path TEXT
            )"
        );
        $db->exec('CREATE INDEX IF NOT EXISTS idx_reply_pid ON reply (pid)');
    }

    /**
     * 根据 main_index.bucket_path 打开桶库（读取路由的唯一依据）
     * bucket_path 形如：bucket/active/2026Q3/5.sqlite
     */
    public static function bucketFromPath(string $bucketPath, ?string $dataPath = null): PDO
    {
        // ^...$ 锚定（防御深度），防止畸形前缀/后缀混入路径仍被当作桶路径打开
        if (!preg_match('#^bucket/(?:active|archive)/(\d{4}Q[1-4])/(\d+)\.sqlite$#', $bucketPath, $m)) {
            throw new \RuntimeException("SplitDB: 非法 bucket_path: {$bucketPath}");
        }
        $isArchive = strpos($bucketPath, 'bucket/archive/') === 0;
        $root = rtrim($dataPath ?: ShardRouter::dataPath(), '/\\');
        $abs = $root . '/' . $bucketPath;
        if (!is_file($abs)) {
            if ($isArchive) {
                // 归档桶缺失：归档桶只能由 cli/archive.php 移动真实桶文件生成，
                // 不存在"首次写入新建"的合法场景。缺失 = 归档文件丢失/放错位置，
                // 静默重建空表会掩盖数据丢失、前台显示空帖 → 抛异常并落日志告警，不静默返回空连接。
                // （调用方按需捕获：Thread 批量摘要/正文回填等已有 try-catch 降级，单帖读取则暴露故障）
                \error_log("[SplitDB] 归档桶缺失（疑似归档文件丢失/放错位置）: {$abs}");
                throw new \RuntimeException("SplitDB: 归档桶缺失（疑似归档文件丢失）: {$abs}");
            }
            // 活跃桶首次写入可建（幂等）
            $dir = dirname($abs);
            // mkdir 失败必须发声（目录不可写等），否则后续 DBFactory 打开会抛隐晦错误
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                \error_log("SplitDB: 创建活跃桶目录失败: {$dir}");
            }
        }
        $db = DBFactory::getConnection($abs);
        self::ensureBucketTables($db);
        return $db;
    }

    /**
     * global_id.sqlite：全局 ID 生成器
     */
    private static function ensureGlobalId(string $root): void
    {
        IDGenerator::initTable(DBFactory::getConnection($root . '/' . self::GLOBAL_ID));
    }
}
