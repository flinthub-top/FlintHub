<?php
/**
 * FlintHub — 任务中心插件 后台控制器
 * /admin/task-center 任务 CRUD（含级联删除进度/流水）+ 领取统计
 * @file plugins/task_center/AdminController.php
 * @package Plugin\TaskCenter
 * @version 1.0.0
 */

namespace Plugin\TaskCenter;

use app\Controllers\Admin\BaseController;
use app\Helpers\Csrf;
use app\Helpers\I18n;

class AdminController extends BaseController
{
    /** 事件类型白名单（与 Plugin::TYPES 一致） */
    private const TYPES = ['register', 'post', 'reply', 'sign_in', 'profile', 'invite', 'vote', 'login'];

    public function index()
    {
        $db = \Plugin\TaskCenter\Plugin::db();
        \app\Helpers\Plugin::ensureSchema('task_center', \Plugin\TaskCenter\Plugin::ddl());

        $tasks = \Plugin\TaskCenter\Plugin::getTasks();
        $claimCounts = \Plugin\TaskCenter\Plugin::claimCounts();
        $today = date('Y-m-d');

        // 依赖插件状态：后台列表给警告徽标
        $depStatus = [
            'sign_in' => \app\Helpers\Plugin::isActivated('daily_checkin'),
            'invite'  => \app\Helpers\Plugin::isActivated('invite'),
        ];

        $error = $_SESSION['tc_error'] ?? '';
        $success = $_SESSION['tc_success'] ?? '';
        unset($_SESSION['tc_error'], $_SESSION['tc_success']);

        $editing = null;
        $editId = (int)($_GET['edit'] ?? 0);
        if ($editId > 0) {
            $editing = \Plugin\TaskCenter\Plugin::getTask($editId);
        }

        $this->view('plugins/task_center/admin', [
            'tasks' => $tasks,
            'claimCounts' => $claimCounts,
            'today' => $today,
            'depStatus' => $depStatus,
            'editing' => $editing,
            'types' => self::TYPES,
            'signInModes' => ['total', 'consecutive'],
            'profileModes' => ['all', 'avatar', 'signature', 'email'],
            'icons' => \Plugin\TaskCenter\Plugin::ICONS,
            'error' => $error,
            'success' => $success,
            '__nav_active' => 'task-center',
        ]);
    }

    /**
     * 读取并校验表单字段；返回 [ok, errorKey, data]
     */
    private function collectInput(): array
    {
        $type = (string)($_POST['type'] ?? '');
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $target = (int)($_POST['target'] ?? 0);
        $reward = (int)($_POST['reward_points'] ?? 0);
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $signInMode = (string)($_POST['sign_in_mode'] ?? 'total');
        $profileMode = (string)($_POST['profile_mode'] ?? 'all');
        $icon = (string)($_POST['icon'] ?? 'clipboard-list');

        if (!in_array($type, self::TYPES, true)) {
            return [false, 'plugin.task_center.err_type', []];
        }
        if ($target < 1) {
            return [false, 'plugin.task_center.err_target', []];
        }
        if ($reward < 0) {
            return [false, 'plugin.task_center.err_reward', []];
        }
        if ($sortOrder < 0) {
            return [false, 'plugin.task_center.err_sort', []];
        }
        if ($type === 'sign_in' && !in_array($signInMode, ['total', 'consecutive'], true)) {
            return [false, 'plugin.task_center.err_sign_in_mode', []];
        }
        if ($type === 'profile' && !in_array($profileMode, ['all', 'avatar', 'signature', 'email'], true)) {
            return [false, 'plugin.task_center.err_profile_mode', []];
        }
        if (!in_array($icon, \Plugin\TaskCenter\Plugin::ICONS, true)) {
            return [false, 'plugin.task_center.err_icon', []];
        }

        return [true, '', [
            'type' => $type, 'title' => $title, 'description' => $description,
            'target' => $target, 'reward' => $reward, 'sort_order' => $sortOrder,
            'sign_in_mode' => $signInMode, 'profile_mode' => $profileMode,
            'icon' => $icon, 'daily_reset' => isset($_POST['daily_reset']) ? 1 : 0,
            'enabled' => isset($_POST['enabled']) ? 1 : 0,
        ]];
    }

    public function add()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        list($ok, $error, $data) = $this->collectInput();
        if (!$ok) {
            $_SESSION['tc_error'] = I18n::get($error);
            $this->redirect('/admin/task-center');
        }

        $db = \Plugin\TaskCenter\Plugin::db();
        $db->prepare(
            "INSERT INTO tc_tasks (type, title, description, target, reward_points, sign_in_mode, profile_mode, daily_reset, icon, sort_order, enabled, created_at)
             VALUES (:type, :title, :desc, :target, :reward, :sigmode, :promode, :daily, :icon, :sort, :enabled, :now)"
        )->execute([
            ':type' => $data['type'], ':title' => $data['title'], ':desc' => $data['description'],
            ':target' => $data['target'], ':reward' => $data['reward'],
            ':sigmode' => $data['sign_in_mode'], ':promode' => $data['profile_mode'],
            ':daily' => $data['daily_reset'], ':icon' => $data['icon'],
            ':sort' => $data['sort_order'], ':enabled' => $data['enabled'],
            ':now' => date('Y-m-d H:i:s'),
        ]);
        \Plugin\TaskCenter\Plugin::clearCache();
        $_SESSION['tc_success'] = I18n::get('plugin.task_center.msg_added');
        $this->redirect('/admin/task-center');
    }

    public function edit()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $existing = \Plugin\TaskCenter\Plugin::getTask($id);
        if ($existing === null) {
            $_SESSION['tc_error'] = I18n::get('plugin.task_center.err_param');
            $this->redirect('/admin/task-center');
        }

        list($ok, $error, $data) = $this->collectInput();
        if (!$ok) {
            $_SESSION['tc_error'] = I18n::get($error);
            $this->redirect('/admin/task-center?edit=' . $id);
        }

        $db = \Plugin\TaskCenter\Plugin::db();
        $db->prepare(
            "UPDATE tc_tasks
                SET type = :type, title = :title, description = :desc, target = :target,
                    reward_points = :reward, sign_in_mode = :sigmode, profile_mode = :promode,
                    daily_reset = :daily, icon = :icon, sort_order = :sort, enabled = :enabled
              WHERE id = :id"
        )->execute([
            ':type' => $data['type'], ':title' => $data['title'], ':desc' => $data['description'],
            ':target' => $data['target'], ':reward' => $data['reward'],
            ':sigmode' => $data['sign_in_mode'], ':promode' => $data['profile_mode'],
            ':daily' => $data['daily_reset'], ':icon' => $data['icon'],
            ':sort' => $data['sort_order'], ':enabled' => $data['enabled'],
            ':id' => $id,
        ]);
        \Plugin\TaskCenter\Plugin::clearCache();
        $_SESSION['tc_success'] = I18n::get('plugin.task_center.msg_updated');
        $this->redirect('/admin/task-center');
    }

    public function delete()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db = \Plugin\TaskCenter\Plugin::db();
            // 级联删除：任务删除后其进度/流水一并清理（避免重建后残留进度干扰领取/每日重置）
            $db->prepare('DELETE FROM tc_log WHERE task_id = :id')->execute([':id' => $id]);
            $db->prepare('DELETE FROM tc_progress WHERE task_id = :id')->execute([':id' => $id]);
            $db->prepare('DELETE FROM tc_tasks WHERE id = :id')->execute([':id' => $id]);
            \Plugin\TaskCenter\Plugin::clearCache();
            $_SESSION['tc_success'] = I18n::get('plugin.task_center.msg_deleted');
        }
        $this->redirect('/admin/task-center');
    }
}
