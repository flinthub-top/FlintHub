<?php
/**
 * FlintHub 1.0 (SplitDB) — 外置正文存储
 * 白皮书模块三：帖子/回复正文剥离 SQLite，存入 extern/ 目录 .txt 文件。
 * 写入采用「临时文件 + rename」原子替换；读取带 APCu 可选缓存（无 APCu 自动降级）。
 *
 * rename 兼容：现代 PHP（8.2+）下 rename() 可直接覆盖已存在目标；对旧版 PHP 的 Windows
 * 行为统一由 atomicRename() 处理（先删旧目标再重试），write() 与 .idx 重写共用同一套逻辑。
 *
 * @file app/SplitDB/ExternStorage.php
 * @package app\SplitDB
 */

namespace app\SplitDB;

class ExternStorage
{
    /** APCu 缓存 TTL（秒） */
    private const APCU_TTL = 600;

    /**
     * APCu 正文缓存键（按内容类型加前缀，隔离主题/回复 ID 重叠区间）
     * 键形如 extern_t5 / extern_r5
     */
    private static function cacheKey(int $id, string $type): string
    {
        return 'extern_' . ShardRouter::typePrefix($type) . $id;
    }

    /**
     * 原子写入外置正文，返回相对路径（供存入 bucket 的 extern_path 字段）
     *
     * @param string      $dataPath  SplitDB 数据根目录（DATA_PATH）
     * @param int         $topicId  帖子/回复 ID
     * @param int         $bucketId 哈希桶编号（用于目录分布）
     * @param string      $type     内容类型 'topic'|'reply'（决定文件名前缀与 APCu 键，P0-1）
     * @param string      $content  正文内容（HTML）
     * @param int|null    $timestamp 写入时间戳（默认当前时间，决定季度目录）
     */
    public static function write(string $dataPath, int $topicId, int $bucketId, string $type, string $content, ?int $timestamp = null, ?string $oldRelPath = null): string
    {
        $quarter = ShardRouter::quarter($timestamp ?: time());
        $relPath = ShardRouter::externRel($topicId, $quarter, $bucketId, $type);
        $absPath = ShardRouter::ensureExternDir($topicId, $quarter, $bucketId, $type, $dataPath);

        // 临时文件：同目录 + 随机后缀，保证 rename 原子性
        $tmpPath = $absPath . '.' . uniqid('', true) . '.tmp';

        // LOCK_EX 确保内容完整落盘后再 rename
        if (@file_put_contents($tmpPath, $content, LOCK_EX) === false) {
            @unlink($tmpPath);
            \error_log('SplitDB: extern 正文写临时文件失败: ' . $absPath);
            throw new \RuntimeException("SplitDB: 正文写临时文件失败: {$absPath}");
        }

        if (!self::atomicRename($tmpPath, $absPath)) {
            \error_log('SplitDB: 原子写入 extern 失败: ' . $absPath);
            throw new \RuntimeException("SplitDB: 原子写入 extern 失败: {$absPath}");
        }

        // 可选 APCu 缓存（无扩展自动跳过）
        if (function_exists('apcu_store')) {
            @apcu_store(self::cacheKey($topicId, $type), $content, self::APCU_TTL);
        }

        // 正文重写（编辑）后作废 .idx 归档条目：
        // 读取链为 APCu → .bin/.idx → .txt，若 .idx 仍保留旧偏移则永远命中归档旧文，
        // 作废后读取自动回退新的 .txt。
        // 跨季度/归档后编辑：旧 .bin/.idx 位于【旧 relPath 所在季度目录】——须按旧路径目录
        // 计算并作废（新路径目录下没有该 .idx，按新路径作废会提前 return，旧条目永不失效）。
        // 同季度编辑（oldRelPath 为空或与新路径相同）回退为按新路径作废，行为与既往一致。
        $invalidatePath = ($oldRelPath !== null && $oldRelPath !== '') ? $oldRelPath : $relPath;
        self::invalidateArchiveEntry($dataPath, $invalidatePath, $topicId, $type);

        return $relPath;
    }

    /**
     * 原子重命名（tmp → 正式路径）——统一 write() 与 invalidateArchiveEntry() 两处
     * tmp+rename 行为：优先直接 rename（现代 PHP 可覆盖已存在目标）；失败时按旧版
     * Windows 语义先删目标再重试一次；仍失败则清理临时文件并返回 false（调用方决定
     * 抛错或容忍）。
     */
    private static function atomicRename(string $tmpPath, string $destPath): bool
    {
        if (@rename($tmpPath, $destPath)) {
            return true;
        }
        // Windows（旧版 PHP）：目标已存在时 rename 失败 → 先删旧目标再重试。
        // 前置条件：仅当临时文件确实已落盘、且目标文件确实已存在时，删除旧目标重试才有意义——
        // 若 tmp 不存在（写入失败）或 dest 不存在（本就不该走删除分支），删除只会让数据丢失。
        if (is_file($tmpPath) && is_file($destPath)) {
            @unlink($destPath);
            if (@rename($tmpPath, $destPath)) {
                return true;
            }
            @unlink($tmpPath);
            \error_log('SplitDB: atomicRename 重试仍失败，临时文件已清理: ' . $destPath);
            return false;
        }
        @unlink($tmpPath);
        \error_log('SplitDB: atomicRename 前置条件不满足（tmp/dest 缺失），跳过删除旧目标: ' . $destPath);
        return false;
    }

    /**
     * 使指定 ID 的 .idx 归档条目失效（编辑正文后调用）
     *
     * .bin 为只读归档（死字节无害、不重写），只需从 .idx JSON 中删除该 ID 键，
     * 使 readFromBin() 返回 null → 读取回退同目录的 .txt（新内容）。
     * 同时清理 APCu 索引缓存（extern_idx_*）与该正文缓存，避免旧偏移被命中。
     *
     * .idx 文件名按类型前缀（t{base}.idx / r{base}.idx）。旧版裸名块（{base}.idx）
     * 已由 cli/migrate_extern_namespace.php 拆分/重命名迁移，此处不做裸名回退——
     * 裸名块会跨类型串读（主题/回复 ID 重叠，同目录下彼此命中对方条目）。
     */
    private static function invalidateArchiveEntry(string $dataPath, string $relPath, int $topicId, string $type): void
    {
        if ($topicId <= 0 || $relPath === '') {
            return;
        }
        $batch = intdiv($topicId, 1000);
        $dir = (rtrim($dataPath, '/\\')) . '/' . dirname(ltrim($relPath, '/\\'));
        $prefix = ShardRouter::typePrefix($type);
        $base = $batch * 1000;
        $idxPath = $dir . '/' . $prefix . $base . '.idx';
        if (!is_file($idxPath)) {
            return; // 迁移后新帖未归档，无需作废
        }

        // 清理 APCu 索引缓存（防止旧偏移被命中）
        $cacheKey = 'extern_idx_' . md5($idxPath);
        if (function_exists('apcu_delete')) {
            @apcu_delete($cacheKey);
        }

        // .idx 读-改-写并发互斥：同一批次（同 1000 个 ID 共用一个 .idx）下不同帖子并发编辑时，
        // 无锁会导致后写者覆盖先写者的作废标记（lost update，编辑不生效）。
        // 用独立锁文件 $idxPath.lock（不能 flock 目标 .idx 本身——tmp+rename 换 inode 后锁失效），
        // 排他锁包裹整个读-改-写；finally 保证异常时也解锁。保持 tmp+rename 原子写不变。
        $lockPath = $idxPath . '.lock';
        $lockFh = @fopen($lockPath, 'c');
        if ($lockFh !== false) {
            @flock($lockFh, LOCK_EX);
        }
        try {
            $raw = @file_get_contents($idxPath);
            if ($raw === false) {
                return;
            }
            $idx = json_decode($raw, true);
            if (!is_array($idx) || !isset($idx[$topicId])) {
                return;
            }

            unset($idx[$topicId]);
            // 原子重写（临时文件 + rename，与 write 同一套 atomicRename 行为；失败不静默——E3）
            $tmp = $idxPath . '.' . uniqid('', true) . '.tmp';
            if (@file_put_contents($tmp, json_encode($idx, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false) {
                if (!self::atomicRename($tmp, $idxPath)) {
                    // E3：.idx 重写失败若被静默容忍 → .bin 旧内容永久压住新 .txt，且无任何发现途径
                    \error_log('SplitDB: .idx 归档条目作废重写失败，旧偏移可能继续命中: ' . $idxPath);
                }
            } else {
                @unlink($tmp);
                \error_log('SplitDB: .idx 临时文件写入失败: ' . $idxPath);
            }
        } finally {
            if ($lockFh !== false) {
                @flock($lockFh, LOCK_UN);
                @fclose($lockFh);
            }
        }
    }

    /**
     * 读取外置正文（APCu 优先；.bin/.idx 懒加载优先，未命中回退 .txt）
     *
     * 降级红线：.bin 存在但 .idx 缺失（迁移未完整）→ 回退 .txt，前台永不 404；
     * 迁移后新帖只写 .txt、不在 .idx → 同样回退 .txt。
     *
     * @param int|null $limit 非 null 时仅读取正文前 $limit 字节（摘要链路用的局部读），
     *                        且该局部内容不写入 APCu 完整正文缓存键，避免污染后续全文读取。
     * @param string $type 内容类型 'topic'|'reply'（决定 APCu 键与 .bin/.idx 前缀，P0-1）
     */
    public static function read(string $dataPath, string $relPath, int $topicId, string $type, ?int $limit = null): string
    {
        if (function_exists('apcu_fetch')) {
            $cached = @apcu_fetch(self::cacheKey($topicId, $type), $success);
            if ($success && is_string($cached)) {
                return $cached;
            }
        }

        // .bin/.idx 优先（迁移合并块，与 .txt 同目录）；null = 未命中 → 回退 .txt
        $content = self::readFromBin($dataPath, $relPath, $topicId, $type, $limit);
        if ($content !== null) {
            if ($limit === null && function_exists('apcu_store') && $content !== '') {
                @apcu_store(self::cacheKey($topicId, $type), $content, self::APCU_TTL);
            }
            return $content;
        }

        // 回退 .txt：迁移未覆盖 / .bin 在但 .idx 缺失（降级红线）/ 迁移后新帖
        $absPath = (rtrim($dataPath, '/\\')) . '/' . ltrim($relPath, '/\\');
        if (!is_file($absPath)) {
            return '';
        }
        // 方案 C 前缀读：$limit 非 null 时仅取前 $limit 字节（file_get_contents 的 length 参数）
        $content = $limit !== null
            ? @file_get_contents($absPath, false, null, 0, $limit)
            : @file_get_contents($absPath);

        if ($limit === null && function_exists('apcu_store') && $content !== false) {
            @apcu_store(self::cacheKey($topicId, $type), $content, self::APCU_TTL);
        }

        return $content !== false ? $content : '';
    }

    /**
     * 从 .bin/.idx 读取正文（懒加载 .idx JSON，APCu 缓存 600s）
     *
     * 路径规则：.bin/.idx 与 .txt 同目录，遵循 ShardRouter 原路径：
     * extern/{年}/{季}/{桶}/{t|r}{批次基数}.bin + .idx，批次基数 = floor(ID/1000)×1000
     * （如 ID 12000~12999 → extern/.../t12000.bin + t12000.idx；回复为 r12000.*）。
     * 只读带类型前缀块：裸名块（12000.bin + 12000.idx）已由
     * cli/migrate_extern_namespace.php 拆分/重命名迁移，此处不做裸名回退——
     * 裸名块会跨类型串读（主题/回复 ID 重叠）。
     * 格式：[4字节大端长度][正文内容]...；.idx = {ID: 偏移量}
     *
     * @return string|null 正文；null = 未命中（.bin/.idx 缺失或 ID 不在索引 → 调用方回退 .txt）
     */
    private static function readFromBin(string $dataPath, string $relPath, int $id, string $type, ?int $limit = null): ?string
    {
        $batch = intdiv($id, 1000);
        $dir = (rtrim($dataPath, '/\\')) . '/' . dirname(ltrim($relPath, '/\\'));
        $base = $batch * 1000; // 批次基数命名（t12000.bin）
        $prefix = ShardRouter::typePrefix($type);

        $binPath = $dir . '/' . $prefix . $base . '.bin';
        $idxPath = $dir . '/' . $prefix . $base . '.idx';
        if (!is_file($binPath) || !is_file($idxPath)) {
            return null; // 带前缀块缺失 → 回退 .txt
        }

        // 懒加载 .idx（JSON）；缓存键含目录与文件名（同一批次基数在不同桶目录各有 .idx）
        $cacheKey = 'extern_idx_' . md5($idxPath);
        $idx = null;
        if (function_exists('apcu_fetch')) {
            $idx = @apcu_fetch($cacheKey, $ok);
            if (!$ok || !is_array($idx)) {
                $idx = null;
            }
        }
        if ($idx === null) {
            $raw = @file_get_contents($idxPath);
            if ($raw === false) return null;
            $idx = json_decode($raw, true);
            if (!is_array($idx)) return null;
            if (function_exists('apcu_store')) {
                @apcu_store($cacheKey, $idx, self::APCU_TTL);
            }
        }

        if (!isset($idx[$id])) {
            return null; // 迁移后新帖不在索引 → 回退 .txt
        }
        $offset = (int)$idx[$id];

        $fh = @fopen($binPath, 'rb');
        if (!$fh) return null;
        try {
            if (@fseek($fh, $offset) !== 0) return null;
            $lenBytes = @fread($fh, 4);
            if (strlen($lenBytes) !== 4) return null;
            $len = unpack('N', $lenBytes)[1];
            // 长度异常（≤0 或超 16MB）静默返回 null 会让大帖显示空白且无迹可查——补告警
            if ($len <= 0 || $len > 16 * 1024 * 1024) {
                \error_log('SplitDB: .bin 记录长度异常（' . $len . ' 字节），跳过该条: ' . $binPath . ' #' . $id);
                return null;
            }
            // 方案 C：局部读取时把 fread 长度钳到 limit（0 < len <= limit，防越过文件内容）
            if ($limit !== null && $len > $limit) {
                $len = $limit;
            }
            $content = @fread($fh, $len);
            return $content !== false ? $content : null;
        } finally {
            fclose($fh);
        }
    }

    /**
     * 删除外置正文文件（软删除/硬删除时调用），并清除 APCu 缓存
     *
     * [P22] 已迁移 ID 的正文在 .bin 块中（只读归档，条目不可变）：
     * 删除只清理 .txt 残留与 APCu 缓存，.bin 内死字节无害、不重写（避免整块重排）。
     *
     * @param string $type 内容类型 'topic'|'reply'（决定 APCu 键，P0-1）
     */
    public static function delete(string $dataPath, string $relPath, int $topicId, string $type): bool
    {
        if ($relPath === '') {
            return false;
        }
        $absPath = (rtrim($dataPath, '/\\')) . '/' . ltrim($relPath, '/\\');
        if (is_file($absPath)) {
            @unlink($absPath);
        }
        if (function_exists('apcu_delete')) {
            @apcu_delete(self::cacheKey($topicId, $type));
        }
        return !file_exists($absPath);
    }
}
