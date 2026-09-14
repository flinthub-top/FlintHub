<?php
/**
 * FlintHub — 任务中心插件 回帖钩子：reply 任务进度+1
 * @file plugins/task_center/hook/post_create_after.php
 * @package Plugin\TaskCenter
 * @version 1.0.0
 */

$uid = (int)($user_id ?? 0);
if ($uid > 0) {
    \Plugin\TaskCenter\Plugin::taskProgress('reply', $uid, (int)($post_id ?? 0));
}
