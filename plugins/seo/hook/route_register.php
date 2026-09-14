<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * SEO 钩子 — 注册前台路由（sitemap.xml / robots.txt）
 * @file plugins/seo/hook/route_register.php
 * @package Plugin\Seo
 * @version 1.0.0
 */

if (!isset($router)) return;

$ctrlFile = __DIR__ . '/../FrontController.php';
if (file_exists($ctrlFile)) require_once $ctrlFile;

$router->get('/sitemap.xml', ['\Plugin\Seo\FrontController', 'sitemap']);
$router->get('/robots.txt',  ['\Plugin\Seo\FrontController', 'robots']);
