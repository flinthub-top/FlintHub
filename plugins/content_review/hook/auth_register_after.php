<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 内容审核钩子 — 注册后标记待审（惰性字段检测）
 * @file plugins/content_review/hook/auth_register_after.php
 * @package Plugin\ContentReview
 * @version 1.1.0
 */

use Plugin\ContentReview\Plugin;

$userId = $user_id ?? 0;
if (!$userId) return;

$config = Plugin::getConfig();
if (empty($config['review_register'])) return;

// 注册审核不根据用户组跳过快查（新用户还没有组），但要排除管理员手动注册的情况
if (Plugin::isUserExempt((int)$userId, 'register')) return;

$db = \app\Core\Database::getInstance();

// 创建审核记录
Plugin::createReview('register', (int)$userId, (int)$userId);

// 标记用户为待审状态（直接尝试 UPDATE，字段不存在时静默忽略）
try {
    $db->query(
        "UPDATE users SET status = 'pending' WHERE id = :id",
        [':id' => (int)$userId]
    );
} catch (\Throwable $e) {
    // status 字段不存在时静默忽略（旧版用户表无此字段）
}
