<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * SEO 钩子 — 注册后台管理路由
 * @file plugins/seo/hook/admin_route_register.php
 * @package Plugin\Seo
 * @version 1.0.0
 */

if (!isset($router)) return;

$ctrlFile = __DIR__ . '/../AdminController.php';
if (file_exists($ctrlFile)) require_once $ctrlFile;

$router->get('/seo', ['\Plugin\Seo\AdminController', 'index']);
$router->post('/seo/save', ['\Plugin\Seo\AdminController', 'save']);
