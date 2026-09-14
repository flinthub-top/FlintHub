<?php
/**
 * FlintHub — 任务中心插件 头像上传钩子：立即刷新 profile 类任务进度
 * （profile 为惰性统计，此处提前重算一次，保证"传完头像进度即时更新"）
 * @file plugins/task_center/hook/avatar_upload_after.php
 * @package Plugin\TaskCenter
 * @version 1.0.0
 */

$uid = (int)($user_id ?? 0);
if ($uid > 0) {
    \Plugin\TaskCenter\Plugin::refreshLazyProgress($uid);
}
