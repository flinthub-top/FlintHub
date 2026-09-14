<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 积分等级系统 — 积分增减、等级计算、等级配置管理
 * @file app/Helpers/Points.php
 * @package app\Helpers
 */

namespace app\Helpers;

class Points
{
    public static function getLevelConfig(int $level): array
    {
        static $configs = [];
        if (!isset($configs[$level])) {
            try {
                $db = \app\Core\Database::getInstance();
                $row = $db->fetchOne('SELECT * FROM level_config WHERE level = :l', [':l' => $level]);
                if ($row) { $configs[$level] = $row; }
            } catch (\Exception $e) {
                error_log('Points::getLevelConfig DB error: ' . $e->getMessage());
            }
        }
        if (isset($configs[$level])) return $configs[$level];

        $defaults = [
            1 => ['title' => '新手小白', 'color' => '#999', 'icon' => '🌰'],
            2 => ['title' => '初入论坛', 'color' => '#4caf50', 'icon' => '🌱'],
            3 => ['title' => '小有名气', 'color' => '#2196f3', 'icon' => '🌲'],
            4 => ['title' => '积极会员', 'color' => '#9c27b0', 'icon' => '🌳'],
            5 => ['title' => '认证粉丝', 'color' => '#ff9800', 'icon' => '🌴'],
            6 => ['title' => '社区明星', 'color' => '#f44336', 'icon' => '🌟'],
            7 => ['title' => '评论家', 'color' => '#e91e63', 'icon' => '🎯'],
            8 => ['title' => '社区助手', 'color' => '#673ab7', 'icon' => '🏆'],
            9 => ['title' => '版主级别', 'color' => '#3f51b5', 'icon' => '👑'],
            10 => ['title' => '社区顾问', 'color' => '#ff5722', 'icon' => '💡'],
        ];
        if ($level >= 10) $defaults[11] = ['title' => '社区元老', 'color' => '#ff6f00', 'icon' => '👑'];
        return $defaults[\min($level, 11)] ?? ['title' => '新手', 'color' => '#999', 'icon' => '🐣'];
    }

    public static function getAllConfigs(): array
    {
        try {
            $db = \app\Core\Database::getInstance();
            $rows = $db->fetchAll('SELECT * FROM level_config ORDER BY level ASC');
            if ($rows) return $rows;
        } catch (\Exception $e) {
            error_log('Points::getAllConfigs DB error: ' . $e->getMessage());
        }

        $defaults = [];
        for ($i = 1; $i <= 11; $i++) {
            $cfg = self::getLevelConfig($i);
            $defaults[] = [
                'level' => $i, 'title' => $cfg['title'],
                'required_points' => $i > 1 ? ($i - 1) * ($i - 1) * 10 : 0,
                'icon' => $cfg['icon'], 'color' => $cfg['color'],
            ];
        }
        return $defaults;
    }

    public static function calculateLevel(int $points): int
    {
        $points = \max(0, $points);
        $configs = self::getAllConfigs();
        $highest = 1;
        foreach ($configs as $cfg) {
            if ($points >= (int)$cfg['required_points'] && (int)$cfg['level'] > $highest) $highest = (int)$cfg['level'];
        }
        return $highest;
    }

    public static function award(int $userId, int $points, string $reason, int $relatedId = 0, string $relatedType = ''): void
    {
        if ($points <= 0) return;
        // 调用方（PostController 等）已管理事务，此处不再 begin/commit
        $db = \app\Core\Database::getInstance();
        try {
            $db->query('UPDATE users SET points = points + :p WHERE id = :id', [':p' => $points, ':id' => $userId]);
            $db->query('INSERT INTO points_log (user_id, points, reason, related_id, related_type, created_at) VALUES (:uid, :p, :reason, :rid, :rtype, :now)', [
                ':uid' => $userId, ':p' => $points, ':reason' => $reason,
                ':rid' => $relatedId, ':rtype' => $relatedType, ':now' => date('Y-m-d H:i:s'),
            ]);

            $user = $db->fetchOne('SELECT points, level FROM users WHERE id = :id', [':id' => $userId]);
            if ($user) {
                $newLevel = self::calculateLevel($user['points']);
                if ($newLevel > (int)($user['level'] ?? 0)) {
                    $db->query('UPDATE users SET level = :lvl WHERE id = :id', [':lvl' => $newLevel, ':id' => $userId]);
                    // 通知内置化：等级升级通知
                    $levelTitle = (string)(self::getLevelConfig($newLevel)['title'] ?? '');
                    $levelTitleText = $levelTitle !== '' ? '「' . $levelTitle . '」' : '';
                    \app\Helpers\Notification::add(
                        $userId,
                        \app\Helpers\Notification::TYPE_LEVEL_UP,
                        \app\Helpers\I18n::get('plugin.notifications.level_up', ['level' => (int)$newLevel, 'title' => $levelTitleText]),
                        '/profile',
                        '',
                        $levelTitle,
                        0,
                        ''
                    );
                }
            }

            // 通知钩子：积分增加后（在调用方事务内，随事务一致提交/回滚）
            \app\Helpers\Plugin::hook('points_award_after', [
                'user_id'      => $userId,
                'points'       => $points,
                'reason'       => $reason,
                'related_id'   => $relatedId,
                'related_type' => $relatedType,
            ]);

            // 通知内置化：积分增加通知（随外层事务一起提交/回滚）
            \app\Helpers\Notification::notifyPointsGain($userId, $points, $reason, $relatedId, $relatedType);
        } catch (\Exception $e) {
            \error_log('Points::award error: ' . $e->getMessage());
            throw $e; // 让调用方处理回滚
        }
    }

    public static function deduct(int $userId, int $points, string $reason, int $relatedId = 0, string $relatedType = ''): bool
    {
        if ($points <= 0) return false;
        $db = \app\Core\Database::getInstance();
        // 嵌套事务感知：调用方已在事务内（如回帖/发帖的 post_create_after 钩子）时，
        // 不自建事务、不自行 commit/rollback（由外层事务统一提交/回滚）；
        // 独立调用点（骰子/商城/对决等）无外层事务时仍自建事务，行为不变。
        $inOuterTxn = $db->getConnection()->inTransaction();
        try {
            if (!$inOuterTxn) {
                // SQLite 无 FOR UPDATE 行锁：改用条件 UPDATE（原子校验余额+扣减，等价防透支）
                $db->begin();
            }
            $stmt = $db->query(
                'UPDATE users SET points = points - :p WHERE id = :id AND points >= :p',
                [':p' => $points, ':id' => $userId]
            );
            if ($stmt->rowCount() === 0) {
                if (!$inOuterTxn) { $db->rollback(); }
                return false; // 余额不足
            }
            $db->query('INSERT INTO points_log (user_id, points, reason, related_id, related_type, created_at) VALUES (:uid, :p, :reason, :rid, :rtype, :now)', [
                ':uid' => $userId, ':p' => -$points, ':reason' => $reason,
                ':rid' => $relatedId, ':rtype' => $relatedType, ':now' => date('Y-m-d H:i:s'),
            ]);
            if (!$inOuterTxn) {
                $db->commit();
            }

            // 通知钩子：积分扣除后（已提交，通知独立写入）
            \app\Helpers\Plugin::hook('points_deduct_after', [
                'user_id'      => $userId,
                'points'       => -$points,
                'reason'       => $reason,
                'related_id'   => $relatedId,
                'related_type' => $relatedType,
            ]);

            // 通知内置化：积分扣除通知（已提交后独立写入；内容审核暂扣不打扰）
            \app\Helpers\Notification::notifyPointsDeduct($userId, $points, $reason, $relatedId, $relatedType);
            return true;
        } catch (\Exception $e) {
            // 独立事务：回滚自身；嵌套场景：不碰外层事务（由调用方 catch 统一回滚）
            if (!$inOuterTxn) {
                try { $db->rollback(); } catch (\Exception $ignored) {}
            }
            \error_log('Points::deduct error: ' . $e->getMessage());
            return false;
        }
    }

    /** @var array<string, bool> 请求级缓存：canViewContent 同请求内重复判定不重查（键 threadId:userId） */
    private static array $viewContentCache = [];

    public static function canViewContent(int $threadId, int $userId): bool
    {
        $key = $threadId . ':' . $userId;
        if (isset(self::$viewContentCache[$key])) return self::$viewContentCache[$key];
        $db = \app\Core\Database::getInstance();
        $row = $db->fetchOne('SELECT COUNT(*) as cnt FROM viewed_replies WHERE thread_id = :tid AND user_id = :uid',
            [':tid' => $threadId, ':uid' => $userId]);
        $result = $row && $row['cnt'] > 0;
        self::$viewContentCache[$key] = $result;
        return $result;
    }

    public static function getLevelColor(int $level): string
    {
        return self::getLevelConfig($level)['color'] ?? '#999';
    }

    public static function renderBadge(int $level): string
    {
        $level = \max(1, $level);
        $cfg = self::getLevelConfig($level);
        // 安全校验颜色值，防存储恶意 payload 导致 XSS
        $color = $cfg['color'] ?? '#999';
        if (!\preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $color)) {
            $color = '#999';
        }
        return '<span class="level-badge" style="background:' . $color . ';color:white;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:bold;">'
            . \htmlspecialchars($cfg['icon'], ENT_QUOTES, 'UTF-8') . ' Lv.' . $level . ' '
            . \htmlspecialchars($cfg['title'] ?? '新手', ENT_QUOTES, 'UTF-8') . '</span>';
    }

    public static function getSummary(int $userId): ?array
    {
        $db = \app\Core\Database::getInstance();
        $user = $db->fetchOne('SELECT points, level FROM users WHERE id = :id', [':id' => $userId]);
        if (!$user) return null;

        $level = self::calculateLevel($user['points']);
        $cfg = self::getLevelConfig($level);
        $configs = self::getAllConfigs();
        $nextLevelPoints = (int)($cfg['required_points'] ?? 0);
        $nextConfig = null;
        foreach ($configs as $c) {
            if ((int)$c['level'] > $level) { $nextConfig = $c; $nextLevelPoints = (int)$c['required_points']; break; }
        }
        $currentLevelPoints = (int)($cfg['required_points'] ?? 0);
        if (!$nextConfig) {
            $prevPoints = 0;
            foreach ($configs as $c2) if ((int)$c2['level'] < $level) $prevPoints = (int)$c2['required_points'];
            $currentLevelPoints = $prevPoints;
            $nextLevelPoints = $prevPoints + 1;
        }

        return [
            'points' => (int)$user['points'], 'level' => $level,
            'title' => $cfg['title'] ?? '新手', 'color' => $cfg['color'] ?? '#999', 'icon' => $cfg['icon'] ?? '',
            'next_level_points' => $nextLevelPoints, 'current_level_start' => $currentLevelPoints,
            'progress' => $nextLevelPoints > $currentLevelPoints
                ? \round((($user['points'] - $currentLevelPoints) / ($nextLevelPoints - $currentLevelPoints)) * 100)
                : 100,
        ];
    }

    public static function markReplied(int $threadId, int $userId): void
    {
        $db = \app\Core\Database::getInstance();
        $existing = $db->fetchOne('SELECT id FROM viewed_replies WHERE thread_id = :tid AND user_id = :uid',
            [':tid' => $threadId, ':uid' => $userId]);
        if (!$existing) {
            $db->query('INSERT INTO viewed_replies (thread_id, user_id, created_at) VALUES (:tid, :uid, :now)', [
                ':tid' => $threadId, ':uid' => $userId, ':now' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // ====== 后台等级管理 ======

    /**
     * 确保 level_config 表存在并初始化默认数据
     */
    public static function ensureTable(): void
    {
        $db = \app\Core\Database::getInstance();
        try {
            $db->fetchOne('SELECT COUNT(*) as cnt FROM level_config');
            return; // 表已存在
        } catch (\Exception $e) {
            // SQLite DDL（Schema::bootstrap 已建表，此处幂等兜底）
            $db->query("CREATE TABLE IF NOT EXISTS level_config (
                level INTEGER PRIMARY KEY, title TEXT NOT NULL, required_points INTEGER NOT NULL DEFAULT 0,
                icon TEXT NOT NULL DEFAULT '', color TEXT NOT NULL DEFAULT '#999'
            )");
        }
        $defaults = [
            [1,'新手小白',0,'🌰','#999'],[2,'初入论坛',10,'🌱','#4caf50'],[3,'小有名气',40,'🌲','#2196f3'],
            [4,'积极会员',90,'🌳','#9c27b0'],[5,'认证粉丝',160,'🌴','#ff9800'],[6,'社区明星',250,'🌟','#f44336'],
            [7,'评论家',360,'🎯','#e91e63'],[8,'社区助手',490,'🏆','#673ab7'],[9,'版主级别',640,'👑','#3f51b5'],
            [10,'社区顾问',810,'💡','#ff5722'],[11,'社区元老',1000,'👑','#ff6f00'],
        ];
        foreach ($defaults as $r) {
            $db->query('INSERT INTO level_config (level, title, required_points, icon, color) VALUES (:l,:t,:p,:i,:c)',
                [':l'=>$r[0],':t'=>$r[1],':p'=>$r[2],':i'=>$r[3],':c'=>$r[4]]);
        }
    }

    /**
     * 保存等级配置（新增或更新）
     */
    public static function saveLevelConfig(int $level, string $title, int $points, string $icon, string $color): void
    {
        $db = \app\Core\Database::getInstance();
        $existing = $db->fetchOne('SELECT level FROM level_config WHERE level = :l', [':l' => $level]);
        if ($existing) {
            $db->query('UPDATE level_config SET title=:t, required_points=:p, icon=:i, color=:c WHERE level=:l',
                [':l'=>$level,':t'=>$title,':p'=>$points,':i'=>$icon,':c'=>$color]);
        } else {
            $db->query('INSERT INTO level_config (level, title, required_points, icon, color) VALUES (:l,:t,:p,:i,:c)',
                [':l'=>$level,':t'=>$title,':p'=>$points,':i'=>$icon,':c'=>$color]);
        }
    }

    /**
     * 删除等级配置
     */
    public static function deleteLevelConfig(int $level): void
    {
        $db = \app\Core\Database::getInstance();
        $db->query('DELETE FROM level_config WHERE level = :l', [':l' => $level]);
    }
}
