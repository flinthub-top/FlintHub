<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 后台回收站控制器 — 软删除帖子/博客的恢复与彻底删除
 * @file app/Controllers/Admin/TrashedController.php
 * @package app\Controllers\Admin
 */

namespace app\Controllers\Admin;

use app\Helpers\Csrf;

class TrashedController extends BaseController
{
    public function index()
    {
        $threadModel = new \app\Models\Thread();
        $action = $_GET['action'] ?? '';
        $error = '';
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $tid = (int)($_POST['thread_id'] ?? 0);
            $subAction = $_POST['sub_action'] ?? '';
            if ($subAction === 'restore' && $tid > 0) {
                // 恢复前取作者（软删时已递减，恢复后加回；仅已删状态递增一次）
                $restoreThread = $threadModel->find($tid);
                $threadModel->restore($tid);
                if ($restoreThread && !empty($restoreThread['deleted_at'])) {
                    (new \app\Models\User())->incrementPostCount((int)$restoreThread['user_id']);
                }
                $this->redirect('/admin/trashed?msg=restored');
            } elseif ($subAction === 'hard_delete' && $tid > 0) {
                $threadModel->hardDelete($tid);
                $this->redirect('/admin/trashed?msg=deleted');
            }
        }

        $perPage = 20;
        $totalTrashed = $threadModel->countDeleted();
        $totalPages = max(1, ceil($totalTrashed / $perPage));

        // 页码钳制到真实 totalPages（与前台一致，解除 1000 页上限）；非法页码回落到最后一页
        $page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
        $offset = ($page - 1) * $perPage;
        $trashed = $threadModel->getDeleted($perPage, $offset);
        $msg = htmlspecialchars($_GET['msg'] ?? '', ENT_QUOTES, 'UTF-8');

        $this->view('admin/trashed', [
            'trashed' => $trashed, 'page' => $page, 'totalPages' => $totalPages,
            'totalTrashed' => $totalTrashed, 'msg' => $msg, 'error' => $error, '__nav_active' => 'trashed',
        ]);
    }
}
