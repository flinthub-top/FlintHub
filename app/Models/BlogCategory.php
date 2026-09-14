<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 博客分类模型 — 博客分类排序、列表查询
 * @file app/Models/BlogCategory.php
 * @package app\Models
 */

namespace app\Models;

use app\Core\Model;

class BlogCategory extends Model
{
    protected $table = 'blog_categories';
    /** 可更新列白名单：后台博客分类编辑走基类 update() */
    protected $fillable = ['name', 'description', 'sort_order'];

    public function allOrdered()
    {
        return $this->all('sort_order ASC, id ASC');
    }

    /**
     * 删除分类并将该分类下的博客置为未分类
     */
    public function deleteWithReset(int $id): void
    {
        $this->db->query('UPDATE blogs SET category_id = NULL WHERE category_id = :cid', [':cid' => $id]);
        $this->delete($id);
    }

    /**
     * 获取每个分类的博客数映射 [category_id => count]
     */
    public function getBlogCountMap(): array
    {
        $rows = $this->db->fetchAll('SELECT category_id, COUNT(*) as cnt FROM blogs GROUP BY category_id');
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['category_id']] = (int)$row['cnt'];
        }
        return $map;
    }
}
