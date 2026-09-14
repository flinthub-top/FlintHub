<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 红包插件主类 — 激活/停用、红包发送/抢领、数据查询
 * @file plugins/red_packet/Plugin.php
 * @package Plugin\RedPacket
 * @version 1.0.0
 */

namespace Plugin\RedPacket;

class Plugin
{
    /** [SplitDB] 插件独立库连接（plugins/red_packet/data/red_packet.sqlite） */
    public static function db(): \PDO
    {
        return \app\Helpers\Plugin::db('red_packet');
    }

    /**
     * 激活插件：创建数据表（[SplitDB] SQLite DDL，独立库，幂等）
     */
    public static function activate(): bool
    {
        $db = self::db();

        $db->exec("CREATE TABLE IF NOT EXISTS red_packets (
            id INTEGER PRIMARY KEY,
            creator_id INTEGER NOT NULL,
            total_points INTEGER NOT NULL DEFAULT 0,
            total_count INTEGER NOT NULL DEFAULT 0,
            remaining_points INTEGER NOT NULL DEFAULT 0,
            remaining_count INTEGER NOT NULL DEFAULT 0,
            expires_at TEXT NOT NULL,
            thread_id INTEGER,
            thread_title TEXT DEFAULT '',
            created_at TEXT NOT NULL
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_red_packets_creator ON red_packets (creator_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_red_packets_expires ON red_packets (expires_at)');
        // 老库补列：thread_title 快照（帖子删除后红包详情页仍能显示来源标题）
        $rpCols = array_column($db->query('PRAGMA table_info(red_packets)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        if (!in_array('thread_title', $rpCols, true)) {
            $db->exec("ALTER TABLE red_packets ADD COLUMN thread_title TEXT DEFAULT ''");
        }

        $db->exec("CREATE TABLE IF NOT EXISTS red_packet_claims (
            id INTEGER PRIMARY KEY,
            packet_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            points INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            UNIQUE(packet_id, user_id)
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_red_packet_claims_user ON red_packet_claims (user_id)');

        return true;
    }

    /**
     * 为帖子创建红包（事务：扣积分 → 插红包 → 记积分日志）
     * 供 RedPacketController::create()（独立页发红包）与 thread_create_after 钩子（发帖附带红包）共用
     *
     * @param int         $userId      发红包者（核心库 users.id）
     * @param int         $totalPoints 总积分
     * @param int         $totalCount  份数
     * @param int         $expireHours 有效期（小时，1-168）
     * @param int|null    $threadId    关联帖子 ID（可选）
     * @param string      $threadTitle 帖子标题快照（可选，帖子删除后仍可显示来源）
     * @return int 红包 ID
     * @throws \RuntimeException 余额不足或创建失败（消息已本地化）
     */
    public static function createForThread(int $userId, int $totalPoints, int $totalCount, int $expireHours, ?int $threadId = null, string $threadTitle = ''): int
    {
        $pdb = self::db();                       // 插件独立库（red_packets）
        $db = \app\Core\Database::getInstance(); // 核心库（users/points_log）
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + $expireHours * 3600);

        $db->begin();
        try {
            // 创建红包记录（独立库）——先落红包行，拿到 packetId 供积分日志关联
            $pdb->prepare(
                'INSERT INTO red_packets (creator_id, total_points, total_count, remaining_points, remaining_count, expires_at, thread_id, thread_title, created_at)
                 VALUES (:cid, :tp, :tc, :rp, :rc, :exp, :tid, :tt, :now)'
            )->execute([
                ':cid' => $userId,
                ':tp'  => $totalPoints,
                ':tc'  => $totalCount,
                ':rp'  => $totalPoints,
                ':rc'  => $totalCount,
                ':exp' => $expiresAt,
                ':tid' => $threadId,
                ':tt'  => mb_substr($threadTitle, 0, 200),
                ':now' => $now,
            ]);
            $packetId = (int)$pdb->lastInsertId();

            // 扣除用户积分（核心库，H-5/P2）：改走 Points::deduct 条件原子扣减
            // （UPDATE ... AND points >= :p，防透支竞态），并触发 points_deduct_after 通知钩子。
            // Points::deduct 嵌套事务感知：当前位于外层事务内，不自建/不提交，随外层一起提交回滚；
            // 积分日志由 Points::deduct 统一写入（reason=发红包 / related_type=red_packet）。
            if (!\app\Helpers\Points::deduct($userId, $totalPoints, '发红包', $packetId, 'red_packet')) {
                throw new \RuntimeException(\app\Helpers\I18n::get('plugin.red_packet.err_points'));
            }

            $db->commit();
            return $packetId;
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /**
     * 禁用插件：清理钩子
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
        $db->exec('DROP TABLE IF EXISTS red_packet_claims');
        $db->exec('DROP TABLE IF EXISTS red_packets');
    }
}
