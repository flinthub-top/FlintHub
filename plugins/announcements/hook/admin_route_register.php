<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 公告钩子 — 注册后台管理路由
 * @file plugins/announcements/hook/admin_route_register.php
 * @package Plugin\Announcements
 * @version 1.1.0
 */

if (!isset($router)) return;

$ctrlFile = __DIR__ . '/../AdminController.php';
if (file_exists($ctrlFile)) require_once $ctrlFile;

$router->get('/announcements', ['\Plugin\Announcements\AdminController', 'index']);
$router->post('/announcements/add', ['\Plugin\Announcements\AdminController', 'add']);
$router->post('/announcements/edit', ['\Plugin\Announcements\AdminController', 'edit']);
$router->post('/announcements/delete', ['\Plugin\Announcements\AdminController', 'delete']);
$router->post('/announcements/set-mode', ['\Plugin\Announcements\AdminController', 'setMode']);
