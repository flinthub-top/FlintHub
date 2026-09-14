<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 权限管理 — 版块浏览/发帖/回复权限校验
 * @file app/Helpers/Permission.php
 * @package app\Helpers
 */

namespace app\Helpers;

/**
 * 权限检查核心类
 * 
 * 配合 user_groups + category_permissions 表使用
 * 管理员组（group_id=4）拥有全部权限，跳过所有检查
 */
class Permission
{
    /**
     * 获取所有用户组
     */
    public static function getGroups(): array
    {
        $db = \app\Core\Database::getInstance();
        $rows = $db->fetchAll('SELECT * FROM user_groups ORDER BY id ASC');
        return $rows ?: [];
    }

    /**
     * 获取单个用户组
     */
    public static function getGroup(int $groupId): ?array
    {
        $db = \app\Core\Database::getInstance();
        $row = $db->fetchOne('SELECT * FROM user_groups WHERE id = :id', [':id' => $groupId]);
        return $row ?: null;
    }

    /**
     * 检查用户是否有权浏览版块
     */
    public static function canView(int $categoryId, ?int $userId = null): bool
    {
        return self::check('can_view', $categoryId, $userId);
    }

    /**
     * 检查用户是否有权发帖
     */
    public static function canPost(int $categoryId, ?int $userId = null): bool
    {
        return self::check('can_post', $categoryId, $userId);
    }

    /**
     * 检查用户是否有权回复
     */
    public static function canReply(int $categoryId, ?int $userId = null): bool
    {
        return self::check('can_reply', $categoryId, $userId);
    }

    /**
     * 检查用户是否有权在该版块上传附件
     * 管理员组（id=4）在 check() 内部始终放行；列缺失时拒绝而非放行
     */
    public static function canAttach(int $categoryId, ?int $userId = null): bool
    {
        // 列缺失（异常库结构）时按拒绝处理，避免权限检查失效；正常库由 Schema 建表保证列存在
        if (!self::canAttachColumnExists()) return false;
        return self::check('can_attach', $categoryId, $userId);
    }

    /** @var bool|null 运行时缓存：can_attach 列是否存在 */
    private static ?bool $canAttachColumnCache = null;

    /**
     * 检查 category_permissions 表是否存在 can_attach 列（结果缓存到本次请求）
     * 用 SQLite 兼容的 PRAGMA table_info 检测
     */
    private static function canAttachColumnExists(): bool
    {
        if (self::$canAttachColumnCache !== null) return self::$canAttachColumnCache;
        try {
            $db = \app\Core\Database::getInstance();
            $rows = $db->fetchAll('PRAGMA table_info(category_permissions)');
            $cols = \array_column($rows, 'name');
            self::$canAttachColumnCache = \in_array('can_attach', $cols, true);
        } catch (\Throwable $e) {
            self::$canAttachColumnCache = false;
        }
        return self::$canAttachColumnCache;
    }

    /**
     * 幂等确保 can_attach 列存在（列已存在时静默忽略），供后台保存权限前调用
     */
    public static function ensureCanAttachColumn(): void
    {
        if (self::canAttachColumnExists()) return;
        try {
            $db = \app\Core\Database::getInstance();
            $db->query('ALTER TABLE category_permissions ADD COLUMN can_attach TINYINT(1) NOT NULL DEFAULT 1');
            self::$canAttachColumnCache = true;
        } catch (\Throwable $e) {
            // 列已存在或加列失败时静默忽略，canAttach 会按放行兜底
        }
    }

    /**
     * 获取用户有权查看的版块 ID 列表
     * 管理员返回全部，其他用户按权限表过滤
     */
    public static function getAuthorizedCategoryIds(?int $userId = null): array
    {
        $db = \app\Core\Database::getInstance();

        // 未登录用户视为 group_id = 0（游客）；已登录走请求级缓存取组（与 check() 共用）
        $groupId = self::groupIdOf($userId);

        // 管理员组全部放行
        if ($groupId === 4) {
            $rows = $db->fetchAll('SELECT id FROM categories');
            return array_map('intval', array_column($rows, 'id'));
        }

        // 整张权限表内存映射（请求级缓存，一次查询覆盖全部版块/组）
        $table = self::permTable();

        // 如果没有任何权限记录，返回所有版块（兼容旧版未配置权限的情况）
        if (empty($table)) {
            $all = $db->fetchAll('SELECT id FROM categories');
            return array_map('intval', array_column($all, 'id'));
        }

        // 查该组有 can_view 权限的版块
        $ids = [];
        foreach ($table as $cid => $groups) {
            if (isset($groups[$groupId]) && $groups[$groupId]['can_view'] === 1) {
                $ids[] = (int)$cid;
            }
        }

        // 游客（group_id=0）并且没有显式的游客权限记录 → 用 group=1 的权限作为回退
        if ($groupId === 0) {
            $fallbackIds = [];
            foreach ($table as $cid => $groups) {
                if (isset($groups[1]) && $groups[1]['can_view'] === 1) {
                    $fallbackIds[] = (int)$cid;
                }
            }
            if (!empty($fallbackIds)) return $fallbackIds;
            // 兜底：group=1 也无权限记录（权限体系已启用但默认组未配置）→ 最小权限：返回空
            return [];
        }

        // 该用户组在权限表中无任何记录（权限体系已启用但该组未配置）→ 最小权限：返回空
        // （权限表完全为空、权限功能未启用时的全放行兜底见上方逻辑）
        $groupHasPerm = false;
        foreach ($table as $groups) {
            if (isset($groups[$groupId])) {
                $groupHasPerm = true;
                break;
            }
        }
        if (!$groupHasPerm) {
            return [];
        }

        return $ids;
    }

    /** @var array 运行时权限缓存，同请求内避免重复查库 */
    private static array $permCache = [];

    /** @var array<int, int> 请求级缓存：user_id => group_id（避免同请求重复 SELECT users.group_id） */
    private static array $groupCache = [];

    /** @var array|null 请求级缓存：category_id => group_id => 权限行（一次拉取整张权限表，替代逐版块逐权限查库） */
    private static ?array $permTableCache = null;

    /**
     * 获取用户组 ID（请求级缓存：同一用户同一请求只查一次 users 表）
     * 游客（null / <=0）返回 0；<=0 的异常组号归一为默认组 1（与旧逻辑一致）
     */
    private static function groupIdOf(?int $userId): int
    {
        if ($userId === null || $userId <= 0) {
            return 0;
        }
        if (isset(self::$groupCache[$userId])) {
            return self::$groupCache[$userId];
        }
        $db = \app\Core\Database::getInstance();
        $row = $db->fetchOne('SELECT group_id FROM users WHERE id = :id', [':id' => $userId]);
        $gid = (int)($row['group_id'] ?? 0);
        if ($gid <= 0) {
            $gid = 1;
        }
        return self::$groupCache[$userId] = $gid;
    }

    /**
     * 加载整张权限表为内存映射（请求级缓存：一次查询覆盖全部版块/组）
     * @return array<int, array<int, array<string, int>>> category_id => group_id => [can_view/can_post/can_reply/can_attach]
     */
    private static function permTable(): array
    {
        if (self::$permTableCache !== null) {
            return self::$permTableCache;
        }
        $db = \app\Core\Database::getInstance();
        $rows = $db->fetchAll(
            'SELECT category_id, group_id, can_view, can_post, can_reply, can_attach FROM category_permissions'
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['category_id']][(int)$r['group_id']] = [
                'can_view'   => (int)$r['can_view'],
                'can_post'   => (int)$r['can_post'],
                'can_reply'  => (int)$r['can_reply'],
                'can_attach' => (int)$r['can_attach'],
            ];
        }
        return self::$permTableCache = $map;
    }

    private static function check(string $perm, int $categoryId, ?int $userId = null): bool
    {
        if ($categoryId <= 0) return false;

        // 运行时缓存
        $cacheKey = "{$perm}_{$categoryId}_" . ($userId ?? 0);
        if (isset(self::$permCache[$cacheKey])) {
            return self::$permCache[$cacheKey];
        }

        // 白名单：只允许这些字段，防 SQL 列名注入
        $allowed = ['can_view', 'can_post', 'can_reply', 'can_attach'];
        if (!in_array($perm, $allowed, true)) {
            return self::$permCache[$cacheKey] = false;
        }

        // 未登录用户视为 group_id = 0（游客）；已登录走请求级缓存取组
        $groupId = self::groupIdOf($userId);

        // 管理员组（id=4）全部放行
        if ($groupId === 4) return self::$permCache[$cacheKey] = true;

        // 整张权限表内存映射（请求级缓存，一次查询覆盖全部版块/组）
        $table = self::permTable();

        // 该组对该版块有明确记录则按记录
        if (isset($table[$categoryId][$groupId])) {
            return self::$permCache[$cacheKey] = ($table[$categoryId][$groupId][$perm] === 1);
        }

        // 游客（group_id=0）无记录时：查 group=1 对该版块是否有权限（版块级别回退）
        if ($groupId === 0 && isset($table[$categoryId][1])) {
            return self::$permCache[$cacheKey] = ($table[$categoryId][1][$perm] === 1);
        }

        // 无记录时：检查该版块是否已设置过权限（该版块完全无任何组配置 → 放行）
        $result = !isset($table[$categoryId]);
        return self::$permCache[$cacheKey] = $result;
    }

    /**
     * 检查用户是否有权回复指定主题（先查主题所在的版块）
     * @deprecated 无调用方（回复权限检查走 canReply），待确认后删除
     */
    public static function canReplyToThread(int $threadId, ?int $userId = null): bool
    {
        // 版块归属改读 main_index.topic_index
        $stmt = \app\SplitDB\Schema::mainIndexDb()->prepare('SELECT category_id FROM topic_index WHERE id = :id');
        $stmt->execute([':id' => $threadId]);
        $categoryId = (int)$stmt->fetchColumn();
        if ($categoryId <= 0) return false;
        return self::canReply($categoryId, $userId);
    }

    /**
     * 新增/保存用户组
     */
    public static function saveGroup(int $id, string $name, string $color, int $isDefault): void
    {
        // 内置组（1~4）不允许设为默认组
        if ($id > 0 && $id <= 4) {
            $isDefault = 0;
        }
        $db = \app\Core\Database::getInstance();
        $existing = $db->fetchOne('SELECT id FROM user_groups WHERE id = :id', [':id' => $id]);
        if ($existing) {
            $db->query('UPDATE user_groups SET name = :n, color = :c, is_default = :d WHERE id = :id',
                [':n' => $name, ':c' => $color, ':d' => $isDefault, ':id' => $id]);
        } else {
            $db->query('INSERT INTO user_groups (name, color, is_default) VALUES (:n, :c, :d)',
                [':n' => $name, ':c' => $color, ':d' => $isDefault]);
        }
    }

    /**
     * 删除用户组（非内置组）
     */
    public static function deleteGroup(int $id): void
    {
        if ($id <= 4) return; // 内置组不可删除
        $db = \app\Core\Database::getInstance();
        $db->begin();
        try {
            // 将属于该组的用户重置到默认组
            $defaultGroup = $db->fetchOne("SELECT id FROM user_groups WHERE is_default = 1");
            $defaultId = (int)($defaultGroup['id'] ?? 1);
            $db->query('UPDATE users SET group_id = :defaultId WHERE group_id = :id',
                [':defaultId' => $defaultId, ':id' => $id]);
            $db->query('DELETE FROM category_permissions WHERE group_id = :id', [':id' => $id]);
            $db->query('DELETE FROM user_groups WHERE id = :id', [':id' => $id]);
            $db->commit();
        } catch (\Exception $e) {
            $db->rollback();
            throw $e;
        }
    }

    /**
     * 保存版块权限设置
     */
    public static function saveCategoryPermission(int $categoryId, int $groupId, int $canView, int $canPost, int $canReply, int $canAttach = 1): void
    {
        $db = \app\Core\Database::getInstance();
        $db->query(
            'INSERT INTO category_permissions (category_id, group_id, can_view, can_post, can_reply, can_attach)
             VALUES (:cid, :gid, :v, :p, :r, :a)
             ON CONFLICT(category_id, group_id) DO UPDATE SET can_view = :v2, can_post = :p2, can_reply = :r2, can_attach = :a2',
            [
                ':cid' => $categoryId, ':gid' => $groupId,
                ':v' => $canView, ':p' => $canPost, ':r' => $canReply, ':a' => $canAttach,
                ':v2' => $canView, ':p2' => $canPost, ':r2' => $canReply, ':a2' => $canAttach,
            ]
        );
    }

    /**
     * 获取版块权限设置
     */
    public static function getCategoryPermissions(int $categoryId): array
    {
        $db = \app\Core\Database::getInstance();
        $rows = $db->fetchAll(
            'SELECT * FROM category_permissions WHERE category_id = :cid',
            [':cid' => $categoryId]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['group_id']] = $row;
        }
        return $result;
    }
}
