<?php
/**
 * FlintHub 1.0 (SplitDB) — 搜索索引季度分文件存储
 *
 * search_index 从 business.sqlite 剥离，改按季度落独立库：
 *   data/meta/search/search_{YYYYQn}.sqlite   （如 search_2026Q3.sqlite）
 *
 * 路由规则：
 *   写入：按索引行 created_at 所在季度路由（thread 取 topic_index.create_time，
 *         blog 取 blogs.created_at；异常/空时间戳防御性落当前季度）
 *   读取：时间范围过滤 → 先算覆盖季度集合（滑动窗口跨季度），只查集合内文件，
 *         各文件取候选 → 归并排序 → 再 TOP N（见 Search::searchByIndex）
 *   删除：target 的 created_at 不可变，正常只命中一个季度文件；
 *         removeFromIndex 无时间信息，稳妥起见扫描全部季度文件
 *
 * 连接一律经 DBFactory 统一 PRAGMA（WAL / busy_timeout 等），表结构幂等自建。
 *
 * @file app/SplitDB/SearchIndexStore.php
 * @package app\SplitDB
 */

namespace app\SplitDB;

use PDO;

class SearchIndexStore
{
    /** 搜索库相对路径（相对 DATA_PATH） */
    public const DIR_REL = 'meta/search';

    /** 季度文件前缀 */
    public const FILE_PREFIX = 'search_';

    /**
     * 搜索库目录绝对路径
     */
    public static function dir(?string $dataPath = null): string
    {
        return (rtrim($dataPath ?: ShardRouter::dataPath(), '/\\')) . '/' . self::DIR_REL;
    }

    /**
     * 指定季度的搜索库文件绝对路径
     */
    public static function pathFor(string $quarter, ?string $dataPath = null): string
    {
        return self::dir($dataPath) . '/' . self::FILE_PREFIX . $quarter . '.sqlite';
    }

    /**
     * 确保搜索库目录存在
     */
    public static function ensureDir(?string $dataPath = null): void
    {
        $dir = self::dir($dataPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /**
     * 幂等建表（不含索引；bulk 加载专用，见 dbBulk）
     */
    public static function createTable(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS search_index (
                id INTEGER PRIMARY KEY,
                token TEXT NOT NULL,
                type INTEGER NOT NULL DEFAULT 1,
                target_id INTEGER NOT NULL,
                weight INTEGER NOT NULL DEFAULT 1,
                created_at TEXT
            )"
        );
    }

    /**
     * 幂等建表 + 索引（与旧 business.search_index 结构一致，含 created_at）
     */
    public static function ensureSchema(PDO $pdo): void
    {
        self::createTable($pdo);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_search_index_token ON search_index (token)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_search_index_target ON search_index (type, target_id)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uk_search_index ON search_index (token, type, target_id, weight)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_search_index_created ON search_index (created_at)');
    }

    /**
     * 获取某季度搜索库连接（幂等建表 + 统一 PRAGMA）
     */
    public static function db(string $quarter, ?string $dataPath = null): PDO
    {
        self::ensureDir($dataPath);
        $pdo = DBFactory::getConnection(self::pathFor($quarter, $dataPath));
        self::ensureSchema($pdo);
        return $pdo;
    }

    /**
     * 获取某季度搜索库「裸连接」（仅建目录 + 统一 PRAGMA，不建表不建索引）：
     * bulk 迁移/重建用——加载期自行临时摘掉非唯一索引提速，收尾 ensureSchema 补回
     */
    public static function dbBulk(string $quarter, ?string $dataPath = null): PDO
    {
        self::ensureDir($dataPath);
        return DBFactory::getConnection(self::pathFor($quarter, $dataPath));
    }

    /**
     * 加载期临时摘除非唯一索引（token / type,target_id / created_at），收尾 ensureSchema 补回
     */
    public static function dropFastIndexes(PDO $pdo): void
    {
        foreach (['idx_search_index_token', 'idx_search_index_target', 'idx_search_index_created'] as $idx) {
            $pdo->exec('DROP INDEX IF EXISTS ' . $idx);
        }
    }

    /**
     * created_at → 季度标识（异常/空时间戳防御性落当前季度）
     */
    public static function quarterOf(string $createdAt): string
    {
        $ts = strtotime((string)$createdAt);
        if ($ts === false || $ts <= 0) {
            $ts = time();
        }
        return ShardRouter::quarter($ts);
    }

    /**
     * 磁盘上已存在的全部季度文件（升序）
     */
    public static function allQuarters(?string $dataPath = null): array
    {
        $dir = self::dir($dataPath);
        if (!is_dir($dir)) {
            return [];
        }
        $quarters = [];
        foreach (glob($dir . '/' . self::FILE_PREFIX . '*.sqlite') ?: [] as $f) {
            if (preg_match('#/' . self::FILE_PREFIX . '(\d{4}Q[1-4])\.sqlite$#', $f, $m)) {
                $quarters[] = $m[1];
            }
        }
        sort($quarters);
        return $quarters;
    }

    /**
     * 时间范围下限 → 覆盖的季度集合（滑动窗口跨季度，如 8-14 的近一月跨 Q2/Q3）
     *
     * @param string $since created_at 下限（'Y-m-d H:i:s'）；空串 = 全部季度
     */
    public static function quartersForSince(string $since, ?string $dataPath = null): array
    {
        $all = self::allQuarters($dataPath);
        if ($since === '') {
            return $all;
        }
        $ts = strtotime($since);
        if ($ts === false || $ts <= 0) {
            return $all;
        }
        $now = time();
        $y = (int)date('Y', $ts);
        $q = (int)ceil((int)date('n', $ts) / 3);
        $ny = (int)date('Y', $now);
        $nq = (int)ceil((int)date('n', $now) / 3);
        $set = [];
        while ($y < $ny || ($y === $ny && $q <= $nq)) {
            $set[$y . 'Q' . $q] = true;
            $q++;
            if ($q > 4) { $q = 1; $y++; }
        }
        $result = [];
        foreach ($all as $quarter) {
            if (isset($set[$quarter])) {
                $result[] = $quarter;
            }
        }
        return $result;
    }

    /**
     * 整篇写入索引（删旧 + 批量 INSERT，单事务）：
     * 由 created_at 路由到唯一季度文件（target 时间戳不可变，旧行只可能在本文件）
     *
     * @param array $tokens Segmenter 分词结果：['token' => string, 'weight' => int][]
     */
    public static function writeIndex(int $type, int $targetId, string $createdAt, array $tokens): void
    {
        $quarter = self::quarterOf($createdAt);
        $pdo = self::db($quarter);

        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $pdo->prepare('DELETE FROM search_index WHERE type = :t AND target_id = :id')
                ->execute([':t' => $type, ':id' => $targetId]);

            if (!empty($tokens)) {
                // 分块 INSERT：单条语句占位符数受编译期 SQLITE_MAX_VARIABLE_NUMBER 限制
                // （默认编译上限 999；本机构建为 32766），整篇塞进一条多行 INSERT 在默认
                // 编译的宿主上长文（数千 token）会整体回滚。按固定块（150 词条/条 → 语句
                // 占位符 ≤ 750，天然低于默认 999）拆为多条 INSERT，共用同一事务，任一块
                // 失败则整体回滚，语义与原单条事务一致。
                $batchSize = 150;
                foreach (array_chunk($tokens, $batchSize) as $chunk) {
                    $values = [];
                    $params = [];
                    foreach ($chunk as $j => $tok) {
                        $values[] = "(:token{$j}, :type, :tid, :weight{$j}, :ct)";
                        $params[":token{$j}"] = (string)$tok['token'];
                        $params[":weight{$j}"] = (int)$tok['weight'];
                    }
                    $params[':type'] = $type;
                    $params[':tid'] = $targetId;
                    $params[':ct'] = (string)$createdAt;
                    $sql = 'INSERT OR IGNORE INTO search_index (token, type, target_id, weight, created_at) VALUES ' . implode(',', $values);
                    $pdo->prepare($sql)->execute($params);
                }
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /**
     * 从全部季度文件移除某 target 的索引行（无时间信息，稳妥扫描）
     */
    public static function deleteIndex(int $type, int $targetId): void
    {
        foreach (self::allQuarters() as $quarter) {
            self::db($quarter)->prepare('DELETE FROM search_index WHERE type = :t AND target_id = :id')
                ->execute([':t' => $type, ':id' => $targetId]);
        }
    }

    /**
     * 批量移除（批量删帖用）：全部季度文件按 900/批 IN 删除（ids 已 int 化，安全内联）
     */
    public static function deleteTargets(int $type, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) return;
        foreach (array_chunk($ids, 900) as $chunk) {
            $marks = implode(',', $chunk);
            foreach (self::allQuarters() as $quarter) {
                self::db($quarter)->exec("DELETE FROM search_index WHERE type = " . (int)$type . " AND target_id IN ({$marks})");
            }
        }
    }

    /**
     * 按 token 集合逐 token 查询候选并归并
     *
     * 背景：原 `WHERE token IN (...)` 查询被 SQLite 规划为走 idx_search_index_target
     *       (type,target_id) 全扫 + TEMP B-TREE 排序（实测 'php' 取数 2.4s）。
     *       改为逐 token 等值查询：
     *         WHERE token = :token AND type = :type [AND created_at >= :since]
     *         GROUP BY target_id ORDER BY score DESC LIMIT :take
     *       token 等值命中 idx_search_index_token 前缀索引，彻底杜绝 type 索引全表扫描。
     * 归并语义：同一 target 命中多个 token 时 score 累加（与旧 IN+SUM 语义一致）。
     *
     * @param PDO    $pdo    季度搜索库连接（单文件内查询）
     * @param int    $type   1=帖子 2=博客
     * @param array  $tokens token 列表（已分词）
     * @param int    $take   每个 token 取前 N 名候选（offset+放大余量，供跨文件/跨轮归并）
     * @param string $since  created_at 下限（'Y-m-d H:i:s'；空串=不限）
     * @return array<int,int> target_id => 累计 score
     */
    public static function searchByTokens(PDO $pdo, int $type, array $tokens, int $take, string $since = ''): array
    {
        if (empty($tokens) || $take < 1) {
            return [];
        }

        $cands = [];
        foreach ($tokens as $token) {
            $params = [':token' => $token, ':type' => $type];
            $sql = 'SELECT target_id, SUM(weight) AS score FROM search_index'
                 . ' WHERE token = :token AND type = :type';
            if ($since !== '') {
                $sql .= ' AND created_at >= :since';
                $params[':since'] = $since;
            }
            $sql .= ' GROUP BY target_id ORDER BY score DESC LIMIT ' . (int)$take;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt as $r) {
                $tid = (int)$r['target_id'];
                $score = (int)$r['score'];
                $cands[$tid] = isset($cands[$tid]) ? $cands[$tid] + $score : $score;
            }
        }

        // 严格候选上限：多 token 时各 token 各取 LIMIT take，累加后最坏 N×take 条；
        // 返回前按总分降序截断到 take，控制上层归并内存。
        // 调用方 Search::searchByIndex 自带 take 放大（offset+limit×3）与补位重查，截断不影响其逻辑。
        arsort($cands);
        return array_slice($cands, 0, $take, true);
    }

    /**
     * 按 token 集合逐 token 统计去重 target 数
     * 与 searchByTokens 同构，走 idx_search_index_token 前缀索引；
     * 跨 token 命中同一 target 只计一次（与旧 COUNT(DISTINCT target_id) 语义一致）。
     *
     * @return int 去重后的 target 数
     */
    public static function countTargets(PDO $pdo, int $type, array $tokens, string $since = ''): int
    {
        if (empty($tokens)) {
            return 0;
        }

        // 高频词 OOM 修复：原实现逐行 fetchColumn 把全部 target_id 拉进 PHP 数组去重，
        // 高频词（如"的/是"）可命中数十万行 → 内存溢出。改为 SQL 层聚合：
        // 每个 token 一段独立子查询（各自走 idx_search_index_token 前缀索引，
        // 避免 WHERE token IN (...) 让规划器走错索引），UNION 后外层 COUNT(DISTINCT target_id)
        // 去重，SQLite 内只回传一个标量，PHP 侧零数组。
        $unionParts = [];
        $params = [];
        foreach ($tokens as $i => $token) {
            $unionParts[] = 'SELECT target_id FROM search_index'
                . ' WHERE token = :tok' . $i . ' AND type = :typ' . $i
                . ($since !== '' ? ' AND created_at >= :snc' . $i : '');
            $params[':tok' . $i] = $token;
            $params[':typ' . $i] = $type;
            if ($since !== '') {
                $params[':snc' . $i] = $since;
            }
        }
        $sql = 'SELECT COUNT(DISTINCT target_id) FROM (' . implode(' UNION ', $unionParts) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * 清空全部季度文件（重建索引前调用）
     */
    public static function clearAll(): void
    {
        foreach (self::allQuarters() as $quarter) {
            self::db($quarter)->exec('DELETE FROM search_index');
        }
    }

    /**
     * 全季度合计行数
     */
    public static function countAll(): int
    {
        $total = 0;
        foreach (self::allQuarters() as $quarter) {
            $total += (int)self::db($quarter)->query('SELECT COUNT(*) FROM search_index')->fetchColumn();
        }
        return $total;
    }

    /**
     * 按类型合计行数（type=1 帖子 / type=2 博客）
     */
    public static function countByType(int $type): int
    {
        $total = 0;
        foreach (self::allQuarters() as $quarter) {
            $total += (int)self::db($quarter)->query('SELECT COUNT(*) FROM search_index WHERE type = ' . (int)$type)->fetchColumn();
        }
        return $total;
    }

    /**
     * 某类型最大 target_id（seed phaseSearch 断点续跑起点）
     */
    public static function maxTargetId(int $type): int
    {
        $max = 0;
        foreach (self::allQuarters() as $quarter) {
            $v = (int)self::db($quarter)->query('SELECT COALESCE(MAX(target_id),0) FROM search_index WHERE type = ' . (int)$type)->fetchColumn();
            if ($v > $max) {
                $max = $v;
            }
        }
        return $max;
    }

    /**
     * 清理正文权重索引行（后台「清理正文索引」，weight=1 为正文词条）
     */
    public static function cleanWeightOne(): int
    {
        $count = 0;
        foreach (self::allQuarters() as $quarter) {
            $stmt = self::db($quarter)->query('DELETE FROM search_index WHERE weight = 1');
            $count += $stmt->rowCount();
        }
        return $count;
    }
}
