<?php
/**
 * FlintHub — 社区治理插件 后台路由
 * @file plugins/mod_system/hook/admin_route_register.php
 * @package Plugin\ModSystem
 * @version 1.0.0
 */
if (!isset($router)) return;

require_once __DIR__ . '/../Plugin.php';
require_once __DIR__ . '/../AdminController.php';

// 单页「社区管理」入口（默认「审核封禁」Tab）
$router->get('/mod-system', ['\Plugin\ModSystem\AdminController', 'reports']);
// 举报列表
$router->get('/mod-system/reports', ['\Plugin\ModSystem\AdminController', 'reports']);
// 单条处理
$router->post('/mod-system/reports/{id}/handle', ['\Plugin\ModSystem\AdminController', 'handle']);
// 批量处理
$router->post('/mod-system/reports/batch', ['\Plugin\ModSystem\AdminController', 'batch']);
// 手动封禁
$router->post('/mod-system/bans/create', ['\Plugin\ModSystem\AdminController', 'banCreate']);
// 解封
$router->post('/mod-system/bans/{id}/unban', ['\Plugin\ModSystem\AdminController', 'unban']);
// 配置页
$router->get('/mod-system/config', ['\Plugin\ModSystem\AdminController', 'config']);
$router->post('/mod-system/config/save', ['\Plugin\ModSystem\AdminController', 'configSave']);