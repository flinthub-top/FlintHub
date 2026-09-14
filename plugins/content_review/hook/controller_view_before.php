<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 内容审核钩子 — 视图渲染前控制待审内容的显示
 * @file plugins/content_review/hook/controller_view_before.php
 * @package Plugin\ContentReview
 * @version 1.0.0
 */

use Plugin\ContentReview\Plugin;

$template = $params['template'] ?? '';
$dataRef = &$params['data'];

if (!$template || !is_array($dataRef)) return;

try {
    $pdb = \app\Helpers\Plugin::db('content_review'); // [SplitDB] 独立库（content_reviews）
    $currentUser = \app\Helpers\Auth::getCurrentUser();
    $userId = $currentUser ? (int)$currentUser['id'] : 0;
    $isAdmin = \app\Helpers\Auth::isAdmin();

    // ====== 帖子详情页 ======
    if ($template === 'thread/show' && isset($dataRef['thread'])) {
        $threadId = (int)($dataRef['thread']['id'] ?? 0);
        if (!$threadId) return;

        // 查审核状态（独立库）
        $stmt = $pdb->prepare(
            "SELECT status FROM content_reviews WHERE target_type = 'thread' AND target_id = :tid AND status = 'pending'"
        );
        $stmt->execute([':tid' => $threadId]);
        $review = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$review) return; // 不在审核中

        $threadUserId = (int)($dataRef['thread']['user_id'] ?? 0);
        $pendingNotice = '<div class="pending-review-notice"><p>' . \app\Helpers\I18n::get('plugin.content_review.pending_thread_hidden') . '</p></div>';
        $authorNotice = '<div class="pending-review-notice pending-review-notice-author"><p>' . \app\Helpers\I18n::get('plugin.content_review.pending_thread_author') . '</p></div>';
        $adminNotice = '<div class="pending-review-notice pending-review-notice-admin"><p>' . \app\Helpers\I18n::get('plugin.content_review.pending_thread_admin') . '</p></div>';

        if ($isAdmin) {
            $dataRef['thread']['content'] = $adminNotice . $dataRef['thread']['content'];
        } elseif ($userId > 0 && $userId === $threadUserId) {
            $dataRef['thread']['content'] = $authorNotice . $dataRef['thread']['content'];
        } else {
            $dataRef['thread']['content'] = $pendingNotice;
        }

        // 收集待审 ID 供 JS 标题标记使用
        if (!isset($GLOBALS['_cr_pending_ids'])) $GLOBALS['_cr_pending_ids'] = [];
        $GLOBALS['_cr_pending_ids'][] = $threadId;
    }

    // ====== 帖子回复列表 ======
    if ($template === 'thread/show' && isset($dataRef['posts'])) {
        $posts = $dataRef['posts'];
        $postIds = [];
        foreach ($posts as $post) {
            $pid = (int)($post['id'] ?? 0);
            if ($pid) $postIds[] = $pid;
        }

        if (!empty($postIds)) {
            // 批量查询所有待审回复，避免 N+1（独立库）
            $in = implode(',', array_map('intval', $postIds));
            $pendingRows = $pdb->query(
                "SELECT target_id FROM content_reviews WHERE target_type = 'post' AND target_id IN ({$in}) AND status = 'pending'"
            )->fetchAll(\PDO::FETCH_ASSOC);
            $pendingPostMap = [];
            foreach ($pendingRows as $row) {
                $pendingPostMap[(int)$row['target_id']] = true;
            }

            foreach ($posts as $k => $post) {
                $pid = (int)($post['id'] ?? 0);
                if (!$pid || !isset($pendingPostMap[$pid])) continue;

                $postUserId = (int)($post['user_id'] ?? 0);
                if ($isAdmin) {
                    $dataRef['posts'][$k]['_review_pending'] = true;
                } elseif ($userId > 0 && $userId === $postUserId) {
                    $dataRef['posts'][$k]['_review_pending'] = true;
                } else {
                    $dataRef['posts'][$k]['_rendered'] = '<div class="pending-review-notice"><p>' . \app\Helpers\I18n::get('plugin.content_review.pending_post_hidden') . '</p></div>';
                    $dataRef['posts'][$k]['_review_hidden'] = true;
                }
            }
        }
    }

    // ====== 首页 / 论坛列表 / 搜索 ======
    // 批量查询待审状态，避免每条一个 SQL（独立库）
    foreach (['latestThreads', 'threads', 'results'] as $key) {
        if (!isset($dataRef[$key]) || !is_array($dataRef[$key])) continue;
        $items = $dataRef[$key];
        $ids = [];
        foreach ($items as $item) {
            if (isset($item['id'])) $ids[] = (int)$item['id'];
        }
        if (empty($ids)) continue;

        // 一次性查出所有待审的帖子 ID
        $in = implode(',', $ids);
        $pendingRows = $pdb->query(
            "SELECT target_id FROM content_reviews WHERE target_type = 'thread' AND target_id IN ({$in}) AND status = 'pending'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $pendingIds = [];
        foreach ($pendingRows as $row) {
            $pendingIds[(int)$row['target_id']] = true;
        }

        if ($isAdmin) {
            foreach ($items as $i => &$item) {
                $tid = (int)($item['id'] ?? 0);
                if (isset($pendingIds[$tid])) {
                    $item['_pending_review'] = true;
                    if (!isset($GLOBALS['_cr_pending_ids'])) $GLOBALS['_cr_pending_ids'] = [];
                    $GLOBALS['_cr_pending_ids'][] = $tid;
                }
            }
            unset($item);
            $dataRef[$key] = $items;
        } else {
            $filtered = [];
            foreach ($items as $item) {
                $tid = (int)($item['id'] ?? 0);
                if (!$tid || !isset($pendingIds[$tid])) {
                    $filtered[] = $item;
                } else {
                    $itemUserId = (int)($item['user_id'] ?? 0);
                    if ($userId > 0 && $userId === $itemUserId) {
                        $item['_pending_review'] = true;
                        if (!isset($GLOBALS['_cr_pending_ids'])) $GLOBALS['_cr_pending_ids'] = [];
                        $GLOBALS['_cr_pending_ids'][] = $tid;
                        $filtered[] = $item;
                    }
                }
            }
            $dataRef[$key] = $filtered;
        }
    }

} catch (\Throwable $e) {
    error_log("CR controller_view_before error: " . $e->getMessage());
}
