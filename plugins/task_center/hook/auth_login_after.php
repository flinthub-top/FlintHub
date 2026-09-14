<?php
/**
 * FlintHub — 任务中心插件 登录钩子：login 任务进度+1（每日任务由 Plugin 内跨天重置）
 * @file plugins/task_center/hook/auth_login_after.php
 * @package Plugin\TaskCenter
 * @version 1.0.0
 */

$uid = (int)($user_id ?? 0);
if ($uid > 0) {
    \Plugin\TaskCenter\Plugin::taskProgress('login', $uid, 0);
}
