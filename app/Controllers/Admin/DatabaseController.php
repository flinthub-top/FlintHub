<?php
/**
 * FlintHub 1.0 (SplitDB) — 后台数据库管理控制器
 * 纯 SQLite 方案：
 *   备份   → 逐库 `VACUUM INTO` 生成一致性快照（SQLite 3.27+，本环境 3.33 支持）
 *   优化   → 每库 `PRAGMA wal_checkpoint(TRUNCATE)` + `VACUUM`
 *   恢复   → 按备份文件名映射回 data/ 对应库文件（带路径穿越校验）
 *   导入   → 仅接受按本系统命名规范的 .sqlite 备份文件
 * 备份文件名约定：forum_backup_{时间戳}__{相对路径(下划线化)}.sqlite
 *   例：forum_backup_20260813_120000__meta__business.sqlite → data/meta/business.sqlite
 * @file app/Controllers/Admin/DatabaseController.php
 * @package app\Controllers\Admin
 */

namespace app\Controllers\Admin;

use app\Helpers\Csrf;
use app\SplitDB\DBFactory;
use app\SplitDB\ShardRouter;

class DatabaseController extends BaseController
{
    public function index()
    {
        $db = \app\Core\Database::getInstance();
        $backupDir = realpath(__DIR__ . '/../../../protected/backups/') ?: (__DIR__ . '/../../../protected/backups/');
        $backupDir = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR;
        if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);

        $action = $_GET['action'] ?? 'info';
        $allowedActions = ['info', 'backup', 'import', 'optimize', 'download', 'restore', 'delete',
                           'extern_merge', 'extern_merge_start', 'extern_merge_resume', 'extern_merge_stop'];
        if (!in_array($action, $allowedActions, true)) $action = 'info';

        $error = $_GET['error'] ?? '';
        $success = $_GET['success'] ?? '';
        $externMergeView = null; // 非 null 时数据库页渲染 extern 合并进度区（含 meta refresh 续批）

        // ★ extern 正文合并：GET 链式续批（meta refresh 不能 POST，需令牌防伪造；参照搜索索引重建）
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'extern_merge') {
            $emPage = (int)($_GET['em_page'] ?? 0);
            $emToken = $_GET['_emt'] ?? '';
            if ($emPage > 0) {
                if ($emToken === '' || empty($_SESSION['_extern_merge_token'])
                    || !\hash_equals((string)$_SESSION['_extern_merge_token'], $emToken)) {
                    $this->redirect('/admin/database?error=' . \urlencode(\app\Helpers\I18n::get('admin.extern_merge_invalid_req')));
                }
                $per = max(1, min(\app\SplitDB\ExternMerger::MAX_GROUPS_PER_BATCH, (int)($_GET['em_per_page'] ?? 10)));
                set_time_limit(120); // 每批最多 2 分钟，防长时间占用
                $res = \app\SplitDB\ExternMerger::processBatch($per);
                if (!$res['ok']) {
                    $this->redirect('/admin/database?error=' . \urlencode(\app\Helpers\I18n::get('admin.extern_merge_' . $res['error'])));
                }
                if ($res['finished']) {
                    $this->redirect('/admin/database?success=extern_merge_done');
                }
                $externMergeView = $this->externMergeProgressData($res, $per);
            }
        }

        // 其余操作均为 POST（含下载：改为 POST + CSRF token，防恶意链接/iframe 诱导触发下载）
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($action === 'backup') { $this->handleBackup($backupDir); $this->redirect('/admin/database?success=backup'); }
            elseif ($action === 'import') { $err = $this->handleImport(); $this->redirect('/admin/database?' . ($err ? 'error=' . urlencode($err) : 'success=import')); }
            elseif ($action === 'optimize') { $this->handleOptimize(); $this->redirect('/admin/database?success=optimize'); }
            elseif ($action === 'restore') { $err = $this->handleRestore($backupDir); $this->redirect('/admin/database?' . ($err ? 'error=' . urlencode($err) : 'success=restore')); }
            elseif ($action === 'delete') { $this->handleDelete($backupDir); $this->redirect('/admin/database?success=delete'); }
            elseif ($action === 'download') {
                // 下载前强制 CSRF 校验（失败即 403 退出，不落盘、不泄露文件是否存在信息）
                Csrf::verifyOrDie($_POST['csrf'] ?? '');
                $this->handleDownload($backupDir);
                return;
            } elseif ($action === 'extern_merge_start' || $action === 'extern_merge_resume') {
                // extern 正文合并：开始（强制重扫）/ 继续（保留断点续跑）
                Csrf::verifyOrDie($_POST['csrf'] ?? '');
                $per = max(1, min(\app\SplitDB\ExternMerger::MAX_GROUPS_PER_BATCH, (int)($_POST['extern_merge_per_page'] ?? 10)));
                $_SESSION['_extern_merge_token'] = \bin2hex(\random_bytes(16)); // 续批令牌（GET 链式续批校验）
                set_time_limit(120);
                $res = ($action === 'extern_merge_start')
                    ? \app\SplitDB\ExternMerger::start($per)
                    : \app\SplitDB\ExternMerger::processBatch($per);
                if (!$res['ok']) {
                    $this->redirect('/admin/database?error=' . \urlencode(\app\Helpers\I18n::get('admin.extern_merge_' . $res['error'])));
                }
                if ($res['finished']) {
                    $this->redirect('/admin/database?success=extern_merge_done');
                }
                // 本批完成但未结束 → 渲染进度页（视图 meta refresh 自动续批）
                $externMergeView = $this->externMergeProgressData($res, $per);
            } elseif ($action === 'extern_merge_stop') {
                Csrf::verifyOrDie($_POST['csrf'] ?? '');
                unset($_SESSION['_extern_merge_token']); // 令牌消失 → meta refresh 断链即停
                $this->redirect('/admin/database?success=extern_merge_stopped');
            }
        }

        // 备份列表（分页）
        $backups = [];
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        if (is_dir($backupDir)) {
            $files = array_merge(
                glob($backupDir . 'forum_backup_*.zip') ?: [],
                glob($backupDir . 'forum_backup_*.sqlite') ?: []
            );
            rsort($files);
            $total = count($files);
            $totalPages = max(1, (int)ceil($total / $perPage));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $perPage;
            $paged = array_slice($files, $offset, $perPage);
            foreach ($paged as $file) {
                $backups[] = ['name' => basename($file), 'size' => filesize($file), 'time' => filemtime($file)];
            }
        } else {
            $total = 0;
            $totalPages = 1;
        }

        // 数据库大小 = data/ 下全部 .sqlite 文件字节总和
        $dbSize = 0;
        foreach ($this->sqliteFiles() as $abs) {
            $dbSize += @filesize($abs) ?: 0;
        }

        // extern 合并视图数据：运行中(externMergeView) > 空闲但有断点(idle 可继续) > null(无任务)
        $externMerge = $externMergeView;
        if ($externMerge === null) {
            $st = \app\SplitDB\ExternMerger::status();
            if ($st !== null) {
                $st['idle'] = true; // 有断点未跑完：显示「继续/停止」，不自动刷新
                $externMerge = $st;
            }
        }

        $this->view('admin/database', [
            'backups' => $backups,
            'dbSize' => $dbSize, 'error' => $error, 'success' => $success,
            'dbPath' => \app\Helpers\I18n::get('admin.db_data_dir_value', ['path' => ShardRouter::dataPath()]),
            'page' => $page, 'totalPages' => $totalPages, 'total' => $total,
            'externMerge' => $externMerge,
            'hasCsrf' => true, '__nav_active' => 'database',
        ]);
    }

    /**
     * extern 合并进度视图数据（本批完成但未结束时调用）：done/total + 链式续批 URL
     * @param array $res ExternMerger::processBatch / start 返回值
     * @param int   $per 每批组数
     */
    private function externMergeProgressData(array $res, int $per): array
    {
        $done = (int)$res['done'];
        $total = (int)$res['total'];
        $per = max(1, min(\app\SplitDB\ExternMerger::MAX_GROUPS_PER_BATCH, $per));
        $batch = max(1, (int)ceil($done / $per) + 1);
        return [
            'running' => true,
            'idle' => false,
            'done' => $done,
            'total' => $total,
            'scanned' => (int)$res['scanned'],
            'perPage' => $per,
            'nextUrl' => '/admin/database?action=extern_merge&em_page=' . $batch
                . '&em_per_page=' . $per
                . '&_emt=' . \urlencode((string)($_SESSION['_extern_merge_token'] ?? '')),
        ];
    }

    // ==================== SplitDB 引擎控制台 ====================

    /**
     * SplitDB 引擎概览（只读监控）— 分片分布 / 总帖子数 / 建议桶数 / 当前季度
     * 路由：GET /admin/database/overview（BaseController 构造器已完成管理员校验）
     */
    public function overview()
    {
        // 引擎概览数据（全部只读，无 POST 操作；自动扩容检查不在本页触发）
        $currentBuckets = self::currentBucketSize();
        $quarter = ShardRouter::quarter();
        $forceRefresh = ($_GET['refresh_stats'] ?? '') === '1';
        $bucketStats = $this->bucketStatsCached($quarter, $forceRefresh);
        $totalThreads = $this->totalThreads();
        $suggested = self::suggestBuckets($totalThreads);

        $this->view('admin/database/overview', [
            'bucketSize'      => $currentBuckets,
            'quarter'         => $quarter,
            'bucketStats'     => $bucketStats,
            'totalThreads'    => $totalThreads,
            'suggestedBuckets'=> $suggested,
            '__nav_active'    => 'database_overview',
        ]);
    }

    /**
     * SplitDB 引擎设置（操作型）— 桶数调整 / 自动扩容 / 扩容记录 / 一致性检查
     * 路由：GET/POST /admin/database/settings（BaseController 构造器已完成管理员校验）
     */
    public function settings()
    {
        // action 从 POST body 优先读取（表单以 <input name="action"> 提交）；GET 兼容旧链接 ?action=
        $action = ($_SERVER['REQUEST_METHOD'] === 'POST')
            ? (string)($_POST['action'] ?? 'info')
            : (string)($_GET['action'] ?? 'info');
        $allowedActions = ['info', 'save_buckets', 'save_auto_expand', 'consistency', 'save_capacity', 'save_archive'];
        if (!in_array($action, $allowedActions, true)) $action = 'info';

        $error = $_GET['error'] ?? '';
        $success = $_GET['success'] ?? '';
        $consistencyResult = null;

        // POST 操作（成功后跳转回本页并带消息，PRG 模式防重复提交）
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($action === 'save_buckets') {
                $r = $this->handleSaveBuckets();
                $this->redirect('/admin/database/settings?' . ($r['ok'] ? 'success=buckets' : 'error=' . urlencode($r['msg'])));
            } elseif ($action === 'save_auto_expand') {
                $r = $this->handleSaveAutoExpand();
                $this->redirect('/admin/database/settings?' . ($r['ok'] ? 'success=autoexpand' : 'error=' . urlencode($r['msg'])));
            } elseif ($action === 'save_capacity') {
                $r = $this->handleSaveCapacity();
                $this->redirect('/admin/database/settings?' . ($r['ok'] ? 'success=capacity' : 'error=' . urlencode($r['msg'])));
            } elseif ($action === 'save_archive') {
                $r = $this->handleSaveArchive();
                $this->redirect('/admin/database/settings?' . ($r['ok'] ? 'success=archive' : 'error=' . urlencode($r['msg'])));
            } elseif ($action === 'consistency') {
                // 一致性检查结果在本页渲染（不跳转）
                $scope = ($_POST['scope'] ?? 'current') === 'all' ? 'all' : 'current';
                $consistencyResult = $this->consistencyCheck($scope);
            }
        }

        // GET 页加载：自动扩容检查（触发点 1/2——引擎设置页加载时；已迁移自原 engine 页）
        $autoExpandMsg = '';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            try {
                $autoExpandMsg = self::maybeAutoExpand();
            } catch (\Throwable $e) {
                \error_log('maybeAutoExpand error: ' . $e->getMessage());
            }
        }

        // config.php 写权限（不可写则前端按钮置灰 + 友好提示）
        $configFile = dirname(__DIR__, 3) . '/config.php';
        $configWritable = is_writable($configFile);

        // 自动扩容设置回显
        $autoExpand = [
            'enabled'   => \app\Helpers\Settings::get('auto_expand_enabled', '0'),
            'threshold' => \app\Helpers\Settings::get('auto_expand_threshold', '0'),
            'lastAt'    => (int)\app\Helpers\Settings::get('auto_expand_last_at', '0'),
        ];

        // 扩容记录（最近 10 条：auto_expand + 手动调整 admin_action，target_type=database）
        $expandRecords = [];
        try {
            $db = \app\Core\Database::getInstance();
            $rows = $db->fetchAll(
                "SELECT id, username, action, detail, ip, created_at FROM audit_logs
                 WHERE target_type = 'database' AND action IN ('auto_expand', 'admin_action')
                 ORDER BY id DESC LIMIT 10"
            );
            $expandRecords = $rows ?: [];
        } catch (\Throwable $e) {
            \error_log('expand records query failed: ' . $e->getMessage());
        }

        // 数据总量 = 主题数 + 回复数（供设置页顶部"适用人群提示条"判断：≤10 万无需关注本页）
        $totalThreads = $this->totalThreads();
        $totalPosts = $totalThreads + $this->totalReplies();

        $this->view('admin/database/settings', [
            'bucketSize'      => self::currentBucketSize(),
            'configWritable'  => $configWritable,
            'autoExpand'      => $autoExpand,
            'autoExpandMsg'   => $autoExpandMsg,
            'expandRecords'   => $expandRecords,
            'totalThreads'    => $totalThreads,
            'totalPosts'      => $totalPosts,
            'consistency'     => $consistencyResult,
            // 智能扩容与归档配置回显（默认值：容量 5 万 / 归档 365 天）
            'bucketCapacity'  => (int)\app\Helpers\Settings::get('bucket_safe_capacity', '50000'),
            'archiveDays'     => (int)\app\Helpers\Settings::get('archive_after_days', '365'),
            'error'           => $error,
            'success'         => $success,
            '__nav_active'    => 'database_settings',
        ]);
    }

    /**
     * 旧路径兼容：/admin/database/engine → /admin/database/overview（302 临时重定向，保旧链接不失效）
     */
    public function engine()
    {
        $this->redirect('/admin/database/overview');
    }

    /**
     * 读取 config.php 中当前配置桶数（文件为准；文件缺失/无法解析时回退常量，再回退 32）
     * 实现已下沉 app/SplitDB/BucketAutoScaler（Model/Controller 共用同一份逻辑）
     */
    private static function currentBucketSize(): int
    {
        return \app\SplitDB\BucketAutoScaler::currentBucketSize();
    }

    /**
     * 桶数动态调整（POST 保存）：白名单 + 只增不减 → 安全写 config.php
     */
    private function handleSaveBuckets(): array
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $target = (int)($_POST['bucket_size'] ?? 0);
        if (!in_array($target, [32, 64, 128, 256], true)) {
            return ['ok' => false, 'msg' => \app\Helpers\I18n::get('admin.db_bucket_invalid')];
        }
        $current = self::currentBucketSize();
        if ($target < $current) {
            return ['ok' => false, 'msg' => \app\Helpers\I18n::get('admin.db_bucket_increase_only_current', ['current' => $current])];
        }
        if ($target === $current) {
            return ['ok' => false, 'msg' => \app\Helpers\I18n::get('admin.db_bucket_same')];
        }
        $r = self::updateBucketSize($target);
        if ($r['ok']) {
            \app\Helpers\AuditLog::log('admin_action', 'database', 0, "手动调整桶数: {$current} → {$target}");
        }
        return $r;
    }

    /**
     * 自动扩容设置保存（POST）：开关 + 阈值，阈值 intval ≥ 1
     */
    private function handleSaveAutoExpand(): array
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $enabled = (($_POST['auto_expand_enabled'] ?? '') === '1') ? '1' : '0';
        $threshold = max(1, (int)($_POST['auto_expand_threshold'] ?? 0));
        $setting = new \app\Models\Setting();
        $setting->setValue('auto_expand_enabled', $enabled);
        $setting->setValue('auto_expand_threshold', (string)$threshold);
        \app\Helpers\Settings::buildCache();
        \app\Helpers\AuditLog::log('admin_action', 'database', 0, "自动扩容设置: 开关={$enabled} 阈值={$threshold}");
        return ['ok' => true, 'msg' => \app\Helpers\I18n::get('admin.db_autoexpand_saved')];
    }

    /**
     * 单桶容量保存（POST）：bucket_safe_capacity 白名单 [30000,50000,80000,100000]（仅统计主题帖）
     */
    private function handleSaveCapacity(): array
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $capacity = (int)($_POST['bucket_safe_capacity'] ?? 50000);
        if (!in_array($capacity, [30000, 50000, 80000, 100000], true)) {
            return ['ok' => false, 'msg' => \app\Helpers\I18n::get('admin.db_capacity_invalid')];
        }
        $setting = new \app\Models\Setting();
        $setting->setValue('bucket_safe_capacity', (string)$capacity);
        \app\Helpers\Settings::buildCache();
        \app\Helpers\AuditLog::log('admin_action', 'database', 0, "单桶容量设置: {$capacity}");
        return ['ok' => true, 'msg' => \app\Helpers\I18n::get('admin.db_capacity_saved')];
    }

    /**
     * 归档设置保存（POST）：archive_after_days 0=关闭 / 180 / 365 / 自定义（intval ≥ 0 且 ≤ 3650）
     * custom 时天数在 archive_after_days_custom 输入框（select 值为 'custom'），必须读取该字段
     */
    private function handleSaveArchive(): array
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $selected = (string)($_POST['archive_after_days'] ?? '365');
        if ($selected === 'custom') {
            $days = max(0, min(3650, (int)($_POST['archive_after_days_custom'] ?? 0)));
        } else {
            $days = max(0, min(3650, (int)$selected));
        }
        $setting = new \app\Models\Setting();
        $setting->setValue('archive_after_days', (string)$days);
        \app\Helpers\Settings::buildCache();
        \app\Helpers\AuditLog::log('admin_action', 'database', 0, "归档设置: {$days} 天" . ($days === 0 ? '（关闭归档）' : ''));
        return ['ok' => true, 'msg' => \app\Helpers\I18n::get('admin.db_archive_saved')];
    }

    /**
     * 安全更新 config.php 的 SPLITDB_BUCKET_SIZE（供手动调整与自动扩容共用）
     * 四步：备份 → 正则替换 → 写回（失败不覆盖）→ opcache 失效（绝对路径）
     * 前置：auto_expand.lock 防重入（获取失败立即放弃，防并发写坏/跳级扩容）
     * 实现已下沉 app/SplitDB/BucketAutoScaler（Model/Controller 共用同一份逻辑）
     *
     * @return array ['ok' => bool, 'msg' => string]
     */
    public static function updateBucketSize(int $newSize): array
    {
        return \app\SplitDB\BucketAutoScaler::updateBucketSize($newSize);
    }

    /**
     * 自动扩容检查（静态，供引擎页 / cron_trigger / cli worker 三处触发，不依赖实例与管理员会话）
     * 触发条件（全部满足才执行）：
     *   ① 开关启用 ② 数据量最大的桶的 topic 行数 ≥ bucket_safe_capacity（100%，仅统计主题帖）③ 距上次扩容 ≥ 24h
     *   防重入锁由 updateBucketSize() 内部统一持有（auto_expand.lock，获取失败立即放弃）
     * @return string 执行结果消息（未满足条件时返回 ''）
     */
    public static function maybeAutoExpand(): string
    {
        try {
            if (\app\Helpers\Settings::get('auto_expand_enabled', '0') !== '1') return '';
            $capacity = (int)\app\Helpers\Settings::get('bucket_safe_capacity', '50000');
            if ($capacity <= 0) return '';
            $maxLoad = self::maxBucketLoad();
            if ($maxLoad < $capacity) return '';

            // 24 小时间隔守卫（防阈值过低导致频繁扩容）
            $lastAt = (int)\app\Helpers\Settings::get('auto_expand_last_at', '0');
            if ($lastAt > 0 && (time() - $lastAt) < 86400) return '';

            $current = self::currentBucketSize();
            $newSize = $current * 2;
            if ($newSize > 256) return \app\Helpers\I18n::get('admin.db_expand_maxed');

            // 防重入锁统一由 updateBucketSize() 内部持有（auto_expand.lock，获取失败立即放弃）
            $r = self::updateBucketSize($newSize);
            if (!$r['ok']) {
                \error_log('SplitDB 自动扩容失败: ' . $r['msg']);
                return $r['msg'];
            }
            // 记录本次扩容时间（24h 守卫依据）
            $setting = new \app\Models\Setting();
            $setting->setValue('auto_expand_last_at', (string)time());
            \app\Helpers\Settings::buildCache();
            \app\Helpers\AuditLog::log('auto_expand', 'database', 0, "自动扩容: 桶数 {$current} → {$newSize}（最大桶负载 {$maxLoad} ≥ 容量 {$capacity}）");
            return \app\Helpers\I18n::get('admin.db_expand_done', ['from' => $current, 'to' => $newSize]);
        } catch (\Throwable $e) {
            \error_log('maybeAutoExpand error: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 紧急扩容（旧帖爆火安全余量：load ≥ 90% 触发）
     * 不受 auto_expand_enabled 开关限制（紧急必须扩）；保留 24h 守卫 + flock 防重入 + 翻倍 ≤256；
     * 实现已下沉 app/SplitDB/BucketAutoScaler::emergencyExpand（Model/Controller 共用），
     * 控制器保留薄委托入口供既有调用方兼容。
     *
     * @param int $load     当前桶负载（topic 行数）
     * @param int $capacity 单桶容量（bucket_safe_capacity）
     * @return string 执行结果消息（未满足条件时返回 ''）
     */
    public static function maybeEmergencyExpand(int $load = 0, int $capacity = 0): string
    {
        return \app\SplitDB\BucketAutoScaler::emergencyExpand($load, $capacity);
    }

    /**
     * 当前桶数下数据量最大的桶的 topic 行数（仅统计主题帖；跨全部活跃季度）
     * 60s 缓存 cache_bucket_load.json（命中直接返回；避免多桶 × 多季度每请求数百次 PDO 退化）
     */
    public static function maxBucketLoad(): int
    {
        $cacheFile = rtrim(ShardRouter::dataPath(), '/\\') . '/runtime/cache_bucket_load.json';
        if (is_file($cacheFile)) {
            $cached = @json_decode(@file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['ts']) && (time() - (int)$cached['ts']) < 60) {
                return (int)($cached['max'] ?? 0);
            }
        }
        $root = rtrim(ShardRouter::dataPath(), '/\\');
        $files = glob($root . '/bucket/active/*/*.sqlite') ?: [];
        $max = 0;
        foreach ($files as $f) {
            try {
                $pdo = new \PDO('sqlite:' . $f, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $pdo->exec('PRAGMA query_only = true;');
                $t = (int)$pdo->query('SELECT COUNT(*) FROM topic')->fetchColumn();
                $pdo = null;
                if ($t > $max) $max = $t;
            } catch (\Throwable $e) {
                // 单桶异常跳过，不阻塞整体
            }
        }
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents($cacheFile, json_encode(['ts' => time(), 'max' => $max]), LOCK_EX);
        return $max;
    }

    /**
     * 建议扩容提示数据（渲染于站点设置页顶部；maxBucketLoad ≥ 80% × capacity 命中）
     * @return array ['suggest' => bool, 'maxLoad' => int, 'capacity' => int, 'ratio' => int]
     */
    public static function suggestExpandData(): array
    {
        $capacity = (int)\app\Helpers\Settings::get('bucket_safe_capacity', '50000');
        $maxLoad = self::maxBucketLoad();
        $ratio = $capacity > 0 ? (int)round($maxLoad * 100 / $capacity) : 0;
        return [
            'suggest'  => $capacity > 0 && $maxLoad >= (int)($capacity * 0.8),
            'maxLoad'  => $maxLoad,
            'capacity' => $capacity,
            'ratio'    => $ratio,
        ];
    }

    /**
     * 分片分布统计（60s 控制台缓存；?refresh_stats=1 强制实时重算）
     */
    private function bucketStatsCached(string $quarter, bool $force = false): array
    {
        $cacheFile = rtrim(ShardRouter::dataPath(), '/\\') . '/runtime/cache_engine_stats.json';
        if (!$force && is_file($cacheFile)) {
            $cached = @json_decode(@file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['ts']) && (time() - (int)$cached['ts']) < 60) {
                return $cached['stats'] ?? [];
            }
        }
        $stats = $this->bucketStatsLive($quarter);
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents($cacheFile, json_encode(['ts' => time(), 'stats' => $stats], JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $stats;
    }

    /**
     * 实时遍历当前季度全部桶文件：逐桶直接 PDO（不经 DBFactory 池，避免 LRU 句柄抖动；
     * 置 PRAGMA query_only 只读句柄，避免隐式 WAL 读锁；读完即关）
     */
    private function bucketStatsLive(string $quarter): array
    {
        $root = rtrim(ShardRouter::dataPath(), '/\\');
        $files = glob($root . '/bucket/active/' . $quarter . '/*.sqlite') ?: [];
        sort($files, SORT_NATURAL);
        $rows = [];
        foreach ($files as $f) {
            $bucket = basename($f, '.sqlite');
            $topic = $reply = -1;
            $size = @filesize($f) ?: 0;
            try {
                $pdo = new \PDO('sqlite:' . $f, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $pdo->exec('PRAGMA query_only = true;');
                $topic = (int)$pdo->query('SELECT COUNT(*) FROM topic')->fetchColumn();
                $reply = (int)$pdo->query('SELECT COUNT(*) FROM reply')->fetchColumn();
                $pdo = null; // 立即释放句柄
            } catch (\Throwable $e) {
                // 单桶异常跳过并标注（-1），不阻塞整体
            }
            $rows[] = ['bucket' => $bucket, 'topic' => $topic, 'reply' => $reply, 'size' => $size];
        }
        return $rows;
    }

    /**
     * 当前总帖子数（topic_index 全量 COUNT）
     */
    private function totalThreads(): int
    {
        try {
            $db = \app\SplitDB\Schema::mainIndexDb();
            return (int)$db->query('SELECT COUNT(*) FROM topic_index')->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 当前总回复数（reply_index 全量 COUNT）
     */
    private function totalReplies(): int
    {
        try {
            $db = \app\SplitDB\Schema::mainIndexDb();
            return (int)$db->query('SELECT COUNT(*) FROM reply_index')->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 根据当前帖子数建议桶数：<10万→32；10万~50万（含50万）→64；50万~200万（含200万）→128；>200万→256
     */
    private static function suggestBuckets(int $total): int
    {
        if ($total < 100000) return 32;
        if ($total <= 500000) return 64;
        if ($total <= 2000000) return 128;
        return 256;
    }

    /**
     * 备份列表（时间倒序，最多 20 条；复用 index() 的 glob 口径）
     */
    private function backupList(string $backupDir, int $limit = 20): array
    {
        $backups = [];
        if (is_dir($backupDir)) {
            $files = array_merge(
                glob($backupDir . 'forum_backup_*.zip') ?: [],
                glob($backupDir . 'forum_backup_*.sqlite') ?: []
            );
            rsort($files);
            foreach (array_slice($files, 0, $limit) as $file) {
                $backups[] = ['name' => basename($file), 'size' => filesize($file), 'time' => filemtime($file)];
            }
        }
        return $backups;
    }

    /**
     * 一致性检查：所选范围内桶 topic/reply 汇总 vs main_index 索引行数对拍
     * scope = current（默认，仅当前季度）/ all（全部历史）
     * @return array 对拍结果（scope/桶汇总/索引计数/是否一致/异常桶清单）
     */
    private function consistencyCheck(string $scope): array
    {
        $root = rtrim(ShardRouter::dataPath(), '/\\');
        if ($scope === 'all') {
            $files = glob($root . '/bucket/active/*/*.sqlite') ?: [];
        } else {
            $files = glob($root . '/bucket/active/' . ShardRouter::quarter() . '/*.sqlite') ?: [];
        }
        $bucketTopic = 0;
        $bucketReply = 0;
        $errors = [];
        foreach ($files as $f) {
            try {
                $pdo = new \PDO('sqlite:' . $f, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $pdo->exec('PRAGMA query_only = true;');
                $bucketTopic += (int)$pdo->query('SELECT COUNT(*) FROM topic')->fetchColumn();
                $bucketReply += (int)$pdo->query('SELECT COUNT(*) FROM reply')->fetchColumn();
                $pdo = null;
            } catch (\Throwable $e) {
                $errors[] = basename($f);
            }
        }
        $idxTopic = 0;
        $idxReply = 0;
        try {
            $db = \app\SplitDB\Schema::mainIndexDb();
            $idxTopic = (int)$db->query('SELECT COUNT(*) FROM topic_index')->fetchColumn();
            $idxReply = (int)$db->query('SELECT COUNT(*) FROM reply_index')->fetchColumn();
        } catch (\Throwable $e) {
            $errors[] = \app\Helpers\I18n::get('admin.db_main_index_read_failed');
        }
        return [
            'scope'        => $scope,
            'bucketTopic'  => $bucketTopic,
            'bucketReply'  => $bucketReply,
            'idxTopic'     => $idxTopic,
            'idxReply'     => $idxReply,
            'topicMatch'   => ($bucketTopic === $idxTopic),
            'replyMatch'   => ($bucketReply === $idxReply),
            'bucketFiles'  => count($files),
            'errors'       => $errors,
        ];
    }

    // ==================== 备份 / 优化 ====================

    private function handleBackup(string $backupDir): void
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $ts = date('Ymd_His');
        $dest = $backupDir . "forum_backup_{$ts}.zip";

        // ① 先对全部 SQLite 库执行 WAL checkpoint，把 -wal 内容折叠回主库，保证打包快照一致
        foreach ($this->sqliteFiles() as $abs) {
            try {
                $pdo = DBFactory::getConnection($abs);
                $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            } catch (\Throwable $e) {
                \error_log('SQLite checkpoint failed [' . $abs . ']: ' . $e->getMessage());
            }
        }
        // ② 释放连接句柄（Windows 下避免文件被占用导致打包失败）
        DBFactory::closeAll();

        // ③ 将 data/ 目录树打包为单个 zip（meta 库 / 分片桶 / extern 正文 / 队列库 / sessions 全量）
        $count = $this->zipDataDir($dest);
        if ($count > 0) {
            \app\Helpers\AuditLog::log('backup', 'database', 0, "SplitDB 数据备份完成，打包 {$count} 个文件: " . basename($dest));
            // 备份自动轮转：仅保留最近 BACKUP_KEEP 份完整备份（.zip/.sqlite），防备份文件无限累积占满磁盘
            self::rotateBackups($backupDir, self::BACKUP_KEEP);
        } else {
            \error_log('SQLite backup: data/ 打包失败或目录为空');
        }
    }

    /** 完整备份保留份数（超出自动删除最旧） */
    private const BACKUP_KEEP = 10;

    /**
     * 备份轮转：按修改时间排序，仅保留最近 $keep 份完整备份文件，删除更旧的
     * （只删受控的 forum_backup_* 前缀文件，且经 realpath 校验确认在备份目录内）
     */
    private static function rotateBackups(string $backupDir, int $keep): void
    {
        if ($keep < 1) $keep = 1;
        $files = \array_merge(
            \glob($backupDir . 'forum_backup_*.zip') ?: [],
            \glob($backupDir . 'forum_backup_*.sqlite') ?: []
        );
        if (\count($files) <= $keep) return;
        \usort($files, function ($a, $b) { return \filemtime($b) <=> \filemtime($a); });
        $realBase = \realpath($backupDir);
        foreach (\array_slice($files, $keep) as $old) {
            $real = \realpath($old);
            if ($real === false || $realBase === false || \strpos($real, $realBase) !== 0) continue;
            if (\is_file($real)) {
                @\unlink($real);
                \error_log('SQLite backup rotation: removed old backup ' . \basename($real));
            }
        }
    }

    /**
     * 将 data/ 目录树打包为单个 zip（相对路径入包，含全部 .sqlite 与 extern 正文）
     * @return int 打包的文件数（0 表示失败）
     */
    private function zipDataDir(string $destZip): int
    {
        $root = rtrim(ShardRouter::dataPath(), '/\\');
        if (!is_dir($root)) return 0;
        @unlink($destZip);
        $zip = new \ZipArchive();
        if ($zip->open($destZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            \error_log('zip backup: 无法创建 ' . $destZip);
            return 0;
        }
        $count = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $abs = $file->getPathname();
            $rel = ltrim(str_replace(['\\'], '/', substr($abs, strlen($root))), '/');
            if ($zip->addFile($abs, $rel)) $count++;
        }
        $zip->close();
        return $count;
    }

    /**
     * 解包 zip 备份回 data/ 目录（带路径穿越防护）
     * @return string 空串表示成功，非空为错误消息
     */
    private function extractZipToData(string $zipPath): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return \app\Helpers\I18n::get('admin.db_zip_invalid');
        }
        $root = rtrim(ShardRouter::dataPath(), '/\\');
        if (!is_dir($root)) @mkdir($root, 0755, true);
        $realRoot = realpath($root);
        if ($realRoot === false) { $zip->close(); return \app\Helpers\I18n::get('admin.db_data_dir_inaccessible'); }

        // 逐条目校验，防 zip 路径穿越（.. / 绝对路径 / 盘符）
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
            if ($name === '' || $name[0] === '/' || strpos($name, '..') !== false || strpos($name, ':') !== false) {
                $zip->close();
                return \app\Helpers\I18n::get('admin.db_zip_illegal_path');
            }
        }
        // Windows：覆盖文件前先释放连接句柄
        DBFactory::closeAll();
        if (!$zip->extractTo($root)) {
            $zip->close();
            return \app\Helpers\I18n::get('admin.db_extract_failed');
        }
        $zip->close();
        return '';
    }

    private function handleOptimize(): void
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        foreach ($this->sqliteFiles() as $abs) {
            try {
                $pdo = DBFactory::getConnection($abs);
                $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
                $pdo->exec('VACUUM');
            } catch (\Throwable $e) {
                \error_log('SQLite optimize failed [' . $abs . ']: ' . $e->getMessage());
            }
        }
    }

    // ==================== 恢复 / 导入 ====================

    /**
     * 从备份列表中选择文件恢复（.zip 整包还原 data/；.sqlite 按文件名映射回对应库）
     * @return string 空串表示成功，非空为错误消息
     */
    private function handleRestore(string $backupDir): string
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $filename = $_POST['backup_file'] ?? '';
        if ($filename === '') return \app\Helpers\I18n::get('admin.db_no_file');
        if (basename($filename) !== $filename) return \app\Helpers\I18n::get('admin.db_illegal_filename');

        $filePath = $backupDir . $filename;
        $realFile = realpath($filePath);
        $realBase = realpath($backupDir);
        if ($realFile === false || $realBase === false || strpos($realFile, $realBase) !== 0) {
            return \app\Helpers\I18n::get('admin.db_illegal_path');
        }
        if (!is_file($realFile)) return \app\Helpers\I18n::get('admin.db_backup_missing');

        // .zip 整包恢复：解包覆盖 data/ 目录
        if (str_ends_with($filename, '.zip')) {
            $err = $this->extractZipToData($realFile);
            if ($err !== '') return $err;
            \app\Helpers\AuditLog::log('restore', 'database', 0, '数据库整包恢复: ' . $filename);
            return '';
        }

        if (!str_ends_with($filename, '.sqlite')) return \app\Helpers\I18n::get('admin.db_sqlite_only');

        $target = $this->mapBackupToTarget($filename);
        if ($target === null) return \app\Helpers\I18n::get('admin.db_restore_name_invalid');

        // 覆盖前先备份当前库为 .prev（安全网），再替换
        $prev = $target . '.prev';
        if (is_file($target)) {
            @copy($target, $prev);
        }
        if (!@copy($realFile, $target)) {
            return \app\Helpers\I18n::get('admin.db_restore_write_failed');
        }
        @unlink($prev);

        \app\Helpers\AuditLog::log('import', 'database', 0, '数据库恢复: ' . $filename);
        return '';
    }

    /**
     * 上传备份文件导入（.zip 整包还原 data/；.sqlite 单库按命名规范映射）
     * @return string 空串表示成功，非空为错误消息
     */
    private function handleImport(): string
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        if (empty($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            return \app\Helpers\I18n::get('admin.db_upload_failed');
        }
        $uploaded = $_FILES['backup_file']['tmp_name'];
        if (!is_file($uploaded) || filesize($uploaded) <= 0) {
            return \app\Helpers\I18n::get('admin.db_upload_invalid');
        }
        $ext = strtolower(pathinfo($_FILES['backup_file']['name'] ?? '', PATHINFO_EXTENSION));

        // .zip 整包导入：解包覆盖 data/ 目录
        if ($ext === 'zip') {
            $err = $this->extractZipToData($uploaded);
            if ($err !== '') return $err;
            \app\Helpers\AuditLog::log('import', 'database', 0, '数据库整包导入: ' . ($_FILES['backup_file']['name'] ?? 'unknown.zip'));
            return '';
        }

        if ($ext !== 'sqlite') return \app\Helpers\I18n::get('admin.db_sqlite_only');

        $target = $this->mapBackupToTarget($_FILES['backup_file']['name'] ?? '');
        if ($target === null) {
            return \app\Helpers\I18n::get('admin.db_import_name_invalid');
        }

        // 校验上传文件确为 SQLite（文件头 "SQLite format 3"）
        $head = @file_get_contents($uploaded, false, null, 0, 16);
        if ($head === false || strpos($head, 'SQLite format 3') !== 0) {
            return \app\Helpers\I18n::get('admin.db_not_sqlite');
        }

        if (!@copy($uploaded, $target)) {
            return \app\Helpers\I18n::get('admin.db_import_write_failed');
        }

        $importName = $_FILES['backup_file']['name'] ?? 'unknown.sqlite';
        \app\Helpers\AuditLog::log('import', 'database', 0, '数据库导入: ' . $importName);
        return '';
    }

    /**
     * 下载备份文件（POST + CSRF 已由调用方校验；文件名取 $_POST）
     */
    private function handleDownload(string $backupDir): void
    {
        $filename = $_POST['file'] ?? '';
        if ($filename === '') {
            $this->redirect('/admin/database?error=' . urlencode(\app\Helpers\I18n::get('admin.db_download_none')));
            return;
        }
        if (basename($filename) !== $filename) {
            $this->redirect('/admin/database?error=' . urlencode(\app\Helpers\I18n::get('admin.db_illegal_filename')));
            return;
        }
        $filePath = $backupDir . $filename;
        $realFile = realpath($filePath);
        $realBase = realpath($backupDir);
        if ($realFile === false || $realBase === false || strpos($realFile, $realBase) !== 0) {
            $this->redirect('/admin/database?error=' . urlencode(\app\Helpers\I18n::get('admin.db_illegal_path')));
            return;
        }
        if (!is_file($realFile)) {
            $this->redirect('/admin/database?error=' . urlencode(\app\Helpers\I18n::get('admin.db_file_missing')));
            return;
        }
        // 清理输出缓冲区，防残留输出损坏文件
        if (ob_get_level()) ob_end_clean();

        \header('Content-Type: application/octet-stream');
        \header('Content-Disposition: attachment; filename="' . basename($realFile) . '"');
        \header('Content-Length: ' . filesize($realFile));
        \header('X-Content-Type-Options: nosniff');
        \readfile($realFile);
        exit;
    }

    /**
     * 删除备份文件
     */
    private function handleDelete(string $backupDir): void
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $filename = $_POST['file'] ?? '';
        if ($filename === '') return;
        if (basename($filename) !== $filename) return;
        $filePath = $backupDir . $filename;
        $realFile = realpath($filePath);
        $realBase = realpath($backupDir);
        if ($realFile === false || $realBase === false || strpos($realFile, $realBase) !== 0) return;
        if (is_file($realFile)) {
            @unlink($realFile);
            \app\Helpers\AuditLog::log('delete', 'backup', 0, '删除备份文件: ' . $filename);
        }
    }

    // ==================== 内部工具 ====================

    /**
     * 枚举 data/ 下全部 .sqlite 文件（meta 库 + 队列库 + 分片桶 + sessions）
     *
     * 由 RecursiveDirectoryIterator 全目录递归改为显式枚举已知库路径（meta/ 队列库/ 分片桶），
     * 跳过 extern 目录（含数十万 .txt 小文件，全遍历会卡死备份页），毫秒级返回。
     */
    private function sqliteFiles(): array
    {
        $root = rtrim(ShardRouter::dataPath(), '/\\');
        $files = [];

        // ① meta 库（business / main_index / global_id / sessions 等）
        foreach (glob($root . '/meta/*.sqlite') ?: [] as $f) {
            if (is_file($f)) $files[] = $f;
        }
        // ①b 搜索库季度分文件（meta/search/search_{YYYYQn}.sqlite）
        foreach (glob($root . '/meta/search/*.sqlite') ?: [] as $f) {
            if (is_file($f)) $files[] = $f;
        }
        // ② 队列库
        foreach (glob($root . '/meta/task_queue/*.sqlite') ?: [] as $f) {
            if (is_file($f)) $files[] = $f;
        }
        // ③ 分片桶（active + archive，按 {季度}/{桶}.sqlite 两层）
        foreach (glob($root . '/bucket/active/*/*.sqlite') ?: [] as $f) {
            if (is_file($f)) $files[] = $f;
        }
        foreach (glob($root . '/bucket/archive/*/*.sqlite') ?: [] as $f) {
            if (is_file($f)) $files[] = $f;
        }

        sort($files);
        return $files;
    }

    /**
     * 备份文件名 → 目标库绝对路径（null 表示不符合规范）
     * forum_backup_{ts}__meta__business.sqlite → data/meta/business.sqlite
     */
    private function mapBackupToTarget(string $filename): ?string
    {
        $base = basename($filename);
        if (!str_starts_with($base, 'forum_backup_') || !str_ends_with($base, '.sqlite')) {
            return null;
        }
        $middle = substr($base, strlen('forum_backup_'), -strlen('.sqlite'));
        $parts = explode('__', $middle);
        array_shift($parts); // 去掉时间戳段
        if (empty($parts)) return null;
        $rel = implode('/', $parts);

        $root = rtrim(ShardRouter::dataPath(), '/\\');
        $target = $root . '/' . $rel;
        // 防穿越：目标必须在 data/ 内
        $realRoot = realpath($root);
        $realTarget = realpath(dirname($target));
        if ($realRoot === false || $realTarget === false || strpos($realTarget, $realRoot) !== 0) {
            return null;
        }
        return $target;
    }
}
