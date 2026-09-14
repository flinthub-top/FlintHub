<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 每日签到钩子 — 注册签到相关路由
 * @file plugins/daily_checkin/hook/route_register.php
 * @package Plugin\DailyCheckin
 * @version 1.0.0
 */
// 可用变量：$router (\app\Core\Router 实例)

if (!isset($router)) return;

// 引入签到控制器
require_once __DIR__ . '/../CheckinController.php';

// 注册路由
$router->get('/checkin', ['\\Plugin\\DailyCheckin\\CheckinController', 'index']);
$router->post('/checkin', ['\\Plugin\\DailyCheckin\\CheckinController', 'doCheckin']);
