<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 勋章中心钩子 — 视图渲染前注入佩戴勋章数据
 * 注入 $data['wearingMedals'] = [userId => HTML]，供帖子楼层/右栏/个人主页展示
 * @file plugins/medal/hook/controller_view_before.php
 * @package Plugin\Medal
 * @version 1.0.0
 */

$template = $params['template'] ?? '';
$data = &$params['data'];

// 只处理需要展示佩戴勋章的视图
$targets = ['thread/show', 'blog/detail', 'plugins/user_profile/front'];
if (!in_array($template, $targets, true)) return;

// 收集需要查询的用户 ID
$uids = [];
if ($template === 'thread/show') {
    if (!empty($data['thread']['user_id'])) $uids[] = (int)$data['thread']['user_id'];
    foreach (($data['posts'] ?? []) as $p) {
        if (!empty($p['user_id'])) $uids[] = (int)$p['user_id'];
    }
} elseif ($template === 'blog/detail') {
    if (!empty($data['blog']['user_id'])) $uids[] = (int)$data['blog']['user_id'];
} elseif ($template === 'plugins/user_profile/front') {
    if (!empty($data['profileUser']['id'])) $uids[] = (int)$data['profileUser']['id'];
}
$uids = array_values(array_unique(array_filter($uids)));
if (empty($uids)) return;

try {
    // 批量查询佩戴勋章（一次查询，避免 N+1，插件独立库）
    $db = \app\Helpers\Plugin::db('medal');
    $in = implode(',', array_map('intval', $uids));
    $rows = $db->query(
        "SELECT w.user_id, m.*
         FROM user_medal_wears w
         JOIN medals m ON w.medal_id = m.id
         WHERE w.user_id IN ({$in}) AND m.status = 1
         ORDER BY w.user_id ASC, w.wear_sort ASC"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $wearingMap = [];
    foreach ($rows as $r) {
        $uid = (int)$r['user_id'];
        if (!isset($wearingMap[$uid])) $wearingMap[$uid] = '';
        $wearingMap[$uid] .= \Plugin\Medal\Plugin::renderMedal($r, 18);
    }
    // 组装成 [uid => '<span class="mn-medal-group">...</span>']
    $wearingHtml = [];
    foreach ($wearingMap as $uid => $html) {
        $wearingHtml[$uid] = '<span class="mn-medal-group">' . $html . '</span>';
    }
    $data['wearingMedals'] = $wearingHtml;
} catch (\Throwable $e) {
    \error_log('Medal controller_view_before error: ' . $e->getMessage());
}
