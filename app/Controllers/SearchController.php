<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 搜索前台控制器 — 全文搜索、类型筛选、高亮展示
 * @file app/Controllers/SearchController.php
 * @package app\Controllers
 */

namespace app\Controllers;

use app\Core\Controller;
use app\Helpers\Search;
use app\Helpers\Content;
use app\Helpers\Settings;
use app\Helpers\Permission;
use app\Helpers\Auth;
use app\Helpers\RateLimiter;

class SearchController extends Controller
{
    public function index()
    {
        // 频率限制：每个 IP 每 30 秒最多 8 次「新搜索」（阈值后台可配）；
        // 翻页（page>1）不消耗搜索配额，仅用只读 isLimited() 风控（已超限才拒绝，不额外计数），
        // 避免正常用户快速翻页被误伤
        [$searchMax, $searchWin] = RateLimiter::config('search', 8, 30);
        $page = max(1, (int)($_GET['page'] ?? 1));
        if ($page <= 1) {
            RateLimiter::hit('search', $searchMax, $searchWin);
        } elseif (RateLimiter::isLimited('search', $searchMax, $searchWin)) {
            RateLimiter::hit('search', $searchMax, $searchWin); // 已超限：hit() 内部 check 不再写入，仅输出 429
        }

        // 未注册用户不允许搜索
        if (!Auth::isLoggedIn()) {
            // 记录登录来源（登录成功后回跳搜索页）
            \app\Helpers\Auth::rememberRedirectAfter();
            header('Location: ' . (\defined('BASE_PATH') ? \BASE_PATH : '') . '/login');
            exit;
        }

        $keyword = trim($_GET['keyword'] ?? '');
        $type = $_GET['type'] ?? 'all';

        // 时间范围：all / 30d（近一月）/ 180d（近半年）/ 365d（近一年），白名单校验
        $timeRange = $_GET['time_range'] ?? 'all';
        if (!in_array($timeRange, ['all', '30d', '180d', '365d'], true)) {
            $timeRange = 'all';
        }
        
        $searchType = 'all';
        if ($type === 'threads') $searchType = 'thread';
        elseif ($type === 'blogs') $searchType = 'blog';
        elseif ($type === 'users') $searchType = 'users';

        $page = max(1, (int)($_GET['page'] ?? 1)); // 页码在统计 totalPages 后钳制
        $perPage = 6;

        $results = [];
        $threadResults = [];
        $blogResults = [];
        $userResults = [];

        // 统一初始化全局真实总计数
        $threadTotal = 0;
        $blogTotal = 0;
        $userTotal = 0;
        $totalCount = 0;

        if ($keyword !== '') {
            // 获取当前用户有权查看的版块 ID 集合
            $allowedIds = Permission::getAuthorizedCategoryIds($this->currentUser() ? (int)$this->currentUser()['id'] : null);
            $catFilter = !empty($allowedIds) ? $allowedIds : null;

            // 用户总数：独立 COUNT（原 count(search(…,50)) 会把 >50 的匹配截断成错误总数，
            // 导致 userTotal 偏小、多页匹配不可达）
            $userTotal = (new \app\Models\User())->countSearch($keyword);
            // 混合/all Tab 预览最多取前 5 个用户，预取 50 条足够；users Tab 分页走下方按页取数
            $allUserResults = ($type === 'users') ? [] : (new \app\Models\User())->search($keyword, 50);

            // 合并计数与取数为一次 Search::search（取消原「计数 perPage=1 + 取数」两次重复调用）：
            // Search::search 一次即返回 thread_count/blog_count（计数）+ threads/blogs（数据，
            // 内部已候选放大 3 倍 + 权限过滤补位 + _score 透出）。
            // 仅当页码被钳制后发生变化时才补一次取数（越界页码兜底）。
            $threadTotal = 0;
            $blogTotal = 0;
            $threadResults = [];
            $blogResults = [];
            $userResults = [];

            if ($type === 'users') {
                $totalCount = $userTotal;
                $totalPages = $totalCount > 0 ? max(1, (int)ceil($totalCount / $perPage)) : 1;
                $page = max(1, min($totalPages, $page));
                $offset = ($page - 1) * $perPage;
                // 独立取数：按当前页足额扩取后切片（search() 仅有 limit 参数；
                // 不再依赖已置空的 $allUserResults，>50 匹配的深页也能取到数据）
                $need = $offset + $perPage;
                $fetched = (new \app\Models\User())->search($keyword, $need);
                $userResults = array_slice($fetched, $offset, $perPage);
            } else {
                // 数据 + 计数一次调用（blogs 无版块权限概念，传 null 与旧逻辑一致）
                $searchType = ($type === 'threads') ? 'thread' : (($type === 'blogs') ? 'blog' : 'all');
                $catArg = ($type === 'blogs') ? null : $catFilter;
                $reqPage = ($type === 'all') ? 1 : $page;     // all 固定取第 1 页混合展示
                $reqPerPage = ($type === 'all') ? 10 : $perPage;

                $tabResults = Search::search($keyword, $searchType, $reqPage, $reqPerPage, $catArg, $timeRange);
                $threadTotal = $tabResults['thread_count'] ?? 0;
                $blogTotal = $tabResults['blog_count'] ?? 0;
                $threadResults = $tabResults['threads'] ?? [];
                $blogResults = $tabResults['blogs'] ?? [];

                // 当前 Tab 全局总数 + 分页钳制
                if ($type === 'threads') $totalCount = $threadTotal;
                elseif ($type === 'blogs') $totalCount = $blogTotal;
                else $totalCount = $threadTotal + $blogTotal + $userTotal;
                $totalPages = $totalCount > 0 ? max(1, (int)ceil($totalCount / $perPage)) : 1;
                $page = max(1, min($totalPages, $page));

                // all / 未知 type：混合展示（threads + blogs + users 各取前部）
                if ($type !== 'threads' && $type !== 'blogs') {
                    $userResults = array_slice($allUserResults, 0, 5);
                }

                // 越界页码兜底：钳制后页码与请求页不一致（如 ?page=9999）→ 补一次取数拿末页真实数据
                if ($type !== 'all' && $page !== $reqPage) {
                    $tabResults = Search::search($keyword, $searchType, $page, $perPage, $catArg, $timeRange);
                    $threadResults = $tabResults['threads'] ?? [];
                    $blogResults = $tabResults['blogs'] ?? [];
                }
            }

            // 数据格式化修饰
            foreach ($threadResults as &$t) { $t['result_type'] = 'thread'; $t['content'] = Content::decode($t['content'] ?? ''); }
            unset($t);
            foreach ($blogResults as &$b) { $b['result_type'] = 'blog'; $b['content'] = Content::decode($b['content'] ?? ''); }
            unset($b);
            foreach ($userResults as &$u) { $u['result_type'] = 'user'; }
            unset($u);

            // 合并排序：以索引侧 score（_score）为主排序（降序），created_at 仅作 Tie-break
            $results = array_merge($threadResults, $blogResults, $userResults);
            usort($results, function ($a, $b) {
                $sa = (int)($a['_score'] ?? 0);
                $sb = (int)($b['_score'] ?? 0);
                if ($sa !== $sb) return $sb - $sa; // score 降序为主
                $ta = strtotime($a['created_at'] ?? 'now');
                $tb = strtotime($b['created_at'] ?? 'now');
                return $tb - $ta; // created_at 降序 Tie-break
            });
        }

        // 基于各自 Tab 的全局总数，精确计算分页总数
        $totalPages = $totalCount > 0 ? max(1, (int)ceil($totalCount / $perPage)) : 1;
        // 统一钳制页码（空关键词等未走上方钳制的路径兜底）
        $page = max(1, min($totalPages, $page));

        $this->view('search/index', [
            'keyword' => $keyword,
            'type' => $type,
            'timeRange' => $timeRange, // 搜索页时间范围下拉回显
            'results' => $results,
            'count' => $totalCount,
            'threadResults' => $threadResults,
            'blogResults' => $blogResults,
            'userResults' => $userResults,
            'threadTotal' => $threadTotal,
            'blogTotal' => $blogTotal,
            'userTotal' => $userTotal,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
            '__nav_active' => 'search',
        ]);
    }
}
