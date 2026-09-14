<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 后台标签管理控制器 — 标签管理、合并/删除
 * @file app/Controllers/Admin/TagController.php
 * @package app\Controllers\Admin
 */

namespace app\Controllers\Admin;

use app\Helpers\Csrf;
use app\Helpers\Tag;

class TagController extends BaseController
{
    public function index()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            Tag::adminHandlePost($_POST);
            $this->redirect('/admin/tags');
        }

        $tags = Tag::getAll();
        $this->view('admin/tags', ['tags' => $tags, '__nav_active' => 'tags']);
    }
}
