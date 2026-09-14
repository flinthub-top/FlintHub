<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 内容审核钩子 — 回帖后标记待审
 * @file plugins/content_review/hook/post_create_after.php
 * @package Plugin\ContentReview
 * @version 1.0.0
 */

use Plugin\ContentReview\Plugin;

$postId = $post_id ?? 0;
$userId = $user_id ?? 0;

if (!$postId || !$userId) return;

$config = Plugin::getConfig();
if (empty($config['review_post'])) return;

if (Plugin::isUserExempt((int)$userId)) return;

// 暂扣回帖积分（回帖 2 分）：审核通过后由 approve() 补发，驳回则不归还
$pointsHeld = 2;
try {
    \app\Helpers\Points::deduct((int)$userId, $pointsHeld, '内容审核暂扣（回复帖子）', (int)$postId, 'post');
} catch (\Throwable $e) {
    \error_log('content_review post points hold error: ' . $e->getMessage());
}

// 创建审核记录 + 发送待审通知（表缺失/异常时静默降级，不 500）
try {
    $reviewId = Plugin::createReview('post', (int)$postId, (int)$userId, $pointsHeld);

    // 待审通知（通知内置化：写入核心 notifications 表）
    $notifyTitle = \app\Helpers\I18n::get('plugin.content_review.notify_post_pending');
    \app\Helpers\Notification::add((int)$userId, \app\Helpers\Notification::TYPE_REVIEW_PENDING, $notifyTitle, '', '', '', (int)$reviewId, 'post');
} catch (\Throwable $e) {
    \error_log('content_review post_create_after error: ' . $e->getMessage());
}
