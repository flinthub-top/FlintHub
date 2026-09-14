<?php
/**
 * FlintHub 1.0 (SplitDB) — search_index 存量数据迁移：business.sqlite → 季度分文件
 *
 * 迁移源：business.sqlite.search_index（旧单表，约 244 万行）
 * 迁移目标：data/meta/search/search_{YYYYQn}.sqlite（按 created_at 所在季度路由）
 *
 * 用法：
 *   php cli/migrate_search_quarters.php --dry-run    # 预览：总行数 + 各季度分布（不写库）
 *   php cli/migrate_search_quarters.php              # 全量迁移（断点续跑，可重复执行）
 *   php cli/migrate_search_quarters.php --from-scratch  # 忽略进度强制重跑
 *   php cli/migrate_search_quarters.php --verify     # 校验：季度合计 == business 原行数
 *
 * 性能与断点：
 *   - keyset 游标分块（WHERE id > last）避免 OFFSET 全表扫描；每块按季度分组，
 *     各季度文件单事务 + 单行预编译（与 seed phaseSearch 同构提速）
 *   - 加载期临时摘除非唯一索引（SearchIndexStore::dropFastIndexes，唯一索引保留），
 *     收尾 ensureSchema 补回 —— 大幅降低每行 INSERT 的索引维护成本
 *   - 进度存 data/runtime/search_migrate_progress.json（lastId），被杀/中断后重跑自动续传
 * 迁移完成后仍需执行「瘦身 business.sqlite」步骤（删除 search_index 表 + VACUUM），
 * 见 cli/shrink_business.php。
 *
 * @package app\cli
 */

require_once __DIR__ . '/_guard.php'; // Web 直访守卫（CLI-only）
require_once __DIR__ . '/../app/Core/Autoloader.php';

use app\SplitDB\Schema;
use app\SplitDB\SearchIndexStore;
use app\SplitDB\ShardRouter;

const CHUNK = 50000;

/** 源表是否存在 */
function hasSourceTable(): bool
{
    $biz = Schema::businessDb();
    return (int)$biz->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='search_index'")->fetchColumn() === 1;
}

/** 各季度文件行数（迁移完成后展示用） */
function quarterFileCounts(): array
{
    $out = [];
    foreach (SearchIndexStore::allQuarters() as $q) {
        $out[$q] = (int)SearchIndexStore::db($q)->query('SELECT COUNT(*) FROM search_index')->fetchColumn();
    }
    return $out;
}

/**
 * 全量迁移（支持断点续跑；dry-run 仅预览分布不写库）
 * @return array{total:int, written:int, byQuarter:array<string,int>}
 */
function migrateSearchIndex(bool $dryRun = false, bool $fromScratch = false): array
{
    $biz = Schema::businessDb();
    if (!hasSourceTable()) {
        fwrite(STDERR, "business.sqlite 无 search_index 表（可能已迁移或从未建立），跳过。\n");
        exit(0);
    }

    $total = (int)$biz->query('SELECT COUNT(*) FROM search_index')->fetchColumn();
    echo "迁移源：business.search_index 共 {$total} 行\n";

    // 断点进度
    $progFile = ShardRouter::dataPath() . '/runtime/search_migrate_progress.json';
    $prog = ['lastId' => 0, 'done' => 0];
    if (!$dryRun && !$fromScratch && is_file($progFile)) {
        $tmp = json_decode((string)file_get_contents($progFile), true);
        if (is_array($tmp)) { $prog = array_merge($prog, $tmp); }
    }
    if ($fromScratch && is_file($progFile)) {
        @unlink($progFile);
    }

    if ($dryRun) {
        echo "[dry-run] 仅预览分布，不写库…\n";
    } elseif ((int)$prog['lastId'] > 0) {
        echo "断点续跑：从 search_index.id > " . (int)$prog['lastId'] . " 继续（已完成 " . (int)$prog['done'] . " 行）\n";
    }

    $byQuarter = [];
    $lastId = (int)$prog['lastId'];
    $done = (int)$prog['done'];
    $t0 = microtime(true);

    $ins = [];     // quarter => 预编译句柄
    $txOpen = [];  // quarter => bool
    $pdoMap = [];  // quarter => PDO（dbBulk 裸连接，加载期无索引维护）

    $txBegin = function (string $q) use (&$txOpen, &$pdoMap) {
        if (empty($txOpen[$q])) {
            $pdoMap[$q]->exec('BEGIN');
            $txOpen[$q] = true;
        }
    };
    $txCommit = function (string $q) use (&$txOpen, &$pdoMap) {
        if (!empty($txOpen[$q])) {
            $pdoMap[$q]->exec('COMMIT');
            $txOpen[$q] = false;
        }
    };

    while (true) {
        $rows = $biz->query(
            "SELECT id, token, type, target_id, weight, created_at
             FROM search_index WHERE id > " . (int)$lastId . " ORDER BY id LIMIT " . CHUNK
        )->fetchAll(\PDO::FETCH_ASSOC);
        if (empty($rows)) break;

        // 按季度分组（created_at → 季度，空/异常防御性落当前季度，与 SearchIndexStore::quarterOf 一致）
        $groups = [];
        foreach ($rows as $r) {
            $q = SearchIndexStore::quarterOf((string)($r['created_at'] ?? ''));
            $groups[$q][] = $r;
        }

        foreach ($groups as $q => $g) {
            if (!isset($byQuarter[$q])) $byQuarter[$q] = 0;
            if ($dryRun) {
                $byQuarter[$q] += count($g);
                continue;
            }
            if (!isset($pdoMap[$q])) {
                // 裸连接 + 建表 + 摘非唯一索引（唯一索引保留，保证 OR IGNORE 幂等语义）
                $pdoMap[$q] = SearchIndexStore::dbBulk($q);
                SearchIndexStore::createTable($pdoMap[$q]);
                SearchIndexStore::dropFastIndexes($pdoMap[$q]);
            }
            $txBegin($q);
            if (!isset($ins[$q])) {
                $ins[$q] = $pdoMap[$q]->prepare(
                    'INSERT OR IGNORE INTO search_index (token, type, target_id, weight, created_at)
                     VALUES (:t, :tp, :id, :w, :ct)'
                );
            }
            foreach ($g as $r) {
                $ins[$q]->execute([
                    ':t'  => (string)$r['token'],
                    ':tp' => (int)$r['type'],
                    ':id' => (int)$r['target_id'],
                    ':w'  => (int)$r['weight'],
                    ':ct' => (string)($r['created_at'] ?? ''),
                ]);
            }
            $byQuarter[$q] += count($g);
        }
        // 每块提交全部季度文件事务（长事务断点安全）
        foreach (array_keys($groups) as $q) {
            $txCommit($q);
        }

        $lastId = (int)$rows[count($rows) - 1]['id'];
        $done += count($rows);

        if (!$dryRun) {
            // 提交完成后落进度（崩溃恢复：最多重做最后一个块，INSERT OR IGNORE 幂等）
            file_put_contents($progFile, json_encode(['lastId' => $lastId, 'done' => $done]), LOCK_EX);
        }
        if ($done % (CHUNK * 5) === 0 || $done === $total) {
            printf("迁移中：%d/%d 行（%.1fs）\n", $done, $total, microtime(true) - $t0);
        }
    }

    // 收尾：补回非唯一索引 + 校验
    if (!$dryRun) {
        foreach (array_keys($pdoMap) as $q) {
            SearchIndexStore::ensureSchema($pdoMap[$q]); // 幂等补回全部索引
        }
        @unlink($progFile);

        $fileTotal = SearchIndexStore::countAll();
        $byType = [1 => SearchIndexStore::countByType(1), 2 => SearchIndexStore::countByType(2)];
        $elapsed = round(microtime(true) - $t0, 2);
        echo "迁移完成：季度文件合计 {$fileTotal} 行（帖子 {$byType[1]} / 博客 {$byType[2]}），耗时 {$elapsed}s\n";
        echo "各季度文件：\n";
        foreach (quarterFileCounts() as $q => $n) {
            echo "  search_{$q}.sqlite: {$n} 行\n";
        }
        if ($fileTotal === $total) {
            echo "✅ 行数一致（business {$total} == 季度文件 {$fileTotal}），迁移完整。\n";
        } else {
            echo "⚠️ 行数不一致（business {$total} vs 季度文件 {$fileTotal}），请用 --verify 复查。\n";
        }
    } else {
        ksort($byQuarter);
        $elapsed = round(microtime(true) - $t0, 2);
        echo "[dry-run] 各季度分布：\n";
        foreach ($byQuarter as $q => $n) {
            echo "  {$q}: {$n} 行\n";
        }
        echo "合计 {$done} 行，耗时 {$elapsed}s\n";
    }

    return ['total' => $total, 'written' => $done, 'byQuarter' => $byQuarter];
}

// ============================================================
// 执行入口
// ============================================================
$dryRun = false;
$verify = false;
$fromScratch = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') $dryRun = true;
    elseif ($arg === '--verify') $verify = true;
    elseif ($arg === '--from-scratch') $fromScratch = true;
}

if ($verify) {
    if (!hasSourceTable()) {
        fwrite(STDERR, "business.search_index 已不存在，无法对照校验。\n");
        exit(1);
    }
    $bizTotal = (int)Schema::businessDb()->query('SELECT COUNT(*) FROM search_index')->fetchColumn();
    $fileTotal = SearchIndexStore::countAll();
    $byType = [1 => SearchIndexStore::countByType(1), 2 => SearchIndexStore::countByType(2)];
    $quarters = SearchIndexStore::allQuarters();
    echo "迁移校验：\n";
    echo "  季度文件：" . (empty($quarters) ? '（无）' : implode(', ', $quarters)) . "\n";
    echo "  business.search_index 原行数：{$bizTotal}\n";
    echo "  季度文件合计：{$fileTotal}（帖子 {$byType[1]} / 博客 {$byType[2]}）\n";
    foreach (quarterFileCounts() as $q => $n) {
        echo "    search_{$q}.sqlite: {$n} 行\n";
    }
    if ($fileTotal === $bizTotal) {
        echo "  ✅ 行数一致，迁移完整。\n";
        exit(0);
    }
    echo "  ❌ 行数不一致（差异 " . ($fileTotal - $bizTotal) . "），请排查后重跑迁移（断点续跑幂等）。\n";
    exit(1);
}

migrateSearchIndex($dryRun, $fromScratch);
exit(0);
