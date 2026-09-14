<?php
/**
 * FlintHub — 存量帖子摘要回填（方案 A 落库，第二步）
 *
 * 用途：为已发布（存量）帖子补齐 topic 桶行与 main_index.topic_index 的
 *       excerpt / excerpt_images 列，使 attachExcerpts 可优先读 DB 摘要（零文件读）。
 *       新帖发帖/编辑已由 Thread::insert / Thread::update 自动写库，本脚本只补存量。
 *
 * 用法：
 *   php cli/backfill_excerpts.php            # 统计待回填并执行回填（边跑边打印进度）
 *   php cli/backfill_excerpts.php --dry-run  # 只统计/演练，不写库
 *
 * 说明：
 *   - 幂等：只处理 extern_path 非空且 excerpt 为空的帖子，可重复运行；
 *   - 摘要口径与 Thread::extractExcerptContent() 完全一致（纯文本 ≤200 字 + 前 9 张安全图）；
 *   - 逐桶直接 PDO（不经 DBFactory 池，避免 LRU 句柄抖动），读完即关；
 *   - 每个桶先幂等 ALTER 补摘要列（老桶表可能缺列），再回填；
 *   - 回填后建议清理列表页静态缓存（data/runtime/pages/*.html）以立即生效。
 *
 * @package app\cli
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../app/Core/Autoloader.php';

use app\Helpers\Settings;
use app\Models\Category;
use app\Models\Thread;
use app\SplitDB\ExternStorage;
use app\SplitDB\Schema;

$dryRun = in_array('--dry-run', $argv, true);
$root = rtrim(SPLITDB_DATA_PATH, '/\\');

// ---- 收集全部桶文件（active + archive），按路径去重、稳定排序 ----
$bucketFiles = [];
foreach ([$root . '/bucket/active/*/*.sqlite', $root . '/bucket/archive/*/*.sqlite'] as $pattern) {
    foreach (glob($pattern) ?: [] as $f) {
        $bucketFiles[$f] = true;
    }
}
ksort($bucketFiles);

// 确保 main_index.topic_index 已含摘要列，否则主索引 UPDATE 会报 no such column
// （老库 topic_index 缺列时 ensureMainIndex 需经 Database bootstrap 才补，CLI 直连须主动补）
$mi = Schema::mainIndexDb();
$miCols = $mi->query('PRAGMA table_info(topic_index)')->fetchAll(PDO::FETCH_COLUMN, 1);
foreach (['excerpt' => "TEXT DEFAULT ''", 'excerpt_images' => "TEXT DEFAULT ''"] as $col => $def) {
    if (!in_array($col, $miCols, true)) {
        $mi->exec("ALTER TABLE topic_index ADD COLUMN {$col} {$def}");
    }
}
$mainStmt = $mi->prepare('UPDATE topic_index SET excerpt = :ex, excerpt_images = :im WHERE id = :id');

$done = 0;
$skipped = 0;
$failed = 0;

foreach (array_keys($bucketFiles) as $file) {
    echo "[桶] {$file}\n";
    try {
        $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (\Throwable $e) {
        echo "  !! 打开失败: {$e->getMessage()}\n";
        $failed++;
        continue;
    }
    $pdo->exec('PRAGMA busy_timeout = 5000;');

    // 幂等补列（老桶表可能无 excerpt 列）
    try {
        $cols = $pdo->query('PRAGMA table_info(topic)')->fetchAll(PDO::FETCH_COLUMN, 1);
        foreach (['excerpt' => "TEXT DEFAULT ''", 'excerpt_images' => "TEXT DEFAULT ''"] as $col => $def) {
            if (!in_array($col, $cols, true)) {
                $pdo->exec("ALTER TABLE topic ADD COLUMN {$col} {$def}");
            }
        }
    } catch (\Throwable $e) {
        echo "  !! 桶 topic 补列失败: {$e->getMessage()}\n";
        $pdo = null;
        $failed++;
        continue;
    }

    // 待回填：有正文且摘要为空；或摘要是已落库的脏数据（字面实体 &amp;/&nbsp;，双重编码源）→ 一并重刷为干净纯文本
    $rows = $pdo->query(
        "SELECT id, extern_path FROM topic WHERE (excerpt = '' OR excerpt LIKE '%&amp;%' OR excerpt LIKE '%&nbsp;%') AND trim(COALESCE(extern_path,'')) <> ''"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $tid = (int)$r['id'];
        $externPath = (string)$r['extern_path'];
        $content = '';
        $ok = true;
        try {
            $content = ExternStorage::read($root, $externPath, $tid, 'topic');
        } catch (\Throwable $e) {
            $ok = false;
        }
        if (!$ok || $content === '') {
            $skipped++;
            continue;
        }
        $ex = Thread::extractExcerptContent($content); // 纯文本 ≤200 + 前 9 张安全图
        $excerpt = $ex['plain'];
        $images = implode(',', $ex['images']);
        if ($excerpt === '' && $images === '') {
            $skipped++;
            continue;
        }

        if ($dryRun) {
            echo "  [演练] 帖子#{$tid} excerpt={$excerpt}\n";
            $done++;
            continue;
        }

        try {
            // 桶行
            $up = $pdo->prepare('UPDATE topic SET excerpt = :ex, excerpt_images = :im WHERE id = :id');
            $up->execute([':ex' => $excerpt, ':im' => $images, ':id' => $tid]);
            // main_index 同步（保持双写一致；连接延迟初始化）
            if ($mainStmt === null) {
                $mi = Schema::mainIndexDb();
                $mainStmt = $mi->prepare('UPDATE topic_index SET excerpt = :ex, excerpt_images = :im WHERE id = :id');
            }
            $mainStmt->execute([':ex' => $excerpt, ':im' => $images, ':id' => $tid]);
            $done++;
            if ($done % 50 === 0) {
                printf("  ...已完成 %d 帖\n", $done);
            }
        } catch (\Throwable $e) {
            echo "  !! 帖子#{$tid} 回填失败: {$e->getMessage()}\n";
            $failed++;
        }
    }
    $pdo = null;
    clearstatcache();
    unset($rows);
}

echo "=====================================\n";
if ($dryRun) {
    echo "演练结束（未写库）。待回填统计: 命中 {$done}，跳过 {$skipped}，桶失败 {$failed}\n";
} else {
    echo "回填完成: 成功 {$done}，跳过 {$skipped}，失败 {$failed}\n";
    echo "提示: 若列表页此前按摘要烘焙了静态缓存，请先清理 data/runtime/pages/*.html 再刷新验证。\n";
}
exit($failed > 0 ? 1 : 0);