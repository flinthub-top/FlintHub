<?php
/**
 * FlintHub 1.0 (SplitDB) — extern/ 外置正文合并为 .bin 分块 + 懒加载 .idx 索引
 *
 * 路径规则：.bin/.idx 不放独立目录，严格按 ShardRouter 原路径规则
 *   保留在原本的 extern/{年}/{季度}/{桶}/ 目录下，与 .txt 同目录：
 *     extern/2026/Q3/12/t12000.bin   +   t12000.idx
 *   批次号 = floor(ID/1000)，.bin/.idx 文件名 = 类型前缀 + 批次号 × 1000
 *   （主题 ID 12000~12999 → t12000.bin + t12000.idx；回复 → r12000.bin + r12000.idx；
 *   类型前缀隔离主题/回复 ID 重叠区间，P0-1）。
 *   内容格式：[4字节大端长度标头][正文内容]...；.idx = JSON {ID: 偏移量}（懒加载）。
 *   生成成功后，删除同目录下的 .txt。
 *
 * 红线保障（严格按序）：
 *   1. 每 (目录, 批次) 组：先原子写 .bin → 再原子写 .idx → 最后才删同目录该批 .txt
 *   2. 单点失败不崩溃：单个文件读取失败/组失败仅记录日志，跳过继续
 *   3. 迁移结束自动校验：每批 .idx 记录数 == 该组成功删除的 .txt 数
 *   4. 断点续跑：.bin/.idx 已存在的组 → 追加新文件并合并 .idx（不丢已迁移条目）
 *   5. 启动自愈：检测到旧版独立目录 data/extern/bin/（早期误产物）→ 先恢复缺失 .txt 再删除
 *
 * 用法：
 *   php cli/migrate_extern_to_bin.php --dry-run   # 扫描预览（不写库不删文件）
 *   php cli/migrate_extern_to_bin.php             # 正式迁移（幂等，可断点重跑）
 *   php cli/migrate_extern_to_bin.php --verify    # 校验 .idx 记录总数与残留 .txt
 *
 * 读路径配套：ExternStorage::read() 已支持 .bin/.idx 优先读取，
 *   .bin 存在但 .idx 缺失（或 ID 不在 idx）自动回退读取 .txt —— 前台永不 404。
 *
 * @package app\cli
 */

require_once __DIR__ . '/_guard.php'; // Web 直访守卫（CLI-only）
require_once __DIR__ . '/../app/Core/Autoloader.php';

use app\SplitDB\Schema;
use app\SplitDB\ShardRouter;

/** 批次号 → .bin/.idx 文件名（批次号 × 1000，如批次 12 → 12000.bin） */
function batchBaseName(int $batch): int
{
    return $batch * 1000;
}

/** 绝对路径转正斜杠 */
function slashPath(string $p): string
{
    return str_replace('\\', '/', $p);
}

/**
 * 递归扫描 extern/ 下命名空间 .txt（t{id}.txt / r{id}.txt，P0-1）
 * @return array<string,array<string,array<int,string>>> dir => (type => (ID => 绝对路径))，
 * 按路径升序、类型、ID 升序
 */
function scanTxtFiles(string $dataPath): array
{
    $out = [];
    $externRoot = $dataPath . '/extern';
    if (!is_dir($externRoot)) {
        return [];
    }
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($externRoot, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'txt') continue;
        $name = $f->getBasename('.txt');
        // 只收命名空间文件 t{id}.txt / r{id}.txt；裸名 {id}.txt 属存量，
        // 先由 cli/migrate_extern_namespace.php 重命名后再合并
        if (!preg_match('/^([tr])(\d+)$/', $name, $m)) continue;
        $type = $m[1] === 'r' ? 'reply' : 'topic';
        $id = (int)$m[2];
        $dir = slashPath(dirname($f->getPathname()));
        $out[$dir][$type][$id] = slashPath($f->getPathname());
    }
    foreach ($out as &$byType) {
        foreach ($byType as &$ids) {
            ksort($ids);
        }
        unset($ids);
        ksort($byType);
    }
    unset($byType);
    ksort($out);
    return $out;
}

/**
 * 原子写文件（临时文件 + rename；Windows 目标已存在时先删旧再重试）
 */
function atomicWrite(string $target, string $content): bool
{
    $tmp = $target . '.' . uniqid('', true) . '.tmp';
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $target)) {
        @unlink($target);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return false;
        }
    }
    return true;
}

/**
 * 自愈：旧版独立目录 data/extern/bin/（早期误产物）→ 恢复缺失 .txt → 删除该目录
 *
 * 关键事实：帖子与回复的全局 ID 空间重叠（topic 1..192160 / reply 1..160778），
 * 同名 {id}.txt 可能同时存在于「帖子推导路径」与「回复推导路径」两个目录；
 * 旧迁移按唯一 ID 各删了一个文件，其内容在旧 .bin 中与 ID 一一对应。
 * 恢复规则：对每个旧 ID，推导帖子/回复两条候选路径，恰好缺失的那条回填旧 .bin 内容；
 * 两条都存在 → 无需恢复；两条都缺失 → 无法确定归属，跳过并计数。
 * 路径推导：extern/{年}/{季}/{桶}/{id}.txt —— 帖子用 topic_index.create_time + bucket_path，
 * 回复用 reply_index.create_time + bucket_path（回复落父帖桶，须用索引里的 bucket_path）。
 */
function restoreLegacyBin(string $dataPath): int
{
    $binDir = $dataPath . '/extern/bin';
    if (!is_dir($binDir)) {
        return 0;
    }
    echo "检测到旧版独立目录 extern/bin/（早期误产物），开始恢复缺失 .txt…\n";

    // 预载索引（帖子/回复分开，避免 ID 空间重叠互相覆盖）
    $mi = Schema::mainIndexDb();
    $topics = [];
    foreach ($mi->query('SELECT id, create_time, bucket_path FROM topic_index')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $topics[(int)$r['id']] = [(int)$r['create_time'], (string)$r['bucket_path']];
    }
    $replies = [];
    foreach ($mi->query('SELECT id, create_time, bucket_path FROM reply_index')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $replies[(int)$r['id']] = [(int)$r['create_time'], (string)$r['bucket_path']];
    }

    $candidateOf = function (int $id, array $meta, string $type): string {
        return ShardRouter::externRel($id, ShardRouter::quarter($meta[0]), ShardRouter::bucketFromPath($meta[1]), $type);
    };

    $restored = 0;
    $bothMissing = 0;
    $failed = [];
    foreach (glob($binDir . '/*.idx') ?: [] as $idxFile) {
        $batch = (int)basename($idxFile, '.idx');
        $binPath = $binDir . '/' . $batch . '.bin';
        $raw = @file_get_contents($idxFile);
        $idx = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($idx) || !is_file($binPath)) {
            $failed[] = "batch {$batch}: 旧 .idx/.bin 不可读";
            continue;
        }
        $fh = @fopen($binPath, 'rb');
        if (!$fh) {
            $failed[] = "batch {$batch}: 旧 .bin 打开失败";
            continue;
        }
        foreach ($idx as $id => $offset) {
            $id = (int)$id;
            $cands = [];
            if (isset($topics[$id]))  $cands[] = $candidateOf($id, $topics[$id], 'topic');
            if (isset($replies[$id])) $cands[] = $candidateOf($id, $replies[$id], 'reply');
            $cands = array_values(array_unique($cands));
            $missing = [];
            foreach ($cands as $rel) {
                if (!is_file($dataPath . '/' . $rel)) {
                    $missing[] = $rel;
                }
            }
            if (count($missing) !== 1) {
                if (count($missing) > 1) $bothMissing++;
                continue; // 0 条缺失（无需恢复）或 >1 条缺失（归属不明，跳过）
            }
            // 从旧 .bin 提取正文
            if (@fseek($fh, (int)$offset) !== 0) {
                $failed[] = "id {$id}: 旧 .bin seek 失败";
                continue;
            }
            $lenBytes = @fread($fh, 4);
            if (strlen($lenBytes) !== 4) {
                $failed[] = "id {$id}: 旧 .bin 长度头缺失";
                continue;
            }
            $len = unpack('N', $lenBytes)[1];
            $content = @fread($fh, $len);
            if ($len <= 0 || $len > 16 * 1024 * 1024 || $content === false) {
                $failed[] = "id {$id}: 旧 .bin 内容读取异常";
                continue;
            }
            $abs = $dataPath . '/' . $missing[0];
            $dir = dirname($abs);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            if (@file_put_contents($abs, $content, LOCK_EX) !== false) {
                $restored++;
            } else {
                $failed[] = "id {$id}: 写回 .txt 失败 ({$missing[0]})";
            }
            if ($restored % 20000 === 0) {
                echo "  恢复进度：{$restored} 个…\n";
            }
        }
        fclose($fh);
    }

    // 恢复完成才删除旧目录
    foreach (glob($binDir . '/*.bin') ?: [] as $f) { @unlink($f); }
    foreach (glob($binDir . '/*.idx') ?: [] as $f) { @unlink($f); }
    @rmdir($binDir);

    echo "自愈完成：恢复 .txt {$restored} 个，旧 extern/bin/ 已删除。\n";
    if ($bothMissing > 0) {
        echo "  ⚠️ 双候选均缺失 {$bothMissing} 条（无法确定归属，跳过）。\n";
    }
    if (!empty($failed)) {
        echo "  ⚠️ 恢复异常 " . count($failed) . " 条（已跳过）：\n";
        foreach (array_slice($failed, 0, 10) as $e) {
            echo "    - {$e}\n";
        }
    }
    return $restored;
}

/**
 * 处理单个 (目录, 类型, 批次) 组：写 .bin + .idx（存在则追加合并），成功后才删除该组 .txt
 * .bin/.idx 文件名带类型前缀（t{base}.bin / r{base}.bin，P0-1），隔离主题/回复 ID 重叠区间。
 *
 * @return array{dir:string,type:string,batch:int, scanned:int, indexed:int, deleted:int, skipped:int, bytes:int}
 */
function processGroup(string $dir, string $type, int $batch, array $files, bool $dryRun, array &$errors): array
{
    $base = batchBaseName($batch);
    $prefix = ShardRouter::typePrefix($type);
    $binPath = $dir . '/' . $prefix . $base . '.bin';
    $idxPath = $dir . '/' . $prefix . $base . '.idx';

    // 已迁移条目（断点续跑合并基础）
    $existingIdx = [];
    if (!$dryRun && is_file($idxPath)) {
        $raw = @file_get_contents($idxPath);
        $dec = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($dec)) {
            $existingIdx = array_map('intval', $dec);
        }
    }

    $result = ['dir' => $dir, 'type' => $type, 'batch' => $batch, 'scanned' => count($files), 'indexed' => 0, 'deleted' => 0, 'skipped' => 0, 'bytes' => 0];
    if ($dryRun) {
        $bytes = 0;
        foreach ($files as $path) {
            $bytes += (int)@filesize($path);
        }
        $result['bytes'] = $bytes;
        return $result;
    }

    // 1. 写 .bin：新文件从尾部追加（合并重跑时旧偏移保持有效）
    // 追加偏移不能用 fopen('ab') 后的 ftell()——Windows PHP 在追加模式下 ftell 返回 0
    // （而非文件末尾），会把所有追加条目记成偏移 0/整体偏移少"已有文件大小"，导致帖子串读。
    // 改用 filesize() 计算真实追加起点，再按写入字节数本地累加（跨平台可靠）。
    clearstatcache(true, $binPath);
    $appendOffset = is_file($binPath) ? (int)filesize($binPath) : 0;
    $fh = @fopen($binPath, 'ab');
    if (!$fh) {
        $errors[] = "{$dir}: 无法打开 .bin 写入 ({$binPath})";
        return $result;
    }
    $newIdx = [];
    foreach ($files as $id => $path) {
        if (isset($existingIdx[$id])) {
            $result['indexed']++; // 已在索引中（上次残留 .txt）→ 直接删
            continue;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            $result['skipped']++; // 读取失败：不写入、不删除（单点失败跳过）
            $errors[] = "{$dir}: 读取失败，保留文件 id={$id} ({$path})";
            continue;
        }
        $offset = $appendOffset;
        @fwrite($fh, pack('N', strlen($content)));
        @fwrite($fh, $content);
        $newIdx[$id] = $offset;
        $appendOffset += 4 + strlen($content);
        $result['bytes'] += strlen($content);
        $result['indexed']++;
    }
    if (!@fflush($fh) || !@fclose($fh)) {
        $errors[] = "{$dir}: .bin 落盘失败（批次 {$base}）";
        return $result; // 不写 .idx、不删 .txt（红线）
    }

    // 2. 写 .idx（合并旧条目 + 新增偏移，JSON）
    $mergedIdx = $existingIdx + $newIdx;
    if (!atomicWrite($idxPath, json_encode($mergedIdx, JSON_UNESCAPED_UNICODE))) {
        $errors[] = "{$dir}: .idx 写入失败，保留 .txt（批次 {$base}）";
        return $result; // 红线：.idx 未成功则不删 .txt
    }

    // 3. 红线通过后才删同目录该批 .txt（仅删已入索引的）
    foreach ($files as $id => $path) {
        if (isset($mergedIdx[$id]) && @unlink($path)) {
            $result['deleted']++;
        }
    }

    return $result;
}

// ============================================================
// 执行入口
// ============================================================
$dryRun = false;
$verify = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') $dryRun = true;
    elseif ($arg === '--verify') $verify = true;
}

$dataPath = ShardRouter::dataPath();

if ($verify) {
    // 校验：.idx 记录总数 vs 残留 .txt（.idx 按目录分布）
    $idxTotal = 0;
    $idxFiles = [];
    $externRoot = $dataPath . '/extern';
    if (is_dir($externRoot)) {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($externRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'idx') continue;
            $raw = @file_get_contents($f->getPathname());
            $dec = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($dec)) {
                $idxTotal += count($dec);
                $idxFiles[] = slashPath($f->getPathname());
            }
        }
        sort($idxFiles);
    }
    $txtTotal = 0;
    foreach (scanTxtFiles($dataPath) as $dir => $ids) {
        $txtTotal += count($ids);
    }
    echo "迁移校验：\n";
    echo "  .idx 文件数：" . count($idxFiles) . "（记录总数 {$idxTotal}）\n";
    echo "  残留 .txt 总数：{$txtTotal}\n";
    if ($txtTotal === 0) {
        echo "  ✅ 全部迁移完成，无残留 .txt。\n";
        exit(0);
    }
    echo "  ⚠️ 仍有 {$txtTotal} 个 .txt 未迁移（新帖或上次跳过），可重跑迁移脚本。\n";
    exit($txtTotal === 0 ? 0 : 1);
}

// 自愈：旧版 extern/bin/ 误产物先恢复再清理
if (!$dryRun) {
    restoreLegacyBin($dataPath);
}

// 裸名存量计数（命名空间迁移前文件 {id}.txt / {base}.bin，需先跑迁移脚本再合并）
$legacyTxt = 0;
$legacyBin = 0;
$externRoot = $dataPath . '/extern';
if (is_dir($externRoot)) {
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($externRoot, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $ext = $f->getExtension();
        if ($ext === 'txt' && preg_match('/^\d+$/', $f->getBasename('.txt'))) $legacyTxt++;
        elseif ($ext === 'bin' && preg_match('/^\d+$/', $f->getBasename('.bin'))) $legacyBin++;
    }
}

// 扫描 + 按 (目录, 类型, 批次) 分组
$byDir = scanTxtFiles($dataPath);
$groups = [];
foreach ($byDir as $dir => $byType) {
    foreach ($byType as $type => $ids) {
        foreach ($ids as $id => $path) {
            $groups[$dir . '|' . $type . '|' . intdiv($id, 1000)][] = $id;
        }
    }
}
ksort($groups);

$totalFiles = 0;
foreach ($byDir as $byType) {
    foreach ($byType as $ids) {
        $totalFiles += count($ids);
    }
}
echo "扫描 extern/：共 {$totalFiles} 个 .txt、" . count($byDir) . " 个目录、" . count($groups) . " 个(目录,批次)组（" . ($dryRun ? "dry-run 预览" : "正式迁移") . "）\n";
if ($totalFiles === 0) {
    if ($legacyTxt > 0 || $legacyBin > 0) {
        echo "⚠ 检测到裸名存量文件：.txt {$legacyTxt} 个 / .bin 块 {$legacyBin} 个（命名空间迁移前）。\n";
        echo "   请先执行 cli/migrate_extern_namespace.php（无 CLI 时浏览器访问站点根目录 upgrade_p115.php）完成迁移后再合并，否则旧帖正文不可读。\n";
    } else {
        echo "无待迁移文件，结束。\n";
    }
    exit(0);
}
if ($legacyTxt > 0 || $legacyBin > 0) {
    echo "⚠ 同时存在裸名存量文件（.txt {$legacyTxt} / .bin {$legacyBin}）：本次仅合并已带 t/r 前缀文件；裸名文件请先执行命名空间迁移再合并。\n";
}

$errors = [];
$grand = ['scanned' => 0, 'indexed' => 0, 'deleted' => 0, 'skipped' => 0, 'bytes' => 0];
$t0 = microtime(true);
$done = 0;

foreach ($groups as $key => $ids) {
    // key 结构：{dir}|{type}|{batch}（P0-1 起含类型维度，拆三段还原）
    [$dir, $type, $batch] = explode('|', $key, 3);
    $files = [];
    foreach ($ids as $id) {
        $files[$id] = $byDir[$dir][$type][$id];
    }
    $r = processGroup($dir, $type, (int)$batch, $files, $dryRun, $errors);
    if ($dryRun) {
        printf("  %s batch %-4d 文件 %-5d 合计 %.1f KB\n", str_replace($dataPath . '/extern/', 'extern/', $dir), (int)$batch, $r['scanned'], $r['bytes'] / 1024);
    } else {
        printf("  %s batch %-4d 扫描 %-4d 索引 %-4d 删除 %-4d 跳过 %d\n",
            str_replace($dataPath . '/extern/', 'extern/', $dir), (int)$batch, $r['scanned'], $r['indexed'], $r['deleted'], $r['skipped']);
    }
    foreach (['scanned', 'indexed', 'deleted', 'skipped', 'bytes'] as $k) {
        $grand[$k] += $r[$k];
    }
    $done += $r['scanned'];
    if (!$dryRun && $done % 50000 === 0) {
        printf("进度：%d/%d 文件（%.1fs）\n", $done, $totalFiles, microtime(true) - $t0);
    }
}

$elapsed = round(microtime(true) - $t0, 2);
if ($dryRun) {
    echo "\n[dry-run] 预览完成：{$totalFiles} 个文件，合计约 " . round($grand['bytes'] / 1048576, 2) . " MB，预计 " . count($groups) . " 个 .bin 文件。\n";
    exit(0);
}

// 自动校验：.idx 记录数 vs 删除数
echo "\n迁移结果（耗时 {$elapsed}s）：\n";
echo "  扫描 {$grand['scanned']} / 索引 {$grand['indexed']} / 删除 {$grand['deleted']} / 跳过 {$grand['skipped']}\n";
if (!empty($errors)) {
    echo "  ⚠️ 异常 " . count($errors) . " 条（已跳过不中断）：\n";
    foreach (array_slice($errors, 0, 20) as $e) {
        echo "    - {$e}\n";
    }
}
if ($grand['indexed'] === $grand['deleted'] && $grand['skipped'] === 0) {
    echo "  ✅ 校验通过：.idx 记录数 == 删除 .txt 数（{$grand['indexed']}），无跳过。\n";
    exit(0);
}
echo "  ❌ 校验未完全一致：.idx {$grand['indexed']} / 删除 {$grand['deleted']} / 跳过 {$grand['skipped']}（可重跑续传）。\n";
exit(1);
