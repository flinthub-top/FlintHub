<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 勋章中心插件主类 — 激活/停用/卸载 + 勋章业务逻辑
 * @file plugins/medal/Plugin.php
 * @package Plugin\Medal
 * @version 1.0.0
 */

namespace Plugin\Medal;

class Plugin
{
    /** 佩戴上限 */
    public const WEAR_LIMIT = 3;

    /** 自动规则条件类型白名单 */
    public const CONDITION_TYPES = ['manual', 'thread_count', 'post_count', 'reg_days', 'points'];

    /** [SplitDB] 插件独立库连接（plugins/medal/data/medal.sqlite，三张勋章表） */
    public static function db(): \PDO
    {
        return \app\Helpers\Plugin::db('medal');
    }

    /**
     * 激活插件：创建数据表（[SplitDB] SQLite DDL，独立库，幂等）
     */
    public static function activate(): bool
    {
        $db = self::db();
        $db->exec("CREATE TABLE IF NOT EXISTS medals (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            icon TEXT NOT NULL DEFAULT 'award',
            color TEXT NOT NULL DEFAULT '#f59e0b',
            description TEXT NOT NULL DEFAULT '',
            condition_type TEXT NOT NULL DEFAULT 'manual',
            condition_value INTEGER NOT NULL DEFAULT 0,
            sort INTEGER NOT NULL DEFAULT 0,
            status INTEGER NOT NULL DEFAULT 1,
            image TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS user_medals (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            medal_id INTEGER NOT NULL,
            source TEXT NOT NULL DEFAULT 'auto',
            created_at TEXT NOT NULL,
            UNIQUE(user_id, medal_id)
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS user_medal_wears (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            medal_id INTEGER NOT NULL,
            wear_sort INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            UNIQUE(user_id, wear_sort),
            UNIQUE(user_id, medal_id)
        )");
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
        $db = self::db();
        $db->exec('DROP TABLE IF EXISTS user_medal_wears');
        $db->exec('DROP TABLE IF EXISTS user_medals');
        $db->exec('DROP TABLE IF EXISTS medals');
    }

    // ========== 勋章定义 ==========

    /**
     * 获取全部勋章（按排序/ID）
     */
    public static function getMedals(bool $onlyActive = false): array
    {
        $db = self::db();
        $where = $onlyActive ? 'WHERE status = 1' : '';
        return $db->query("SELECT * FROM medals {$where} ORDER BY sort ASC, id ASC")->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * 获取单个勋章
     */
    public static function getMedal(int $id): ?array
    {
        $db = self::db();
        $stmt = $db->prepare('SELECT * FROM medals WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * 新增勋章
     */
    public static function addMedal(array $data): int
    {
        $db = self::db();
        $db->prepare(
            'INSERT INTO medals (name, icon, color, description, condition_type, condition_value, sort, status, image, created_at)
             VALUES (:name, :icon, :color, :description, :condition_type, :condition_value, :sort, :status, :image, :now)'
        )->execute([
            ':name' => trim((string)($data['name'] ?? '')),
            ':icon' => trim((string)($data['icon'] ?? 'award')),
            ':color' => trim((string)($data['color'] ?? '#f59e0b')),
            ':description' => trim((string)($data['description'] ?? '')),
            ':condition_type' => in_array($data['condition_type'] ?? '', self::CONDITION_TYPES, true) ? $data['condition_type'] : 'manual',
            ':condition_value' => max(0, (int)($data['condition_value'] ?? 0)),
            ':sort' => (int)($data['sort'] ?? 0),
            ':status' => !empty($data['status']) ? 1 : 0,
            ':image' => trim((string)($data['image'] ?? '')),
            ':now' => date('Y-m-d H:i:s'),
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * 更新勋章
     */
    public static function updateMedal(int $id, array $data): void
    {
        $db = self::db();
        $db->prepare(
            'UPDATE medals SET name = :name, icon = :icon, color = :color, description = :description,
             condition_type = :condition_type, condition_value = :condition_value, sort = :sort,
             status = :status, image = :image WHERE id = :id'
        )->execute([
            ':name' => trim((string)($data['name'] ?? '')),
            ':icon' => trim((string)($data['icon'] ?? 'award')),
            ':color' => trim((string)($data['color'] ?? '#f59e0b')),
            ':description' => trim((string)($data['description'] ?? '')),
            ':condition_type' => in_array($data['condition_type'] ?? '', self::CONDITION_TYPES, true) ? $data['condition_type'] : 'manual',
            ':condition_value' => max(0, (int)($data['condition_value'] ?? 0)),
            ':sort' => (int)($data['sort'] ?? 0),
            ':status' => !empty($data['status']) ? 1 : 0,
            ':image' => trim((string)($data['image'] ?? '')),
            ':id' => $id,
        ]);
    }

    /**
     * 删除勋章（同时清空用户的持有与佩戴记录）
     */
    public static function deleteMedal(int $id): void
    {
        $db = self::db();
        $db->prepare('DELETE FROM user_medal_wears WHERE medal_id = :id')->execute([':id' => $id]);
        $db->prepare('DELETE FROM user_medals WHERE medal_id = :id')->execute([':id' => $id]);
        $db->prepare('DELETE FROM medals WHERE id = :id')->execute([':id' => $id]);
    }

    // ========== 用户勋章 ==========

    /**
     * 用户已获得的勋章 ID 集合
     */
    public static function getUserMedalIds(int $userId): array
    {
        $db = self::db();
        $stmt = $db->prepare('SELECT medal_id FROM user_medals WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map('intval', array_column($rows, 'medal_id'));
    }

    /**
     * 用户获得的勋章完整信息（含 medals 字段）
     */
    public static function getUserMedals(int $userId): array
    {
        $db = self::db();
        $stmt = $db->prepare(
            'SELECT m.*, um.source, um.created_at as obtained_at
             FROM user_medals um
             JOIN medals m ON um.medal_id = m.id
             WHERE um.user_id = :uid
             ORDER BY um.created_at DESC'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * 用户佩戴的勋章（含 medals 字段，按佩戴位排序）
     */
    public static function getWearingMedals(int $userId): array
    {
        $db = self::db();
        $stmt = $db->prepare(
            'SELECT m.*, w.wear_sort
             FROM user_medal_wears w
             JOIN medals m ON w.medal_id = m.id
             WHERE w.user_id = :uid AND m.status = 1
             ORDER BY w.wear_sort ASC'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * 颁发勋章（幂等：已有则跳过）
     */
    public static function grant(int $userId, int $medalId, string $source = 'auto'): bool
    {
        if ($userId <= 0 || $medalId <= 0) return false;
        $db = self::db();
        $stmt = $db->prepare('SELECT 1 FROM user_medals WHERE user_id = :uid AND medal_id = :mid');
        $stmt->execute([':uid' => $userId, ':mid' => $medalId]);
        if ($stmt->fetch(\PDO::FETCH_ASSOC)) return false;
        $db->prepare(
            'INSERT INTO user_medals (user_id, medal_id, source, created_at) VALUES (:uid, :mid, :src, :now)'
        )->execute([':uid' => $userId, ':mid' => $medalId, ':src' => $source === 'manual' ? 'manual' : 'auto', ':now' => date('Y-m-d H:i:s')]);
        return true;
    }

    /**
     * 回收勋章（同时移除佩戴）
     */
    public static function revoke(int $userId, int $medalId): void
    {
        $db = self::db();
        $db->prepare('DELETE FROM user_medal_wears WHERE user_id = :uid AND medal_id = :mid')->execute([':uid' => $userId, ':mid' => $medalId]);
        $db->prepare('DELETE FROM user_medals WHERE user_id = :uid AND medal_id = :mid')->execute([':uid' => $userId, ':mid' => $medalId]);
    }

    // ========== 佩戴 ==========

    /**
     * 设置佩戴（slots 为勋章 id 数组，最多 WEAR_LIMIT 个；传空数组清空佩戴）
     * 返回 [ok, msg]
     */
    public static function setWearing(int $userId, array $medalIds): array
    {
        if ($userId <= 0) return [false, '未登录'];
        $medalIds = array_values(array_unique(array_map('intval', $medalIds)));
        $medalIds = array_filter($medalIds, fn($id) => $id > 0);
        if (count($medalIds) > self::WEAR_LIMIT) {
            return [false, '最多佩戴 ' . self::WEAR_LIMIT . ' 枚勋章'];
        }

        $db = self::db(); // [SplitDB] 独立库（user_medal_wears）
        // 校验：只能佩戴自己已获得的勋章
        $owned = self::getUserMedalIds($userId);
        foreach ($medalIds as $mid) {
            if (!in_array($mid, $owned, true)) {
                return [false, '勋章不存在或尚未获得'];
            }
        }

        // 事务：清空旧佩戴 → 写入新佩戴（原生 PDO：beginTransaction/commit/rollBack）
        try {
            $db->beginTransaction();
            $db->prepare('DELETE FROM user_medal_wears WHERE user_id = :uid')->execute([':uid' => $userId]);
            foreach ($medalIds as $i => $mid) {
                $db->prepare(
                    'INSERT INTO user_medal_wears (user_id, medal_id, wear_sort, created_at) VALUES (:uid, :mid, :slot, :now)'
                )->execute([':uid' => $userId, ':mid' => $mid, ':slot' => $i + 1, ':now' => date('Y-m-d H:i:s')]);
            }
            $db->commit();
            return [true, '佩戴已更新'];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            \error_log('Medal setWearing error: ' . $e->getMessage());
            return [false, '佩戴更新失败'];
        }
    }

    // ========== 自动规则 ==========

    /**
     * 检查用户的自动规则勋章（惰性：满足且未获得则颁发）
     * 返回本次新获得的勋章数
     * 性能优化：固定 3 次查询（勋章列表 / 已获得 ID / 用户计数聚合），避免逐勋章查询
     */
    public static function checkAutoRules(int $userId): int
    {
        if ($userId <= 0) return 0;
        $pdb = self::db(); // [SplitDB] 独立库（medals/user_medals）
        $db = \app\Core\Database::getInstance(); // 核心库（threads/posts/users 计数）

        // 1. 自动规则勋章列表（独立库）
        $medals = $pdb->query(
            "SELECT id, condition_type, condition_value FROM medals WHERE status = 1 AND condition_type <> 'manual'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        if (empty($medals)) return 0;

        // 2. 用户已获得的勋章 ID（独立库）
        $stmt = $pdb->prepare('SELECT medal_id FROM user_medals WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        $owned = array_map('intval', array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'medal_id'));

        // 3. 用户计数（[SplitDB] 发帖/回帖走 main_index 分片，不再直查退役表 threads/posts；积分/注册时间读核心 users）
        $mi = \app\SplitDB\Schema::mainIndexDb();
        $stmt = $mi->prepare('SELECT COUNT(*) FROM topic_index WHERE uid = :uid AND status = 0 AND deleted_at IS NULL');
        $stmt->execute([':uid' => $userId]);
        $threadCount = (int)$stmt->fetchColumn();

        $stmt = $mi->prepare('SELECT COUNT(*) FROM reply_index WHERE uid = :uid AND status = 0');
        $stmt->execute([':uid' => $userId]);
        $postCount = (int)$stmt->fetchColumn();

        $u = $db->fetchOne('SELECT points, created_at FROM users WHERE id = :uid', [':uid' => $userId]);
        $points = (int)($u['points'] ?? 0);
        $regDays = 0;
        if (!empty($u['created_at'])) {
            $regDays = (int)floor((time() - strtotime($u['created_at'])) / 86400);
        }

        // 内存判断各勋章条件并颁发
        $granted = 0;
        foreach ($medals as $m) {
            $mid = (int)$m['id'];
            if (in_array($mid, $owned, true)) continue;
            $type = $m['condition_type'] ?? 'manual';
            $value = max(0, (int)($m['condition_value'] ?? 0));
            $ok = false;
            switch ($type) {
                case 'thread_count': $ok = $threadCount >= $value; break;
                case 'post_count':   $ok = $postCount >= $value; break;
                case 'reg_days':     $ok = $regDays >= $value; break;
                case 'points':       $ok = $points >= $value; break;
            }
            if ($ok && self::grant($userId, $mid, 'auto')) {
                $granted++;
            }
        }
        return $granted;
    }

    /**
     * 检查单个勋章条件是否满足
     */
    public static function checkCondition(int $userId, array $medal): bool
    {
        $type = $medal['condition_type'] ?? 'manual';
        $value = max(0, (int)($medal['condition_value'] ?? 0));
        $db = \app\Core\Database::getInstance();

        switch ($type) {
            case 'thread_count':
                // [SplitDB] 计数走 main_index 分片，不再直查退役表 threads
                $mi = \app\SplitDB\Schema::mainIndexDb();
                $stmt = $mi->prepare('SELECT COUNT(*) FROM topic_index WHERE uid = :uid AND status = 0 AND deleted_at IS NULL');
                $stmt->execute([':uid' => $userId]);
                return (int)$stmt->fetchColumn() >= $value;

            case 'post_count':
                // [SplitDB] 计数走 main_index 分片，不再直查退役表 posts
                $mi = \app\SplitDB\Schema::mainIndexDb();
                $stmt = $mi->prepare('SELECT COUNT(*) FROM reply_index WHERE uid = :uid AND status = 0');
                $stmt->execute([':uid' => $userId]);
                return (int)$stmt->fetchColumn() >= $value;

            case 'reg_days':
                $row = $db->fetchOne(
                    'SELECT created_at FROM users WHERE id = :uid',
                    [':uid' => $userId]
                );
                if (empty($row['created_at'])) return false;
                $days = (int)floor((time() - strtotime($row['created_at'])) / 86400);
                return $days >= $value;

            case 'points':
                $row = $db->fetchOne(
                    'SELECT points FROM users WHERE id = :uid',
                    [':uid' => $userId]
                );
                return (int)($row['points'] ?? 0) >= $value;

            default:
                return false;
        }
    }

    // ========== 渲染辅助 ==========

    /**
     * 条件说明文案（勋章墙展示"如何获得"）
     */
    public static function conditionLabel(array $medal): string
    {
        $type = $medal['condition_type'] ?? 'manual';
        $value = max(0, (int)($medal['condition_value'] ?? 0));
        switch ($type) {
            case 'thread_count': return '发帖数 ≥ ' . $value;
            case 'post_count':   return '回帖数 ≥ ' . $value;
            case 'reg_days':     return '注册满 ' . $value . ' 天';
            case 'points':       return '积分 ≥ ' . $value;
            default:             return '管理员颁发';
        }
    }

    /**
     * 渲染单枚勋章图标（内置图标 + CSS 勋章化；有 image 字段则用图片）
     */
    public static function renderMedal(array $medal, int $size = 18): string
    {
        $title = \htmlspecialchars((string)($medal['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        if (!empty($medal['image'])) {
            // 勋章图独立存放在 assets/medals/（不进 uploads，避免后台孤儿附件清理误删）
            $url = '/assets/medals/' . $medal['image'];
            // 图片按上传原尺寸显示（不裁剪成圆形/方形、不强制宽高，如 20x35 竖长条原样展示）
            return '<img src="' . \htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="' . $title . '" title="' . $title . '" class="mn-medal-img">';
        }
        $icon = (string)($medal['icon'] ?? 'award');
        $color = (string)($medal['color'] ?? '#f59e0b');
        // 内置图标经 TemplateCompiler::icon 输出 font-awesome unicode（无图兜底）
        $view = $GLOBALS['__view'] ?? null;
        $inner = $view ? $view->icon($icon, $size) : $icon;
        return '<span class="mn-medal" style="--mn-medal-color:' . \htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . ';" title="' . $title . '" data-medal-name="' . $title . '">' . $inner . '</span>';
    }

    /**
     * 渲染用户佩戴勋章组（空则返回 ''）
     */
    public static function renderWearing(int $userId, int $size = 18): string
    {
        if ($userId <= 0) return '';
        $wearing = self::getWearingMedals($userId);
        if (empty($wearing)) return '';
        $html = '';
        foreach ($wearing as $m) {
            $html .= self::renderMedal($m, $size);
        }
        return '<span class="mn-medal-group">' . $html . '</span>';
    }
}
