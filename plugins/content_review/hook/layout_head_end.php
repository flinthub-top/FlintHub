<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 内容审核钩子 — 仅帖子详情页加载插件样式（待审提示条）
 * @file plugins/content_review/hook/layout_head_end.php
 * @package Plugin\ContentReview
 * @version 1.0.0
 */

// 仅在帖子详情页需要待审提示条样式（其余页面不加载）
$tpl = $template ?? '';
if ($tpl !== 'thread/show') return;

$bp = \defined('BASE_PATH') ? BASE_PATH : '';
$cssVer = @filemtime(__DIR__ . '/../assets/style.css') ?: 1;
echo '<link rel="stylesheet" href="' . $bp . '/plugins/content_review/assets/style.css?v=' . $cssVer . '">';
