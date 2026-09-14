<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 邀请注册钩子 — 注册邀请码相关路由
 * @file plugins/invite/hook/route_register.php
 * @package Plugin\Invite
 * @version 1.0.0
 */

/** @var \app\Core\Router $router */
$router->get('/invites', [\Plugin\Invite\InviteController::class, 'index']);
$router->post('/invites', [\Plugin\Invite\InviteController::class, 'index']);
