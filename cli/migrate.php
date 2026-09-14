<?php
/**
 * FlintHub 1.0 (SplitDB) — 旧 MySQL 数据迁移脚本（CLI）
 *
 * ⚠️ 一次性历史工具：旧 MySQL（fastbb）数据已迁移完成，本脚本仅作历史参考留存，
 *    供重装/复盘时查阅，日常运行不需要执行。
 *
 * 用法：
 *   php cli/migrate.php                     # 全量迁移（默认连接旧 MySQL fastbb 库）
 *   php cli/migrate.php --dry-run           # 仅预览各表行数，不实际迁移
 *   php cli/migrate.php --host=127.0.0.1 --port=3306 --name=fastbb --user=fastbb --pass=123456
 *
 * 迁移规则（SplitDB v2.3）：
 *   - 保留原始 ID（外键关系完整），迁移完成后把 global_id 游标推进到 max(id)
 *   - 帖子/回复正文剥离 SQLite → extern/ .txt（原子写入，失败必须报错回滚，杜绝「索引成功但正文丢失」）
 *   - 帖子/回复按 ID%BUCKET_SIZE 哈希分桶写入 bucket/，同时写 main_index 索引行
 *   - 基础业务表（用户/版块/设置/博客/私信/标签等）迁入 business.sqlite
 *
 * 红线（强制）：正文剥离 extern 失败 → 立即抛出、清理本批已写 extern、终止迁移并提示清空 data/ 重跑。
 *
 * @package app\cli
 */

// ============================================================
// 0. 旧 MySQL 配置（可按 CLI 参数覆盖）
// ============================================================
$MIGRATE = [
    'host' => getenv('MIGRATE_DB_HOST') ?: 'localhost',
    'port' => (int)(getenv('MIGRATE_DB_PORT') ?: 3306),
    'name' => getenv('MIGRATE_DB_NAME') ?: 'fastbb',
    'user' => getenv('MIGRATE_DB_USER') ?: 'fastbb',
    'pass' => getenv('MIGRATE_DB_PASS') ?: '123456',
    'charset' => 'utf8mb4',
    'dryRun' => false,
];

// ============================================================
// 1. 参数解析
// ============================================================
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $MIGRATE['dryRun'] = true;
    } elseif (preg_match('/^--host=(.+)$/', $arg, $m)) {
        $MIGRATE['host'] = $m[1];
    } elseif (preg_match('/^--port=(\d+)$/', $arg, $m)) {
        $MIGRATE['port'] = (int)$m[1];
    } elseif (preg_match('/^--name=(.+)$/', $arg, $m)) {
        $MIGRATE['name'] = $m[1];
    } elseif (preg_match('/^--user=(.+)$/', $arg, $m)) {
        $MIGRATE['user'] = $m[1];
    } elseif (preg_match('/^--pass=(.+)$/', $arg, $m)) {
        $MIGRATE['pass'] = $m[1];
    } else {
        fwrite(STDERR, "未知参数: {$arg}\n");
        fwrite(STDERR, "用法: php cli/migrate.php [--dry-run] [--host=..] [--port=..] [--name=..] [--user=..] [--pass=..]\n");
        exit(1);
    }
}

require_once __DIR__ . '/_guard.php'; // Web 直访守卫（CLI-only）
require_once __DIR__ . '/../app/Core/Autoloader.php';

// SplitDB 引擎类（供迁移逻辑使用）
use app\SplitDB\ExternStorage;
use app\SplitDB\Schema;
use app\SplitDB\ShardRouter;

// ============================================================
// 2. 迁移源抽象（B5 冒烟可注入 fixture 源）
// ============================================================

interface MigrateSourceInterface
{
    /** 源库全部表名 */
    public function tableNames(): array;

    /** 逐行读取某表（assoc 数组迭代器） */
    public function rows(string $table): iterable;

    /** 表行数 */
    public function count(string $table): int;

    public function close(): void;
}

/**
 * 真实 MySQL 源：PDO mysql + 非缓冲查询（大表防 OOM）
 */
class MySqlMigrateSource implements MigrateSourceInterface
{
    private ?\PDO $pdo = null;

    public function __construct(array $cfg)
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']);
        $this->pdo = new \PDO($dsn, $cfg['user'], $cfg['pass'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_TIMEOUT => 10,
        ]);
    }

    public function tableNames(): array
    {
        return $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function rows(string $table): iterable
    {
        // 非缓冲查询：逐行 yield，大表不占内存
        $stmt = $this->pdo->query('SELECT * FROM `' . $table . '`', \PDO::FETCH_ASSOC);
        while ($row = $stmt->fetch()) {
            yield $row;
        }
        $stmt->closeCursor();
    }

    public function count(string $table): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    public function close(): void
    {
        $this->pdo = null;
    }
}

/**
 * fixture 源：数组注入（B5 冒烟用，不连真实 MySQL）
 */
class FixtureMigrateSource implements MigrateSourceInterface
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function tableNames(): array
    {
        return array_keys($this->data);
    }

    public function rows(string $table): iterable
    {
        foreach ($this->data[$table] ?? [] as $row) {
            yield $row;
        }
    }

    public function count(string $table): int
    {
        return count($this->data[$table] ?? []);
    }

    public function close(): void {}
}

// ============================================================
// 3. 连接探测
// ============================================================

/**
 * 建立迁移源（真实 MySQL / fixture 注入），失败抛异常
 */
function createMigrateSource(array $cfg, ?MigrateSourceInterface $injected = null): MigrateSourceInterface
{
    if ($injected !== null) {
        return $injected;
    }
    return new MySqlMigrateSource($cfg);
}

/**
 * dry-run：预览各表行数
 */
function previewMigrate(MigrateSourceInterface $source): void
{
    $tables = $source->tableNames();
    echo "源库表清单（共 " . count($tables) . " 张）：\n";
    foreach ($tables as $t) {
        printf("  %-24s %8d 行\n", $t, $source->count($t));
    }
}

// ============================================================
// 4. 迁移主流程（B2→B4 逐步实现）
// ============================================================

/**
 * 获取目标 SQLite 表列名（PRAGMA table_info；表名来自白名单，安全）
 */
function getTargetColumns(\PDO $target, string $table): ?array
{
    $stmt = $target->query("PRAGMA table_info({$table})");
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    return $rows ? array_column($rows, 'name') : null;
}

/**
 * B2：基础业务表迁移（显式 ID 保外键）
 * 顺序 = 依赖序；仅迁移目标表存在的列（容忍旧库扩展列如 experience/is_admin）；
 * settings 跳过 _runtime_* 运行时计数键（迁移后由 worker 重建）。
 */
function migrateBusinessTables(MigrateSourceInterface $source, \PDO $target): array
{
    $tables = [
        'users', 'categories', 'user_groups', 'category_permissions',
        'settings', 'blog_categories', 'blogs', 'blog_comments',
        'messages', 'tags', 'thread_tags', 'level_config',
        'points_log', 'viewed_replies', 'online_users', 'audit_logs', 'password_resets',
    ];
    $stats = [];

    foreach ($tables as $table) {
        $cols = getTargetColumns($target, $table);
        if ($cols === null) {
            echo "  [skip] {$table}（目标表不存在）\n";
            continue;
        }

        $count = 0;
        $stmt = null;
        foreach ($source->rows($table) as $row) {
            // settings：跳过运行时计数键（迁移后异步重建）
            if ($table === 'settings' && isset($row['key']) && strpos((string)$row['key'], '_runtime_') === 0) {
                continue;
            }
            // 仅取目标表存在的列（适配旧库多余列）
            $data = [];
            foreach ($cols as $col) {
                if (array_key_exists($col, $row)) {
                    $data[$col] = $row[$col];
                }
            }
            if (empty($data)) continue;

            // 按首行的列结构构建 INSERT（同源表各行列一致）
            if ($stmt === null) {
                $colList = '"' . implode('", "', array_keys($data)) . '"';
                $ph = ':' . implode(', :', array_keys($data));
                $stmt = $target->prepare("INSERT OR REPLACE INTO {$table} ({$colList}) VALUES ({$ph})");
            }
            $params = [];
            foreach ($data as $k => $v) {
                $params[':' . $k] = $v;
            }
            $stmt->execute($params);
            $count++;
        }
        $stats[$table] = $count;
        echo "  {$table}: {$count} 行\n";
    }
    return $stats;
}

/**
 * B3：帖子/回复分片迁移（extern 先行 + 桶 + 索引，红线：extern 失败报错回滚）
 *
 * 流程（每条帖子）：
 *   ① ExternStorage::write 原子写正文 extern/（必须先成功）
 *   ② 写桶 topic 行（真相源，含 extern_path）
 *   ③ 写 main_index.topic_index（bucket_path）
 * 回复（posts）：extern → 父帖所在桶 reply → reply_index。
 * ID 保留原始值（桶路由 id % BUCKET_SIZE 随之确定），结束后推进 global_id 游标。
 *
 * 红线：任一 extern 写入失败 → 抛出、清理本批已写 extern、终止并提示清空 data/ 重跑，
 * 绝不出现「索引成功但正文丢失」（索引行只在外 extern 成功之后写入）。
 */
function migrateShardedContent(MigrateSourceInterface $source, \PDO $mi, \PDO $gid, string $dataPath): void
{
    $bucketSize = ShardRouter::bucketSize();
    $threadBucket = [];   // thread_id => bucket_path（回复定位父帖桶）
    $writtenExterns = []; // 本批已写 extern 相对路径（回滚用）
    $maxThreadId = 0;
    $maxReplyId = 0;

    $ts = function ($v) { return ($v !== null && $v !== '') ? strtotime((string)$v) : null; };

    try {
        // ---------- 1. 帖子 ----------
        foreach ($source->rows('threads') as $t) {
            $tid = (int)($t['id'] ?? 0);
            if ($tid <= 0) continue;
            $maxThreadId = max($maxThreadId, $tid);

            $content  = (string)($t['content'] ?? '');
            $bucket   = $tid % $bucketSize;
            $createTs = $ts($t['created_at'] ?? null) ?: time();
            $quarter  = ShardRouter::quarter($createTs);
            $bucketPath = ShardRouter::bucketRel($quarter, $bucket);

            // ① extern 先行（原子写入，失败抛异常——此时绝不写索引）
            $externRel = ExternStorage::write($dataPath, $tid, $bucket, 'topic', $content, $createTs);
            $writtenExterns[] = $externRel;

            // ② 桶 topic 行（真相源）
            $bdb = Schema::bucketDb($quarter, $bucket, $dataPath);
            $bdb->prepare(
                'INSERT OR REPLACE INTO topic (id, uid, category_id, title, create_time, update_time,
                                               last_reply_time, view_count, reply_count, status,
                                               is_pinned, is_highlighted, color, reply_to_view, deleted_at, extern_path)
                 VALUES (:id, :uid, :cid, :title, :ct, :ut, :lrt, :vc, :rc, :st, :pin, :hl, :color, :rtv, :del, :extern)'
            )->execute([
                ':id' => $tid,
                ':uid' => (int)($t['user_id'] ?? 0),
                ':cid' => (int)($t['category_id'] ?? 0),
                ':title' => (string)($t['title'] ?? ''),
                ':ct' => $createTs,
                ':ut' => $createTs,
                ':lrt' => $ts($t['last_reply_at'] ?? null) ?: $createTs,
                ':vc' => (int)($t['view_count'] ?? 0),
                ':rc' => (int)($t['reply_count'] ?? 0),
                ':st' => (($t['deleted_at'] ?? null) !== null && $t['deleted_at'] !== '') ? 1 : 0,
                ':pin' => (int)($t['is_pinned'] ?? 0),
                ':hl' => (int)($t['is_highlighted'] ?? 0),
                ':color' => (string)($t['color'] ?? ''),
                ':rtv' => (int)($t['reply_to_view'] ?? 0),
                ':del' => $ts($t['deleted_at'] ?? null),
                ':extern' => $externRel,
            ]);

            // ③ main_index.topic_index
            $mi->prepare(
                'INSERT OR REPLACE INTO topic_index (id, uid, title, create_time, last_reply_time, view_count,
                                                     reply_count, status, category_id, is_pinned, is_highlighted,
                                                     color, reply_to_view, deleted_at, bucket_path)
                 VALUES (:id, :uid, :title, :ct, :lrt, :vc, :rc, :st, :cid, :pin, :hl, :color, :rtv, :del, :bpath)'
            )->execute([
                ':id' => $tid, ':uid' => (int)($t['user_id'] ?? 0), ':title' => (string)($t['title'] ?? ''),
                ':ct' => $createTs, ':lrt' => $ts($t['last_reply_at'] ?? null) ?: $createTs,
                ':vc' => (int)($t['view_count'] ?? 0), ':rc' => (int)($t['reply_count'] ?? 0),
                ':st' => (($t['deleted_at'] ?? null) !== null && $t['deleted_at'] !== '') ? 1 : 0,
                ':cid' => (int)($t['category_id'] ?? 0), ':pin' => (int)($t['is_pinned'] ?? 0),
                ':hl' => (int)($t['is_highlighted'] ?? 0), ':color' => (string)($t['color'] ?? ''),
                ':rtv' => (int)($t['reply_to_view'] ?? 0), ':del' => $ts($t['deleted_at'] ?? null),
                ':bpath' => $bucketPath,
            ]);

            $threadBucket[$tid] = $bucketPath;
        }
        echo "  帖子分片迁移完成：{$maxThreadId} 条（max id）\n";

        // ---------- 2. 回复（posts）----------
        foreach ($source->rows('posts') as $p) {
            $pid = (int)($p['id'] ?? 0);
            $tid = (int)($p['thread_id'] ?? 0);
            if ($pid <= 0 || $tid <= 0) continue;
            $maxReplyId = max($maxReplyId, $pid);

            $bucketPath = $threadBucket[$tid] ?? null;
            if ($bucketPath === null) {
                echo "  [warn] 回复 {$pid} 的父帖 {$tid} 不存在，跳过\n";
                continue;
            }
            $bucket = ShardRouter::bucketFromPath($bucketPath);
            $content = (string)($p['content'] ?? '');
            $createTs = $ts($p['created_at'] ?? null) ?: time();

            // ① extern 先行（父帖桶号定位 extern 目录）
            $externRel = ExternStorage::write($dataPath, $pid, $bucket, 'reply', $content, $createTs);
            $writtenExterns[] = $externRel;

            // ② 父帖所在桶 reply 行
            $bdb = Schema::bucketFromPath($bucketPath, $dataPath);
            $bdb->prepare(
                'INSERT OR REPLACE INTO reply (id, pid, uid, create_time, update_time, status, deleted_at, extern_path)
                 VALUES (:id, :pid, :uid, :ct, :ut, :st, :del, :extern)'
            )->execute([
                ':id' => $pid, ':pid' => $tid, ':uid' => (int)($p['user_id'] ?? 0),
                ':ct' => $createTs, ':ut' => $createTs,
                ':st' => (($p['deleted_at'] ?? null) !== null && $p['deleted_at'] !== '') ? 1 : 0,
                ':del' => $ts($p['deleted_at'] ?? null),
                ':extern' => $externRel,
            ]);

            // ③ reply_index
            $mi->prepare(
                'INSERT OR REPLACE INTO reply_index (id, pid, uid, create_time, update_time, status, bucket_path)
                 VALUES (:id, :pid, :uid, :ct, :ut, :st, :bpath)'
            )->execute([
                ':id' => $pid, ':pid' => $tid, ':uid' => (int)($p['user_id'] ?? 0),
                ':ct' => $createTs, ':ut' => $createTs,
                ':st' => (($p['deleted_at'] ?? null) !== null && $p['deleted_at'] !== '') ? 1 : 0,
                ':bpath' => $bucketPath,
            ]);
        }
        echo "  回复分片迁移完成：{$maxReplyId} 条（max id）\n";

        // ---------- 3. global_id 游标推进（保留原始 ID 后无缝衔接新 ID） ----------
        advanceGlobalId($gid, 'topic', $maxThreadId);
        advanceGlobalId($gid, 'reply', $maxReplyId);
        echo "  global_id 游标已推进：topic={$maxThreadId} reply={$maxReplyId}\n";
    } catch (\Throwable $e) {
        // 红线回滚：清理本批已写 extern 文件
        foreach ($writtenExterns as $rel) {
            $abs = rtrim($dataPath, '/\\') . '/' . $rel;
            if (is_file($abs)) { @unlink($abs); }
        }
        throw new \RuntimeException(
            '迁移中止：' . $e->getMessage()
            . '（已回滚本批 extern 文件，请清空 data/ 目录后重跑，避免部分数据残留）',
            0,
            $e
        );
    }
}

/**
 * 推进 global_id 游标（只增不减）
 */
function advanceGlobalId(\PDO $gid, string $table, int $maxId): void
{
    if ($maxId <= 0) return;
    $gid->prepare('INSERT OR IGNORE INTO id_generator (table_name, last_id) VALUES (:t, 0)')->execute([':t' => $table]);
    $gid->prepare('UPDATE id_generator SET last_id = :max WHERE table_name = :t AND last_id < :max')
        ->execute([':t' => $table, ':max' => $maxId]);
}

/**
 * B4：附件与投票迁移（显式 ID 保外键）
 *   attachments      → business.attachments（显式 ID）
 *   thread_votes     → business.thread_votes（唯一索引 thread_id+user_id）
 *   post_votes       → business.post_votes（唯一索引 post_id+user_id）
 * 附件物理文件：旧站 assets/uploads/ 下的文件已在站点内保留，无需复制；
 * 若旧文件缺失，仅 DB 记录迁移（文件名记录仍完整，便于追溯）。
 */
function migrateAttachmentsAndVotes(MigrateSourceInterface $source, \PDO $target): array
{
    $tables = ['attachments', 'thread_votes', 'post_votes'];
    $stats = [];

    foreach ($tables as $table) {
        $cols = getTargetColumns($target, $table);
        if ($cols === null) {
            echo "  [skip] {$table}（目标表不存在）\n";
            continue;
        }

        $count = 0;
        $stmt = null;
        foreach ($source->rows($table) as $row) {
            // 仅取目标表存在的列
            $data = [];
            foreach ($cols as $col) {
                if (array_key_exists($col, $row)) {
                    $data[$col] = $row[$col];
                }
            }
            if (empty($data)) continue;

            if ($stmt === null) {
                $colList = '"' . implode('", "', array_keys($data)) . '"';
                $ph = ':' . implode(', :', array_keys($data));
                $stmt = $target->prepare("INSERT OR REPLACE INTO {$table} ({$colList}) VALUES ({$ph})");
            }
            $params = [];
            foreach ($data as $k => $v) {
                $params[':' . $k] = $v;
            }
            $stmt->execute($params);
            $count++;
        }
        $stats[$table] = $count;
        echo "  {$table}: {$count} 行\n";
    }
    return $stats;
}

function migrateAll(MigrateSourceInterface $source, array $cfg): void
{
    // 0. 初始化 SplitDB 骨架（幂等）
    echo "步骤 1/4：初始化 SplitDB 骨架\n";
    \app\SplitDB\Schema::bootstrap();
    $target = \app\SplitDB\Schema::businessDb();
    $mi = \app\SplitDB\Schema::mainIndexDb();
    $gid = \app\SplitDB\Schema::globalIdDb();
    $dataPath = \app\SplitDB\ShardRouter::dataPath();

    // B2：基础业务表迁移
    echo "步骤 2/4：迁移基础业务表（显式 ID 保外键）\n";
    $stats = migrateBusinessTables($source, $target);
    echo "  基础表迁移完成，共 " . array_sum($stats) . " 行。\n";

    // B3：帖子/回复分片迁移（extern 先行 + 桶 + 索引）红线
    echo "步骤 3/4：迁移帖子/回复分片\n";
    migrateShardedContent($source, $mi, $gid, $dataPath);
    echo "  分片迁移完成。\n";

    // B4：附件/投票迁移 + 重建搜索索引（收口）
    echo "步骤 4/4：迁移附件与投票 + 重建搜索索引\n";
    $rest = migrateAttachmentsAndVotes($source, $target);
    echo "  附件/投票迁移完成：" . array_sum($rest) . " 行。\n";

    // 重建倒排索引（从分片真相源全量重建，与 cli/rebuild_search.php 一致）
    echo "  重建搜索索引…\n";
    require_once __DIR__ . '/rebuild_search.php';
    $rebuild = runRebuildSearch(true, true);
    echo "  索引重建完成：{$rebuild['total']} 条（帖子 {$rebuild['thread']} / 博客 {$rebuild['blog']}）。\n";

    echo "\n全部迁移完成。请启动 worker 刷新统计：php cli/worker.php 0 --once（或 supervisor 常驻）。\n";
}

// ============================================================
// 5. 执行入口（仅直接运行时执行；被 require 时跳过，供 B5 冒烟注入 fixture 源）
// ============================================================

$isDirectRun = (PHP_SAPI === 'cli')
    && (isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === __FILE__);

if ($isDirectRun) {
    echo "SplitDB 迁移工具\n";
    echo "源 MySQL: {$MIGRATE['host']}:{$MIGRATE['port']}/{$MIGRATE['name']}\n";

    try {
        $source = createMigrateSource($MIGRATE);
        echo "连接成功。\n";

        if ($MIGRATE['dryRun']) {
            previewMigrate($source);
            echo "\n[dry-run] 预览完成，未执行迁移。\n";
            $source->close();
            exit(0);
        }

        migrateAll($source, $MIGRATE);
        $source->close();
        echo "迁移完成。\n";
        exit(0);
    } catch (\Throwable $e) {
        fwrite(STDERR, "迁移失败: " . $e->getMessage() . "\n");
        fwrite(STDERR, "提示: 若正文 extern 写入失败导致中断，请清空 data/ 目录后重跑（避免部分数据残留）。\n");
        exit(1);
    }
}
