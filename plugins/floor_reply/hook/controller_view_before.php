<?php
/**
 * 楼中楼插件 视图渲染前注入 — 帖子详情页一次性批量统计各楼层楼中楼数量
 * 结果存 $GLOBALS['__floor_reply_counts']，post_operation_after 钩子读取，避免每楼一次 count 查询。
 * @file plugins/floor_reply/hook/controller_view_before.php
 * @package Plugin\FloorReply
 * @version 1.0.0
 */

$template = $params['template'] ?? '';
if ($template !== 'thread/show') return; // 仅帖子详情页

$data = &$params['data'];
$posts = $data['posts'] ?? [];
if (empty($posts)) return;

$ids = [];
foreach ($posts as $p) {
    if (!empty($p['id'])) $ids[] = (int)$p['id'];
}
if (!$ids) return;

$GLOBALS['__floor_reply_counts'] = \Plugin\FloorReply\Plugin::getCountByPostIds($ids);
