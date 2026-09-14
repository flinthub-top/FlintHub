<?php
/**
 * FlintHub — 任务中心插件 head 尾部钩子：仅前台任务中心页加载插件样式
 * 后台页已自带 section('css')，其余页面不加载
 * @file plugins/task_center/hook/layout_head_end.php
 * @package Plugin\TaskCenter
 * @version 1.0.0
 */

// 仅前台任务中心页需要样式（后台页已自带）
$tpl = $template ?? '';
if ($tpl !== 'plugins/task_center/index') return;

$bp = \defined('BASE_PATH') ? BASE_PATH : '';
$cssVer = @filemtime(__DIR__ . '/../assets/style.css') ?: 1;
echo '<link rel="stylesheet" href="' . $bp . '/plugins/task_center/assets/style.css?v=' . $cssVer . '">';
