<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 邀请注册插件主类 — 激活/停用、邀请码验证
 * @file plugins/invite/Plugin.php
 * @package Plugin\Invite
 * @version 1.0.0
 */

namespace Plugin\Invite;

class Plugin
{
    /** [SplitDB] 插件独立库连接（plugins/invite/data/invite.sqlite） */
    public static function db(): \PDO
    {
        return \app\Helpers\Plugin::db('invite');
    }

    public static function activate(): bool
    {
        // [SplitDB] activate 建表（与 hook/init_after.php 一致，独立库 + SQLite DDL，幂等）
        $db = self::db();
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
        return true;
    }

    public static function deactivate(): bool
    {
        return true;
    }

    /**
     * 卸载插件：删除数据表（[SplitDB] 独立库）
     */
    public static function uninstall(): void
    {
        \app\Helpers\Plugin::db('invite')->exec('DROP TABLE IF EXISTS invites');
    }
}
