<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 勋章中心后台控制器 — 勋章 CRUD + 手动颁发/回收
 * @file plugins/medal/AdminMedalController.php
 * @package Plugin\Medal
 * @version 1.0.0
 */

namespace Plugin\Medal;

use app\Controllers\Admin\BaseController;
use app\Helpers\Csrf;

class AdminMedalController extends BaseController
{
    /**
     * 勋章列表 + 手动颁发/回收
     */
    public function index()
    {
        $medals = Plugin::getMedals();
        // 每枚勋章统计已获得人数（[SplitDB] user_medals 在 medal 插件独立库）
        $db = \app\Helpers\Plugin::db('medal');
        $rows = $db->query('SELECT medal_id, COUNT(*) AS c FROM user_medals GROUP BY medal_id')->fetchAll(\PDO::FETCH_ASSOC);
        $countMap = [];
        foreach ($rows as $r) {
            $countMap[(int)$r['medal_id']] = (int)$r['c'];
        }

        $error = $_SESSION['medal_error'] ?? '';
        $success = $_SESSION['medal_success'] ?? '';
        unset($_SESSION['medal_error'], $_SESSION['medal_success']);

        $this->view('plugins/medal/admin', [
            'medals' => $medals,
            'countMap' => $countMap,
            'error' => $error,
            'success' => $success,
            '__nav_active' => 'medal',
        ]);
    }

    /**
     * 新增勋章
     */
    public function add()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $_SESSION['medal_error'] = \app\Helpers\I18n::get('plugin.medal.msg_name_empty');
        } else {
            Plugin::addMedal($this->formData());
            $_SESSION['medal_success'] = \app\Helpers\I18n::get('plugin.medal.msg_added');
        }
        $this->redirect('/admin/medals');
    }

    /**
     * 编辑勋章
     */
    public function edit()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id <= 0 || $name === '') {
            $_SESSION['medal_error'] = \app\Helpers\I18n::get('plugin.medal.msg_param');
        } else {
            Plugin::updateMedal($id, $this->formData());
            $_SESSION['medal_success'] = \app\Helpers\I18n::get('plugin.medal.msg_updated');
        }
        $this->redirect('/admin/medals');
    }

    /**
     * 删除勋章
     */
    public function delete()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            Plugin::deleteMedal($id);
            $_SESSION['medal_success'] = \app\Helpers\I18n::get('plugin.medal.msg_deleted');
        }
        $this->redirect('/admin/medals');
    }

    /**
     * 手动颁发（按用户名）
     */
    public function grant()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $medalId = (int)($_POST['medal_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        if ($medalId <= 0 || $username === '') {
            $_SESSION['medal_error'] = \app\Helpers\I18n::get('plugin.medal.msg_select');
            $this->redirect('/admin/medals');
        }
        $db = \app\Core\Database::getInstance();
        $user = $db->fetchOne(
            'SELECT id FROM users WHERE username = :u',
            [':u' => $username]
        );
        if (!$user) {
            $_SESSION['medal_error'] = \app\Helpers\I18n::get('plugin.medal.msg_user_missing', ['name' => $username]);
            $this->redirect('/admin/medals');
        }
        $ok = Plugin::grant((int)$user['id'], $medalId, 'manual');
        $_SESSION['medal_success'] = $ok
            ? \app\Helpers\I18n::get('plugin.medal.msg_granted', ['name' => $username])
            : \app\Helpers\I18n::get('plugin.medal.msg_already_has', ['name' => $username]);
        $this->redirect('/admin/medals');
    }

    /**
     * 回收勋章（按用户名）
     */
    public function revoke()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $medalId = (int)($_POST['medal_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        if ($medalId <= 0 || $username === '') {
            $_SESSION['medal_error'] = \app\Helpers\I18n::get('plugin.medal.msg_select');
            $this->redirect('/admin/medals');
        }
        $db = \app\Core\Database::getInstance();
        $user = $db->fetchOne(
            'SELECT id FROM users WHERE username = :u',
            [':u' => $username]
        );
        if (!$user) {
            $_SESSION['medal_error'] = \app\Helpers\I18n::get('plugin.medal.msg_user_missing', ['name' => $username]);
            $this->redirect('/admin/medals');
        }
        Plugin::revoke((int)$user['id'], $medalId);
        $_SESSION['medal_success'] = \app\Helpers\I18n::get('plugin.medal.msg_revoked', ['name' => $username]);
        $this->redirect('/admin/medals');
    }

    /**
     * 收集表单数据（共用字段）
     */
    private function formData(): array
    {
        return [
            'name' => $_POST['name'] ?? '',
            'icon' => trim($_POST['icon'] ?? 'award'),
            'color' => trim($_POST['color'] ?? '#f59e0b'),
            'description' => $_POST['description'] ?? '',
            'condition_type' => $_POST['condition_type'] ?? 'manual',
            'condition_value' => (int)($_POST['condition_value'] ?? 0),
            'sort' => (int)($_POST['sort'] ?? 0),
            'status' => isset($_POST['status']) ? 1 : 0,
            'image' => trim($_POST['image'] ?? ''),
        ];
    }
}
