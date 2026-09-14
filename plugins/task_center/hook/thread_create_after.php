<?php
/**
 * FlintHub — 任务中心插件 发主题钩子：post 任务进度+1
 * @file plugins/task_center/hook/thread_create_after.php
 * @package Plugin\TaskCenter
 * @version 1.0.0
 */

$uid = (int)($user_id ?? 0);
if ($uid > 0) {
    \Plugin\TaskCenter\Plugin::taskProgress('post', $uid, (int)($thread_id ?? 0));
}
