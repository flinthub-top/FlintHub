<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 红包插件钩子 — 在插件下拉菜单中添加红包链接
 * @file plugins/red_packet/hook/nav_plugin_links.php
 * @package Plugin\RedPacket
 * @version 1.0.0
 */

$view = $GLOBALS['__view'] ?? null;
$bp = \defined('BASE_PATH') ? BASE_PATH : '';
$__active = !empty($__nav_uri) && strpos($__nav_uri, '/redpacket') === 0 ? ' mn-cat-active' : '';
if ($view) {
    echo '<a href="' . $bp . '/redpacket" class="' . $__active . '">' . $view->icon('gift', 16) . \app\Helpers\I18n::get('plugin.red_packet.title') . '</a>';
} else {
    echo '<a href="' . $bp . '/redpacket" class="' . $__active . '">🧧 ' . \app\Helpers\I18n::get('plugin.red_packet.title') . '</a>';
}
