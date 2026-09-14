<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 邀请注册钩子 — 在插件下拉菜单中添加邀请注册链接
 * @file plugins/invite/hook/nav_plugin_links.php
 * @package Plugin\Invite
 * @version 1.0.0
 */

if (!\app\Helpers\Auth::isLoggedIn()) return;
$view = $GLOBALS['__view'] ?? null;
if ($view) {
    echo '<a href="' . BASE_PATH . '/invites">' . $view->icon('users', 16) . \app\Helpers\I18n::get('plugin.invite.nav') . '</a>';
} else {
    echo '<a href="' . BASE_PATH . '/invites">' . \app\Helpers\I18n::get('plugin.invite.nav') . '</a>';
}
