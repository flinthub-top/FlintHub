<?php
/**
 * FlintHub 1.0 (SplitDB) — 数据库连接池
 * 单进程 LRU 轻量连接池：最多 8 个常驻句柄，禁止 PDO 持久连接，
 * 所有 SQLite 连接统一强制白皮书 v2.3 底层参数。
 * @file app/SplitDB/DBFactory.php
 * @package app\SplitDB
 */

namespace app\SplitDB;

use PDO;
use RuntimeException;

class DBFactory
{
    /** 单进程最大常驻句柄数（白皮书 9.1：8 个） */
    private const MAX_HANDLES = 8;

    /** 连接池缓存：path => PDO */
    private static array $connections = [];

    /** 句柄最近使用时间：path => monotonic time，用于 LRU 淘汰 */
    private static array $lastUsed = [];

    /** 每个库在连接上执行的统一 PRAGMA */
    private const PRAGMAS = [
        'PRAGMA busy_timeout = 5000;',
        'PRAGMA journal_mode = WAL;',
        'PRAGMA synchronous = NORMAL;',
        'PRAGMA cache_size = -20000;',
        'PRAGMA temp_store = MEMORY;',
        // 外键约束保持 OFF 是有意取舍：分片架构下业务数据跨库分布，外键本就不成立；
        // business.sqlite 内的删除由业务代码显式手工清理（Thread::batchDelete / User::purge 等）
        'PRAGMA foreign_keys = OFF;',
    ];

    private function __construct() {}

    /**
     * 获取指定 SQLite 数据库文件的连接（LRU 池化，不持久化）
     *
     * @param string $path 数据库文件绝对路径
     */
    public static function getConnection(string $path): PDO
    {
        $key = self::normalizePath($path);

        if (isset(self::$connections[$key])) {
            self::$lastUsed[$key] = hrtime(true);
            return self::$connections[$key];
        }

        // 容量已满：按 LRU 淘汰最久未使用的句柄
        if (count(self::$connections) >= self::MAX_HANDLES) {
            self::evictLRU();
        }

        // 统一使用 CountingPDO：全库（business/main_index/分片桶/session）SQL 计数
        // 由 Database::recordSql() 汇总，页脚"总 SQL"即真实查询总数
        $pdo = new CountingPDO(
            'sqlite:' . $key,
            null,
            null,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT         => false, // ★ 红线：禁止持久连接
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );

        foreach (self::PRAGMAS as $pragma) {
            $pdo->exec($pragma);
        }

        // B3：校验 journal_mode 实际生效为 WAL——不支持共享内存的文件系统（网络盘等）上
        // SQLite 会静默回落 delete 模式，若不校验，WAL 并发/崩溃安全假设将整体崩塌且无任何告警。
        $actualMode = $pdo->query('PRAGMA journal_mode')->fetchColumn();
        if (strcasecmp((string)$actualMode, 'wal') !== 0) {
            \error_log('SplitDB: journal_mode 非 WAL（实际=' . var_export($actualMode, true) . '），并发安全假设不成立: ' . $key);
        }

        self::$connections[$key] = $pdo;
        self::$lastUsed[$key] = hrtime(true);

        return $pdo;
    }

    /**
     * 请求结束时清空连接池（PHP-FPM 每请求生命周期结束自动释放）
     */
    public static function closeAll(): void
    {
        // 注意：foreach 内对 $pdo 置 null 是无效代码（foreach 传值拷贝，不影响池内句柄），
        // 真正清理靠清空连接数组（句柄随引用计数归零自动释放）；此处不再保留死代码。
        self::$connections = [];
        self::$lastUsed = [];
    }

    /**
     * 当前池内句柄数量（诊断用）
     */
    public static function count(): int
    {
        return count(self::$connections);
    }

    /**
     * LRU 淘汰：移除最久未使用的句柄
     * 安全：淘汰前检查该句柄是否处于活动事务——处于事务中（未提交/回滚）的句柄不淘汰，
     * 避免静默丢弃未提交事务；若全部句柄都在事务中则放弃本次淘汰（调用方会新建临时句柄）。
     */
    private static function evictLRU(): void
    {
        if (empty(self::$connections)) return;

        $oldest = null;
        $oldestKey = null;
        foreach (self::$lastUsed as $key => $time) {
            if ($oldest === null || $time < $oldest) {
                $oldest = $time;
                $oldestKey = $key;
            }
        }
        if ($oldestKey === null) return;

        // 活动事务保护：最久未用句柄若在事务中，改挑一个非事务句柄淘汰
        // B1 注释（inTransaction() 局限）：PDO::inTransaction() 只对 beginTransaction() 起始的事务返回 true——
        // 本工程事务统一走 PDO beginTransaction()（Database::begin / 模型层），可被正确识别；
        // 但 IDGenerator 用 exec('BEGIN IMMEDIATE') 起始的原始 SQL 事务不置 PDO 内部标志，
        // inTransaction() 对其恒为 false（该连接极短生命周期、紧邻 COMMIT，实际无害）。
        // 故此处保护覆盖常规事务路径即可，exec 原始事务依赖 IDGenerator 自有的重试/回滚闭环。
        $candidate = self::$connections[$oldestKey] ?? null;
        if ($candidate !== null && $candidate->inTransaction()) {
            $fallbackKey = null;
            foreach (self::$connections as $key => $pdo) {
                if (!$pdo->inTransaction()) {
                    $fallbackKey = $key;
                    break;
                }
            }
            $oldestKey = $fallbackKey; // 全在事务中则为 null → 放弃淘汰
        }
        if ($oldestKey === null) return;

        unset(self::$connections[$oldestKey], self::$lastUsed[$oldestKey]);
    }

    /**
     * 归一化路径：解析相对路径并统一目录分隔符，避免同一文件双连接
     */
    private static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $real = realpath(dirname($path));
        if ($real === false) {
            throw new RuntimeException('SplitDB: 数据库目录不存在: ' . dirname($path));
        }
        return rtrim(str_replace('\\', '/', $real), '/') . '/' . basename($path);
    }
}
