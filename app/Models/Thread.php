<?php
/**
 * FlintHub 1.0 (SplitDB) — 帖子模型（分片路由读写）
 * 白皮书 8.x 读写流程：
 *   写入：global_id 取号 → 季度+哈希路由 → 写 Bucket(topic) 真相源 + 正文 extern → 写 main_index 索引行
 *   读取：列表/分页走 main_index.topic_index → 详情按 bucket_path 读单桶 + extern 正文
 * 时间：桶/索引统一秒级时间戳 INTEGER，读取时转 'Y-m-d H:i:s' 字符串（视图兼容）
 * @file app/Models/Thread.php
 * @package app\Models
 */

namespace app\Models;

use app\Core\Model;
use app\SplitDB\ExternStorage;
use app\SplitDB\IDGenerator;
use app\SplitDB\Queue;
use app\SplitDB\Schema;
use app\SplitDB\ShardRouter;
use app\SplitDB\ViewCounter;

class Thread extends Model
{
    /** 兼容保留：business.sqlite 的 threads 表（历史过渡遗留，现已不再写入） */
    protected $table = 'threads';

    /** 帖子状态：0 正常 / 1 已删除 */
    private const STATUS_NORMAL = 0;
    private const STATUS_DELETED = 1;

    /**
     * 标题预设色白名单（自由选色收敛为预设色板，防颜色混乱）
     * 与 admin/thread_edit.php 的 title-color-palette 色板一一对应；前台编辑方案 A 共用。
     * '' 表示无颜色（继承主题色）。
     */
    public const PRESET_TITLE_COLORS = ['', '#dc2626', '#d97706', '#16a34a', '#2563eb', '#7c3aed'];

    // ==================== 分片写入 ====================

    /**
     * 分片写入新帖（覆写 Model::insert）
     * 流程：global_id 取号 → 路由 → 写桶 + extern 正文 → 写 main_index 索引行 → 返回主题 ID
     */
    public function insert($data)
    {
        // 1. 全局 ID 取号（白皮书 8.1 步骤 1）
        $id = IDGenerator::nextId('topic', Schema::globalIdDb());

        $now = time();
        $quarter = ShardRouter::quarter($now);
        // 新帖写入路由：扩容后进新增桶范围，旧桶零新帖（按 created_at 与扩容时间戳判定）
        $createdAt = isset($data['created_at']) && is_string($data['created_at'])
            ? (int)strtotime($data['created_at']) : $now;
        $bucket = ShardRouter::bucketForWrite($id, $createdAt);

        // 2. 正文剥离 SQLite → extern 原子写入（白皮书红线）
        $content = (string)($data['content'] ?? '');
        $externPath = ExternStorage::write(ShardRouter::dataPath(), $id, $bucket, 'topic', $content);

        // 2.5 生成摘要并落库（方案 A：写帖时一次性算好，列表渲染零文件读）
        $__ex = self::extractExcerptContent($content);
        $excerpt = $__ex['plain'];
        $excerptImages = implode(',', $__ex['images']);

        // 3. 写桶 topic（唯一真相源）
        $bdb = Schema::bucketDb($quarter, $bucket);
        $stmt = $bdb->prepare(
            'INSERT INTO topic (id, uid, category_id, title, create_time, update_time,
                                last_reply_time, status, is_pinned, is_highlighted,
                                color, reply_to_view, extern_path, excerpt, excerpt_images)
             VALUES (:id, :uid, :cid, :title, :ct, :ut, :lrt, :status, :pin, :hl, :color, :rtv, :extern, :excerpt, :eimg)'
        );
        $stmt->execute([
            ':id'     => $id,
            ':uid'    => (int)($data['user_id'] ?? 0),
            ':cid'    => (int)($data['category_id'] ?? 0),
            ':title'  => (string)($data['title'] ?? ''),
            ':ct'     => $createdAt,
            ':ut'     => $now,
            ':lrt'    => $createdAt,
            ':status' => self::STATUS_NORMAL,
            ':pin'    => (int)($data['is_pinned'] ?? 0),
            ':hl'     => (int)($data['is_highlighted'] ?? 0),
            ':color'  => (string)($data['color'] ?? ''),
            ':rtv'    => (int)($data['reply_to_view'] ?? 0),
            ':extern' => $externPath,
            ':excerpt' => $excerpt,
            ':eimg'    => $excerptImages,
        ]);

        // 4. 写 main_index 索引行（UPSERT，最终一致性视图）
        $mi = Schema::mainIndexDb();
        $mi->prepare(
            'INSERT INTO topic_index (id, uid, title, create_time, last_reply_time, view_count,
                                      reply_count, status, category_id, is_pinned, is_highlighted,
                                      color, reply_to_view, deleted_at, bucket_path, excerpt, excerpt_images)
             VALUES (:id, :uid, :title, :ct, :lrt, 0, 0, :status, :cid, :pin, :hl, :color, :rtv, NULL, :bpath, :excerpt, :eimg)
             ON CONFLICT(id) DO UPDATE SET
                uid = :uid, title = :title, create_time = :ct, last_reply_time = :lrt,
                status = :status, category_id = :cid, is_pinned = :pin, is_highlighted = :hl,
                color = :color, reply_to_view = :rtv, bucket_path = :bpath, excerpt = :excerpt, excerpt_images = :eimg'
        )->execute([
            ':id' => $id, ':uid' => (int)($data['user_id'] ?? 0), ':title' => (string)($data['title'] ?? ''),
            ':ct' => $createdAt, ':lrt' => $createdAt, ':status' => self::STATUS_NORMAL,
            ':cid' => (int)($data['category_id'] ?? 0), ':pin' => (int)($data['is_pinned'] ?? 0),
            ':hl' => (int)($data['is_highlighted'] ?? 0), ':color' => (string)($data['color'] ?? ''),
            ':rtv' => (int)($data['reply_to_view'] ?? 0), ':bpath' => ShardRouter::bucketRel($quarter, $bucket),
            ':excerpt' => $excerpt, ':eimg' => $excerptImages,
        ]);

        // 5. 按队列调度模式处理搜索索引与统计（queue_mode: sync=同步直写 / cron|cli=入队异步）
        $mode = \app\Helpers\Settings::get('queue_mode', 'sync');
        if ($mode === 'sync') {
            // 模式 A：同步直写（实时索引 + 实时统计，与 worker handler 同构，数据 100% 实时）
            \app\Helpers\Search::indexThread($id);
            \app\Helpers\Settings::runtimeBuild();
        } else {
            // 模式 B/C：入队重建搜索索引 + 刷新全站统计（worker/cron 消费）
            //   必须同时入队 stats，否则 cron/cli 模式下发帖后 total_threads 断链不更新
            Queue::push('rebuild_search', ['topic_id' => $id]);
            Queue::push('stats', ['type' => 'thread', 'thread_id' => $id, 'user_id' => (int)($data['user_id'] ?? 0)]);
        }

        // 6. 主动失效版块统计缓存（新帖立即可见，无需等 60s TTL）
        \app\Models\Category::invalidateCategoryStats();

        // 6.5. 刷新版块最新帖快照（论坛/首页"每版块最新帖"走 JSON 快照，避免实时查大表）
        \app\Models\Category::refreshLatestSnapshot((int)($data['category_id'] ?? 0));

        // 7. 主动失效列表页静态缓存（发帖后首页/版块列表立即刷新）
        \app\Helpers\PageCache::invalidate();

        return $id;
    }

    // ==================== 分片读取 ====================

    /**
     * 按主键读取（main_index → bucket_path → 单桶 + extern 正文）
     */
    public function find($id): ?array
    {
        return $this->getById((int)$id);
    }

    /**
     * 帖子详情：索引行 + 桶行 + extern 正文 + 用户/分类信息
     */
    public function getById($id)
    {
        $id = (int)$id;
        $indexRow = $this->fetchIndexRow($id);
        if (!$indexRow || (int)$indexRow['status'] === self::STATUS_DELETED) {
            return null;
        }

        $bucketRow = $this->fetchBucketRow($indexRow['bucket_path'], $id);
        if (!$bucketRow) {
            return null;
        }

        // extern 正文
        $content = ExternStorage::read(ShardRouter::dataPath(), $bucketRow['extern_path'], $id, 'topic');

        // 组装视图兼容字段 + 用户/分类（business.sqlite）
        $row = $this->decorate($bucketRow, $content);

        // 归档状态随索引行带出（is_archived 存于 main_index.topic_index，桶 topic 表无此字段）
        $row['is_archived'] = (int)($indexRow['is_archived'] ?? 0);

        return $row;
    }

    /**
     * 首页最新主题（SQL 层版块权限过滤）— 读 main_index
     */
    public function getLatestAllowed(array $allowedCategoryIds, int $limit = 10): array
    {
        if (empty($allowedCategoryIds)) return [];
        $ids = implode(',', array_fill(0, count($allowedCategoryIds), '?'));

        $stmt = Schema::mainIndexDb()->prepare(
            "SELECT * FROM topic_index
             WHERE status = " . self::STATUS_NORMAL . " AND deleted_at IS NULL
               AND category_id IN ({$ids})
             ORDER BY is_pinned DESC, last_reply_time DESC, create_time DESC
             LIMIT " . (int)$limit
        );
        $stmt->execute(array_values(array_map('intval', $allowedCategoryIds)));
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $this->decorateList($rows);
    }

    /**
     * 批量按 ID 取主题（列表场景：读 main_index + 批量补用户/分类，避免逐条 find() 的 N+1）
     * 等价于 find() 的列表视图（decorateList 不含正文/桶行），供标签页等 ID 列表场景批量取数。
     * 软删/不存在的主体会被过滤（status/deleted_at 条件与 find() 一致）。
     *
     * @param array $ids 主题 ID 列表
     * @return array 视图兼容主题列表（与 getIndexPaginated 同结构）
     */
    public function getByIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) return [];

        // 分批 IN（900/批，与 Tag::rebuild 一致的 SQLite 安全批大小）
        $out = [];
        foreach (array_chunk($ids, 900) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = Schema::mainIndexDb()->prepare(
                "SELECT * FROM topic_index
                 WHERE status = " . self::STATUS_NORMAL . " AND deleted_at IS NULL
                   AND id IN ({$marks})
                 ORDER BY is_pinned DESC, last_reply_time DESC, create_time DESC"
            );
            $stmt->execute($chunk);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $out = array_merge($out, $this->decorateList($rows));
        }
        return $out;
    }

    /**
     * 版块列表分页 — 读 main_index
     */
    public function getByCategoryPaginated($categoryId, $perPage, $offset, ?array &$firstRow = null, ?array &$lastRow = null)
    {
        $rows = Schema::mainIndexDb()->query(
            "SELECT * FROM topic_index
             WHERE status = " . self::STATUS_NORMAL . " AND deleted_at IS NULL
               AND category_id = " . (int)$categoryId . "
             ORDER BY is_pinned DESC, last_reply_time DESC, create_time DESC
             LIMIT " . (int)$perPage . " OFFSET " . (int)$offset
        )->fetchAll(\PDO::FETCH_ASSOC);

        // 普通页码模式也返回首尾行游标（供"上一页/下一页"keyset 链接）：
        // 必须取原始索引行字段（decorateList 会丢弃 last_reply_time/create_time）
        $firstRow = null;
        $lastRow = null;
        if (!empty($rows)) {
            $head = $rows[0];
            $tail = $rows[count($rows) - 1];
            $firstRow = [(int)$head['is_pinned'], (int)$head['last_reply_time'], (int)$head['create_time'], (int)$head['id']];
            $lastRow = [(int)$tail['is_pinned'], (int)$tail['last_reply_time'], (int)$tail['create_time'], (int)$tail['id']];
        }

        return $this->decorateList($rows);
    }

    /**
     * keyset 游标分页：绕过 OFFSET 深翻页的线性索引扫描
     *
     * 与 getIndexPaginated/getByCategoryPaginated 同排序（idx_topic_index_list），
     * 用行值比较 (is_pinned, last_reply_time, create_time, id) < 游标 直接定位下一页，
     * 不依赖 OFFSET。游标 = 当前页最后一条的 4 元组（由 lastRow 返回）。
     *
     * @param array       $categoryIds 版块白名单（空 = 不限）
     * @param int         $perPage
     * @param array|null  $after       上一页最后一条 [is_pinned, last_reply_time, create_time, id]；null = 第一页
     * @param bool        $highlightOnly
     * @param array|null  &$lastRow    返回本页最后一条的 4 元组（供"下一页"游标），无数据时为 null
     */
    public function getIndexCursor(array $categoryIds, int $perPage, ?array $after, bool $highlightOnly = false, ?array &$lastRow = null, ?array &$firstRow = null): array
    {
        $where = 'status = ' . self::STATUS_NORMAL . ' AND deleted_at IS NULL';
        if (!empty($categoryIds)) {
            $where .= ' AND category_id IN (' . implode(',', array_map('intval', $categoryIds)) . ')';
        }
        if ($highlightOnly) {
            $where .= ' AND is_highlighted = 1';
        }

        $sql = "SELECT * FROM topic_index WHERE {$where}";
        if ($after !== null) {
            $sql .= ' AND (is_pinned, last_reply_time, create_time, id) < ('
                . (int)$after[0] . ',' . (int)$after[1] . ',' . (int)$after[2] . ',' . (int)$after[3] . ')';
        }
        $sql .= ' ORDER BY is_pinned DESC, last_reply_time DESC, create_time DESC, id DESC LIMIT ' . (int)$perPage;

        $rows = Schema::mainIndexDb()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        $lastRow = null;
        $firstRow = null;
        if (!empty($rows)) {
            $head = $rows[0];
            $tail = $rows[count($rows) - 1];
            // 游标必须取原始索引行字段（decorateList 会丢弃 last_reply_time/create_time）
            $firstRow = [(int)$head['is_pinned'], (int)$head['last_reply_time'], (int)$head['create_time'], (int)$head['id']];
            $lastRow = [(int)$tail['is_pinned'], (int)$tail['last_reply_time'], (int)$tail['create_time'], (int)$tail['id']];
        }

        return $this->decorateList($rows);
    }

    /**
     * keyset 上一页：基于当前页第一条游标反向取上一页（与 getIndexCursor 互补）
     *
     * 行值比较 > 游标 + 升序取 LIMIT 条后反转，保证与 getIndexCursor 排序一致。
     *
     * @param array|null  $before 当前页第一条 [is_pinned, last_reply_time, create_time, id]；null = 第一页
     * @param array|null  &$firstRow 返回取回页的第一条 4 元组（供"下一页"回程），无数据时为 null
     */
    public function getIndexCursorPrev(array $categoryIds, int $perPage, ?array $before, bool $highlightOnly = false, ?array &$firstRow = null, ?array &$lastRow = null): array
    {
        $where = 'status = ' . self::STATUS_NORMAL . ' AND deleted_at IS NULL';
        if (!empty($categoryIds)) {
            $where .= ' AND category_id IN (' . implode(',', array_map('intval', $categoryIds)) . ')';
        }
        if ($highlightOnly) {
            $where .= ' AND is_highlighted = 1';
        }

        $sql = "SELECT * FROM topic_index WHERE {$where}";
        if ($before !== null) {
            $sql .= ' AND (is_pinned, last_reply_time, create_time, id) > ('
                . (int)$before[0] . ',' . (int)$before[1] . ',' . (int)$before[2] . ',' . (int)$before[3] . ')';
        }
        $sql .= ' ORDER BY is_pinned ASC, last_reply_time ASC, create_time ASC, id ASC LIMIT ' . (int)$perPage;

        $rows = Schema::mainIndexDb()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        $rows = array_reverse($rows);
        $firstRow = null;
        $lastRow = null;
        if (!empty($rows)) {
            $head = $rows[0];
            $tail = $rows[count($rows) - 1];
            // 游标必须取原始索引行字段（decorateList 会丢弃 last_reply_time/create_time）
            $firstRow = [(int)$head['is_pinned'], (int)$head['last_reply_time'], (int)$head['create_time'], (int)$head['id']];
            $lastRow = [(int)$tail['is_pinned'], (int)$tail['last_reply_time'], (int)$tail['create_time'], (int)$tail['id']];
        }

        return $this->decorateList($rows);
    }

    /**
     * @deprecated 无调用方（首页聚合走 getLatestAllowed），待确认后删除
     */
    public function getLatest($limit = 20, $categoryId = null)
    {
        $rows = Schema::mainIndexDb()->query(
            "SELECT * FROM topic_index
             WHERE status = " . self::STATUS_NORMAL . " AND deleted_at IS NULL
               AND (category_id = " . (int)$categoryId . " OR " . (int)$categoryId . " = 0)
             ORDER BY is_pinned DESC, last_reply_time DESC, create_time DESC
             LIMIT " . (int)$limit
        )->fetchAll(\PDO::FETCH_ASSOC);

        return $this->decorateList($rows);
    }

    // ==================== 修改 / 更新 ====================

    /**
     * 更新帖子（覆写 Model::update）
     * 内容变更 → 重写 extern；标题/分类/颜色/置顶等 → 桶行 + main_index 双写
     */
    public function update($id, $data)
    {
        $id = (int)$id;
        $indexRow = $this->fetchIndexRow($id);
        if (!$indexRow) return null;
        $bucketRow = $this->fetchBucketRow($indexRow['bucket_path'], $id);
        if (!$bucketRow) return null;

        $now = time();
        $bdb = Schema::bucketFromPath($indexRow['bucket_path']);
        $bucketNum = ShardRouter::bucketFromPath($indexRow['bucket_path']);

        // 1. 正文变更 → 重写 extern（原子写入）
        // 旧正文文件清理：extern 路径按 (id, 季度, 桶) 确定——同季度编辑路径相同、写入即原子覆盖，
        // 无需删除；跨季度/归档后编辑会生成新路径，此时旧 .txt 成孤儿文件，需在成功后删除。
        $oldExtern = (string)($bucketRow['extern_path'] ?? '');
        $contentExcerpt = null; // null = 本次无正文变更，不动摘录列
        $contentImages = '';
        if (array_key_exists('content', $data)) {
            // 传入旧 extern_path：跨季度/归档编辑时归档 .idx 位于旧路径所在季度目录，
            // write() 按旧路径目录作废 .idx（新路径目录下无该 .idx，否则旧归档条目永不失效）
            $externPath = ExternStorage::write(ShardRouter::dataPath(), $id, $bucketNum, 'topic', (string)$data['content'], null, $oldExtern !== '' ? $oldExtern : null);
            $bdb->prepare('UPDATE topic SET extern_path = :extern WHERE id = :id')
                ->execute([':extern' => $externPath, ':id' => $id]);
            if ($oldExtern !== '' && $oldExtern !== $externPath) {
                ExternStorage::delete(ShardRouter::dataPath(), $oldExtern, $id, 'topic');
            }
            // 方案 A：正文变更 → 重算摘要并随桶/索引用例一并落库（后续列表渲染零文件读）
            $__ex = self::extractExcerptContent((string)$data['content']);
            $contentExcerpt = $__ex['plain'];
            $contentImages = implode(',', $__ex['images']);
        }

        // 2. 桶行可更新字段（白名单映射）
        $bucketCols = [
            'title' => 'title', 'category_id' => 'category_id', 'color' => 'color',
            'reply_to_view' => 'reply_to_view', 'is_pinned' => 'is_pinned', 'is_highlighted' => 'is_highlighted',
        ];
        $sets = ['update_time' => $now];
        $bparams = [':now' => $now, ':id' => $id];
        $mparams = [];
        foreach ($bucketCols as $field => $col) {
            if (array_key_exists($field, $data)) {
                $sets[$col] = $data[$field];
                $bparams[':f_' . $col] = $data[$field];
                $mparams[':f_' . $col] = $data[$field];
            }
        }
        // 正文变更时同步落库摘要列（桶行）
        if ($contentExcerpt !== null) {
            $sets['excerpt'] = $contentExcerpt;
            $sets['excerpt_images'] = $contentImages;
            $bparams[':f_excerpt'] = $contentExcerpt;
            $bparams[':f_excerpt_images'] = $contentImages;
        }
        $setSql = implode(', ', array_map(fn($c) => "{$c} = :f_{$c}", array_keys(array_diff_key($sets, ['update_time' => 1]))));
        // 空 SET 守卫：调用方字段均不在白名单且无正文变更时 $setSql 为空，
        // 原样拼接会生成 "UPDATE topic SET update_time = :now,  WHERE ..." 尾逗号 SQL → prepare 抛异常 500。
        // main_index 分支已有 !empty($mSet) 守卫，此处对称提前返回（无可更新字段 = 无操作）。
        if ($setSql === '') {
            return true;
        }
        $bdb->prepare("UPDATE topic SET update_time = :now, {$setSql} WHERE id = :id")->execute($bparams);

        // 3. main_index 同步（无 content 列）
        $mi = Schema::mainIndexDb();
        $mSet = [];
        foreach ($bucketCols as $field => $col) {
            if (array_key_exists($field, $data)) {
                $mSet[] = "{$col} = :f_{$col}";
            }
        }
        // 正文变更时同步落库摘要列（main_index）
        if ($contentExcerpt !== null) {
            $mSet[] = 'excerpt = :f_excerpt';
            $mSet[] = 'excerpt_images = :f_excerpt_images';
            $mparams[':f_excerpt'] = $contentExcerpt;
            $mparams[':f_excerpt_images'] = $contentImages;
        }
        if (!empty($mSet)) {
            $mi->prepare('UPDATE topic_index SET ' . implode(', ', $mSet) . ' WHERE id = :id')
               ->execute(array_merge($mparams, [':id' => $id]));
        }

        // 4. 按队列调度模式重建搜索索引（标题/正文变更后重索引）
        //    queue_mode: sync=同步直写（控制器 ThreadController#L342 已同步 Search::indexThread，此处不重复重建也不入队）；
        //               cron|cli=入队异步（worker/cron 消费后 Search::indexThread）
        $mode = \app\Helpers\Settings::get('queue_mode', 'sync');
        if ($mode !== 'sync') {
            Queue::push('rebuild_search', ['topic_id' => $id]);
        }

        // 编辑后同步刷新：版块最新帖快照（标题/最后回复变化）、版块统计、列表页缓存
        \app\Models\Category::refreshLatestSnapshot((int)($indexRow['category_id'] ?? 0));
        \app\Models\Category::invalidateCategoryStats();
        \app\Helpers\PageCache::invalidate();

        return true;
    }

    // ==================== 计数 / 列表（Forum 前台） ====================

    /**
     * 活跃主题计数（读 main_index，替代原 threads 表 COUNT）
     *
     * @param array $categoryIds  版块 ID 白名单（空 = 不限）
     * @param bool  $highlightOnly 仅精华帖
     */
    public function countActive(array $categoryIds = [], bool $highlightOnly = false): int
    {
        // 常规统计接入 Category::getCategoryStats() 合并缓存（一次 GROUP BY 出全部版块主题数，60s 文件缓存）
        if (!$highlightOnly) {
            $stats = (new \app\Models\Category())->getCategoryStats();
            if (empty($categoryIds)) {
                $total = 0;
                foreach ($stats as $s) { $total += $s['count']; }
                return $total;
            }
            $total = 0;
            foreach ($categoryIds as $cid) {
                $total += $stats[(int)$cid]['count'] ?? 0;
            }
            return $total;
        }

        // 精华帖统计：需 is_highlighted 过滤，无法从聚合缓存取得，保留原 SQL
        $where = 'status = ' . self::STATUS_NORMAL . ' AND deleted_at IS NULL AND is_highlighted = 1';
        if (!empty($categoryIds)) {
            $where .= ' AND category_id IN (' . implode(',', array_map('intval', $categoryIds)) . ')';
        }
        return (int)Schema::mainIndexDb()->query(
            "SELECT COUNT(*) FROM topic_index WHERE {$where}"
        )->fetchColumn();
    }

    /**
     * 论坛首页列表（读 main_index + 批量补用户/分类）
     *
     * @param array $categoryIds  版块 ID 白名单（空 = 不限）
     * @param bool  $highlightOnly 仅精华帖
     */
    public function getIndexPaginated(array $categoryIds, int $perPage, int $offset, bool $highlightOnly = false, ?array &$firstRow = null, ?array &$lastRow = null): array
    {
        $where = 'status = ' . self::STATUS_NORMAL . ' AND deleted_at IS NULL';
        if (!empty($categoryIds)) {
            $where .= ' AND category_id IN (' . implode(',', array_map('intval', $categoryIds)) . ')';
        }
        if ($highlightOnly) {
            $where .= ' AND is_highlighted = 1';
        }
        $rows = Schema::mainIndexDb()->query(
            "SELECT * FROM topic_index WHERE {$where}
             ORDER BY is_pinned DESC, last_reply_time DESC, create_time DESC
             LIMIT " . (int)$perPage . " OFFSET " . (int)$offset
        )->fetchAll(\PDO::FETCH_ASSOC);

        // 普通页码模式也返回首尾行游标（供"上一页/下一页"keyset 链接）：
        // 必须取原始索引行字段（decorateList 会丢弃 last_reply_time/create_time）
        $firstRow = null;
        $lastRow = null;
        if (!empty($rows)) {
            $head = $rows[0];
            $tail = $rows[count($rows) - 1];
            $firstRow = [(int)$head['is_pinned'], (int)$head['last_reply_time'], (int)$head['create_time'], (int)$head['id']];
            $lastRow = [(int)$tail['is_pinned'], (int)$tail['last_reply_time'], (int)$tail['create_time'], (int)$tail['id']];
        }

        return $this->decorateList($rows);
    }

    // ==================== 计数与浏览 ====================

    /**
     * 浏览量 +1（APCu 内存计数 / 无 APCu 静默降级 UPDATE，补充要求 #6）
     */
    public function incrementView($id)
    {
        ViewCounter::inc((int)$id);
    }

    /**
     * 回复计数 +1（桶 + 索引双写）
     */
    public function incrementReplyCount(int $id, string $lastReplyAt): void
    {
        $lrt = strtotime($lastReplyAt) ?: time();
        $this->updateCounters($id, $lrt, 1);
    }

    /**
     * 回复计数 -1（防负保护，SQLite 标量 MAX）
     */
    public function decrementReplyCount(int $id): void
    {
        $row = $this->fetchIndexRow((int)$id);
        $lrt = $row ? (int)$row['last_reply_time'] : time();
        $this->updateCounters((int)$id, $lrt, -1);
    }

    /**
     * 桶 + main_index 同步回复计数与最后回复时间
     */
    private function updateCounters(int $id, int $lrt, int $delta): void
    {
        $indexRow = $this->fetchIndexRow($id);
        if (!$indexRow) return;

        // 桶（真相源）
        $bdb = Schema::bucketFromPath($indexRow['bucket_path']);
        $bdb->prepare(
            'UPDATE topic SET reply_count = MAX(reply_count + :delta, 0), last_reply_time = :lrt, update_time = :now WHERE id = :id'
        )->execute([':delta' => $delta, ':lrt' => $lrt, ':now' => time(), ':id' => $id]);

        // 索引视图
        $mi = Schema::mainIndexDb();
        $mi->prepare(
            'UPDATE topic_index SET reply_count = MAX(reply_count + :delta, 0), last_reply_time = :lrt WHERE id = :id'
        )->execute([':delta' => $delta, ':lrt' => $lrt, ':id' => $id]);
    }

    // ==================== 软删除 / 恢复 / 回收站 ====================

    public function softDelete($id)
    {
        $id = (int)$id;
        $now = time();
        $indexRow = $this->fetchIndexRow($id);
        if (!$indexRow) return null;
        // 状态守卫：已删除的帖子不重复软删，避免每次重复递减全站计数（幂等）
        if ((int)($indexRow['status'] ?? 0) === self::STATUS_DELETED) {
            return true;
        }

        $bdb = Schema::bucketFromPath($indexRow['bucket_path']);
        $bdb->prepare('UPDATE topic SET status = :st, deleted_at = :now, update_time = :now WHERE id = :id')
            ->execute([':st' => self::STATUS_DELETED, ':now' => $now, ':id' => $id]);

        Schema::mainIndexDb()->prepare(
            'UPDATE topic_index SET status = :st, deleted_at = :now WHERE id = :id'
        )->execute([':st' => self::STATUS_DELETED, ':now' => $now, ':id' => $id]);

        // 删除后同步刷新：最新帖快照（首页/版块页不再显示已删帖）、版块统计、列表页缓存
        \app\Models\Category::refreshLatestSnapshot((int)($indexRow['category_id'] ?? 0));
        \app\Models\Category::invalidateCategoryStats();
        \app\Helpers\PageCache::invalidate();
        // 同步递减全站主题计数（发帖时 runtimeBuild 重建，删除若不减会漂移多 1）
        \app\Helpers\Settings::runtimeDecr('total_threads');

        return true;
    }

    public function restore($id)
    {
        $id = (int)$id;
        $indexRow = $this->fetchIndexRow($id);
        if (!$indexRow) return null;
        // 状态守卫：非删除态的帖子不重复恢复，避免每次重复递增全站计数（幂等）
        if ((int)($indexRow['status'] ?? 0) !== self::STATUS_DELETED) {
            return true;
        }

        Schema::bucketFromPath($indexRow['bucket_path'])
            ->prepare('UPDATE topic SET status = :st, deleted_at = NULL, update_time = :now WHERE id = :id')
            ->execute([':st' => self::STATUS_NORMAL, ':now' => time(), ':id' => $id]);

        Schema::mainIndexDb()->prepare(
            'UPDATE topic_index SET status = :st, deleted_at = NULL WHERE id = :id'
        )->execute([':st' => self::STATUS_NORMAL, ':id' => $id]);

        // 恢复后同步刷新：最新帖快照（恢复的帖子重新进入首页/版块页）、版块统计、列表页缓存
        \app\Models\Category::refreshLatestSnapshot((int)($indexRow['category_id'] ?? 0));
        \app\Models\Category::invalidateCategoryStats();
        \app\Helpers\PageCache::invalidate();
        // 同步递增全站主题计数（与 softDelete 的递减对称）
        \app\Helpers\Settings::runtimeIncr('total_threads');

        return true;
    }

    /**
     * 回收站列表（读 main_index）
     */
    public function getDeleted($perPage = 20, $offset = 0)
    {
        $rows = Schema::mainIndexDb()->query(
            "SELECT * FROM topic_index
             WHERE status = " . self::STATUS_DELETED . " OR deleted_at IS NOT NULL
             ORDER BY COALESCE(deleted_at, create_time) DESC
             LIMIT " . (int)$perPage . " OFFSET " . (int)$offset
        )->fetchAll(\PDO::FETCH_ASSOC);

        return $this->decorateList($rows);
    }

    public function countDeleted()
    {
        return (int)Schema::mainIndexDb()->query(
            "SELECT COUNT(*) as count FROM topic_index
             WHERE status = " . self::STATUS_DELETED . " OR deleted_at IS NOT NULL"
        )->fetchColumn();
    }

    // ==================== 硬删除 / 批量 ====================

    /**
     * 永久删除（级联清理：附件文件/外置正文/桶行/索引行）
     */
    public function hardDelete($id)
    {
        $id = (int)$id;
        $indexRow = $this->fetchIndexRow($id);
        if (!$indexRow) return null;

        $bucketRow = $this->fetchBucketRow($indexRow['bucket_path'], $id);

        // 清理附件文件（business.sqlite）
        $atts = $this->db->fetchAll('SELECT filename FROM attachments WHERE thread_id = :id', [':id' => $id]);
        foreach ($atts as $att) {
            // 文件名非法（非白名单字符）时不再静默跳过——记日志，避免「删帖成功但附件残留」无迹可查
            if (\preg_match('/^[a-zA-Z0-9_.\-]+$/', (string)$att['filename']) !== 1) {
                \error_log('Thread::hardDelete 跳过非法附件文件名（防路径穿越，未删除文件）: ' . var_export($att['filename'], true));
                continue;
            }
            $f = \rtrim(UPLOAD_PATH, '/\\') . \DIRECTORY_SEPARATOR . $att['filename'];
            if (\file_exists($f)) { @\unlink($f); }
        }
        $this->db->query('DELETE FROM attachments WHERE thread_id = :id', [':id' => $id]);
        $this->db->query('DELETE FROM thread_tags WHERE thread_id = :id', [':id' => $id]);
        $this->db->query('DELETE FROM thread_votes WHERE thread_id = :id', [':id' => $id]);

        // 清理回复（分片 reply 表 + extern）→ 返回被删回复数
        $deletedReplies = $this->deleteRepliesOf($indexRow['bucket_path'], $id);

        // 清理正文 extern + 桶行 + 索引行
        if ($bucketRow && !empty($bucketRow['extern_path'])) {
            ExternStorage::delete(ShardRouter::dataPath(), $bucketRow['extern_path'], $id, 'topic');
        }
        Schema::bucketFromPath($indexRow['bucket_path'])
            ->prepare('DELETE FROM topic WHERE id = :id')->execute([':id' => $id]);
        Schema::mainIndexDb()->prepare('DELETE FROM topic_index WHERE id = :id')->execute([':id' => $id]);

        // 清理倒排索引（季度分文件，type=1 帖子）
        \app\SplitDB\SearchIndexStore::deleteIndex(1, (int)$id);

        // 硬删除后同步刷新：最新帖快照、版块统计、列表页缓存（与 softDelete 一致）
        \app\Models\Category::refreshLatestSnapshot((int)($indexRow['category_id'] ?? 0));
        \app\Models\Category::invalidateCategoryStats();
        \app\Helpers\PageCache::invalidate();
        // 同步递减全站计数：主题 -1、回复 -deletedReplies（与发帖时 runtimeBuild 对称）
        \app\Helpers\Settings::runtimeDecr('total_threads');
        if ($deletedReplies > 0) {
            \app\Helpers\Settings::runtimeDecr('total_posts', $deletedReplies);
        }

        return true;
    }

    /**
     * 删除主题下的全部回复（reply 桶行 + extern + reply_index 索引）
     *
     * @return int 被删除的回复数（供 total_posts 计数器递减）
     */
    private function deleteRepliesOf(string $bucketPath, int $threadId): int
    {
        $bdb = Schema::bucketFromPath($bucketPath);
        $replies = $bdb->query('SELECT id, extern_path FROM reply WHERE pid = ' . (int)$threadId)
            ->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($replies as $rep) {
            if (!empty($rep['extern_path'])) {
                ExternStorage::delete(ShardRouter::dataPath(), $rep['extern_path'], (int)$rep['id'], 'reply');
            }
        }
        // 删除前统计回复数（reply_index 与桶 reply 一致，取其一）
        // 仅统计未删除回复（status=0）：已软删回复在各自 softDelete 时已递减过 total_posts，
        // 硬删时再按全量递减会造成重复扣减
        $count = (int)Schema::mainIndexDb()->query(
            'SELECT COUNT(*) FROM reply_index WHERE pid = ' . (int)$threadId . ' AND status = 0'
        )->fetchColumn();
        $bdb->prepare('DELETE FROM reply WHERE pid = :pid')->execute([':pid' => $threadId]);
        Schema::mainIndexDb()->prepare('DELETE FROM reply_index WHERE pid = :pid')->execute([':pid' => $threadId]);
        return $count;
    }

    /**
     * 批量获取主题作者 ID 列表（去重）— 读 main_index
     */
    public function getAuthorsByIds(array $ids): array
    {
        $ids = \array_values(\array_filter(\array_map('intval', $ids)));
        if (empty($ids)) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Schema::mainIndexDb()->prepare(
            "SELECT DISTINCT uid FROM topic_index WHERE id IN ({$marks}) AND uid > 0"
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return \array_map('intval', \array_column($rows, 'uid'));
    }

    /**
     * 批量删除主题（含附件/extern/桶/索引清理，事务保护业务侧）
     */
    public function batchDelete(array $ids): int
    {
        $ids = \array_values(\array_filter(\array_map('intval', $ids)));
        if (empty($ids)) return 0;
        $marks = implode(',', array_fill(0, count($ids), '?'));

        // 1. 清理附件（business）
        $atts = $this->db->fetchAll("SELECT filename FROM attachments WHERE thread_id IN ({$marks})", $ids);
        foreach ($atts as $att) {
            if (\preg_match('/^[a-zA-Z0-9_.\-]+$/', (string)$att['filename']) !== 1) continue;
            $f = \rtrim(UPLOAD_PATH, '/\\') . \DIRECTORY_SEPARATOR . $att['filename'];
            if (\file_exists($f)) { @\unlink($f); }
        }
        $this->db->query("DELETE FROM attachments WHERE thread_id IN ({$marks})", $ids);
        $this->db->query("DELETE FROM thread_tags WHERE thread_id IN ({$marks})", $ids);
        $this->db->query("DELETE FROM thread_votes WHERE thread_id IN ({$marks})", $ids);
        // 清理倒排索引（季度分文件，type=1 帖子批量）
        \app\SplitDB\SearchIndexStore::deleteTargets(1, $ids);

        // 2. 分片侧清理（按 bucket_path 分组）
        $stmt = Schema::mainIndexDb()->prepare(
            "SELECT id, category_id, bucket_path FROM topic_index WHERE id IN ({$marks})"
        );
        $stmt->execute($ids);
        $indexRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $affectedCategories = [];
        foreach ($indexRows as $r) {
            $affectedCategories[(int)$r['category_id']] = true;
        }
        $byBucket = [];
        foreach ($indexRows as $r) {
            $byBucket[$r['bucket_path']][] = (int)$r['id'];
        }
        $deletedReplies = 0;
        foreach ($byBucket as $bucketPath => $bucketIds) {
            $marks2 = implode(',', array_fill(0, count($bucketIds), '?'));
            $bdb = Schema::bucketFromPath($bucketPath);
            // extern 清理
            $stmt = $bdb->prepare("SELECT id, extern_path FROM topic WHERE id IN ({$marks2})");
            $stmt->execute($bucketIds);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                if (!empty($r['extern_path'])) {
                    ExternStorage::delete(ShardRouter::dataPath(), $r['extern_path'], (int)$r['id'], 'topic');
                }
            }
            // reply 清理
            foreach ($bucketIds as $tid) {
                $deletedReplies += $this->deleteRepliesOf($bucketPath, $tid);
            }
            $bdb->prepare("DELETE FROM topic WHERE id IN ({$marks2})")->execute($bucketIds);
        }
        Schema::mainIndexDb()->prepare("DELETE FROM topic_index WHERE id IN ({$marks})")->execute($ids);

        // 批量删除后同步刷新：涉及版块的最新帖快照、版块统计、列表页缓存
        foreach (array_keys($affectedCategories) as $cid) {
            \app\Models\Category::refreshLatestSnapshot((int)$cid);
        }
        \app\Models\Category::invalidateCategoryStats();
        \app\Helpers\PageCache::invalidate();
        // 同步递减全站计数：主题 -N、回复 -deletedReplies
        \app\Helpers\Settings::runtimeDecr('total_threads', \count($ids));
        if ($deletedReplies > 0) {
            \app\Helpers\Settings::runtimeDecr('total_posts', $deletedReplies);
        }

        return \count($ids);
    }

    // ==================== 内部工具 ====================

    /**
     * 从 main_index 读取索引行（读取路由的唯一依据，白皮书 5.3）
     */
    private function fetchIndexRow(int $id): ?array
    {
        $stmt = Schema::mainIndexDb()->prepare('SELECT * FROM topic_index WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * 按 bucket_path 打开桶并读取 topic 行
     */
    private function fetchBucketRow(string $bucketPath, int $id): ?array
    {
        $bdb = Schema::bucketFromPath($bucketPath);
        $stmt = $bdb->prepare('SELECT * FROM topic WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * 桶行 → 视图兼容字段（bucket_path / extern 读取 + 用户/分类装饰）
     */
    private function decorate(array $bucketRow, string $content): array
    {
        $row = $bucketRow;
        $row['user_id']     = (int)($row['uid'] ?? 0);
        $row['content']     = $content;
        $row['created_at']  = $this->ts($row['create_time'] ?? null);
        $row['update_time'] = $this->ts($row['update_time'] ?? null);
        $row['last_reply_at'] = $this->ts($row['last_reply_time'] ?? null);
        unset($row['create_time'], $row['last_reply_time']);

        // 用户/分类信息（business.sqlite）；合并时剔除用户的 id，防止覆盖帖子 id
        $user = $this->db->fetchOne(
            'SELECT id, username, avatar, signature, level, post_count, points FROM users WHERE id = :id',
            [':id' => (int)$row['uid']]
        );
        if ($user) {
            $row = array_merge($row, array_diff_key($user, ['id' => 1]));
        }
        $cat = $this->db->fetchOne('SELECT id, name FROM categories WHERE id = :id', [':id' => (int)($row['category_id'] ?? 0)]);
        $row['category_name'] = $cat['name'] ?? '';
        $row['category_id']   = (int)($row['category_id'] ?? 0);

        return $row;
    }

    /**
     * 索引行列表 → 视图兼容列表（批量补用户/分类，避免 N+1）
     */
    private function decorateList(array $rows): array
    {
        if (empty($rows)) return [];

        // 批量取用户/分类（business.sqlite）
        $uids = array_values(array_unique(array_map(fn($r) => (int)$r['uid'], $rows)));
        $cids = array_values(array_unique(array_map(fn($r) => (int)$r['category_id'], $rows)));
        $users = $this->batchFetch('users', $uids);
        $cats  = $this->batchFetch('categories', $cids, 'id, name');

        $result = [];
        foreach ($rows as $r) {
            $uid = (int)$r['uid'];
            $cid = (int)$r['category_id'];
            $result[] = [
                'id'            => (int)$r['id'],
                'user_id'       => $uid,
                'category_id'   => $cid,
                'title'         => $r['title'],
                'view_count'    => (int)$r['view_count'],
                'reply_count'   => (int)$r['reply_count'],
                'is_pinned'     => (int)$r['is_pinned'],
                'is_highlighted'=> (int)$r['is_highlighted'],
                'color'         => $r['color'] ?? '',
                'reply_to_view' => (int)($r['reply_to_view'] ?? 0),
                'created_at'    => $this->ts($r['create_time'] ?? null),
                'last_reply_at' => $this->ts($r['last_reply_time'] ?? null),
                'deleted_at'    => $r['deleted_at'] !== null ? $this->ts($r['deleted_at']) : null,
                'bucket_path'   => $r['bucket_path'],
                'excerpt'       => (string)($r['excerpt'] ?? ''),
                'excerpt_images'=> (string)($r['excerpt_images'] ?? ''),
                'username'      => $users[$uid]['username'] ?? '',
                'avatar'        => $users[$uid]['avatar'] ?? '',
                'level'         => $users[$uid]['level'] ?? 0,
                'category_name' => $cats[$cid]['name'] ?? '',
            ];
        }
        return $result;
    }

    /**
     * 列表行批量补摘要（excerpt）：按 bucket_path 分组，每桶一次批量查 extern_path，
     * 再逐个读 extern 正文生成纯文本摘要（避免逐条 N+1 开桶/读文件）。
     * 空正文 / 读取失败 → excerpt 置空（视图层仅非空时渲染摘要行）。
     *
     * @param array $threads decorateList 后的列表行（须含 id + bucket_path）
     * @param int   $len     摘要长度（限幅 50~200，默认 80）
     * @return array
     */
    public function attachExcerpts(array $threads, int $len = 80): array
    {
        if (empty($threads)) {
            return $threads;
        }
        $len = max(50, min(200, (int)$len));
        $dataPath = ShardRouter::dataPath();

        // 0. 补齐缺失的 bucket_path：快照聚合路径的帖子行不含该字段 → 按 id 从 main_index 批量反查
        $missing = [];
        foreach ($threads as $t) {
            if ((string)($t['bucket_path'] ?? '') === '') {
                $missing[] = (int)$t['id'];
            }
        }
        if (!empty($missing)) {
            $marks = implode(',', array_fill(0, count($missing), '?'));
            $stmt = Schema::mainIndexDb()->prepare("SELECT id, bucket_path FROM topic_index WHERE id IN ({$marks})");
            $stmt->execute($missing);
            $bpMap = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $bpMap[(int)$r['id']] = (string)($r['bucket_path'] ?? '');
            }
            foreach ($threads as &$t) {
                if ((string)($t['bucket_path'] ?? '') === '' && isset($bpMap[(int)$t['id']])) {
                    $t['bucket_path'] = $bpMap[(int)$t['id']];
                }
            }
            unset($t);
        }

        // 1. 已由索引行自带的落库摘要（decorateList 透传 topic_index.excerpt/excerpt_images）→ 零额外查询；
        //    仅对「行内无摘要」的帖子才需按 bucket 批量查桶行摘要（同时取 extern_path 供文件兜底）。
        $preExcerpt = [];
        $needBucket = [];
        foreach ($threads as $t) {
            $pe  = (string)($t['excerpt'] ?? '');
            $pei = (string)($t['excerpt_images'] ?? '');
            if ($pe !== '' || $pei !== '') {
                $preExcerpt[(int)$t['id']] = ['plain' => $pe, 'images' => self::parseExcerptImages($pei)];
            } else {
                $needBucket[(int)$t['id']] = true;
            }
        }
        // 1.5 按 bucket_path 分组（仅待查桶行摘要的帖子）→ 每桶一次批量查 extern_path / excerpt
        $byBucket = [];
        foreach ($threads as $t) {
            $tid = (int)$t['id'];
            if (!isset($needBucket[$tid])) {
                continue;
            }
            $bp = (string)($t['bucket_path'] ?? '');
            if ($bp === '') {
                continue;
            }
            $byBucket[$bp][] = $tid;
        }
        $externMap = []; // id => extern_path（文件回退用）
        $bucketExcerpt = []; // id => ['plain' => string, 'images' => array]（桶行落库摘要，次优）
        foreach ($byBucket as $bp => $ids) {
            try {
                $bdb = Schema::bucketFromPath($bp, $dataPath);
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $bdb->prepare("SELECT id, extern_path, excerpt, excerpt_images FROM topic WHERE id IN ({$marks})");
                $stmt->execute($ids);
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                    $rid = (int)$r['id'];
                    $externMap[$rid] = (string)($r['extern_path'] ?? '');
                    $bucketExcerpt[$rid] = [
                        'plain'  => (string)($r['excerpt'] ?? ''),
                        'images' => self::parseExcerptImages((string)($r['excerpt_images'] ?? '')),
                    ];
                }
            } catch (\Throwable $e) {
                // 桶打开失败（归档/迁移等）：跳过该桶，摘要留空，不影响列表
                // 空 catch 补日志，避免「摘要莫名为空但无迹可查」
                \error_log('Thread::attachExcerpts 桶读取失败（摘要留空，跳过该桶）: ' . $bp . ' | ' . $e->getMessage());
            }
        }

        // 2. 批量读正文生成摘要
        //    方案 B（memo 缓存）：static $memo 以 thread_id 为键，同一请求内再次取同一帖子摘要时
        //    直接复用已算好的纯文本 + 图片，不再重复读正文文件（仅按本次 len 重新切片）。
        //    方案 C（前缀读）：读取正文仅取前 8KB（EXCERPT_PREFIX_BYTES），足够生成摘要与首图即可；
        //    HTML 正文首字符为 '<' 时跳过 Content::decode 的全量 base64 锚定正则。
        static $memo = []; // thread_id => ['plain' => string, 'images' => array]
        $memoMax = 500; // 缓存上限：常驻 worker 下无上限持续内存膨胀；超限按 FIFO 淘汰最旧
        $prefixBytes = 8192; // 8KB

        foreach ($threads as &$t) {
            $tid = (int)$t['id'];

            // 方案 A：优先用 DB 落库摘要 —— ①索引行自带（零查询）②桶行摘要；两者皆空才走文件兜底。
            // 判定依据为「纯文本 或 图片」任一项非空：纯图帖无文字，仅 excerpt_images 有值也必须走快路径。
            if (isset($preExcerpt[$tid])) {
                $t['excerpt'] = $preExcerpt[$tid]['plain'] === '' ? '' : mb_substr($preExcerpt[$tid]['plain'], 0, $len);
                $t['excerpt_images'] = $preExcerpt[$tid]['images'];
                continue;
            }
            $be = $bucketExcerpt[$tid] ?? ['plain' => '', 'images' => []];
            if ($be['plain'] !== '' || !empty($be['images'])) {
                $t['excerpt'] = $be['plain'] === '' ? '' : mb_substr($be['plain'], 0, $len);
                $t['excerpt_images'] = $be['images'];
                continue;
            }

            $externPath = $externMap[$tid] ?? '';
            if ($externPath === '') {
                $t['excerpt'] = '';
                $t['excerpt_images'] = [];
                continue;
            }

            if (isset($memo[$tid])) {
                // 方案 B：命中 memo，直接复用（只按当前 len 重新 mb_substr 切片）
                $t['excerpt'] = $memo[$tid]['plain'] === '' ? '' : mb_substr($memo[$tid]['plain'], 0, $len);
                $t['excerpt_images'] = $memo[$tid]['images'];
                continue;
            }

            // 方案 C：仅读前 8KB 供摘要/首图使用（ExternStorage 内不缓存该局部读取，避免污染全文缓存）
            $content = ExternStorage::read($dataPath, $externPath, $tid, 'topic', $prefixBytes);
            // 编辑器提交内容可能为 base64 编码态（详情页/搜索均先 Content::decode 再渲染），
            // 摘要链路必须同样解码后再 strip_tags / 提取首图，否则摘要显示 base64 乱码。
            // Content::decode 幂等：非 base64 原文原样返回，对已是 HTML 的正文无副作用。
            // 方案 C：HTML 正文首字符为 '<' → base64 锚定正则必然整体空跑，直接跳过解码。
            if ($content !== '' && $content[0] !== '<') {
                $content = \app\Helpers\Content::decode($content);
            }
            // 与 extractExcerptContent 同口径防双重编码：strip_tags 前先解码实体
            $plain = trim(strip_tags(html_entity_decode((string)$content, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $plain = preg_replace('/\s+/u', ' ', $plain); // 折叠空白

            // 正文图片（微博样式多图）：提取正文中前 N 个 <img src="...">
            // 正文存的是完整可访问 URL（编辑器上传即 UPLOAD_URL.filename），无需拼前缀；
            // 安全白名单：仅接受 http(s):// 、协议相对 // 或站内 / 开头，排除 javascript: 等伪协议；
            // 最多 9 张（微博 3×3 网格上限），去重保序
            $images = [];
            if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', (string)$content, $ms)) {
                $seen = [];
                foreach ($ms[1] as $src) {
                    $src = trim($src);
                    if ($src === '' || !preg_match('#^(?:https?:)?//|^/#i', $src)) {
                        continue;
                    }
                    // 表情图不进摘要缩略图（A 修复 2026-09-05）：src 含 /assets/img/emoji/ 即 QQ 表情，
                    // 用子串匹配兼容二级目录部署时的 BASE_PATH 前缀
                    if (strpos($src, '/assets/img/emoji/') !== false) {
                        continue;
                    }
                    $seen[$src] = true;
                    $images[] = $src;
                    if (count($images) >= 9) {
                        break;
                    }
                }
            }

            // 方案 B：整段纯文本 + 图片结果入 memo，供同一请求内复用
            if (count($memo) >= $memoMax) {
                array_shift($memo); // FIFO 淘汰最旧条目
            }
            $memo[$tid] = ['plain' => $plain, 'images' => $images];
            $t['excerpt'] = $plain === '' ? '' : mb_substr($plain, 0, $len);
            $t['excerpt_images'] = $images;
        }
        unset($t);

        return $threads;
    }

    /**
     * 从正文提取摘要（写帖/编辑时落库用）：纯文本 ≤200 字 + 安全图片 URL 前 9 张。
     * 与 attachExcerpts 的文件回退分支保持同口径（解码 → strip_tags → 折叠空白 → 切 200 → 去重保序取图）。
     *
     * @param string $content 原始正文（HTML 或 base64 态）
     * @return array ['plain' => string, 'images' => array]
     */
    public static function extractExcerptContent(string $content): array
    {
        $images = [];
        if ($content === '') {
            return ['plain' => '', 'images' => $images];
        }
        // Content::decode 幂等：非 base64 原文原样返回，对已是 HTML 的正文无副作用
        $content = \app\Helpers\Content::decode($content);
        // 摘要链路防双重编码：strip_tags 前先全面解码实体（&amp;/&nbsp; 等还原为字符），
        // 否则字面实体随摘要落库，经视图 $this->e() 再转义后显示为 &amp;amp; / 字面 &nbsp;
        $plain = trim(strip_tags(html_entity_decode((string)$content, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $plain = preg_replace('/\s+/u', ' ', $plain); // 折叠空白
        $plain = mb_substr($plain, 0, 200);

        // 正文图片：提取正文中前 N 个 <img src="...">
        // 安全白名单：仅接受 http(s):// 、协议相对 // 或站内 / 开头，排除 javascript: 等伪协议
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', (string)$content, $ms)) {
            $seen = [];
            foreach ($ms[1] as $src) {
                $src = trim($src);
                if ($src === '' || !preg_match('#^(?:https?:)?//|^/#i', $src)) {
                    continue;
                }
                // 表情图不进摘要缩略图（A 修复 2026-09-05）：src 含 /assets/img/emoji/ 即 QQ 表情，
                // 用子串匹配兼容二级目录部署时的 BASE_PATH 前缀
                if (strpos($src, '/assets/img/emoji/') !== false) {
                    continue;
                }
                $seen[$src] = true;
                $images[] = $src;
                if (count($images) >= 9) {
                    break;
                }
            }
        }
        return ['plain' => $plain, 'images' => $images];
    }

    /**
     * 反序列化落库的缩略图列表：逗号拼接的站内/协议相对安全 URL → 数组。
     *
     * @param string $s 逗号分隔的图片 URL
     * @return array
     */
    private static function parseExcerptImages(string $s): array
    {
        $s = trim($s);
        if ($s === '') {
            return [];
        }
        // B 修复 2026-09-05：读取端剔除存量脏数据中的 QQ 表情 URL（含 assets/img/emoji/ 即表情，
        // 子串匹配兼容 BASE_PATH 前缀），历史帖子的 excerpt_images 无需回写即可在前台消失
        return array_values(array_filter(array_map('trim', explode(',', $s)), fn($v) => $v !== '' && strpos($v, '/assets/img/emoji/') === false));
    }

    /**
     * 批量回填正文 content（供搜索结果等"详情行+正文"场景，替代逐条 find() 的 SQL N+1）
     *
     * 输入：getByIds / decorateList 产出的行（须含 id + bucket_path）。
     * 读取：按 bucket_path 分组 → 每桶一次批量查 extern_path → 逐条 ExternStorage::read 读文件
     *       （文件读取无法合并，SQL 查询次数从 N 次降为「每桶 1 次」）。
     * 语义与 Thread::getById 的 content 一致：原文（HTML）不经解码，失败/缺失留空。
     *
     * @param array $rows 列表行（含 id、bucket_path）
     * @return array 回填 content 后的同一批行（引用改原数组并返回）
     */
    public function fillExternContent(array $rows): array
    {
        $dataPath = ShardRouter::dataPath();
        $byBucket = [];
        foreach ($rows as $t) {
            $bp = (string)($t['bucket_path'] ?? '');
            if ($bp === '') continue;
            $byBucket[$bp][] = (int)$t['id'];
        }
        $externMap = []; // id => extern_path
        foreach ($byBucket as $bp => $ids) {
            try {
                $bdb = Schema::bucketFromPath($bp, $dataPath);
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $bdb->prepare("SELECT id, extern_path FROM topic WHERE id IN ({$marks})");
                $stmt->execute($ids);
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                    $externMap[(int)$r['id']] = (string)($r['extern_path'] ?? '');
                }
            } catch (\Throwable $e) {
                // 桶打开失败（归档/迁移等）：跳过该桶，正文留空，不影响列表
            }
        }
        foreach ($rows as &$t) {
            $tid = (int)$t['id'];
            $externPath = $externMap[$tid] ?? '';
            $t['content'] = $externPath === '' ? '' : ExternStorage::read($dataPath, $externPath, $tid, 'topic');
        }
        unset($t);
        return $rows;
    }

    /**
     * 批量取 business 表记录（id IN (...)，返回 id => row 映射）
     */
    private function batchFetch(string $table, array $ids, string $cols = '*'): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->fetchAll("SELECT {$cols} FROM {$table} WHERE id IN ({$marks})", $ids);
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['id']] = $r;
        }
        return $map;
    }

    /**
     * 秒级时间戳 → 'Y-m-d H:i:s'（null 返回 null）
     */
    private function ts($ts): ?string
    {
        if ($ts === null || $ts === '') return null;
        return date('Y-m-d H:i:s', (int)$ts);
    }
}
