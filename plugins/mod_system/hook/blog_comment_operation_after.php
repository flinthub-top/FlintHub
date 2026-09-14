<?php
/**
 * FlintHub — 社区治理插件 博客详情评论操作区举报按钮
 * @file plugins/mod_system/hook/blog_comment_operation_after.php
 * @package Plugin\ModSystem
 * @version 1.0.0
 *
 * 由核心 blog/detail.php 评论循环内注入：hook('blog_comment_operation_after', ['comment'=>$comment])
 */
if (!isset($comment)) return;
if (!\app\Helpers\Auth::isLoggedIn()) return;

$commentId = (int)($comment['id'] ?? 0);
$uid       = (int)($comment['user_id'] ?? 0);
if ($commentId <= 0) return;

echo \Plugin\ModSystem\Plugin::reportButtonHtml('blog_comment', $commentId, $uid);