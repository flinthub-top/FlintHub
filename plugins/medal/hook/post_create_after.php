<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 勋章中心钩子 — 回帖后核对自动规则
 * @file plugins/medal/hook/post_create_after.php
 * @package Plugin\Medal
 * @version 1.0.0
 */

$userId = (int)($params['user_id'] ?? 0);
if ($userId <= 0) return;

try {
    \Plugin\Medal\Plugin::checkAutoRules($userId);
} catch (\Throwable $e) {
    \error_log('Medal post_create_after error: ' . $e->getMessage());
}
