<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 帖子收藏钩子 — 仅帖子详情页注入收藏按钮的 CSS 和 JS
 * @file plugins/post_favorite/hook/layout_head_end.php
 * @package Plugin\PostFavorite
 * @version 1.0.0
 */

// 收藏按钮仅注入帖子详情页，其余页面不加载
$tpl = $template ?? '';
if ($tpl !== 'thread/show') return;
if (!\app\Helpers\Auth::isLoggedIn()) return;
$user = \app\Helpers\Auth::getCurrentUser();
if (!$user) return;
$userId = (int)$user['id'];
$bp = \defined('BASE_PATH') ? BASE_PATH : '';
// 资源加 filemtime 版本号：插件资源不经 asset() 无 ?v=，浏览器会缓存旧版 JS/CSS，
// 用文件修改时间强制拉新
$cssVer = @filemtime(__DIR__ . '/../assets/style.css') ?: 1;
$jsVer  = @filemtime(__DIR__ . '/../assets/script.js') ?: 1;
?>
<link rel="stylesheet" href="<?php echo $bp; ?>/plugins/post_favorite/assets/style.css?v=<?php echo $cssVer; ?>">
<script src="<?php echo $bp; ?>/plugins/post_favorite/assets/script.js?v=<?php echo $jsVer; ?>"></script>