<?php
/**
 * FlintHub — 社区治理插件 移动抽屉入口（小黑屋公示）
 * @file plugins/mod_system/hook/nav_drawer_links.php
 * @package Plugin\ModSystem
 */
$base = \defined('BASE_PATH') ? \BASE_PATH : '';
$uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$active = (strpos($uri, '/blacklist') === 0) ? ' mn-drawer-active' : '';
echo '<a href="' . $base . '/blacklist" class="mn-drawer-group-title' . $active . '">' .
     '<i class="fa mn-fs-15">&#xf05e;</i> ' .
     \app\Helpers\I18n::get('plugin.mod_system.admin_bans') .
     '</a>';