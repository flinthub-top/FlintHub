<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 友情链接钩子 — 初始化后建表 + 迁移字段迁移标记
 * @file plugins/friend_links/hook/init_after.php
 * @package Plugin\FriendLinks
 * @version 1.2.0
 */

if (\app\Helpers\Plugin::isActivated('friend_links')) {
    // 性能优化：缓存文件守卫——表已存在时每请求 0 查询（首次建表/迁移后写入标记）
    $cacheFile = __DIR__ . '/../../../protected/.friend_links_table_cache';
    if (file_exists($cacheFile)) return;

    try {
        \Plugin\FriendLinks\Plugin::activate();

        // [SplitDB] 迁移：status / applicant_id / applied_at 列由新 DDL 直接创建（SQLite 独立库），旧库升级时幂等补齐，仅执行一次
        if (empty(\app\Helpers\Settings::get('_friend_links_migrated', ''))) {
            try {
                $db = \app\Helpers\Plugin::db('friend_links');
                $cols = [];
                foreach ($db->query('PRAGMA table_info(friend_links)')->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                    $cols[] = $c['name'];
                }
                if (!in_array('status', $cols, true)) {
                    $db->exec("ALTER TABLE friend_links ADD COLUMN status TEXT NOT NULL DEFAULT 'approved'");
                }
                if (!in_array('applicant_id', $cols, true)) {
                    $db->exec('ALTER TABLE friend_links ADD COLUMN applicant_id INTEGER NOT NULL DEFAULT 0');
                }
                if (!in_array('applied_at', $cols, true)) {
                    $db->exec('ALTER TABLE friend_links ADD COLUMN applied_at TEXT');
                }
                \app\Helpers\Settings::update('_friend_links_migrated', '1');
            } catch (\Throwable $e) {
                \error_log('friend_links migration error: ' . $e->getMessage());
            }
        }

        file_put_contents($cacheFile, '1', LOCK_EX);
    } catch (\Throwable $e) {
        \error_log('friend_links init error: ' . $e->getMessage());
    }
}
