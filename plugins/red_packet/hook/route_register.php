<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 红包插件钩子 — 注册红包相关路由
 * @file plugins/red_packet/hook/route_register.php
 * @package Plugin\RedPacket
 * @version 1.0.0
 */

if (!isset($router)) return;

// 引入红包控制器
require_once __DIR__ . '/../RedPacketController.php';

// 注册路由
$router->get('/redpacket', ['\\Plugin\\RedPacket\\RedPacketController', 'index']);
$router->post('/redpacket/create', ['\\Plugin\\RedPacket\\RedPacketController', 'create']);
$router->get('/redpacket/{id}', ['\\Plugin\\RedPacket\\RedPacketController', 'detail']);
$router->post('/redpacket/{id}/grab', ['\\Plugin\\RedPacket\\RedPacketController', 'grab']);
