<?php
/**
 * FlintHub — 单页/链接管理插件 前台路由注册
 * @file plugins/single_page/hook/route_register.php
 * @package Plugin\SinglePage
 * @version 1.0.0
 */

if (!isset($router)) return;

require_once __DIR__ . '/../FrontController.php';

// 站内单页：GET /page/{slug}
$router->get('/page/{slug}', ['\Plugin\SinglePage\FrontController', 'show']);
