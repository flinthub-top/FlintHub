<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 红包控制器 — 红包发送、抢领、领取记录、积分扣减
 * @file plugins/red_packet/RedPacketController.php
 * @package Plugin\RedPacket
 * @version 1.0.0
 */

namespace Plugin\RedPacket;

use app\Core\Controller;
use app\Core\Database;
use app\Helpers\Auth;
use app\Helpers\Csrf;
use app\Helpers\Points;

class RedPacketController extends Controller
{
    /** [SplitDB] 插件独立库连接（red_packets / red_packet_claims 表） */
    public static function db(): \PDO
    {
        return \app\Helpers\Plugin::db('red_packet');
    }

    /**
     * 红包首页：创建表单 + 可抢红包列表
     */
    public function index()
    {
        $this->requireLogin();

        $pdb = self::db(); // [SplitDB] 独立库
        $user = Auth::getCurrentUser();
        $now = date('Y-m-d H:i:s');

        // 先检查过期红包，退回未领积分
        $this->refundExpired();

        // 获取可抢的红包（未过期、有剩余、不是自己发的；[SplitDB] 独立库查 + 核心库补用户名）
        $available = $pdb->query(
            "SELECT * FROM red_packets
             WHERE expires_at > " . $pdb->quote($now) . "
               AND remaining_count > 0
             ORDER BY created_at DESC
             LIMIT 10"
        )->fetchAll(\PDO::FETCH_ASSOC);

        // 批量补发红包者用户名（M-1/P2：经 User::batchGetNames 批量封装读核心库 users）
        if (!empty($available)) {
            $cids = array_unique(array_map(fn($r) => (int)$r['creator_id'], $available));
            $users = \app\Models\User::batchGetNames($cids);
            foreach ($available as &$pkt) {
                $pkt['creator_name'] = $users[(int)$pkt['creator_id']] ?? '';
            }
            unset($pkt);
        }

        // 标记当前用户是否已抢过
        if (!empty($available)) {
            $pids = array_column($available, 'id');
            $placeholders = [];
            $params = [':uid' => $user['id']];
            foreach ($pids as $i => $pid) {
                $key = ':pid' . $i;
                $placeholders[] = $key;
                $params[$key] = $pid;
            }
            $stmt = $pdb->prepare(
                "SELECT packet_id FROM red_packet_claims
                 WHERE user_id = :uid AND packet_id IN (" . implode(',', $placeholders) . ")"
            );
            $stmt->execute($params);
            $claimed = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $claimedIds = [];
            foreach ($claimed as $c) {
                $claimedIds[$c['packet_id']] = true;
            }
            foreach ($available as &$pkt) {
                $pkt['already_claimed'] = isset($claimedIds[$pkt['id']]);
            }
            unset($pkt);
        }

        // 用户自己发的红包（独立库查 + 核心库补用户名）
        $stmt = $pdb->prepare(
            "SELECT * FROM red_packets
             WHERE creator_id = :uid
             ORDER BY created_at DESC
             LIMIT 10"
        );
        $stmt->execute([':uid' => $user['id']]);
        $myPackets = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!empty($myPackets)) {
            $cids = array_unique(array_map(fn($r) => (int)$r['creator_id'], $myPackets));
            $users = \app\Models\User::batchGetNames($cids);
            foreach ($myPackets as &$pkt) {
                $pkt['creator_name'] = $users[(int)$pkt['creator_id']] ?? '';
            }
            unset($pkt);
        }

        $data = [
            '__nav_active' => 'redpacket',
            'points' => (int)($user['points'] ?? 0),
            'available' => $available,
            'my_packets' => $myPackets,
            'now' => $now,
        ];

        $this->view('plugins/red_packet/index', $data);
    }

    /**
     * 创建红包
     */
    public function create()
    {
        $this->requireLogin();
        Csrf::verifyOrDie($_POST['csrf'] ?? '');

        $user = Auth::getCurrentUser();

        $totalPoints = (int)($_POST['total_points'] ?? 0);
        $totalCount = (int)($_POST['total_count'] ?? 0);
        $expireHours = (int)($_POST['expire_hours'] ?? 24);
        $threadId = !empty($_POST['thread_id']) ? (int)$_POST['thread_id'] : null;

        // 验证
        if ($totalPoints < $totalCount) {
            $_SESSION['redpacket_msg'] = \app\Helpers\I18n::get('plugin.red_packet.err_points_lt_count');
            $_SESSION['redpacket_success'] = false;
            $this->redirect('/redpacket');
        }
        if ($totalCount < 1 || $totalCount > 100) {
            $_SESSION['redpacket_msg'] = \app\Helpers\I18n::get('plugin.red_packet.err_count_range');
            $_SESSION['redpacket_success'] = false;
            $this->redirect('/redpacket');
        }
        if ($totalPoints < 1) {
            $_SESSION['redpacket_msg'] = \app\Helpers\I18n::get('plugin.red_packet.err_points_min');
            $_SESSION['redpacket_success'] = false;
            $this->redirect('/redpacket');
        }
        if ($expireHours < 1 || $expireHours > 168) {
            $expireHours = 24;
        }

        try {
            \Plugin\RedPacket\Plugin::createForThread(
                (int)$user['id'],
                $totalPoints,
                $totalCount,
                $expireHours,
                $threadId
            );
            $_SESSION['redpacket_msg'] = \app\Helpers\I18n::get('plugin.red_packet.created', ['points' => $totalPoints, 'count' => $totalCount]);
            $_SESSION['redpacket_success'] = true;
        } catch (\Throwable $e) {
            \error_log('red_packet create failed: ' . $e->getMessage());
            $_SESSION['redpacket_msg'] = \app\Helpers\I18n::get('plugin.red_packet.err_create');
            $_SESSION['redpacket_success'] = false;
        }

        $this->redirect('/redpacket');
    }

    /**
     * 红包详情页
     */
    public function detail(int $id)
    {
        $this->requireLogin();

        $pdb = self::db(); // [SplitDB] 独立库
        $db = \app\Core\Database::getInstance(); // 核心库（users）
        $user = Auth::getCurrentUser();
        $now = date('Y-m-d H:i:s');

        // 先检查过期
        $this->refundExpired();

        // 红包（独立库查 + 核心库补发红包者用户名）
        $stmt = $pdb->prepare('SELECT * FROM red_packets WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $packet = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$packet) {
            $this->redirect('/redpacket');
        }
        $names = \app\Models\User::batchGetNames([(int)$packet['creator_id']]);
        $packet['creator_name'] = $names[(int)$packet['creator_id']] ?? '';

        // 领取记录（截断最近 10 条，仅展示；独立库查 + 核心库补用户名）
        $stmt = $pdb->prepare(
            "SELECT * FROM red_packet_claims
             WHERE packet_id = :pid
             ORDER BY created_at ASC
             LIMIT 10"
        );
        $stmt->execute([':pid' => $id]);
        $claims = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!empty($claims)) {
            $uids = array_unique(array_map(fn($r) => (int)$r['user_id'], $claims));
            $users = \app\Models\User::batchGetUsers($uids);
            foreach ($claims as &$c) {
                $c['username'] = $users[(int)$c['user_id']]['username'] ?? '';
                $c['avatar'] = $users[(int)$c['user_id']]['avatar'] ?? '';
            }
            unset($c);
        }

        // 当前用户是否已抢（独立查询，避免被列表截断影响）
        $myClaim = null;
        $stmt = $pdb->prepare('SELECT * FROM red_packet_claims WHERE packet_id = :pid AND user_id = :uid');
        $stmt->execute([':pid' => $id, ':uid' => (int)$user['id']]);
        $myRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($myRow) $myClaim = $myRow;

        // 是否过期
        $isExpired = $now > $packet['expires_at'];
        $isEmpty = (int)$packet['remaining_count'] <= 0;

        $this->view('plugins/red_packet/detail', [
            '__nav_active' => 'redpacket',
            'packet' => $packet,
            'claims' => $claims,
            'my_claim' => $myClaim,
            'is_expired' => $isExpired,
            'is_empty' => $isEmpty,
            'is_creator' => (int)$packet['creator_id'] === (int)$user['id'],
            'points' => (int)($user['points'] ?? 0),
        ]);
    }

    /**
     * 抢红包
     */
    public function grab(int $id)
    {
        $this->requireLogin();
        Csrf::verifyOrDie($_POST['csrf'] ?? '');

        $isAjax = !empty($_POST['ajax']); // 帖子内嵌红包卡片 AJAX 抢红包（返回 JSON 不跳转）

        $user = Auth::getCurrentUser();
        $pdb = self::db(); // [SplitDB] 独立库（red_packets / red_packet_claims）
        $db = \app\Core\Database::getInstance(); // 核心库（users/points_log）
        $now = date('Y-m-d H:i:s');

        // 先检查过期退回
        $this->refundExpired();

        $db->begin();
        try {
            // 锁定红包行（独立库；SQLite 无 FOR UPDATE，事务内读 + 条件更新防并发）
            $stmt = $pdb->prepare('SELECT * FROM red_packets WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $packet = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$packet) {
                throw new \RuntimeException(\app\Helpers\I18n::get('plugin.red_packet.err_not_found'));
            }

            // 检查是否过期
            if ($now > $packet['expires_at']) {
                throw new \RuntimeException(\app\Helpers\I18n::get('plugin.red_packet.err_expired'));
            }

            // 检查是否还有剩余
            if ((int)$packet['remaining_count'] <= 0 || (int)$packet['remaining_points'] <= 0) {
                throw new \RuntimeException(\app\Helpers\I18n::get('plugin.red_packet.err_empty'));
            }

            // 检查是否已抢过
            $stmt = $pdb->prepare('SELECT id FROM red_packet_claims WHERE packet_id = :pid AND user_id = :uid');
            $stmt->execute([':pid' => $id, ':uid' => $user['id']]);
            if ($stmt->fetch(\PDO::FETCH_ASSOC)) {
                throw new \RuntimeException(\app\Helpers\I18n::get('plugin.red_packet.err_already'));
            }

            // 生成随机金额
            $points = $this->calculatePoints(
                (int)$packet['remaining_points'],
                (int)$packet['remaining_count']
            );
            if ($points <= 0) {
                throw new \RuntimeException(\app\Helpers\I18n::get('plugin.red_packet.err_calc'));
            }

            // 插入领取记录（独立库）
            $pdb->prepare(
                "INSERT INTO red_packet_claims (packet_id, user_id, points, created_at)
                 VALUES (:pid, :uid, :p, :now)"
            )->execute([':pid' => $id, ':uid' => $user['id'], ':p' => $points, ':now' => $now]);

            // 更新红包余额（独立库，条件更新防并发）
            $upd = $pdb->prepare(
                "UPDATE red_packets
                 SET remaining_points = remaining_points - :p,
                     remaining_count  = remaining_count - 1
                 WHERE id = :id AND remaining_count > 0 AND remaining_points >= :p"
            );
            $upd->execute([':p' => $points, ':id' => $id]);
            if ($upd->rowCount() === 0) {
                throw new \RuntimeException(\app\Helpers\I18n::get('plugin.red_packet.err_empty'));
            }

            // 给用户加积分（核心库 users/points_log）
            $db->query(
                "UPDATE users SET points = points + :p WHERE id = :uid",
                [':p' => $points, ':uid' => $user['id']]
            );
            $db->query(
                "INSERT INTO points_log (user_id, points, reason, related_id, related_type, created_at)
                 VALUES (:uid, :p, :reason, :rid, :rtype, :now)",
                [
                    ':uid'   => $user['id'],
                    ':p'     => $points,
                    ':reason' => '抢红包',
                    ':rid'   => $id,
                    ':rtype' => 'red_packet_claim',
                    ':now'   => $now,
                ]
            );

            $db->commit();

            // AJAX（帖子内嵌卡片）：返回 JSON，不写 session、不跳转
            if ($isAjax) {
                $this->json([
                    'success' => true,
                    'points' => $points,
                    'remaining' => (int)$packet['remaining_count'] - 1,
                    'message' => \app\Helpers\I18n::get('plugin.red_packet.grabbed', ['points' => $points]),
                    // 按钮只显示"已抢"（积分提示走下方 message，不重复显示）
                    'claimed_btn_text' => \app\Helpers\I18n::get('plugin.red_packet.claimed'),
                ]);
            }

            $_SESSION['redpacket_grab_points'] = $points;
            $_SESSION['redpacket_msg'] = \app\Helpers\I18n::get('plugin.red_packet.grabbed', ['points' => $points]);
            $_SESSION['redpacket_success'] = true;

        } catch (\Throwable $e) {
            $db->rollback();

            // AJAX：返回具体失败原因（已抢/已抢完/已过期/金额不足等）
            if ($isAjax) {
                $this->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ]);
            }

            $_SESSION['redpacket_msg'] = \app\Helpers\I18n::get('plugin.red_packet.err_grab');
            $_SESSION['redpacket_success'] = false;
        }

        $this->redirect('/redpacket/' . $id);
    }

    /**
     * 随机金额算法（二倍均值法，类微信红包）
     * 每次抢时，取 [1, 剩余平均值 × 2] 之间的随机数
     * 保证每次至少 1 积分，最后一个人领完所有剩余
     */
    private function calculatePoints(int $remainingPoints, int $remainingCount): int
    {
        if ($remainingCount <= 1) {
            return $remainingPoints;
        }

        // 二倍均值法：随机上限 = 剩余平均值 × 2
        // 每人至少保底 1 积分
        $avg = $remainingPoints / $remainingCount;
        $max = (int)($avg * 2);
        // 确保不超过剩余积分减去后面人的保底
        $max = min($max, $remainingPoints - ($remainingCount - 1));
        return mt_rand(1, max(1, $max));
    }

    /**
     * 检查过期红包，退回未领积分
     */
    private function refundExpired(): void
    {
        $pdb = self::db(); // [SplitDB] 独立库（red_packets）
        $db = \app\Core\Database::getInstance(); // 核心库（users/points_log）
        $now = date('Y-m-d H:i:s');

        // 时间戳跳过检测：上次无过期红包且在 60 秒内，不再查库
        $cacheKey = '_rp_refund_check';
        if (isset($_SESSION[$cacheKey]) && ($now < $_SESSION[$cacheKey]['next_check'])) {
            return;
        }

        // 找出已过期但还有剩余的红包（独立库）
        $stmt = $pdb->prepare(
            "SELECT id, creator_id, remaining_points
             FROM red_packets
             WHERE expires_at <= :now AND remaining_points > 0
             LIMIT 50"
        );
        $stmt->execute([':now' => $now]);
        $expired = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($expired)) {
            // 无过期红包，60 秒内不再检查
            $_SESSION[$cacheKey] = ['next_check' => date('Y-m-d H:i:s', time() + 60)];
            return;
        }
        $db->begin();
        try {
            foreach ($expired as $pkt) {
                $refundPoints = (int)$pkt['remaining_points'];
                if ($refundPoints <= 0) continue;

                // 退回积分给发红包者（核心库）
                $db->query(
                    "UPDATE users SET points = points + :p WHERE id = :uid",
                    [':p' => $refundPoints, ':uid' => $pkt['creator_id']]
                );
                $db->query(
                    "INSERT INTO points_log (user_id, points, reason, related_id, related_type, created_at)
                     VALUES (:uid, :p, :reason, :rid, :rtype, :now)",
                    [
                        ':uid'   => $pkt['creator_id'],
                        ':p'     => $refundPoints,
                        ':reason' => '红包过期退回',
                        ':rid'   => $pkt['id'],
                        ':rtype' => 'red_packet_refund',
                        ':now'   => $now,
                    ]
                );
                // 清零剩余（独立库）
                $pdb->prepare(
                    "UPDATE red_packets SET remaining_points = 0, remaining_count = 0 WHERE id = :id"
                )->execute([':id' => $pkt['id']]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            \error_log('RedPacket refund error: ' . $e->getMessage());
        }
    }
}
