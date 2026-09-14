<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 勋章中心钩子 — 启动时初始化（懒建表 + 自动规则兜底检查）
 * 性能优化：建表按会话只做一次；自动规则检查 30 分钟节流，避免每请求多次查询
 * @file plugins/medal/hook/init_after.php
 * @package Plugin\Medal
 * @version 1.1.0
 */

// 自动创建三张表（如不存在，幂等，[SplitDB] 独立库）——会话守卫：每个会话只执行一次
if (empty($_SESSION['medal_tables_ready'])) {
    try {
        $db = \app\Helpers\Plugin::db('medal');
        $db->exec("CREATE TABLE IF NOT EXISTS medals (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            icon TEXT NOT NULL DEFAULT 'award',
            color TEXT NOT NULL DEFAULT '#f59e0b',
            description TEXT NOT NULL DEFAULT '',
            condition_type TEXT NOT NULL DEFAULT 'manual',
            condition_value INTEGER NOT NULL DEFAULT 0,
            sort INTEGER NOT NULL DEFAULT 0,
            status INTEGER NOT NULL DEFAULT 1,
            image TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS user_medals (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            medal_id INTEGER NOT NULL,
            source TEXT NOT NULL DEFAULT 'auto',
            created_at TEXT NOT NULL,
            UNIQUE(user_id, medal_id)
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS user_medal_wears (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            medal_id INTEGER NOT NULL,
            wear_sort INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            UNIQUE(user_id, wear_sort),
            UNIQUE(user_id, medal_id)
        )");
        $_SESSION['medal_tables_ready'] = 1;
    } catch (\Throwable $e) {
        // 忽略，可能数据库未就绪
    }
}

// 已登录用户惰性核对自动规则——30 分钟节流（发帖/回帖/注册钩子仍即时触发）
try {
    $currentUser = \app\Helpers\Auth::getCurrentUser();
    if (!empty($currentUser['id'])) {
        $uid = (int)$currentUser['id'];
        $lastCheck = (int)($_SESSION['medal_auto_check_' . $uid] ?? 0);
        if (time() - $lastCheck > 1800) {
            \Plugin\Medal\Plugin::checkAutoRules($uid);
            $_SESSION['medal_auto_check_' . $uid] = time();
        }
    }
} catch (\Throwable $e) {
    \error_log('Medal init_after check error: ' . $e->getMessage());
}
