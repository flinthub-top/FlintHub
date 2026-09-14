<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 帖子收藏插件主类 — 激活/停用、收藏 CRUD、数据查询
 * @file plugins/post_favorite/Plugin.php
 * @package Plugin\PostFavorite
 * @version 1.0.0
 */

namespace Plugin\PostFavorite;

class Plugin
{
    /** [SplitDB] 插件独立库连接（plugins/post_favorite/data/post_favorite.sqlite） */
    public static function db(): \PDO
    {
        return \app\Helpers\Plugin::db('post_favorite');
    }

    /**
     * 激活插件：创建数据表（[SplitDB] SQLite DDL，独立库）
     */
    public static function activate(): bool
    {
        $ddl = "CREATE TABLE IF NOT EXISTS post_favorites (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            thread_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(user_id, thread_id)
        )";
        \app\Helpers\Plugin::ensureSchema('post_favorite', $ddl);
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
        self::db()->exec('DROP TABLE IF EXISTS post_favorites');
    }

    /**
     * 检查用户是否已收藏某帖子
     */
    public static function isFavorited(int $userId, int $threadId): bool
    {
        try {
            $stmt = self::db()->prepare(
                'SELECT id FROM post_favorites WHERE user_id = :uid AND thread_id = :tid'
            );
            $stmt->execute([':uid' => $userId, ':tid' => $threadId]);
            return !empty($stmt->fetch(\PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 获取用户收藏的所有帖子ID
     */
    public static function getUserFavoriteIds(int $userId): array
    {
        try {
            $stmt = self::db()->prepare('SELECT thread_id FROM post_favorites WHERE user_id = :uid ORDER BY id DESC');
            $stmt->execute([':uid' => $userId]);
            return array_map(fn($r) => (int)$r['thread_id'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 获取用户收藏总数
     */
    public static function getFavoriteCount(int $userId): int
    {
        try {
            $stmt = self::db()->prepare('SELECT COUNT(*) as cnt FROM post_favorites WHERE user_id = :uid');
            $stmt->execute([':uid' => $userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return (int)($row['cnt'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 切换收藏状态（收藏/取消）
     */
    public static function toggle(int $userId, int $threadId): array
    {
        $db = self::db();

        $stmt = $db->prepare('SELECT id FROM post_favorites WHERE user_id = :uid AND thread_id = :tid');
        $stmt->execute([':uid' => $userId, ':tid' => $threadId]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($existing) {
            $db->prepare('DELETE FROM post_favorites WHERE id = :id')->execute([':id' => $existing['id']]);
            return ['favorited' => false, 'message' => \app\Helpers\I18n::get('plugin.post_favorite.unfavorited')];
        } else {
            $db->prepare('INSERT INTO post_favorites (user_id, thread_id, created_at) VALUES (:uid, :tid, :now)')
               ->execute([':uid' => $userId, ':tid' => $threadId, ':now' => date('Y-m-d H:i:s')]);
            return ['favorited' => true, 'message' => \app\Helpers\I18n::get('plugin.post_favorite.favorited')];
        }
    }
}
