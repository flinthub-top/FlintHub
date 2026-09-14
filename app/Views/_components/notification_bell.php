<?php
/**
 * 通知铃铛组件（通知插件内置化）— PC 顶栏 & 移动端「我的」菜单双入口
 * 用法：<?php $this->include('_components/notification_bell', ['placement' => 'pc'|'mobile']); ?>
 * 博客模式(blog)下论坛被关闭，隐藏通知入口。
 * @file app/Views/_components/notification_bell.php
 */
$placement = $placement ?? 'pc';
$bp = \defined('BASE_PATH') ? BASE_PATH : '';
if (\app\Helpers\Settings::get('site_mode', 'portal') === 'blog') return;
if (!\app\Helpers\Auth::isLoggedIn()) return;

$userId = (int)(\app\Helpers\Auth::getCurrentUser()['id'] ?? 0);
if (!$userId) return;

// 未读徽标（Session 缓存 30 秒），避免每页请求都查 COUNT
$cacheKey = '_notif_unread_' . $userId;
$unread = 0;
if (isset($_SESSION[$cacheKey]) && (time() - $_SESSION[$cacheKey]['time']) < 30) {
    $unread = $_SESSION[$cacheKey]['count'];
} else {
    try {
        $unread = \app\Helpers\Notification::getUnreadCount($userId);
        $_SESSION[$cacheKey] = ['count' => $unread, 'time' => time()];
    } catch (\Throwable $e) {
        $unread = 0;
    }
}

// 方案A：私信未读并入铃铛徽标（仅开启私信时；消息与通知零耦合，仅 UI 层聚合）
if (\app\Helpers\Settings::get('message_enabled') === '1') {
    try {
        $unread += (int)\app\Helpers\Settings::getUnreadMessageCount();
    } catch (\Throwable $e) {
    }
}

if ($placement === 'mobile') {
    $badge = $unread > 0 ? ' <small class="mn-text-error">(' . $unread . ')</small>' : '';
    echo '<a href="' . $bp . '/notifications">' . $this->icon('bell', 14) . '<span>' . \app\Helpers\I18n::get('plugin.notifications.nav') . $badge . '</span></a>';
} else {
    $badge = $unread > 0 ? '<small class="mn-text-error mn-fw-700">' . $unread . '</small>' : '';
    echo '<a href="' . $bp . '/notifications" class="mn-desktop-only" title="' . \app\Helpers\I18n::get('plugin.notifications.nav') . '">' . $this->icon('bell', 14) . $badge . '</a>';
}