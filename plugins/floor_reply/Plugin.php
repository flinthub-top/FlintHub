<?php
/**
 * 楼中楼插件主类 — 独立库连接 / 建表 / 业务数据方法
 * 楼中楼 = 对帖子楼层（posts）的再回复，独立库存储，不占核心楼层数。
 * @file plugins/floor_reply/Plugin.php
 * @package Plugin\FloorReply
 * @version 1.0.0
 */

namespace Plugin\FloorReply;

class Plugin
{
    /** 插件独立库连接（plugins/floor_reply/data/floor_reply.sqlite，原生 PDO） */
    public static function db(): \PDO
    {
        return \app\Helpers\Plugin::db('floor_reply');
    }

    /** 建表 DDL（幂等；mention_user_id 记录本条回复 @ 的用户，0 = 无 @） */
    public static function ddl(): string
    {
        return "CREATE TABLE IF NOT EXISTS fr_replies (
            id INTEGER PRIMARY KEY,
            post_id INTEGER NOT NULL,
            thread_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            mention_user_id INTEGER NOT NULL DEFAULT 0,
            content TEXT NOT NULL,
            created_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_fr_post ON fr_replies (post_id, id);
        CREATE INDEX IF NOT EXISTS idx_fr_thread ON fr_replies (thread_id);";
    }

    /** 激活：幂等建表 */
    public static function activate(): bool
    {
        \app\Helpers\Plugin::ensureSchema('floor_reply', self::ddl());
        return true;
    }

    /** 禁用 */
    public static function deactivate(): bool
    {
        return true;
    }

    /** 卸载：删表 */
    public static function uninstall(): void
    {
        self::db()->exec('DROP TABLE IF EXISTS fr_replies');
    }

    // ============================================================
    // 业务数据方法
    // ============================================================

    /**
     * 批量统计多个楼层的楼中楼数量（controller_view_before 一次性注入用，避免每楼一次查询）
     * @param int[] $postIds
     * @return array<int,int> [post_id => count]
     */
    public static function getCountByPostIds(array $postIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $postIds)));
        $ids = array_filter($ids, fn($v) => $v > 0);
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = self::db()->prepare("SELECT post_id, COUNT(*) AS c FROM fr_replies WHERE post_id IN ({$ph}) GROUP BY post_id");
        $stmt->execute($ids);
        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['post_id']] = (int)$row['c'];
        }
        return $map;
    }

    /**
     * 取某楼层的楼中楼列表（时间正序，对话流；支持 limit/offset 分页）
     * 每条含 content_html（@ 已高亮的安全 HTML）与 username/avatar/mention_username。
     * @return array<int,array>
     */
    public static function getByPost(int $postId, int $limit = 10, int $offset = 0): array
    {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);
        $stmt = self::db()->prepare(
            'SELECT * FROM fr_replies WHERE post_id = :pid ORDER BY id ASC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute([':pid' => (int)$postId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!$rows) return [];

        $rows = self::decorate($rows);
        $nameMap = self::mentionNameMap($rows);
        foreach ($rows as &$r) {
            $r['content_html'] = self::renderMentions($r['content'], $nameMap);
        }
        unset($r);
        return $rows;
    }

    /** 某楼层的楼中楼总数（分页器用） */
    public static function countByPost(int $postId): int
    {
        $stmt = self::db()->prepare('SELECT COUNT(*) AS c FROM fr_replies WHERE post_id = :pid');
        $stmt->execute([':pid' => (int)$postId]);
        return (int)($stmt->fetch(\PDO::FETCH_ASSOC)['c'] ?? 0);
    }

    /** 新增楼中楼回复 */
    public static function addReply(int $postId, int $threadId, int $userId, string $content, int $mentionUserId): int
    {
        $stmt = self::db()->prepare(
            'INSERT INTO fr_replies (post_id, thread_id, user_id, mention_user_id, content, created_at)
             VALUES (:pid, :tid, :uid, :muid, :content, :created)'
        );
        $stmt->execute([
            ':pid' => (int)$postId,
            ':tid' => (int)$threadId,
            ':uid' => (int)$userId,
            ':muid' => (int)$mentionUserId,
            ':content' => $content,
            ':created' => date('Y-m-d H:i:s'),
        ]);
        return (int)self::db()->lastInsertId();
    }

    /** 删除楼中楼回复（权限由控制器校验） */
    public static function deleteReply(int $id): bool
    {
        $stmt = self::db()->prepare('DELETE FROM fr_replies WHERE id = :id');
        $stmt->execute([':id' => (int)$id]);
        return $stmt->rowCount() > 0;
    }

    /** 按 id 取单条楼中楼回复（删除前权限校验用） */
    public static function getReply(int $id): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM fr_replies WHERE id = :id');
        $stmt->execute([':id' => (int)$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        return $row ?: null;
    }

    /**
     * 解析内容中的 @用户名：返回 [exists => [用户名 => 用户id], missing => [用户名]]
     * 用户名匹配规则：@ 后跟 字母/数字/下划线/中文/连字符（与注册宽松规则对齐），再查库确认存在性。
     */
    public static function parseMentions(string $content): array
    {
        $exists = [];
        $missing = [];
        if ($content === '' || !preg_match_all('/@([\p{L}\p{N}_\-]+)/u', $content, $m)) {
            return ['exists' => $exists, 'missing' => $missing];
        }
        $names = array_values(array_unique($m[1]));
        foreach ($names as $name) {
            $user = self::findUserByName($name);
            if ($user) {
                $exists[$name] = (int)$user['id'];
            } else {
                $missing[] = $name;
            }
        }
        return ['exists' => $exists, 'missing' => $missing];
    }

    /**
     * 渲染 @ 高亮：内容中已被解析确认的用户名替换为个人主页链接（未确认的保持原样）。
     * 返回安全 HTML：先转义再替换（防止内容注入标签）。
     */
    public static function renderMentions(string $content, array $nameMap): string
    {
        if ($content === '' || empty($nameMap) || !preg_match('/@/u', $content)) {
            return htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
        }
        $escaped = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
        return preg_replace_callback('/@([\p{L}\p{N}_\-]+)/u', function ($m) use ($nameMap) {
            $name = $m[1];
            if (!isset($nameMap[$name])) {
                return $m[0]; // 未确认的用户名不处理
            }
            $uid = (int)$nameMap[$name];
            $bp = \defined('BASE_PATH') ? BASE_PATH : '';
            return '<a href="' . $bp . '/u/' . $uid . '" class="fr-mention">@' . $name . '</a>';
        }, $escaped);
    }

    // ============================================================
    // 私有辅助
    // ============================================================

    /** 收集列表所有回复内容中出现的 @用户名 → 用户id 映射（批量查库一次） */
    private static function mentionNameMap(array $rows): array
    {
        $names = [];
        foreach ($rows as $r) {
            if (preg_match_all('/@([\p{L}\p{N}_\-]+)/u', (string)$r['content'], $m)) {
                foreach ($m[1] as $n) {
                    if ($n !== '') $names[$n] = true;
                }
            }
        }
        if (!$names) return [];
        $list = array_keys($names);
        // M-1/P2：经 User::batchGetIdsByNames 批量封装读核心库（用户名 → id 反向查询），不直查 users 表
        return \app\Models\User::batchGetIdsByNames($list);
    }

    /** 列表 decorate：补作者用户名/头像、被@用户名（核心库只读，一次批量） */
    private static function decorate(array $rows): array
    {
        if (!$rows) return [];
        $uids = array_values(array_unique(array_map(fn($r) => (int)$r['user_id'], $rows)));
        $muids = array_values(array_unique(array_map(fn($r) => (int)$r['mention_user_id'], $rows)));
        $all = array_values(array_unique(array_merge($uids, array_filter($muids, fn($v) => $v > 0))));
        $userMap = self::userMap($all);

        foreach ($rows as &$r) {
            $uid = (int)$r['user_id'];
            $r['username'] = $userMap[$uid]['username'] ?? ('#' . $uid);
            $r['avatar'] = $userMap[$uid]['avatar'] ?? '';
            $muid = (int)$r['mention_user_id'];
            $r['mention_username'] = $muid > 0 ? ($userMap[$muid]['username'] ?? '') : '';
        }
        unset($r);
        return $rows;
    }

    /**
     * 核心库只读：批量查用户（id → username/avatar）——参考 qa 的 userMap 写法
     * @param int[] $userIds
     * @return array<int,array>
     */
    private static function userMap(array $userIds): array
    {
        $map = [];
        // M-1/P2：经 User::batchGetUsers 批量封装读核心库 users（含 username/avatar/level）
        foreach (\app\Models\User::batchGetUsers($userIds) as $id => $u) {
            $map[$id] = $u;
        }
        return $map;
    }

    /** 按用户名查用户（核心 Model 实例方法，禁止静态调用） */
    private static function findUserByName(string $username): ?array
    {
        try {
            return (new \app\Models\User())->findByUsername($username);
        } catch (\Throwable $e) {
            \error_log('floor_reply findUserByName error: ' . $e->getMessage());
            return null;
        }
    }
}
