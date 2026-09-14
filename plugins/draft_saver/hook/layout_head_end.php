<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 本地草稿保护钩子 — 仅 4 类表单页注入插件资源与当前用户 ID
 * @file plugins/draft_saver/hook/layout_head_end.php
 * @package Plugin\DraftSaver
 * @version 1.0.0
 *
 * 注入策略：
 * 1. 按模板名限定：post/create（新建主题）、thread/show（回复表单所在详情页）、
 *    blog/edit（博客新建/编辑共用视图）、blog/detail（博客详情页，用于"成功页确认后
 *    清理草稿"——发帖/博客成功跳转至详情页时按 URL 清理对应草稿，替代原 pagehide 盲删）；
 *    其余页面零开销直接 return；
 * 2. draft.js 用 defer 加载：editor.js（head 同步脚本）先执行并注册 DOMContentLoaded，
 *    保证编辑器 autoInit 先于草稿绑定执行，ACTIVE_EDITORS 就绪后可读富文本；
 * 3. 用户 ID 必须服务端注入（页面无 currentUserId 全局变量），一行配置注入，零内联样式/JS。
 */

$tpl = $template ?? '';
if (!in_array($tpl, ['post/create', 'thread/show', 'blog/edit', 'blog/detail'], true)) return;

$bp = \defined('BASE_PATH') ? BASE_PATH : '';
$cssVer = @filemtime(__DIR__ . '/../assets/draft.css') ?: 1;
$jsVer  = @filemtime(__DIR__ . '/../assets/draft.js')  ?: 1;

echo '<link rel="stylesheet" href="' . $bp . '/plugins/draft_saver/assets/draft.css?v=' . $cssVer . '">';
echo '<script defer src="' . $bp . '/plugins/draft_saver/assets/draft.js?v=' . $jsVer . '"></script>';

$currentUser = \app\Helpers\Auth::getCurrentUser();
$userId = (int)($currentUser['id'] ?? 0);
echo '<script>window.FlintDraftUserId=' . $userId . ';</script>';
