<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 每日签到钩子 — 初始化后检查并创建签到数据表（[SplitDB] 独立库 + SQLite DDL）
 * @file plugins/daily_checkin/hook/init_after.php
 * @package Plugin\DailyCheckin
 * @version 1.2.0
 */
try {
    $db = \app\Helpers\Plugin::db('daily_checkin');
    $ddl = "CREATE TABLE IF NOT EXISTS daily_checkin (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL,
        checkin_date TEXT NOT NULL,
        points INTEGER NOT NULL DEFAULT 0,
        consecutive_days INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        UNIQUE(user_id, checkin_date)
    )";
    $db->exec($ddl);
} catch (\Throwable $e) {
    error_log('daily_checkin init error: ' . $e->getMessage());
}