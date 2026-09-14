<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 勋章中心钩子 — 注册后台管理路由
 * @file plugins/medal/hook/admin_route_register.php
 * @package Plugin\Medal
 * @version 1.0.0
 */

if (!isset($router)) return;

$ctrlFile = __DIR__ . '/../AdminMedalController.php';
if (file_exists($ctrlFile)) require_once $ctrlFile;

$router->get('/medals', ['\Plugin\Medal\AdminMedalController', 'index']);
$router->post('/medals/add', ['\Plugin\Medal\AdminMedalController', 'add']);
$router->post('/medals/edit', ['\Plugin\Medal\AdminMedalController', 'edit']);
$router->post('/medals/delete', ['\Plugin\Medal\AdminMedalController', 'delete']);
$router->post('/medals/grant', ['\Plugin\Medal\AdminMedalController', 'grant']);
$router->post('/medals/revoke', ['\Plugin\Medal\AdminMedalController', 'revoke']);
