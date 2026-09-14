<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 友情链接钩子 — 注册后台管理路由
 * @file plugins/friend_links/hook/admin_route_register.php
 * @package Plugin\FriendLinks
 * @version 1.1.0
 */

if (!isset($router)) return;

// 保险：直接引入控制器（兼容自动加载器尚未完善的情况）
$ctrlFile = __DIR__ . '/../AdminController.php';
if (file_exists($ctrlFile)) {
    require_once $ctrlFile;
}

$router->get('/friend-links', ['\Plugin\FriendLinks\AdminController', 'index']);
$router->post('/friend-links/add', ['\Plugin\FriendLinks\AdminController', 'add']);
$router->post('/friend-links/edit', ['\Plugin\FriendLinks\AdminController', 'edit']);
$router->post('/friend-links/delete', ['\Plugin\FriendLinks\AdminController', 'delete']);
$router->post('/friend-links/approve', ['\Plugin\FriendLinks\AdminController', 'approve']);
$router->post('/friend-links/reject', ['\Plugin\FriendLinks\AdminController', 'reject']);
$router->post('/friend-links/toggle-apply', ['\Plugin\FriendLinks\AdminController', 'toggleApply']);
