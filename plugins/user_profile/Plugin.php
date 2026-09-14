<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 用户主页插件主类 — 激活/停用/卸载 + 关注功能
 * @file plugins/user_profile/Plugin.php
 * @package Plugin\UserProfile
 * @version 1.1.0
 */

namespace Plugin\UserProfile;

class Plugin
{
    /** [SplitDB] 插件独立库连接（plugins/user_profile/data/user_profile.sqlite，user_follows 表） */
    public static function db(): \PDO
    {
        return \app\Helpers\Plugin::db('user_profile');
    }

    /**
     * 激活插件：创建数据表（[SplitDB] SQLite DDL，独立库）
     */
    public static function activate(): bool
    {
        $ddl = "CREATE TABLE IF NOT EXISTS user_follows (
            id INTEGER PRIMARY KEY,
            follower_id INTEGER NOT NULL,
            followed_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(follower_id, followed_id)
        )";
        \app\Helpers\Plugin::ensureSchema('user_profile', $ddl);
        self::db()->exec('CREATE INDEX IF NOT EXISTS idx_user_follows_followed ON user_follows (followed_id)');
        self::db()->exec('CREATE INDEX IF NOT EXISTS idx_user_follows_follower ON user_follows (follower_id)');
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
     * 卸载插件
     */
    public static function uninstall(): void
    {
        self::db()->exec('DROP TABLE IF EXISTS user_follows');
    }

    /**
     * 根据用户ID获取用户资料（核心库 users，只读）
     */
    public static function getUser(int $userId): ?array
    {
        $db = \app\Core\Database::getInstance();
        return $db->fetchOne(
            'SELECT id, username, email, avatar, signature, role, group_id, level, points, post_count, status, created_at, email_verified FROM users WHERE id = :id',
            [':id' => $userId]
        );
    }

    /**
     * 获取用户最近的帖子（[SplitDB] 读 main_index 主索引库，business.sqlite 的 threads 表已退役）
     */
    public static function getThreads(int $userId, int $limit = 10): array
    {
        try {
            $mi = \app\SplitDB\Schema::mainIndexDb();
            $stmt = $mi->prepare(
                'SELECT * FROM topic_index WHERE uid = :uid AND status = 0 AND deleted_at IS NULL
                 ORDER BY create_time DESC, id DESC LIMIT ' . (int)$limit
            );
            $stmt->execute([':uid' => (int)$userId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($rows)) return [];

            // 批量补分类名（核心库 categories，只读）
            $cids = array_values(array_unique(array_map(fn($r) => (int)$r['category_id'], $rows)));
            $cats = [];
            if ($cids) {
                $marks = implode(',', array_map('intval', $cids));
                foreach (\app\Core\Database::getInstance()->fetchAll("SELECT id, name FROM categories WHERE id IN ({$marks})") as $c) {
                    $cats[(int)$c['id']] = $c['name'];
                }
            }

            $result = [];
            foreach ($rows as $r) {
                $result[] = [
                    'id'            => (int)$r['id'],
                    'category_id'   => (int)$r['category_id'],
                    'category_name' => $cats[(int)$r['category_id']] ?? '',
                    'title'         => $r['title'],
                    'view_count'    => (int)$r['view_count'],
                    'reply_count'   => (int)$r['reply_count'],
                    'created_at'    => $r['create_time'] ? date('Y-m-d H:i:s', (int)$r['create_time']) : null,
                ];
            }
            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 获取用户最近的博客（[SplitDB] 经 Blog Model 读核心库 blogs，不直接调 Database::getInstance()）
     */
    public static function getBlogs(int $userId, int $limit = 10): array
    {
        $rows = (new \app\Models\Blog())->query(
            'SELECT b.*, bc.name as category_name
             FROM blogs b
             LEFT JOIN blog_categories bc ON b.category_id = bc.id
             WHERE b.user_id = :uid
             ORDER BY b.created_at DESC
             LIMIT ' . (int)$limit,
            [':uid' => $userId]
        );
        return $rows ?: [];
    }

    /**
     * 获取用户统计信息（[SplitDB] 帖子/回复/浏览量读 main_index + reply_index，博客读核心库 blogs）
     */
    public static function getStats(int $userId): array
    {
        try {
            $mi = \app\SplitDB\Schema::mainIndexDb();
            $stmt = $mi->prepare(
                'SELECT COUNT(*) as total_threads,
                        COALESCE(SUM(view_count), 0) as total_views
                 FROM topic_index WHERE uid = :uid AND status = 0 AND deleted_at IS NULL'
            );
            $stmt->execute([':uid' => (int)$userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            $stmt = $mi->prepare(
                'SELECT COUNT(*) as total_replies FROM reply_index WHERE uid = :uid AND status = 0'
            );
            $stmt->execute([':uid' => (int)$userId]);
            $row2 = $stmt->fetch(\PDO::FETCH_ASSOC);

            $blogs = (new \app\Models\Blog())->queryOne(
                'SELECT COUNT(*) as cnt FROM blogs WHERE user_id = :uid',
                [':uid' => (int)$userId]
            );

            return [
                'total_threads' => (int)($row['total_threads'] ?? 0),
                'total_replies' => (int)($row2['total_replies'] ?? 0),
                'total_views'   => (int)($row['total_views'] ?? 0),
                'total_blogs'   => (int)($blogs['cnt'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return ['total_threads' => 0, 'total_replies' => 0, 'total_views' => 0, 'total_blogs' => 0];
        }
    }

    // ========== 关注功能（[SplitDB] user_follows 独立库） ==========

    /**
     * 关注用户
     */
    public static function follow(int $followerId, int $followedId): bool
    {
        if ($followerId === $followedId) return false;
        try {
            self::db()->prepare('INSERT INTO user_follows (follower_id, followed_id, created_at) VALUES (:fid, :foid, :now)')
               ->execute([':fid' => $followerId, ':foid' => $followedId, ':now' => date('Y-m-d H:i:s')]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 取消关注
     */
    public static function unfollow(int $followerId, int $followedId): bool
    {
        self::db()->prepare('DELETE FROM user_follows WHERE follower_id = :fid AND followed_id = :foid')
           ->execute([':fid' => $followerId, ':foid' => $followedId]);
        return true;
    }

    /**
     * 是否已关注
     */
    public static function isFollowing(int $followerId, int $followedId): bool
    {
        $stmt = self::db()->prepare('SELECT 1 FROM user_follows WHERE follower_id = :fid AND followed_id = :foid');
        $stmt->execute([':fid' => $followerId, ':foid' => $followedId]);
        return (bool)$stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * 获取粉丝数 + 关注数
     * try/catch 兜底：独立库 user_follows 表缺失时前台不 500（新装站点未手动启用插件时表未建）
     */
    public static function getFollowCounts(int $userId): array
    {
        try {
            $db = self::db();
            $row = $db->query(
                "SELECT
                    (SELECT COUNT(*) FROM user_follows WHERE followed_id = " . (int)$userId . ") as followers,
                    (SELECT COUNT(*) FROM user_follows WHERE follower_id = " . (int)$userId . ") as following"
            )->fetch(\PDO::FETCH_ASSOC);
            return [
                'followers' => (int)($row['followers'] ?? 0),
                'following' => (int)($row['following'] ?? 0),
            ];
        } catch (\Throwable $e) {
            // 独立库/表暂不可用（如未建表）→ 返回零值，绝不 500
            return ['followers' => 0, 'following' => 0];
        }
    }

    /**
     * 获取粉丝列表（[SplitDB] 独立库查 user_follows + 核心库批量补用户信息）
     */
    public static function getFollowers(int $userId, int $limit = 10): array
    {
        try {
            $stmt = self::db()->prepare('SELECT follower_id FROM user_follows WHERE followed_id = :uid ORDER BY id DESC LIMIT :limit');
            $stmt->bindValue(':uid', $userId, \PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $ids = array_map(fn($r) => (int)$r['follower_id'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
            return $this->fetchUsersByIds($ids);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 获取关注列表（[SplitDB] 独立库查 user_follows + 核心库批量补用户信息）
     */
    public static function getFollowing(int $userId, int $limit = 10): array
    {
        try {
            $stmt = self::db()->prepare('SELECT followed_id FROM user_follows WHERE follower_id = :uid ORDER BY id DESC LIMIT :limit');
            $stmt->bindValue(':uid', $userId, \PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $ids = array_map(fn($r) => (int)$r['followed_id'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
            return $this->fetchUsersByIds($ids);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 按 ID 批量取用户资料（核心库 users，只读；M-1/P2：经 User::batchGetUsers 批量封装）
     */
    private static function fetchUsersByIds(array $ids): array
    {
        $rows = [];
        foreach (\app\Models\User::batchGetUsers($ids) as $id => $u) {
            $rows[] = [
                'id'       => $id,
                'username' => $u['username'],
                'avatar'   => $u['avatar'],
                'level'    => (int)$u['level'],
            ];
        }
        return $rows;
    }
}