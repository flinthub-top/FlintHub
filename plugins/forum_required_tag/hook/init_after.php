<?php
/**
 * FlintHub — 版块强制 Tag 插件 初始化钩子
 * 独立库幂等建表兜底（插件未激活时表也可能被钩子引用）
 * @file plugins/forum_required_tag/hook/init_after.php
 * @package Plugin\ForumRequiredTag
 * @version 1.0.0
 */

try {
    \Plugin\ForumRequiredTag\Plugin::ensureSchema();
} catch (\Throwable $e) {
    \error_log('forum_required_tag init_after ensureSchema error: ' . $e->getMessage());
}
