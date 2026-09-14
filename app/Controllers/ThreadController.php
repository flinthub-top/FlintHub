<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 帖子前台控制器 — 帖子展示、发帖、编辑、删除、投票
 * @file app/Controllers/ThreadController.php
 * @package app\Controllers
 */

namespace app\Controllers;

use app\Core\Controller;
use app\Models\{Thread, Post};
use app\Helpers\Csrf;
use app\Helpers\Vote;
use app\Helpers\Points;
use app\Helpers\Settings;
use app\Helpers\Tag;
use app\Helpers\Upload;
use app\Helpers\Search;
use app\Helpers\RateLimiter;

class ThreadController extends Controller
{
    /**
     * 博客模式(blog)下论坛被关闭：帖子详情/编辑等直连重定向首页
     */
    public function __construct()
    {
        if (\app\Helpers\Settings::get('site_mode', 'portal') === 'blog') {
            $this->redirect('/');
        }
    }

    public function show($id)
    {
        $threadModel = new Thread();
        $postModel = new Post();

        $threadId = (int)$id;
        $thread = $threadModel->getById($threadId);
        if (!$thread) { $this->redirect('/?msg=not_found'); }

        // 浏览权限检查
        $currentUser = $this->currentUser();
        $userId = $currentUser ? (int)$currentUser['id'] : 0;
        if (!\app\Helpers\Permission::canView((int)$thread['category_id'], $userId ?: null)) {
            $this->view('thread/show', [
                'thread' => $thread, 'posts' => [], 'page' => 1,
                'totalPages' => 1, 'totalPosts' => 0,
                'postVotes' => [], 'threadVote' => ['likes' => 0, 'user_vote' => 0],
                'attachments' => [], 'postAttachments' => [],
                'replyToView' => false, 'hasReplied' => false, 'perPage' => 20,
                'threadTags' => [], '_renderedContent' => '',
                'error' => \app\Helpers\I18n::get('thread.no_permission_view'), 'canReply' => false,
            ]);
            return;
        }

        // 浏览量去重：同一 Session 30 分钟内不重复计数
        $viewedKey = '_viewed_thread_' . $threadId;
        if (empty($_SESSION[$viewedKey]) || $_SESSION[$viewedKey] < time()) {
            $threadModel->incrementView($threadId);
            $_SESSION[$viewedKey] = time() + 1800; // 30 分钟冷却
        }

        // 回复权限检查
        $canReply = $currentUser ? \app\Helpers\Permission::canReply((int)$thread['category_id'], (int)$currentUser['id']) : false;

        // 归档状态：is_archived 标记 + 读取时惰性标记（每日脚本未跑时即时生效兜底）
        $isArchived = (int)($thread['is_archived'] ?? 0) === 1;
        $archiveAfterDays = (int)\app\Helpers\Settings::get('archive_after_days', '365');
        if (!$isArchived && $archiveAfterDays > 0 && !empty($thread['created_at'])) {
            $threadTs = strtotime((string)$thread['created_at']);
            if ($threadTs > 0 && $threadTs < (time() - $archiveAfterDays * 86400)) {
                try {
                    \app\SplitDB\Schema::mainIndexDb()
                        ->prepare('UPDATE topic_index SET is_archived = 1 WHERE id = :id AND is_archived = 0')
                        ->execute([':id' => $threadId]);
                    $isArchived = true;
                } catch (\Throwable $e) {
                    \error_log('archive lazy mark error: ' . $e->getMessage());
                }
            }
        }
        $archiveDays = 0;
        if ($isArchived && !empty($thread['created_at'])) {
            $threadTs = strtotime((string)$thread['created_at']);
            if ($threadTs > 0) $archiveDays = max(0, (int)floor((time() - $threadTs) / 86400));
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $this->requireLogin();
            if (!$canReply) { $this->redirect('/thread/' . $threadId); }
            // 归档守卫：非管理员不可回复归档帖（管理员豁免，服务端强制）
            if ($isArchived && !$this->isAdmin()) {
                $this->redirect('/thread/' . $threadId . '?msg=archived_no_reply');
            }
            // 回帖频率限制：每 30 秒最多 3 次（后台「系统设置→限流设置」可配）
            RateLimiter::hitConfig('reply', 3, 30);
            // 与发帖/编辑一致：编辑器提交的是 base64，必须先解码为 HTML 再入库
            $content = \app\Helpers\Content::decode(trim($_POST['content'] ?? ''));
            if ($content !== '') {
                $currentUser = $this->currentUser();
                $now = date('Y-m-d H:i:s');
                $db = \app\Core\Database::getInstance();

                // 回帖携带附件时校验附件上传权限（用帖子所属版块判定）
                if (!empty($_FILES['reply_attachments']['name'][0])
                    && !\app\Helpers\Permission::canAttach((int)$thread['category_id'], (int)$currentUser['id'])) {
                    $this->redirect('/thread/' . $threadId . '?error=' . \urlencode(\app\Helpers\I18n::get('thread.no_perm_attach')));
                }

                try {
                    $db->begin();

                    $postId = (new Post())->insert([
                        'thread_id' => $threadId, 'user_id' => (int)$currentUser['id'],
                        'content' => $content, 'created_at' => $now,
                    ]);

                    $threadModel->incrementReplyCount($threadId, $now);
                    Points::award((int)$currentUser['id'], (int)Settings::get('points_post_create', '2'), '回复帖子', $threadId, 'reply');

                    Points::markReplied($threadId, (int)$currentUser['id']);

                    if (!empty($_FILES['reply_attachments']['name'][0])) {
                        $fileCount = \count($_FILES['reply_attachments']['name']);
                        $maxAttach = (int)Settings::get('attachment_max_per_post', '10');
                        if ($fileCount > $maxAttach) {
                            $errorMsg = \app\Helpers\I18n::get('thread.max_attachments', ['count' => $maxAttach]);
                            $db->rollback();
                            $this->redirect('/thread/' . $threadId . '?error=' . \urlencode($errorMsg));
                        }
                        foreach ($_FILES['reply_attachments']['name'] as $key => $name) {
                            if (($_FILES['reply_attachments']['error'][$key] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                                $file = [
                                    'name' => $_FILES['reply_attachments']['name'][$key] ?? '',
                                    'type' => $_FILES['reply_attachments']['type'][$key] ?? '',
                                    'tmp_name' => $_FILES['reply_attachments']['tmp_name'][$key] ?? '',
                                    'error' => $_FILES['reply_attachments']['error'][$key] ?? UPLOAD_ERR_NO_FILE,
                                    'size' => $_FILES['reply_attachments']['size'][$key] ?? 0
                                ];
                                $upload = Upload::file($file);
                                if (!empty($upload['success'])) {
                                    $db->query('INSERT INTO attachments (post_id, thread_id, filename, original_name, file_size, mime_type) VALUES (:pid, :tid, :filename, :original_name, :file_size, :mime_type)',
                                        [':pid' => $postId, ':tid' => $threadId, ':filename' => $upload['filename'],
                                         ':original_name' => $upload['original_name'], ':file_size' => $upload['size'], ':mime_type' => $upload['type']]);
                                }
                            }
                        }
                    }

                    \app\Helpers\Plugin::hook('post_create_after', [
                        'post_id' => $postId,
                        'thread_id' => $threadId,
                        'user_id' => (int)$currentUser['id'],
                    ]);

                    // 通知内置化：有人回复帖子时通知楼主（自己回自己不通知；同主题未读回复合并计数）
                    \app\Helpers\Notification::notifyReplyAggregated($threadId, (int)$currentUser['id'], $postId);

                    // 通知内置化：引用回复通知（向被引用人发送，排除自己）
                    $quotedUids = trim((string)($_POST['quoted_uids'] ?? ''));
                    if ($quotedUids !== '') {
                        \app\Helpers\Notification::notifyQuote(
                            array_filter(array_map('intval', explode(',', $quotedUids)), fn($v) => $v > 0),
                            (int)$currentUser['id'],
                            $threadId,
                            $postId
                        );
                    }

                    $db->commit();
                } catch (\Exception $e) {
                    $db->rollback();
                    \error_log('ThreadController::show reply error: ' . $e->getMessage());
                }

                $totalPosts2 = $postModel->countByThread($threadId);
                $lastPage = (int)ceil(max(1, $totalPosts2) / max(5, (int)\app\Helpers\Settings::get('posts_per_page', 20)));
                $this->redirect('/thread/' . $threadId . '?page=' . $lastPage);
            }
        }

        $replyToView = !empty($thread['reply_to_view']);
        $hasReplied = false;
        if ($replyToView) {
            $currentUser = $this->currentUser();
            if ($currentUser) {
                $viewed = $this->checkViewedReply($threadId, (int)$currentUser['id']);
                $hasReplied = $viewed || $thread['user_id'] == $currentUser['id'];
            }
        }

        $perPage = max(5, (int)Settings::get('posts_per_page', 20));
        $totalPosts = $postModel->countByThread($threadId);
        $totalPages = max(1, ceil($totalPosts / $perPage));

        // 页码钳制到真实 totalPages（不再硬编码 1000 页上限）；非法页码回落到最后一页
        $page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
        $offset = ($page - 1) * $perPage;
        $posts = $postModel->getByThread($threadId, $offset, $perPage);

        $postIds = array_map(function ($p) { return $p['id']; }, $posts);

        // 投票数据：post + thread 一次合并取回（Vote::getCountsMulti 跨表 UNION ALL，4 次查询 → 2 次）
        $voteData = Vote::getCountsMulti(['post' => $postIds, 'thread' => [$threadId]]);
        $postVotes = $voteData['post'] ?? [];
        $threadVote = $voteData['thread'][$threadId] ?? ['likes' => 0, 'user_vote' => 0];

        // 一次查询取回主楼 + 回帖附件（替代原先两次独立查询，减少开销）
        $attGroup = \app\Helpers\Settings::getThreadAttachmentsGrouped($threadId);
        $atts = $attGroup['thread'];
        $postAttachments = [];
        foreach ($attGroup['posts'] as $pa) {
            $pid = (int)$pa['post_id'];
            if (!isset($postAttachments[$pid])) $postAttachments[$pid] = [];
            $postAttachments[$pid][] = $pa;
        }

        // 附件可见性：该组无 can_attach 权限时，前台不显示附件（下载接口也会拦截）
        if (!\app\Helpers\Permission::canAttach((int)$thread['category_id'], $userId ?: null)) {
            $atts = [];
            $postAttachments = [];
        }

        // 预处理模板所需数据
        $threadTags = \app\Helpers\Tag::getByThread($threadId);
        // 详情页主帖正文始终服务端完整直出；折叠只由 thread/show 的 CSS + Alpine 控制。
        // 注意：reply_to_view 未授权时，视图只输出锁定提示，不输出此正文。
        $threadContentRaw = \app\Helpers\Content::decode($thread['content'] ?? '');
        $renderedContent = \app\Helpers\Content::formatPostContent($threadContentRaw);
        foreach ($posts as &$p) {
            $p['_rendered'] = \app\Helpers\Content::formatPostContent(\app\Helpers\Content::decode($p['content'] ?? ''));
        }
        unset($p);

        // 【安全】回复可见：只隐藏主帖正文，回复列表正常显示（回复本身是公开内容，不应连带锁定）
        if ($replyToView && !$hasReplied) {
            $hiddenMsg = '<div class="locked-content" style="padding:20px;background:#f9f9f9;border:1px solid #eee;border-radius:8px;text-align:center;color:#999;font-size:14px;">' . \app\Helpers\I18n::get('thread.content_hidden') . '</div>';
            $renderedContent = $hiddenMsg;
        }

        $this->view('thread/show', [
            'thread' => $thread, 'posts' => $posts, 'page' => $page,
            'totalPages' => $totalPages, 'totalPosts' => $totalPosts,
            'postVotes' => $postVotes, 'threadVote' => $threadVote,
            'attachments' => $atts, 'postAttachments' => $postAttachments,
            'replyToView' => $replyToView, 'hasReplied' => $hasReplied, 'perPage' => $perPage,
            'threadTags' => $threadTags, '_renderedContent' => $renderedContent,
            'canReply' => $canReply,
            // 归档状态（视图：归档横幅 + 非管理员隐藏回复框）
            'isArchived' => $isArchived,
            'archiveDays' => $archiveDays,
            'categories' => (new \app\Models\Category())->allOrdered(),
            'needsEditor' => true,
        ]);
    }

    public function edit($id)
    {
        $this->requireLogin();
        $threadModel = new Thread();
        $threadId = (int)$id;
        $thread = $threadModel->find($threadId);
        if (!$thread) { $this->redirect('/'); }

        $currentUser = $this->currentUser();
        if (!$this->isAdmin() && (int)$thread['user_id'] !== (int)$currentUser['id']) { $this->redirect('/'); }

        // 归档守卫：非管理员不可编辑归档帖（管理员豁免，服务端强制）
        if (!empty($thread['is_archived']) && !$this->isAdmin()) {
            $this->redirect('/thread/' . $threadId . '?msg=archived_no_reply');
        }

        // 版块访问权限校验
        $userId = $currentUser ? (int)$currentUser['id'] : 0;
        if (!\app\Helpers\Permission::canView((int)$thread['category_id'], $userId ?: null)) {
            $this->redirect('/');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            // 前台 admin 操作：管理员编辑他人主题需 30 分钟二次验证（本人编辑不受限）
            if ($this->isAdmin() && (int)$thread['user_id'] !== (int)$currentUser['id']) {
                $this->requireAdminVerified();
            }
            $title = trim($_POST['title'] ?? '');
            $rawContent = trim($_POST['content'] ?? '');
            $content = \app\Helpers\Content::decode($rawContent);
            $categoryId = (int)($_POST['category_id'] ?? 0);
            // 标题颜色仅管理员可改，且只接受预设色板白名单；非法/缺失时保留原色，避免误清后台设置的颜色
            $color = $this->isAdmin()
                ? (in_array(trim($_POST['color'] ?? ''), Thread::PRESET_TITLE_COLORS, true) ? trim($_POST['color']) : ($thread['color'] ?? ''))
                : ($thread['color'] ?? '');
            $replyToView = isset($_POST['reply_to_view']) ? 1 : 0;

            if (!$title || !$content) {
                $this->view('thread/edit', ['thread' => $thread, 'error' => \app\Helpers\I18n::get('post.title_content_required')]);
                return;
            }

            // 跨版块移动校验：如果改了版块，检查目标版块的发帖权限
            if ($categoryId !== (int)$thread['category_id']) {
                if (!\app\Helpers\Permission::canPost($categoryId, (int)$currentUser['id'])) {
                    $this->view('thread/edit', ['thread' => $thread, 'error' => \app\Helpers\I18n::get('thread.no_perm_move')]);
                    return;
                }
            }

            // 二次校验附件上传权限（编辑新增附件时拦截，用提交的目标版块判定）
            if (!empty($_FILES['attachments']['name'][0])
                && !\app\Helpers\Permission::canAttach($categoryId, (int)$currentUser['id'])) {
                $this->view('thread/edit', ['thread' => $thread, 'error' => \app\Helpers\I18n::get('thread.no_perm_attach')]);
                return;
            }

            Tag::saveForThread($threadId, trim($_POST['tags'] ?? ''));

            $threadModel->update($threadId, [
                'title' => $title, 'content' => $content, 'category_id' => $categoryId,
                'color' => $color, 'reply_to_view' => $replyToView,
            ]);
            \app\Helpers\Plugin::hook('thread_edit_after', [
                'thread_id' => $threadId,
                'user_id' => (int)$currentUser['id'],
            ]);

            if (!empty($_FILES['attachments']['name'][0])) {
                Settings::handleAttachments($threadId, null);
            }

            // 处理删除选中附件（校验所属权，防 IDOR）
            if (!empty($_POST['delete_attachments']) && is_array($_POST['delete_attachments'])) {
                $db = \app\Core\Database::getInstance();
                foreach ($_POST['delete_attachments'] as $attId) {
                    $att = $db->fetchOne('SELECT id, thread_id FROM attachments WHERE id = :id', [':id' => (int)$attId]);
                    if ($att && (int)$att['thread_id'] === $threadId) {
                        \app\Helpers\Settings::deleteAttachment((int)$attId);
                    }
                }
            }

            Search::indexThread($threadId);

            $this->redirect('/thread/' . $threadId . '?msg=thread_updated');
        }

        $catModel = new \app\Models\Category();
        $categories = $catModel->allOrdered();
        $threadTags = Tag::getByThread($threadId);
        // 编辑页 tags 输入框回显：模板读 tagsStr（逗号分隔），控制器须传同名键（此前只传 threadTags 导致回显为空）
        $tagsStr = \implode(', ', \array_column($threadTags, 'name'));

        // 获取该主题现有附件（不含回帖附件）
        $attachments = \app\Helpers\Settings::getThreadAttachments($threadId);

        $this->view('thread/edit', ['thread' => $thread, 'categories' => $categories, 'threadTags' => $threadTags, 'tagsStr' => $tagsStr, 'attachments' => $attachments, 'error' => null, 'needsEditor' => true, 'isAdmin' => $this->isAdmin()]);
    }

    public function delete($id)
    {
        $this->requireLogin();
        $currentUser = $this->currentUser();
        Csrf::verifyOrDie($_POST['csrf'] ?? '');

        $threadModel = new Thread();
        $thread = $threadModel->find((int)$id);
        if (!$thread) { $this->redirect('/'); }

        $postModel = new Post();
        $postId = (int)($_POST['post_id'] ?? 0);
        if ($postId) {
            $post = $postModel->find($postId);
            if (!$post) { $this->redirect('/thread/' . (int)$id); }
            // 【安全】校验 post_id 是否真的属于当前 URL 的 thread_id，防跨主题越权删除
            if ((int)$post['thread_id'] !== (int)$id) {
                $this->redirect('/thread/' . (int)$id);
            }
            // 复合鉴权：管理员 || 回帖作者本人 || 主题楼主（有权删除自己主题下的违规回复）
            $isOp = (int)$thread['user_id'] === (int)$currentUser['id'];
            if (!$this->isAdmin() && (int)$post['user_id'] !== (int)$currentUser['id'] && !$isOp) {
                $this->redirect('/thread/' . (int)$id);
            }
            // 前台 admin 操作：管理员删除他人回帖需 30 分钟二次验证（本人/楼主删除不受限）
            if ($this->isAdmin() && (int)$post['user_id'] !== (int)$currentUser['id'] && !$isOp) {
                $this->requireAdminVerified();
            }
            $postModel->softDelete($postId);
            // 软删回复后同步递减主题回复数（防 reply_count 虚高）
            $threadModel->decrementReplyCount((int)$id);
            // 删除回帖成功后一次性消费 CSRF Token，防被窃取的 Token 无限重放
            Csrf::consume();
            $this->redirect('/thread/' . (int)$id);
        }

        if (!$this->isAdmin() && (int)$thread['user_id'] !== (int)$currentUser['id']) { $this->redirect('/'); }
        // 前台 admin 操作：管理员删除他人主题需 30 分钟二次验证（本人删除不受限）
        if ($this->isAdmin() && (int)$thread['user_id'] !== (int)$currentUser['id']) {
            $this->requireAdminVerified();
        }

        // 版块访问权限校验
        $userId = $currentUser ? (int)$currentUser['id'] : 0;
        if (!\app\Helpers\Permission::canView((int)$thread['category_id'], $userId ?: null)) {
            $this->redirect('/');
        }

        $threadModel->softDelete((int)$id);
        // 软删主题后同步递减作者发帖数（与 getStats 口径一致；仅未删状态递减一次）
        if (empty($thread['deleted_at'])) {
            (new \app\Models\User())->decrementPostCount((int)$thread['user_id']);
        }
        \app\Helpers\Search::removeFromIndex('thread', (int)$id);
        // 删除主题成功后一次性消费 CSRF Token，防被窃取的 Token 无限重放
        Csrf::consume();
        $this->redirect('/');
    }

    private function checkViewedReply($threadId, $userId)
    {
        return \app\Helpers\Points::canViewContent($threadId, $userId);
    }
}
