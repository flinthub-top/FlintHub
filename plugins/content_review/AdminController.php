<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 内容审核后台控制器 — 审核列表、通过/驳回、设置
 * @file plugins/content_review/AdminController.php
 * @package Plugin\ContentReview
 * @version 1.0.0
 */

namespace Plugin\ContentReview;

use app\Controllers\Admin\BaseController;
use app\Core\Database;
use app\Helpers\Csrf;

class AdminController extends BaseController
{
    public function index()
    {
        $pending = Plugin::getPendingList();
        $reviewed = [];
        $totalPages = 1;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $total = Plugin::getReviewedTotal();
        if ($total > 0) {
            $totalPages = max(1, (int)ceil($total / $perPage));
            if ($page > $totalPages) $page = $totalPages;
            $reviewed = Plugin::getReviewedList($page, $perPage);
        }

        $config = Plugin::getConfig();
        $groups = Plugin::getGroupsWithExempt('thread');
        $avatarGroups = Plugin::getGroupsWithExempt('avatar');
        $registerGroups = Plugin::getGroupsWithExempt('register');
        $categories = (new \app\Models\Category())->allOrdered();
        $error = $_SESSION['cr_error'] ?? '';
        $success = $_SESSION['cr_success'] ?? '';
        unset($_SESSION['cr_error'], $_SESSION['cr_success']);

        $this->view('plugins/content_review/admin', [
            'pending' => $pending,
            'reviewed' => $reviewed,
            'config' => $config,
            'groups' => $groups,
            'avatarGroups' => $avatarGroups,
            'registerGroups' => $registerGroups,
            'categories' => $categories,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'error' => $error,
            'success' => $success,
            '__nav_active' => 'content-review',
        ]);
    }

    public function approve()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['cr_error'] = \app\Helpers\I18n::get('plugin.content_review.msg_param');
        } else {
            $currentUser = $this->currentUser();
            Plugin::approve($id, (int)$currentUser['id']);
            $_SESSION['cr_success'] = \app\Helpers\I18n::get('plugin.content_review.msg_approved');
        }
        $this->redirect('/admin/content-review');
    }

    public function reject()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if ($id <= 0) {
            $_SESSION['cr_error'] = \app\Helpers\I18n::get('plugin.content_review.msg_param');
        } else {
            $currentUser = $this->currentUser();
            Plugin::reject($id, (int)$currentUser['id'], $reason);
            $_SESSION['cr_success'] = \app\Helpers\I18n::get('plugin.content_review.msg_rejected');
        }
        $this->redirect('/admin/content-review');
    }

    public function settings()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        Plugin::saveConfig($_POST);
        $_SESSION['cr_success'] = \app\Helpers\I18n::get('plugin.content_review.msg_saved');
        $this->redirect('/admin/content-review');
    }

    public function delete()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            Plugin::deleteReview($id);
            $_SESSION['cr_success'] = \app\Helpers\I18n::get('plugin.content_review.msg_deleted');
        }
        $this->redirect('/admin/content-review');
    }

    public function batchDelete()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $ids = $_POST['ids'] ?? [];
        if (is_array($ids) && !empty($ids)) {
            Plugin::batchDeleteReviews($ids);
            $_SESSION['cr_success'] = \app\Helpers\I18n::get('plugin.content_review.msg_batch_deleted', ['count' => count($ids)]);
        }
        $this->redirect('/admin/content-review');
    }

    public function batchApprove()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $ids = $_POST['ids'] ?? [];
        if (is_array($ids) && !empty($ids)) {
            $currentUser = $this->currentUser();
            $uid = (int)$currentUser['id'];
            $count = 0;
            foreach ($ids as $id) {
                $id = (int)$id;
                if ($id > 0) { Plugin::approve($id, $uid); $count++; }
            }
            $_SESSION['cr_success'] = \app\Helpers\I18n::get('plugin.content_review.msg_batch_approved', ['count' => $count]);
        }
        $this->redirect('/admin/content-review');
    }

    public function batchReject()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $ids = $_POST['ids'] ?? [];
        if (is_array($ids) && !empty($ids)) {
            $currentUser = $this->currentUser();
            $uid = (int)$currentUser['id'];
            $count = 0;
            foreach ($ids as $id) {
                $id = (int)$id;
                if ($id > 0) { Plugin::reject($id, $uid); $count++; }
            }
            $_SESSION['cr_success'] = \app\Helpers\I18n::get('plugin.content_review.msg_batch_rejected', ['count' => $count]);
        }
        $this->redirect('/admin/content-review');
    }
}
