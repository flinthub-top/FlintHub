<?php
/**
 * FlintHub — 版块强制 Tag 插件 前台路由注册
 * 提供版块强制配置 JSON 接口（发帖页未预选分类时，前端选分类后懒加载）
 * @file plugins/forum_required_tag/hook/route_register.php
 * @package Plugin\ForumRequiredTag
 * @version 1.0.0
 */

if (!isset($router)) return;

// 前台路由注册到主 router，路径需写完整（含 /api 前缀）
$router->get('/api/forum-required-tag/config', ['\Plugin\ForumRequiredTag\FrontController', 'config']);
