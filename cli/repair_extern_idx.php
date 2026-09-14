<?php
/**
 * FlintHub 1.0 (SplitDB) — extern .bin/.idx 偏移表修复器（CLI 入口）
 *
 * 背景：V1.0.115 合并工具在 Windows 上 fopen('ab') 后 ftell() 返回 0（而非文件末尾），
 * 导致追加条目 .idx 偏移整体偏小 → 帖子读取串到其他正文（正文乱套）。
 * 本脚本基于 ExternIdxRepairer 按「链序 = ID 升序」不变式重建 .idx，幂等可重跑。
 *
 * 用法：
 *   php cli/repair_extern_idx.php --dry-run   # 仅预览将修复的块，不落盘
 *   php cli/repair_extern_idx.php             # 正式修复（先备份 .idx.orig 再原子写）
 *
 * 输出：块总数 / 已验证正确 / 已修复 / 跳过（待人工核对）/ 详情。
 *
 * @package app\cli
 */

require_once __DIR__ . '/_guard.php'; // Web 直访守卫（CLI-only）
require_once __DIR__ . '/../app/Core/Autoloader.php';

use app\SplitDB\ExternIdxRepairer;

$dryRun = in_array('--dry-run', $argv, true);

$result = ExternIdxRepairer::run(['dry_run' => $dryRun]);

echo "=== extern .idx 偏移表修复" . ($dryRun ? "（预览）" : "（正式）") . " ===\n";
echo "extern 根目录: " . ($result['extern_root'] ?? '(无 extern 目录)') . "\n";
echo "扫描到前缀块数: {$result['blocks']}\n";
echo "已验证正确: {$result['ok']}\n";
echo "已修复: {$result['fixed']}\n";
echo "跳过（待人工核对）: " . count($result['skipped']) . "\n";

foreach ($result['details'] as $d) {
    echo "  详情: {$d}\n";
}
foreach ($result['skipped'] as $s) {
    echo "  跳过: {$s}\n";
}

if ($result['skipped']) {
    echo "\n警告：存在跳过项，请按上方提示人工核对（.idx.orig 为修复前备份）。\n";
    exit(2);
}
if ($result['fixed'] === 0 && $result['ok'] === $result['blocks'] && $result['blocks'] > 0) {
    echo "\n全部块已验证正确，无需改动（幂等终态）。\n";
}
exit(0);
