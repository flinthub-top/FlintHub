<?php
/**
 * FlintHub 1.0 (SplitDB) — 队列消费者
 * CAS 乐观锁抢占 + 防挂起。
 *
 * 机制：
 *   fetchTask()      SELECT 一条 pending → CAS UPDATE（WHERE id AND status='pending'）
 *                    rowCount()==0 表示被其他 worker 抢占 → 放弃本任务
 *   processTask()    业务回调成功后置 done；异常时不标记失败，
 *                    保持 processing 等待超时自动恢复（防挂起）
 *   recoverTimeout() processing 超时（update_time < now-timeout）：
 *                    retry_count<3 → 回 pending 并 +1；retry_count>=3 → 置 failed
 *
 * 与白皮书差异（兼容 SQLite 3.33）：update_time 使用 PHP time() 秒级时间戳
 * 传入参数比较，不依赖 strftime('%s','now')。
 *
 * @file app/SplitDB/QueueConsumer.php
 * @package app\SplitDB
 */

namespace app\SplitDB;

use PDO;
use Throwable;

class QueueConsumer
{
    /** 超时秒数：processing 超过该时间未更新视为挂起 */
    private int $timeout = 300;

    /** 重试上限：超过则置 failed */
    private int $maxRetries = 3;

    /** done/failed 任务保留时长（秒）：超过后 purgeCompleted 可清理 */
    private int $retentionSeconds;

    /** 上次执行 recover/purge 维护的时间戳（节流：避免高吞吐下每轮都查） */
    private int $lastMaintenanceAt = 0;

    /** 维护节流间隔（秒）：忙碌循环中也按周期回收挂起任务 + 清理旧任务 */
    private const MAINTENANCE_INTERVAL = 60;

    /** 业务处理回调：function(string $type, array|string $data, int $taskId): void */
    private $handler;

    /** 队列库连接 */
    private PDO $db;

    /** 运行标志（consume 循环退出用） */
    private bool $running = true;

    public function __construct(PDO $db, callable $handler, int $timeout = 300, int $maxRetries = 3, int $retentionSeconds = 604800)
    {
        $this->db = $db;
        $this->handler = $handler;
        $this->timeout = max(1, $timeout);
        $this->maxRetries = max(1, $maxRetries);
        $this->retentionSeconds = max(0, $retentionSeconds);
    }

    /**
     * 无限消费循环（cli/worker.php 常驻进程使用）
     * 无任务时 sleep(1) 避免空转烧 CPU
     *
     * @param callable|null $onIdle 空闲回调（无任务时触发，供低频任务如自动扩容检查；需自行节流）
     */
    public function consume(?callable $onIdle = null): void
    {
        $this->maintenance(); // 启动即回收一次挂起任务 + 清理过期任务
        while ($this->running) {
            $task = $this->fetchTask();
            if ($task === null) {
                sleep(1);
                $this->maintenance(); // 空闲路径：按周期回收/清理（原仅空闲时回收）
                if ($onIdle !== null) {
                    try {
                        $onIdle();
                    } catch (Throwable $e) {
                        \error_log('SplitDB QueueConsumer idle callback error: ' . $e->getMessage());
                    }
                }
                continue;
            }
            $this->processTask($task);
            $this->maintenance(); // 忙碌路径也按周期触发（高吞吐下挂起任务不再等队列空闲才回收）
        }
    }

    /**
     * 有界消费：处理最多 $limit 个任务后返回（0 = 直到队列空）
     * 供测试与 --once 场景使用，不阻塞 sleep
     *
     * @param int   $limit         最多处理任务数（0 = 直到队列空）
     * @param float $budgetSeconds 本轮时间预算（秒），>0 时到期即退出，未处理完任务留在队列下次再消费；<=0 表示不限额
     * @return int 实际处理任务数
     */
    public function drain(int $limit = 0, float $budgetSeconds = 0): int
    {
        $this->maintenance();
        $processed = 0;
        $deadline = $budgetSeconds > 0 ? microtime(true) + $budgetSeconds : 0.0;
        while ($limit === 0 || $processed < $limit) {
            if ($deadline > 0 && microtime(true) >= $deadline) {
                break; // 时间预算耗尽，超时直接退出（剩余任务留在队列，交给下一 cron 周期）
            }
            $task = $this->fetchTask();
            if ($task === null) {
                break;
            }
            $this->processTask($task);
            $processed++;
        }
        return $processed;
    }

    /**
     * 节流维护：recoverTimeout + purgeCompleted 按周期执行一次
     * （忙碌循环每轮调用但受 MAINTENANCE_INTERVAL 节流，避免每轮都查库）
     */
    private function maintenance(): void
    {
        $now = time();
        if ($this->lastMaintenanceAt > 0 && ($now - $this->lastMaintenanceAt) < self::MAINTENANCE_INTERVAL) {
            return;
        }
        $this->lastMaintenanceAt = $now;
        try {
            $this->recoverTimeout();
            $this->purgeCompleted();
        } catch (Throwable $e) {
            // 维护失败不阻塞消费
            \error_log('SplitDB QueueConsumer maintenance error: ' . $e->getMessage());
        }
    }

    /**
     * 清理已完成/失败任务（保留 N 天，防 queue_*.sqlite 无限增长）
     * done/failed 记录只增不删会随运行时间持续膨胀；按 update_time 保留
     * retentionSeconds 内的记录，更早的删除（命中 idx_update_time 索引）。
     * 注意：delete 不加 LIMIT——done/failed 无并发写者，单条 DELETE 即可；
     * 如担心超大表一次删除过大，可分批调用。
     *
     * @param int $retentionSeconds 覆盖保留时长（0/缺省 = 构造参数，默认 7 天）
     * @return int 删除行数
     */
    public function purgeCompleted(int $retentionSeconds = 0): int
    {
        $keep = $retentionSeconds > 0 ? $retentionSeconds : $this->retentionSeconds;
        if ($keep <= 0) {
            return 0; // 0 = 不清理
        }
        $cutoff = time() - $keep;
        $stmt = $this->db->prepare(
            "DELETE FROM task_queue
             WHERE status IN ('done', 'failed') AND update_time < :cutoff"
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    /**
     * CAS 抢占任务：SELECT pending → 条件 UPDATE，rowCount()==0 视为抢占失败
     */
    private function fetchTask(): ?array
    {
        $row = $this->db->query(
            "SELECT id, type, data FROM task_queue
             WHERE status = 'pending'
             ORDER BY id ASC
             LIMIT 1"
        )->fetch();

        if (!$row) {
            return null;
        }

        // CAS 核心：只有仍为 pending 才能抢占成功，防多 worker 重复处理
        $stmt = $this->db->prepare(
            "UPDATE task_queue
             SET status = 'processing', update_time = :now
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute([
            ':id'  => (int)$row['id'],
            ':now' => time(),
        ]);

        if ($stmt->rowCount() === 0) {
            return null; // 被其他 worker 抢先
        }

        return $row;
    }

    /**
     * 处理任务：成功置 done；异常保持 processing，等待超时后自动恢复
     */
    private function processTask(array $task): void
    {
        $taskId = (int)$task['id'];
        try {
            $data = json_decode((string)$task['data'], true);
            $data = $data === null ? (string)$task['data'] : $data;

            ($this->handler)((string)$task['type'], $data, $taskId);

            $this->db->prepare(
                "UPDATE task_queue SET status = 'done', update_time = :now WHERE id = :id"
            )->execute([':id' => $taskId, ':now' => time()]);
        } catch (Throwable $e) {
            // 不标记失败：保持 processing，超时后由 recoverTimeout 决定重试或失败
            error_log("SplitDB QueueConsumer: task #{$taskId} ({$task['type']}) failed: " . $e->getMessage());
        }
    }

    /**
     * 恢复超时挂起任务：
     *   retry_count < maxRetries → 回 pending，retry_count+1
     *   retry_count >= maxRetries → 置 failed
     *
     * @return array{recovered:int, failed:int} 统计
     */
    public function recoverTimeout(): array
    {
        $now = time();
        $cutoff = $now - $this->timeout;

        $stmt = $this->db->prepare(
            "UPDATE task_queue
             SET status = 'pending', retry_count = retry_count + 1, update_time = :now
             WHERE status = 'processing' AND update_time < :cutoff AND retry_count < :maxRetries"
        );
        $stmt->execute([':now' => $now, ':cutoff' => $cutoff, ':maxRetries' => $this->maxRetries]);
        $recovered = $stmt->rowCount();

        $stmt = $this->db->prepare(
            "UPDATE task_queue
             SET status = 'failed', update_time = :now
             WHERE status = 'processing' AND update_time < :cutoff AND retry_count >= :maxRetries"
        );
        $stmt->execute([':now' => $now, ':cutoff' => $cutoff, ':maxRetries' => $this->maxRetries]);
        $failed = $stmt->rowCount();

        return ['recovered' => $recovered, 'failed' => $failed];
    }

    /**
     * 停止常驻循环
     */
    public function stop(): void
    {
        $this->running = false;
    }
}
