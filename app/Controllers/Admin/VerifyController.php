<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 后台二次验证控制器 — 管理员密码验证确认
 * @file app/Controllers/Admin/VerifyController.php
 * @package app\Controllers\Admin
 */

namespace app\Controllers\Admin;

use app\Helpers\Csrf;

class VerifyController extends BaseController
{
    public function index()
    {
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // 二次验证加限流（5 次 / 15 分钟），防会话被控后高速爆破这最后一道密码防线
            \app\Helpers\RateLimiter::hitConfig('admin_verify', 5, 900);
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $currentUser = \app\Helpers\Auth::getCurrentUserWithPassword();
            if ($currentUser && password_verify($password, $currentUser['password'])) {
                $_SESSION['admin_verified_at'] = time();
                session_write_close();
                $this->redirect('/admin/');
            } else {
                $error = \app\Helpers\I18n::get('admin.verify_password_wrong');
            }
        }
        $this->view('admin/verify', ['error' => $error]);
    }
}
