<?php
/**
 * FlintHub 1.0 (SplitDB) — extern/.txt → .bin 合并器（后台 Web 版）
 *
 * 背景：cli/migrate_extern_to_bin.php 是唯一 .bin 生成入口（CLI-only），
 * 虚拟主机无 CLI/cron 时无法使用。本类将该脚本的"扫描分组 + 分组原子合并 +
 * 断点续跑"逻辑搬到后台可调用的位置（不 include cli/_guard.php），
 * 配合控制器/视图实现"每批 N 组、meta refresh 链式续批"的后台操作。
 *
 * 与 CLI 版本保持一致的红线：
 *   每 (目录, 批次) 组：先原子写 .bin → 再原子写 .idx → 成功后才删该组 .txt；
 *   任一步失败保留 .txt（前台读取自动回退 .txt，永不 404）。
 * 幂等：.idx 已存在的条目跳过追加（断点丢失后重扫重跑也安全）。
 *
 * @file app/SplitDB/ExternMerger.php
 * @package app\SplitDB
 */

namespace app\SplitDB;

class ExternMerger
{
    /** checkpoint 文件（data/runtime/extern_merge_checkpoint.json） */
    private const CHECKPOINT_FILE = 'extern_merge_checkpoint.json';
    /** 合并互斥锁文件（防并发双写 .bin/.idx） */
    private const LOCK_FILE = 'extern_merge.lock';
    /** 单批最大组数上限（视图下拉 5/10/20/50，此处硬钳制防异常请求） */
    public const MAX_GROUPS_PER_BATCH = 50;

    // ====================================================================
    // 路径 / 锁
    // ====================================================================

    private static function runtimeDir(): string
    {
        $dir = rtrim(ShardRouter::dataPath(), '/\\') . '/runtime';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    public static function checkpointFile(): string
    {
        return self::runtimeDir() . '/' . self::CHECKPOINT_FILE;
    }

    private static function lockFile(): string
    {
        return self::runtimeDir() . '/' . self::LOCK_FILE;
    }

    /**
     * 获取合并互斥锁（非阻塞）。返回资源 = 持锁成功；false = 已有合并任务在跑
     */
    private static function acquireLock()
    {
        $dir = self::runtimeDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $lock = @fopen(self::lockFile(), 'c');
        if ($lock === false) {
            return false;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            @fclose($lock);
            return false;
        }
        return $lock;
    }

    private static function releaseLock($lock): void
    {
        if ($lock) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    // ====================================================================
    // checkpoint（结构：groups=[[dir,batch]...], next, scanned, done,
    //             indexed, deleted, skipped, errors[], ts）
    // ====================================================================

    public static function read(): ?array
    {
        $file = self::checkpointFile();
        clearstatcache(true, $file);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        $cp = $raw !== false ? @json_decode($raw, true) : null;
        if (!is_array($cp)) {
            return null;
        }
        // 字段合法性校验（groups 含 type）
        if (!isset($cp['groups']) || !is_array($cp['groups'])) {
            return null;
        }
        foreach ($cp['groups'] as $g) {
            if (!is_array($g) || !isset($g['dir'], $g['batch'], $g['type'])) {
                return null; // 旧版无 type 的断点视为失效 → start() 强制重扫重建
            }
        }
        return [
            'groups'  => $cp['groups'],
            'next'    => max(0, (int)($cp['next'] ?? 0)),
            'scanned' => max(0, (int)($cp['scanned'] ?? 0)),
            'done'    => max(0, (int)($cp['done'] ?? 0)),
            'indexed' => max(0, (int)($cp['indexed'] ?? 0)),
            'deleted' => max(0, (int)($cp['deleted'] ?? 0)),
            'skipped' => max(0, (int)($cp['skipped'] ?? 0)),
            'errors'  => is_array($cp['errors'] ?? null) ? array_slice($cp['errors'], 0, 50) : [],
            'ts'      => (int)($cp['ts'] ?? time()),
        ];
    }

    private static function write(array $cp): void
    {
        $cp['ts'] = time();
        $file = self::checkpointFile();
        $ok = @file_put_contents($file, json_encode($cp, JSON_UNESCAPED_UNICODE), LOCK_EX);
        clearstatcache(true, $file);
        if ($ok === false) {
            \error_log('ExternMerger: checkpoint 写入失败');
        }
    }

    public static function clear(): void
    {
        $file = self::checkpointFile();
        if (is_file($file)) {
            @unlink($file);
        }
        clearstatcache(true, $file);
    }

    private static function initial(array $groups, int $scanned): array
    {
        return [
            'groups'  => $groups,
            'next'    => 0,
            'scanned' => $scanned,
            'done'    => 0,
            'indexed' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'errors'  => [],
            'ts'      => time(),
        ];
    }

    // ====================================================================
    // 扫描分组（extern/{年}/{季}/{桶}/{t|r}{ID}.txt → 按 (目录, 类型, 批次) 分组）
    // ====================================================================

    /**
     * 递归扫描 extern/ 下命名空间 .txt（t{id}.txt / r{id}.txt），
     * 按 (目录, 类型, intdiv(ID,1000)) 分组。
     * 裸名文件（{id}.txt 等，命名空间改造前）不参与合并、被跳过（本版面向无存量的新装站点，
     * 不再做前置迁移拦截）。
     * @return array{groups: array<int, array{dir:string,type:string,batch:int}>, files:int}
     */
    public static function scan(): array
    {
        $dataPath = ShardRouter::dataPath();
        $externRoot = rtrim($dataPath, '/\\') . '/extern';
        $byDir = []; // dir => type => [id...]
        if (is_dir($externRoot)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($externRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if (!$f->isFile()) {
                    continue;
                }
                $ext = $f->getExtension();
                $name = $f->getBasename('.' . $ext);
                if ($ext !== 'txt') {
                    continue; // 跳过 .bin / .idx / 其它
                }
                if (!preg_match('/^([tr])(\d+)$/', $name, $m)) {
                    continue; // 跳过裸名/异常文件名（防御）
                }
                $type = $m[1] === 'r' ? 'reply' : 'topic';
                $id = (int)$m[2];
                $dir = self::slashPath(dirname($f->getPathname()));
                $byDir[$dir][$type][] = $id;
            }
        }
        $groups = [];
        $files = 0;
        foreach ($byDir as $dir => $byType) {
            foreach ($byType as $type => $ids) {
                sort($ids);
                $byBatch = [];
                foreach ($ids as $id) {
                    $byBatch[intdiv($id, 1000)][] = $id;
                }
                ksort($byBatch);
                foreach ($byBatch as $batch => $batchIds) {
                    $groups[] = ['dir' => $dir, 'type' => $type, 'batch' => (int)$batch];
                    $files += count($batchIds);
                }
            }
        }
        usort($groups, function ($a, $b) {
            return [$a['dir'], $a['type'], $a['batch']] <=> [$b['dir'], $b['type'], $b['batch']];
        });
        return ['groups' => $groups, 'files' => $files];
    }

    /** 绝对路径转正斜杠 */
    private static function slashPath(string $p): string
    {
        return str_replace('\\', '/', $p);
    }

    /** 批次基数 → .bin/.idx 文件名（批次 12 → 12000） */
    private static function batchBaseName(int $batch): int
    {
        return $batch * 1000;
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

    // ====================================================================
    // 合并单组（复用 CLI processGroup 红线逻辑）
    // ====================================================================

    /**
     * 处理单个 (目录, 类型, 批次) 组：写 .bin（追加）→ 写 .idx（合并旧偏移）→ 成功后删该组 .txt
     * .bin/.idx 文件名带类型前缀（t{base}.bin / r{base}.bin），隔离主题/回复 ID 重叠区间。
     * @param array $errors 追加错误信息（引用）
     * @return array{scanned:int,indexed:int,deleted:int,skipped:int,bytes:int}
     */
    private static function mergeGroup(string $dir, string $type, int $batch, array &$errors): array
    {
        $base = self::batchBaseName($batch);
        $prefix = ShardRouter::typePrefix($type);
        $binPath = $dir . '/' . $prefix . $base . '.bin';
        $idxPath = $dir . '/' . $prefix . $base . '.idx';

        // 收集该组现存 .txt（dir 下同类型前缀、batch 段内的 ID）
        $files = [];
        $re = '/^' . $prefix . '(\d+)\.txt$/';
        foreach (glob($dir . '/*.txt') ?: [] as $path) {
            if (preg_match($re, basename($path), $m)) {
                $id = (int)$m[1];
                if (intdiv($id, 1000) === $batch) {
                    $files[$id] = self::slashPath($path);
                }
            }
        }
        ksort($files);

        $result = ['scanned' => count($files), 'indexed' => 0, 'deleted' => 0, 'skipped' => 0, 'bytes' => 0];
        if (!$files) {
            return $result;
        }

        // 已迁移条目（断点续跑合并基础）
        $existingIdx = [];
        if (is_file($idxPath)) {
            $raw = @file_get_contents($idxPath);
            $dec = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($dec)) {
                $existingIdx = array_map('intval', $dec);
            }
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
        if (!self::atomicWrite($idxPath, json_encode($mergedIdx, JSON_UNESCAPED_UNICODE))) {
            $errors[] = "{$dir}: .idx 写入失败，保留 .txt（批次 {$base}）";
            return $result; // 红线：.idx 未成功则不删 .txt
        }

        // 3. 红线通过后才删该组 .txt（仅删已入索引的）
        foreach ($files as $id => $path) {
            if (isset($mergedIdx[$id]) && @unlink($path)) {
                $result['deleted']++;
            }
        }

        return $result;
    }

    // ====================================================================
    // 批处理（从断点 next 处理 $limit 组，更新断点）
    // ====================================================================

    /**
     * 处理一批（互斥锁保护；失败只推进断点不中断——断点文件损坏时重扫即可）
     * @param int $limit 本批处理组数
     * @return array{ok:bool, error:string, cp:?array, finished:bool,
     *               done:int, total:int, scanned:int, batch_done:int}
     */
    public static function processBatch(int $limit): array
    {
        $limit = max(1, min(self::MAX_GROUPS_PER_BATCH, (int)$limit));

        $lock = self::acquireLock();
        if ($lock === false) {
            return ['ok' => false, 'error' => 'busy', 'cp' => null,
                    'finished' => false, 'done' => 0, 'total' => 0, 'scanned' => 0, 'batch_done' => 0];
        }
        try {
            $cp = self::read();
            if ($cp === null) {
                return ['ok' => false, 'error' => 'no_checkpoint', 'cp' => null,
                        'finished' => false, 'done' => 0, 'total' => 0, 'scanned' => 0, 'batch_done' => 0];
            }

            $total = count($cp['groups']);
            if ($cp['next'] >= $total) {
                self::clear(); // 全部完成 → 清断点
                return ['ok' => true, 'error' => '', 'cp' => null, 'finished' => true,
                        'done' => $total, 'total' => $total, 'scanned' => $cp['scanned'], 'batch_done' => 0];
            }

            $batchDone = 0;
            $errors = $cp['errors'];
            for ($i = 0; $i < $limit && $cp['next'] < $total; $i++) {
                $g = $cp['groups'][$cp['next']];
                $r = self::mergeGroup((string)$g['dir'], (string)$g['type'], (int)$g['batch'], $errors);
                $cp['done']++;
                $cp['next']++;
                $cp['indexed'] += $r['indexed'];
                $cp['deleted'] += $r['deleted'];
                $cp['skipped'] += $r['skipped'];
                $batchDone++;
                $cp['errors'] = array_slice($errors, 0, 50);
            }
            self::write($cp);

            $finished = $cp['next'] >= $total;
            if ($finished) {
                self::clear();
            }
            return ['ok' => true, 'error' => '', 'cp' => $finished ? null : $cp,
                    'finished' => $finished, 'done' => $cp['done'], 'total' => $total,
                    'scanned' => $cp['scanned'], 'batch_done' => $batchDone];
        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * 开始一次合并：强制重扫（忽略旧断点）→ 建初始 checkpoint → 跑第一批
     * @return array 同 processBatch
     */
    public static function start(int $limit): array
    {
        $lock = self::acquireLock();
        if ($lock === false) {
            return ['ok' => false, 'error' => 'busy', 'cp' => null,
                    'finished' => false, 'done' => 0, 'total' => 0, 'scanned' => 0, 'batch_done' => 0];
        }
        try {
            $scan = self::scan();
            if (!$scan['groups']) {
                self::clear(); // 无待合并文件（新装站点无正文或已全部合并）
                return ['ok' => true, 'error' => '', 'cp' => null, 'finished' => true,
                        'done' => 0, 'total' => 0, 'scanned' => 0, 'batch_done' => 0];
            }
            self::write(self::initial($scan['groups'], $scan['files']));
        } finally {
            self::releaseLock($lock);
        }
        return self::processBatch($limit);
    }

    /** 供进度页展示的当前状态（无任务时返回 null） */
    public static function status(): ?array
    {
        $cp = self::read();
        if ($cp === null) {
            return null;
        }
        return [
            'done'    => $cp['done'],
            'total'   => count($cp['groups']),
            'scanned' => $cp['scanned'],
            'indexed' => $cp['indexed'],
            'deleted' => $cp['deleted'],
            'skipped' => $cp['skipped'],
            'errors'  => $cp['errors'],
            'ts'      => $cp['ts'],
        ];
    }
}
