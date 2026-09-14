<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 友情链接插件主类 — 激活/停用、数据表创建、申请/审核
 * @file plugins/friend_links/Plugin.php
 * @package Plugin\FriendLinks
 * @version 1.1.0
 */

namespace Plugin\FriendLinks;

class Plugin
{
    /** [SplitDB] 插件独立库连接（plugins/friend_links/data/friend_links.sqlite） */
    public static function db(): \PDO
    {
        return \app\Helpers\Plugin::db('friend_links');
    }

    /**
     * 激活插件：创建数据表（[SplitDB] SQLite DDL，独立库）
     */
    public static function activate(): bool
    {
        $ddl = "CREATE TABLE IF NOT EXISTS friend_links (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            url TEXT NOT NULL,
            description TEXT,
            logo TEXT,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_visible INTEGER NOT NULL DEFAULT 1,
            status TEXT NOT NULL DEFAULT 'approved',
            applicant_id INTEGER NOT NULL DEFAULT 0,
            applied_at TEXT,
            created_at TEXT NOT NULL
        )";
        \app\Helpers\Plugin::ensureSchema('friend_links', $ddl);
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
        self::db()->exec('DROP TABLE IF EXISTS friend_links');
    }

    /** @var array|null 运行时缓存，同请求内避免重复查库 */
    private static $linksCache = null;

    /**
     * 获取已审核通过的友情链接（前台展示用，运行时缓存）
     */
    public static function getLinks(): array
    {
        if (self::$linksCache !== null) {
            return self::$linksCache;
        }
        try {
            self::$linksCache = self::db()->query(
                "SELECT * FROM friend_links WHERE is_visible = 1 AND status = 'approved' ORDER BY sort_order ASC, id ASC"
            )->fetchAll(\PDO::FETCH_ASSOC);
            return self::$linksCache;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 获取待审核列表（[SplitDB] friend_links 在独立库，users 在核心库 → 分两次查询合并）
     */
    public static function getPending(): array
    {
        try {
            $rows = self::db()->query(
                "SELECT * FROM friend_links WHERE status = 'pending' ORDER BY applied_at ASC"
            )->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($rows)) return [];

            // 批量补申请人用户名（M-1/P2：经 User::batchGetNames 批量封装读核心库 users）
            $uids = array_unique(array_map(fn($r) => (int)$r['applicant_id'], $rows));
            $users = \app\Models\User::batchGetNames($uids);
            foreach ($rows as &$row) {
                $row['applicant_name'] = $users[(int)$row['applicant_id']] ?? '';
            }
            unset($row);
            return $rows;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 提交友情链接申请
     */
    public static function apply(string $name, string $url, string $description, string $logo, int $userId): bool
    {
        $now = date('Y-m-d H:i:s');
        self::db()->prepare(
            "INSERT INTO friend_links (name, url, description, logo, status, applicant_id, applied_at, created_at)
             VALUES (:name, :url, :desc, :logo, 'pending', :uid, :now, :now)"
        )->execute([
            ':name' => $name,
            ':url' => $url,
            ':desc' => $description,
            ':logo' => $logo,
            ':uid' => $userId,
            ':now' => $now,
        ]);
        self::$linksCache = null;
        return true;
    }

    /**
     * 通过审核
     */
    public static function approve(int $id): void
    {
        // 排在已有链接最后面
        $max = self::db()->query("SELECT MAX(sort_order) as m FROM friend_links WHERE status = 'approved'")->fetch(\PDO::FETCH_ASSOC);
        $nextSort = ($max['m'] ?? 0) + 1;
        self::db()->prepare(
            "UPDATE friend_links SET status = 'approved', is_visible = 1, sort_order = :sort WHERE id = :id"
        )->execute([':id' => $id, ':sort' => $nextSort]);
        self::$linksCache = null;
    }

    /**
     * 驳回审核
     */
    public static function reject(int $id): void
    {
        self::db()->prepare(
            "UPDATE friend_links SET status = 'rejected', is_visible = 0 WHERE id = :id"
        )->execute([':id' => $id]);
        self::$linksCache = null;
    }

    /**
     * 获取申请开关状态
     */
    public static function isApplyEnabled(): bool
    {
        return \app\Helpers\Settings::get('friend_links_apply_enabled', '1') === '1';
    }

    /**
     * 设置申请开关
     */
    public static function setApplyEnabled(bool $enabled): void
    {
        $settingModel = new \app\Models\Setting();
        $settingModel->setValue('friend_links_apply_enabled', $enabled ? '1' : '0');
        \app\Helpers\Settings::buildCache();
    }
}
