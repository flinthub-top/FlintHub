<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 帖子收藏控制器 — 收藏/取消收藏、收藏列表
 * @file plugins/post_favorite/FavoriteController.php
 * @package Plugin\PostFavorite
 * @version 1.0.0
 */

namespace Plugin\PostFavorite;

use app\Core\Controller;
use app\Helpers\Auth;
use app\Helpers\Csrf;
use app\Helpers\Permission;

class FavoriteController extends Controller
{
    /**
     * GET /favorites — 查看我的收藏列表
     */
    public function index()
    {
        $this->requireLogin();
        $user = Auth::getCurrentUser();
        $userId = (int)$user['id'];

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 10;
        $offset = ($page - 1) * $perPage;

        // 获取收藏总数（[SplitDB] 插件独立库）
        $count = \Plugin\PostFavorite\Plugin::getFavoriteCount($userId);
        $totalPages = max(1, (int)ceil($count / $perPage));

        // 获取用户有权查看的版块 ID
        $allowedIds = Permission::getAuthorizedCategoryIds($userId);

        if (empty($allowedIds)) {
            // 用户没有任何可见版块 → 收藏列表为空
            $this->view('plugins/post_favorite/index', [
                'favorites' => [], 'page' => 1, 'totalPages' => 1, 'totalCount' => 0,
                '__nav_active' => 'favorites',
            ]);
            return;
        }

        // [SplitDB] 收藏记录在插件独立库
        $pdb = \app\Helpers\Plugin::db('post_favorite');
        $stmt = $pdb->prepare(
            "SELECT id as fav_id, thread_id, created_at as fav_time
             FROM post_favorites WHERE user_id = :uid
             ORDER BY id DESC
             LIMIT " . (int)$perPage . " OFFSET " . (int)$offset
        );
        $stmt->execute([':uid' => $userId]);
        $favRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $favorites = [];
        if (!empty($favRows)) {
            // [SplitDB] 帖子信息在 main_index 主索引库（topic_index），按收藏页批量拉取
            $threadIds = array_map(fn($f) => (int)$f['thread_id'], $favRows);
            $marks = implode(',', array_fill(0, count($threadIds), '?'));
            $catFilter = ' AND category_id IN (' . implode(',', array_map('intval', $allowedIds)) . ')';
            $tstmt = \app\SplitDB\Schema::mainIndexDb()->prepare(
                "SELECT id, uid, title, view_count, reply_count, category_id
                 FROM topic_index WHERE id IN ($marks){$catFilter}"
            );
            $tstmt->execute($threadIds);
            $tmap = [];
            foreach ($tstmt->fetchAll(\PDO::FETCH_ASSOC) as $t) {
                $tmap[(int)$t['id']] = $t;
            }

            // [SplitDB] 用户名在核心库（business.sqlite），批量补
            $uids = [];
            foreach ($tmap as $t) {
                if ((int)$t['uid'] > 0) $uids[] = (int)$t['uid'];
            }
            $uids = array_values(array_unique($uids));
            // [SplitDB] 用户名在核心库（business.sqlite），M-1/P2：经 User::batchGetNames 批量补
            $unames = \app\Models\User::batchGetNames($uids);

            foreach ($favRows as $f) {
                $tid = (int)$f['thread_id'];
                $t = $tmap[$tid] ?? null;
                // 帖子已删除或不在可见版块 → 跳过（与原 SQL 的 category 过滤行为一致）
                if (!$t) continue;
                $favorites[] = [
                    'fav_id'      => (int)$f['fav_id'],
                    'thread_id'   => $tid,
                    'fav_time'    => $f['fav_time'],
                    'title'       => $t['title'] ?? '',
                    'reply_count' => (int)($t['reply_count'] ?? 0),
                    'view_count'  => (int)($t['view_count'] ?? 0),
                    'username'    => $unames[(int)$t['uid']] ?? '',
                ];
            }
        }

        $this->view('plugins/post_favorite/index', [
            'favorites' => $favorites,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalCount' => $count,
            '__nav_active' => 'favorites',
        ]);
    }

    /**
     * POST /favorite/toggle — 切换收藏（AJAX）
     */
    public function toggle()
    {
        $this->requireLogin();
        Csrf::verifyOrDie($_POST['csrf'] ?? '');

        $user = Auth::getCurrentUser();
        $threadId = (int)($_POST['thread_id'] ?? 0);

        if ($threadId <= 0) {
            $this->json(['error' => \app\Helpers\I18n::get('plugin.post_favorite.err_param')], 400);
        }

        try {
            $result = \Plugin\PostFavorite\Plugin::toggle((int)$user['id'], $threadId);

            // htmx 请求：返回空片段（配合 hx-swap="outerHTML" 移除收藏项）
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
                echo '';
                exit;
            }

            $this->json($result);
        } catch (\Throwable $e) {
            $this->json(['error' => \app\Helpers\I18n::get('plugin.post_favorite.err_operation')], 500);
        }
    }

    /**
     * GET /favorite/check?thread_id=X — 检查收藏状态（AJAX）
     */
    public function check()
    {
        $this->requireLogin();
        $user = Auth::getCurrentUser();
        $threadId = (int)($_GET['thread_id'] ?? 0);

        if ($threadId <= 0) {
            $this->json(['error' => \app\Helpers\I18n::get('plugin.post_favorite.err_param')], 400);
        }

        $favorited = \Plugin\PostFavorite\Plugin::isFavorited((int)$user['id'], $threadId);
        $this->json(['favorited' => $favorited]);
    }
}
