<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 编辑信息钩子 — 向帖子详情视图注入编辑信息
 * @file plugins/edit_info/hook/controller_view_before.php
 * @package Plugin\EditInfo
 * @version 1.0.0
 *
 * 可用变量：
 *   $template — 视图模板名称
 *   $data     — 视图数据（引用）
 */

use Plugin\EditInfo\Plugin as EditInfoPlugin;

// 只在帖子详情页注入
if ($template !== 'thread/show') return;

$thread = $data['thread'] ?? null;
$posts = $data['posts'] ?? [];
if (!$thread) return;

// 查出帖子本身的编辑信息
try {
    $threadEdit = EditInfoPlugin::getEditInfo('thread', (int)$thread['id']);
    if ($threadEdit) {
        $data['threadEditInfo'] = $threadEdit;
    }

    // 查出所有回复的编辑信息（批量）
    $postIds = [];
    foreach ($posts as $p) {
        if (!empty($p['id'])) $postIds[] = (int)$p['id'];
    }
    if (!empty($postIds)) {
        $postEdits = EditInfoPlugin::getEditInfoBatch('post', $postIds);
        $data['postEditInfos'] = $postEdits;
    }
} catch (\Throwable $e) {
    // 静默忽略：表不存在或查询异常
}
