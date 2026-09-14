<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 首页控制器 — 聚合最新帖子/博客、统计信息
 * @file app/Controllers/HomeController.php
 * @package app\Controllers
 */

namespace app\Controllers;
use app\Core\Controller;
use app\Models\{User, Setting, Thread, Blog, Category, BlogCategory};
use app\Helpers\Settings;

class HomeController extends Controller
{
    public function index()
    {
        // 论坛/博客单开模式：首页直接渲染对应模块页（portal 走下方聚合逻辑）
        // 委托时传 is_home_delegate=true，模块视图据此输出纯净站点名标题（无「论坛/博客」冗余前缀）
        $siteMode = Settings::get('site_mode', 'portal');
        if ($siteMode === 'forum') {
            return (new ForumController())->index(true);
        }
        if ($siteMode === 'blog') {
            return (new BlogController())->index(0, true);
        }

        // 首页模式（仅 portal 模式生效）：portal=官网介绍（home/index）/ community=社区首页（home/index_default）
        // 优先级：用户偏好（登录 users.home_mode / 游客 cookie site_home_mode）→ 站点默认 default_home_mode
        $homeMode = \app\Helpers\Theme::getHomeMode();
        $homeView = $homeMode === 'community' ? 'home/index_default' : 'home/index';
        $homeCacheKey = $homeMode === 'community' ? 'home_1_c' : 'home_1_p';
        // 游客偏好已由 PageCache::prefKey 按摘要偏好分键，无需额外后缀

        // 列表页静态缓存：仅游客（登录用户含用户态内容不缓存），永久缓存发帖失效，命中直接输出
        // 首页无分页，缓存键按首页模式区分（home_1_p / home_1_c，防官网/社区首页游客互相命中）
        if (!\app\Helpers\Auth::isLoggedIn() && \app\Helpers\PageCache::serve($homeCacheKey)) {
            return;
        }

        $threadModel = new Thread();
        $blogModel = new Blog();
        $categoryModel = new Category();
        $blogCategoryModel = new BlogCategory();

        $latestBlogs = $blogModel->getLatest((int)Settings::get('home_blogs_count', 5));
        $categories = $categoryModel->allOrdered();
        $blogCategories = $blogCategoryModel->allOrdered();

        // SQL 层过滤无权限查看的帖子
        $currentUser = $this->currentUser();
        $userId = $currentUser ? (int)$currentUser['id'] : 0;
        $allowedIds = \app\Helpers\Permission::getAuthorizedCategoryIds($userId ?: null);
        $homeLimit = (int)Settings::get('home_threads_count', 10);

        // 首页"最新帖"优先聚合各版块 JSON 快照（发帖/回帖时由 Category::refreshLatestSnapshot 刷新），
        // 避免高频首页实时查 topic_index 大表；快照全部缺失时回退现有 getLatestAllowed 查询。
        foreach ($categories as $cat) {
            $cid = (int)$cat['id'];
            if (!empty($allowedIds) && !in_array($cid, $allowedIds, true)) {
                continue;
            }
            // 每版块最新帖快照（含回退生成）
            $snap = \app\Models\Category::getLatestThreads($cid, 3);
            foreach ($snap as $t) {
                $latestThreads[] = [
                    'id' => (int)$t['id'],
                    'title' => (string)$t['title'],
                    'user_id' => (int)$t['user_id'],
                    'username' => (string)($t['username'] ?? ''),
                    'created_at' => date('Y-m-d H:i:s', (int)$t['created_at']),
                    // 快照回填后按回帖时间排序；旧快照无 last_reply_time 时回退发帖时间
                    'last_reply_time' => date('Y-m-d H:i:s', (int)($t['last_reply_time'] ?? $t['created_at'])),
                    'category_id' => $cid,
                    'category_name' => $cat['name'] ?? '',
                    'view_count' => 0,
                    'reply_count' => 0,
                    // 快照聚合透传展示字段（快照已带 is_pinned/is_highlighted/color）
                    'is_pinned' => (int)($t['is_pinned'] ?? 0),
                    'is_highlighted' => (int)($t['is_highlighted'] ?? 0),
                    'color' => (string)($t['color'] ?? ''),
                    'avatar' => '',
                    'level' => 1,
                    '_pending_review' => false,
                ];
            }
        }
        if (empty($latestThreads)) {
            // 回退：快照均不存在（新站/首次访问）→ 走原查询（getLatestThreads 的兜底已尝试生成，此处再兜底一次）
            $latestThreads = $threadModel->getLatestAllowed($allowedIds, $homeLimit);
        } else {
            // 快照聚合：跨版块归并排序（置顶优先 + 时间次之，与回退路径 getLatestAllowed 语义一致）
            usort($latestThreads, function ($a, $b) {
                if ((int)$b['is_pinned'] !== (int)$a['is_pinned']) {
                    return (int)$b['is_pinned'] - (int)$a['is_pinned'];
                }
                // 与版块列表一致：按回帖时间（last_reply_time）排序
                return strcmp((string)$b['last_reply_time'], (string)$a['last_reply_time']);
            });
            $latestThreads = array_slice($latestThreads, 0, $homeLimit);

            // 快照只存展示必需的最小字段（不含头像/等级/计数），此处按 user_id / 帖子 id 一次批量补全：
            // 头像/等级/用户名来自 business.users；view_count/reply_count 来自 main_index.topic_index
            $uids = array_values(array_unique(array_map(fn($t) => (int)$t['user_id'], $latestThreads)));
            $userMap = [];
            if (!empty($uids)) {
                $marks = implode(',', array_fill(0, count($uids), '?'));
                $ur = \app\Core\Database::getInstance()->query('SELECT id, username, avatar, level FROM users WHERE id IN (' . $marks . ')', $uids);
                foreach ($ur as $u) {
                    $userMap[(int)$u['id']] = $u;
                }
            }
            // 批量补浏览数/回复数（main_index.topic_index，一次 IN 查询）
            $tids = array_values(array_map(fn($t) => (int)$t['id'], $latestThreads));
            $countMap = [];
            if (!empty($tids)) {
                $marks2 = implode(',', array_fill(0, count($tids), '?'));
                $stmt = \app\SplitDB\Schema::mainIndexDb()->prepare(
                    'SELECT id, view_count, reply_count, excerpt, excerpt_images, bucket_path FROM topic_index WHERE id IN (' . $marks2 . ')'
                );
                $stmt->execute($tids);
                $cr = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($cr as $c) {
                    $countMap[(int)$c['id']] = $c;
                }
            }
            foreach ($latestThreads as &$t) {
                $uid = (int)$t['user_id'];
                $t['username'] = $userMap[$uid]['username'] ?? ($t['username'] ?? '');
                $t['avatar'] = $userMap[$uid]['avatar'] ?? '';
                $t['level'] = (int)($userMap[$uid]['level'] ?? 0);
                // 真实计数（快照无计数字段 → 以索引为准）
                $c = $countMap[(int)$t['id']] ?? null;
                $t['view_count'] = $c ? (int)$c['view_count'] : 0;
                $t['reply_count'] = $c ? (int)$c['reply_count'] : 0;
                // 补 bucket_path + 索引行落库摘要，使 attachExcerpts 走"索引行自带摘要"零额外查询；
                // 快照不存这些字段，须在此补齐（否则首页每次都要补 bucket_path + 按桶查摘要）
                $t['bucket_path'] = (string)($c['bucket_path'] ?? ($t['bucket_path'] ?? ''));
                $t['excerpt'] = (string)($c['excerpt'] ?? '');
                $t['excerpt_images'] = (string)($c['excerpt_images'] ?? '');
            }
            unset($t);
        }
        // 列表内容模式：开关开启时给列表行补摘要（用户级偏好，默认开 + 80 字）
        $excerptPref = \app\Helpers\Theme::getListExcerptPref();
        if ($excerptPref['enabled']) {
            $latestThreads = $threadModel->attachExcerpts($latestThreads, $excerptPref['len']);
        }
        $totalUsers = Settings::getTotalUsers();
        $totalThreads = Settings::getTotalThreads();
        // 右侧栏"论坛主题/回复"：回复数取 Settings::getTotalPosts()（与主题同源，实时口径一致）
        $totalPosts = Settings::getTotalPosts();
        $totalBlogs = Settings::getTotalBlogs();
        // 右侧栏"博客文章/评论"：博客评论数走 30s 短 TTL 缓存（替代每请求 COUNT(blog_comments)）
        $blogComments = Settings::getBlogCommentsCount();
        $onlineData = Settings::getOnlineData();
        $siteName = htmlspecialchars(Settings::get('site_name', DEFAULT_SITE_NAME), ENT_QUOTES, 'UTF-8');
        $siteDescription = htmlspecialchars(Settings::get('site_description', \app\Helpers\I18n::get('home.site_description_default')), ENT_QUOTES, 'UTF-8');

        $threadIds = array_map(function ($t) { return $t['id']; }, $latestThreads);
        $threadVotes = !empty($threadIds) ? \app\Helpers\Vote::getCounts('thread', $threadIds) : [];

        $tags = \app\Helpers\Tag::getPopular(20);
        // 在线数据只取一次（30s 文件缓存），供右侧栏与页脚共用
        $onlineCount = $onlineData['count'];
        $onlineUsers = $onlineData['users'];

        $data = [
            'latestThreads' => $latestThreads,
            'latestBlogs' => $latestBlogs,
            'categories' => $categories,
            'blogCategories' => $blogCategories,
            'totalUsers' => $totalUsers,
            'totalThreads' => $totalThreads,
            'totalPosts' => $totalPosts,
            'totalBlogs' => $totalBlogs,
            'blogComments' => $blogComments,
            'onlineCount' => $onlineCount,
            'onlineUsers' => $onlineUsers,
            'siteName' => $siteName,
            'siteDescription' => $siteDescription,
            'tags' => $tags,
            'threadVotes' => $threadVotes,
            'showExcerpt' => $excerptPref['enabled'],
            '__nav_active' => 'home',
        ];

        // 游客渲染落缓存（永久，发帖/回帖失效）；登录用户直接渲染（内容含用户态）
        // 首页缓存键按首页模式区分（home_1_p / home_1_c，与上方 serve 一致）
        if (!\app\Helpers\Auth::isLoggedIn()) {
            \app\Helpers\PageCache::render(function () use ($data, $homeView, $homeCacheKey) { $this->view($homeView, $data); }, $homeCacheKey);
            return;
        }
        $this->view($homeView, $data);
    }
}
