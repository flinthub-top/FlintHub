<?php
/**
 * FlintHub — 单页/链接管理插件主类
 * 生命周期（激活/停用/卸载）+ 数据访问（公开记录/按 slug 查询）
 * @file plugins/single_page/Plugin.php
 * @package Plugin\SinglePage
 * @version 1.0.0
 */

namespace Plugin\SinglePage;

class Plugin
{
    /**
     * 插件独立库连接（plugins/single_page/data/single_page.sqlite）
     */
    public static function db(): \PDO
    {
        return \app\Helpers\Plugin::db('single_page');
    }

    /**
     * 建表 DDL（幂等：CREATE TABLE IF NOT EXISTS）
     */
    public static function ddl(): string
    {
        return "CREATE TABLE IF NOT EXISTS single_pages (
            id INTEGER PRIMARY KEY,
            type TEXT NOT NULL DEFAULT 'page',
            title TEXT NOT NULL,
            content TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '',
            slug TEXT NOT NULL DEFAULT '',
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_public INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )";
    }

    /**
     * 激活插件：建表 + 部分唯一索引（幂等）
     * 仅约束非空 slug（站内单页唯一）；外部链接 slug 恒为空串，需允许多条并存。
     */
    public static function activate(): bool
    {
        \app\Helpers\Plugin::ensureSchema('single_page', self::ddl());
        // 先删旧全量唯一索引，再建部分唯一索引，保证激活时可修复历史索引
        self::db()->exec('DROP INDEX IF EXISTS idx_single_pages_slug');
        self::db()->exec(
            "CREATE UNIQUE INDEX idx_single_pages_slug ON single_pages(slug) WHERE slug <> ''"
        );
        return true;
    }

    /**
     * 禁用插件
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
        self::db()->exec('DROP TABLE IF EXISTS single_pages');
    }

    /** @var array|null 运行时缓存，同请求内避免重复查库 */
    private static $publicCache = null;

    /**
     * 获取全部公开记录（页脚显示用，按排序权重升序）
     */
    public static function getPublic(): array
    {
        if (self::$publicCache !== null) {
            return self::$publicCache;
        }
        try {
            self::$publicCache = self::db()->query(
                "SELECT * FROM single_pages WHERE is_public = 1 ORDER BY sort_order ASC, id ASC"
            )->fetchAll(\PDO::FETCH_ASSOC);
            return self::$publicCache;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 按 slug 查询单页（前台渲染用，含未公开记录供权限判断）
     */
    public static function getBySlug(string $slug): ?array
    {
        if ($slug === '') return null;
        try {
            $stmt = self::db()->prepare("SELECT * FROM single_pages WHERE slug = :slug LIMIT 1");
            $stmt->execute([':slug' => $slug]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * slug 是否已被占用（编辑时排除自身）
     */
    public static function slugExists(string $slug, int $excludeId = 0): bool
    {
        try {
            $stmt = self::db()->prepare(
                "SELECT id FROM single_pages WHERE slug = :slug AND id != :exclude LIMIT 1"
            );
            $stmt->execute([':slug' => $slug, ':exclude' => $excludeId]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 清空运行时缓存（后台增删改后调用，保证同请求内页脚输出取到最新数据）
     */
    public static function clearCache(): void
    {
        self::$publicCache = null;
    }
}
