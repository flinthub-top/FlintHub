<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 勋章中心钩子 — 注册前台路由
 * @file plugins/medal/hook/route_register.php
 * @package Plugin\Medal
 * @version 1.0.0
 */

if (!isset($router)) return;

$ctrlFile = __DIR__ . '/../MedalController.php';
if (file_exists($ctrlFile)) require_once $ctrlFile;

$router->get('/medal', ['\Plugin\Medal\MedalController', 'index']);
$router->post('/medal/wear', ['\Plugin\Medal\MedalController', 'wear']);
