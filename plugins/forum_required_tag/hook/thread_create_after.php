<?php
/**
 * FlintHub — 版块强制 Tag 插件 发帖成功后记录钩子
 * thread_create_after：$params 提供 thread_id/user_id/category_id/title（已 grep 核实 PostController.php:160）
 * 记录实际使用的 Tag 与标题前缀（仅当版块配置了强制项时写，避免无意义记录）
 * @file plugins/forum_required_tag/hook/thread_create_after.php
 * @package Plugin\ForumRequiredTag
 * @version 1.0.0
 */

$threadId = (int)($params['thread_id'] ?? 0);
$forumId = (int)($params['category_id'] ?? 0);
$userId = (int)($params['user_id'] ?? 0);
if ($threadId <= 0 || $forumId <= 0) return;

// 版块未配置强制项 → 不记录（0 影响）
if (!\Plugin\ForumRequiredTag\Plugin::isForumConstrained($forumId)) return;

$tagName = '';
$prefix = '';
$tagsRaw = (string)($_POST['tags'] ?? '');
$title = (string)($params['title'] ?? ($_POST['title'] ?? ''));

// 记录实际命中的 Tag（记第一个命中的预设 Tag，与校验语义一致：任一位置命中即算）
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

// 记录实际命中的前缀（取标题开头匹配的第一个）
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

// 任一命中才写记录
if ($tagName !== '' || $prefix !== '') {
    \Plugin\ForumRequiredTag\Plugin::logPostTag($threadId, $forumId, $tagName, $prefix, $userId);
}
