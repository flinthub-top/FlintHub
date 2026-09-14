<?php
/**
 * FlintHub — 任务中心插件 后台路由注册
 * @file plugins/task_center/hook/admin_route_register.php
 * @package Plugin\TaskCenter
 * @version 1.0.0
 */

if (!isset($router)) return;

$ctrlFile = __DIR__ . '/../AdminController.php';
if (file_exists($ctrlFile)) {
    require_once $ctrlFile;
}

// 后台路由注册不带 /admin 前缀：核心挂载点会自动拼接 /admin（与 mod_system/points_mall 同规范）
$router->get('/task-center', ['\Plugin\TaskCenter\AdminController', 'index']);
$router->post('/task-center/add', ['\Plugin\TaskCenter\AdminController', 'add']);
$router->post('/task-center/edit', ['\Plugin\TaskCenter\AdminController', 'edit']);
$router->post('/task-center/delete', ['\Plugin\TaskCenter\AdminController', 'delete']);
