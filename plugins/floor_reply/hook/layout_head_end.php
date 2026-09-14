<?php
/**
 * 楼中楼插件 头部资源注入 — 仅帖子详情页（thread/show）加载 CSS/JS
 * @file plugins/floor_reply/hook/layout_head_end.php
 * @package Plugin\FloorReply
 * @version 1.0.0
 */

$tpl = $template ?? '';
if ($tpl !== 'thread/show') return; // 仅帖子详情页

$bp = \defined('BASE_PATH') ? BASE_PATH : '';
$cssVer = @filemtime(__DIR__ . '/../assets/style.css') ?: 1;
$jsVer  = @filemtime(__DIR__ . '/../assets/script.js') ?: 1;
?>
<link rel="stylesheet" href="<?php echo $bp; ?>/plugins/floor_reply/assets/style.css?v=<?php echo $cssVer; ?>">
<script src="<?php echo $bp; ?>/plugins/floor_reply/assets/script.js?v=<?php echo $jsVer; ?>"></script>
