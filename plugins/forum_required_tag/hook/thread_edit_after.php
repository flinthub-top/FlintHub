<?php
/**
 * FlintHub — 版块强制 Tag 插件 编辑帖成功后记录钩子
 * thread_edit_after：$params 提供 thread_id/user_id（ThreadController.php:313 已核实）
 * 逻辑：删除该帖旧记录 → 重新记录（防 Tag/前缀变更残留）
 * @file plugins/forum_required_tag/hook/thread_edit_after.php
 * @package Plugin\ForumRequiredTag
 * @version 1.0.0
 */

$threadId = (int)($params['thread_id'] ?? 0);
$userId = (int)($params['user_id'] ?? 0);
if ($threadId <= 0) return;

// 编辑后新版块（route_before_dispatch 已按新版块校验；此处取 POST 与回退逻辑保持一致）
$forumId = (int)($_POST['category_id'] ?? 0);
if ($forumId <= 0) {
    $thread = (new \app\Models\Thread())->getById($threadId); // 实例方法调用（禁止静态调用）
    if (!$thread) return;
    $forumId = (int)$thread['category_id'];
}
if ($forumId <= 0) return;

// 先删旧记录（幂等：无旧记录也无副作用）
\Plugin\ForumRequiredTag\Plugin::deletePostTag($threadId);

// 版块未配置强制项 → 不重记（0 影响）
if (!\Plugin\ForumRequiredTag\Plugin::isForumConstrained($forumId)) return;

$tagName = '';
$prefix = '';
$tagsRaw = (string)($_POST['tags'] ?? '');
$title = (string)($_POST['title'] ?? '');

$tags = \Plugin\ForumRequiredTag\Plugin::getForumTags($forumId);
if (!empty($tags)) {
    $submitted = \array_values(\array_filter(\array_map('trim', \explode(',', $tagsRaw))));
    $allowed = \array_column($tags, 'tag_name');
    foreach ($submitted as $t) {
        if (\in_array($t, $allowed, true)) {
            $tagName = $t;
            break;
        }
    }
}

$prefixes = \Plugin\ForumRequiredTag\Plugin::getForumPrefixes($forumId);
if (!empty($prefixes)) {
    $title = \trim($title);
    foreach ($prefixes as $p) {
        if (\str_starts_with($title, '[' . $p['prefix'] . ']')) {
            $prefix = $p['prefix'];
            break;
        }
    }
}

if ($tagName !== '' || $prefix !== '') {
    \Plugin\ForumRequiredTag\Plugin::logPostTag($threadId, $forumId, $tagName, $prefix, $userId);
}
