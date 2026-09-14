<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 公告钩子 — 首页展示公告（支持普通堆叠和上下滚动两种模式）
 * @file plugins/announcements/hook/announcements_display.php
 * @package Plugin\Announcements
 * @version 1.2.0
 */

$announcements = \Plugin\Announcements\Plugin::getAll();
if (empty($announcements)) return;

$styles = \Plugin\Announcements\Plugin::getStyles();
$mode = \app\Helpers\Settings::get('announcement_mode', 'normal');
$bp = \defined('BASE_PATH') ? BASE_PATH : '';
$cssVer = @filemtime(__DIR__ . '/../assets/style.css') ?: 1;
$jsVer  = @filemtime(__DIR__ . '/../assets/script.js') ?: 1;
?>
<link rel="stylesheet" href="<?php echo $bp; ?>/plugins/announcements/assets/style.css?v=<?php echo $cssVer; ?>">
<script src="<?php echo $bp; ?>/plugins/announcements/assets/script.js?v=<?php echo $jsVer; ?>"></script>
<?php if ($mode === 'scroll'): ?>
<!-- 滚动模式 -->
<div class="announcement-scroll-wrap">
    <div class="announcement-scroll-inner" id="announcementScroll">
        <?php foreach ($announcements as $ann):
            $s = $styles[$ann['style']] ?? $styles['yellow'];
            $hasLink = !empty($ann['url']);
            $annClass = 'announcement-scroll-item ann-style-' . (isset($styles[$ann['style']]) ? $ann['style'] : 'yellow');
        ?>
        <div class="<?php echo $annClass; ?>">
            <?php if ($hasLink): ?>
            <a href="<?php echo htmlspecialchars($ann['url']); ?>" target="_blank" rel="noopener" class="announcement-link"><?php echo nl2br(htmlspecialchars(\app\Helpers\Content::decode($ann['content']), ENT_QUOTES, 'UTF-8')); ?></a>
            <?php else: ?>
            <?php echo nl2br(htmlspecialchars(\app\Helpers\Content::decode($ann['content']), ENT_QUOTES, 'UTF-8')); ?>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<!-- 普通堆叠模式 -->
<div class="announcements-container">
<?php foreach ($announcements as $ann):
    $s = $styles[$ann['style']] ?? $styles['yellow'];
    $annId = 'ann_' . $ann['id'];
    $hasLink = !empty($ann['url']);
    $annClass = 'announcement-bar ann-style-' . (isset($styles[$ann['style']]) ? $ann['style'] : 'yellow');
?>
    <div class="<?php echo $annClass; ?>" id="<?php echo $annId; ?>" data-aid="<?php echo (int)$ann['id']; ?>">
        <div class="announcement-content">
        <?php if ($hasLink): ?>
            <a href="<?php echo htmlspecialchars($ann['url']); ?>" target="_blank" rel="noopener" class="announcement-link"><?php echo nl2br(htmlspecialchars(\app\Helpers\Content::decode($ann['content']), ENT_QUOTES, 'UTF-8')); ?></a>
        <?php else: ?>
            <?php echo nl2br(htmlspecialchars(\app\Helpers\Content::decode($ann['content']), ENT_QUOTES, 'UTF-8')); ?>
        <?php endif; ?>
        </div>
        <button class="announcement-close" data-id="<?php echo (int)$ann['id']; ?>" title="关闭">×</button>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
