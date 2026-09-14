<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 公告钩子 — 初始化后建表 + 迁移字段迁移标记
 * @file plugins/announcements/hook/init_after.php
 * @package Plugin\Announcements
 * @version 1.2.0
 */

if (\app\Helpers\Plugin::isActivated('announcements')) {
    \Plugin\Announcements\Plugin::activate();

    // [SplitDB] 迁移：url 列由新 DDL 直接创建（SQLite 独立库），旧库升级时幂等补齐，仅执行一次
    if (empty(\app\Helpers\Settings::get('_announcements_migrated', ''))) {
        try {
            $db = \app\Helpers\Plugin::db('announcements');
            $cols = [];
            foreach ($db->query('PRAGMA table_info(announcements)')->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                $cols[] = $c['name'];
            }
            if (!in_array('url', $cols, true)) {
                $db->exec('ALTER TABLE announcements ADD COLUMN url TEXT');
            }
            \app\Helpers\Settings::update('_announcements_migrated', '1');
        } catch (\Throwable $e) {
            \error_log('announcements migration error: ' . $e->getMessage());
        }
    }
}
