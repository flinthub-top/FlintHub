<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 公告系统插件主类 — 公告 CRUD、样式配置（运行时缓存）
 * @file plugins/announcements/Plugin.php
 * @package Plugin\Announcements
 * @version 1.2.0
 */

namespace Plugin\Announcements;

class Plugin
{
    /** @var array|null 运行时缓存，同请求内避免重复查库 */
    private static $listCache = null;

    /** [SplitDB] 插件独立库连接（plugins/announcements/data/announcements.sqlite） */
    public static function db(): \PDO
    {
        return \app\Helpers\Plugin::db('announcements');
    }

    /**
     * 激活插件：创建数据表（[SplitDB] SQLite DDL，独立库）
     */
    public static function activate(): bool
    {
        $ddl = "CREATE TABLE IF NOT EXISTS announcements (
            id INTEGER PRIMARY KEY,
            content TEXT NOT NULL,
            url TEXT,
            style TEXT NOT NULL DEFAULT 'yellow',
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        )";
        \app\Helpers\Plugin::ensureSchema('announcements', $ddl);

        // 首次建表时插入欢迎公告
        $db = self::db();
        $n = (int)$db->query('SELECT COUNT(*) FROM announcements')->fetchColumn();
        if ($n === 0) {
            $db->prepare('INSERT INTO announcements (content, url, style, sort_order, created_at) VALUES (:c, :u, :s, :o, :n)')
               ->execute([':c' => '🎉 欢迎使用公告系统！可在后台添加多条公告，支持多种颜色样式。', ':u' => '', ':s' => 'yellow', ':o' => 0, ':n' => date('Y-m-d H:i:s')]);
        }
        return true;
    }

    public static function deactivate(): bool
    {
        return true;
    }

    public static function uninstall(): void
    {
        self::db()->exec('DROP TABLE IF EXISTS announcements');
    }

    /**
     * 获取所有公告（运行时缓存，同请求内不重复查库）
     */
    public static function getAll(): array
    {
        if (self::$listCache !== null) {
            return self::$listCache;
        }
        try {
            self::$listCache = self::db()->query('SELECT * FROM announcements ORDER BY sort_order ASC, id ASC')->fetchAll(\PDO::FETCH_ASSOC);
            return self::$listCache;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 获取单条公告
     */
    public static function get(int $id): ?array
    {
        try {
            $stmt = self::db()->prepare('SELECT * FROM announcements WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 添加公告
     */
    public static function add(string $content, string $url = '', string $style = 'yellow', int $sortOrder = 0): void
    {
        self::db()->prepare(
            'INSERT INTO announcements (content, url, style, sort_order, created_at) VALUES (:c, :u, :s, :o, :n)'
        )->execute([':c' => $content, ':u' => $url, ':s' => $style, ':o' => $sortOrder, ':n' => date('Y-m-d H:i:s')]);
        self::$listCache = null; // 清运行时缓存
    }

    /**
     * 编辑公告
     */
    public static function update(int $id, string $content, string $url, string $style, int $sortOrder): void
    {
        self::db()->prepare(
            'UPDATE announcements SET content = :c, url = :u, style = :s, sort_order = :o WHERE id = :id'
        )->execute([':c' => $content, ':u' => $url, ':s' => $style, ':o' => $sortOrder, ':id' => $id]);
        self::$listCache = null;
    }

    /**
     * 删除公告
     */
    public static function delete(int $id): void
    {
        self::db()->prepare('DELETE FROM announcements WHERE id = :id')->execute([':id' => $id]);
        self::$listCache = null;
    }

    /**
     * 样式列表
     */
    public static function getStyles(): array
    {
        return [
            'yellow'  => ['label' => '浅黄', 'bg' => '#fff8e1', 'border' => '#ffe082', 'color' => '#856404'],
            'red'     => ['label' => '浅红', 'bg' => '#ffebee', 'border' => '#ef9a9a', 'color' => '#b71c1c'],
            'gray'    => ['label' => '灰色', 'bg' => '#f5f5f5', 'border' => '#ccc',     'color' => '#555'],
            'bluegray'=> ['label' => '蓝灰', 'bg' => '#eceff1', 'border' => '#b0bec5', 'color' => '#37474f'],
            'blue'    => ['label' => '浅蓝', 'bg' => '#e3f2fd', 'border' => '#90caf9', 'color' => '#0d47a1'],
            'green'   => ['label' => '浅绿', 'bg' => '#e8f5e9', 'border' => '#a5d6a7', 'color' => '#1b5e20'],
        ];
    }
}
