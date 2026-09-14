<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 帖子收藏钩子 — 注册收藏相关路由
 * @file plugins/post_favorite/hook/route_register.php
 * @package Plugin\PostFavorite
 * @version 1.0.0
 */

if (!isset($router)) return;

$router->get('/favorites', ['\Plugin\PostFavorite\FavoriteController', 'index']);
$router->get('/favorite/check', ['\Plugin\PostFavorite\FavoriteController', 'check']);
$router->post('/favorite/toggle', ['\Plugin\PostFavorite\FavoriteController', 'toggle']);
