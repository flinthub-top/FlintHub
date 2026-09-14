<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 帖子回复前台控制器 — 发帖、回帖、编辑/删除
 * @file app/Controllers/PostController.php
 * @package app\Controllers
 */

namespace app\Controllers;
use app\Core\Controller;
use app\Models\{Thread, Post, Category};
use app\Helpers\Csrf;
use app\Helpers\Tag;
use app\Helpers\Points;
use app\Helpers\Settings;
use app\Helpers\Search;
use app\Helpers\RateLimiter;

class PostController extends Controller
{
    /**
     * 博客模式(blog)下论坛被关闭：发帖/回帖直连重定向首页
     */
    public function __construct()
    {
        if (\app\Helpers\Settings::get('site_mode', 'portal') === 'blog') {
            $this->redirect('/');
        }
    }

    public function create()
    {
        $this->requireLogin();
        $currentUser = $this->currentUser();
        $catModel = new Category();
        $allCategories = $catModel->allOrdered();

        // 过滤出用户有发帖权限的版块
        $categories = [];
        foreach ($allCategories as $c) {
            if (\app\Helpers\Permission::canPost((int)$c['id'], (int)$currentUser['id'])) {
                $categories[] = $c;
            }
        }

        // 如果没有有权限的版块，提示无权限
        if (empty($categories)) {
            $this->view('post/create', [
                'categories' => [], 'error' => \app\Helpers\I18n::get('post.no_permission_any'),
                'form' => ['title' => '', 'content' => '', 'category_id' => 0],
                'hasPermission' => false,
            ]);
            return;
        }

        // 检查指定分类的发帖权限（如果有）
        $selectedCategoryId = (int)($_GET['category_id'] ?? 0);
        if ($selectedCategoryId > 0) {
            if (!\app\Helpers\Permission::canPost($selectedCategoryId, (int)$currentUser['id'])) {
                $this->view('post/create', [
                    'categories' => $categories, 'error' => \app\Helpers\I18n::get('post.no_permission'),
                    'form' => ['title' => '', 'content' => '', 'category_id' => $selectedCategoryId],
                    'hasPermission' => false,
                ]);
                return;
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            // 防刷帖：每个 IP 每 10 分钟最多 5 个新主题（与回帖/私信等限流对齐；阈值后台可配）
            RateLimiter::hitConfig('create_thread', 5, 600);
            $title = trim($_POST['title'] ?? '');
            $rawContent = trim($_POST['content'] ?? '');
            // 解码编辑器提交的 base64 编码内容
            $content = \app\Helpers\Content::decode($rawContent);
            // 内容长度校验：按【可见字符数】限制，不再把 HTML 标签/内联样式计入
            // 1) 可见字符上限 60000（去标签后，粘贴富文本不再被标签开销误伤）
            // 2) HTML 源码字节兜底 500KB，防超长原始内容溢出数据库 MEDIUMTEXT
            $visibleLen = \mb_strlen(\strip_tags($content));
            if ($visibleLen > 60000 || \strlen($content) > 500000) {
                $this->view('post/create', [
                    'categories' => $categories, 'error' => \app\Helpers\I18n::get('post.content_too_long'),
                    'form' => ['title' => $title, 'content' => $content, 'category_id' => $categoryId],
                ]);
                return;
            }
            $categoryId = (int)($_POST['category_id'] ?? 0);

            if (!$title || !$content) {
                $this->view('post/create', [
                    'categories' => $categories, 'error' => \app\Helpers\I18n::get('post.title_content_required'),
                    'form' => ['title' => $title, 'content' => $content, 'category_id' => $categoryId],
                ]);
                return;
            }

            // 验证分类是否存在
            $catExists = (new \app\Models\Category())->find($categoryId);
            if (!$catExists) {
                $this->view('post/create', [
                    'categories' => $categories, 'error' => \app\Helpers\I18n::get('post.select_valid_category'),
                    'form' => ['title' => $title, 'content' => $content, 'category_id' => $categoryId],
                ]);
                return;
            }

            // 二次校验发帖权限（防 F12 篡改 category_id）
            if (!\app\Helpers\Permission::canPost($categoryId, (int)$currentUser['id'])) {
                $this->view('post/create', [
                    'categories' => $categories, 'error' => \app\Helpers\I18n::get('post.no_permission'),
                    'form' => ['title' => $title, 'content' => $content, 'category_id' => $categoryId],
                ]);
                return;
            }

            // 二次校验附件上传权限（仅发帖携带附件时拦截）
            if (!empty($_FILES['attachments']['name'][0])
                && !\app\Helpers\Permission::canAttach($categoryId, (int)$currentUser['id'])) {
                $this->view('post/create', [
                    'categories' => $categories, 'error' => \app\Helpers\I18n::get('post.no_perm_attach'),
                    'form' => ['title' => $title, 'content' => $content, 'category_id' => $categoryId],
                ]);
                return;
            }

            $db = \app\Core\Database::getInstance();
            $now = date('Y-m-d H:i:s');
            $threadModel = new Thread();

            try {
                set_time_limit(0); // 长帖内容处理可能耗时较长

                $db->begin();

                $threadId = $threadModel->insert([
                    'category_id' => $categoryId,
                    'user_id' => (int)$currentUser['id'],
                    'title' => $title,
                    'content' => $content,
                    'created_at' => $now,
                    'last_reply_at' => $now,
                    'reply_to_view' => (int)(isset($_POST['reply_to_view']) ? 1 : 0),
                ]);

                Tag::saveForThread($threadId, trim($_POST['tags'] ?? ''));
                (new \app\Models\User())->incrementPostCount((int)$currentUser['id']);
                Points::award((int)$currentUser['id'], (int)Settings::get('points_thread_create', '5'), '发布主题', $threadId, 'thread');

                if (!empty($_FILES['attachments']['name'][0])) {
                    Settings::handleAttachments($threadId, null);
                }

                $db->commit();

                Search::indexThread($threadId);

                \app\Helpers\Plugin::hook('thread_create_after', [
                    'thread_id' => $threadId,
                    'user_id' => (int)$currentUser['id'],
                    'category_id' => $categoryId,
                    'title' => $title,
                ]);

                $this->redirect('/thread/' . $threadId);
            } catch (\Exception $e) {
                $db->rollback();
                \error_log('PostController::create error: ' . $e->getMessage());
                // 不暴露异常消息给用户，防泄露表结构
                $this->view('post/create', [
                    'categories' => $categories, 'error' => \app\Helpers\I18n::get('post.publish_failed'),
                    'form' => ['title' => $title, 'content' => $content, 'category_id' => $categoryId],
                ]);
                return;
            }
        }

        $this->view('post/create', [
            'categories' => $categories, 'error' => null,
            'form' => ['title' => '', 'content' => '', 'category_id' => (int)($_GET['category_id'] ?? 0)],
            'needsEditor' => true,
        ]);
    }

    public function edit($id)
    {
        $this->requireLogin();
        $postModel = new Post();
        $postId = (int)$id;
        $post = $postModel->find($postId);
        if (!$post) { $this->redirect('/'); }

        $currentUser = $this->currentUser();
        $isOwner = (int)$post['user_id'] === (int)$currentUser['id'];
        if (!$this->isAdmin() && !$isOwner) { $this->redirect('/'); }

        $threadModel = new Thread();
        $thread = $threadModel->find((int)$post['thread_id']);
        if (!$thread) { $this->redirect('/'); }

        // 归档守卫：非管理员不可编辑归档帖的回复（管理员豁免，服务端强制）
        if (!empty($thread['is_archived']) && !$this->isAdmin()) {
            $this->redirect('/thread/' . (int)$thread['id'] . '?msg=archived_no_reply');
        }

        $db = \app\Core\Database::getInstance();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            // 前台 admin 操作：管理员编辑他人回帖需 30 分钟二次验证（本人编辑不受限）
            if ($this->isAdmin() && !$isOwner) {
                $this->requireAdminVerified();
            }
            $rawContent = trim($_POST['content'] ?? '');
            $content = \app\Helpers\Content::decode($rawContent);
            if (!$content) {
                $this->view('post/edit', ['post' => $post, 'thread' => $thread, 'error' => \app\Helpers\I18n::get('post.content_required')]);
                return;
            }
            $postModel->update($postId, ['content' => $content]);
            \app\Helpers\Search::indexThread((int)$thread['id']);
            \app\Helpers\Plugin::hook('post_edit_after', [
                'post_id' => $postId,
                'thread_id' => (int)$thread['id'],
                'user_id' => (int)$currentUser['id'],
            ]);

            // 二次校验附件上传权限（编辑新增附件时拦截，用帖子所属版块判定）
            if (!empty($_FILES['reply_attachments']['name'][0])
                && !\app\Helpers\Permission::canAttach((int)$thread['category_id'], (int)$currentUser['id'])) {
                $this->view('post/edit', ['post' => $post, 'thread' => $thread, 'error' => \app\Helpers\I18n::get('post.no_perm_attach')]);
                return;
            }

            if (!empty($_FILES['reply_attachments']['name'][0])) {
                Settings::handleAttachments((int)$thread['id'], $postId);
            }

            // 处理删除选中附件（校验所属权，防 IDOR）
            if (!empty($_POST['delete_attachments']) && is_array($_POST['delete_attachments'])) {
                foreach ($_POST['delete_attachments'] as $attId) {
                    $att = $db->fetchOne('SELECT id, thread_id, post_id FROM attachments WHERE id = :id', [':id' => (int)$attId]);
                    if ($att && (int)$att['thread_id'] === (int)$thread['id'] && (int)$att['post_id'] === $postId) {
                        \app\Helpers\Settings::deleteAttachment((int)$attId);
                    }
                }
            }

            $this->redirect('/thread/' . $thread['id'] . '?msg=post_updated');
        }

        $attachments = $db->fetchAll('SELECT * FROM attachments WHERE thread_id = :tid AND post_id = :pid', [':tid' => (int)$thread['id'], ':pid' => $postId]);

        $this->view('post/edit', ['post' => $post, 'thread' => $thread, 'attachments' => $attachments, 'error' => null, 'needsEditor' => true]);
    }
}
