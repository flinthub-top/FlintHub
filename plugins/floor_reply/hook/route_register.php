<?php
/**
 * 楼中楼插件 前台路由注册（自动加载器处理类，无需 require_once）
 * @file plugins/floor_reply/hook/route_register.php
 * @package Plugin\FloorReply
 * @version 1.0.0
 */

if (!isset($router)) return;

$router->get('/floor-reply/list/{id}', ['\Plugin\FloorReply\FrontController', 'list']);
$router->post('/floor-reply/reply', ['\Plugin\FloorReply\FrontController', 'reply']);
$router->post('/floor-reply/delete', ['\Plugin\FloorReply\FrontController', 'delete']);
