<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 后台博客管理控制器 — 博客文章/分类管理
 * @file app/Controllers/Admin/BlogController.php
 * @package app\Controllers\Admin
 */

namespace app\Controllers\Admin;

use app\Helpers\Csrf;
use app\Models\BlogCategory;

class BlogController extends BaseController
{
    public function categories()
    {
        $catModel = new BlogCategory();
        $action = $_POST['action'] ?? $_GET['action'] ?? '';
        $editCategory = null;

        if ($action === 'edit_form' && !empty($_GET['id'])) {
            $editCategory = $catModel->find((int)$_GET['id']);
        }

        if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $cid = (int)($_POST['id'] ?? 0);
            $catModel->deleteWithReset($cid);
            $this->redirect('/admin/blog-categories');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            $editId = (int)($_GET['id'] ?? 0);
            if ($editId) {
                $catModel->update($editId, [
                    'name' => $name, 'description' => $desc, 'sort_order' => $sortOrder,
                ]);
            } else {
                $catModel->insert([
                    'name' => $name, 'description' => $desc, 'sort_order' => $sortOrder,
                ]);
            }
            $this->redirect('/admin/blog-categories');
        }

        $categories = $catModel->allOrdered();
        $blogCounts = $catModel->getBlogCountMap();

        $this->view('admin/blog_categories', [
            'categories' => $categories, 'editCategory' => $editCategory,
            'blogCounts' => $blogCounts, '__nav_active' => 'blog-categories',
        ]);
    }

    public function manage()
    {
        // 批量删除
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'batch_delete') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $ids = $_POST['blog_ids'] ?? [];
            if (is_array($ids)) {
                // 防御纵深：ID 全量 intval（模型层已 intval，控制器层再清洗一次防未来路径漏网）
                $ids = array_map('intval', $ids);
                $ids = array_values(array_filter($ids, fn($v) => $v > 0));
            }
            if (!empty($ids)) {
                $count = (new \app\Models\Blog())->batchDelete($ids);
                foreach ($ids as $id) {
                    \app\Helpers\Search::removeFromIndex('blog', (int)$id);
                }
                \app\Helpers\AuditLog::log('batch_delete', 'blog', 0, '批量删除 ' . $count . ' 篇博客');
                $this->redirect('/admin/blog-manage?msg=deleted_' . $count);
            }
            $this->redirect('/admin/blog-manage');
        }

        // 批量转移分类
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'batch_move') {
            $this->batchMoveIds('blogs', 'blog_ids', '/admin/blog-manage');
            return;
        }

        // 博客列表（分页：每页 10 条）
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 10;
        $offset = ($page - 1) * $perPage;
        $blogModel = new \app\Models\Blog();
        $totalBlogs = (int)($blogModel->queryOne('SELECT COUNT(*) as cnt FROM blogs')['cnt'] ?? 0);
        $totalPages = max(1, (int)ceil($totalBlogs / $perPage));
        $blogs = $blogModel->query(
            "SELECT b.*, u.username, bc.name as category_name
             FROM blogs b JOIN users u ON b.user_id = u.id
             LEFT JOIN blog_categories bc ON b.category_id = bc.id
             ORDER BY b.created_at DESC
             LIMIT " . (int)$perPage . " OFFSET " . (int)$offset
        );
        $blogCategories = (new \app\Models\BlogCategory())->allOrdered();
        $this->view('admin/blog_manage', [
            'blogs' => $blogs, 'blogCategories' => $blogCategories,
            'page' => $page, 'totalPages' => $totalPages, 'total' => $totalBlogs,
            '__nav_active' => 'blog-manage',
        ]);
    }
}
