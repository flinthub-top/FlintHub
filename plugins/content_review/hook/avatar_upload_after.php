<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 内容审核钩子 — 头像上传后标记待审
 * @file plugins/content_review/hook/avatar_upload_after.php
 * @package Plugin\ContentReview
 * @version 1.0.0
 */

use Plugin\ContentReview\Plugin;

$userId = $user_id ?? 0;
$newAvatar = $avatar ?? '';
$oldAvatar = $old_avatar ?? '';

if (!$userId || !$newAvatar) return;

$config = Plugin::getConfig();
if (empty($config['review_avatar'])) return;

if (Plugin::isUserExempt((int)$userId, 'avatar')) return;

// 创建审核记录 + 发送待审通知（独立库；表缺失/异常时静默降级，不 500）
try {
    $pdb = \app\Helpers\Plugin::db('content_review'); // [SplitDB] 独立库
    $db = \app\Core\Database::getInstance(); // 核心库（users）

    // 新头像已被 ProfileController 写入 DB，现在回退到旧头像（经 User Model，不再直写核心表）
    // 旧头像文件保留未删（ProfileController 已判断）
    (new \app\Models\User())->updateAvatar((int)$userId, (string)($oldAvatar ?: ''));

    // 创建审核记录，reason 字段存新头像路径（独立库）
    $reviewId = Plugin::createReview('avatar', (int)$userId, (int)$userId);
    $pdb->prepare("UPDATE content_reviews SET reason = :avatar WHERE id = :id")
       ->execute([':avatar' => $newAvatar, ':id' => $reviewId]);

    // 发送待审通知（通知内置化：写入核心 notifications 表）
    \app\Helpers\Notification::add((int)$userId, \app\Helpers\Notification::TYPE_REVIEW_PENDING, \app\Helpers\I18n::get('plugin.content_review.notify_avatar_pending'), '/profile', '', '', (int)$reviewId, 'avatar');
} catch (\Throwable $e) {
    \error_log('content_review avatar_upload_after error: ' . $e->getMessage());
}
