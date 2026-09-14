<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 友情链接钩子 — 注册前台路由
 * @file plugins/friend_links/hook/route_register.php
 * @package Plugin\FriendLinks
 * @version 1.1.0
 */

if (!isset($router)) return;

require_once __DIR__ . '/../FrontController.php';

$router->get('/links/apply', ['\Plugin\FriendLinks\FrontController', 'apply']);
$router->post('/links/apply', ['\Plugin\FriendLinks\FrontController', 'apply']);
