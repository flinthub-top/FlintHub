<?php
/**
 * FlintHub 1.0 (SplitDB) — 旧帖归档标记脚本（智能扩容与归档机制）
 *
 * 用法：
 *   php cli/archive_mark.php            # 将超过归档阈值（settings.archive_after_days）的帖子标记为 is_archived=1
 *   php cli/archive_mark.php --dry-run  # 仅预览本次将标记的行数，不实际写入
 *
 * 说明：
 *   ① 阈值读取 settings.archive_after_days（0=关闭归档，默认 365 天）；
 *   ② 分批 UPDATE（每批 LIMIT 5000，写放大硬上限），循环至 0 行；
 *   ③ 幂等可重跑：is_archived=1 的行自动跳过；
 *   ④ 跳过已删除帖（deleted_at IS NULL）；
 *   ⑤ 前台详情页还有「读取时惰性标记」兜底（ThreadController::show），
 *      即使定时任务未跑，归档状态也能即时生效。
 *
 * 部署：
 *   - 有 crontab：0 3 * * * php /path/cli/archive_mark.php >> /path/protected/archive_mark.log 2>&1
 *   - 无 crontab（虚拟主机）：由 cron_trigger.php（queue_mode=cron）内部 require 执行。
 *     ★ 内部加载与命令行直跑共用同一逻辑函数；两种入口均打印结果文案并以正常方式结束：
 *       CLI 直跑用 exit(0)；cron_trigger 内部加载走 return（return 于被包含脚本等同文件结束，
 *       不会中断 cron_trigger 其后续低频维护代码）。
 *
 * @package app\cli
 */

// 直跑（命令行）需通过 Web 守卫（CLI-only）；由 cron_trigger.php 内部 require 时，
// 其已定义哨兵常量 CRON_TRIGGER_ARCHIVE_MARK，此处跳过 _guard.php，避免 cgi 上下文 403 终止宿主请求。
if (!defined('CRON_TRIGGER_ARCHIVE_MARK')) {
    require_once __DIR__ . '/_guard.php';
}
require_once __DIR__ . '/../app/Core/Autoloader.php';

/**
 * 执行旧帖归档标记（阈值读取 + 幂等 + 分批 LIMIT 5000）
 *
 * 同时兼容命令行直跑与 cron_trigger 内部加载，不触发 exit()，仅返回结果文案（由调用方输出）。
 *
 * @param bool $dryRun 仅预览待标记行数，不写入
 * @return string 结果文案（调用方追加换行并输出）
 */
function flint_archive_mark(bool $dryRun): string
{
    $days = (int)\app\Helpers\Settings::get('archive_after_days', '365');
    if ($days <= 0) {
        return "archive_after_days = {$days}（关闭归档），无需标记。";
    }

    $cutoff = time() - $days * 86400;
    $db = \app\SplitDB\Schema::mainIndexDb();

    // 预览（一次性 COUNT）
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM topic_index WHERE is_archived = 0 AND deleted_at IS NULL AND create_time < :cut'
    );
    $stmt->execute([':cut' => $cutoff]);
    $pending = (int)$stmt->fetchColumn();

    if ($dryRun) {
        return "[dry-run] 归档阈值 {$days} 天（cutoff=" . date('Y-m-d H:i:s', $cutoff) . "），待标记 {$pending} 行。";
    }
    if ($pending === 0) {
        return "无需标记（归档阈值 {$days} 天，无超期未归档帖）。";
    }

    // 分批标记（每批 5000，写放大硬上限）
    $marked = 0;
    while (true) {
        $batch = $db->prepare(
            'UPDATE topic_index SET is_archived = 1
             WHERE rowid IN (
                 SELECT rowid FROM topic_index
                 WHERE is_archived = 0 AND deleted_at IS NULL AND create_time < :cut
                 LIMIT 5000
             )'
        );
        $batch->execute([':cut' => $cutoff]);
        $n = $batch->rowCount();
        if ($n <= 0) break;
        $marked += $n;
        if ($n < 5000) break; // 最后一批
    }

    return "归档标记完成：共 {$marked} 行（阈值 {$days} 天，cutoff=" . date('Y-m-d H:i:s', $cutoff) . "）。";
}

// —— 入口（命令行直跑才读 $argv；cron_trigger 内部加载时 $argv 不存在于 cgi 上下文，用 isset 兜底） ——
$dryRun = isset($argv) && in_array('--dry-run', array_slice($argv, 1), true);

echo flint_archive_mark($dryRun) . PHP_EOL;

// 命令行直跑以 exit(0) 收尾；cron_trigger 内部加载时这里不执行 exit，脚本 return 结束，宿主继续。
if (!defined('CRON_TRIGGER_ARCHIVE_MARK')) {
    exit(0);
}