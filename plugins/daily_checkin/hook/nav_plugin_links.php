<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 每日签到钩子 — 在插件下拉菜单中添加签到链接
 * @file plugins/daily_checkin/hook/nav_plugin_links.php
 * @package Plugin\DailyCheckin
 * @version 1.0.0
 */

$view = $GLOBALS['__view'] ?? null;
$bp = \defined('BASE_PATH') ? BASE_PATH : '';
$__active = !empty($__nav_uri) && strpos($__nav_uri, '/checkin') === 0 ? ' mn-cat-active' : '';
if ($view) {
    echo '<a href="' . $bp . '/checkin" class="' . $__active . '">' . $view->icon('calendar', 16) . \app\Helpers\I18n::get('plugin.daily_checkin.title') . '</a>';
} else {
    echo '<a href="' . $bp . '/checkin" class="' . $__active . '">📅 ' . \app\Helpers\I18n::get('plugin.daily_checkin.title') . '</a>';
}
