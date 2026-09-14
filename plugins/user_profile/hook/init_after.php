<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 用户主页钩子 — 启动时初始化（自动建表）
 * @file plugins/user_profile/hook/init_after.php
 * @package Plugin\UserProfile
 * @version 1.2.0
 */

// 原实现用 MySQL 语法（INT AUTO_INCREMENT / UNIQUE KEY / INDEX）在核心库建表，
// SplitDB SQLite 下必然语法失败 → 表永远建不出来，新装站点侧边栏 getFollowCounts() 报 no such table → 500。
// 现改为：SQLite 语法 + 插件独立库（Plugin::db）幂等建表，DDL 与 Plugin::activate() 完全一致。

// 自动创建 user_follows 表（如不存在，插件独立库）
// 性能优化：缓存文件守卫——表已存在时每请求 0 查询（首次检测后写入标记）
$cacheFile = __DIR__ . '/../../../protected/.user_profile_table_cache';
if (file_exists($cacheFile)) return;

try {
    $db = \app\Helpers\Plugin::db('user_profile');
    $db->exec(
        "CREATE TABLE IF NOT EXISTS user_follows (
            id INTEGER PRIMARY KEY,
            follower_id INTEGER NOT NULL,
            followed_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(follower_id, followed_id)
        )"
    );
    $db->exec('CREATE INDEX IF NOT EXISTS idx_user_follows_followed ON user_follows (followed_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_user_follows_follower ON user_follows (follower_id)');
    file_put_contents($cacheFile, '1', LOCK_EX);
} catch (\Throwable $e) {
    // 忽略，可能数据库未就绪
}