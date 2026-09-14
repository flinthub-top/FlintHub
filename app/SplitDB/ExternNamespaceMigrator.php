<?php
/**
 * FlintHub 1.0 (SplitDB) — 存量 extern 命名空间迁移核心
 *
 * 背景：命名空间隔离上线前，主题/回复正文文件名为裸 ID：extern/{年}/{季}/{桶}/{id}.txt。
 *   主题与回复的全局 ID 序列各自从 1 起（区间重叠），裸名文件在同桶同季度下可能互相
 *   覆盖/串读。本类将存量裸名 {id}.txt 重命名为带类型前缀的 t{id}.txt / r{id}.txt；
 *   同时把 migrate_extern_to_bin.php 在命名空间改造前生成的**裸名 .bin/.idx 归档块**
 *   按类型拆分/重命名为 t{base}.bin/.idx 与 r{base}.bin/.idx，杜绝旧块与新数据串读。
 *
 * 归属判定（按 main_index，防歧义误判）：
 *   1. id 存在于 topic_index 且 topic_index.bucket_path 的桶号 == 文件所在目录桶号 → 主题 t{id}.txt
 *   2. 否则 id 存在于 reply_index 且 reply_index.bucket_path 的桶号 == 文件所在目录桶号 → 回复 r{id}.txt
 *      （回复正文落父帖所在桶，路径桶号 == reply_index.bucket_path（父帖桶）桶号，是正确口径）
 *   3. 索引命中但桶号不匹配，或 topic/reply 均命中同桶 → 记歧义，跳过待人工核对
 *   4. 两个索引都查不到 → 记孤儿，跳过待人工核对
 * 已带前缀（t{id}.txt / r{id}.txt / t{base}.bin / r{base}.bin 等）的文件跳过（幂等，可重跑）。
 * 目标名已存在（冲突）时跳过并记录，绝不覆盖已存在文件（防数据丢失）。
 *
 * .bin/.idx 归档块处理（延伸，本类覆盖）：
 *   裸名块 {base}.bin/.idx 按桶内混装主题+回复条目，逐条用上面的桶号口径分类：
 *     - 全主题 → 重命名 t{base}.bin/.idx；全回复 → 重命名 r{base}.bin/.idx；
 *     - 混装 → 拆分为 t{base}.* 与 r{base}.*（从旧块按偏移提取正文，重建两个前缀块）；
 *     - 任一条目歧义/孤儿 → 整块跳过待人工核对（不局部拆分，防误判）。
 *   先写新块（临时文件 + rename 原子落盘）成功后，才删除旧裸名块。
 *
 * 调用方：cli/migrate_extern_namespace.php（CLI）与 upgrade_p115.php（Web/CLI 双模式）。
 *
 * @package app\SplitDB
 */

namespace app\SplitDB;

class ExternNamespaceMigrator
{
    /** @var array<string,int> 目录桶号解析缓存（避免逐文件正则解析） */
    private static array $bucketCache = [];

    /**
     * 执行迁移（幂等，可断点重跑）。
     *
     * @param array $opts 可选键：
     *   - dry_run (bool)：仅预览将重命名的文件，不落盘
     *   - repair  (bool)：仅列出歧义/孤儿/冲突清单，不重命名
     * @return array 迁移汇总（统计 + 待人工核对清单），结构：
     *   [
     *     'extern_root' => string|null,   // extern 目录不存在时为 null（无需迁移）
     *     'mode' => 'formal'|'dry_run'|'repair',
     *     'txt_scanned' => int,           // 扫描到的裸名 .txt 数
     *     'txt_prefixed_skipped' => int,  // 已带前缀跳过数
     *     'bin_blocks' => int,            // 扫描到的裸名 .bin/.idx 块数
     *     'bin_prefixed_skipped' => int,
     *     'renamed' => int,               // 重命名（或预览）的 .txt 数
     *     'ambiguous' => string[],        // 索引命中但桶号不匹配（待人工核对）
     *     'orphans'   => string[],        // 两索引均查不到（待人工核对）
     *     'conflicts' => string[],        // 目标已存在/rename 失败（待人工核对）
     *     'bin_renamed' => int,
     *     'bin_split'   => int,
     *     'bin_skipped' => string[],      // 归档块跳过清单（待人工核对）
     *     'dirty' => bool,                // true = 存在待人工核对项
     *   ]
     */
    public static function run(array $opts = []): array
    {
        $dryRun = !empty($opts['dry_run']);
        $repair = !empty($opts['repair']);

        $result = [
            'extern_root' => null,
            'mode' => $repair ? 'repair' : ($dryRun ? 'dry_run' : 'formal'),
            'txt_scanned' => 0,
            'txt_prefixed_skipped' => 0,
            'bin_blocks' => 0,
            'bin_prefixed_skipped' => 0,
            'renamed' => 0,
            'ambiguous' => [],
            'orphans' => [],
            'conflicts' => [],
            'bin_renamed' => 0,
            'bin_split' => 0,
            'bin_skipped' => [],
            'dirty' => false,
        ];

        $dataPath = ShardRouter::dataPath();
        $externRoot = $dataPath . '/extern';
        if (!is_dir($externRoot)) {
            return $result; // extern_root 保持 null → 调用方提示"无需迁移"
        }
        $result['extern_root'] = $externRoot;

        // ---------- 预载索引（内存哈希，避免逐文件查询） ----------
        $mi = Schema::mainIndexDb($dataPath);
        $topics = [];
        foreach ($mi->query('SELECT id, bucket_path FROM topic_index')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $topics[(int)$r['id']] = (string)$r['bucket_path'];
        }
        $replies = [];
        foreach ($mi->query('SELECT id, bucket_path FROM reply_index')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $replies[(int)$r['id']] = (string)$r['bucket_path'];
        }

        // ---------- 扫描存量裸名 .txt（{id}.txt；跳过 .tmp 与已带前缀文件） ----------
        $files = []; // 绝对路径 => ['id'=>int, 'bucket'=>?int, 'rel'=>相对 DATA_PATH 路径]
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($externRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'txt') continue;
            // 用迭代器子路径计算相对 extern 的路径，避免文件系统返回短名/符号链接路径
            // 导致与 dataPath 前缀剥离失败（Windows 8.3 短路径等场景）
            $rel = 'extern/' . str_replace('\\', '/', $it->getSubPathname());
            $abs = $dataPath . '/' . $rel;
            // 跳过旧版误产物目录 extern/bin/（由 migrate_extern_to_bin.php 自愈处理）
            if (preg_match('#^extern/bin/#', $rel)) continue;
            $name = $f->getBasename('.txt');
            if (preg_match('/^[tr]\d+$/', $name)) { // 已带前缀，跳过
                $result['txt_prefixed_skipped']++;
                continue;
            }
            if (!preg_match('/^(\d+)$/', $name, $m)) {
                continue; // 非存量正文文件（如未知命名），不动
            }
            $id = (int)$m[1];
            $dir = dirname($rel); // extern/{年}/{季}/{桶}
            $bucket = null;
            if (preg_match('#^extern/\d{4}/Q\d/(\d+)$#', $dir, $bm)) {
                $bucket = (int)$bm[1];
            }
            $files[$abs] = ['id' => $id, 'bucket' => $bucket, 'rel' => $rel];
        }
        ksort($files);
        $result['txt_scanned'] = count($files);

        // ---------- 扫描裸名 .bin/.idx（{base}.bin + {base}.idx；跳过已带前缀） ----------
        $binBlocks = []; // 绝对 .idx 路径 => ['dirAbs'=>string, 'base'=>int, 'bucket'=>?int, 'rel'=>string]
        $it2 = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($externRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it2 as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'idx') continue;
            $rel = 'extern/' . str_replace('\\', '/', $it2->getSubPathname());
            if (preg_match('#^extern/bin/#', $rel)) continue; // 旧版误产物目录，由 migrate_extern_to_bin 自愈
            $name = $f->getBasename('.idx');
            if (preg_match('/^[tr]\d+$/', $name)) { // 已带前缀，跳过
                $result['bin_prefixed_skipped']++;
                continue;
            }
            if (!preg_match('/^(\d+)$/', $name, $m)) {
                continue; // 非存量块（异常命名），不动
            }
            $base = (int)$m[1];
            $dir = dirname($rel);
            $bucket = null;
            if (preg_match('#^extern/\d{4}/Q\d/(\d+)$#', $dir, $bm)) {
                $bucket = (int)$bm[1];
            }
            $binBlocks[$dataPath . '/' . $rel] = [
                'dirAbs' => dirname($f->getPathname()),
                'base'   => $base,
                'bucket' => $bucket,
                'rel'    => $rel,
            ];
        }
        ksort($binBlocks);
        $result['bin_blocks'] = count($binBlocks);

        // ---------- 逐个判定归属并重命名 ----------
        foreach ($files as $abs => $info) {
            $id = $info['id'];
            $pathBucket = $info['bucket'];
            $rel = $info['rel'];

            $tBucket = isset($topics[$id]) ? self::bucketOf($topics[$id]) : null;
            $rBucket = isset($replies[$id]) ? self::bucketOf($replies[$id]) : null;

            $type = null;
            if ($tBucket !== null && $tBucket === $pathBucket) {
                $type = 'topic';
            } elseif ($rBucket !== null && $rBucket === $pathBucket) {
                $type = 'reply';
            } elseif ($tBucket !== null || $rBucket !== null) {
                // 索引命中但桶号不匹配，或两索引同桶命中 → 归属存疑，防误判跳过
                $result['ambiguous'][] = "{$rel}（id={$id}）：索引命中但桶号不匹配 —— topic_bucket="
                    . var_export($tBucket, true) . " / reply_bucket=" . var_export($rBucket, true)
                    . " / path_bucket=" . var_export($pathBucket, true);
                continue;
            } else {
                $result['orphans'][] = "{$rel}（id={$id}）：topic_index/reply_index 均查不到该 ID";
                continue;
            }

            $newRel = preg_replace('#/(\d+)\.txt$#', '/' . ShardRouter::typePrefix($type) . '$1.txt', $rel);
            if ($newRel === null || $newRel === $rel) {
                $result['conflicts'][] = "{$rel}：无法推导目标路径";
                continue;
            }
            $newAbs = $dataPath . '/' . $newRel;

            // 目标已存在（如新帖已写 t{id}.txt）→ 不覆盖，跳过待人工核对
            if (is_file($newAbs)) {
                $result['conflicts'][] = "{$rel} → {$newRel}：目标已存在，跳过（不覆盖）";
                continue;
            }

            if ($dryRun || $repair) {
                $result['renamed']++;
                continue;
            }

            if (!@rename($abs, $newAbs)) {
                $result['conflicts'][] = "{$rel} → {$newRel}：rename 失败（权限/占用？）";
                continue;
            }
            $result['renamed']++;
        }

        // ==================== .bin/.idx 归档块命名空间迁移 ====================
        foreach ($binBlocks as $idxAbs => $binfo) {
            $dirAbs = $binfo['dirAbs'];
            $base   = $binfo['base'];
            $rel    = $binfo['rel'];
            $binAbs = $dirAbs . '/' . $base . '.bin';
            if (!is_file($binAbs)) {
                $result['bin_skipped'][] = "{$rel}：对应 {$base}.bin 缺失（.idx 孤儿块）";
                continue;
            }
            $raw = @file_get_contents($idxAbs);
            $idx = $raw !== false ? json_decode($raw, true) : null;
            if (!is_array($idx)) {
                $result['bin_skipped'][] = "{$rel}：.idx 不可读";
                continue;
            }
            if (count($idx) === 0) {
                $result['bin_skipped'][] = "{$rel}：.idx 为空，无法判定类型";
                continue;
            }

            // 逐条按桶号口径分类（与 .txt 同款，防歧义误判）
            $topicIds = [];
            $replyIds = [];
            $bad = [];
            foreach (array_keys($idx) as $id) {
                $id = (int)$id;
                $tBucket = isset($topics[$id]) ? self::bucketOf($topics[$id]) : null;
                $rBucket = isset($replies[$id]) ? self::bucketOf($replies[$id]) : null;
                $tMatch = ($tBucket !== null && $tBucket === $binfo['bucket']);
                $rMatch = ($rBucket !== null && $rBucket === $binfo['bucket']);
                if ($tMatch && $rMatch) {
                    $bad[] = "id={$id} 主题/回复均命中同桶";
                } elseif ($tMatch) {
                    $topicIds[] = $id;
                } elseif ($rMatch) {
                    $replyIds[] = $id;
                } else {
                    $bad[] = "id={$id} 两索引均未命中或桶号不匹配（t=" . var_export($tBucket, true) . ",r=" . var_export($rBucket, true) . "）";
                }
            }
            if (!empty($bad)) {
                $result['bin_skipped'][] = "{$rel}：存在歧义/孤儿条目（" . implode('；', array_slice($bad, 0, 3)) . "…）→ 整块跳过";
                continue;
            }
            if (empty($topicIds) && empty($replyIds)) {
                $result['bin_skipped'][] = "{$rel}：分类后无有效条目";
                continue;
            }

            // 目标存在性检查（防覆盖；已带前缀的合法块绝不动）
            $targets = [];
            if (!empty($topicIds)) $targets[] = ['t', $dirAbs . '/t' . $base . '.bin', $dirAbs . '/t' . $base . '.idx'];
            if (!empty($replyIds)) $targets[] = ['r', $dirAbs . '/r' . $base . '.bin', $dirAbs . '/r' . $base . '.idx'];
            $conflicted = false;
            foreach ($targets as $tg) {
                if (is_file($tg[1]) || is_file($tg[2])) {
                    $conflicted = true;
                    $result['bin_skipped'][] = "{$rel}：目标 {$tg[0]}{$base}.* 已存在，跳过（不覆盖）";
                    break;
                }
            }
            if ($conflicted) continue;

            $isSplit = !empty($topicIds) && !empty($replyIds);
            if ($dryRun || $repair) {
                $result['bin_split'] += $isSplit ? 1 : 0;
                $result['bin_renamed'] += $isSplit ? 0 : 1;
                continue;
            }

            if (!$isSplit) {
                // 单类型：直接重命名（目标已确认不存在）
                $type = empty($replyIds) ? 'topic' : 'reply';
                $prefix = ShardRouter::typePrefix($type);
                $ok = @rename($binAbs, $dirAbs . '/' . $prefix . $base . '.bin')
                    && @rename($idxAbs, $dirAbs . '/' . $prefix . $base . '.idx');
                if (!$ok) {
                    // 回滚已成功的重命名，保持原子
                    if (is_file($dirAbs . '/' . $prefix . $base . '.bin')) {
                        @rename($dirAbs . '/' . $prefix . $base . '.bin', $binAbs);
                    }
                    $result['bin_skipped'][] = "{$rel}：重命名失败（权限/占用？）";
                    continue;
                }
                $result['bin_renamed']++;
                continue;
            }

            // 混装拆分：先按偏移提取两类正文，重建前缀块；全部成功才删旧裸名块
            $topicContents = self::binExtract($binAbs, $idx, $topicIds);
            $replyContents = self::binExtract($binAbs, $idx, $replyIds);
            if ($topicContents === null || $replyContents === null) {
                $result['bin_skipped'][] = "{$rel}：从旧块提取正文失败（跳过防丢条目）";
                continue;
            }
            $okT = self::binWritePrefixed($dirAbs, $base, 'topic', $topicContents);
            $okR = self::binWritePrefixed($dirAbs, $base, 'reply', $replyContents);
            if (!$okT || !$okR) {
                // 回滚：删除已写出的前缀块（若旧裸名块仍在，重跑可重建）
                @unlink($dirAbs . '/t' . $base . '.bin');
                @unlink($dirAbs . '/t' . $base . '.idx');
                @unlink($dirAbs . '/r' . $base . '.bin');
                @unlink($dirAbs . '/r' . $base . '.idx');
                $result['bin_skipped'][] = "{$rel}：拆分写入失败，已回滚（可重跑）";
                continue;
            }
            if (!@unlink($binAbs) || !@unlink($idxAbs)) {
                // 新块已写、旧块删除失败：保留旧块待人工核对（新块与旧块并存，重跑会因目标存在而跳过）
                $result['bin_skipped'][] = "{$rel}：旧裸名块删除失败，保留待人工核对";
                continue;
            }
            $result['bin_split']++;
        }

        // ---------- 汇总 ----------
        $dirty = !empty($result['ambiguous']) || !empty($result['orphans'])
            || !empty($result['conflicts']) || !empty($result['bin_skipped']);
        $result['dirty'] = $dirty;

        return $result;
    }

    /** 原子写文件（临时文件 + rename；Windows 目标已存在时先删旧再重试） */
    private static function binAtomicWrite(string $target, string $content): bool
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
     * 从裸名 .bin 块按 .idx 偏移提取指定 ID 的正文
     * 格式：[4字节大端长度][正文内容]...；.idx = {ID: 偏移量}
     * @return array<int,string>|null 提取失败返回 null（调用方整块跳过，防丢条目）
     */
    private static function binExtract(string $binPath, array $idx, array $ids): ?array
    {
        $fh = @fopen($binPath, 'rb');
        if (!$fh) return null;
        $out = [];
        try {
            foreach ($ids as $id) {
                $offset = (int)($idx[$id] ?? -1);
                if ($offset < 0 || @fseek($fh, $offset) !== 0) return null;
                $lenBytes = @fread($fh, 4);
                if (strlen($lenBytes) !== 4) return null;
                $len = unpack('N', $lenBytes)[1];
                if ($len <= 0 || $len > 16 * 1024 * 1024) return null; // 防异常长度
                $content = @fread($fh, $len);
                if ($content === false) return null;
                $out[$id] = $content;
            }
        } finally {
            fclose($fh);
        }
        return $out;
    }

    /**
     * 写出单个类型前缀块（t{base}.bin/.idx 或 r{base}.bin/.idx）
     * 内容按「先 .bin 后 .idx、原子落盘」红线写入，防止出现有 .bin 无 .idx 的半成品。
     * @param array<int,string> $contents id => 正文
     */
    private static function binWritePrefixed(string $dirAbs, int $base, string $type, array $contents): bool
    {
        $prefix = ShardRouter::typePrefix($type);
        $binPath = $dirAbs . '/' . $prefix . $base . '.bin';
        $idxPath = $dirAbs . '/' . $prefix . $base . '.idx';

        $newIdx = [];
        $binRaw = '';
        foreach ($contents as $id => $content) {
            $offset = strlen($binRaw);
            $binRaw .= pack('N', strlen($content)) . $content;
            $newIdx[$id] = $offset;
        }
        if (!self::binAtomicWrite($binPath, $binRaw)) {
            @unlink($binPath); // 落盘失败清理残留
            return false;
        }
        if (!self::binAtomicWrite($idxPath, json_encode($newIdx, JSON_UNESCAPED_UNICODE))) {
            @unlink($binPath); // .idx 失败则回滚 .bin（红线：不留下有 .bin 无 .idx 的半成品）
            @unlink($idxPath);
            return false;
        }
        return true;
    }

    /** bucket_path → 桶号（带缓存，避免重复解析） */
    private static function bucketOf(string $bucketPath): ?int
    {
        if (!array_key_exists($bucketPath, self::$bucketCache)) {
            self::$bucketCache[$bucketPath] = null;
            if (preg_match('#bucket/(?:active|archive)/(\d{4}Q\d)/(\d+)\.sqlite#', $bucketPath, $m)) {
                self::$bucketCache[$bucketPath] = (int)$m[2];
            }
        }
        return self::$bucketCache[$bucketPath];
    }
}
