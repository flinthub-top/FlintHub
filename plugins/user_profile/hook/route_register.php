<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 用户主页钩子 — 注册前台路由
 * @file plugins/user_profile/hook/route_register.php
 * @package Plugin\UserProfile
 * @version 1.1.0
 */

if (!isset($router)) return;

$router->get('/u/{id}', ['\Plugin\UserProfile\FrontController', 'show']);
$router->post('/u/{id}/follow', ['\Plugin\UserProfile\FrontController', 'follow']);
$router->post('/u/{id}/unfollow', ['\Plugin\UserProfile\FrontController', 'unfollow']);