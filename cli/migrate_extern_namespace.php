<?php
/**
 * FlintHub 1.0 (SplitDB) — 存量 extern 命名空间迁移（P0-1）CLI 入口
 *
 * 核心逻辑见 app/SplitDB/ExternNamespaceMigrator.php（CLI 与 upgrade_p115.php Web 入口共用）。
 * 本文件保留 CLI 调用面：--dry-run 预览 / --repair 仅列清单 / 无参正式迁移（幂等，可断点重跑）。
 *
 * 用法：
 *   php cli/migrate_extern_namespace.php --dry-run   # 预览将重命名的文件（不落盘）
 *   php cli/migrate_extern_namespace.php --repair    # 仅列出歧义/孤儿清单（不重命名）
 *   php cli/migrate_extern_namespace.php             # 正式迁移（幂等，可断点重跑）
 *
 * 退出码：0 = 全部处理干净（无歧义/孤儿/冲突）；1 = 存在待人工核对项。
 *
 * @package app\cli
 */

require_once __DIR__ . '/_guard.php'; // Web 直访守卫（CLI-only）
require_once __DIR__ . '/../app/Core/Autoloader.php';

use app\SplitDB\ExternNamespaceMigrator;

$dryRun = false;
$repair = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') $dryRun = true;
    elseif ($arg === '--repair') $repair = true;
}

$mode = $repair ? 'repair 模式' : ($dryRun ? 'dry-run 预览' : '正式迁移');
$result = ExternNamespaceMigrator::run(['dry_run' => $dryRun, 'repair' => $repair]);

if ($result['extern_root'] === null) {
    echo "extern/ 目录不存在，无需迁移。\n";
    exit(0);
}

echo "扫描 extern/：裸名 .txt " . $result['txt_scanned'] . " 个（已带前缀跳过 " . $result['txt_prefixed_skipped'] . "）/ 裸名 .bin 块 " . $result['bin_blocks'] . " 个"
    . "（" . $mode . "）\n";
if ($result['txt_scanned'] === 0 && $result['bin_blocks'] === 0) {
    echo "无待迁移裸名文件，结束。\n";
    exit(0);
}

echo "\n迁移结果：\n";
echo "  重命名 " . $result['renamed'] . " 个" . ($dryRun || $repair ? "（预览）" : "") . "\n";
if ($result['bin_blocks'] > 0 || $result['bin_split'] > 0 || $result['bin_renamed'] > 0) {
    echo "  .bin/.idx 归档块：重命名 " . $result['bin_renamed'] . " 个 / 拆分 " . $result['bin_split'] . " 个 / 已带前缀跳过 " . $result['bin_prefixed_skipped'] . " 个\n";
}
echo "  歧义 " . count($result['ambiguous']) . " 个 / 孤儿 " . count($result['orphans']) . " 个 / 冲突跳过 " . count($result['conflicts']) . " 个\n";

$lists = [
    '歧义' => $result['ambiguous'],
    '孤儿' => $result['orphans'],
    '冲突' => $result['conflicts'],
    '归档块跳过' => $result['bin_skipped'],
];
foreach ($lists as $label => $list) {
    if (!empty($list)) {
        echo "  —— {$label}清单（待人工核对）——\n";
        foreach (array_slice($list, 0, 50) as $msg) {
            echo "    - {$msg}\n";
        }
        if (count($list) > 50) {
            echo "    … 其余 " . (count($list) - 50) . " 条略（见上方统计）\n";
        }
    }
}

if ($result['renamed'] > 0 && !$dryRun && !$repair) {
    echo "\n提示：.txt 重命名完成后，可重跑 cli/migrate_extern_to_bin.php 将新前缀 .txt 合并为 .bin/.idx（迁移器已同步拆分存量归档块）。\n";
}

exit($result['dirty'] ? 1 : 0);
