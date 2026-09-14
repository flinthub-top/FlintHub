<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 博客评论模型 — 博客评论查询、管理
 * @file app/Models/BlogComment.php
 * @package app\Models
 */

namespace app\Models;

use app\Core\Model;

class BlogComment extends Model
{
    protected $table = 'blog_comments';

    public function getByBlogPaginated($blogId, $perPage, $offset)
    {
        return $this->query(
            "SELECT c.*, u.username, u.avatar
             FROM {$this->table} c
             JOIN users u ON c.user_id = u.id
             WHERE c.blog_id = :bid
             ORDER BY c.created_at ASC
             LIMIT " . (int)$perPage . " OFFSET " . (int)$offset,
            [':bid' => (int)$blogId]
        );
    }

    public function countByBlog($blogId)
    {
        $r = $this->queryOne("SELECT COUNT(*) as count FROM {$this->table} WHERE blog_id = :bid", [':bid' => (int)$blogId]);
        return (int)($r['count'] ?? 0);
    }
}
