<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 后台板块管理控制器 — 板块增删改、排序、权限设置
 * @file app/Controllers/Admin/CategoryController.php
 * @package app\Controllers\Admin
 */

namespace app\Controllers\Admin;

use app\Helpers\Csrf;
use app\Models\Category;

class CategoryController extends BaseController
{
    public function index()
    {
        $categoryModel = new Category();
        $action = $_POST['action'] ?? $_GET['action'] ?? '';
        $editCategory = null;
        $error = '';

        if ($action === 'edit' && !empty($_GET['id'])) {
            $editCategory = $categoryModel->find((int)$_GET['id']);
        }

        if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $cid = (int)($_POST['id'] ?? 0);
            if ($categoryModel->deleteWithReset($cid)) {
                $this->redirect('/admin/categories');
            }
            // 最后一个版块不可删除（deleteWithReset 返回 false）：展示错误并留在本页
            $error = \app\Helpers\I18n::get('admin.category_last_cannot_delete');
            $deleteDenied = true;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($deleteDenied)) {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            $icon = trim($_POST['icon'] ?? 'forum');
            $showIcon = isset($_POST['show_icon']) ? 1 : 0;
            if (!$name) { $error = \app\Helpers\I18n::get('admin.category_name_required'); }
            elseif (!empty($_GET['id'])) {
                $categoryModel->update((int)$_GET['id'], [
                    'name' => $name, 'description' => $desc, 'sort_order' => $sortOrder,
                    'icon' => $icon, 'show_icon' => $showIcon,
                ]);
                $this->redirect('/admin/categories');
            } else {
                $categoryModel->insert([
                    'name' => $name, 'description' => $desc, 'sort_order' => $sortOrder,
                    'icon' => $icon, 'show_icon' => $showIcon,
                ]);
                $this->redirect('/admin/categories');
            }
        }

        $categories = $categoryModel->allOrdered();
        $threadCounts = $categoryModel->getThreadCountMap();

        $this->view('admin/categories', [
            'categories' => $categories, 'editCategory' => $editCategory, 'error' => $error,
            'threadCounts' => $threadCounts, 'success' => htmlspecialchars($_GET['msg'] ?? '', ENT_QUOTES, 'UTF-8'), '__nav_active' => 'categories',
        ]);
    }
}
