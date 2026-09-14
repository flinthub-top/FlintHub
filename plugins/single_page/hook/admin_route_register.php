<?php
/**
 * FlintHub — 单页/链接管理插件 后台路由注册
 * @file plugins/single_page/hook/admin_route_register.php
 * @package Plugin\SinglePage
 * @version 1.0.0
 */

if (!isset($router)) return;

$ctrlFile = __DIR__ . '/../AdminController.php';
if (file_exists($ctrlFile)) {
    require_once $ctrlFile;
}

$router->get('/single-page', ['\Plugin\SinglePage\AdminController', 'index']);
$router->post('/single-page/add', ['\Plugin\SinglePage\AdminController', 'add']);
$router->post('/single-page/edit', ['\Plugin\SinglePage\AdminController', 'edit']);
$router->post('/single-page/delete', ['\Plugin\SinglePage\AdminController', 'delete']);
