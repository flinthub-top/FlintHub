<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 帖子收藏钩子 — PC 顶栏收藏入口
 * @file plugins/post_favorite/hook/nav_pc_user_links.php
 * @package Plugin\PostFavorite
 * @version 1.2.0
 *
 * 置空：PC 顶栏收藏图标已并入用户下拉菜单（app/Views/layouts/main.php 的
 * .mn-user-dropdown「我的收藏」项，收藏数 htmx 懒加载 /api/favorites-count）。
 * 本文件保留用于钩子注册兼容，不再输出任何内容；
 * 通知按钮由 plugins/notifications/hook/nav_pc_user_links.php 独立输出，不受影响。
 */

return;
