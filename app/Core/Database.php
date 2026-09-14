<?php
/**
 * FlintHub 1.0 (SplitDB) — 数据库门面
 * SplitDB（纯 SQLite 分片引擎）统一门面：
 *   - 业务连接 → business.sqlite（Schema::businessDb，经 DBFactory 统一 PRAGMA）
 *   - Session 连接 → sessions.sqlite（独立库，与业务库物理隔离）
 *   - API：getInstance / getConnection / query / fetchAll / fetchOne /
 *     lastInsertId / begin / commit / rollback / getSessionConnection /
 *     getQueryCount / closeLastStatement —— Models 调用方零改动迁移
 *   - 数据目录缺失时自动 Schema::bootstrap()（幂等，空库可初始化）
 *
 * @file app/Core/Database.php
 * @package app\Core
 */

namespace app\Core;

use app\SplitDB\DBFactory;
use app\SplitDB\Schema;
use app\SplitDB\ShardRouter;

class Database
{
    private static $instance = null;
    private $db;
    private static int $queryCount = 0;
    /** @var int 全库总 SQL 计数（business + main_index + 分片桶 + session，含 PRAGMA 之外的真实查询） */
    private static int $totalSqlCount = 0;
    /** @var \PDOStatement|null 上一个查询的 PDOStatement，用于自动释放游标 */
    private $lastStmt = null;
    /** @var \PDO|null Session 专用独立连接（sessions.sqlite） */
    private static $sessionDb = null;

    private function __construct()
    {
        try {
            // 数据目录缺失时自动引导建库（幂等，支持全新安装直接运行）
            $root = rtrim(ShardRouter::dataPath(), '/\\');
            if (!is_dir($root . '/meta')) {
                Schema::bootstrap();
            }
            $this->db = Schema::businessDb();
            // 幂等补列：已存在的库不重跑 bootstrap，需在初始化时补齐新列（老库升级自动 ALTER）
            Schema::ensureColumnPatches($this->db);
        } catch (\Throwable $e) {
            \error_log('Database Connection Failed: ' . $e->getMessage());
            http_response_code(500);
            echo '<h2>500 Database Error</h2><p>系统服务暂时不可用，请稍后再试。</p>';
            exit;
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 获取业务库原始 PDO 连接（business.sqlite）
     */
    public function getConnection()
    {
        return $this->db;
    }

    /**
     * 获取 Session 专用独立 PDO 连接（sessions.sqlite）
     * 与业务连接物理隔离，避免互相阻塞
     */
    public static function getSessionConnection(): \PDO
    {
        if (self::$sessionDb === null) {
            self::$sessionDb = Schema::sessionDb();
        }
        return self::$sessionDb;
    }

    public function query($sql, $params = [])
    {
        self::$queryCount++;
        // 执行新查询前，自动关闭上一个查询的游标
        if ($this->lastStmt !== null) {
            $this->lastStmt->closeCursor();
            $this->lastStmt = null;
        }
        try {
            // ERRMODE_EXCEPTION 下 prepare() 失败必抛异常，不再需要 if (!$stmt) 死代码检查
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            // 追踪可能产生结果集的语句（SELECT/EXPLAIN）
            if (preg_match('/^\s*(SELECT|EXPLAIN)\s/i', $sql)) {
                $this->lastStmt = $stmt;
            }
            return $stmt;
        } catch (\PDOException $e) {
            // 底层异常不向调用方/浏览器泄露：真实错误与完整 SQL 只写 error.log，对外抛通用异常
            \error_log('[Database] ' . $e->getMessage() . ' | SQL: ' . $sql);
            throw new \RuntimeException(
                '数据库操作失败，请稍后重试',
                0,
                $e
            );
        }
    }

    public function fetchAll($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $data;
    }

    public function fetchOne($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        $stmt->closeCursor();
        return $row;
    }

    public function lastInsertId()
    {
        return $this->db->lastInsertId();
    }

    public function begin()
    {
        $this->db->beginTransaction();
    }

    public function commit()
    {
        $this->db->commit();
    }

    public function rollback()
    {
        $this->db->rollBack();
    }

    public static function getQueryCount(): int
    {
        return self::$queryCount;
    }

    /**
     * 记录一条全库 SQL（business/main_index/分片桶/session 统一入口，
     * 由 SplitDB\CountingPDO 在各连接上调用；仅统计真实数据语句，
     * 连接初始化 PRAGMA / 建表 DDL 不计入，保持口径与"查询数"可比）
     */
    public static function recordSql(string $sql): void
    {
        if (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE|EXPLAIN)\b/i', $sql)) {
            self::$totalSqlCount++;
        }
    }

    /**
     * 获取当前请求的全库总 SQL 数（business + main_index + 分片桶 + session）
     */
    public static function getTotalSqlCount(): int
    {
        return self::$totalSqlCount;
    }

    /**
     * 显式关闭连接上最后残留的游标
     * 专为 session_write_close 等终点站操作服务
     */
    public static function closeLastStatement(): void
    {
        $instance = self::$instance;
        if ($instance && $instance->lastStmt !== null) {
            $instance->lastStmt->closeCursor();
            $instance->lastStmt = null;
        }
    }

    /**
     * 请求结束时清空 SplitDB 连接池（PHP-FPM 每请求生命周期结束自动释放）
     */
    public static function closeAll(): void
    {
        DBFactory::closeAll();
    }
}
