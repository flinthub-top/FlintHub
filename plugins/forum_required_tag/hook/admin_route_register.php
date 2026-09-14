<?php
/**
 * FlintHub — 版块强制 Tag 插件 后台路由注册
 * 新插件不注册 admin_sidebar_links，后台入口统一走 plugin.json 的 admin_url
 * @file plugins/forum_required_tag/hook/admin_route_register.php
 * @package Plugin\ForumRequiredTag
 * @version 1.0.0
 */

if (!isset($router)) return;

// 核心后台路由组已带 /admin 前缀（index.php:187 group('/admin')，钩子注入组内 $r），
// 这里注册的是相对路径；plugin.json 的 admin_url 才写完整 /admin/forum-required-tag
$router->get('/forum-required-tag', ['\Plugin\ForumRequiredTag\AdminController', 'index']);
$router->post('/forum-required-tag/add-tag', ['\Plugin\ForumRequiredTag\AdminController', 'addTag']);
$router->post('/forum-required-tag/add-prefix', ['\Plugin\ForumRequiredTag\AdminController', 'addPrefix']);
$router->post('/forum-required-tag/delete', ['\Plugin\ForumRequiredTag\AdminController', 'delete']);
$router->post('/forum-required-tag/toggle', ['\Plugin\ForumRequiredTag\AdminController', 'toggle']);
$router->post('/forum-required-tag/copy', ['\Plugin\ForumRequiredTag\AdminController', 'copy']);
$router->post('/forum-required-tag/delete-record', ['\Plugin\ForumRequiredTag\AdminController', 'deleteRecord']);
