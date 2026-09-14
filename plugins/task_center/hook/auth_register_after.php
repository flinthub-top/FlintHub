<?php
/**
 * FlintHub — 任务中心插件 注册完成钩子：register 任务直接完成
 * @file plugins/task_center/hook/auth_register_after.php
 * @package Plugin\TaskCenter
 * @version 1.0.0
 */

$uid = (int)($user_id ?? 0);
if ($uid > 0) {
    \Plugin\TaskCenter\Plugin::taskProgress('register', $uid, $uid);
}
