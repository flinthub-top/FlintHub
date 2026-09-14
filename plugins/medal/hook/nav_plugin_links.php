<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 勋章中心钩子 — 前台"应用"面板/下拉入口
 * @file plugins/medal/hook/nav_plugin_links.php
 * @package Plugin\Medal
 * @version 1.0.0
 */

$view = $GLOBALS['__view'] ?? null;
$bp = \defined('BASE_PATH') ? BASE_PATH : '';
$__active = !empty($__nav_uri) && strpos($__nav_uri, '/medal') === 0 ? ' mn-cat-active' : '';
if ($view) {
    echo '<a href="' . $bp . '/medal" class="' . $__active . '">' . $view->icon('award', 16) . \app\Helpers\I18n::get('plugin.medal.nav') . '</a>';
} else {
    echo '<a href="' . $bp . '/medal" class="' . $__active . '">🏅 ' . \app\Helpers\I18n::get('plugin.medal.nav') . '</a>';
}
