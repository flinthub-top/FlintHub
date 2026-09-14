<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 友情链接钩子 — 在首页侧边栏底部显示友情链接
 * @file plugins/friend_links/hook/home_sidebar_bottom.php
 * @package Plugin\FriendLinks
 * @version 1.0.0
 */

if (\app\Helpers\Plugin::isActivated('friend_links')) {
    $links = \Plugin\FriendLinks\Plugin::getLinks();
    if (!empty($links)):
    $bp = \defined('BASE_PATH') ? BASE_PATH : '';
    $cssVer = @filemtime(__DIR__ . '/../assets/style.css') ?: 1;
?>
<link rel="stylesheet" href="<?php echo $bp; ?>/plugins/friend_links/assets/style.css?v=<?php echo $cssVer; ?>">
<div class="home-sidebar-box friend-links-box">
    <h3 class="home-sidebar-title"><i class="fa friend-link-title-icon">&#xf0c1;</i> <?php echo \app\Helpers\I18n::get('plugin.friend_links.sidebar_title'); ?></h3>
    <?php
    $hasLogo = false;
    $hasText = false;
    foreach ($links as $link) {
        if (!empty($link['logo'])) $hasLogo = true;
        else $hasText = true;
    }
    ?>
    <?php if ($hasText): ?>
    <div class="friend-links-text">
        <?php foreach ($links as $link): ?>
        <?php if (empty($link['logo'])): ?>
        <a href="<?php echo htmlspecialchars($link['url']); ?>" target="_blank" rel="noopener" class="friend-link-text-item" title="<?php echo htmlspecialchars($link['description'] ?: $link['name']); ?>">
            <?php echo htmlspecialchars($link['name']); ?>
        </a>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($hasLogo): ?>
    <div class="friend-links-logo">
        <?php foreach ($links as $link): ?>
        <?php if (!empty($link['logo'])): ?>
        <a href="<?php echo htmlspecialchars($link['url']); ?>" target="_blank" rel="noopener" class="friend-link-logo-item" title="<?php echo htmlspecialchars($link['description'] ?: $link['name']); ?>">
            <img src="<?php echo htmlspecialchars($link['logo']); ?>" alt="<?php echo htmlspecialchars($link['name']); ?>" class="friend-link-logo-img">
        </a>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if (\Plugin\FriendLinks\Plugin::isApplyEnabled() && \app\Helpers\Auth::isLoggedIn()): ?>
    <div class="friend-links-apply">
        <a href="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/links/apply"><?php echo \app\Helpers\I18n::get('plugin.friend_links.apply_link'); ?></a>
    </div>
    <?php endif; ?>
</div>
<?php
    endif;
}
