<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 社区治理前台控制器 — 举报提交、通知中心
 * @file plugins/mod_system/FrontController.php
 * @package Plugin\ModSystem
 * @version 1.0.0
 */

namespace Plugin\ModSystem;

use app\Core\Controller;
use app\Helpers\Auth;
use app\Helpers\Csrf;
use app\Helpers\I18n;

class FrontController extends Controller
{
    /**
     * 提交举报（htmx 局部处理 -> 返回内联反馈）
     */
    public function submit()
    {
        $this->requireLogin();
        Csrf::verifyOrDie($_POST['csrf'] ?? '');

        $user  = Auth::getCurrentUser();
        $me    = (int)$user['id'];
        $db    = Plugin::db();
        $core  = \app\Core\Database::getInstance();

        $targetType   = (string)($_POST['target_type'] ?? '');
        $targetId     = (int)($_POST['target_id'] ?? 0);
        $targetUid    = (int)($_POST['target_uid'] ?? 0);
        $category     = (string)($_POST['category'] ?? '');
        $note         = trim((string)($_POST['note'] ?? ''));
        $isAnonymous  = isset($_POST['anonymous']) ? 1 : 0;

        $notify  = '';
        $fail    = '';

        try {
            // 目标类型白名单
            if (!in_array($targetType, Plugin::TARGETS, true)) {
                $fail = I18n::get('plugin.mod_system.target_missing');
            }
            // 目标 ID 合法
            elseif ($targetId <= 0 || $targetUid <= 0) {
                $fail = I18n::get('plugin.mod_system.target_missing');
            }
            // 不能举报自己
            elseif ($targetUid === $me) {
                $fail = I18n::get('plugin.mod_system.cannot_report_self');
            }
            // 分类合法
            elseif (!in_array($category, Plugin::CATEGORIES, true)) {
                $fail = I18n::get('plugin.mod_system.fail');
            }
            // 举报理由必填
            elseif ($note === '') {
                $fail = I18n::get('plugin.mod_system.report_reason_required');
            }

            // 说明长度限制（≤200 字）
            if (mb_strlen($note) > 200) {
                $note = mb_substr($note, 0, 200);
            }

            // 目标必须存在
            if ($fail === '' && !self::targetExists($targetType, $targetId, $targetUid)) {
                $fail = I18n::get('plugin.mod_system.target_missing');
            }

            // 冷却：同用户同目标（target_type+target_id）在 cooldown 秒内的重复举报
            if ($fail === '') {
                $cooldown = (int)Plugin::getSetting('cooldown', '300');
                $stmt = $db->prepare(
                    "SELECT created_at FROM mod_reports
                     WHERE reporter_id = :r AND target_type = :tt AND target_id = :ti
                     ORDER BY id DESC LIMIT 1"
                );
                $stmt->execute([':r' => $me, ':tt' => $targetType, ':ti' => $targetId]);
                $last = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($last && strtotime($last['created_at']) > time() - $cooldown) {
                    $fail = I18n::get('plugin.mod_system.cooldown');
                }
            }

            // 每日上限
            if ($fail === '') {
                $dailyLimit = (int)Plugin::getSetting('daily_limit', '5');
                $today = date('Y-m-d', time());
                $stmt = $db->prepare(
                    "SELECT COUNT(*) c FROM mod_reports
                     WHERE reporter_id = :r AND substr(created_at, 1, 10) = :d"
                );
                $stmt->execute([':r' => $me, ':d' => $today]);
                $cnt = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ((int)($cnt['c'] ?? 0) >= $dailyLimit) {
                    $fail = I18n::get('plugin.mod_system.daily_limit');
                }
            }

            if ($fail !== '') {
                $this->viewRaw('plugins/mod_system/_feedback', [
                    'notify' => '', 'fail' => $fail,
                ]);
                exit;
            }

            // 写入举报
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $stmt = $db->prepare(
                "INSERT INTO mod_reports
                 (reporter_id, target_type, target_id, target_uid, category, note, is_anonymous, ip, status, created_at)
                 VALUES (:r, :tt, :ti, :tu, :c, :n, :an, :ip, 0, :now)"
            );
            $stmt->execute([
                ':r' => $me, ':tt' => $targetType, ':ti' => $targetId, ':tu' => $targetUid,
                ':c' => $category, ':n' => $note, ':an' => $isAnonymous, ':ip' => $ip,
                ':now' => date('Y-m-d H:i:s'),
            ]);
            $reportId = (int)$db->lastInsertId();

            // 计数预警判断
            $pendingCount = Plugin::countPendingReports($targetType, $targetId);

            // 通知被举报人（不暴露举报人）
            $reportedUid = $targetUid;
            $catText = I18n::get('plugin.mod_system.cat.' . $category);
            Plugin::notify(
                $reportedUid,
                'reported',
                I18n::get('plugin.mod_system.notify_reported_title'),
                I18n::get('plugin.mod_system.notify_reported', ['category' => $catText]),
                self::targetUrl($targetType, $targetId)
            );

            // 通知管理员（新举报 / 达阈值）
            $autoCount = (int)Plugin::getSetting('auto_audit_count', '3');
            $adminIds = Plugin::adminIds();
            $summary = self::targetSummary($targetType, $targetId);
            foreach ($adminIds as $aid) {
                if ($aid === $me) continue;
                if ($pendingCount >= $autoCount) {
                    Plugin::notify(
                        $aid, 'auto_audit',
                        I18n::get('plugin.mod_system.notify_admin_auto_title'),
                        I18n::get('plugin.mod_system.notify_admin_auto', ['summary' => $summary]),
                        BASE_PATH . '/admin/mod-system/reports'
                    );
                } elseif ($pendingCount === 1) {
                    Plugin::notify(
                        $aid, 'report_new',
                        I18n::get('plugin.mod_system.notify_admin_new_title'),
                        I18n::get('plugin.mod_system.notify_admin_new'),
                        BASE_PATH . '/admin/mod-system/reports'
                    );
                }
            }

            $notify = I18n::get('plugin.mod_system.success');
        } catch (\Throwable $e) {
            \error_log('[mod_system] submit error: ' . $e->getMessage());
            $fail = I18n::get('plugin.mod_system.fail');
        }

        $this->viewRaw('plugins/mod_system/_feedback', [
            'notify' => $notify, 'fail' => $fail,
        ]);
    }

    /**
     * 前台小黑屋公示页（公开，不需登录）
     */
    public function blacklist()
    {
        $db   = Plugin::db();
        $core = \app\Core\Database::getInstance();

        // 仅公示「封禁中」的用户（status=1）
        $stmt = $db->prepare("SELECT * FROM mod_bans WHERE status = 1 ORDER BY id DESC LIMIT 100");
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $uidMap = [];
        foreach ($rows as $b) {
            $uidMap[$b['user_id']] = $b['user_id'];
            $uidMap[$b['banned_by']] = $b['banned_by'];
        }
        // 批量补用户名（M-1/P2：经 User::batchGetNames 批量封装，一次 IN 查询替代循环 N+1）
        $nameMap = \app\Models\User::batchGetNames(array_values($uidMap));

        $items = [];
        foreach ($rows as $b) {
            $items[] = [
                'id'          => (int)$b['id'],
                'user_id'     => (int)$b['user_id'],
                'username'    => $nameMap[(int)$b['user_id']] ?? ('#' . (int)$b['user_id']),
                'reason'      => $b['reason'],
                'banned_by'   => $nameMap[(int)$b['banned_by']] ?? ('#' . (int)$b['banned_by']),
                'banned_at'   => $b['banned_at'],
                'expires_at'  => $b['expires_at'],
                'is_permanent'=> empty($b['expires_at']),
            ];
        }

        $this->view('plugins/mod_system/blacklist', [
            '__nav_active' => 'blacklist',
            'items' => $items,
        ]);
    }

    /**
     * 目标内容是否仍存在（含对应用户校验）
     */
    private static function targetExists(string $type, int $id, int $uid): bool
    {
        $core = \app\Core\Database::getInstance();
        switch ($type) {
            case 'thread':
                try {
                    $mi = \app\SplitDB\Schema::mainIndexDb();
                    $st = $mi->prepare("SELECT id FROM topic_index WHERE id = :i AND deleted_at IS NULL");
                    $st->execute([':i' => $id]);
                    return (bool)$st->fetch(\PDO::FETCH_ASSOC);
                } catch (\Throwable $e) { return false; }
            case 'post':
                try {
                    $mi = \app\SplitDB\Schema::mainIndexDb();
                    $st = $mi->prepare("SELECT id FROM reply_index WHERE id = :i");
                    $st->execute([':i' => $id]);
                    return (bool)$st->fetch(\PDO::FETCH_ASSOC);
                } catch (\Throwable $e) { return false; }
            case 'blog':
                $r = (new \app\Models\Blog())->queryOne('SELECT id FROM blogs WHERE id = :i', [':i' => $id]);
                return !empty($r);
            case 'blog_comment':
                $r = $core->fetchOne('SELECT id FROM blog_comments WHERE id = :i', [':i' => $id]);
                return !empty($r);
            case 'user':
                $r = $core->fetchOne('SELECT id FROM users WHERE id = :i', [':i' => $uid]);
                return !empty($r);
        }
        return false;
    }

    /**
     * 目标跳转链接
     */
    public static function targetUrl(string $type, int $id): string
    {
        $base = \defined('BASE_PATH') ? \BASE_PATH : '';
        switch ($type) {
            case 'thread': return $base . '/thread/' . $id;
            case 'post':
                // 回帖：跳主题页（id 为回复 id，可再定位，此处先回主题列表兜底）
                try {
                    $mi = \app\SplitDB\Schema::mainIndexDb();
                    $st = $mi->prepare("SELECT pid FROM reply_index WHERE id = :i");
                    $st->execute([':i' => $id]);
                    $row = $st->fetch(\PDO::FETCH_ASSOC);
                    if ($row && !empty($row['pid'])) return $base . '/thread/' . (int)$row['pid'];
                } catch (\Throwable $e) {}
                return $base . '/forum';
            case 'blog':            return $base . '/blog/' . $id;
            case 'blog_comment':    return $base . '/blog/' . $id;
            case 'user':            return $base . '/profile/' . $id;
        }
        return $base . '/';
    }

    /**
     * 目标内容摘要（用于通知）
     */
    public static function targetSummary(string $type, int $id): string
    {
        $core = \app\Core\Database::getInstance();
        try {
            switch ($type) {
                case 'thread':
                    $mi = \app\SplitDB\Schema::mainIndexDb();
                    $st = $mi->prepare("SELECT title FROM topic_index WHERE id = :i");
                    $st->execute([':i' => $id]);
                    $row = $st->fetch(\PDO::FETCH_ASSOC);
                    if ($row) return mb_substr($row['title'], 0, 30);
                    break;
                case 'blog':
                    $r = (new \app\Models\Blog())->queryOne('SELECT title FROM blogs WHERE id = :i', [':i' => $id]);
                    if ($r) return mb_substr($r['title'], 0, 30);
                    break;
            }
        } catch (\Throwable $e) {}
        return '#'.$id;
    }
}