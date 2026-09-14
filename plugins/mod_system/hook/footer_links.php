<?php
/**
 * FlintHub — 社区治理插件 页脚链接区（小黑屋公示）
 * @file plugins/mod_system/hook/footer_links.php
 * @package Plugin\ModSystem
 */
$base = \defined('BASE_PATH') ? \BASE_PATH : '';
$uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$active = (strpos($uri, '/blacklist') === 0) ? ' mn-active' : '';
echo '<a href="' . $base . '/blacklist" class="' . trim($active) . ' mn-text-decoration-none">' .
     '<i class="fa mn-fs-14">&#xf05e;</i> ' .
     \app\Helpers\I18n::get('plugin.mod_system.admin_bans') .
     '</a>';