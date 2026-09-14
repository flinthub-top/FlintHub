<?php
/**
 * FlintHub 1.0 (SplitDB) — 分片路由
 * 路由规则：
 *   一级分区：季度时序分区（YYYYQn，如 2026Q3），新数据写入当前季度
 *   二级分区：哈希分桶（默认 32 桶），写入路由 = ID % 桶数量
 *   读取时永远以 main_index.bucket_path 为准，不再二次哈希
 *   未来季度可平滑升级 32 → 64 → 128 桶，历史数据零迁移
 *
 * 路径约定（相对 DATA_PATH）：
 *   桶文件：bucket/active/{YYYYQn}/{bucket}.sqlite  → main_index.bucket_path
 *   归档桶：bucket/archive/{YYYYQn}/{bucket}.sqlite
 *   外置正文：extern/{YYYY}/{Qn}/{bucket}/{t|r}{id}.txt
 *     （t=主题 / r=回复：主题与回复 ID 区间重叠，文件名按类型加前缀隔离，
 *       避免同桶同季度下不同内容互相覆盖/串读；目录可共用）
 *
 * 命名空间隔离：
 *   externRel()/externAbs()/ensureExternDir() 均带 $type（'topic'|'reply'）参数，
 *   文件名 = typePrefix($type) . "{id}.txt"（t5.txt / r5.txt）。
 *   .bin/.idx 归档块同样按类型前缀命名（t12000.bin / r12000.bin），
 *   ExternStorage 读取时优先命中带前缀的块，缺失则回退旧版裸名块（存量数据兼容）。
 *
 * @file app/SplitDB/ShardRouter.php
 * @package app\SplitDB
 */

namespace app\SplitDB;

class ShardRouter
{
    /** 默认桶数量（32） */
    public const DEFAULT_BUCKET_SIZE = 32;

    /**
     * 桶数量：优先取 config.php 的 SPLITDB_BUCKET_SIZE，否则 32
     */
    public static function bucketSize(): int
    {
        return defined('SPLITDB_BUCKET_SIZE') && (int)SPLITDB_BUCKET_SIZE > 0
            ? (int)SPLITDB_BUCKET_SIZE
            : self::DEFAULT_BUCKET_SIZE;
    }

    /**
     * 数据根目录：优先取 config.php 的 SPLITDB_DATA_PATH，否则站点根 /data
     */
    public static function dataPath(): string
    {
        return defined('SPLITDB_DATA_PATH') && SPLITDB_DATA_PATH !== ''
            ? rtrim((string)SPLITDB_DATA_PATH, '/\\')
            : rtrim(dirname(__DIR__, 2), '/\\') . '/data';
    }

    /**
     * 一级分区：季度标识，如 2026Q3
     *
     * @param int|null $timestamp 时间戳，默认当前时间
     */
    public static function quarter(?int $timestamp = null): string
    {
        $ts = $timestamp ?: time();
        return date('Y', $ts) . 'Q' . (int)ceil((int)date('n', $ts) / 3);
    }

    /**
     * 二级分区：哈希分桶（ID % 桶数量）
     */
    public static function bucket(int $id, ?int $bucketSize = null): int
    {
        return $id % ($bucketSize ?: self::bucketSize());
    }

    /**
     * 新帖写入路由（智能扩容路由策略）
     *
     * 扩容后（SPLITDB_LAST_EXPANSION_AT > 0 且帖子创建时间 ≥ 扩容时间戳）：
     *   强制进入新增桶编号范围 [PREV, NEW)——旧桶不再接收任何新主题帖；
     * 未扩容 / 历史帖导入（created_at 早于扩容时间戳）/ 常量缺失或非法：
     *   沿用旧哈希路由 id % bucketSize（还原历史分布语义，与现状一致）。
     *
     * @param int $id        帖子全局 ID
     * @param int $createdAt 帖子创建时间戳
     */
    public static function bucketForWrite(int $id, int $createdAt): int
    {
        $size   = self::bucketSize();
        $lastAt = defined('SPLITDB_LAST_EXPANSION_AT') ? (int)SPLITDB_LAST_EXPANSION_AT : 0;
        $prev   = defined('SPLITDB_PREV_BUCKET_SIZE') ? (int)SPLITDB_PREV_BUCKET_SIZE : 0;
        if ($lastAt > 0 && $createdAt >= $lastAt && $prev > 0 && $prev < $size) {
            // ★ [审计修复 2026-08-20] 显式兜底：扩容配置出错（差值为 0 或负数，如
            //   SPLITDB_PREV_BUCKET_SIZE 误设为等于 SPLITDB_BUCKET_SIZE）时，
            //   直接回落旧哈希路由，防止 % ($size - $prev) 触发 DivisionByZeroError
            $delta = $size - $prev;
            if ($delta <= 0) {
                \error_log('ShardRouter: 非法扩容配置 prev=' . $prev . ' size=' . $size . '，回落旧哈希路由');
                return $id % $size;
            }
            // 扩容后新帖 → 新增桶范围 [PREV, NEW)
            return $prev + ($id % $delta);
        }
        return $id % $size;
    }

    /**
     * 桶文件相对路径（写入 main_index.bucket_path 的唯一依据）
     */
    public static function bucketRel(string $quarter, int $bucket): string
    {
        return "bucket/active/{$quarter}/{$bucket}.sqlite";
    }

    /**
     * 桶文件绝对路径
     */
    public static function bucketAbs(string $quarter, int $bucket, ?string $dataPath = null): string
    {
        return (rtrim($dataPath ?: self::dataPath(), '/\\')) . '/' . self::bucketRel($quarter, $bucket);
    }

    /**
     * 归档桶相对路径（18 个月以上冷数据，archive.php 使用）
     */
    public static function bucketArchiveRel(string $quarter, int $bucket): string
    {
        return "bucket/archive/{$quarter}/{$bucket}.sqlite";
    }

    /**
     * 外置正文命名空间前缀：主题 → 't'，回复 → 'r'
     *
     * 主题与回复的全局 ID 序列各自从 1 起（ID 区间重叠），APCu 缓存键与 .txt/.bin 文件名
     * 必须按类型加前缀隔离，否则同 ID 不同内容会互相覆盖/串读。
     */
    public static function typePrefix(string $type): string
    {
        return $type === 'reply' ? 'r' : 't';
    }

    /**
     * 外置正文相对路径：extern/{YYYY}/{Qn}/{bucket}/{t|r}{id}.txt
     *
     * @param string $type 'topic'|'reply'（决定文件名前缀）
     */
    public static function externRel(int $topicId, string $quarter, int $bucket, string $type): string
    {
        $year = substr($quarter, 0, 4);
        $q    = substr($quarter, 4); // 如 Q3
        return "extern/{$year}/{$q}/{$bucket}/" . self::typePrefix($type) . "{$topicId}.txt";
    }

    /**
     * 外置正文绝对路径
     */
    public static function externAbs(int $topicId, string $quarter, int $bucket, string $type, ?string $dataPath = null): string
    {
        return (rtrim($dataPath ?: self::dataPath(), '/\\')) . '/' . self::externRel($topicId, $quarter, $bucket, $type);
    }

    /**
     * 从 bucket_path（如 bucket/active/2026Q3/5.sqlite）提取桶编号
     */
    public static function bucketFromPath(string $bucketPath): int
    {
        // ^...$ 锚定（防御深度），防止畸形前缀/后缀混入路径仍被解析出桶号
        if (preg_match('#^bucket/(?:active|archive)/(?:\d{4}Q[1-4])/(\d+)\.sqlite$#', $bucketPath, $m)) {
            return (int)$m[1];
        }
        throw new \RuntimeException("SplitDB: 非法 bucket_path: {$bucketPath}");
    }

    /**
     * 确保桶目录存在（不存在则递归创建，返回桶文件绝对路径）
     */
    public static function ensureBucketDir(string $quarter, int $bucket, ?string $dataPath = null): string
    {
        $abs = self::bucketAbs($quarter, $bucket, $dataPath);
        $dir = dirname($abs);
        // mkdir 失败必须发声，否则后续打开库会抛更隐晦的错误（目录不存在）
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            \error_log("SplitDB: 创建桶目录失败: {$dir}");
        }
        return $abs;
    }

    /**
     * 确保 extern 目录存在，返回正文文件绝对路径
     */
    public static function ensureExternDir(int $topicId, string $quarter, int $bucket, string $type, ?string $dataPath = null): string
    {
        $abs = self::externAbs($topicId, $quarter, $bucket, $type, $dataPath);
        $dir = dirname($abs);
        // mkdir 失败必须发声，否则正文写入会在更深层抛隐晦错误
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            \error_log("SplitDB: 创建 extern 目录失败: {$dir}");
        }
        return $abs;
    }
}
