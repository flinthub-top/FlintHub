<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 后台用户管理控制器 — 用户列表、编辑、禁用/删除
 * @file app/Controllers/Admin/UserController.php
 * @package app\Controllers\Admin
 */

namespace app\Controllers\Admin;

use app\Helpers\Auth;
use app\Helpers\Csrf;
use app\Models\User;

class UserController extends BaseController
{
    public function index()
    {
        $action = $_POST['action'] ?? $_GET['action'] ?? '';
        $success = '';
        $error = '';

        if ($action === 'edit' && !empty($_GET['id'])) {
            $userModel = new User();
            $user = $userModel->find((int)$_GET['id']);
            if (!$user) $this->redirect('/admin/users');

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                Csrf::verifyOrDie($_POST['csrf'] ?? '');
                $role = trim($_POST['role'] ?? 'user');
                $email = trim($_POST['email'] ?? '');
                $status = trim($_POST['status'] ?? 'active');
                $points = (int)($_POST['points'] ?? 0);
                $level = (int)($_POST['level'] ?? 1);
                $group_id = (int)($_POST['group_id'] ?? 1);
                $password = (string)($_POST['password'] ?? '');
                if (!in_array($role, ['user', 'admin'], true)) {
                    $error = \app\Helpers\I18n::get('admin.invalid_role');
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = \app\Helpers\I18n::get('admin.invalid_email');
                } elseif ($password !== '' && strlen($password) < 6) {
                    $error = \app\Helpers\I18n::get('admin.pwd_too_short');
                } else {
                    // 唯一管理员降级保护：禁止将最后一个 admin 降为普通用户或封禁
                    if ($user['role'] === 'admin' && ($role !== 'admin' || $status !== 'active' || $group_id !== 4)) {
                        if (User::getAdminCount() <= 1) {
                            $error = \app\Helpers\I18n::get('admin.cannot_demote_last_admin');
                        }
                    }
                    // role 与 group_id 强绑定
                    if (!$error) {
                        if ($role !== 'admin' && $group_id === 4) {
                            $error = \app\Helpers\I18n::get('admin.nonadmin_in_admin_group');
                        } elseif ($role === 'admin' && $group_id !== 4) {
                            $group_id = 4; // 管理员必须属于管理员组，强制修正
                        }
                    }
                    if ($error) {
                        $this->view('admin/user_edit', ['user' => $user, 'error' => $error]);
                        return;
                    }
                    if (!$error) {
                        $userModel->updateByAdmin((int)$user['id'], [
                            'role' => $role, 'email' => $email, 'status' => $status,
                            'points' => $points, 'level' => $level, 'group_id' => $group_id,
                        ], $password);
                        // 邮箱验证：后台手动通过（无需真实邮件验证）
                        if (!empty($_POST['email_verified_approve'])) {
                            $userModel->markEmailVerified((int)$user['id']);
                        }
                        \app\Helpers\Auth::clearUserCache();
                        $this->redirect('/admin/users');
                    }
                }
            }

            $this->view('admin/user_edit', ['user' => $user, 'error' => $error]);
            return;
        }

        if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $uid = (int)($_POST['id'] ?? 0);
            if ($uid > 1 && $uid !== (int)($this->currentUser()['id'] ?? 0)) {
                // 检查是否为唯一管理员，防删完最后一个 admin 导致后台不可用
                $targetUser = (new User())->find($uid);
                $isLastAdmin = $targetUser && $targetUser['role'] === 'admin' && User::getAdminCount() <= 1;
                if ($isLastAdmin) {
                    $error = \app\Helpers\I18n::get('admin.cannot_delete_last_admin');
                } else {
                    (new User())->deleteWithCascade($uid);
                    $success = \app\Helpers\I18n::get('admin.user_deleted');
                }
            }
        }

        // 用户列表（分页：每页 10 条）
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 10;
        $offset = ($page - 1) * $perPage;

        // 后台用户查询：读取筛选参数（q/field/role/status），白名单校验在 Model 层 buildAdminSearchWhere 内
        $filters = [
            'q'      => trim((string)($_GET['q'] ?? '')),
            'field'  => (string)($_GET['field'] ?? 'all'),
            'role'   => (string)($_GET['role'] ?? ''),
            'status' => (string)($_GET['status'] ?? ''),
        ];

        $userModel = new User();
        $totalUsers = $userModel->countAdminSearch($filters);
        $totalPages = max(1, (int)ceil($totalUsers / $perPage));
        $users = $userModel->adminSearch($filters, $perPage, $offset);
        $this->view('admin/users', [
            'users' => $users, 'editUser' => null, 'error' => $error, 'success' => $success,
            'page' => $page, 'totalPages' => $totalPages, 'total' => $totalUsers,
            'filters' => $filters, // 回传视图用于表单回显与分页条带参
            '__nav_active' => 'users',
        ]);
    }
}
