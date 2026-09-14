<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 红包插件钩子 — 初始化后确保数据表存在（[SplitDB] 独立库幂等建表）
 * @file plugins/red_packet/hook/init_after.php
 * @package Plugin\RedPacket
 * @version 1.0.0
 */

try {
    $db = \app\Helpers\Plugin::db('red_packet');
    $db->exec("CREATE TABLE IF NOT EXISTS red_packets (
        id INTEGER PRIMARY KEY,
        creator_id INTEGER NOT NULL,
        total_points INTEGER NOT NULL DEFAULT 0,
        total_count INTEGER NOT NULL DEFAULT 0,
        remaining_points INTEGER NOT NULL DEFAULT 0,
        remaining_count INTEGER NOT NULL DEFAULT 0,
        expires_at TEXT NOT NULL,
        thread_id INTEGER,
        thread_title TEXT DEFAULT '',
        created_at TEXT NOT NULL
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_red_packets_creator ON red_packets (creator_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_red_packets_expires ON red_packets (expires_at)');
    // 老库补列：thread_title 快照（与 Plugin::activate() 一致，幂等）
    $rpCols = array_column($db->query('PRAGMA table_info(red_packets)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
    if (!in_array('thread_title', $rpCols, true)) {
        $db->exec("ALTER TABLE red_packets ADD COLUMN thread_title TEXT DEFAULT ''");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS red_packet_claims (
        id INTEGER PRIMARY KEY,
        packet_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        points INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        UNIQUE(packet_id, user_id)
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_red_packet_claims_user ON red_packet_claims (user_id)');
} catch (\Throwable $e) {
    \error_log('red_packet init error: ' . $e->getMessage());
}