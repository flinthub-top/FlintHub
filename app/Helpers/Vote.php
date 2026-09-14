<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 投票系统 — 赞/踩切换、投票统计、按钮渲染
 * @file app/Helpers/Vote.php
 * @package app\Helpers
 */

namespace app\Helpers;

class Vote
{
    /** @var array<string, array{table:string, idCol:string, targetTable:string}> 白名单映射 */
    private static array $typeMap = [
        'thread' => ['table' => 'thread_votes', 'idCol' => 'thread_id', 'targetTable' => 'threads'],
        'post'   => ['table' => 'post_votes',   'idCol' => 'post_id',   'targetTable' => 'posts'],
    ];

    /**
     * 根据类型获取映射配置（白名单校验），类型非法时返回 null
     */
    private static function getTypeConfig(string $type): ?array
    {
        return self::$typeMap[$type] ?? null;
    }

    /**
     * 获取用户投票状态
     * @param string $type 'thread'|'post'
     * @param int $id
     * @return int 0=未投票, 1=点赞, -1=踩
     */
    public static function getUserVote(string $type, int $id): int
    {
        if (!Auth::isLoggedIn()) return 0;
        $cfg = self::getTypeConfig($type);
        if ($cfg === null) return 0;
        $db = \app\Core\Database::getInstance();
        $r = $db->fetchOne("SELECT vote FROM {$cfg['table']} WHERE {$cfg['idCol']} = :id AND user_id = :uid",
            [':id' => $id, ':uid' => $_SESSION['user_id']]);
        return (int)($r['vote'] ?? 0);
    }

    /**
     * 批量获取投票统计
     */
    public static function getCounts(string $type, array $ids): array
    {
        $cfg = self::getTypeConfig($type);
        if ($cfg === null) return [];

        $ids = \array_values(\array_filter(\array_map('intval', (array)$ids)));
        if (empty($ids)) return [];

        $db = \app\Core\Database::getInstance();
        $table = $cfg['table'];
        $idCol = $cfg['idCol'];
        $placeholders = \implode(',', array_fill(0, count($ids), '?'));

        $userVotes = [];
        if (Auth::isLoggedIn()) {
            $rows = $db->fetchAll("SELECT {$idCol} as id, vote FROM {$table} WHERE {$idCol} IN ({$placeholders}) AND user_id = ?",
                array_merge($ids, [$_SESSION['user_id']]));
            foreach ($rows as $row) $userVotes[(int)$row['id']] = (int)$row['vote'];
        }

        $counts = $db->fetchAll(
            "SELECT {$idCol} as id, SUM(CASE WHEN vote > 0 THEN 1 ELSE 0 END) as likes FROM {$table} WHERE {$idCol} IN ({$placeholders}) GROUP BY {$idCol}",
            $ids
        );

        $result = [];
        foreach ($ids as $id) $result[$id] = ['likes' => 0, 'user_vote' => $userVotes[$id] ?? 0];
        foreach ($counts as $row) {
            $id = (int)$row['id'];
            $result[$id] = ['likes' => (int)($row['likes'] ?? 0), 'user_vote' => $userVotes[$id] ?? 0];
        }

        return $result;
    }

    /**
     * 批量获取多类型投票统计（UNION ALL 跨表合并，减少详情页查询次数：N 类型 2N 次 → 2 次）
     *
     * 与 getCounts 同构返回：['post' => [id => ['likes','user_vote']], 'thread' => [...]]。
     * 仅当类型 id 列表非空才并入查询分支；类型不在白名单内则忽略（防注入，table/idCol 均取自白名单映射）。
     *
     * @param array<string, int[]> $types 类型 → id 列表，如 ['post' => [..], 'thread' => [tid]]
     */
    public static function getCountsMulti(array $types): array
    {
        $result = [];
        $branches = []; // 非空类型分支
        foreach ($types as $type => $ids) {
            $cfg = self::getTypeConfig($type);
            if ($cfg === null) continue;
            $ids = \array_values(\array_filter(\array_map('intval', (array)$ids)));
            if (empty($ids)) continue;
            $branches[$type] = ['cfg' => $cfg, 'ids' => $ids];
            foreach ($ids as $id) $result[$type][(int)$id] = ['likes' => 0, 'user_vote' => 0];
        }
        if (empty($branches)) return $result;

        $db = \app\Core\Database::getInstance();

        // ① 用户投票（登录时）：跨表 UNION ALL 合并为 1 次查询
        if (Auth::isLoggedIn()) {
            $parts = [];
            $params = [];
            foreach ($branches as $type => $b) {
                $cfg = $b['cfg'];
                $ph = \implode(',', array_fill(0, count($b['ids']), '?'));
                $parts[] = "SELECT '{$type}' AS t, {$cfg['idCol']} AS id, vote FROM {$cfg['table']} WHERE {$cfg['idCol']} IN ({$ph}) AND user_id = ?";
                $params = \array_merge($params, $b['ids'], [$_SESSION['user_id']]);
            }
            $rows = $db->fetchAll(\implode(' UNION ALL ', $parts), $params);
            foreach ($rows as $row) {
                $t = (string)$row['t'];
                if (isset($result[$t][(int)$row['id']])) $result[$t][(int)$row['id']]['user_vote'] = (int)$row['vote'];
            }
        }

        // ② 统计：跨表 UNION ALL 合并为 1 次查询
        $parts = [];
        $params = [];
        foreach ($branches as $type => $b) {
            $cfg = $b['cfg'];
            $ph = \implode(',', array_fill(0, count($b['ids']), '?'));
            $parts[] = "SELECT '{$type}' AS t, {$cfg['idCol']} AS id, "
                . 'SUM(CASE WHEN vote > 0 THEN 1 ELSE 0 END) AS likes '
                . "FROM {$cfg['table']} WHERE {$cfg['idCol']} IN ({$ph}) GROUP BY {$cfg['idCol']}";
            $params = \array_merge($params, $b['ids']);
        }
        $rows = $db->fetchAll(\implode(' UNION ALL ', $parts), $params);
        foreach ($rows as $row) {
            $t = (string)$row['t'];
            if (isset($result[$t][(int)$row['id']])) {
                $result[$t][(int)$row['id']]['likes'] = (int)($row['likes'] ?? 0);
            }
        }

        return $result;
    }

    /**
     * 切换点赞/踩
     */
    public static function toggle(string $type, int $id, int $vote): array
    {
        if (!Auth::isLoggedIn()) return ['success' => false, 'error' => '请先登录'];

        // 纯点赞优化：仅支持赞(1)/取消(0)，拒绝踩(-1)
        if ($vote !== 1 && $vote !== 0) return ['success' => false, 'error' => '无效的投票类型'];

        $cfg = self::getTypeConfig($type);
        if ($cfg === null) return ['success' => false, 'error' => '无效的投票类型'];

        $db = \app\Core\Database::getInstance();
        $table = $cfg['table'];
        $idCol = $cfg['idCol'];

        // 目标存在性校验改读 main_index（topic_index / reply_index）
        $mi = \app\SplitDB\Schema::mainIndexDb();
        $targetTable = $cfg['idCol'] === 'thread_id' ? 'topic_index' : 'reply_index';
        $stmt = $mi->prepare("SELECT id FROM {$targetTable} WHERE id = :id");
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetchColumn()) {
            return ['success' => false, 'error' => '目标不存在'];
        }

        // 记录本次更新前的用户投票状态，判定「是否由非赞态首次变为赞态」——
        // 仅在 0/-1 → +1 的真实状态迁移时才对作者发积分（防重复点亮循环刷分）
        $prevVote = self::getUserVote($type, $id);
        $isNewLike = ($vote === 1 && $prevVote !== 1);

        try {
            if ($vote === 0) {
                $db->query("DELETE FROM {$table} WHERE {$idCol} = :id AND user_id = :uid",
                    [':id' => $id, ':uid' => $_SESSION['user_id']]);
            } else {
                $conn = $db->getConnection();
                $now = \date('Y-m-d H:i:s');
                // SQLite UPSERT（ON CONFLICT 兼容 3.33，唯一索引 uk_*_votes）替代 ON DUPLICATE KEY
                $stmt = $conn->prepare("INSERT INTO {$table} ({$idCol}, user_id, vote, created_at) VALUES (:id, :uid, :vote, :now) ON CONFLICT({$idCol}, user_id) DO UPDATE SET vote = :vote2");
                $stmt->execute([':id' => $id, ':uid' => $_SESSION['user_id'], ':vote' => $vote, ':now' => $now, ':vote2' => $vote]);
            }

            $counts = self::getCounts($type, [$id]);
            $c = $counts[$id] ?? ['likes' => 0];
            return ['success' => true, 'likes' => $c['likes'], 'user_vote' => $vote, 'award_like' => $isNewLike];
        } catch (\Exception $e) {
            \error_log('Vote::toggle error: ' . $e->getMessage());
            return ['success' => false, 'error' => '操作失败，请稍后重试'];
        }
    }
}
