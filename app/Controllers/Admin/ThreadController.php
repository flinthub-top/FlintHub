<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 后台帖子管理控制器 — 帖子列表、编辑、批量删除/移动
 * @file app/Controllers/Admin/ThreadController.php
 * @package app\Controllers\Admin
 */

namespace app\Controllers\Admin;

use app\Helpers\Csrf;
use app\Models\Thread;
use app\Models\Category;
use app\Helpers\Search;

class ThreadController extends BaseController
{
    public function index()
    {
        $threadModel = new Thread();
        $action = $_POST['action'] ?? $_GET['action'] ?? '';

        // 批量删除
        if ($action === 'batch_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $ids = $_POST['thread_ids'] ?? [];
            if (is_array($ids)) {
                // 防御纵深：ID 全量 intval（模型层已 intval，控制器层再清洗一次防未来路径漏网）
                $ids = array_map('intval', $ids);
                $ids = array_values(array_filter($ids, fn($v) => $v > 0));
            }
            if (!empty($ids)) {
                // 先取各主题作者，硬删后同步递减发帖数
                $authors = $threadModel->getAuthorsByIds($ids);
                $count = $threadModel->batchDelete($ids);
                foreach ($authors as $authorId) {
                    (new \app\Models\User())->decrementPostCount((int)$authorId);
                }
                foreach ($ids as $id) {
                    \app\Helpers\Search::removeFromIndex('thread', (int)$id);
                }
                \app\Helpers\AuditLog::log('batch_delete', 'thread', 0, '批量删除 ' . $count . ' 个帖子');
                // 读取当前筛选分类并拼入跳转 URL，操作后仍停留在该分类下
                $filterCategoryId = (int)($_POST['category_id'] ?? $_GET['category_id'] ?? 0);
                $qs = 'msg=deleted_' . $count;
                if ($filterCategoryId > 0) $qs = 'category_id=' . $filterCategoryId . '&' . $qs;
                $this->redirect('/admin/threads?' . $qs);
            }
            $this->redirect('/admin/threads');
        }

        // 批量转移版块
        if ($action === 'batch_move' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            // 读取当前筛选分类并传给 batchMoveIds，重定向时拼入 category_id
            $filterCategoryId = (int)($_POST['category_id'] ?? $_GET['category_id'] ?? 0);
            $this->batchMoveIds('threads', 'thread_ids', '/admin/threads', 'moved_', function ($tid) {
                \app\Helpers\Search::indexThread($tid);
            }, $filterCategoryId > 0 ? 'category_id=' . $filterCategoryId : '');
            return;
        }

        if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $tid = (int)($_POST['id'] ?? 0);
            // 先取主题作者，硬删后同步递减发帖数
            $delThread = $threadModel->find($tid);
            $threadModel->hardDelete($tid);
            if ($delThread) {
                (new \app\Models\User())->decrementPostCount((int)$delThread['user_id']);
            }
            \app\Helpers\Search::removeFromIndex('thread', $tid);
            \app\Helpers\AuditLog::log('delete', 'thread', $tid, '硬删除帖子');
            $this->redirect('/admin/threads');
        }

        if ($action === 'edit' && !empty($_GET['id'])) {
            $thread = $threadModel->find((int)$_GET['id']);
            if (!$thread) $this->redirect('/admin/threads');
            $categories = (new Category())->allOrdered();

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                Csrf::verifyOrDie($_POST['csrf'] ?? ''); // CSRF 校验
                $threadModel->update((int)$thread['id'], [
                    'title' => trim($_POST['title'] ?? ''),
                    'content' => trim($_POST['content'] ?? ''),
                    'category_id' => (int)($_POST['category_id'] ?? 0),
                    'is_pinned' => (int)($_POST['is_pinned'] ?? 0),
                    'is_highlighted' => (int)($_POST['is_highlighted'] ?? 0),
                    // 标题颜色白名单校验：仅收预设色板值，非白名单丢弃
                    'color' => in_array(trim($_POST['color'] ?? ''), Thread::PRESET_TITLE_COLORS, true)
                        ? trim($_POST['color']) : '',
                ]);
                \app\Helpers\Search::indexThread((int)$thread['id']); // 后台编辑后更新倒排索引
                \app\Helpers\AuditLog::log('update', 'thread', (int)$thread['id'], '后台编辑帖子');
                $this->redirect('/admin/threads');
            }

            $this->view('admin/thread_edit', ['thread' => $thread, 'categories' => $categories, '__nav_active' => 'threads', 'needsEditor' => true]);
            return;
        }

        // 分类筛选参数：intval 清洗，非法值或 0 视为「全部」
        $filterCategoryId = (int)($_GET['category_id'] ?? 0);
        if ($filterCategoryId < 0) $filterCategoryId = 0;
        $categoryFilter = $filterCategoryId > 0 ? [$filterCategoryId] : [];

        $perPage = 20;

        // 帖子已分片：后台列表改读 main_index.topic_index（原直查 business.threads 表已退役、无数据）
        $totalThreads = $threadModel->countActive($categoryFilter);
        $totalPages = max(1, ceil($totalThreads / $perPage));

        // 页码钳制到真实 totalPages（与前台一致，解除 1000 页上限）；非法页码回落到最后一页
        $page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
        $offset = ($page - 1) * $perPage;

        $threads = $threadModel->getIndexPaginated($categoryFilter, $perPage, $offset);
        $categories = (new Category())->allOrdered();
        $this->view('admin/threads', [
            'threads' => $threads, 'page' => $page, 'totalPages' => $totalPages,
            'totalThreads' => $totalThreads, 'categories' => $categories,
            'filterCategoryId' => $filterCategoryId, // 回传视图：下拉框回显 + 分页/批量操作保筛选
            '__nav_active' => 'threads',
        ]);
    }
}
