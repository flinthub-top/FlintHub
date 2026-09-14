<?php
/**
 * FlintHub 1.0 (SplitDB) — 冷数据归档（CLI）
 *
 * 用法：
 *   php cli/archive.php            # 归档全部超期季度
 *   php cli/archive.php --dry-run  # 仅预览，不实际移动
 *
 * 规则：季度结束后超过 18 个月的桶数据自动归档。
 *   活跃桶：data/bucket/active/{YYYYQn}/{bucket}.sqlite
 *   归档桶：data/bucket/archive/{YYYYQn}/{bucket}.sqlite
 * 归档时同步更新 main_index（topic_index / reply_index）的 bucket_path，
 * 读取路由始终以 bucket_path 为准，归档后无需改业务代码。
 * 正文 extern 文件不随桶移动（其路径独立于 bucket，读取仍有效）。
 *
 * 安全顺序（E2 修复，2026-09-06）：
 *   ① VACUUM INTO 生成归档副本（一致性快照，源文件原地不动，窗口期读写不受影响）；
 *   ② 打开副本校验行数与源一致（校验不过 = 失败，源/索引均未动，可安全重跑）；
 *   ③ 两条索引 UPDATE 包进同一事务后提交（原子翻转 bucket_path）；
 *   ④ 最后才删除源桶文件——索引只可能指向「已存在且已校验」的文件，
 *      彻底消除「先移文件后改索引」窗口下的活跃桶静默新建空桶 / 帖子分叉。
 *   另：脚本级 flock 防并发归档；mkdir 与删除失败均 error_log（失败必发声）。
 *
 * @package app\cli
 */

require_once __DIR__ . '/_guard.php'; // Web 直访守卫（CLI-only）
require_once __DIR__ . '/../app/Core/Autoloader.php';

use app\SplitDB\DBFactory;
use app\SplitDB\ShardRouter;

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

$root = rtrim(ShardRouter::dataPath(), '/\\');
$activeDir = $root . '/bucket/active';
$archiveDir = $root . '/bucket/archive';

if (!is_dir($activeDir)) {
    echo "无活跃桶目录，退出。\n";
    exit(0);
}
if (!is_dir($archiveDir) && !@mkdir($archiveDir, 0755, true)) {
    \error_log('SplitDB 归档: 创建归档目录失败: ' . $archiveDir);
    echo "归档目录创建失败，退出。\n";
    exit(1);
}

// 脚本级互斥锁（防止 cron 与手动触发并发归档；dry-run 只读不抢锁）
$lockPath = $root . '/lock/archive.lock';
$lockFh = false;
if (!$dryRun) {
    if (!is_dir(dirname($lockPath))) {
        @mkdir(dirname($lockPath), 0755, true);
    }
    $lockFh = @fopen($lockPath, 'c');
    if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
        if ($lockFh !== false) @fclose($lockFh);
        \error_log('SplitDB 归档: 已有归档进程在运行，跳过本次');
        echo "另一归档进程正在运行，本次跳过。\n";
        exit(0);
    }
}

try {
    // 1. 找出超期季度（季度结束时间 + 18 个月 < 当前时间）
    $cutoff = time() - 18 * 30 * 24 * 3600; // 18 个月（按月近似 30 天）
    $expired = [];
    foreach (glob($activeDir . '/????Q?', GLOB_ONLYDIR) ?: [] as $dir) {
        $quarter = basename($dir);
        if (!preg_match('/^(\d{4})Q([1-4])$/', $quarter, $m)) continue;
        $year = (int)$m[1];
        $q = (int)$m[2];
        // 季度结束时间：当季最后一天 23:59:59（mktime day 传 0 = 上月最后一天，下月 0 日即当季末日，兼容 28/29/30/31）
        $endMonth = ($q - 1) * 3 + 3; // 3/6/9/12
        $quarterEnd = mktime(23, 59, 59, $endMonth + 1, 0, $year);
        if ($quarterEnd < $cutoff) {
            $expired[$quarter] = $dir;
        }
    }

    if (empty($expired)) {
        echo "无超期季度（18 个月），无需归档。\n";
        exit(0);
    }

    echo "待归档季度：" . implode(', ', array_keys($expired)) . "\n";

    if ($dryRun) {
        echo "[dry-run] 预览完成，未实际移动。\n";
        exit(0);
    }

    // 2. 逐季度归档
    $mi = \app\SplitDB\Schema::mainIndexDb();
    foreach ($expired as $quarter => $dir) {
        $dest = $archiveDir . '/' . $quarter;
        if (!is_dir($dest) && !@mkdir($dest, 0755, true)) {
            \error_log("SplitDB 归档: 创建季度归档目录失败: {$dest}");
            echo "  [warn] 创建归档目录失败: {$dest}\n";
            continue;
        }

        $bucketFiles = glob($dir . '/*.sqlite') ?: [];
        if (empty($bucketFiles)) {
            @rmdir($dir); // 空季度目录
            echo "归档 {$quarter}：无桶文件，跳过\n";
            continue;
        }

        // ① VACUUM INTO 生成归档副本（源文件原地不动）
        $copied = [];
        $fail = false;
        foreach ($bucketFiles as $bucketFile) {
            $name = basename($bucketFile);
            $target = $dest . '/' . $name;
            if (is_file($target)) @unlink($target); // VACUUM INTO 不覆盖已存在文件，先清残留副本
            try {
                $src = DBFactory::getConnection($bucketFile);
                $topicCnt = (int)$src->query('SELECT COUNT(*) FROM topic')->fetchColumn();
                $replyCnt = (int)$src->query('SELECT COUNT(*) FROM reply')->fetchColumn();
                $targetEsc = str_replace("'", "''", $target);
                $src->exec("VACUUM INTO '{$targetEsc}'");
            } catch (\Throwable $e) {
                \error_log("SplitDB 归档: VACUUM INTO 失败 {$bucketFile}: " . $e->getMessage());
                echo "  [warn] VACUUM INTO 失败: {$bucketFile}\n";
                $fail = true;
                break;
            }

            // ② 校验副本：可打开 + 行数与源一致
            try {
                $dst = DBFactory::getConnection($target);
                $t2 = (int)$dst->query('SELECT COUNT(*) FROM topic')->fetchColumn();
                $r2 = (int)$dst->query('SELECT COUNT(*) FROM reply')->fetchColumn();
                if ($t2 !== $topicCnt || $r2 !== $replyCnt) {
                    \error_log("SplitDB 归档: 副本校验不一致 {$name}（源 topic={$topicCnt}/reply={$replyCnt}，副本 topic={$t2}/reply={$r2}）");
                    echo "  [warn] 副本校验不一致: {$name}\n";
                    $fail = true;
                    break;
                }
            } catch (\Throwable $e) {
                \error_log("SplitDB 归档: 副本校验异常 {$target}: " . $e->getMessage());
                echo "  [warn] 副本校验异常: {$name}\n";
                $fail = true;
                break;
            }
            $copied[] = $name;
        }

        if ($fail) {
            // 源文件与索引均未动；副本残留清掉，下次重跑可自愈
            foreach ($copied as $name) { @unlink($dest . '/' . $name); }
            echo "归档 {$quarter}：校验失败，已跳过（索引未改，可重跑）\n";
            continue;
        }
        if (empty($copied)) {
            echo "归档 {$quarter}：无桶文件，跳过\n";
            continue;
        }

        // 释放全部句柄后再删源文件（Windows 下文件移动/删除前必须先释放句柄）
        DBFactory::closeAll();

        // ③ 原子翻转索引（两条 UPDATE 同一事务）
        $from = "bucket/active/{$quarter}/";
        $to = "bucket/archive/{$quarter}/";
        try {
            $mi->beginTransaction();
            $mi->prepare(
                "UPDATE topic_index SET bucket_path = REPLACE(bucket_path, :from, :to) WHERE bucket_path LIKE :like"
            )->execute([':from' => $from, ':to' => $to, ':like' => $from . '%']);
            $topicUpdated = (int)$mi->query('SELECT changes()')->fetchColumn();
            $mi->prepare(
                "UPDATE reply_index SET bucket_path = REPLACE(bucket_path, :from, :to) WHERE bucket_path LIKE :like"
            )->execute([':from' => $from, ':to' => $to, ':like' => $from . '%']);
            $mi->commit();
        } catch (\Throwable $e) {
            if ($mi->inTransaction()) { $mi->rollBack(); }
            \error_log("SplitDB 归档: 索引翻转失败 {$quarter}: " . $e->getMessage());
            echo "  [warn] 索引翻转失败: {$quarter}（副本已生成，源文件未删，需人工处理）\n";
            continue;
        }

        // ④ 校验副本全数存在后删除源文件（副本已在第②步校验行数一致）
        foreach ($copied as $name) {
            if (!is_file($dest . '/' . $name)) {
                \error_log("SplitDB 归档: 归档副本缺失，停止删除源文件 {$dir}/{$name}");
                echo "  [warn] 归档副本缺失，保留源文件: {$name}\n";
                continue;
            }
            if (!@unlink($dir . '/' . $name)) {
                \error_log("SplitDB 归档: 删除源桶失败 {$dir}/{$name}（句柄占用？索引已指向归档副本）");
                echo "  [warn] 删除源桶失败: {$name}（索引已翻转，副本可用）\n";
            }
            foreach (['-wal', '-shm'] as $suffix) {
                $side = $dir . '/' . $name . $suffix;
                if (is_file($side) && !@unlink($side)) {
                    \error_log("SplitDB 归档: 删除源桶附属文件失败: {$side}");
                }
            }
        }

        // 删除空的季度目录（非空则说明仍有文件未删，保留并告警）
        if (!@rmdir($dir)) {
            \error_log("SplitDB 归档: 季度目录非空未删除（残留源文件？）: {$dir}");
        }

        echo "归档 {$quarter}：生成副本 " . count($copied) . " 个，更新 topic_index {$topicUpdated} 行\n";
    }

    echo "归档完成。\n";
} finally {
    if ($lockFh !== false) {
        flock($lockFh, LOCK_UN);
        @fclose($lockFh);
    }
}
exit(0);
