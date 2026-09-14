<?php
/**
 * FlintHub 1.0 (SplitDB) — 任务队列分发
 * 白皮书 7.6 + 8.1：SQLite 多队列异步任务系统。
 * 写入时随机分发到 3 个队列文件之一（打散锁竞争），
 * 用于异步重建索引 / 更新统计，支持最终一致性。
 *
 * 队列文件路径（相对 DATA_PATH）：
 *   meta/task_queue/queue_0.sqlite ~ queue_2.sqlite
 *
 * @file app/SplitDB/Queue.php
 * @package app\SplitDB
 */

namespace app\SplitDB;

use PDO;

class Queue
{
    /** 队列文件数量（3 队列打散锁竞争） */
    public const QUEUE_COUNT = 3;

    /** 任务状态 */
    public const STATUS_PENDING   = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE      = 'done';
    public const STATUS_FAILED    = 'failed';

    /**
     * 幂等建表（task_queue 表 + 两个索引）
     */
    public static function initTable(PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS task_queue (
                id INTEGER PRIMARY KEY,
                type TEXT,
                data TEXT,
                status TEXT DEFAULT 'pending',
                create_time INTEGER,
                update_time INTEGER DEFAULT 0,
                retry_count INTEGER DEFAULT 0
            )"
        );
        $db->exec('CREATE INDEX IF NOT EXISTS idx_status ON task_queue (status)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_update_time ON task_queue (update_time)');
    }

    /**
     * 计算第 N 个队列文件的绝对路径（自动创建目录）
     */
    public static function queuePath(int $index, ?string $dataPath = null): string
    {
        $idx = max(0, min(self::QUEUE_COUNT - 1, $index));
        $dir = (rtrim($dataPath ?: ShardRouter::dataPath(), '/\\')) . '/meta/task_queue';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . "/queue_{$idx}.sqlite";
    }

    /**
     * 获取第 N 个队列的连接（经 DBFactory，统一 PRAGMA，建表幂等）
     */
    public static function getQueueDb(int $index, ?string $dataPath = null): PDO
    {
        $db = DBFactory::getConnection(self::queuePath($index, $dataPath));
        self::initTable($db);
        return $db;
    }

    /**
     * 入队：随机分发到 3 个队列之一（打散写锁竞争）
     *
     * @param string           $type  任务类型（如 'rebuild_search' / 'stats'）
     * @param array|string     $data  任务负载（数组将 JSON 编码为 TEXT 存储）
     * @param int|null         $queueIndex 指定队列（默认 null = 随机）
     * @return int 任务 ID
     */
    public static function push(string $type, $data, ?int $queueIndex = null, ?string $dataPath = null): int
    {
        if ($queueIndex === null) {
            $queueIndex = mt_rand(0, self::QUEUE_COUNT - 1);
        }
        $db = self::getQueueDb($queueIndex, $dataPath);

        $payload = is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : (string)$data;
        // 失败检测：数组负载 JSON 编码失败（非法 UTF-8 等）时禁止入队空串任务，
        // 避免消费端解析空串/伪任务且无法重试
        if (is_array($data) && $payload === false) {
            $jsonErr = json_last_error_msg();
            \error_log('Queue::push json_encode failed (type=' . $type . '): ' . $jsonErr);
            throw new \RuntimeException('Queue::push: 任务负载 JSON 编码失败: ' . $jsonErr);
        }
        $stmt = $db->prepare(
            'INSERT INTO task_queue (type, data, status, create_time) VALUES (:type, :data, :status, :now)'
        );
        $stmt->execute([
            ':type'   => $type,
            ':data'   => $payload,
            ':status' => self::STATUS_PENDING,
            ':now'    => time(),
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * 统计第 N 个队列的任务数量（按状态分组，诊断/监控用）
     */
    public static function countByStatus(int $queueIndex, ?string $dataPath = null): array
    {
        $db = self::getQueueDb($queueIndex, $dataPath);
        $rows = $db->query('SELECT status, COUNT(*) as cnt FROM task_queue GROUP BY status')->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['status']] = (int)$row['cnt'];
        }
        return $map;
    }
}
