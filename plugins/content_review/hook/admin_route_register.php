<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 内容审核钩子 — 注册后台管理路由
 * @file plugins/content_review/hook/admin_route_register.php
 * @package Plugin\ContentReview
 * @version 1.0.0
 */

if (!isset($router)) return;

$ctrlFile = __DIR__ . '/../AdminController.php';
if (file_exists($ctrlFile)) {
    require_once $ctrlFile;
}

$router->get('/content-review', ['\Plugin\ContentReview\AdminController', 'index']);
$router->post('/content-review/approve', ['\Plugin\ContentReview\AdminController', 'approve']);
$router->post('/content-review/reject', ['\Plugin\ContentReview\AdminController', 'reject']);
$router->post('/content-review/settings', ['\Plugin\ContentReview\AdminController', 'settings']);
$router->post('/content-review/delete', ['\Plugin\ContentReview\AdminController', 'delete']);
$router->post('/content-review/batch-delete', ['\Plugin\ContentReview\AdminController', 'batchDelete']);
$router->post('/content-review/batch-approve', ['\Plugin\ContentReview\AdminController', 'batchApprove']);
$router->post('/content-review/batch-reject', ['\Plugin\ContentReview\AdminController', 'batchReject']);
