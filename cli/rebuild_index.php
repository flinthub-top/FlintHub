<?php
/**
 * FlintHub 1.0 (SplitDB) — 索引回补工具（D8 保底）
 *
 * 用途：跨库写入部分失败（桶有真相、索引无行 = 帖子/回复隐身）时，
 * 扫描各季度桶（active + archive），把缺失的 topic_index / reply_index 行回补上。
 * 平时无需运行；仅在出现「桶里有数据但列表/详情读不到」的疑似索引缺失时运行。
 *
 * 用法：
 *   php cli/rebuild_index.php            # 扫描并回补缺失索引行
 *   php cli/rebuild_index.php --dry-run  # 仅预览将回补的行数，不实际写入
 *
 * 语义（只回补、不覆盖）：
 *   对每个桶文件，把桶内 topic/reply 全量行按「INSERT OR IGNORE」写入 main_index——
 *   已存在同 id 的行原样保留（不动其 view_count/置顶等索引态字段），仅补缺失行。
 *   幂等可重跑；每批 500 行，写放大受控。
 *
 * @package app\cli
 */

require_once __DIR__ . '/_guard.php'; // Web 直访守卫（CLI-only）
require_once __DIR__ . '/../app/Core/Autoloader.php';

use app\SplitDB\DBFactory;
use app\SplitDB\ShardRouter;
use app\SplitDB\Schema;

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

$root = rtrim(ShardRouter::dataPath(), '/\\');
$mi = Schema::mainIndexDb();

$scannedBuckets = 0;
$insertedTopics = 0;
$insertedReplies = 0;

try {
    foreach (['bucket/active', 'bucket/archive'] as $relArea) {
        $areaDir = $root . '/' . $relArea;
        if (!is_dir($areaDir)) continue;
        foreach (glob($areaDir . '/????Q?/*.sqlite') ?: [] as $bucketFile) {
            $scannedBuckets++;
            $relPath = str_replace('\\', '/', substr($bucketFile, strlen($root) + 1)); // bucket/xxx/2026Q3/5.sqlite
            try {
                $bdb = DBFactory::getConnection($bucketFile);

                // ---- topic → topic_index 回补 ----
                $stmt = $bdb->query('SELECT * FROM topic');
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach (array_chunk($rows, 500) as $chunk) {
                    foreach ($chunk as $r) {
                        $params = [
                            ':id' => (int)$r['id'], ':uid' => (int)($r['uid'] ?? 0),
                            ':title' => (string)($r['title'] ?? ''), ':ct' => (int)($r['create_time'] ?? 0),
                            ':lrt' => (int)($r['last_reply_time'] ?? 0),
                            ':vc' => (int)($r['view_count'] ?? 0), ':rc' => (int)($r['reply_count'] ?? 0),
                            ':st' => (int)($r['status'] ?? 0), ':cid' => (int)($r['category_id'] ?? 0),
                            ':pin' => (int)($r['is_pinned'] ?? 0), ':hl' => (int)($r['is_highlighted'] ?? 0),
                            ':color' => (string)($r['color'] ?? ''), ':rtv' => (int)($r['reply_to_view'] ?? 0),
                            ':del' => isset($r['deleted_at']) && $r['deleted_at'] !== null ? (int)$r['deleted_at'] : null,
                            ':bp' => $relPath,
                            ':ex' => (string)($r['excerpt'] ?? ''), ':ei' => (string)($r['excerpt_images'] ?? ''),
                        ];
                        if ($dryRun) {
                            // 预览：仅统计桶中存在但索引缺失的 id（OR IGNORE 无法预览 → 查缺失）
                            $exists = $mi->prepare('SELECT 1 FROM topic_index WHERE id = :id');
                            $exists->execute([':id' => $params[':id']]);
                            if ($exists->fetchColumn() === false) $insertedTopics++;
                        } else {
                            $ins = $mi->prepare(
                                'INSERT OR IGNORE INTO topic_index
                                 (id, uid, title, create_time, last_reply_time, view_count, reply_count,
                                  status, category_id, is_pinned, is_highlighted, color, reply_to_view,
                                  deleted_at, bucket_path, excerpt, excerpt_images)
                                 VALUES (:id, :uid, :title, :ct, :lrt, :vc, :rc, :st, :cid, :pin, :hl,
                                         :color, :rtv, :del, :bp, :ex, :ei)'
                            );
                            $ins->execute($params);
                            $insertedTopics += $ins->rowCount(); // OR IGNORE 跳过已存在 → 只计入实际补齐
                        }
                    }
                }

                // ---- reply → reply_index 回补 ----
                $stmt = $bdb->query('SELECT * FROM reply');
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach (array_chunk($rows, 500) as $chunk) {
                    foreach ($chunk as $r) {
                        $params = [
                            ':id' => (int)$r['id'], ':pid' => (int)($r['pid'] ?? 0),
                            ':uid' => (int)($r['uid'] ?? 0), ':ct' => (int)($r['create_time'] ?? 0),
                            ':ut' => (int)($r['update_time'] ?? 0), ':st' => (int)($r['status'] ?? 0),
                            ':bp' => $relPath,
                        ];
                        if ($dryRun) {
                            $exists = $mi->prepare('SELECT 1 FROM reply_index WHERE id = :id');
                            $exists->execute([':id' => $params[':id']]);
                            if ($exists->fetchColumn() === false) $insertedReplies++;
                        } else {
                            $ins = $mi->prepare(
                                'INSERT OR IGNORE INTO reply_index (id, pid, uid, create_time, update_time, status, bucket_path)
                                 VALUES (:id, :pid, :uid, :ct, :ut, :st, :bp)'
                            );
                            $ins->execute($params);
                            $insertedReplies += $ins->rowCount();
                        }
                    }
                }
            } catch (\Throwable $e) {
                \error_log('SplitDB 索引回补: 处理桶失败 ' . $relPath . ': ' . $e->getMessage());
                echo "  [warn] 处理桶失败（已跳过，见 error_log）: {$relPath}\n";
            }
        }
    }
} catch (\Throwable $e) {
    \error_log('SplitDB 索引回补异常: ' . $e->getMessage());
    echo "索引回补异常，见 error_log。\n";
    exit(1);
}

echo ($dryRun ? '[dry-run] 预览：' : '索引回补完成：')
    . "扫描桶 {$scannedBuckets} 个，"
    . "待补/补齐 topic_index {$insertedTopics} 行，reply_index {$insertedReplies} 行。\n";
exit(0);
