<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 内容审核钩子 — 发帖后标记待审
 * @file plugins/content_review/hook/thread_create_after.php
 * @package Plugin\ContentReview
 * @version 1.0.0
 */

use Plugin\ContentReview\Plugin;

$threadId = $thread_id ?? 0;
$userId = $user_id ?? 0;
$categoryId = $category_id ?? 0;

if (!$threadId || !$userId) return;

$config = Plugin::getConfig();

// 检查是否需要审核：全局开启 或 当前版块在分版块列表中
if (!Plugin::isThreadReviewRequired((int)$categoryId)) return;

if (Plugin::isUserExempt((int)$userId)) return;

// 暂扣发帖积分（发帖 5 分）：审核通过后由 approve() 补发，驳回则不归还
$pointsHeld = 5;
try {
    \app\Helpers\Points::deduct((int)$userId, $pointsHeld, '内容审核暂扣（发布主题）', (int)$threadId, 'thread');
} catch (\Throwable $e) {
    \error_log('content_review thread points hold error: ' . $e->getMessage());
}

// 创建审核记录 + 发送待审通知（表缺失/异常时静默降级，不 500）
try {
    // 创建审核记录（不隐藏帖子，仅标记待审，通过 controller_view_before 控制显示）
    $reviewId = Plugin::createReview('thread', (int)$threadId, (int)$userId, $pointsHeld);

    // 待审通知（通知内置化：写入核心 notifications 表；标题经 Thread Model 读分片）
    $t = (new \app\Models\Thread())->find((int)$threadId);
    $title = $t ? mb_substr((string)($t['title'] ?? ''), 0, 50) : '';
    $notifyTitle = $title ? \app\Helpers\I18n::get('plugin.content_review.notify_thread_pending', ['title' => $title]) : \app\Helpers\I18n::get('plugin.content_review.notify_thread_pending_short');
    \app\Helpers\Notification::add((int)$userId, \app\Helpers\Notification::TYPE_REVIEW_PENDING, $notifyTitle, '/thread/' . (int)$threadId, '', '', (int)$reviewId, 'thread');
} catch (\Throwable $e) {
    \error_log('content_review thread_create_after error: ' . $e->getMessage());
}
