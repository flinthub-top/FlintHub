<?php
/**
 * FlintHub 1.0 (SplitDB) — 瘦身 business.sqlite：删除 search_index 表 + VACUUM
 *
 * 前置条件：search_index 存量已全部迁移到季度分文件（cli/migrate_search_quarters.php），
 * 本脚本执行前自动校验「季度文件合计 == business.search_index 行数」，不一致则拒绝执行。
 *
 * 用法：
 *   php cli/shrink_business.php --check   # 仅校验迁移完整性（不删表）
 *   php cli/shrink_business.php           # 校验通过后：DROP TABLE + VACUUM + 报告瘦身效果
 *
 * 删表不可逆：迁移校验 100% 一致是本脚本放行的硬性前提。
 *
 * @package app\cli
 */

require_once __DIR__ . '/_guard.php'; // Web 直访守卫（CLI-only）
require_once __DIR__ . '/../app/Core/Autoloader.php';

use app\SplitDB\Schema;
use app\SplitDB\SearchIndexStore;
use app\SplitDB\ShardRouter;

$checkOnly = in_array('--check', array_slice($argv, 1), true);

$biz = Schema::businessDb();
$root = ShardRouter::dataPath();

$hasTable = (int)$biz->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='search_index'")->fetchColumn() === 1;
if (!$hasTable) {
    echo "business.sqlite 已无 search_index 表（可能已瘦身），退出。\n";
    exit(0);
}

$bizTotal = (int)$biz->query('SELECT COUNT(*) FROM search_index')->fetchColumn();
$fileTotal = SearchIndexStore::countAll();
$fileSize = filesize($root . '/meta/business.sqlite');

echo "迁移校验：business.search_index {$bizTotal} 行 vs 季度文件合计 {$fileTotal} 行\n";
if ($bizTotal !== $fileTotal) {
    fwrite(STDERR, "❌ 行数不一致，拒绝删表！请先完成迁移（php cli/migrate_search_quarters.php）并复查。\n");
    exit(1);
}
echo "✅ 迁移完整，可以安全删表。\n";

if ($checkOnly) {
    echo "（--check 模式：未执行删表）\n";
    exit(0);
}

// 删表 + VACUUM（VACUUM 不能在事务内；执行前先 checkpoint WAL 再删）
$biz->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$biz->exec('DROP TABLE IF EXISTS search_index');
$start = microtime(true);
$biz->exec('VACUUM');
$elapsed = round(microtime(true) - $start, 2);

$newSize = filesize($root . '/meta/business.sqlite');
$savedMB = round(($fileSize - $newSize) / 1048576, 1);

echo "已删除 search_index 并 VACUUM（耗时 {$elapsed}s）。\n";
printf("business.sqlite：%.1f MB → %.1f MB（释放 %.1f MB）\n",
    $fileSize / 1048576, $newSize / 1048576, $savedMB);

// 最终确认：表已不存在 + 其余表完好
$tables = $biz->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
echo "business 剩余业务表 {$tables} 张（users/categories/blogs 等不受影响）。\n";
exit(0);
