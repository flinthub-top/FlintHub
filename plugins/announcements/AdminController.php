<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 公告系统后台控制器 — 公告管理
 * @file plugins/announcements/AdminController.php
 * @package Plugin\Announcements
 * @version 1.1.0
 */

namespace Plugin\Announcements;

use app\Controllers\Admin\BaseController;
use app\Helpers\Csrf;

class AdminController extends BaseController
{
    public function index()
    {
        $announcements = \Plugin\Announcements\Plugin::getAll();
        $styles = \Plugin\Announcements\Plugin::getStyles();
        $mode = \app\Helpers\Settings::get('announcement_mode', 'normal');

        $error = $_SESSION['ann_error'] ?? '';
        $success = $_SESSION['ann_success'] ?? '';
        unset($_SESSION['ann_error'], $_SESSION['ann_success']);

        $this->view('plugins/announcements/admin', [
            'announcements' => $announcements,
            'styles' => $styles,
            'mode' => $mode,
            'error' => $error,
            'success' => $success,
            '__nav_active' => 'announcements',
        ]);
    }

    public function setMode()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $mode = $_POST['mode'] ?? 'normal';
        if (!in_array($mode, ['normal', 'scroll'], true)) $mode = 'normal';
        (new \app\Models\Setting())->setValue('announcement_mode', $mode);
        \app\Helpers\Settings::buildCache();
        $_SESSION['ann_success'] = $mode === 'scroll' ? \app\Helpers\I18n::get('plugin.announcements.msg_scroll') : \app\Helpers\I18n::get('plugin.announcements.msg_normal');
        $this->redirect('/admin/announcements');
    }

    public function add()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $style = trim($_POST['style'] ?? 'yellow');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($content)) {
            $_SESSION['ann_error'] = \app\Helpers\I18n::get('plugin.announcements.msg_empty');
        } else {
            \Plugin\Announcements\Plugin::add($content, $url, $style, $sortOrder);
            $_SESSION['ann_success'] = \app\Helpers\I18n::get('plugin.announcements.msg_added');
        }
        $this->redirect('/admin/announcements');
    }

    public function edit()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $style = trim($_POST['style'] ?? 'yellow');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if ($id <= 0 || empty($content)) {
            $_SESSION['ann_error'] = \app\Helpers\I18n::get('plugin.announcements.msg_param');
        } else {
            \Plugin\Announcements\Plugin::update($id, $content, $url, $style, $sortOrder);
            $_SESSION['ann_success'] = \app\Helpers\I18n::get('plugin.announcements.msg_updated');
        }
        $this->redirect('/admin/announcements');
    }

    public function delete()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            \Plugin\Announcements\Plugin::delete($id);
            $_SESSION['ann_success'] = \app\Helpers\I18n::get('plugin.announcements.msg_deleted');
        }
        $this->redirect('/admin/announcements');
    }
}
