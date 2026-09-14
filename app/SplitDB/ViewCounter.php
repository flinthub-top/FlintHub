<?php
/**
 * FlintHub 1.0 (SplitDB) — 浏览量防刷计数器
 *   - 有 APCu：内存计数（apcu_inc），flush 时 APCUIterator 批量落库 main_index.view_count
 *   - 无 APCu：静默降级为直接 UPDATE main_index（降低性能但不崩站，任何 PHP 环境可运行）
 *   - 无 APCu 时绝对不抛错、不阻塞业务
 *
 * @file app/SplitDB/ViewCounter.php
 * @package app\SplitDB
 */

namespace app\SplitDB;

use PDO;

class ViewCounter
{
    /** APCu 键前缀 */
    private const KEY_PREFIX = 'topic_views_';

    /** APCu 计数键 TTL（秒）：新键创建即带过期，防键随历史访问主题数无限增长 */
    private const KEY_TTL = 3600;

    /**
     * 浏览量 +1（防刷入口）
     *
     * @param int      $topicId 主题 ID
     * @param PDO|null $mainDb  main_index 连接（降级直写用；null 时自动取）
     */
    public static function inc(int $topicId, PDO $mainDb = null): void
    {
        // 有 APCu → 内存计数，flush 时批量落库（inc 时传 TTL：新键创建即设过期时间，
        // 避免只增不减的键永驻 APCu 共享内存；已存在的键继续累加）
        if (function_exists('apcu_inc')) {
            $success = null;
            @apcu_inc(self::KEY_PREFIX . $topicId, 1, $success, self::KEY_TTL);
            return;
        }

        // 无 APCu → 静默降级：直接 UPDATE 实时计数（性能降低，不崩站）
        try {
            $db = $mainDb ?: Schema::mainIndexDb();
            $db->prepare('UPDATE topic_index SET view_count = view_count + 1 WHERE id = :id')
               ->execute([':id' => $topicId]);
        } catch (\Throwable $e) {
            // 极端情况下静默失败，绝不影响页面
            \error_log('ViewCounter::inc (fallback) error: ' . $e->getMessage());
        }
    }

    /**
     * 批量落库（APCu 模式下由 cli/flush 或 shutdown 调用）
     *
     * @param PDO|null $mainDb main_index 连接
     * @return int 落库的主题数
     */
    public static function flush(PDO $mainDb = null): int
    {
        if (!function_exists('apcu_fetch') || !class_exists('APCUIterator')) {
            return 0; // 无 APCu 环境无需 flush
        }

        // flush 30s 节流：仅延迟落库、绝不丢计数——计数在 COMMIT 落库成功后才从 APCu 扣除，
        // 避免高并发下每个请求 shutdown 都执行 BEGIN IMMEDIATE + 全量遍历 APCu 键
        // 造成 main_index 写锁竞争与无谓开销。
        $throttleFile = rtrim(ShardRouter::dataPath(), '/\\') . '/runtime/viewcounter_flush.ts';
        $now = time();
        if (is_file($throttleFile)) {
            $last = (int)@file_get_contents($throttleFile);
            if ($last > 0 && ($now - $last) < 30) {
                return 0; // 30s 窗口内跳过本次 flush
            }
        }

        $db = $mainDb ?: Schema::mainIndexDb();
        $flushed = 0;
        $deferred = []; // key => views：待 COMMIT 成功后统一从 APCu 扣除

        $db->exec('BEGIN IMMEDIATE');
        try {
            $iterator = new \APCUIterator('/^' . preg_quote(self::KEY_PREFIX, '/') . '/', APC_ITER_KEY | APC_ITER_VALUE);
            foreach ($iterator as $item) {
                $views = (int)($item['value'] ?? 0);
                $key = (string)$item['key'];
                if ($views <= 0) {
                    // 归零/残留键直接清理，防键随历史主题数无限增长
                    @apcu_delete($key);
                    continue;
                }

                $topicId = (int)substr($key, strlen(self::KEY_PREFIX));
                if ($topicId <= 0) continue;

                // 事务内先落库（不扣 APCu）：DB 失败回滚时计数仍在内存，下个窗口重试不丢
                $db->prepare('UPDATE topic_index SET view_count = view_count + :v WHERE id = :id')
                   ->execute([':v' => $views, ':id' => $topicId]);
                $deferred[$key] = $views;
                $flushed++;
            }
            $db->exec('COMMIT');

            // 落库成功后再扣除内存计数；归零键删除（防键无限增长）
            foreach ($deferred as $key => $views) {
                $remaining = @apcu_dec($key, $views);
                if ($remaining !== false && $remaining <= 0) {
                    @apcu_delete($key);
                }
            }

            // 落库成功后更新时间戳（失败不更新，下个窗口继续重试）
            $dir = dirname($throttleFile);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            @file_put_contents($throttleFile, (string)$now, LOCK_EX);
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }

        return $flushed;
    }
}
