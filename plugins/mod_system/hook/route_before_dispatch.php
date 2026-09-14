<?php
/**
 * FlintHub — 社区治理插件 封禁全局操作拦截
 * @file plugins/mod_system/hook/route_before_dispatch.php
 * @package Plugin\ModSystem
 * @version 1.0.0
 *
 * 力度：只拦"写操作"（非 GET 请求），读操作全部放行，保证被封禁者仍可看帖/看通知/看主页、可申诉自查。
 */

if (!isset($_SESSION['user_id'])) return;

if (!\app\Helpers\Auth::isLoggedIn()) return;

$uid = (int)$_SESSION['user_id'];
if (!\Plugin\ModSystem\Plugin::isBanned($uid)) return;

// 写操作白名单：以下写请求必须放行（登出 / 举报 / 登录注册 / 后台验证）
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'GET') return;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$base = \defined('BASE_PATH') ? \BASE_PATH : '';
if ($base !== '' && strpos($path, $base) === 0) $path = substr($path, strlen($base));

$allowPrefixes = [
    '/logout', '/mod-system/report', '/login', '/register',
    '/api/captcha', '/admin/verify', '/admin/verify/',
];
foreach ($allowPrefixes as $p) {
    if (strncmp($path, $p, strlen($p)) === 0) return;
}
// 管理员封禁对象一般不会同时是管理员，但以防万一：管理员后台写操作不拦截
if (\app\Helpers\Auth::isAdmin()) return;

// 其余写操作全部拦截
$msg = \app\Helpers\I18n::get('plugin.mod_system.banned_action');
http_response_code(403);
$base = \defined('BASE_PATH') ? BASE_PATH : '';
$cssVer = @filemtime(__DIR__ . '/../assets/style.css') ?: 1;
exit('<link rel="stylesheet" href="' . $base . '/plugins/mod_system/assets/style.css?v=' . $cssVer . '">'
    . '<div class="mn-alert mn-alert-error mod-ban-alert">' . htmlspecialchars($msg, ENT_QUOTES) . '</div>');