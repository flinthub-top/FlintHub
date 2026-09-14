<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 社区治理后台控制器 — 举报列表/处理/批量、小黑屋、配置
 * @file plugins/mod_system/AdminController.php
 * @package Plugin\ModSystem
 * @version 1.0.0
 */

namespace Plugin\ModSystem;

use app\Controllers\Admin\BaseController;
use app\Helpers\Csrf;
use app\Helpers\I18n;

class AdminController extends BaseController
{
    // ========================================================================
    //  举报列表
    // ========================================================================

    public function reports()
    {
        $db     = Plugin::db();
        $status = (int)($_GET['status'] ?? -1);
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $where = '';
        $params = [];
        if ($status >= 0) {
            $where = 'WHERE status = :st';
            $params[':st'] = $status;
        }

        $stmt = $db->prepare("SELECT COUNT(*) c FROM mod_reports {$where}");
        if ($status >= 0) $stmt->execute($params); else $stmt->execute();
        $total = (int)$stmt->fetch(\PDO::FETCH_ASSOC)['c'] ?? 0;
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) $page = $totalPages;

        $sql = "SELECT * FROM mod_reports {$where} ORDER BY id DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
        $stmt = $db->prepare($sql);
        if ($status >= 0) $stmt->execute($params); else $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // 收集相关的用户/内容信息
        $autoCount = (int)Plugin::getSetting('auto_audit_count', '3');
        $items = [];
        $uidMap = [];
        foreach ($rows as $r) {
            $uidMap[$r['reporter_id']] = $r['reporter_id'];
            $uidMap[$r['target_uid']] = $r['target_uid'];
        }
        $users = \app\Models\User::batchGetNames(array_keys($uidMap));

        foreach ($rows as $r) {
            $title = self::contentTitle($r['target_type'], (int)$r['target_id'], (int)$r['target_uid']);
            $pending = Plugin::countPendingReports($r['target_type'], (int)$r['target_id']);
            $items[] = [
                'id'           => (int)$r['id'],
                'type'         => $r['target_type'],
                'target_id'    => (int)$r['target_id'],
                'target_uid'   => (int)$r['target_uid'],
                'target_title' => $title,
                'category'     => $r['category'],
                'category_text'=> I18n::get('plugin.mod_system.cat.' . $r['category']),
                'reporter'     => $users[(int)$r['reporter_id']] ?? ('#' . (int)$r['reporter_id']),
                'is_anonymous' => (int)$r['is_anonymous'],
                'note'         => $r['note'],
                'created_at'   => $r['created_at'],
                'status'       => (int)$r['status'],
                'handle_action'=> $r['handle_action'],
                'alert'        => $pending >= $autoCount,
            ];
        }

        $msg = $_GET['msg'] ?? '';
        $success = '';
        if ($msg === 'handled') $success = I18n::get('plugin.mod_system.handled_ok');
        if ($msg === 'batch_ok') $success = I18n::get('plugin.mod_system.batch_ok');
        if ($msg === 'banned') $success = I18n::get('plugin.mod_system.handled_ok');
        if ($msg === 'unbanned') $success = I18n::get('plugin.mod_system.handled_ok');

        $banData = $this->banItems();

        // 单页 Tab 切换（Alpine 无刷新切换，服务端仅决定首次激活哪个面板）：
        //   - 落地页 /admin/mod-system 默认「审核封禁」(用户要求进入即显示审核内容)
        //   - /admin/mod-system/config 保持「封禁配置」
        //   - 显式 ?tab=reports|config 可覆盖默认
        $reqTab = (string)($_GET['tab'] ?? '');
        if ($reqTab === 'reports' || $reqTab === 'config') {
            $tab = $reqTab;
        } else {
            $uriPath = rtrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
            $tab = str_ends_with($uriPath, '/admin/mod-system/config') ? 'config' : 'reports';
        }
        $configSuccess = $_SESSION['mod_system_config_ok'] ?? '';
        unset($_SESSION['mod_system_config_ok']);

        $this->view('plugins/mod_system/admin_index', [
            'items'    => $items,
            'status'   => $status,
            'page'     => $page,
            'total'    => $total,
            'total_pages' => $totalPages,
            'csrfToken'=> Csrf::token(),
            'success'  => $success,
            'ban_items' => $banData['items'],
            'default_ban_days' => $banData['default_ban_days'],
            'settings' => Plugin::getAllSettings(),
            'config_success' => $configSuccess,
            'tab'      => $tab,
            '__nav_active' => 'mod_system_reports',
        ]);
    }

    // ========================================================================
    //  单条处理
    // ========================================================================

    public function handle(int $id)
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');

        $db   = Plugin::db();
        $adminUid = (int)$_SESSION['user_id'];

        $stmt = $db->prepare("SELECT * FROM mod_reports WHERE id = :id AND status = 0");
        $stmt->execute([':id' => $id]);
        $report = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$report) {
            $this->redirect('/admin/mod-system/reports');
        }

        $action = (string)($_POST['action'] ?? 'dismiss');
        $banDays = max(0, (int)($_POST['ban_days'] ?? 0));
        $reason = trim((string)($_POST['reason'] ?? ''));

        self::applyHandle($report, $action, $adminUid, $banDays, $reason);

        $this->redirect('/admin/mod-system/reports?msg=handled');
    }

    // ========================================================================
    //  批量处理
    // ========================================================================

    public function batch()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');

        $db      = Plugin::db();
        $adminUid = (int)$_SESSION['user_id'];
        $action  = (string)($_POST['action'] ?? 'dismiss');
        $banDays = max(0, (int)($_POST['ban_days'] ?? 0));
        $reason  = trim((string)($_POST['reason'] ?? ''));
        $ids     = $_POST['reports'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_slice(array_map('intval', array_filter($ids)), 0, 200);
        if (empty($ids)) {
            $this->redirect('/admin/mod-system/reports');
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT * FROM mod_reports WHERE id IN ({$ph}) AND status = 0");
        $stmt->execute($ids);
        $reports = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($reports as $report) {
            self::applyHandle($report, $action, $adminUid, $banDays, $reason);
        }

        $this->redirect('/admin/mod-system/reports?msg=batch_ok');
    }

    /**
     * 执行单个处理动作（单条/批量共用）
     */
    private static function applyHandle(array $report, string $action, int $adminUid, int $banDays, string $reason): void
    {
        $db   = Plugin::db();
        $core = \app\Core\Database::getInstance();
        $now  = date('Y-m-d H:i:s');
        $type = $report['target_type'];
        $tid  = (int)$report['target_id'];
        $tuid = (int)$report['target_uid'];
        $reportId = (int)$report['id'];

        $allowed = ['dismiss', 'delete', 'ban', 'delete_ban'];
        if (!in_array($action, $allowed, true)) $action = 'dismiss';

        $resultKey = 'dismiss';
        try {
            if ($action === 'dismiss') {
                $db->prepare("UPDATE mod_reports SET status = 2, handle_action = 'dismiss', handler_id = :h, handled_at = :now WHERE id = :id")
                    ->execute([':h' => $adminUid, ':now' => $now, ':id' => $reportId]);
                $resultKey = 'dismiss';
            }

            if ($action === 'delete' || $action === 'delete_ban') {
                self::deleteTarget($type, $tid);
                $resultKey = $action === 'delete' ? 'delete' : 'delete_ban';
            }

            if ($action === 'ban' || $action === 'delete_ban') {
                Plugin::ban($tuid, $reason !== '' ? $reason : ($report['category']), $adminUid, $banDays);
                $resultKey = $action === 'ban' ? 'ban' : 'delete_ban';
            }

            // 标记已处理
            $db->prepare("UPDATE mod_reports SET status = 1, handle_action = :a, handler_id = :h, handled_at = :now WHERE id = :id")
                ->execute([':a' => $action == 'dismiss' ? 'dismiss' : $action, ':h' => $adminUid, ':now' => $now, ':id' => $reportId]);

            // 处理日志
            $db->prepare("INSERT INTO mod_logs (report_id, user_id, target_uid, action, detail, created_at)
                          VALUES (:r, :u, :t, :a, :d, :now)")
                ->execute([
                    ':r' => $reportId, ':u' => $adminUid, ':t' => $tuid,
                    ':a' => $action, ':d' => $reason, ':now' => $now,
                ]);

            // 通知举报人
            $resultText = I18n::get('plugin.mod_system.result_' . $resultKey);
            Plugin::notify(
                (int)$report['reporter_id'], 'report_result',
                I18n::get('plugin.mod_system.notify_result_title'),
                I18n::get('plugin.mod_system.notify_result', ['result' => $resultText]),
                ''
            );

            // 失效页面缓存
            try { \app\Helpers\PageCache::invalidate(); } catch (\Throwable $e) {}
        } catch (\Throwable $e) {
            \error_log('[mod_system] handle error: ' . $e->getMessage());
        }
    }

    // ========================================================================
    //  删除目标内容
    // ========================================================================

    private static function deleteTarget(string $type, int $id): void
    {
        switch ($type) {
            case 'thread':
                try {
                    (new \app\Models\Thread())->softDelete($id);
                } catch (\Throwable $e) { \error_log('[mod_system] thread delete: ' . $e->getMessage()); }
                break;
            case 'post':
                try {
                    (new \app\Models\Post())->softDelete($id);
                } catch (\Throwable $e) { \error_log('[mod_system] post delete: ' . $e->getMessage()); }
                break;
            case 'blog':
                try {
                    (new \app\Models\Blog())->batchDelete([$id]);
                } catch (\Throwable $e) { \error_log('[mod_system] blog delete: ' . $e->getMessage()); }
                break;
            case 'blog_comment':
                try {
                    // 物理删除博客评论——M-2/P2：走 Blog Model 封装，不直写 blog_comments 表
                    (new \app\Models\Blog())->deleteComment($id);
                } catch (\Throwable $e) { \error_log('[mod_system] blog_comment delete: ' . $e->getMessage()); }
                break;
            case 'user':
                // 对 user 目标的举报，delete 视为对该用户封禁的一种前置删除提示；此处不做内容级删除
                break;
        }
    }

    /**
     * 后台列表内容摘要
     */
    private static function contentTitle(string $type, int $id, int $uid): string
    {
        $core = \app\Core\Database::getInstance();
        try {
            switch ($type) {
                case 'thread':
                    $mi = \app\SplitDB\Schema::mainIndexDb();
                    $st = $mi->prepare("SELECT title FROM topic_index WHERE id = :i");
                    $st->execute([':i' => $id]);
                    $row = $st->fetch(\PDO::FETCH_ASSOC);
                    if ($row) return '[' . I18n::get('plugin.mod_system.col_target') . '] ' . mb_substr($row['title'], 0, 40);
                    break;
                case 'post':
                    $mi = \app\SplitDB\Schema::mainIndexDb();
                    $st = $mi->prepare("SELECT pid FROM reply_index WHERE id = :i");
                    $st->execute([':i' => $id]);
                    $row = $st->fetch(\PDO::FETCH_ASSOC);
                    if ($row) return '[回帖] 主题#' . (int)$row['pid'] . ' 楼层#' . $id;
                    break;
                case 'blog':
                    $r = (new \app\Models\Blog())->queryOne('SELECT title FROM blogs WHERE id = :i', [':i' => $id]);
                    if ($r) return '[博客] ' . mb_substr($r['title'], 0, 40);
                    break;
                case 'blog_comment':
                    $r = $core->fetchOne('SELECT content FROM blog_comments WHERE id = :i', [':i' => $id]);
                    if ($r) return '[博客评论] ' . mb_substr(strip_tags($r['content']), 0, 40);
                    break;
                case 'user':
                    return '[用户] #' . $uid;
            }
        } catch (\Throwable $e) {}
        return '#' . $id;
    }

    // ========================================================================
    //  小黑屋（封禁列表数据，供「审核封禁」页平铺展示）
    // ========================================================================

    private function banItems(): array
    {
        $db = Plugin::db();
        $core = \app\Core\Database::getInstance();

        $stmt = $db->prepare("SELECT * FROM mod_bans ORDER BY id DESC LIMIT 200");
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $items = [];
        $bannedByUsers = [];
        $unbannedByUsers = [];
        $targetUsers = [];
        foreach ($rows as $b) {
            $bannedByUsers[$b['banned_by']] = $b['banned_by'];
            if (!empty($b['unbanned_by'])) $unbannedByUsers[$b['unbanned_by']] = $b['unbanned_by'];
            $targetUsers[$b['user_id']] = $b['user_id'];
        }
        $nameMap = fn(array $ids) => \app\Models\User::batchGetNames(array_values($ids));
        $bannedByNameMap = $nameMap($bannedByUsers);
        $unbannedByNameMap = $nameMap($unbannedByUsers);
        $targetNameMap = $nameMap($targetUsers);

        foreach ($rows as $b) {
            $isActive = (int)$b['status'] === 1;
            $items[] = [
                'id'              => (int)$b['id'],
                'user_id'         => (int)$b['user_id'],
                'username'        => $targetNameMap[(int)$b['user_id']] ?? ('#' . (int)$b['user_id']),
                'reason'          => $b['reason'],
                'banned_by'       => $bannedByNameMap[(int)$b['banned_by']] ?? ('#' . (int)$b['banned_by']),
                'banned_at'       => $b['banned_at'],
                'expires_at'      => $b['expires_at'],
                'is_permanent'    => empty($b['expires_at']),
                'is_active'       => $isActive,
                'unbanned_by'     => $unbannedByNameMap[(int)$b['unbanned_by']] ?? ('#' . (int)$b['unbanned_by']),
                'unbanned_at'     => $b['unbanned_at'],
            ];
        }

        return ['items' => $items, 'default_ban_days' => (int)Plugin::getSetting('ban_days', '7')];
    }

    /**
     * 手动封禁
     */
    public function banCreate()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $core = \app\Core\Database::getInstance();

        $username = trim((string)($_POST['username'] ?? ''));
        $reason   = trim((string)($_POST['reason'] ?? ''));
        $banDays  = max(0, (int)($_POST['ban_days'] ?? 0));
        $adminUid = (int)$_SESSION['user_id'];

        $u = $core->fetchOne('SELECT id FROM users WHERE username = :n', [':n' => $username]);
        if ($u) {
            Plugin::ban((int)$u['id'], $reason, $adminUid, $banDays);
            $db = Plugin::db();
            $db->prepare("INSERT INTO mod_logs (report_id, user_id, target_uid, action, detail, created_at)
                          VALUES (NULL, :u, :t, 'ban', :d, :now)")
                ->execute([':u' => $adminUid, ':t' => (int)$u['id'], ':d' => '手动封禁: ' . $reason, ':now' => date('Y-m-d H:i:s')]);
        }

        // 手动封禁后回到「审核封禁」页（封禁区块同页展示）
        $this->redirect('/admin/mod-system/reports?msg=banned');
    }

    /**
     * 解封
     */
    public function unban(int $id)
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        Plugin::unban($id, (int)$_SESSION['user_id']);
        $this->redirect('/admin/mod-system/reports?msg=unbanned');
    }

    // ========================================================================
    //  配置
    // ========================================================================

    public function config()
    {
        // 已并入单页「社区管理」，直接定位到「封禁配置」Tab
        $this->redirect('/admin/mod-system?tab=config');
    }

    public function configSave()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $data = $_POST;
        // 复选框未勾选时无对应字段：通知联动开关需显式置 0
        if (isset($data['notify_enabled']) && (string)$data['notify_enabled'] === '1') {
            $data['notify_enabled'] = '1';
        } else {
            $data['notify_enabled'] = '0';
        }
        Plugin::saveSettings($data);
        $_SESSION['mod_system_config_ok'] = I18n::get('plugin.mod_system.saved');
        // 保存后回到「封禁配置」Tab，保持当前子页
        $this->redirect('/admin/mod-system?tab=config');
    }
}