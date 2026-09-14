<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 内容审核钩子 — 初始化（[SplitDB] 独立库幂等建表兜底）
 * @file plugins/content_review/hook/init_after.php
 * @package Plugin\ContentReview
 * @version 1.0.0
 */

// 幂等建表兜底（独立库，插件未激活时表也可能被 hook 引用）
try {
    $db = \app\Helpers\Plugin::db('content_review');
    $db->exec("CREATE TABLE IF NOT EXISTS content_reviews (
        id INTEGER PRIMARY KEY,
        target_type TEXT NOT NULL,
        target_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        reason TEXT,
        reviewed_by INTEGER DEFAULT 0,
        points_held INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        reviewed_at TEXT
    )");
} catch (\Throwable $e) {
    error_log("CR content_review auto table creation failed: " . $e->getMessage());
}
