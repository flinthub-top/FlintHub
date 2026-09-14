<?php
/**
 * FlintHub — 社区治理插件 帖子详情回复楼层操作行举报按钮（位于楼层号前）
 * @file plugins/mod_system/hook/post_report_button.php
 * @package Plugin\ModSystem
 * @version 1.0.0
 *
 * 由核心 thread/show.php 回帖底部操作行内注入：hook('post_report_button', ['post'=>$post])
 * 注意：post_operation_after 被楼中楼 floor_reply 插件共用，切勿在此混用。
 */
if (!isset($post)) return;
if (!\app\Helpers\Auth::isLoggedIn()) return;

$postId = (int)($post['id'] ?? 0);
$uid    = (int)($post['user_id'] ?? 0);
if ($postId <= 0) return;

echo \Plugin\ModSystem\Plugin::reportButtonHtml('post', $postId, $uid);