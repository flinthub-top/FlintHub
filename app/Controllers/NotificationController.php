<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 通知中心前台控制器（通知插件内置化）— 通知列表、标记已读、批量删除
 * @file app/Controllers/NotificationController.php
 * @package app\Controllers
 */

namespace app\Controllers;

use app\Core\Controller;
use app\Helpers\Csrf;
use app\Helpers\Notification;

class NotificationController extends Controller
{
    /**
     * GET/POST /notifications — 通知列表（POST 处理批量删除）
     */
    public function index()
    {
        $this->requireLogin();
        $currentUser = $this->currentUser();
        $userId = (int)$currentUser['id'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $ids = $_POST['ids'] ?? [];
            if (is_array($ids)) {
                $cleanIds = array_map('intval', $ids);
                Notification::deleteByIds($userId, array_filter($cleanIds, fn($v) => $v > 0));
            }
            $this->redirect('/notifications');
        }

        // 进入页面即自动标记全部已读
        Notification::markAllRead($userId);

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 10;
        $notifications = Notification::getList($userId, $page, $perPage);
        $total = Notification::getTotal($userId);
        $totalPages = max(1, (int)ceil($total / $perPage));

        $this->view('notifications/index', [
            'notifications' => $notifications,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            '__nav_active' => 'notifications',
        ]);
    }
}