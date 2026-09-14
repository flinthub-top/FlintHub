<?php
/**
 * FlintHub — 社区治理插件 帖子详情主帖操作区举报按钮
 * @file plugins/mod_system/hook/thread_operation_after.php
 * @package Plugin\ModSystem
 * @version 1.0.0
 *
 * 由核心 thread/show.php 注入：hook('thread_operation_after', ['thread'=>$thread])
 */
if (!isset($thread)) return;
if (!\app\Helpers\Auth::isLoggedIn()) return;

$tid = (int)($thread['id'] ?? 0);
$uid = (int)($thread['user_id'] ?? 0);
if ($tid <= 0) return;

echo \Plugin\ModSystem\Plugin::reportButtonHtml('thread', $tid, $uid);