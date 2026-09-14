<?php
/**
 * FlintHub 1.0 (SplitDB) — Cron 定时触发入口（队列任务 Web 消费端）
 *
 * 用途：模式 B（Cron 定时触发）的执行入口，供虚拟主机控制面板的 Cron 定时访问：
 *   wget -q -O /dev/null http://你的域名/cron_trigger.php
 *
 * 安全设计：
 *   ① 模式守卫：仅当 settings.queue_mode = cron 时执行（防止 CLI 常驻模式下被误触发双消费）
 *   ② 文件锁：lock/cron_trigger.lock（LOCK_EX|LOCK_NB），防止上次未跑完时并发重入
 *   ③ 有界消费：对 3 个队列各 drain() 一次后立即退出，不常驻
 *
 * @package app
 */

require_once __DIR__ . '/app/Core/Autoloader.php';
// ★ 必须加载根目录 config.php：本文件被 Cron 直接 Web 访问（不走 index.php/init.php），
//   只有在这里 require config.php，CRON_KEY、SPLITDB_DATA_PATH 等全局常量才会被定义。
require_once __DIR__ . '/config.php';

// Web 上下文 PHP 默认 max_execution_time=30s，会截断队列消费（drain 超时 300s）及后续扩容/归档/维护任务；
// 放宽到 600s，保证完整流程跑完不被 30s 杀进程。
set_time_limit(600);

use app\SplitDB\Queue;
use app\SplitDB\QueueConsumer;

header('Content-Type: text/plain; charset=utf-8');

// ---------- ① 模式守卫 ----------
$mode = \app\Helpers\Settings::get('queue_mode', 'sync');
if ($mode !== 'cron') {
    echo 'queue_mode = ' . $mode . '，非 cron 模式，拒绝执行。' . PHP_EOL;
    exit(0);
}

// ---------- ①b 触发密钥校验（强制） ----------
// CRON_KEY 为空直接 503 拒绝（不再"留空即放行"，杜绝无钥情况下被匿名触发队列消费/扩容）；
// 密钥读取优先取 Header X-Cron-Key，兼容读取 URL 参数 ?key= 作为过渡。
if (!defined('CRON_KEY') || \CRON_KEY === '') {
    http_response_code(503);
    echo '503 Service Unavailable — CRON_KEY 未配置，拒绝执行。' . PHP_EOL;
    exit(0);
}
$gotKey = (string)($_SERVER['HTTP_X_CRON_KEY'] ?? ($_GET['key'] ?? ''));
if (!hash_equals((string)\CRON_KEY, $gotKey)) {
    http_response_code(403);
    echo '403 Forbidden — 触发密钥无效。' . PHP_EOL;
    exit(0);
}

// ---------- ② 文件锁（防并发重入） ----------
$lockFile = \app\SplitDB\ShardRouter::dataPath() . '/lock/cron_trigger.lock';
$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) @mkdir($lockDir, 0755, true);
$lock = @fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo '已有 cron_trigger 正在执行，本次跳过。' . PHP_EOL;
    if ($lock !== false) @fclose($lock);
    exit(0);
}
// 锁兜底释放：脚本因异常或 exit 提前终止时（如队列消费中途被 max_execution_time 截断），
// 确保已持有的 flock 也在 shutdown 阶段被显式释放，避免锁残留导致后续执行永远跳过。
// is_resource 保护：脚本正常路径会先 flock LOCK_UN + fclose，shutdown 若再执行需跳过已关闭句柄。
register_shutdown_function(function () use ($lock): void {
    if (is_resource($lock)) {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
});

// ---------- ③ 业务处理器（与 cli/worker.php 同构） ----------
$handler = function (string $type, $data, int $taskId): void {
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
            \error_log("SplitDB cron_trigger: 未知任务类型 {$type} (task #{$taskId})");
    }
};

// ---------- ④ 轻量低频维护（置于队列消费之前：轻量任务先行，即使队列被拖慢也能保证执行） ----------
// 按上次运行时间节流，同一窗口重复触发不会重复执行。
// 归档/孤儿附件等重活已移出本段，改到队列消费后以独立时间预算执行（见下方"⑧ 重活维护"）。
$dataPathMaint = \app\SplitDB\ShardRouter::dataPath();
$runtimeMaintDir = $dataPathMaint . '/runtime';
if (!is_dir($runtimeMaintDir)) @mkdir($runtimeMaintDir, 0755, true);
$onceEvery = function (string $key, int $seconds) use ($runtimeMaintDir): bool {
    $f = $runtimeMaintDir . '/cron_last_' . $key . '.ts';
    if (is_file($f) && time() - (int)@file_get_contents($f) < $seconds) return false;
    @file_put_contents($f, time(), LOCK_EX);
    return true;
};

// b) 会话 GC（自 config.php 300s 节流逻辑迁移）：清理文件会话 + SQLite sessions 过期行
try {
    if ($onceEvery('session_gc', 300)) {
        $sessDir = __DIR__ . '/protected/sessions';
        $maxLifetime = (int)ini_get('session.gc_maxlifetime') ?: 1440;
        foreach (glob($sessDir . '/sess_*') ?: [] as $sf) {
            if (time() - filemtime($sf) > $maxLifetime) @unlink($sf);
        }
        // 会话已存 SQLite（init 注册 SQLite handler），文件 gc 对 sessions.sqlite 无效；
        // PHP session.gc_probability=1/1000 几乎不触发 SQLite gc → 过期行只增不删，此处直删之。
        // 写放大硬上限：单次最多删 5000 行，剩余由下一 300s 窗口分批清理。
        $sessionsFile = $dataPathMaint . '/meta/sessions.sqlite';
        if (is_file($sessionsFile)) {
            $gcPdo = null;
            try {
                $gcPdo = new PDO('sqlite:' . $sessionsFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $gcPdo->exec('PRAGMA busy_timeout = 5000;');
                $gcPdo->prepare(
                    'DELETE FROM sessions WHERE rowid IN (
                        SELECT rowid FROM sessions WHERE expires < :now LIMIT 5000
                    )'
                )->execute([':now' => time()]);
            } finally {
                // 显式置 null 关闭 PDO，尽早释放底层 SQLite 连接，避免本进程内锁/句柄残留
                $gcPdo = null;
            }
        }
    }
} catch (\Throwable $e) {
    \error_log('cron_trigger session_gc error: ' . $e->getMessage());
}

// c) 审计日志清理（每日一次，按 AuditLog 保留天数）
try {
    if ($onceEvery('audit_log_purge', 86400)) {
        $purged = \app\Helpers\AuditLog::purgeOldLogs();
        if ($purged > 0) echo "清理审计日志 {$purged} 条。" . PHP_EOL;
    }
} catch (\Throwable $e) {
    \error_log('cron_trigger audit_log_purge error: ' . $e->getMessage());
}

// e) AI 助手待执行任务消费（腾讯云函数 / 虚拟主机 cron 触发即处理）
// 后台"立即处理"走 spawnWorkerOnce 子进程，虚拟主机常被禁用不可用；
// cron 内改用 flushBatch 同步有界消费（内部 drainDue 自带 flush 锁 + 时间预算 + 超时恢复，无并发风险）。
// ★ 尊重延迟（不加 force）：只消费已到期任务，未到期的随机延迟任务留给后续触发，随机延迟节奏不被破坏。
// ★ 节流 300s：与外部 Cron（腾讯云函数等）触发频率匹配，每次触发最多消费一批（20 条），
//   大积压任务分多批逐步消化，不常驻、不阻塞其他 cron 功能。
try {
    if ($onceEvery('ai_flush', 300)) {
        set_time_limit(300);
        $r = \Plugin\AiAssistant\Plugin::flushBatch(20);
        if ($r['processed'] > 0) echo "AI 助手待执行任务：本批处理 {$r['processed']} 条。" . PHP_EOL;
    }
} catch (\Throwable $e) {
    \error_log('cron_trigger ai_flush error: ' . $e->getMessage());
}

// ---------- ⑤ 有界消费全部 3 个队列（共享总时间预算） ----------
// 共享 $deadline = 240s 总预算：每个队列按"剩余时间 / 剩余队列数"动态分配片段，
// 任一队列或总预算耗尽即退出，避免第 1 个队列独占全部时间拖垮后续队列；
// 未消费完的任务留在队列，交给下一 cron 周期继续。
$total = 0;
$deadline = microtime(true) + 240;
for ($i = 0; $i < Queue::QUEUE_COUNT; $i++) {
    $remaining = $deadline - microtime(true);
    if ($remaining <= 0) {
        echo sprintf("queue_%d: 总时间预算已耗尽，跳过。\n", $i);
        break;
    }
    $budget = $remaining / (Queue::QUEUE_COUNT - $i); // 剩余时间均分给剩余队列
    $db = Queue::getQueueDb($i);
    $consumer = new QueueConsumer($db, $handler, 300, 3);
    $processed = $consumer->drain(0, $budget);
    echo sprintf("queue_%d: 处理 %d 个任务（本队列预算 %.1fs）\n", $i, $processed, $budget);
    $total += $processed;
}

// ---------- ⑥ 自动扩容检查（消费末尾调用，无需管理员访问也能自动扩） ----------
try {
    $expandMsg = \app\Controllers\Admin\DatabaseController::maybeAutoExpand();
    if ($expandMsg !== '') {
        echo $expandMsg . PHP_EOL;
    }
} catch (\Throwable $e) {
    \error_log('cron_trigger maybeAutoExpand error: ' . $e->getMessage());
}

// ---------- ⑧ 重活维护（置于队列消费之后，独立时间预算） ----------
// 归档标记、孤儿附件清理为重量级同步任务（全表扫描 / 遍历分片桶 IO），
// 放到队列消费之后执行，确保其拖慢不影响队列吞吐；并各自按"剩余总预算 ≥15s"决定是否本轮执行——
// 时间不够则跳过且不写节流时间戳，把机会留给下一 cron 周期（做不完下次再做）。
$deadlineHeavy = microtime(true) + 15; // 重活独立时间预算 15s

// 8.1) 旧帖归档标记（每日一次，幂等）：超过 archive_after_days 的帖子置 is_archived=1
// ★ 内部 require 加载（shell_exec 在虚拟主机常被禁用，不能走子进程）：
//   先置哨兵常量，让 cli/archive_mark.php 跳过 _guard.php 的 CLI-only 守卫（cgi 上下文若非哨兵会 403 并终止本请求）；
//   其内部逻辑已函数化，以 `return` 结束。
// ★ 每日节流（$onceEvery 86400）：避免 cron 每 5 分钟触发一次导致全表扫描 288 次/天。
try {
    if (microtime(true) < $deadlineHeavy) {
        if ($onceEvery('archive_mark', 86400)) {
            $archiveScript = __DIR__ . '/cli/archive_mark.php';
            if (is_file($archiveScript)) {
                if (!defined('CRON_TRIGGER_ARCHIVE_MARK')) {
                    define('CRON_TRIGGER_ARCHIVE_MARK', 1);
                }
                require_once $archiveScript;
            }
        }
    }
} catch (\Throwable $e) {
    \error_log('cron_trigger archive_mark error: ' . $e->getMessage());
}

// 8.2) 孤儿附件清理（每周一次）：需遍历分片桶读取回复正文匹配图片，IO 较重，限定低频执行
try {
    if (microtime(true) < $deadlineHeavy) {
        if ($onceEvery('orphan_attachments', 7 * 86400)) {
            [$del, $freed] = \app\Helpers\Settings::cleanupOrphanAttachments();
            echo "清理孤儿附件：删除 {$del} 个，释放 {$freed} 字节。" . PHP_EOL;
        }
    }
} catch (\Throwable $e) {
    \error_log('cron_trigger orphan_attachments error: ' . $e->getMessage());
}

// ---------- ⑦ 释放锁并退出 ----------
flock($lock, LOCK_UN);
@fclose($lock);
echo sprintf("cron_trigger 完成，共处理 %d 个任务。\n", $total);
