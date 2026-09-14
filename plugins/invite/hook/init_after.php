<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 邀请注册钩子 — 初始化后创建邀请码数据表（[SplitDB] 独立库 + SQLite DDL）
 * @file plugins/invite/hook/init_after.php
 * @package Plugin\Invite
 * @version 1.0.0
 */

// 创建邀请码数据表（幂等）
$db = \app\Helpers\Plugin::db('invite');
try {
    $db->exec("CREATE TABLE IF NOT EXISTS invites (
        id INTEGER PRIMARY KEY,
        code TEXT NOT NULL UNIQUE,
        creator_id INTEGER NOT NULL,
        used_by_user_id INTEGER,
        used_at TEXT,
        expires_at TEXT,
        points_cost INTEGER DEFAULT 10,
        created_at TEXT
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_invites_code ON invites (code)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_invites_creator ON invites (creator_id)');
} catch (\Exception $e) {
    \error_log('[invite] init error: ' . $e->getMessage());
}
