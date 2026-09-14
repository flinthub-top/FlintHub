<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 帖子收藏钩子 — 用户下拉菜单添加「我的收藏」入口（Session 缓存）
 * @file plugins/post_favorite/hook/nav_user_menu_items.php
 * @package Plugin\PostFavorite
 * @version 1.1.0
 */

$view = $GLOBALS['__view'] ?? null;
// 博客模式(blog)下论坛被关闭，隐藏收藏入口
if (\app\Helpers\Settings::get('site_mode', 'portal') === 'blog') return;
// PC 端用户下拉已有收藏直链，本钩子仅在移动端「我的」菜单渲染，避免重复
if (($placement ?? '') === 'pc') return;
if ($view && \app\Helpers\Auth::isLoggedIn()) {
    $user = \app\Helpers\Auth::getCurrentUser();
    $userId = (int)($user['id'] ?? 0);

    // Session 缓存（30 秒过期），避免每页请求都查 COUNT
    $cacheKey = '_fav_count_' . $userId;
    $now = time();
    if (isset($_SESSION[$cacheKey]) && ($now - $_SESSION[$cacheKey]['time']) < 30) {
        $count = $_SESSION[$cacheKey]['count'];
    } else {
        try {
            $count = \Plugin\PostFavorite\Plugin::getFavoriteCount($userId);
            $_SESSION[$cacheKey] = ['count' => $count, 'time' => $now];
        } catch (\Throwable $e) {
            $count = 0;
        }
    }

    $badge = $count > 0 ? '<span class="fav-count-badge">' . $count . '</span>' : '';
    echo '<a href="/favorites">' . $view->icon('highlight', 14) . '<span>' . \app\Helpers\I18n::get('plugin.post_favorite.title') . $badge . '</span></a>';
}
