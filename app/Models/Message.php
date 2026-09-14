<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 私信模型 — 收件箱/发件箱、私信收发、已读状态
 * @file app/Models/Message.php
 * @package app\Models
 */

namespace app\Models;

use app\Core\Model;

class Message extends Model
{
    protected $table = 'messages';

    public function getInbox($userId, int $limit = 10, int $offset = 0)
    {
        return $this->query(
            "SELECT m.*, u.username as sender_name
             FROM {$this->table} m
             JOIN users u ON m.sender_id = u.id
             WHERE m.receiver_id = :uid
             ORDER BY m.created_at DESC
             LIMIT " . (int)$limit . " OFFSET " . (int)$offset,
            [':uid' => (int)$userId]
        );
    }

    public function countInbox($userId)
    {
        $result = $this->queryOne(
            "SELECT COUNT(*) as count FROM {$this->table} WHERE receiver_id = :uid",
            [':uid' => (int)$userId]
        );
        return (int)($result['count'] ?? 0);
    }

    public function getSent($userId, int $limit = 10, int $offset = 0)
    {
        return $this->query(
            "SELECT m.*, u.username as receiver_name
             FROM {$this->table} m
             JOIN users u ON m.receiver_id = u.id
             WHERE m.sender_id = :uid
             ORDER BY m.created_at DESC
             LIMIT " . (int)$limit . " OFFSET " . (int)$offset,
            [':uid' => (int)$userId]
        );
    }

    public function countSent($userId)
    {
        $result = $this->queryOne(
            "SELECT COUNT(*) as count FROM {$this->table} WHERE sender_id = :uid",
            [':uid' => (int)$userId]
        );
        return (int)($result['count'] ?? 0);
    }

    public function markAsRead($id, $userId)
    {
        return $this->execute(
            "UPDATE {$this->table} SET is_read = 1 WHERE id = :id AND receiver_id = :uid",
            [':id' => (int)$id, ':uid' => (int)$userId]
        );
    }

    /**
     * 删除消息（校验发送者或接收者身份）
     */
    public function deleteOwned(int $id, int $userId): void
    {
        $this->execute(
            "DELETE FROM {$this->table} WHERE id = :id AND (sender_id = :uid OR receiver_id = :uid)",
            [':id' => $id, ':uid' => $userId]
        );
    }
}
