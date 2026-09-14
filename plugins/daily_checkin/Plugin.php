<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 每日签到插件主类 — 激活/停用、签到核心逻辑
 * @file plugins/daily_checkin/Plugin.php
 * @package Plugin\DailyCheckin
 * @version 1.0.0
 */

namespace Plugin\DailyCheckin;

class Plugin
{
    /** [SplitDB] 插件独立库连接（plugins/daily_checkin/data/daily_checkin.sqlite） */
    public static function db(): \PDO
    {
        return \app\Helpers\Plugin::db('daily_checkin');
    }

    /**
     * 激活插件：创建数据表（[SplitDB] SQLite DDL，独立库）
     */
    public static function activate(): bool
    {
        $ddl = "CREATE TABLE IF NOT EXISTS daily_checkin (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            checkin_date TEXT NOT NULL,
            points INTEGER NOT NULL DEFAULT 0,
            consecutive_days INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            UNIQUE(user_id, checkin_date)
        )";
        \app\Helpers\Plugin::ensureSchema('daily_checkin', $ddl);

        // 添加签到记录到 settings 表：连续签到基础奖励（settings 在核心库，属全局配置）
        try {
            $setting = new \app\Models\Setting();
            if (!$setting->getValue('checkin_points_base')) {
                $setting->setValue('checkin_points_base', '2');
            }
            if (!$setting->getValue('checkin_points_bonus')) {
                $setting->setValue('checkin_points_bonus', '1');
            }
        } catch (\Throwable $e) {
            // 忽略
        }

        return true;
    }

    /**
     * 禁用插件：清理钩子
     */
    public static function deactivate(): bool
    {
        return true;
    }

    /**
     * 卸载插件：删除数据表
     */
    public static function uninstall(): void
    {
        self::db()->exec('DROP TABLE IF EXISTS daily_checkin');
    }
}
