<?php
/**
 * FlintHub — 社区治理插件 前台路由
 * @file plugins/mod_system/hook/route_register.php
 * @package Plugin\ModSystem
 * @version 1.0.0
 */
if (!isset($router)) return;

require_once __DIR__ . '/../Plugin.php';
require_once __DIR__ . '/../FrontController.php';

// 举报提交（htmx POST）
$router->post('/mod-system/report', ['\Plugin\ModSystem\FrontController', 'submit']);
// 小黑屋公示（公开）
$router->get('/blacklist', ['\Plugin\ModSystem\FrontController', 'blacklist']);