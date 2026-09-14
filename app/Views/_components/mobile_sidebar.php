<?php
/**
 * Modern 主题 — 移动端侧栏组件（仅 ≤768px 显示）
 * 渲染在中栏内容底部：热门标签 + 友情链接（首页）
 * 样式复用右栏的 mn-panel / mn-tag-cloud / mn-friend-links
 */
$mobileTags = $this->getData('tags', []);
$mobileUri = $_SERVER['REQUEST_URI'] ?? '/';
$mobileIsHome = $mobileUri === '/' || preg_match('#^\?preview_theme#', $mobileUri) || preg_match('#^/\?preview_theme#', $mobileUri);
// 标签页本身即标签展示页，移动端不再重复渲染热门标签面板
$mobileIsTagPage = $mobileUri === '/tags' || strpos($mobileUri, '/tag/') === 0;
// 友情链接显示范围：首页 / 论坛列表 / 博客页（分类页不显示）
$mobileShowFriendLinks = $mobileIsHome || strpos($mobileUri, '/forum') === 0 || strpos($mobileUri, '/blog') === 0;
?>
<?php if (!$mobileIsTagPage): ?>
<div class="mn-mobile-sidebar">
    <!-- 热门标签 -->
    <?php if (!empty($mobileTags)): ?>
    <div class="mn-panel">
        <h3 class="mn-panel-title"><a href="<?php echo $this->url('/tags'); ?>" style="text-decoration:none;color:inherit;"><?php echo $this->icon('tags', 12); ?> <?php echo $this->t('sidebar.hot_tags'); ?></a></h3>
        <div class="mn-tag-cloud">
            <?php
            $mobileTagMax = 1;
            foreach ($mobileTags as $mobileT) { $mc = (int)($mobileT['count'] ?? 0); if ($mc > $mobileTagMax) $mobileTagMax = $mc; }
            foreach ($mobileTags as $mobileTag):
                $mobileCount = (int)($mobileTag['count'] ?? 0);
                $mobileSize = 0.8 + ($mobileCount / max($mobileTagMax, 1)) * 0.7;
            ?>
            <a href="<?php echo $this->url('/tag/' . (int)($mobileTag['id'] ?? 0)); ?>" style="font-size:<?php echo $mobileSize; ?>em;"><?php echo $this->e($mobileTag['name'] ?? ''); ?> <small style="font-size:0.7em;opacity:0.6;">(<?php echo $mobileCount; ?>)</small></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 友情链接（首页/论坛/博客，与右栏逻辑一致） -->
    <?php if ($mobileShowFriendLinks): ?>
    <div class="mn-panel">
        <h3 class="mn-panel-title"><?php echo $this->icon('link', 12); ?> <?php echo $this->t('sidebar.friend_links'); ?></h3>
        <div class="mn-friend-links">
<?php
\app\Helpers\Plugin::hook('home_sidebar_bottom');
?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
