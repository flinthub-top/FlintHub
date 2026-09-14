<?php
/**
 * FlintHub 1.0 (SplitDB) — 全局 ID 生成器
 * 基于 global_id.sqlite 的 id_generator 表，高并发下原子自增取号。
 *
 * 兼容性说明：白皮书使用 UPDATE ... RETURNING（需 SQLite ≥ 3.35），
 * 本工程兼容基线 SQLite 3.33（不支持 RETURNING），
 * 故改用 BEGIN IMMEDIATE 写锁事务方案：取号期间持有写锁，
 * SELECT+UPDATE 原子完成，等价于 RETURNING。
 *
 * @file app/SplitDB/IDGenerator.php
 * @package app\SplitDB
 */

namespace app\SplitDB;

use PDO;
use PDOException;
use RuntimeException;

class IDGenerator
{
    /** 已移除 spl_object_id「busy_timeout 已设置」缓存——对象 id 可复用会误命中，改为每次直设 PRAGMA */

    /**
     * 初始化 id_generator 表并写入默认种子（幂等）
     */
    public static function initTable(PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS id_generator (
                table_name TEXT PRIMARY KEY,
                last_id INTEGER DEFAULT 0
            )"
        );
        $db->exec(
            "INSERT OR IGNORE INTO id_generator (table_name, last_id) VALUES ('topic', 0), ('reply', 0)"
        );
    }

    /**
     * 原子获取下一个 ID（带重试机制）
     *
     * @param string $table 业务表名（如 'topic' / 'reply'），首次出现自动建种子
     * @param PDO    $db    global_id.sqlite 连接
     * @param int    $maxRetries 写锁竞争重试次数
     * @throws RuntimeException 超过重试次数后抛出
     */
    public static function nextId(string $table, PDO $db, int $maxRetries = 3): int
    {
        // global_id 连接单独下调写锁等待（DBFactory 统一 PRAGMA 为 5000ms，
        // 若 5000ms × 3 次重试 → 高写争用单请求最坏约 15s 阻塞）。
        // 取号事务为微秒级 INSERT OR IGNORE + SELECT + UPDATE，1000ms 已足够；仍竞争则由
        // 下方重试循环兜底并记告警。
        // 放弃 spl_object_id 缓存「已设置」标记——连接池淘汰重建后对象 id 可能被复用，
        // 缓存会失效/误命中；PRAGMA busy_timeout 设置本身是廉价 exec，每次直设更稳妥。
        try {
            $db->exec('PRAGMA busy_timeout = 1000');
        } catch (\Throwable $e) {
            \error_log('[IDGenerator] 设置 busy_timeout 失败（忽略）: ' . $e->getMessage());
        }

        $attempts = 0;
        while (true) {
            try {
                // 事务控制必须使用原始 SQL（exec），不可混用 PDO::commit()/rollBack()：
                // PDO-SQLite 对 exec('BEGIN') 不置内部事务标志，混用会抛 "no active transaction"
                $db->exec('BEGIN IMMEDIATE');
            } catch (PDOException $e) {
                // 区分错误类型——仅「写锁竞争/busy」才重试；
                // 其余（残留事务、语法等）直接抛出，避免误判为锁竞争导致误诊日志与无谓重试
                if (!self::isLockError($e)) {
                    self::rollbackQuiet($db);
                    throw new RuntimeException(
                        "Failed to begin ID transaction for '{$table}': " . $e->getMessage(),
                        0,
                        $e
                    );
                }
                // 写锁竞争或残留事务 → 尝试清理后随机退避重试
                self::rollbackQuiet($db);
                $attempts++;
                if ($attempts >= $maxRetries) {
                    // 取号超时埋点告警（写锁竞争持续阻塞）
                    \error_log("[IDGenerator] 取号超时告警 table={$table} attempts={$attempts}（写锁竞争，请检查 global_id.sqlite 写并发）");
                    throw new RuntimeException(
                        "Failed to generate ID for '{$table}' after {$maxRetries} attempts: " . $e->getMessage(),
                        0,
                        $e
                    );
                }
                usleep(rand(5000, 20000));
                continue;
            }

            try {
                $newId = self::incrementLocked($table, $db);
                $db->exec('COMMIT');
                return $newId;
            } catch (PDOException $e) {
                // 仅写锁竞争（database is locked）→ 回滚重试；其它 PDO 错误直接抛出
                if (!self::isLockError($e)) {
                    self::rollbackQuiet($db);
                    throw new RuntimeException(
                        "Failed to generate ID for '{$table}': " . $e->getMessage(),
                        0,
                        $e
                    );
                }
                // 事务内的锁竞争（database is locked）→ 回滚后重试
                self::rollbackQuiet($db);
                $attempts++;
                if ($attempts >= $maxRetries) {
                    // 取号超时埋点告警（写锁竞争持续阻塞）
                    \error_log("[IDGenerator] 取号超时告警 table={$table} attempts={$attempts}（写锁竞争，请检查 global_id.sqlite 写并发）");
                    throw new RuntimeException(
                        "Failed to generate ID for '{$table}' after {$maxRetries} attempts: " . $e->getMessage(),
                        0,
                        $e
                    );
                }
                usleep(rand(5000, 20000));
            } catch (\Throwable $e) {
                // 非锁竞争错误（如 SQL 语法）→ 不重试，直接抛出
                self::rollbackQuiet($db);
                throw new RuntimeException(
                    "Failed to generate ID for '{$table}': " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }
    }

    /**
     * 当前 ID（只读，不递增），用于诊断/迁移
     */
    public static function currentId(string $table, PDO $db): int
    {
        $stmt = $db->prepare('SELECT last_id FROM id_generator WHERE table_name = :table');
        $stmt->execute([':table' => $table]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    /**
     * 必须在 BEGIN IMMEDIATE 事务内调用：SELECT 旧值 → 递增 → 写回
     */
    private static function incrementLocked(string $table, PDO $db): int
    {
        // 首次出现的表名自动建种子行（幂等，兼容 SQLite 3.33）
        $ins = $db->prepare(
            'INSERT OR IGNORE INTO id_generator (table_name, last_id) VALUES (:table, 0)'
        );
        $ins->execute([':table' => $table]);

        $stmt = $db->prepare('SELECT last_id FROM id_generator WHERE table_name = :table');
        $stmt->execute([':table' => $table]);
        $lastId = (int)($stmt->fetchColumn() ?: 0);

        $newId = $lastId + 1;

        $upd = $db->prepare(
            'UPDATE id_generator SET last_id = :id WHERE table_name = :table'
        );
        $upd->execute([':id' => $newId, ':table' => $table]);

        return $newId;
    }

    /**
     * 静默回滚：清理可能残留的原始 SQL 事务（无活动事务时忽略）
     */
    private static function rollbackQuiet(PDO $db): void
    {
        try {
            $db->exec('ROLLBACK');
        } catch (\Throwable $ignored) {
            // 无活动事务或已提交，忽略
        }
    }

    /**
     * 判断 PDO 异常是否为写锁竞争（database is locked / busy_timeout 耗尽）。
     * 仅此类错误值得随机退避重试；其余（残留事务、语法错误等）直接抛出，
     * 避免把语法错误等误诊为「写锁竞争」并误导排查方向。
     */
    private static function isLockError(PDOException $e): bool
    {
        $msg = strtolower($e->getMessage());
        return strpos($msg, 'database is locked') !== false
            || strpos($msg, 'database table is locked') !== false
            || strpos($msg, 'is locked') !== false;
    }
}
