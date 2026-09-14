<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 博客模型 — 博客文章 CRUD、关联用户/分类查询
 * @file app/Models/Blog.php
 * @package app\Models
 */

namespace app\Models;

use app\Core\Model;

class Blog extends Model
{
    protected $table = 'blogs';
    /** 可更新列白名单：前台博客编辑走基类 update() */
    protected $fillable = ['category_id', 'title', 'content', 'cover_image', 'updated_at'];

    public function getLatest($limit = 10)
    {
        $rows = $this->query(
            "SELECT b.*, u.username, u.avatar, c.name as category_name
             FROM {$this->table} b
             JOIN users u ON b.user_id = u.id
             LEFT JOIN blog_categories c ON b.category_id = c.id
             ORDER BY b.created_at DESC
             LIMIT " . (int)$limit
        );
        return $this->decodeRows($rows);
    }

    /**
     * 博客归档（按年月分组计数）— 30s 短 TTL 文件缓存
     * 列表页与详情页共用同一份归档数据，替代每请求 GROUP BY 全表
     */
    public function getArchivesCached(): array
    {
        $cacheFile = (rtrim(\app\SplitDB\ShardRouter::dataPath(), '/\\')) . '/runtime/blog_archives.cache.json';
        if (is_file($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            $dec = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($dec) && isset($dec['ts'], $dec['v']) && time() - (int)$dec['ts'] < 30) {
                return $dec['v'];
            }
        }
        $rows = $this->query(
            "SELECT strftime('%Y-%m', created_at) as month, COUNT(*) as cnt
             FROM blogs
             GROUP BY strftime('%Y-%m', created_at)
             ORDER BY month DESC"
        );
        $dir = dirname($cacheFile);
        if (is_dir($dir) || @mkdir($dir, 0755, true)) {
            @file_put_contents($cacheFile, json_encode(['ts' => time(), 'v' => $rows], JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        return $rows ?: [];
    }

    public function getWithUser($id)
    {
        $row = $this->queryOne(
            "SELECT b.*, u.username, u.avatar, u.signature, u.level, c.name as category_name
             FROM {$this->table} b
             JOIN users u ON b.user_id = u.id
             LEFT JOIN blog_categories c ON b.category_id = c.id
             WHERE b.id = :id",
            [':id' => (int)$id]
        );
        return $this->decodeRow($row);
    }

    public function getLatestPaginated($perPage, $offset, $categoryId = null)
    {
        $where = '';
        $params = [];
        if ($categoryId) {
            $where = 'WHERE b.category_id = :cid';
            $params[':cid'] = (int)$categoryId;
        }
        $sql = "SELECT b.*, u.username, u.avatar, c.name as category_name
                FROM {$this->table} b
                JOIN users u ON b.user_id = u.id
                LEFT JOIN blog_categories c ON b.category_id = c.id
                {$where}
                ORDER BY b.created_at DESC
                LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
        $rows = $this->query($sql, $params);
        return $this->decodeRows($rows);
    }

    public function decodeRowsPublic($rows)
    {
        return $this->decodeRows($rows);
    }

    private function decodeRows($rows)
    {
        if (empty($rows)) return $rows;
        foreach ($rows as &$row) {
            if (isset($row['content'])) {
                $row['content'] = \app\Helpers\Content::decode($row['content']);
            }
        }
        return $rows;
    }

    private function decodeRow($row)
    {
        if ($row && isset($row['content'])) {
            $row['content'] = \app\Helpers\Content::decode($row['content']);
        }
        return $row;
    }

    /**
     * 批量删除博客（含评论清理，事务保护）
     */
    public function batchDelete(array $ids): int
    {
        $ids = \array_values(\array_filter(\array_map('intval', $ids)));
        if (empty($ids)) return 0;
        $marks = \implode(',', \array_fill(0, \count($ids), '?'));
        try {
            $this->db->begin();
            $this->execute("DELETE FROM {$this->table} WHERE id IN ({$marks})", $ids);
            $this->execute("DELETE FROM blog_comments WHERE blog_id IN ({$marks})", $ids);
            $this->db->commit();
            return \count($ids);
        } catch (\Exception $e) {
            try { $this->db->rollback(); } catch (\Exception $ignored) {}
            \error_log('Blog::batchDelete error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 删除单条博客评论：mod_system 等插件侧评论删除统一走 Model 封装，
     * 不再由插件直写 blog_comments 表。
     */
    public function deleteComment(int $id): bool
    {
        $id = (int)$id;
        if ($id <= 0) return false;
        try {
            $this->execute('DELETE FROM blog_comments WHERE id = :id', [':id' => $id]);
            return true;
        } catch (\Exception $e) {
            \error_log('Blog::deleteComment error: ' . $e->getMessage());
            return false;
        }
    }
}
