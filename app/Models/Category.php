<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 板块分类模型 — 板块排序、层级管理、帖子统计
 * @file app/Models/Category.php
 * @package app\Models
 */

namespace app\Models;

use app\Core\Model;

class Category extends Model
{
    protected $table = 'categories';
    /** 可更新列白名单：后台版块编辑 + smoke 用例共用 */
    protected $fillable = ['name', 'description', 'sort_order', 'icon', 'show_icon'];

    /** @var array|null 请求内缓存：getCategoryStats() 结果（避免同请求重复查库） */
    private static $catStatsCache = null;

    /**
     * 主动失效版块统计缓存：发帖/回帖后调用，删除文件缓存 + 重置请求内缓存，
     * 使版块页统计立即反映新内容
     */
    public static function invalidateCategoryStats(): void
    {
        self::$catStatsCache = null;
        // 统一缓存根：data/runtime/（与 Blog/Settings 的文件缓存一致）
        $cacheFile = self::categoryStatsPath();
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
        // 兼容清理：旧版缓存曾置于 protected/cache/，统一路径后遗留文件一并删除
        $legacy = __DIR__ . '/../../protected/cache/category_stats.cache';
        if (is_file($legacy)) {
            @unlink($legacy);
        }
    }

    /**
     * 版块统计缓存文件路径（data/runtime/，与 Blog/Settings 文件缓存同根）
     */
    private static function categoryStatsPath(): string
    {
        $root = rtrim(\app\SplitDB\ShardRouter::dataPath(), '/\\');
        return $root . '/runtime/category_stats.cache';
    }

    public function allOrdered()
    {
        return $this->all('sort_order ASC, id ASC');
    }

    /**
     * 获取每个版块的主题数映射 [category_id => count]
     * 改读 main_index.topic_index（分片真相源的最终一致性视图）
     * 永久文件缓存 + 请求内静态缓存（发帖/回帖时由 invalidateCategoryStats() 主动失效）
     */
    public function getThreadCountMap(): array
    {
        $out = [];
        foreach ($this->getCategoryStats() as $cid => $s) {
            $out[$cid] = $s['count'];
        }
        return $out;
    }

    /**
     * 获取每个版块的回复数映射 [category_id => count]
     * 改读 main_index.topic_index 的 reply_count 聚合
     * 永久文件缓存 + 请求内静态缓存（发帖/回帖时由 invalidateCategoryStats() 主动失效）
     */
    public function getPostCountMap(): array
    {
        $out = [];
        foreach ($this->getCategoryStats() as $cid => $s) {
            $out[$cid] = $s['posts'];
        }
        return $out;
    }

    /**
     * 合并统计：一次 GROUP BY 同时产出主题数 + 回复数两份映射，
     * 供 getThreadCountMap / getPostCountMap / Thread::countActive 复用，避免版块页多次全表扫描。
     * 永久文件缓存（data/runtime/category_stats.cache）+ 请求内静态缓存，
     * 发帖/回帖时由 invalidateCategoryStats() 主动失效。
     *
     * @return array<int, array{count:int, posts:int}> category_id => ['count'=>, 'posts'=>]
     */
    public function getCategoryStats(): array
    {
        if (self::$catStatsCache !== null) {
            return self::$catStatsCache;
        }
        $cacheFile = self::categoryStatsPath();
        // 永久缓存：不按 60s TTL 过期（避免大表 GROUP BY 周期性全表扫描），
        // 仅在发帖/回帖时由 invalidateCategoryStats() 主动失效
        if (file_exists($cacheFile)) {
            $data = @file_get_contents($cacheFile);
            if ($data !== false) {
                $cached = @json_decode($data, true); // 用 json 避免 unserialize RCE
                if (is_array($cached)) {
                    self::$catStatsCache = $cached;
                    return $cached;
                }
            }
        }
        $rows = \app\SplitDB\Schema::mainIndexDb()->query(
            'SELECT category_id, COUNT(*) as cnt, COALESCE(SUM(reply_count), 0) as posts
             FROM topic_index WHERE status = 0 AND deleted_at IS NULL GROUP BY category_id'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['category_id']] = [
                'count' => (int)$row['cnt'],
                'posts' => (int)$row['posts'],
            ];
        }
        self::$catStatsCache = $map;
        @file_put_contents($cacheFile, json_encode($map, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $map;
    }

    /**
     * 版块最新帖快照文件
     * data/runtime/category_{category_id}_latest.json — 发帖/回帖时由 refreshLatestSnapshot() 刷新，
     * 论坛/首页"每版块最新帖"读取优先走本快照，避免高频列表页实时查 topic_index 大表。
     * 快照缺失/损坏时回退查询并重建。
     */
    private const LATEST_SNAPSHOT_LIMIT = 10;

    public static function latestSnapshotPath(int $categoryId): string
    {
        $root = rtrim(\app\SplitDB\ShardRouter::dataPath(), '/\\');
        return $root . '/runtime/category_' . (int)$categoryId . '_latest.json';
    }

    /**
     * 刷新指定版块的最新帖快照（发帖/回帖后调用）
     *
     * 读 main_index.topic_index（同版块最新 N 条，索引命中 LIMIT 10）；
     * 用户名从 business.users 批量补齐（跨库，一次 IN 查询）。
     */
    public static function refreshLatestSnapshot(int $categoryId): void
    {
        $categoryId = (int)$categoryId;
        if ($categoryId <= 0) {
            return;
        }
        $rows = \app\SplitDB\Schema::mainIndexDb()->query(
            'SELECT id, uid, title, create_time, last_reply_time, is_pinned, is_highlighted, color FROM topic_index
             WHERE status = 0 AND deleted_at IS NULL AND category_id = ' . $categoryId . '
             ORDER BY is_pinned DESC, last_reply_time DESC, create_time DESC
             LIMIT ' . self::LATEST_SNAPSHOT_LIMIT
        )->fetchAll(\PDO::FETCH_ASSOC);
        if (empty($rows)) {
            // 版块暂无帖子：写空快照（或删除旧快照），避免残留过期数据
            @unlink(self::latestSnapshotPath($categoryId));
            return;
        }

        // 批量补用户名（business.users）
        // ★ array_values 必须：array_unique 保留原键（同作者多帖时键不连续，如 [0=>5, 2=>7]），
        //   PDO execute 位置参数要求数组 0 起始连续，否则报 number of bound variables mismatch
        $uids = array_values(array_unique(array_map(fn($r) => (int)$r['uid'], $rows)));
        $users = [];
        if (!empty($uids)) {
            $marks = implode(',', array_fill(0, count($uids), '?'));
            $ur = \app\Core\Database::getInstance()->query('SELECT id, username FROM users WHERE id IN (' . $marks . ')', $uids);
            foreach ($ur as $u) {
                $users[(int)$u['id']] = $u['username'];
            }
        }

        $snapshot = [];
        foreach ($rows as $r) {
            $snapshot[] = [
                'id'             => (int)$r['id'],
                'title'          => (string)$r['title'],
                'user_id'        => (int)$r['uid'],
                'username'       => $users[(int)$r['uid']] ?? '',
                'created_at'     => (int)$r['create_time'],
                'last_reply_time'=> (int)($r['last_reply_time'] ?? 0),
                // 快照补带展示字段：首页/论坛列表的置顶、精华、标题颜色
                'is_pinned'      => (int)($r['is_pinned'] ?? 0),
                'is_highlighted' => (int)($r['is_highlighted'] ?? 0),
                'color'          => (string)($r['color'] ?? ''),
            ];
        }

        $file = self::latestSnapshotPath($categoryId);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($file, json_encode($snapshot, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * 读取指定版块的最新帖快照（读取路径优先）；快照缺失/损坏时回退查询并重建
     *
     * @return array<int, array{id:int, title:string, user_id:int, username:string, created_at:int, last_reply_time:int}>
     */
    public static function getLatestThreads(int $categoryId, int $limit = 5): array
    {
        $categoryId = (int)$categoryId;
        if ($categoryId <= 0) {
            return [];
        }
        $file = self::latestSnapshotPath($categoryId);
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $dec = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($dec)) {
                return array_slice($dec, 0, max(1, $limit));
            }
        }
        // 回退：查询并重建快照（兜底，首次访问或文件损坏时）
        self::refreshLatestSnapshot($categoryId);
        $raw = @file_get_contents($file);
        $dec = $raw !== false ? json_decode($raw, true) : null;
        return is_array($dec) ? array_slice($dec, 0, max(1, $limit)) : [];
    }

    /**
     * 删除版块并将该版块下的帖子重置到其他版块（帖子仍可见）
     * 分片感知：同步更新分片桶 topic 行 + main_index.topic_index
     *
     * @return bool 是否已删除；false = 无其他版块可承接（最后/唯一版块），拒绝删除
     */
    public function deleteWithReset(int $id): bool
    {
        $mi = \app\SplitDB\Schema::mainIndexDb();

        // 承接版块 = ID 最小的「其他」版块（原实现取全表最小 ID 且未排除自身——
        // 删除最小 ID 版块或唯一版块时 fallbackId 等于被删版块自身，帖子会被重置到已删除版块）
        $fallback = $this->db->fetchOne(
            'SELECT id FROM categories WHERE id != :id ORDER BY id ASC LIMIT 1',
            [':id' => (int)$id]
        );
        if (!$fallback) {
            return false; // 无其他版块可承接帖子，拒绝删除最后一个版块
        }
        $fallbackId = (int)$fallback['id'];

        // 分片桶侧更新（按 bucket_path 分组，逐桶 UPDATE）
        $rows = $mi->query('SELECT DISTINCT bucket_path FROM topic_index WHERE category_id = ' . (int)$id)
            ->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($rows as $bucketPath) {
            \app\SplitDB\Schema::bucketFromPath($bucketPath)
                ->prepare('UPDATE topic SET category_id = :to WHERE category_id = :cid')
                ->execute([':to' => $fallbackId, ':cid' => (int)$id]);
        }

        // 索引侧更新
        $mi->prepare('UPDATE topic_index SET category_id = :to WHERE category_id = :cid')
            ->execute([':to' => $fallbackId, ':cid' => (int)$id]);

        $this->delete($id);

        // 版块删除改变各版块主题/回复归属 → 失效统计缓存（文件 + 请求内），
        // 与发帖/回帖时的 invalidateCategoryStats() 口径一致，避免论坛页继续显示旧计数
        self::invalidateCategoryStats();

        return true;
    }
}
