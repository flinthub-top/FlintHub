<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 用户资料前台控制器 — 个人资料查看/编辑、头像上传
 * @file app/Controllers/ProfileController.php
 * @package app\Controllers
 */

namespace app\Controllers;
use app\Core\Controller;
use app\Models\User;
use app\Helpers\Auth;
use app\Helpers\Csrf;
use app\Helpers\Upload;
use app\Helpers\Permission;

class ProfileController extends Controller
{
    public function index()
    {
        $this->requireLogin();
        $currentUser = $this->currentUser();
        $userModel = new User();
        $error = '';
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $action = $_POST['action'] ?? '';

            if ($action === 'update_profile') {
                $email = trim($_POST['email'] ?? '');
                $signature = trim($_POST['signature'] ?? '');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = \app\Helpers\I18n::get('profile.email_invalid'); }
                elseif (strlen($signature) > (int)\app\Helpers\Settings::get('limit_user_signature', '500')) { $error = \app\Helpers\I18n::get('profile.signature_too_long'); }
                else {
                    // 邮箱有变更直接更新，已认证用户无需重新验证防锁定
                    $oldEmail = $currentUser['email'] ?? '';
                    if ($email !== $oldEmail) {
                        $userModel->execute(
                            'UPDATE users SET email = :email, signature = :sig WHERE id = :id',
                            [':email' => $email, ':sig' => $signature, ':id' => (int)$currentUser['id']]
                        );
                        \app\Helpers\AuditLog::log('update', 'user', (int)$currentUser['id'], '修改邮箱: ' . $oldEmail . ' -> ' . $email);
                        $success = \app\Helpers\I18n::get('profile.email_updated');
                    } else {
                        $userModel->updateProfile((int)$currentUser['id'], $email, $signature);
                        $success = \app\Helpers\I18n::get('profile.updated_success');
                    }
                    Auth::clearUserCache();
                }
            } elseif ($action === 'change_password') {
                $oldPwd = (string)($_POST['old_password'] ?? '');
                $newPwd = (string)($_POST['new_password'] ?? '');
                $confirmPwd = (string)($_POST['confirm_password'] ?? '');

                $userWithPwd = \app\Helpers\Auth::getCurrentUserWithPassword();
                if (!$userWithPwd || !password_verify($oldPwd, $userWithPwd['password'])) { $error = \app\Helpers\I18n::get('profile.old_pwd_wrong'); }
                elseif (strlen($newPwd) < 6) { $error = \app\Helpers\I18n::get('profile.new_pwd_short'); }
                elseif ($newPwd !== $confirmPwd) { $error = \app\Helpers\I18n::get('profile.pwd_mismatch'); }
                else {
                    $userModel->updatePassword((int)$currentUser['id'], $newPwd);
                    session_regenerate_id(true);
                    \app\Helpers\AuditLog::log('update', 'user', (int)$currentUser['id'], '用户修改密码');
                    $success = \app\Helpers\I18n::get('profile.pwd_changed');
                    // 修改密码成功后一次性消费 CSRF Token，防被窃取的 Token 无限重放
                    Csrf::consume();
                }
            } elseif ($action === 'upload_avatar') {
                if (!empty($_FILES['avatar']['name'])) {
                    $upload = Upload::file($_FILES['avatar'], ['jpg', 'jpeg', 'png', 'gif']);
                    if ($upload['success']) {
                        // 记录旧头像路径（审核插件可能需要，先不删文件）
                        $oldAvatar = $currentUser['avatar'] ?? '';
                        $userModel->updateAvatar((int)$currentUser['id'], $upload['filename']);
                        $success = \app\Helpers\I18n::get('profile.avatar_uploaded');
                        Auth::clearUserCache();
                        // 插件钩子：头像上传后
                        \app\Helpers\Plugin::hook('avatar_upload_after', [
                            'user_id' => (int)$currentUser['id'],
                            'avatar' => $upload['filename'],
                            'old_avatar' => $oldAvatar,
                        ]);
                        // 如果无审核插件或未开启头像审核，立即删除旧头像
                        $reviewAvatar = \app\Helpers\Settings::get('cr_review_avatar', '0');
                        if ($reviewAvatar !== '1' && $oldAvatar) {
                            $oldPath = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . basename($oldAvatar);
                            if (is_file($oldPath) && strpos(realpath($oldPath) ?: '', realpath(UPLOAD_PATH) ?: '') === 0) { @unlink($oldPath); }
                        }
                    } else { $error = $upload['error']; }
                } else { $error = \app\Helpers\I18n::get('profile.choose_avatar_file'); }
            }
        }

        // 复用 Auth::getCurrentUser（请求级缓存：GET 0 次额外查询；POST 已 clearUserCache 会强制重查，
        // 返回字段覆盖视图所需 avatar/username/created_at/email/signature，替代重复 User::find）
        $user = $this->currentUser();
        $userThreads = $userModel->getThreads((int)$currentUser['id']);
        // 权限过滤：剔除无权查看的版块的帖子
        $allowedIds = Permission::getAuthorizedCategoryIds((int)$currentUser['id']);
        if (!empty($allowedIds)) {
            $userThreads = array_values(array_filter($userThreads, function ($t) use ($allowedIds) {
                return in_array((int)$t['category_id'], $allowedIds, true);
            }));
        } elseif ($userThreads) {
            $userThreads = [];
        }
        $userBlogs = $userModel->getBlogs((int)$currentUser['id']);
        $stats = $userModel->getStats((int)$currentUser['id']);

        $this->view('profile/index', [
            'user' => $user, 'error' => $error, 'success' => $success, 'userThreads' => $userThreads, 'userBlogs' => $userBlogs,
            'stats' => $stats,
        ]);
    }
}
