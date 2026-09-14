<?php
/**
 * FlintHub — 任务中心插件 前台路由注册
 * @file plugins/task_center/hook/route_register.php
 * @package Plugin\TaskCenter
 * @version 1.0.0
 */

if (!isset($router)) return;

require_once __DIR__ . '/../FrontController.php';

$router->get('/task-center', ['\Plugin\TaskCenter\FrontController', 'index']);
$router->post('/task-center/claim', ['\Plugin\TaskCenter\FrontController', 'claim']);
