<?php
/**
 * FlintHub — 任务中心插件 投票钩子：vote 任务进度+1
 * 仅统计真实点赞（vote=1），排除自赞（user_id === owner_id）；点踩/取消不计数
 * @file plugins/task_center/hook/vote_after.php
 * @package Plugin\TaskCenter
 * @version 1.0.0
 */

$uid = (int)($user_id ?? 0);
$owner = (int)($owner_id ?? 0);
$vote = (int)($vote ?? 0);
if ($uid > 0 && $vote === 1 && $owner !== $uid) {
    \Plugin\TaskCenter\Plugin::taskProgress('vote', $uid, (int)($id ?? 0));
}
