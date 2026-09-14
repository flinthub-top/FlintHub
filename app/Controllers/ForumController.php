<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 论坛前台控制器 — 版块列表、帖子列表、分类浏览
 * @file app/Controllers/ForumController.php
 * @package app\Controllers
 */

namespace app\Controllers;
use app\Core\Controller;
use app\Models\{Thread, Category};
use app\Helpers\Settings;
use app\Helpers\Vote;

class ForumController extends Controller
{
    /**
     * 博客模式(blog)下论坛被关闭：直连 /forum、/forum/category/* 重定向首页
     */
    public function __construct()
    {
        if (\app\Helpers\Settings::get('site_mode', 'portal') === 'blog') {
            $this->redirect('/');
        }
    }

    public function index(bool $isHomeDelegate = false)
    {
        $type = $_GET['type'] ?? 'latest';
        $perPage = max(5, (int)\app\Helpers\Settings::get('threads_per_page', 6));
        $highlightOnly = $type === 'highlighted';

        // keyset 游标模式：请求带 after/before（4 元组 pin-lrt-ct-id）时走行值比较查询，
        // 完全绕过 OFFSET 深翻页的线性索引扫描；游标模式不参与前置缓存（keyset 本身 <1ms，且游标 URL 一次性）
        $afterRaw = (string)($_GET['after'] ?? '');
        $beforeRaw = (string)($_GET['before'] ?? '');
        $cursorMode = ($afterRaw !== '' || $beforeRaw !== '');
        $cursorPrev = null; // 上一页游标（本页第一条）
        $cursorNext = null; // 下一页游标（本页最后一条）

        if (!$cursorMode) {
            // 游客列表页静态缓存（永久，发帖/回帖失效；highlighted 视图键加 _h 后缀避免串缓存）
            // 缓存键页码上限放宽到 100000（防恶意页码刷缓存文件），实际页码由 totalPages 钳制
            $cachePage = max(1, min(100000, (int)($_GET['page'] ?? 1)));
            $cacheKey = 'forum_0_' . $cachePage . ($highlightOnly ? '_h' : '');
            if (!\app\Helpers\Auth::isLoggedIn() && \app\Helpers\PageCache::serve($cacheKey)) {
                return;
            }
        }
        $threadModel = new Thread();
        $categoryModel = new Category();

        $categories = $categoryModel->allOrdered();

        // 获取用户有权查看的版块 ID，用于 SQL 层过滤
        $currentUser = $this->currentUser();
        $userId = $currentUser ? (int)$currentUser['id'] : 0;
        $allowedIds = \app\Helpers\Permission::getAuthorizedCategoryIds($userId ?: null);

        // 过滤版块列表：只显示用户有浏览权限的版块
        if (!empty($allowedIds)) {
            $categories = array_values(array_filter($categories, function ($cat) use ($allowedIds) {
                return in_array((int)$cat['id'], $allowedIds, true);
            }));
        }

        $threadCountMap = $categoryModel->getThreadCountMap();
        $postCountMap = $categoryModel->getPostCountMap();

        $highlightWhere = '';
        if ($highlightOnly) {
            $highlightWhere = ' AND t.is_highlighted = 1';
        }

        // 统计时排除软删除帖子（读 main_index 计数）
        $total = $threadModel->countActive($allowedIds, $highlightOnly);
        $totalPages = max(1, (int)ceil($total / $perPage));

        // 页码钳制到真实 totalPages（不再硬编码 1000 页上限）；非法页码回落到最后一页
        $page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));

        // 列表读 main_index + 批量补用户/分类（SQL 层权限过滤，分页准确）
        if ($cursorMode) {
            // 游标必须由模型返回的原始索引行字段产生（decorateList 丢弃 last_reply_time/create_time）
            $firstRow = null;
            $lastRow = null;
            if ($beforeRaw !== '') {
                $before = array_map('intval', explode('-', $beforeRaw));
                $latestThreads = $threadModel->getIndexCursorPrev($allowedIds, $perPage, $before, $highlightOnly, $firstRow, $lastRow);
            } else {
                $after = array_map('intval', explode('-', $afterRaw));
                $latestThreads = $threadModel->getIndexCursor($allowedIds, $perPage, $after, $highlightOnly, $lastRow, $firstRow);
            }
            $cursorPrev = $firstRow; // 上一页游标 = 本页第一条
            $cursorNext = $lastRow;  // 下一页游标 = 本页最后一条
            $offset = 0; // 游标模式无 OFFSET
        } else {
            $offset = ($page - 1) * $perPage;
            // 普通页码模式同样接收首尾行游标 → 分页条的上一页/下一页可带 after/before 直达 keyset
            $latestThreads = $threadModel->getIndexPaginated($allowedIds, $perPage, $offset, $highlightOnly, $firstRow, $lastRow);
            $cursorPrev = $firstRow;
            $cursorNext = $lastRow;
        }

        // 最后发表数据
        $lastPostByCat = [];
        foreach ($latestThreads as $t) {
            $cid = $t['category_id'];
            if (!isset($lastPostByCat[$cid])) {
                $lastPostByCat[$cid] = $t;
            }
        }

        $siteName = htmlspecialchars(Settings::get('site_name', \DEFAULT_SITE_NAME), ENT_QUOTES, 'UTF-8');

        // 构造虚拟父分类
        $forumGroups = [[
            'id' => 0,
            'name' => $siteName,
            'description' => '',
            'forums' => [],
        ]];

        foreach ($categories as &$cat) {
            $cid = (int)$cat['id'];
            $cat['thread_count'] = $threadCountMap[$cid] ?? 0;
            $cat['post_count'] = $postCountMap[$cid] ?? 0;
            // 每版块最新帖优先读 JSON 快照（发帖/回帖时由 Category::refreshLatestSnapshot 刷新），
            // 避免高频版块页实时查 topic_index 大表；快照缺失/空时回退当前页列表首条。
            $snap = \app\Models\Category::getLatestThreads($cid, 1);
            if (!empty($snap)) {
                $lp = $snap[0];
                $cat['last_thread_id'] = $lp['id'];
                $cat['last_thread_title'] = $lp['title'];
                $cat['last_thread_username'] = $lp['username'];
                $cat['last_thread_user_id'] = $lp['user_id'];
                $cat['last_thread_created_at'] = $lp['created_at'];
            } elseif (isset($lastPostByCat[$cid])) {
                $lp = $lastPostByCat[$cid];
                $cat['last_thread_id'] = $lp['id'];
                $cat['last_thread_title'] = $lp['title'];
                $cat['last_thread_username'] = $lp['username'];
                $cat['last_thread_user_id'] = $lp['user_id'];
                $cat['last_thread_created_at'] = $lp['created_at'];
            }
            $forumGroups[0]['forums'][] = $cat;
        }
        unset($cat);

        $totalUsers = Settings::getTotalUsers();   // 右侧栏统计
        $totalThreads = Settings::getTotalThreads();
        $totalPosts = Settings::getTotalPosts();
        $onlineCount = Settings::getOnlineCount();

        $tags = \app\Helpers\Tag::getPopular(20);

        // 列表内容模式：开关开启时给列表行补摘要（用户级偏好，默认开 + 80 字）
        $excerptPref = \app\Helpers\Theme::getListExcerptPref();
        if ($excerptPref['enabled']) {
            $latestThreads = $threadModel->attachExcerpts($latestThreads, $excerptPref['len']);
        }

        // 主题投票数据（默认主题需要）
        $threadIds = array_map(function ($t) { return $t['id']; }, $latestThreads);
        $threadVotes = !empty($threadIds) ? Vote::getCounts('thread', $threadIds) : [];

        $data = [
            'forumGroups' => $forumGroups,
            'categories' => $categories,
            // 移动端抽屉博客分类：供布局复用（博客模式下论坛页仍显示博客抽屉）
            'blogCategories' => (new \app\Models\BlogCategory())->allOrdered(),
            'latestThreads' => $latestThreads,
            'threadVotes' => $threadVotes,
            'totalUsers' => $totalUsers,
            'totalThreads' => $totalThreads,
            'totalPosts' => $totalPosts,
            'onlineCount' => $onlineCount,
            'tags' => $tags,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'type' => $type,
            'showExcerpt' => $excerptPref['enabled'],
            // keyset 游标分页：上一页/下一页游标（4 元组 pin-lrt-ct-id，视图拼 after/before 链接）；
            // cursorMode 供视图区分「游标导航」与「普通页码条」
            'cursorPrev' => $cursorPrev,
            'cursorNext' => $cursorNext,
            'cursorMode' => $cursorMode,
            // 首页委托渲染标志：单模式下首页标题输出纯净站点名
            'is_home_delegate' => $isHomeDelegate,
            '__nav_active' => 'forum',
        ];

        // 游客渲染落缓存；登录用户直接渲染（内容含用户态）
        // 游标模式不落缓存（keyset <1ms，且游标 URL 一次性）；普通页码模式才渲染缓存
        // 缓存键用钳制后的 $page（原实现用未钳制页码写盘，恶意遍历 ?page=1..100000 会各生成一个缓存文件）
        if (!\app\Helpers\Auth::isLoggedIn() && !$cursorMode) {
            // 游客偏好已由 PageCache::prefKey 按摘要偏好分键，无需额外后缀
            $lxKey = 'forum_0_' . $page . ($highlightOnly ? '_h' : '');
            \app\Helpers\PageCache::render(function () use ($data) { $this->view('forum/index', $data); }, $lxKey);
            return;
        }
        $this->view('forum/index', $data);
    }

    public function category($id)
    {
        $categoryModel = new Category();
        $category = $categoryModel->find((int)$id);
        if (!$category) {
            $this->redirect('/forum');
        }

        // 权限检查
        $currentUser = $this->currentUser();
        $userId = $currentUser ? (int)$currentUser['id'] : 0;
        if (!\app\Helpers\Permission::canView((int)$id, $userId ?: null)) {
            $this->view('forum/category', [
                'category' => $category, 'latestThreads' => [], 'categories' => [],
                'threadVotes' => [], 'totalThreads' => 0, 'totalPosts' => 0,
                'onlineCount' => 0, 'page' => 1, 'totalPages' => 1, 'total' => 0,
                'error' => \app\Helpers\I18n::get('forum.no_permission_board'),
                '__nav_active' => 'forum',
            ]);
            return;
        }

        // keyset 游标模式：请求带 after/before 时走行值比较查询，绕过 OFFSET 深翻页扫描
        $afterRaw = (string)($_GET['after'] ?? '');
        $beforeRaw = (string)($_GET['before'] ?? '');
        $cursorMode = ($afterRaw !== '' || $beforeRaw !== '');
        $cursorPrev = null; // 上一页游标（本页第一条）
        $cursorNext = null; // 下一页游标（本页最后一条）

        if (!$cursorMode) {
            // 游客列表页静态缓存（永久，发帖/回帖失效；权限已校验，无权版块不会命中/生成缓存）
            // 缓存键页码上限放宽到 100000（防恶意页码刷缓存文件），实际页码由 totalPages 钳制
            $cachePage = max(1, min(100000, (int)($_GET['page'] ?? 1)));
            $cacheKey = 'forum_' . (int)$id . '_' . $cachePage;
            if (!\app\Helpers\Auth::isLoggedIn() && \app\Helpers\PageCache::serve($cacheKey)) {
                return;
            }
        }

        $threadModel = new Thread();

        $perPage = max(5, (int)Settings::get('threads_per_page', 6));

        // 版块内计数与列表均读 main_index
        $total = $threadModel->countActive([(int)$id]);
        $totalPages = max(1, (int)ceil($total / $perPage));

        // 页码钳制到真实 totalPages（不再硬编码 1000 页上限）；非法页码回落到最后一页
        $page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));

        if ($cursorMode) {
            // 游标必须由模型返回的原始索引行字段产生（decorateList 丢弃 last_reply_time/create_time）
            $firstRow = null;
            $lastRow = null;
            if ($beforeRaw !== '') {
                $before = array_map('intval', explode('-', $beforeRaw));
                $latestThreads = $threadModel->getIndexCursorPrev([(int)$id], $perPage, $before, false, $firstRow, $lastRow);
            } else {
                $after = array_map('intval', explode('-', $afterRaw));
                $latestThreads = $threadModel->getIndexCursor([(int)$id], $perPage, $after, false, $lastRow, $firstRow);
            }
            $cursorPrev = $firstRow; // 上一页游标 = 本页第一条
            $cursorNext = $lastRow;  // 下一页游标 = 本页最后一条
            $offset = 0;
        } else {
            $offset = ($page - 1) * $perPage;
            // 普通页码模式同样接收首尾行游标 → 分页条的上一页/下一页可带 after/before 直达 keyset
            $latestThreads = $threadModel->getByCategoryPaginated((int)$id, $perPage, $offset, $firstRow, $lastRow);
            $cursorPrev = $firstRow;
            $cursorNext = $lastRow;
        }
        $categories = $categoryModel->allOrdered();

        // 过滤无权限的版块
        $currentUser = $this->currentUser();
        $userId = $currentUser ? (int)$currentUser['id'] : 0;
        $allowedIds = \app\Helpers\Permission::getAuthorizedCategoryIds($userId ?: null);
        if (!empty($allowedIds)) {
            $categories = array_values(array_filter($categories, function ($cat) use ($allowedIds) {
                return in_array((int)$cat['id'], $allowedIds, true);
            }));
        }

        // 列表内容模式：开关开启时给列表行补摘要（用户级偏好，默认开 + 80 字）
        $excerptPref = \app\Helpers\Theme::getListExcerptPref();
        if ($excerptPref['enabled']) {
            $latestThreads = $threadModel->attachExcerpts($latestThreads, $excerptPref['len']);
        }

        $threadIds = array_map(function ($t) { return $t['id']; }, $latestThreads);
        $threadVotes = !empty($threadIds) ? Vote::getCounts('thread', $threadIds) : [];

        $totalUsers = Settings::getTotalUsers();   // 右侧栏统计
        $totalThreads = Settings::getTotalThreads();
        $totalPosts = Settings::getTotalPosts();
        $onlineCount = Settings::getOnlineCount();

        $data = [
            'category' => $category,
            'latestThreads' => $latestThreads,
            'categories' => $categories,
            'threadVotes' => $threadVotes,
            'totalUsers' => $totalUsers,
            'totalThreads' => $totalThreads,
            'totalPosts' => $totalPosts,
            'onlineCount' => $onlineCount,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'showExcerpt' => $excerptPref['enabled'],
            // keyset 游标分页：上一页/下一页游标（视图拼 after/before 链接）；
            // cursorMode 供视图区分「游标导航」与「普通页码条」
            'cursorPrev' => $cursorPrev,
            'cursorNext' => $cursorNext,
            'cursorMode' => $cursorMode,
            '__nav_active' => 'forum',
        ];

        // 游客渲染落缓存；登录用户直接渲染（内容含用户态）
        // 游标模式不落缓存（keyset <1ms，且游标 URL 一次性）；普通页码模式才渲染缓存
        // 缓存键用钳制后的 $page（防恶意页码刷缓存文件）
        if (!\app\Helpers\Auth::isLoggedIn() && !$cursorMode) {
            // 游客偏好已由 PageCache::prefKey 按摘要偏好分键，无需额外后缀
            $lxKey = 'forum_' . (int)$id . '_' . $page;
            \app\Helpers\PageCache::render(function () use ($data) { $this->view('forum/category', $data); }, $lxKey);
            return;
        }
        $this->view('forum/category', $data);
    }
}
