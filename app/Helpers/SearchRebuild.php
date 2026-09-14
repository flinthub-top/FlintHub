<?php
/**
 * FlintHub — 搜索重建断点续建 Helper
 *
 * processRebuild 由 OFFSET 分页改造为 keyset 游标（WHERE id > last_id）+ 断点续建后，
 * 本类统一负责断点文件的读写、指纹校验与重置：
 *
 *   - 断点文件：data/runtime/search_rebuild_checkpoint.json（受 nginx/protected 防护，非 Web 可读）
 *   - 结构：{"phase":"threads","threads_last":0,"blogs_last":0,
 *            "include_content":"0","processed_threads":0,"processed_blogs":0,"ts":...}
 *   - phase：双阶段独立 id 空间 —— "threads"=帖子(topic_index.id) / "blogs"=博客(blogs.id)
 *     （两个阶段 id 互不相干，必须各自维护游标，不能共用一个 last_id）
 *   - include_content：指纹字段，重建中途开关变更（仅标题 ↔ 标题+正文）时断点失效、强制从头；
 *   - 容错：文件缺失/损坏/字段非法 → 返回 null（调用方从头开始）；写入失败仅 error_log 不中断。
 *
 * @file app/Helpers/SearchRebuild.php
 * @package app\Helpers
 */

namespace app\Helpers;

class SearchRebuild
{
    /** 断点文件名（存于 data/runtime/ 下） */
    private const FILE = 'search_rebuild_checkpoint.json';

    /**
     * 断点文件绝对路径（自动建 runtime 目录）
     */
    public static function checkpointFile(): string
    {
        $dir = rtrim(\app\SplitDB\ShardRouter::dataPath(), '/\\') . '/runtime';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/' . self::FILE;
    }

    /**
     * 读取断点。文件不存在 / JSON 损坏 / 字段非法 → 返回 null（调用方按"从头"处理）。
     *
     * @return array|null ['phase','threads_last','blogs_last','include_content','processed_threads','processed_blogs','ts']
     */
    public static function read(): ?array
    {
        $file = self::checkpointFile();
        clearstatcache(true, $file); // 清 stat 缓存，确保读到最新文件状态
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $cp = @json_decode($raw, true);
        if (!is_array($cp)) {
            return null;
        }

        // 字段合法性校验（损坏/半写入 → 视为无效断点，从头）
        if (!isset($cp['phase']) || !in_array($cp['phase'], ['threads', 'blogs'], true)) {
            return null;
        }
        foreach (['threads_last', 'blogs_last'] as $k) {
            if (!isset($cp[$k]) || !is_numeric($cp[$k]) || (int)$cp[$k] < 0) {
                return null;
            }
        }
        if (!isset($cp['include_content']) || !in_array((string)$cp['include_content'], ['0', '1'], true)) {
            return null;
        }

        return [
            'phase'             => $cp['phase'],
            'threads_last'      => (int)$cp['threads_last'],
            'blogs_last'        => (int)$cp['blogs_last'],
            'include_content'   => (string)$cp['include_content'],
            'processed_threads' => (int)($cp['processed_threads'] ?? 0),
            'processed_blogs'   => (int)($cp['processed_blogs'] ?? 0),
            'ts'                => (int)($cp['ts'] ?? time()),
        ];
    }

    /**
     * 写入断点（每次成功处理完一批后调用）。
     * 失败仅 error_log、不抛异常不中断重建——最坏情况是中断后从头开始，可接受。
     *
     * @param array $cp 完整断点结构（与 read() 返回同构）
     */
    public static function write(array $cp): void
    {
        $cp['ts'] = time();
        $file = self::checkpointFile();
        $ok = @file_put_contents($file, json_encode($cp, JSON_UNESCAPED_UNICODE), LOCK_EX);
        clearstatcache(true, $file); // 写后清 stat 缓存，保证后续 read()/is_file 立即生效
        if ($ok === false) {
            \error_log('SearchRebuild: 断点写入失败（重建可继续，中断后将从头开始）');
        }
    }

    /**
     * 删除断点（重建整体完成 / 强制从头时调用）
     */
    public static function clear(): void
    {
        $file = self::checkpointFile();
        if (is_file($file)) {
            @unlink($file);
        }
        clearstatcache(true, $file);
    }

    /**
     * 初始断点结构（从头开始：phase=threads，双游标归零）
     */
    public static function initial(string $includeContent): array
    {
        return [
            'phase'             => 'threads',
            'threads_last'      => 0,
            'blogs_last'        => 0,
            'include_content'   => $includeContent,
            'processed_threads' => 0,
            'processed_blogs'   => 0,
            'ts'                => time(),
        ];
    }

    /**
     * 指纹校验：断点 include_content 与当前设置不一致 → 断点失效（调用方须清空索引、从头）。
     * 文件缺失/损坏 → 同样返回 null（从头）。
     *
     * @param string $includeContent 当前设置：'1'=索引标题+正文 / '0'=仅标题
     * @return array|null 有效断点；null = 需从头
     */
    public static function validated(string $includeContent): ?array
    {
        $cp = self::read();
        if ($cp === null) {
            return null;
        }
        if ($cp['include_content'] !== $includeContent) {
            \error_log('SearchRebuild: 全文索引开关已变更（' . $cp['include_content'] . ' → ' . $includeContent . '），忽略旧断点，从头重建');
            self::clear();
            return null;
        }
        return $cp;
    }
}
