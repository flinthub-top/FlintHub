<?php
/**
 * 楼中楼插件 前台控制器 — 楼中楼列表片段 / 提交回复（含@校验）/ 删除（权限校验）
 * @file plugins/floor_reply/FrontController.php
 * @package Plugin\FloorReply
 * @version 1.0.0
 */

namespace Plugin\FloorReply;

use app\Core\Controller;
use app\Helpers\Auth;
use app\Helpers\Csrf;

class FrontController extends Controller
{
    /**
     * 动态片段响应禁用缓存
     * 关键：楼中楼列表靠 htmx GET 片段局部加载，若响应不带防缓存头，
     * 浏览器会把"未登录"版旧片段缓存住 → 登录后回到详情页仍显示"请登录"，直到强刷。
     * 此头保证登录态/删除态变更后片段始终从服务端新鲜拉取。
     */
    private function noCache(): void
    {
        \header('Cache-Control: no-store, no-cache, must-revalidate');
        \header('Pragma: no-cache');
        \header('Expires: 0');
    }

    /** GET /floor-reply/list/{id} — 楼中楼列表片段（htmx 局部加载）
     * 无 page 参数 = 自动展开首屏（前 5 条预览）；带 page = 分页（每页 10 条） */
    public function list(int $id)
    {
        $this->noCache();
        $postId = (int)$id;
        if ($postId <= 0) {
            return $this->renderError(\app\Helpers\I18n::get('plugin.floor_reply.err_not_found'));
        }

        $post = (new \app\Models\Post())->find($postId);
        if (!$post) {
            return $this->renderError(\app\Helpers\I18n::get('plugin.floor_reply.err_not_found'));
        }
        $threadId = (int)$post['thread_id'];

        $total = \Plugin\FloorReply\Plugin::countByPost($postId);
        // 每页 5 条；自动展开即第 1 页，分页器按需切换
        $perPage = 5;
        $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 0;
        $page = max(1, (int)($_GET['page'] ?? 1));
        if ($totalPages > 0) {
            $page = min($page, $totalPages);
        }
        $offset = ($page - 1) * $perPage;

        $replies = \Plugin\FloorReply\Plugin::getByPost($postId, $perPage, $offset);
        $this->renderList($postId, $threadId, $replies, '', $page, $total, $perPage, $totalPages > 1);
    }

    /** POST /floor-reply/reply — 提交楼中楼回复（@解析：用户不存在时提示） */
    public function reply()
    {
        $this->noCache();
        if (!Auth::isLoggedIn()) {
            return $this->renderError(\app\Helpers\I18n::get('plugin.floor_reply.login_to_reply'));
        }
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        // 积分频控：与核心回帖同阈值（默认每 30 秒 3 次，后台「限流设置」可配），
        // 防止楼中楼变刷分漏洞（Points::award 联动后按次发分）。超限输出 429 并终止。
        \app\Helpers\RateLimiter::hitConfig('reply', 3, 30);

        $postId = (int)($_POST['post_id'] ?? 0);
        $content = trim((string)($_POST['content'] ?? ''));

        $post = (new \app\Models\Post())->find($postId);
        if (!$post) {
            return $this->renderError(\app\Helpers\I18n::get('plugin.floor_reply.err_not_found'));
        }
        $threadId = (int)$post['thread_id'];

        // 内容校验（纯文本；入库前 strip_tags 兜底防标签，长度限制 500）
        if ($content === '') {
            return $this->renderCurrentPage($postId, $threadId, \app\Helpers\I18n::get('plugin.floor_reply.err_empty'));
        }
        if (mb_strlen($content) > 500) {
            return $this->renderCurrentPage($postId, $threadId, \app\Helpers\I18n::get('plugin.floor_reply.err_too_long'));
        }

        // @ 解析：所有 @用户名 必须存在，否则提示用户
        $mentions = \Plugin\FloorReply\Plugin::parseMentions($content);
        if (!empty($mentions['missing'])) {
            $badName = $mentions['missing'][0];
            return $this->renderCurrentPage($postId, $threadId,
                \app\Helpers\I18n::get('plugin.floor_reply.err_user_not_found', ['name' => $badName]));
        }

        // 取第一个被@的用户 id（无 @ 则为 0）
        $mentionUserId = 0;
        if (!empty($mentions['exists'])) {
            $mentionUserId = (int)reset($mentions['exists']);
        }

        $safeContent = strip_tags($content);
        $uid = (int)Auth::getCurrentUser()['id'];
        \Plugin\FloorReply\Plugin::addReply($postId, $threadId, $uid, $safeContent, $mentionUserId);

        // —— 核心计数体系轻量联动（与 ThreadController::show POST 回帖同口径）——
        // 回复计数 + 最后回复时间（帖子可重新顶起排序）；删除时由 delete() 对称扣回
        try { (new \app\Models\Thread())->incrementReplyCount($threadId, date('Y-m-d H:i:s')); } catch (\Throwable $e) { \error_log('floor_reply incrementReplyCount error: ' . $e->getMessage()); }
        // 全站回复总数
        try { \app\Helpers\Settings::runtimeIncr('total_posts'); } catch (\Throwable $e) { \error_log('floor_reply runtimeIncr error: ' . $e->getMessage()); }
        // 回复可见标记（幂等）：用户经楼中楼回复后也能解锁主帖隐藏内容
        try { \app\Helpers\Points::markReplied($threadId, $uid); } catch (\Throwable $e) { \error_log('floor_reply markReplied error: ' . $e->getMessage()); }
        // 基础积分奖励（默认 +2，后台 points_post_create 可配）
        try { \app\Helpers\Points::award($uid, (int)\app\Helpers\Settings::get('points_post_create', '2'), '回复帖子', $threadId, 'reply'); } catch (\Throwable $e) { \error_log('floor_reply award error: ' . $e->getMessage()); }

        // 楼中楼写入独立库，不触发核心 Post::insert → 手动失效游客页面缓存，保证内容一致性
        try { \app\Helpers\PageCache::invalidate(); } catch (\Throwable $e) {}

        // 重新渲染列表片段（跳到含最新回复的末页）
        $total = \Plugin\FloorReply\Plugin::countByPost($postId);
        $perPage = 5;
        $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 0;
        $page = max(1, $totalPages);
        $replies = \Plugin\FloorReply\Plugin::getByPost($postId, $perPage, ($page - 1) * $perPage);
        $this->renderList($postId, $threadId, $replies, '', $page, $total, $perPage, $totalPages > 1);
    }

    /** POST /floor-reply/delete — 删除楼中楼回复（作者本人 / 管理员 / 楼主） */
    public function delete()
    {
        $this->noCache();
        if (!Auth::isLoggedIn()) {
            return $this->renderError(\app\Helpers\I18n::get('plugin.floor_reply.login_to_reply'));
        }
        Csrf::verifyOrDie($_POST['csrf'] ?? '');

        $id = (int)($_POST['id'] ?? 0);
        $row = \Plugin\FloorReply\Plugin::getReply($id);
        if (!$row) {
            return $this->renderError(\app\Helpers\I18n::get('plugin.floor_reply.err_not_found'));
        }

        $user = Auth::getCurrentUser();
        $uid = (int)$user['id'];
        $isAuthor = (int)$row['user_id'] === $uid;
        $isAdmin = Auth::isAdmin();
        $isThreadOwner = false;
        if (!$isAuthor && !$isAdmin) {
            // 楼主判定：读帖子作者（核心 Model 实例方法）
            $thread = (new \app\Models\Thread())->find((int)$row['thread_id']);
            $isThreadOwner = $thread && (int)$thread['user_id'] === $uid;
        }
        if (!$isAuthor && !$isAdmin && !$isThreadOwner) {
            return $this->renderError(\app\Helpers\I18n::get('plugin.floor_reply.err_perm'));
        }

        \Plugin\FloorReply\Plugin::deleteReply($id);
        // —— 核心计数体系对称扣回（与 reply() 的 +1 配对；存量旧数据未加过计数，MAX 防负保护不会变负）——
        try { (new \app\Models\Thread())->decrementReplyCount((int)$row['thread_id']); } catch (\Throwable $e) { \error_log('floor_reply decrementReplyCount error: ' . $e->getMessage()); }
        try { \app\Helpers\Settings::runtimeDecr('total_posts'); } catch (\Throwable $e) { \error_log('floor_reply runtimeDecr error: ' . $e->getMessage()); }
        // 写操作触发游客缓存失效（内容一致性）
        try { \app\Helpers\PageCache::invalidate(); } catch (\Throwable $e) {}
        // 删除后保持当前页（超出自动回落最后一页）
        $this->renderCurrentPage((int)$row['post_id'], (int)$row['thread_id'], '');
    }

    // ============================================================
    // 渲染辅助
    // ============================================================

    /** 渲染楼中楼列表片段（viewRaw 无布局，htmx 局部替换用；携带分页上下文） */
    private function renderList(int $postId, int $threadId, array $replies, string $error,
                                int $page = 1, int $total = 0, int $perPage = 10, bool $hasPage = false): void
    {
        // 楼主 id：视图删除按钮可见性需要（服务端 delete() 仍会严格校验）
        $threadOwnerId = 0;
        if ($threadId > 0) {
            try {
                $thread = (new \app\Models\Thread())->find($threadId);
                $threadOwnerId = $thread ? (int)$thread['user_id'] : 0;
            } catch (\Throwable $e) {
                $threadOwnerId = 0;
            }
        }
        $totalPages = $total > 0 ? (int)ceil($total / max(1, $perPage)) : 0;
        $this->viewRaw('plugins/floor_reply/list', [
            'postId'        => $postId,
            'threadId'      => $threadId,
            'threadOwnerId' => $threadOwnerId,
            'replies'       => $replies,
            'error'         => $error,
            'page'          => $page,
            'total'         => $total,
            'perPage'       => $perPage,
            'totalPages'    => $totalPages,
            'hasPage'       => $hasPage,
            'isLoggedIn'    => Auth::isLoggedIn(),
            'isAdmin'       => Auth::isAdmin(),
            'currentUser'   => Auth::getCurrentUser(),
            'csrfToken'     => Csrf::token(),
        ]);
    }

    /** 按当前请求的 page（POST 携带）重新渲染该楼层列表，保留分页上下文（出错/删除后回显用） */
    private function renderCurrentPage(int $postId, int $threadId, string $error): void
    {
        $total = \Plugin\FloorReply\Plugin::countByPost($postId);
        $perPage = 5;
        $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 0;
        $page = $totalPages > 0 ? min(max(1, (int)($_POST['page'] ?? 1)), $totalPages) : 1;
        $replies = \Plugin\FloorReply\Plugin::getByPost($postId, $perPage, ($page - 1) * $perPage);
        $this->renderList($postId, $threadId, $replies, $error, $page, $total, $perPage, $totalPages > 1);
    }

    /** 错误提示片段（htmx 局部替换用，保持楼层锚点可见） */
    private function renderError(string $msg): void
    {
        $this->viewRaw('plugins/floor_reply/list', [
            'postId'        => (int)($_POST['post_id'] ?? 0),
            'threadId'      => (int)($_POST['thread_id'] ?? 0),
            'threadOwnerId' => 0,
            'replies'       => [],
            'error'         => $msg,
            'page'          => 1,
            'total'         => 0,
            'perPage'       => 10,
            'totalPages'    => 0,
            'hasPage'       => false,
            'isLoggedIn'    => Auth::isLoggedIn(),
            'isAdmin'       => Auth::isAdmin(),
            'currentUser'   => Auth::getCurrentUser(),
            'csrfToken'     => Csrf::token(),
        ]);
    }
}
