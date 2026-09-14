<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 友情链接后台控制器 — 友情链接增删改、排序、审核
 * @file plugins/friend_links/AdminController.php
 * @package Plugin\FriendLinks
 * @version 1.1.0
 */

namespace Plugin\FriendLinks;

use app\Controllers\Admin\BaseController;
use app\Helpers\Csrf;

class AdminController extends BaseController
{
    /** [SplitDB] 插件独立库连接 */
    public static function db(): \PDO
    {
        return \app\Helpers\Plugin::db('friend_links');
    }

    public function index()
    {
        $db = self::db();

        // [SplitDB] 建表幂等（独立库 + SQLite DDL）
        \app\Helpers\Plugin::ensureSchema('friend_links', "CREATE TABLE IF NOT EXISTS friend_links (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            url TEXT NOT NULL,
            description TEXT,
            logo TEXT,
            sort_order INTEGER DEFAULT 0,
            is_visible INTEGER DEFAULT 1,
            status TEXT NOT NULL DEFAULT 'approved',
            applicant_id INTEGER NOT NULL DEFAULT 0,
            applied_at TEXT,
            created_at TEXT
        )");

        $links = $db->query("SELECT * FROM friend_links WHERE status != 'pending' ORDER BY sort_order ASC, id ASC")->fetchAll(\PDO::FETCH_ASSOC);
        $pending = \Plugin\FriendLinks\Plugin::getPending();

        $error = $_SESSION['fl_error'] ?? '';
        $success = $_SESSION['fl_success'] ?? '';
        unset($_SESSION['fl_error'], $_SESSION['fl_success']);

        $this->view('plugins/friend_links/admin', [
            'links' => $links,
            'pending' => $pending,
            'applyEnabled' => \Plugin\FriendLinks\Plugin::isApplyEnabled(),
            'error' => $error,
            'success' => $success,
            '__nav_active' => 'friend-links',
        ]);
    }

    public function add()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);

        if (empty($name) || empty($url)) {
            $_SESSION['fl_error'] = \app\Helpers\I18n::get('plugin.friend_links.err_empty');
        } else {
            $db = self::db();
            $db->prepare(
                "INSERT INTO friend_links (name, url, description, logo, sort_order, status, created_at) VALUES (:name, :url, :desc, :logo, :sort, 'approved', :now)"
            )->execute([
                ':name' => $name,
                ':url' => $url,
                ':desc' => trim($_POST['description'] ?? ''),
                ':logo' => trim($_POST['logo'] ?? ''),
                ':sort' => $sort_order,
                ':now' => date('Y-m-d H:i:s'),
            ]);
            $_SESSION['fl_success'] = \app\Helpers\I18n::get('plugin.friend_links.msg_added');
        }
        $this->redirect('/admin/friend-links');
    }

    public function edit()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $url = trim($_POST['url'] ?? '');

        if ($id <= 0 || empty($name) || empty($url)) {
            $_SESSION['fl_error'] = \app\Helpers\I18n::get('plugin.friend_links.err_param');
        } else {
            $db = self::db();
            $db->prepare(
                "UPDATE friend_links SET name = :name, url = :url, description = :desc, logo = :logo, sort_order = :sort, is_visible = :vis WHERE id = :id"
            )->execute([
                ':name' => $name,
                ':url' => $url,
                ':desc' => trim($_POST['description'] ?? ''),
                ':logo' => trim($_POST['logo'] ?? ''),
                ':sort' => (int)($_POST['sort_order'] ?? 0),
                ':vis' => (int)($_POST['is_visible'] ?? 1),
                ':id' => $id,
            ]);
            $_SESSION['fl_success'] = \app\Helpers\I18n::get('plugin.friend_links.msg_updated');
        }
        $this->redirect('/admin/friend-links');
    }

    public function delete()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            self::db()->prepare("DELETE FROM friend_links WHERE id = :id")->execute([':id' => $id]);
            $_SESSION['fl_success'] = \app\Helpers\I18n::get('plugin.friend_links.msg_deleted');
        }
        $this->redirect('/admin/friend-links');
    }

    public function approve()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            \Plugin\FriendLinks\Plugin::approve($id);
            $_SESSION['fl_success'] = \app\Helpers\I18n::get('plugin.friend_links.msg_approved');
        }
        $this->redirect('/admin/friend-links');
    }

    public function reject()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            \Plugin\FriendLinks\Plugin::reject($id);
            $_SESSION['fl_success'] = \app\Helpers\I18n::get('plugin.friend_links.msg_rejected');
        }
        $this->redirect('/admin/friend-links');
    }

    public function toggleApply()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $enabled = !empty($_POST['apply_enabled']);
        \Plugin\FriendLinks\Plugin::setApplyEnabled($enabled);
        $_SESSION['fl_success'] = $enabled ? \app\Helpers\I18n::get('plugin.friend_links.msg_apply_on') : \app\Helpers\I18n::get('plugin.friend_links.msg_apply_off');
        $this->redirect('/admin/friend-links');
    }
}
