<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 标签模型 — 标签管理、关联帖子数量统计
 * @file app/Models/Tag.php
 * @package app\Models
 */

namespace app\Models;

use app\Core\Model;

class Tag extends Model
{
    protected $table = 'tags';

    public function getAllWithCount()
    {
        return $this->query(
            "SELECT t.*, COUNT(tt.thread_id) as thread_count
             FROM tags t
             LEFT JOIN thread_tags tt ON t.id = tt.tag_id
             GROUP BY t.id
             ORDER BY thread_count DESC"
        );
    }
}
