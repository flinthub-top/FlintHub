<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 内容审核钩子 — 注册前台路由（通知页）
 * @file plugins/content_review/hook/route_register.php
 * @package Plugin\ContentReview
 * @version 1.0.0
 */

if (!isset($router)) return;

$ctrlFile = __DIR__ . '/../FrontController.php';
if (file_exists($ctrlFile)) {
    require_once $ctrlFile;
}
