<?php
/**
 * FlintHub — 任务中心插件 左侧栏「应用」面板 / 顶部「应用」下拉入口
 * @file plugins/task_center/hook/nav_plugin_links.php
 * @package Plugin\TaskCenter
 * @version 1.0.0
 */

$base = defined('BASE_PATH') ? BASE_PATH : '';
$__active = !empty($__nav_uri) && strpos($__nav_uri, '/task-center') === 0 ? ' mn-cat-active' : '';
$view = $GLOBALS['__view'] ?? null;
if ($view) {
    echo '<a href="' . $base . '/task-center" class="' . $__active . '">' . $view->icon('clipboard-list', 16) . \app\Helpers\I18n::get('plugin.task_center.nav') . '</a>';
} else {
    echo '<a href="' . $base . '/task-center" class="' . $__active . '">📋 ' . \app\Helpers\I18n::get('plugin.task_center.nav') . '</a>';
}
