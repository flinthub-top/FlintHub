<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 系统设置 — KV 缓存、运行时计数器、分页渲染、附件处理
 * @file app/Helpers/Settings.php
 * @package app\Helpers
 */

namespace app\Helpers;

class Settings
{
    private static $cache = [];
    private static $cacheFileLoaded = false;
    private static $allLoaded = false;     // 是否已从 DB 加载全部 KV
    private static $runtimeCache = [];     // 运行时计数器内存缓存
    private static $pendingCounters = [];  // 待批量写入的计数器（runtimeIncr 延迟到请求结束时写 DB）
    private static bool $runtimeKeysLoaded = false; // 标准计数器键是否已批量预载（同请求只查一次 settings 表）
    private const CACHE_FILE = __DIR__ . '/../../protected/settings_cache.php';

    // ========================================================================
    //  KV 缓存（三层：内存 → 缓存文件 → 数据库）
    // ========================================================================

    /**
     * 获取设置值
     */
    public static function get(string $key, string $default = ''): string
    {
        // 手动清空标记
        if (!empty($GLOBALS['_settingCacheClear'])) {
            self::$cache = [];
            self::$cacheFileLoaded = false;
            self::$allLoaded = false;
            $GLOBALS['_settingCacheClear'] = false;
        }

        // 运行时缓存命中
        if (\array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        // 尝试加载缓存文件
        if (!self::$cacheFileLoaded) {
            self::loadCacheFile();
        }

        // 缓存文件命中
        if (\array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        // 第一次从 DB 拉取时一次性加载全部，避免逐 key 查库
        if (!self::$allLoaded) {
            self::loadAllFromDb();
            if (\array_key_exists($key, self::$cache)) {
                return self::$cache[$key];
            }
        }

        // 已全量加载且键仍缺失 → 表中确实无此键，直接返回默认值，不再逐键单查
        if (self::$allLoaded) {
            self::$cache[$key] = $default;
            return $default;
        }

        // 兜底：单查（仅在从未全量加载时保留，如 DB 初始化异常路径）
        $db = \app\Core\Database::getInstance();
        $result = $db->fetchOne('SELECT value FROM settings WHERE "key" = :key', [':key' => $key]);
        $value = $result ? $result['value'] : $default;
        self::$cache[$key] = $value;
        return $value;
    }

    /**
     * 一次性从数据库加载全部设置到缓存
     */
    private static function loadAllFromDb(): void
    {
        self::$allLoaded = true;
        try {
            $db = \app\Core\Database::getInstance();
            $rows = $db->fetchAll('SELECT "key", value FROM settings');
            foreach ($rows as $row) {
                self::$cache[$row['key']] = $row['value'];
            }
        } catch (\Exception $e) {
            // DB 不可用时静默失败
        }
    }

    /**
     * 从缓存文件加载全部设置
     *
     * 载入后与数据库做一次对账，避免「DB 被替换/重置（迁移、还原、删库重建）而文件缓存留存旧键」
     * 导致旧值永久生效 —— 典型症状：插件配置代码已改，旧配置值仍然出现在页面上。
     *
     * 对账策略（仅补孤儿键，不做全量重建）：
     *  - 缓存有、DB 无的键：说明该键已在 DB 中被移除 → 从缓存中删除
     *  仅当确实发现孤儿键时才回写缓存文件，正常路径零额外开销（一次 keys 比对）。
     */
    private static function loadCacheFile(): void
    {
        self::$cacheFileLoaded = true;
        $file = self::CACHE_FILE;
        if (\file_exists($file) && \is_readable($file)) {
            // 用 json_decode 替代 @require，消除 RCE 风险
            $content = @\file_get_contents($file);
            if ($content !== false) {
                // 移除 <?php 前缀和注释行
                $json = \preg_replace('/^<\?php\s*(?:\/\/[^\n]*\n)?/s', '', $content);
                $data = @\json_decode($json, true);
                if (\is_array($data)) {
                    // 与 DB 对账：剔除 DB 中已不存在的孤儿键
                    $orphans = self::reconcileWithDb($data);
                    if (!empty($orphans)) {
                        foreach ($orphans as $k) {
                            unset($data[$k]);
                        }
                        // 回写干净缓存；失败不影响本次读取（内存中已剔除）
                        self::buildCache($data);
                    }
                    self::$cache = $data;
                    return;
                }
            }
        }
        // 缓存文件不存在或格式错误时，从数据库加载并重建缓存
        self::loadAllFromDb();
        self::buildCache(self::$cache);
    }

    /**
     * 找出文件缓存中「DB 已不存在」的孤儿键。
     *
     * 注：只取 DB 键名集合做比对，不比较值 —— 值的权威方是文件缓存/DB 中较新的一方，
     *     且写路径（update/incr）都会重建缓存，因此值的一致性由写路径保证。
     *
     * @param array $data 文件缓存数据
     * @return string[] 需要从缓存剔除的键；DB 不可用时返回空数组（保守放行）
     */
    private static function reconcileWithDb(array $data): array
    {
        if (empty($data)) return [];
        try {
            $rows = \app\Core\Database::getInstance()->fetchAll('SELECT "key" FROM settings');
        } catch (\Throwable $e) {
            // DB 暂不可用：保守跳过对账，避免把有效配置误删
            return [];
        }
        if (empty($rows)) return []; // DB 空（如全新安装）交由正常流程处理

        $dbKeys = [];
        foreach ($rows as $r) $dbKeys[$r['key']] = true;

        $orphans = [];
        foreach ($data as $k => $_) {
            if (!isset($dbKeys[$k])) $orphans[] = $k;
        }
        return $orphans;
    }

    /**
     * 构建缓存文件
     * @param array|null $data 可选：直接写入指定数据，免二次查库
     */
    public static function buildCache(?array $data = null): void
    {
        if ($data === null) {
            $db = \app\Core\Database::getInstance();
            $rows = $db->fetchAll('SELECT "key", value FROM settings');
            $data = [];
            foreach ($rows as $row) {
                $data[$row['key']] = $row['value'];
            }
        }

        $export = \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $content = "<?php\n{$export}\n";

        $file = self::CACHE_FILE;
        $dir = \dirname($file);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        $tmp = $file . '.tmp';
        if (@\file_put_contents($tmp, $content, LOCK_EX) !== false) {
            if (\function_exists('opcache_invalidate')) {
                @\opcache_invalidate($tmp, true);
            }
            @\rename($tmp, $file);
            if (\function_exists('opcache_invalidate')) {
                @\opcache_invalidate($file, true);
            }
        }

        self::$cache = $data;
    }

    /**
     * 清除运行时缓存
     */
    public static function clearCache(): void
    {
        $GLOBALS['_settingCacheClear'] = true;
    }

    /**
     * 更新设置并重建缓存
     *
     * @param string $key        设置键
     * @param string $value      设置值
     * @param string $pluginName 插件名（目录名）；插件写入全局设置时必须传，
     *                           用于校验 system:settings 权限；空=核心调用（放行）
     */
    public static function update(string $key, string $value, string $pluginName = ''): void
    {
        // 插件写全局设置需声明 system:settings 权限（未声明时按全局开关警告/拒绝，均由 requirePermission 记录）
        if ($pluginName !== '' && !\app\Helpers\Plugin::requirePermission($pluginName, 'system:settings')) {
            return;
        }
        $db = \app\Core\Database::getInstance();
        $db->query('INSERT INTO settings ("key", value) VALUES (:key, :value) ON CONFLICT("key") DO UPDATE SET value = :value2',
            [':key' => $key, ':value' => $value, ':value2' => $value]);
        self::buildCache();
    }

    // ========================================================================
    //  运行时数据缓存（替代 COUNT(*)）
    // ========================================================================
    //  原理：首次请求时从 COUNT(*) 构建缓存，之后发帖/删帖时原子增减，
    //       不再重复全表 COUNT(*)。
    // ========================================================================

    /**
     * 标准站点计数器键（getTotalUsers/Threads/Posts/Blogs 对应 settings 表 _runtime_* 键）
     */
    private const RUNTIME_COUNTER_KEYS = ['total_users', 'total_threads', 'total_posts', 'total_blogs'];

    /**
     * 批量预载全部标准计数器：一次 IN 查询取出 4 个 _runtime_* 键，
     * 替代原先每个计数器一次独立单键 SELECT（首页/论坛/版块页各 3~4 次 → 1 次）。
     * 与旧逻辑一致：数据库值（flushCounters 写入的最新值）优先于文件缓存；
     * 键缺失时保留内存缓存已有值，由 runtimeBuildSingle 兜底 COUNT 重建。
     */
    private static function preloadRuntimeCounters(): void
    {
        if (self::$runtimeKeysLoaded) {
            return;
        }
        self::$runtimeKeysLoaded = true;

        $marks = [];
        $params = [];
        foreach (self::RUNTIME_COUNTER_KEYS as $k) {
            $marks[] = ':rt_' . $k;
            $params[':rt_' . $k] = '_runtime_' . $k;
        }
        try {
            $db = \app\Core\Database::getInstance();
            $rows = $db->fetchAll(
                'SELECT "key", value FROM settings WHERE "key" IN (' . implode(',', $marks) . ')',
                $params
            );
            foreach ($rows as $row) {
                $key = substr((string)$row['key'], strlen('_runtime_'));
                if ($key === '') continue;
                $val = (int)$row['value'];
                self::$runtimeCache[$key] = $val;
                self::$cache[$row['key']] = (string)$val; // 同步内存缓存
            }
        } catch (\Throwable $e) {
            // DB 不可用时静默忽略，走原兜底路径
        }
    }

    public static function runtimeGet(string $key): int
    {
        // 内存缓存命中
        if (\array_key_exists($key, self::$runtimeCache)) {
            return self::$runtimeCache[$key];
        }

        // 首次请求任一标准计数器时批量预载（一次 IN 查询，避免逐键单查）
        self::preloadRuntimeCounters();

        // 批量预载已覆盖 → 直接返回
        if (\array_key_exists($key, self::$runtimeCache)) {
            return self::$runtimeCache[$key];
        }

        // 从文件缓存读取（settings_cache.php，含 buildCache 时写入的值）
        $dbKey = '_runtime_' . $key;
        $val = self::get($dbKey, '');
        if ($val !== '') {
            self::$runtimeCache[$key] = (int)$val;
        }

        // 数据库有 UNIQUE 索引，flushCounters 写入的是最新值，用它覆盖缓存
        // （非标准键仍走单键查询；标准键已在 preloadRuntimeCounters 中覆盖）
        try {
            $db = \app\Core\Database::getInstance();
            $result = $db->fetchOne('SELECT value FROM settings WHERE "key" = :key', [':key' => $dbKey]);
            if ($result && $result['value'] !== '') {
                $dbVal = (int)$result['value'];
                self::$runtimeCache[$key] = $dbVal;
                self::$cache[$dbKey] = (string)$dbVal; // 同步内存缓存
                return $dbVal;
            }
        } catch (\Throwable $e) {
            // DB 不可用时忽略
        }

        if (\array_key_exists($key, self::$runtimeCache)) {
            return self::$runtimeCache[$key];
        }

        // 未找到，从数据库构建（COUNT(*) 全表统计）
        $value = self::runtimeBuildSingle($key);
        self::$runtimeCache[$key] = $value;
        self::$cache[$dbKey] = (string)$value;
        return $value;
    }

    /**
     * 增加运行时计数器
     */
    public static function runtimeIncr(string $key, int $amount = 1): void
    {
        if (!isset(self::$pendingCounters[$key])) {
            self::$pendingCounters[$key] = 0;
        }
        self::$pendingCounters[$key] += $amount;

        // 先确保内存中有值
        if (!\array_key_exists($key, self::$runtimeCache)) {
            self::runtimeGet($key);
            // 如果读出来为 0 且增长方向为正，自动校正（应对计数器漂移）
            if ($amount > 0 && (self::$runtimeCache[$key] ?? 0) === 0) {
                $auto = self::runtimeBuildSingle($key);
                if ($auto > 0) {
                    self::$runtimeCache[$key] = $auto;
                    $dbKey = '_runtime_' . $key;
                    $db = \app\Core\Database::getInstance();
                    $db->query('INSERT INTO settings ("key", value) VALUES (:k, :v) ON CONFLICT("key") DO UPDATE SET value = :v2',
                        [':k' => $dbKey, ':v' => (string)$auto, ':v2' => (string)$auto]);
                    self::$cache[$dbKey] = (string)$auto;
                }
            }
        }
        self::$runtimeCache[$key] += $amount;
        if (self::$runtimeCache[$key] < 0) {
            self::$runtimeCache[$key] = 0;
        }

        // 不同步到 DB（延迟到 flushCounters 批量写入），但更新内存缓存
        $dbKey = '_runtime_' . $key;
        self::$cache[$dbKey] = (string)self::$runtimeCache[$key];
    }

    /**
     * 批量写入所有待处理的计数器（由 register_shutdown_function 在请求结束时调用）
     */
    public static function flushCounters(): void
    {
        if (empty(self::$pendingCounters)) return;
        try {
            $db = \app\Core\Database::getInstance();
            // 按键名排序，保证全局加锁顺序一致，防死锁
            \ksort(self::$pendingCounters);
            foreach (self::$pendingCounters as $key => $amount) {
                if ($amount === 0) continue;
                $dbKey = '_runtime_' . $key;
                $db->query(
                    'INSERT INTO settings ("key", value) VALUES (:k, :v) ON CONFLICT("key") DO UPDATE SET value = CAST(CAST(value AS INTEGER) + :vd AS TEXT)',
                    [':k' => $dbKey, ':v' => (string)$amount, ':vd' => $amount]
                );
            }
            self::$pendingCounters = [];
        } catch (\Throwable $e) {
            // 请求结束时 DB 可能已断开，静默忽略（计数器近似值，可接受）
            \error_log('flushCounters error: ' . $e->getMessage());
        }
    }

    /**
     * 减少运行时计数器
     */
    public static function runtimeDecr(string $key, int $amount = 1): void
    {
        self::runtimeIncr($key, -$amount);
    }

    /**
     * 从 COUNT(*) 重建单个运行时计数器
     */
    public static function runtimeBuildSingle(string $key): int
    {
        $db = \app\Core\Database::getInstance();
        $dbKey = '_runtime_' . $key;
        $value = 0;

        switch ($key) {
            case 'total_users':
                $r = $db->fetchOne('SELECT COUNT(*) as c FROM users');
                $value = (int)($r['c'] ?? 0);
                break;
            case 'total_threads':
                // 改读 main_index.topic_index（活跃帖计数）
                $value = (int)\app\SplitDB\Schema::mainIndexDb()->query(
                    'SELECT COUNT(*) FROM topic_index WHERE status = 0 AND deleted_at IS NULL'
                )->fetchColumn();
                break;
            case 'total_posts':
                // 改读 main_index.reply_index（活跃回复计数）
                $value = (int)\app\SplitDB\Schema::mainIndexDb()->query(
                    'SELECT COUNT(*) FROM reply_index WHERE status = 0'
                )->fetchColumn();
                break;
            case 'total_blogs':
                $r = $db->fetchOne('SELECT COUNT(*) as c FROM blogs');
                $value = (int)($r['c'] ?? 0);
                break;
            default:
                return 0;
        }

        // 存入 settings 表
        $db->query(
            'INSERT INTO settings ("key", value) VALUES (:k, :v) ON CONFLICT("key") DO UPDATE SET value = :v2',
            [':k' => $dbKey, ':v' => (string)$value, ':v2' => (string)$value]
        );

        self::$cache[$dbKey] = (string)$value;
        return $value;
    }

    /**
     * 重建全部运行时计数器（管理后台用）
     */
    public static function runtimeBuild(): void
    {
        $keys = ['total_users', 'total_threads', 'total_posts', 'total_blogs'];
        foreach ($keys as $key) {
            self::$runtimeCache[$key] = self::runtimeBuildSingle($key);
        }
    }

    // ========================================================================
    //  基于运行时缓存的统计方法（替代原有的 COUNT(*) 实现）
    // ========================================================================

    public static function getTotalUsers(): int
    {
        return self::runtimeGet('total_users');
    }

    public static function getTotalThreads(): int
    {
        return self::runtimeGet('total_threads');
    }

    public static function getTotalPosts(): int
    {
        return self::runtimeGet('total_posts');
    }

    public static function getTotalBlogs(): int
    {
        return self::runtimeGet('total_blogs');
    }

    /**
     * 博客评论总数（30s 短 TTL 文件缓存：首页/博客列表页共用，替代每请求 COUNT(blog_comments)）
     * 评论数是展示型统计，30s 延迟可接受；写入路径无需维护（对比 runtime 计数器零漂移风险）
     */
    public static function getBlogCommentsCount(): int
    {
        $cacheFile = (rtrim(\app\SplitDB\ShardRouter::dataPath(), '/\\')) . '/runtime/blog_comments_count.cache.json';
        if (is_file($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            $dec = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($dec) && isset($dec['ts'], $dec['v']) && time() - (int)$dec['ts'] < 30) {
                return (int)$dec['v'];
            }
        }
        $db = \app\Core\Database::getInstance();
        $cnt = (int)($db->fetchOne('SELECT COUNT(*) as cnt FROM blog_comments')['cnt'] ?? 0);
        $dir = dirname($cacheFile);
        if (is_dir($dir) || @mkdir($dir, 0755, true)) {
            @file_put_contents($cacheFile, json_encode(['ts' => time(), 'v' => $cnt], JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        return $cnt;
    }

    // ========================================================================
    //  在线状态
    // ========================================================================

    public static function updateOnlineStatus(): void
    {
        if (!Auth::isLoggedIn()) return;

        // 会话级节流：本会话 60s 内已同步过在线状态则直接跳过，
        // 活跃用户（两次请求间隔 <60s）每请求省 1 次 SELECT + 可能 UPSERT（全站登录请求 -1~2）
        $now = time();
        if (isset($_SESSION['_online_sync']) && (int)$_SESSION['_online_sync'] > $now - 60) {
            return;
        }
        $_SESSION['_online_sync'] = $now;

        $db = \app\Core\Database::getInstance();
        $last = $db->fetchOne('SELECT last_activity FROM online_users WHERE user_id = :user_id', [':user_id' => $_SESSION['user_id']]);
        if ($last && \strtotime($last['last_activity']) > $now - 60) return;

        $nowStr = date('Y-m-d H:i:s', $now);
        // SQLite UPSERT（ON CONFLICT 兼容 3.33）替代 ON DUPLICATE KEY UPDATE
        $db->query(
            "INSERT INTO online_users (user_id, last_activity) VALUES (:uid, :now)
             ON CONFLICT(user_id) DO UPDATE SET last_activity = :now2",
            [':uid' => $_SESSION['user_id'], ':now' => $nowStr, ':now2' => $nowStr]
        );

        $threshold = \date('Y-m-d H:i:s', \strtotime('-15 minutes'));
        // 写放大硬上限：单次清理最多 5000 行，避免单次请求卡顿；
        // 用 rowid IN (SELECT ... LIMIT) 而非 DELETE ... LIMIT，兼容 SQLite 编译项
        $db->query(
            'DELETE FROM online_users WHERE rowid IN (
                SELECT rowid FROM online_users WHERE last_activity < :threshold LIMIT 5000
            )',
            [':threshold' => $threshold]
        );
    }

    public static function getOnlineCount(): int
    {
        // 统一走带 30s 文件缓存的 getOnlineData（原实现每次请求 COUNT 全表，
        // 且与首页 getOnlineData 口径不一致）；论坛/版块/博客页与首页共享同一份缓存
        return (int)(self::getOnlineData()['count'] ?? 0);
    }

    /**
     * 获取在线数据（一次查询返回在线数和用户列表，替代分别调用 getOnlineCount + getOnlineUsers）
     */
    public static function getOnlineData(int $limit = 30): array
    {
        // 短 TTL 文件缓存（30s）：在线列表为准实时数据，避免每请求 JOIN + 排序
        $cacheFile = (rtrim(\app\SplitDB\ShardRouter::dataPath(), '/\\')) . '/runtime/online_data.cache.json';
        if (is_file($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            $dec = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($dec) && isset($dec['ts'], $dec['data']) && time() - (int)$dec['ts'] < 30) {
                return $dec['data'];
            }
        }

        $db = \app\Core\Database::getInstance();
        $threshold = date('Y-m-d H:i:s', strtotime('-15 minutes'));
        // 在线总数：独立 COUNT（不截断），避免 LIMIT 30 列表导致 >30 人在线时计数失真
        // （getOnlineCount 现已统一走本方法，计数必须真实）
        $countRow = $db->fetchOne(
            'SELECT COUNT(*) as count FROM online_users WHERE last_activity >= :threshold',
            [':threshold' => $threshold]
        );
        $rows = $db->fetchAll(
            'SELECT u.id, u.username, u.avatar FROM online_users o
             JOIN users u ON o.user_id = u.id
             WHERE o.last_activity >= :threshold
             ORDER BY u.username ASC LIMIT ' . (int)$limit,
            [':threshold' => $threshold]
        );
        $result = ['count' => (int)($countRow['count'] ?? 0), 'users' => $rows ?: []];
        $dir = dirname($cacheFile);
        if (is_dir($dir) || @mkdir($dir, 0755, true)) {
            @file_put_contents($cacheFile, json_encode(['ts' => time(), 'data' => $result], JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        return $result;
    }

    /** @var array<int, int> 请求级缓存：user_id => 未读私信数（布局 + 收件箱页共用一次查询） */
    private static array $unreadMsgCache = [];

    public static function getUnreadMessageCount(): int
    {
        if (!Auth::isLoggedIn()) return 0;
        $uid = (int)$_SESSION['user_id'];
        if (isset(self::$unreadMsgCache[$uid])) {
            return self::$unreadMsgCache[$uid];
        }
        $db = \app\Core\Database::getInstance();
        $r = $db->fetchOne('SELECT COUNT(*) as count FROM messages WHERE receiver_id = :uid AND is_read = 0',
            [':uid' => $uid]);
        return self::$unreadMsgCache[$uid] = (int)($r['count'] ?? 0);
    }

    /**
     * 清除未读私信数请求级缓存（已读状态变更后调用，防同请求读到旧值）
     */
    public static function clearUnreadMessageCache(): void
    {
        self::$unreadMsgCache = [];
    }

    // ========================================================================
    //  附件处理
    // ========================================================================

    public static function handleAttachments(int $threadId, ?int $postId = null): void
    {
        $field = $postId ? 'reply_attachments' : 'attachments';
        if (empty($_FILES[$field]['name'][0])) return;

        $db = \app\Core\Database::getInstance();
        foreach ($_FILES[$field]['name'] as $key => $name) {
            if (($_FILES[$field]['error'][$key] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $file = [
                    'name' => $_FILES[$field]['name'][$key] ?? '',
                    'type' => $_FILES[$field]['type'][$key] ?? '',
                    'tmp_name' => $_FILES[$field]['tmp_name'][$key] ?? '',
                    'error' => $_FILES[$field]['error'][$key] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $_FILES[$field]['size'][$key] ?? 0,
                ];
                $upload = Upload::file($file);
                if (!empty($upload['success'])) {
                    $db->query(
                        'INSERT INTO attachments (post_id, thread_id, filename, original_name, file_size, mime_type)
                         VALUES (:pid, :tid, :filename, :original_name, :file_size, :mime_type)',
                        [
                            ':pid' => $postId, ':tid' => (int)$threadId,
                            ':filename' => $upload['filename'], ':original_name' => $upload['original_name'],
                            ':file_size' => $upload['size'], ':mime_type' => $upload['type'],
                        ]
                    );
                }
            }
        }
    }

    /**
     * 清理上传目录中的孤儿附件（数据库无记录的废弃文件）
     * @return array [deletedCount, freedBytes]
     */
    public static function cleanupOrphanAttachments(): array
    {
        $db = \app\Core\Database::getInstance();
        $files = $db->fetchAll('SELECT filename FROM attachments');
        $used = [];
        foreach ($files as $f) $used[$f['filename']] = true;

        // 头像和博客封面也在 uploads 目录，不能删
        $avatars = $db->fetchAll("SELECT avatar FROM users WHERE avatar IS NOT NULL AND avatar != ''");
        foreach ($avatars as $a) $used[\basename($a['avatar'])] = true;
        $covers = $db->fetchAll("SELECT cover_image FROM blogs WHERE cover_image IS NOT NULL AND cover_image != ''");
        foreach ($covers as $c) $used[\basename($c['cover_image'])] = true;

        // 扫描主题/博客/回复正文中的嵌入图片，防编辑器上传的内容图被误删
        // threads/posts 为退役空表（SplitDB 分片后正文外置 extern），原扫描扫不到分片正文 →
        // 删除两条退役表查询，改：主题图走 topic_index.excerpt_images（写帖/编辑时落库的图片列），
        // 博客未分片保留原查询，回复图按 reply.extern_path 遍历活跃/归档桶读取正文匹配（低频后台任务，性能可接受）。
        $uploadUrl = \rtrim(\UPLOAD_URL, '/');
        // 主题正文图片（main_index.topic_index.excerpt_images，逗号分隔 URL）
        $eiRows = \app\SplitDB\Schema::mainIndexDb()
            ->query("SELECT excerpt_images FROM topic_index WHERE excerpt_images IS NOT NULL AND excerpt_images != ''")
            ->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($eiRows as $ei) {
            foreach (explode(',', $ei) as $img) {
                $img = \trim($img);
                if ($img === '') continue;
                $fn = \basename($img);
                if ($fn === '' || $fn === '.' || $fn === '/') continue;
                $used[$fn] = true;
                $used['thumb_' . $fn] = true;
            }
        }
        // 博客内容（未分片，保留原查询）
        $blogRows = $db->fetchAll('SELECT content FROM blogs WHERE content LIKE :p', [':p' => '%' . $uploadUrl . '%']);
        foreach ($blogRows as $row) {
            if (\preg_match_all('/' . \preg_quote($uploadUrl . '/', '/') . '([^"\'\s?]+)/i', $row['content'] ?? '', $m)) {
                foreach ($m[1] as $fname) {
                    $used[\basename($fname)] = true;
                    $used['thumb_' . \basename($fname)] = true;
                }
            }
        }
        // 回复正文图片（posts 退役后回复正文存 extern，按 reply.extern_path 遍历桶读取匹配）
        $dataPath = defined('SPLITDB_DATA_PATH') ? \rtrim(SPLITDB_DATA_PATH, '/\\') : '';
        if ($dataPath !== '' && \is_dir($dataPath)) {
            foreach (['bucket/active', 'bucket/archive'] as $sub) {
                foreach (\glob($dataPath . '/' . $sub . '/*/*.sqlite') ?: [] as $bucketFile) {
                    try {
                        $bdb = \app\SplitDB\DBFactory::getConnection($bucketFile);
                    } catch (\Throwable $e) {
                        \error_log('[cleanupOrphanAttachments] 桶打开失败跳过: ' . $bucketFile . ' — ' . $e->getMessage());
                        continue;
                    }
                    $epRows = $bdb->query("SELECT id, extern_path FROM reply WHERE extern_path IS NOT NULL AND extern_path != ''")->fetchAll(\PDO::FETCH_ASSOC);
                    foreach ($epRows as $er) {
                        $replyContent = \app\SplitDB\ExternStorage::read($dataPath, (string)$er['extern_path'], (int)$er['id'], 'reply');
                        if ($replyContent === '') continue;
                        if (\preg_match_all('/' . \preg_quote($uploadUrl . '/', '/') . '([^"\'\s?]+)/i', $replyContent, $m)) {
                            foreach ($m[1] as $fname) {
                                $used[\basename($fname)] = true;
                                $used['thumb_' . \basename($fname)] = true;
                            }
                        }
                    }
                    // 主题正文图：正文同样外置 extern，但 topic_index.excerpt_images 最多仅收录前 9 张
                    // （Thread::extractExcerptContent 上限），且旧数据该列为空 → 必须扫 topic.extern_path 全文，
                    // 否则第 10 张起/未回填 excerpt_images 的主题正文图会被误判为孤儿删除
                    $tRows = $bdb->query("SELECT id, extern_path FROM topic WHERE extern_path IS NOT NULL AND extern_path != ''")->fetchAll(\PDO::FETCH_ASSOC);
                    foreach ($tRows as $tr) {
                        $topicContent = \app\SplitDB\ExternStorage::read($dataPath, (string)$tr['extern_path'], (int)$tr['id'], 'topic');
                        if ($topicContent === '') continue;
                        if (\preg_match_all('/' . \preg_quote($uploadUrl . '/', '/') . '([^"\'\s?]+)/i', $topicContent, $m)) {
                            foreach ($m[1] as $fname) {
                                $used[\basename($fname)] = true;
                                $used['thumb_' . \basename($fname)] = true;
                            }
                        }
                    }
                }
            }
        }

        $deleted = 0;
        $freed = 0;
        $uploadDir = \rtrim(\UPLOAD_PATH, '/\\');
        if (\is_dir($uploadDir)) {
            $allFiles = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($uploadDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($allFiles as $file) {
                // 跳过符号链接，防链接指向系统目录时被误删
                if ($file->isLink()) continue;
                if ($file->isFile()) {
                    $name = $file->getFilename();
                    if (!isset($used[$name])) {
                        // 时间缓冲：只删除 24 小时前上传的文件，防误删正在编辑中的附件
                        if ($file->getMTime() > time() - 86400) continue;
                        $size = @$file->getSize();
                        @\unlink($file->getPathname());
                        $deleted++;
                        if ($size !== false) $freed += $size;
                    }
                }
            }
        }
        return [$deleted, $freed];
    }

    /**
     * 获取数据库总大小（字节）— 统计 data/ 下全部 .sqlite 文件字节总和
     *
     * 递归遍历时跳过 extern/ 目录：正文外置后该目录含数十万 .txt 小文件，
     * 全量遍历耗时数十秒；sqlite 库文件仅分布在 meta/、bucket/、task_queue/ 等目录
     */
    public static function getDbSize(): int
    {
        $root = defined('SPLITDB_DATA_PATH') ? rtrim(SPLITDB_DATA_PATH, '/\\') : '';
        if ($root === '' || !is_dir($root)) return 0;
        $size = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                function ($file) {
                    // 目录：跳过 extern（正文外置海量 .txt 所在，不含 sqlite）
                    if ($file->isDir()) {
                        return $file->getFilename() !== 'extern';
                    }
                    // 文件：仅统计 sqlite 库文件
                    return strtolower($file->getExtension()) === 'sqlite';
                }
            )
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $size += @$file->getSize() ?: 0;
            }
        }
        return $size;
    }

    // ====== 附件查询工具 ======

    /**
     * 一次查询取回主题的全部附件（主楼 + 回帖），PHP 侧按 post_id 分组。
     * 替代详情页原先 getThreadAttachments + getPostAttachments 两次独立查询（合并为一次查询，减少开销）。
     *
     * @return array{thread: array, posts: array} thread=主楼附件(post_id 空)，posts=回帖附件(post_id 非空)
     */
    public static function getThreadAttachmentsGrouped(int $threadId): array
    {
        $db = \app\Core\Database::getInstance();
        $rows = $db->fetchAll('SELECT * FROM attachments WHERE thread_id = :tid', [':tid' => $threadId]);
        $thread = [];
        $posts = [];
        foreach ($rows as $row) {
            if (empty($row['post_id'])) {
                $thread[] = $row;
            } else {
                $posts[] = $row;
            }
        }
        return ['thread' => $thread, 'posts' => $posts];
    }

    /**
     * 获取主题的主楼附件（post_id 为空）
     */
    public static function getThreadAttachments(int $threadId): array
    {
        $db = \app\Core\Database::getInstance();
        return $db->fetchAll('SELECT * FROM attachments WHERE thread_id = :tid AND (post_id IS NULL OR post_id = 0)', [':tid' => $threadId]);
    }

    /**
     * 获取主题的回帖附件（post_id 不为空）
     */
    public static function getPostAttachments(int $threadId): array
    {
        $db = \app\Core\Database::getInstance();
        return $db->fetchAll('SELECT * FROM attachments WHERE thread_id = :tid AND post_id > 0', [':tid' => $threadId]);
    }

    /**
     * 删除附件（文件 + 记录）
     */
    public static function deleteAttachment(int $id): void
    {
        $db = \app\Core\Database::getInstance();
        $att = $db->fetchOne('SELECT * FROM attachments WHERE id = :id', [':id' => $id]);
        if ($att) {
            // 附件名白名单校验（系统生成：hex名.扩展名），非法文件名不触碰文件，仅清理记录
            if (\preg_match('/^[a-zA-Z0-9_.\-]+$/', (string)$att['filename']) === 1) {
                $filePath = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . $att['filename'];
                if (file_exists($filePath)) @unlink($filePath);
            }
            $db->query('DELETE FROM attachments WHERE id = :id', [':id' => $id]);
        }
    }
}
