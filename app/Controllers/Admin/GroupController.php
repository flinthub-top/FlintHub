<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 后台用户组管理控制器 — 用户组增删改、权限分配
 * @file app/Controllers/Admin/GroupController.php
 * @package app\Controllers\Admin
 */

namespace app\Controllers\Admin;

use app\Helpers\Csrf;
use app\Helpers\Permission;
use app\Models\Category;

class GroupController extends BaseController
{
    public function index()
    {
        $error = '';
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $action = $_POST['action'] ?? '';

            if ($action === 'save') {
                $id = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $color = trim($_POST['color'] ?? '#666');
                $isDefault = (int)($_POST['is_default'] ?? 0);
                if (!$name) {
                    $error = \app\Helpers\I18n::get('admin.group_name_required');
                } else {
                    // 内置组（1~4）限制：不允许设为默认组，不允许改名
                    if ($id > 0 && $id <= 4) {
                        $isDefault = 0; // 内置组不可设为默认
                    }
                    if ($isDefault) {
                        $db = \app\Core\Database::getInstance();
                        $db->query('UPDATE user_groups SET is_default = 0');
                    }
                    Permission::saveGroup($id, $name, $color, $isDefault);
                    $success = $id > 0 ? \app\Helpers\I18n::get('admin.group_updated') : \app\Helpers\I18n::get('admin.group_created');
                }
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0 && $id <= 4) {
                    $error = \app\Helpers\I18n::get('admin.group_builtin_no_delete');
                } elseif ($id > 0) {
                    Permission::deleteGroup($id);
                    $success = \app\Helpers\I18n::get('admin.group_deleted');
                }
            }
        }

        $groups = Permission::getGroups();
        $this->view('admin/groups', [
            'groups' => $groups, 'error' => $error, 'success' => $success,
            '__nav_active' => 'groups',
        ]);
    }

    /**
     * 版块权限设置
     */
    public function permissions()
    {
        $db = \app\Core\Database::getInstance();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $perms = $_POST['perms'] ?? [];
            if ($categoryId > 0 && is_array($perms)) {
                $error = '';
                // 确保 can_attach 列存在（幂等），再保存含该字段的权限
                Permission::ensureCanAttachColumn();
                // 获取所有用户组（含游客），确保全部取消勾选的组也被保存为全 0
                $allGroups = Permission::getGroups();
                array_unshift($allGroups, ['id' => 0]);
                foreach ($allGroups as $g) {
                    $groupId = (int)$g['id'];
                    $p = $perms[$groupId] ?? [];
                    Permission::saveCategoryPermission(
                        $categoryId,
                        $groupId,
                        (int)($p['can_view'] ?? 0),
                        (int)($p['can_post'] ?? 0),
                        (int)($p['can_reply'] ?? 0),
                        (int)($p['can_attach'] ?? 0)
                    );
                }
                $this->redirect('/admin/permissions?msg=saved');
            }
        }

        $groups = Permission::getGroups();
        array_unshift($groups, ['id' => 0, 'name' => \app\Helpers\I18n::get('admin.guest_group'), 'color' => '#999']);
        $categories = (new Category())->allOrdered();
        $selectedCategoryId = (int)($_GET['category_id'] ?? 0);
        $currentPerms = [];
        if ($selectedCategoryId > 0) {
            $currentPerms = Permission::getCategoryPermissions($selectedCategoryId);
        }

        $this->view('admin/permissions', [
            'groups' => $groups, 'categories' => $categories,
            'selectedCategoryId' => $selectedCategoryId,
            'currentPerms' => $currentPerms,
            'msg' => htmlspecialchars($_GET['msg'] ?? '', ENT_QUOTES, 'UTF-8'),
            '__nav_active' => 'permissions',
        ]);
    }
}
