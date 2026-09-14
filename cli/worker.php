<?php
/**
 * FlintHub 1.0 (SplitDB) — 后台任务队列消费者（CLI）
 *
 * 用法：
 *   php cli/worker.php               # 常驻消费 queue_0（默认）
 *   php cli/worker.php 1             # 常驻消费 queue_1
 *   php cli/worker.php 2 --once      # 有界消费 queue_2：处理完当前队列即退出
 *
 * 部署建议（supervisor 管理，白皮书第十一章）：
 *   supervisorctl 启动 3 个实例，分别绑定 queue_0/1/2，
 *   配合 splitdb_wal_reclaim.sh 定时 checkpoint WAL。
 *
 * @package app\cli
 */

require_once __DIR__ . '/_guard.php'; // Web 直访守卫（CLI-only）
require_once __DIR__ . '/../app/Core/Autoloader.php';

use app\SplitDB\Queue;
use app\SplitDB\QueueConsumer;

// ---------- 参数解析 ----------
$queueIndex = 0;
$once = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--once') {
        $once = true;
    } elseif (is_numeric($arg)) {
        $queueIndex = (int)$arg;
    }
}

$queueIndex = max(0, min(Queue::QUEUE_COUNT - 1, $queueIndex));

// ---------- 建立队列连接（幂等建表 + 统一 PRAGMA） ----------
$db = Queue::getQueueDb($queueIndex);

/**
 * 业务处理器（P6 已挂接真实业务）：按 $type 分发
 *   'rebuild_search' → Search::indexThread（重建单帖倒排索引，正文经 Thread 模型读 extern）
 *   'stats'          → Settings::runtimeBuild（异步刷新全站统计计数）
 * 处理器抛异常时任务不会被标记 done，等待超时自动重试（QueueConsumer 防挂起）。
 */
$handler = function (string $type, $data, int $taskId): void {
    $summary = is_array($data)
        ? json_encode($data, JSON_UNESCAPED_UNICODE)
        : (string)$data;
    echo sprintf("[worker queue:%s] task #%d type=%s data=%s\n",
        $GLOBALS['__queue_index'] ?? '?', $taskId, $type, $summary);

    $payload = is_array($data) ? $data : [];

    switch ($type) {
        case 'rebuild_search':
            if (!empty($payload['topic_id'])) {
                \app\Helpers\Search::indexThread((int)$payload['topic_id']);
            }
            break;

        case 'stats':
            \app\Helpers\Settings::runtimeBuild();
            break;

        default:
            // 未知任务类型：记录但不抛错，避免无限重试堆积
            \error_log("SplitDB worker: 未知任务类型 {$type} (task #{$taskId})");
    }
};

$GLOBALS['__queue_index'] = $queueIndex;

// ---------- [P29] 自动扩容检查（SplitDB 引擎控制台） ----------
// 空闲回调：worker 无任务空闲时触发 maybeAutoExpand（桶数翻倍），
// ★ 静态变量 60s 节流：同一常驻进程距上次检查 <60s 直接跳过，防死循环每毫秒读 Settings 表/DB
$lastExpandCheck = 0;
$onIdle = function () use (&$lastExpandCheck): void {
    if (time() - $lastExpandCheck < 60) return;
    $lastExpandCheck = time();
    try {
        $msg = \app\Controllers\Admin\DatabaseController::maybeAutoExpand();
        if ($msg !== '') {
            echo "[worker] " . $msg . PHP_EOL;
        }
    } catch (\Throwable $e) {
        \error_log('worker maybeAutoExpand error: ' . $e->getMessage());
    }
};

$consumer = new QueueConsumer($db, $handler, 300, 3);

echo sprintf("SplitDB worker 启动：队列 queue_%d（%s）\n", $queueIndex, $once ? '--once 有界模式' : '常驻模式');

if ($once) {
    $processed = $consumer->drain(0);
    // --once 消费末尾也检查一次自动扩容
    $onIdle();
    echo sprintf("有界消费完成，共处理 %d 个任务，退出。\n", $processed);
    exit(0);
}

$consumer->consume($onIdle);
