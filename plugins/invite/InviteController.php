<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 邀请码前台控制器 — 邀请码生成、使用记录
 * @file plugins/invite/InviteController.php
 * @package Plugin\Invite
 * @version 1.0.0
 */

namespace Plugin\Invite;

use app\Core\Controller;
use app\Helpers\Auth;
use app\Helpers\Csrf;
use app\Helpers\Points;

class InviteController extends Controller
{
    /** [SplitDB] 插件独立库连接 */
    public static function db(): \PDO
    {
        return \app\Helpers\Plugin::db('invite');
    }

    public function index()
    {
        $this->requireLogin();
        $user = Auth::getCurrentUser();
        $userId = (int)$user['id'];
        $db = self::db();
        $error = '';
        $success = '';

        // 处理生成请求
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $pointsCost = max(1, (int)($_POST['points_cost'] ?? 10));

            if ((int)$user['points'] < $pointsCost) {
                $error = \app\Helpers\I18n::get('plugin.invite.err_points', ['cur' => (int)$user['points'], 'need' => $pointsCost]);
            } else {
                $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $expiresDays = max(1, (int)($_POST['expires_days'] ?? 30));
                $expiresAt = date('Y-m-d H:i:s', time() + $expiresDays * 86400);

                $db->begin();
                try {
                    $db->prepare(
                        'INSERT INTO invites (code, creator_id, points_cost, expires_at, created_at) VALUES (:code, :creator, :cost, :expires, :now)'
                    )->execute([
                        ':code' => $code, ':creator' => $userId, ':cost' => $pointsCost,
                        ':expires' => $expiresAt, ':now' => date('Y-m-d H:i:s'),
                    ]);
                    Points::award($userId, -$pointsCost, '生成邀请码', 0, 'invite');
                    Auth::clearUserCache();
                    $db->commit();
                    $success = \app\Helpers\I18n::get('plugin.invite.success', ['code' => $code, 'cost' => $pointsCost, 'days' => $expiresDays]);
                } catch (\Exception $e) {
                    $db->rollback();
                    $error = \app\Helpers\I18n::get('plugin.invite.err_gen');
                }
            }
        }

        // 获取用户的邀请码列表（[SplitDB] invites 独立库 + 核心库批量补用户名）
        $invites = $db->query(
            'SELECT * FROM invites
             WHERE creator_id = ' . (int)$userId . '
             ORDER BY created_at DESC
             LIMIT 10'
        )->fetchAll(\PDO::FETCH_ASSOC);
        if (!$invites) $invites = [];

        $usedIds = array_unique(array_map(fn($r) => (int)$r['used_by_user_id'], $invites));
        $usedNames = \app\Models\User::batchGetNames($usedIds);
        foreach ($invites as &$row) {
            $row['used_by_username'] = $usedNames[(int)$row['used_by_user_id']] ?? '';
        }
        unset($row);

        // 统计
        $totalUsed = $db->query('SELECT COUNT(*) as cnt FROM invites WHERE creator_id = ' . (int)$userId . ' AND used_by_user_id IS NOT NULL')->fetch(\PDO::FETCH_ASSOC);
        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM invites WHERE creator_id = :uid AND used_by_user_id IS NULL AND (expires_at IS NULL OR expires_at > :now)');
        $stmt->execute([':uid' => $userId, ':now' => $now]);
        $totalValid = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->view('plugins/invite/index', [
            'invites' => $invites,
            'totalUsed' => (int)($totalUsed['cnt'] ?? 0),
            'totalValid' => (int)($totalValid['cnt'] ?? 0),
            'error' => $error,
            'success' => $success,
            '__nav_active' => '',
        ]);
    }
}
