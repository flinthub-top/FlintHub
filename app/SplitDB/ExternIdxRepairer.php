<?php
/**
 * FlintHub 1.0 (SplitDB) — extern .bin/.idx 偏移表修复器
 *
 * 背景：V1.0.115 合并工具（Web ExternMerger / CLI migrate_extern_to_bin）在 Windows 上
 * 用 fopen($bin, 'ab') 后 ftell() 计算追加偏移，而 Windows PHP 的 ftell 在追加模式下
 * 返回 0（而非文件末尾），导致追加到"非空" .bin 的条目 .idx 偏移整体偏小
 * （少"已有文件大小"），帖子读取时串到其他正文 → 正文乱套。
 *
 * 好消息：.bin 内容链本身完整（[4字节大端长度][正文]...，按 ID 升序写入），只有 .idx
 * 的 id→偏移 映射写错。本类按以下不变式重建 .idx：
 *   1. 逐块顺序解析 .bin 链 → 得到真实条目位置列表（链内顺序 = 写入顺序 = ID 升序）
 *   2. main_index（topic_index/reply_index）取同桶、同批次（intdiv(id,1000)）、同类型的候选 ID（升序）
 *   3. 对齐 ID 集 = 候选 ∩ 现有 idx 键集（升序，键集不受 Windows 追加 bug 影响）；
 *      仅当 链数 == 对齐数 时把第 i 个对齐 ID 赋给链第 i 条位置
 *   4. 与现有 .idx 直接比对（键集 + 偏移全等），不一致才重建（先备份 .idx.orig，再原子写新 .idx）
 * 任何 无法对齐（链数 != 对齐数）的块一律跳过待人工核对，绝不乱改（幂等，可重跑）。
 *
 * 注意：严禁用"按损坏偏移排序"的 ID 序去对链——错乱映射自身满足"偏移升序==链序"的
 * 自洽校验，单靠自洽无法识别，必须与上述正确映射直接比对（21/r0 的 26↔943 互换、
 * 22/r0 全块错乱即此 bug 所致）。
 *
 * 调用方：repair_extern_idx.php（Web/CLI 双模式）。
 *
 * @package app\SplitDB
 */

namespace app\SplitDB;

class ExternIdxRepairer
{
    /** @var array<string,int> 目录桶号解析缓存 */
    private static array $bucketCache = [];

    /**
     * 执行 .idx 偏移表修复（幂等，可断点重跑）。
     *
     * @param array $opts 可选键：
     *   - dry_run (bool)：仅预览将修复的块，不落盘
     * @return array [
     *   'extern_root' => string|null,
     *   'blocks' => int,        // 扫描到的前缀 .idx 块数
     *   'ok' => int,            // 已验证正确，无需改动
     *   'fixed' => int,         // 已重建（或预览将重建）
     *   'skipped' => string[],  // 计数/ID 集合不匹配，待人工核对
     * ]
     */
    public static function run(array $opts = []): array
    {
        $dryRun = !empty($opts['dry_run']);

        $result = [
            'extern_root' => null,
            'blocks' => 0,
            'ok' => 0,
            'fixed' => 0,
            'skipped' => [],
            'details' => [],
        ];

        $dataPath = ShardRouter::dataPath();
        $externRoot = $dataPath . '/extern';
        if (!is_dir($externRoot)) {
            return $result;
        }
        $result['extern_root'] = $externRoot;

        // ---------- 预载 main_index（id => bucket_path） ----------
        $mi = Schema::mainIndexDb($dataPath);
        $topics = [];
        foreach ($mi->query('SELECT id, bucket_path FROM topic_index')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $topics[(int)$r['id']] = (string)$r['bucket_path'];
        }
        $replies = [];
        foreach ($mi->query('SELECT id, bucket_path FROM reply_index')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $replies[(int)$r['id']] = (string)$r['bucket_path'];
        }

        // ---------- 扫描前缀 .idx 块 ----------
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($externRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'idx') continue;
            $rel = 'extern/' . str_replace('\\', '/', $it->getSubPathname());
            if (preg_match('#^extern/bin/#', $rel)) continue; // 旧版误产物目录，不处理
            $name = $f->getBasename('.idx');
            if (!preg_match('/^([tr])(\d+)$/', $name, $m)) continue; // 仅处理前缀块
            $type = $m[1] === 'r' ? 'reply' : 'topic';
            $base = (int)$m[2];
            $dirRel = dirname($rel);
            if (!preg_match('#^extern/\d{4}/Q\d/(\d+)$#', $dirRel, $dm)) continue;
            $bucket = (int)$dm[1];

            $result['blocks']++;
            $abs = $f->getPathname();
            $binAbs = substr($abs, 0, -4) . '.bin';
            if (!is_file($binAbs)) {
                $result['skipped'][] = "{$rel}：.bin 缺失";
                continue;
            }

            // 1. 顺序解析 .bin 链
            $chain = self::walkChain($binAbs);
            if ($chain === null) {
                $result['skipped'][] = "{$rel}：.bin 链解析失败（长度头异常）";
                continue;
            }

            // 2. main_index 候选 ID（同桶、同批次、同类型，升序）
            $index = $type === 'topic' ? $topics : $replies;
            $candidates = [];
            foreach ($index as $id => $bp) {
                if (intdiv($id, 1000) !== $base) continue;
                if (self::bucketOf($bp) === $bucket) {
                    $candidates[] = (int)$id;
                }
            }
            sort($candidates);

            // 3. 现有 idx
            $raw = @file_get_contents($abs);
            $current = $raw !== false ? json_decode($raw, true) : null;
            if (!is_array($current)) {
                $result['skipped'][] = "{$rel}：现有 .idx 不可读";
                continue;
            }

            // 4. 重建映射的 ID 集：候选 ∩ 当前键集，按 ID 升序。
            //    Windows 追加 bug 只损坏偏移值，.idx 键集（= 合并工具实际写入的 ID）可信；
            //    严禁用"按损坏偏移排序"的 ID 序去对链——那会把错乱序固化进重建结果
            //    （21/r0 的 26↔943 互换、22/r0 全块错乱即因此产生；且错乱映射本身满足
            //    "偏移升序 == 链序"的自洽校验，单靠自洽无法识别，必须与正确映射直接比对）。
            $idSet = array_values(array_intersect($candidates, array_map('intval', array_keys($current))));
            sort($idSet);

            // 5. 仅当 链数 == 对齐 ID 数 才可安全重建：第 i 个（ID 升序）候选 => 链第 i 条位置
            if (count($idSet) !== count($chain)) {
                $missingIds = array_values(array_diff($candidates, $idSet));
                $noTxt = [];
                foreach ($missingIds as $mid) {
                    $txtRel = preg_replace('#^(extern/\d{4}/Q\d/\d+)/[tr]\d+\.idx$#', '$1/' . ($type === 'reply' ? 'r' : 't') . $mid . '.txt', $rel);
                    if (!is_file($dataPath . '/' . $txtRel)) {
                        $noTxt[] = $mid;
                    }
                }
                $result['skipped'][] = "{$rel}：链数 " . count($chain) . " 与对齐 ID 数 " . count($idSet) . " 不符（人工核对）"
                    . ($noTxt ? "；以下候选 ID 无 .txt 兜底：" . implode(',', $noTxt) : '');
                continue;
            }

            // 6. 正确映射：第 i 个（ID 升序）候选 => 链第 i 条位置
            $correct = [];
            foreach ($idSet as $i => $id) {
                $correct[$id] = $chain[$i]['pos'];
            }
            ksort($correct);

            // 7. 与现有 .idx 直接比对（键集 + 偏移全等）→ 一致则无需改动
            $same = count($correct) === count($current);
            if ($same) {
                foreach ($correct as $id => $off) {
                    if (!isset($current[$id]) || (int)$current[$id] !== $off) {
                        $same = false;
                        break;
                    }
                }
            }
            if ($same) {
                $result['ok']++;
                continue;
            }

            // 记录重建后仍无归属的候选（可能有 .txt 兜底则读取正常）
            $afterMissing = array_values(array_diff($candidates, array_keys($correct)));
            if ($afterMissing) {
                $noTxt2 = [];
                foreach ($afterMissing as $mid) {
                    $txtRel = preg_replace('#^(extern/\d{4}/Q\d/\d+)/[tr]\d+\.idx$#', '$1/' . ($type === 'reply' ? 'r' : 't') . $mid . '.txt', $rel);
                    if (!is_file($dataPath . '/' . $txtRel)) {
                        $noTxt2[] = $mid;
                    }
                }
                if ($noTxt2) {
                    $result['details'][] = $rel . "：重建后以下 ID 无归属且无 .txt 兜底（疑似损坏前已缺失）：" . implode(',', $noTxt2);
                }
            }

            // 8. 修复（先备份 .idx.orig 再原子写）
            if (!$dryRun) {
                if (!is_file($abs . '.orig')) {
                    @copy($abs, $abs . '.orig'); // 只备份一次，可重跑
                }
                if (!self::atomicWrite($abs, json_encode($correct, JSON_UNESCAPED_UNICODE))) {
                    $result['skipped'][] = "{$rel}：.idx 写入失败（保留备份可重试）";
                    continue;
                }
            }
            $result['fixed']++;
            if (empty($result['details']) || end($result['details']) !== $rel) {
                $result['details'][] = $rel;
            }
        }

        return $result;
    }

    /**
     * 顺序解析 .bin 链（[4字节大端长度][内容]...），返回条目位置与内容样本。
     * @return array<int,array{pos:int,len:int,utf8:bool}>|null 链异常返回 null
     */
    private static function walkChain(string $binPath): ?array
    {
        $bin = @file_get_contents($binPath);
        if ($bin === false) return null;
        $entries = [];
        $pos = 0;
        $len = strlen($bin);
        while ($pos + 4 <= $len) {
            $l = unpack('N', substr($bin, $pos, 4))[1];
            if ($l <= 0 || $l > 16 * 1024 * 1024 || $pos + 4 + $l > $len) {
                return null; // 链断裂（非 [长度][内容] 结构）
            }
            $content = substr($bin, $pos + 4, $l);
            $entries[] = [
                'pos' => $pos,
                'len' => $l,
                'utf8' => mb_check_encoding($content, 'UTF-8'),
            ];
            $pos += 4 + $l;
        }
        if ($pos !== $len) return null; // 尾部残留数据，链不完整
        return $entries;
    }

    /** 原子写文件（临时文件 + rename；Windows 目标已存在时先删旧再重试） */
    private static function atomicWrite(string $target, string $content): bool
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

    /** bucket_path → 桶号（带缓存） */
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
