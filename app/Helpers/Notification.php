<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 通知中心核心类（通知插件内置化）— 增删查、标记已读、各类业务便捷通知
 * 与原插件差异：
 *   1. 数据表 notifications 从插件独立库迁入核心 business.sqlite（\app\Core\Database）
 *   2. 原插件 10 个 hook 的全部业务通知逻辑内聚到本类方法
 *   3. deleteByIds 不再跨库清理 review_notifications（该表已废弃）
 * @file app/Helpers/Notification.php
 * @package app\Helpers
 */

namespace app\Helpers;

class Notification
{
    // ================= 通知类型常量 =================
    // ① 论坛核心
    const TYPE_REPLY       = 'reply';
    const TYPE_VOTE        = 'vote';
    const TYPE_MENTION     = 'mention';          // 预留：无 @ 解析，暂不触发
    const TYPE_REPLY_QUOTE = 'reply_quote';       // 预留：无引用检测，暂不触发
    const TYPE_THREAD_NEW_POST = 'thread_new_post'; // 预留：订阅无联动，暂不触发
    const TYPE_FOLLOW      = 'follow';            // 预留：无关注功能，暂不触发
    const TYPE_WELCOME     = 'welcome';
    // ② 积分/商城
    const TYPE_POINTS      = 'points';
    const TYPE_LEVEL_UP    = 'level_up';          // 预留：本次内置化暂不触发
    const TYPE_ORDER_PAID  = 'order_paid';        // 预留
    const TYPE_ORDER_SHIPPED = 'order_shipped';   // 预留
    const TYPE_POINTS_TRANSFER = 'points_transfer'; // 预留
    const TYPE_RED_PACKET  = 'red_packet_received'; // 预留
    // ③ 内容互动
    const TYPE_REVIEW_PENDING  = 'review_pending';
    const TYPE_REVIEW_APPROVED = 'review_approved';
    const TYPE_REVIEW_REJECTED = 'review_rejected';
    const TYPE_BLACKLIST   = 'blacklist';
    const TYPE_FRIEND_REQUEST = 'friend_request'; // 预留
    // ④ 系统/站务
    const TYPE_SYSTEM      = 'system';            // 预留
    const TYPE_ANNOUNCEMENT = 'announcement';     // 预留
    const TYPE_MOD_REPORT  = 'mod_report';
    // mod_system 原始类型变体（保留原义，前台视图按此映射图标/分类）
    const TYPE_MOD_REPORTED     = 'mod_reported';
    const TYPE_MOD_REPORT_NEW   = 'mod_report_new';
    const TYPE_MOD_AUTO_AUDIT   = 'mod_auto_audit';
    const TYPE_MOD_REPORT_RESULT = 'mod_report_result';

    /**
     * 核心库连接（business.sqlite）
     */
    private static function db(): \app\Core\Database
    {
        return \app\Core\Database::getInstance();
    }

    /**
     * 写入一条通知（核心方法；业务便捷方法均委托此）；异常静默，不影响主流程
     * @param int    $userId     接收人
     * @param string $type       类型（reply/vote/points/welcome/review_pending/review_approved/review_rejected/mod_ 前缀 等）
     * @param string $title      通知文案
     * @param string $link       跳转链接（可空）
     * @param string $actorName  触发者用户名（可空）
     * @param string $summary    内容摘要（可空）
     * @param int    $relatedId  关联 ID（可空）
     * @param string $relatedType 关联类型（可空）
     */
    public static function add(int $userId, string $type, string $title, string $link = '', string $actorName = '', string $summary = '', int $relatedId = 0, string $relatedType = ''): void
    {
        if ($userId <= 0) return;
        try {
            self::db()->query(
                "INSERT INTO notifications (user_id, type, title, link, actor_name, summary, related_id, related_type, is_read, created_at)
                 VALUES (:uid, :type, :title, :link, :actor, :summary, :rid, :rtype, 0, :now)",
                [
                    ':uid'     => $userId,
                    ':type'    => mb_substr($type, 0, 20),
                    ':title'   => mb_substr($title, 0, 500),
                    ':link'    => mb_substr($link, 0, 500),
                    ':actor'   => mb_substr($actorName, 0, 100),
                    ':summary' => mb_substr($summary, 0, 300),
                    ':rid'     => $relatedId,
                    ':rtype'   => mb_substr($relatedType, 0, 20),
                    ':now'     => date('Y-m-d H:i:s'),
                ]
            );
        } catch (\Throwable $e) {
            \error_log('Notification add error: ' . $e->getMessage());
        }
    }

    /**
     * 获取用户通知列表（分页）
     */
    public static function getList(int $userId, int $page = 1, int $perPage = 20): array
    {
        try {
            $offset = max(0, ($page - 1) * $perPage);
            $stmt = self::db()->getConnection()->prepare(
                'SELECT * FROM notifications WHERE user_id = :uid ORDER BY is_read ASC, created_at DESC, id DESC LIMIT :cnt OFFSET :off'
            );
            $stmt->bindValue(':uid', $userId, \PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
            $stmt->bindValue(':cnt', $perPage, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 获取用户通知总数
     */
    public static function getTotal(int $userId): int
    {
        try {
            $row = self::db()->fetchOne('SELECT COUNT(*) as cnt FROM notifications WHERE user_id = :uid', [':uid' => $userId]);
            return (int)($row['cnt'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 获取未读数量
     */
    public static function getUnreadCount(int $userId): int
    {
        try {
            $row = self::db()->fetchOne('SELECT COUNT(*) as cnt FROM notifications WHERE user_id = :uid AND is_read = 0', [':uid' => $userId]);
            return (int)($row['cnt'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 全部标记已读
     */
    public static function markAllRead(int $userId): void
    {
        try {
            self::db()->query('UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND is_read = 0', [':uid' => $userId]);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 批量删除（仅限本人，防越权）
     * 用户掌控：不做自动清理，删除权绝对。
     */
    public static function deleteByIds(int $userId, array $ids): void
    {
        if (empty($ids) || $userId <= 0) return;
        try {
            $ids = array_values(array_filter(array_map('intval', (array)$ids), fn($v) => $v > 0));
            if (empty($ids)) return;
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $params = $ids;
            $params[] = $userId;
            self::db()->getConnection()->prepare(
                "DELETE FROM notifications WHERE id IN ({$marks}) AND user_id = ?"
            )->execute($params);
        } catch (\Throwable $e) {
            \error_log('Notification deleteByIds error: ' . $e->getMessage());
        }
    }

    /**
     * 回复通知：有人回复我的帖子时通知楼主（自己回自己不通知）
     * @param int $threadId  主题 ID
     * @param int $replierId 回复人 ID
     * @param int $postId    回复 ID
     */
    public static function notifyReply(int $threadId, int $replierId, int $postId): void
    {
        $threadId = (int)$threadId;
        $replierId = (int)$replierId;
        $postId = (int)$postId;
        if (!$threadId || !$replierId || !$postId) return;
        try {
            $thread = (new \app\Models\Thread())->find($threadId);
            if (!$thread) return;
            $ownerId = (int)$thread['user_id'];
            if ($ownerId === $replierId) return; // 自己回自己不通知

            $replierName = \app\Models\User::batchGetNames([$replierId])[$replierId] ?? '';
            $title = mb_substr((string)($thread['title'] ?? ''), 0, 40);
            $link = '/thread/' . $threadId . '?pid=' . $postId . '#post-' . $postId;
            self::add(
                $ownerId,
                self::TYPE_REPLY,
                I18n::get('plugin.notifications.reply', ['user' => ($replierName !== '' ? $replierName : I18n::get('plugin.notifications.someone')), 'title' => $title]),
                $link,
                $replierName,
                $title,
                $threadId,
                'thread'
            );
        } catch (\Throwable $e) {
            \error_log('Notification notifyReply error: ' . $e->getMessage());
        }
    }

    /**
     * 点赞通知：我的帖子/回复被点赞时通知作者（仅赞，点踩/取消赞不打扰）
     * @param string $type    thread|post
     * @param int    $id      目标 ID
     * @param int    $vote    1=赞
     * @param int    $voterId 点赞人 ID
     * @param int    $ownerId 作者 ID
     */
    public static function notifyVote(string $type, int $id, int $vote, int $voterId, int $ownerId): void
    {
        $id = (int)$id;
        $vote = (int)$vote;
        $voterId = (int)$voterId;
        $ownerId = (int)$ownerId;
        if ($vote !== 1 || !$id || !$voterId || !$ownerId) return; // 仅"赞"通知
        if ($voterId === $ownerId) return; // 自己赞自己不通知
        try {
            $voterName = \app\Models\User::batchGetNames([$voterId])[$voterId] ?? '';
            if ($type === 'thread') {
                $target = (new \app\Models\Thread())->find($id);
                $label = I18n::get('plugin.notifications.vote_thread');
                $title = $target['title'] ?? '';
                $link = '/thread/' . $id;
            } else {
                $target = (new \app\Models\Post())->find($id);
                $label = I18n::get('plugin.notifications.vote_reply');
                $title = mb_substr(strip_tags($target['content'] ?? ''), 0, 40);
                $link = '/thread/' . (int)($target['thread_id'] ?? 0) . '?pid=' . $id . '#post-' . $id;
            }
            $summary = $title ? '「' . $title . '」' : '';
            self::add(
                $ownerId,
                self::TYPE_VOTE,
                I18n::get('plugin.notifications.voted', ['user' => ($voterName !== '' ? $voterName : I18n::get('plugin.notifications.someone')), 'target' => $label, 'summary' => $summary]),
                $link,
                $voterName,
                $title,
                $id,
                $type
            );
        } catch (\Throwable $e) {
            \error_log('Notification notifyVote error: ' . $e->getMessage());
        }
    }

    /**
     * 注册欢迎通知
     */
    public static function notifyWelcome(int $userId): void
    {
        $userId = (int)$userId;
        if ($userId <= 0) return;
        $siteName = defined('DEFAULT_SITE_NAME') ? \DEFAULT_SITE_NAME : I18n::get('plugin.notifications.welcome_title');
        self::add(
            $userId,
            self::TYPE_WELCOME,
            I18n::get('plugin.notifications.welcome', ['site' => $siteName]),
            '/profile',
            '',
            I18n::get('plugin.notifications.welcome_title'),
            0,
            ''
        );
    }

    /**
     * 积分增加通知
     * @param int $userId  接收人
     * @param int $points  增加分数
     * @param string $reason 原因
     */
    public static function notifyPointsGain(int $userId, int $points, string $reason, int $relatedId = 0, string $relatedType = ''): void
    {
        $userId = (int)$userId;
        $points = (int)$points;
        if ($userId <= 0 || $points <= 0) return;
        $link = '';
        if ($relatedId > 0 && $relatedType === 'thread') {
            $link = '/thread/' . (int)$relatedId;
        }
        self::add(
            $userId,
            self::TYPE_POINTS,
            I18n::get('plugin.notifications.points_gain', ['reason' => ($reason ?: I18n::get('plugin.notifications.points_activity')), 'points' => $points]),
            $link,
            '',
            $reason,
            (int)$relatedId,
            $relatedType
        );
    }

    /**
     * 积分扣除通知（内容审核暂扣积分不发通知，审核通过后补发的 +积分 通知才展示）
     * @param int $userId  接收人
     * @param int $points  扣除分数（正数，内部取反展示）
     * @param string $reason 原因
     */
    public static function notifyPointsDeduct(int $userId, int $points, string $reason, int $relatedId = 0, string $relatedType = ''): void
    {
        $userId = (int)$userId;
        $points = (int)$points;
        if ($userId <= 0 || $points <= 0) return;
        if (strpos($reason, '内容审核暂扣') === 0) return; // 审核暂扣不打扰
        $link = '';
        if ($relatedId > 0 && $relatedType === 'thread') {
            $link = '/thread/' . (int)$relatedId;
        }
        self::add(
            $userId,
            self::TYPE_POINTS,
            I18n::get('plugin.notifications.points_lose', ['reason' => ($reason ?: I18n::get('plugin.notifications.points_op')), 'points' => $points]),
            $link,
            '',
            $reason,
            (int)$relatedId,
            $relatedType
        );
    }

    /**
     * 内容审核结果通知（供 content_review 的 approve/reject 调用）
     * @param int    $userId    被审核人
     * @param int    $reviewId  审核记录 ID（存入 related_id）
     * @param string $result    approved|rejected
     * @param string $title     通知标题
     * @param string $link      跳转链接
     * @param string $targetType 目标类型 thread|post|avatar
     */
    public static function notifyReview(int $userId, int $reviewId, string $result, string $title, string $link, string $targetType): void
    {
        $userId = (int)$userId;
        $reviewId = (int)$reviewId;
        if ($userId <= 0 || !in_array($result, ['approved', 'rejected'], true)) return;
        self::add(
            $userId,
            $result === 'approved' ? self::TYPE_REVIEW_APPROVED : self::TYPE_REVIEW_REJECTED,
            $title,
            $link,
            '',
            I18n::get('plugin.notifications.review_result'),
            $reviewId,
            $targetType
        );
    }

    /**
     * 引用回复通知：向被引用人发送通知（排除自己引用自己）
     * @param array $quotedUids  被引用人 ID 列表
     * @param int   $replierId   回复人 ID
     * @param int   $threadId    主题 ID
     * @param int   $postId      回复 ID
     */
    public static function notifyQuote(array $quotedUids, int $replierId, int $threadId, int $postId): void
    {
        $quotedUids = array_values(array_unique(array_filter(array_map('intval', (array)$quotedUids), fn($v) => $v > 0)));
        if (empty($quotedUids) || !$threadId || !$replierId || !$postId) return;
        try {
            $thread = (new \app\Models\Thread())->find($threadId);
            if (!$thread) return;
            $replierName = \app\Models\User::batchGetNames([$replierId])[$replierId] ?? '';
            $title = mb_substr((string)($thread['title'] ?? ''), 0, 40);
            $link = '/thread/' . $threadId . '?pid=' . $postId . '#post-' . $postId;

            foreach ($quotedUids as $uid) {
                if ($uid === $replierId) continue; // 自己引用自己不通知
                self::add(
                    $uid,
                    self::TYPE_REPLY_QUOTE,
                    I18n::get('plugin.notifications.quote', [
                        'user' => ($replierName !== '' ? $replierName : I18n::get('plugin.notifications.someone')),
                        'title' => $title,
                    ]),
                    $link,
                    $replierName,
                    $title,
                    $threadId,
                    'thread'
                );
            }
        } catch (\Throwable $e) {
            \error_log('Notification::notifyQuote error: ' . $e->getMessage());
        }
    }

    /**
     * 回复通知（即时合并版）：同一主题下多条未读回复合并为一条，标题实时更新为"N条新回复"。
     * 用户标记已读后自动重置，下次回复重新计数。
     * @param int $threadId  主题 ID
     * @param int $replierId 回复人 ID
     * @param int $postId    回复 ID
     */
    public static function notifyReplyAggregated(int $threadId, int $replierId, int $postId): void
    {
        $threadId = (int)$threadId;
        $replierId = (int)$replierId;
        $postId = (int)$postId;
        if (!$threadId || !$replierId || !$postId) return;
        try {
            $thread = (new \app\Models\Thread())->find($threadId);
            if (!$thread) return;
            $ownerId = (int)$thread['user_id'];
            if ($ownerId <= 0 || $ownerId === $replierId) return; // 自己回自己不通知

            $replierName = \app\Models\User::batchGetNames([$replierId])[$replierId] ?? '';
            $threadTitle = mb_substr((string)($thread['title'] ?? ''), 0, 40);
            $link = '/thread/' . $threadId . '?pid=' . $postId . '#post-' . $postId;

            $db = self::db();

            // 查找该主题下该用户的未读 reply 通知
            $existing = $db->fetchOne(
                "SELECT id, title FROM notifications
                 WHERE user_id = :uid AND type = :type AND related_id = :rid AND related_type = 'thread' AND is_read = 0
                 ORDER BY id DESC LIMIT 1",
                [':uid' => $ownerId, ':type' => self::TYPE_REPLY, ':rid' => $threadId]
            );

            if ($existing) {
                // 已有未读 → 从标题中提取当前计数并 +1
                $count = 1;
                if (preg_match('/(\d+)/', (string)($existing['title'] ?? ''), $m)) {
                    $count = (int)$m[1] + 1;
                }
                $newTitle = $replierName !== ''
                    ? I18n::get('plugin.notifications.reply_aggregated_named', ['user' => $replierName, 'count' => $count, 'title' => $threadTitle])
                    : I18n::get('plugin.notifications.reply_aggregated', ['count' => $count, 'title' => $threadTitle]);
                $db->query(
                    "UPDATE notifications SET title = :title, link = :link, created_at = :now WHERE id = :id",
                    [':title' => mb_substr($newTitle, 0, 500), ':link' => $link, ':now' => date('Y-m-d H:i:s'), ':id' => (int)$existing['id']]
                );
            } else {
                // 无未读 → 新建
                $title = $replierName !== ''
                    ? I18n::get('plugin.notifications.reply', ['user' => $replierName, 'title' => $threadTitle])
                    : I18n::get('plugin.notifications.reply_anonymous', ['title' => $threadTitle]);
                self::add(
                    $ownerId,
                    self::TYPE_REPLY,
                    $title,
                    $link,
                    $replierName,
                    $threadTitle,
                    $threadId,
                    'thread'
                );
            }
        } catch (\Throwable $e) {
            \error_log('Notification::notifyReplyAggregated error: ' . $e->getMessage());
        }
    }
}