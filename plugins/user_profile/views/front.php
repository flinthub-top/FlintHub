<?php
/**
 * 用户主页视图 — 用户资料、统计、最近主题/博客（user_profile 插件）
 * @file plugins/user_profile/views/front.php
 */
?>
<link rel="stylesheet" href="<?php echo $this->url('/plugins/user_profile/assets/style.css'); ?>">
<?php
$profileUser = $this->getData('profileUser');
$threads = $this->getData('threads', []);
$blogs = $this->getData('blogs', []);
$stats = $this->getData('stats', []);
$isOwner = $this->getData('isOwner', false);
$isFollowing = $this->getData('isFollowing', false);
$followCounts = $this->getData('followCounts', ['followers' => 0, 'following' => 0]);
$csrfToken = $this->e($this->getData('csrfToken'));
$isLoggedIn = $this->getData('isLoggedIn');
?>
<?php $this->section('title'); ?><?php echo $this->e($profileUser['username'] ?? ''); ?> - <?php echo \app\Helpers\I18n::get('plugin.user_profile.page_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="up-container">
    <!-- 用户信息卡片 -->
    <div class="up-card">
        <div class="up-card-header">
            <div class="up-avatar">
                <?php if (!empty($profileUser['avatar'])): ?>
                    <img src="<?php echo $this->e(\UPLOAD_URL . $profileUser['avatar']); ?>" alt="">
                <?php else: ?>
                    <div class="up-avatar-placeholder"><?php echo $this->e(mb_substr($profileUser['username'] ?? '', 0, 1)); ?></div>
                <?php endif; ?>
            </div>
            <div class="up-user-info">
                <div class="up-user-info-top">
                    <h1 class="up-username"><?php echo $this->e($profileUser['username'] ?? ''); ?></h1>
                    <?php if ($isLoggedIn && !$isOwner): ?>
                    <form method="POST" action="<?php echo $this->url('/u/' . (int)$profileUser['id'] . ($isFollowing ? '/unfollow' : '/follow')); ?>" class="up-follow-form">
                        <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                        <button type="submit" class="up-btn-follow <?php echo $isFollowing ? 'up-btn-following' : ''; ?>">
                            <?php echo $isFollowing ? \app\Helpers\I18n::get('plugin.user_profile.unfollow') : \app\Helpers\I18n::get('plugin.user_profile.follow'); ?>
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php \app\Helpers\Plugin::hook('profile_user_card_after', ['user' => $profileUser]); ?>
                </div>
                <div class="up-badges">
                    <?php
                    $level = (int)($profileUser['level'] ?? 1);
                    echo \app\Helpers\Points::renderBadge($level);
                    ?>
                    <?php if (($profileUser['role'] ?? '') === 'admin'): ?>
                        <span class="up-badge up-badge-admin"><?php echo \app\Helpers\I18n::get('plugin.user_profile.admin'); ?></span>
                    <?php endif; ?>
                    <?php $wearingMedals = $this->getData('wearingMedals', []); echo $wearingMedals[(int)($profileUser['id'] ?? 0)] ?? ''; ?>
                </div>
                <div class="up-meta">
                    <span><?php echo \app\Helpers\I18n::get('plugin.user_profile.registered', ['date' => date('Y-m-d', strtotime($profileUser['created_at'] ?? 'now'))]); ?></span>
                </div>
            </div>
        </div>
        <?php if (!empty($profileUser['signature'])): ?>
            <div class="up-signature"><?php echo $this->e($profileUser['signature']); ?></div>
        <?php endif; ?>
        <div class="up-stats">
            <div class="up-stat-item">
                <span class="up-stat-value"><?php echo (int)$profileUser['points']; ?></span>
                <span class="up-stat-label"><?php echo \app\Helpers\I18n::get('plugin.user_profile.stat_points'); ?></span>
            </div>
            <div class="up-stat-item">
                <span class="up-stat-value"><?php echo (int)$stats['total_threads']; ?></span>
                <span class="up-stat-label"><?php echo \app\Helpers\I18n::get('plugin.user_profile.stat_threads'); ?></span>
            </div>
            <div class="up-stat-item">
                <span class="up-stat-value"><?php echo (int)$stats['total_replies']; ?></span>
                <span class="up-stat-label"><?php echo \app\Helpers\I18n::get('plugin.user_profile.stat_replies'); ?></span>
            </div>
            <div class="up-stat-item">
                <span class="up-stat-value"><?php echo (int)$stats['total_blogs']; ?></span>
                <span class="up-stat-label"><?php echo \app\Helpers\I18n::get('plugin.user_profile.stat_blogs'); ?></span>
            </div>
            <div class="up-stat-item">
                <span class="up-stat-value"><?php echo (int)$stats['total_views']; ?></span>
                <span class="up-stat-label"><?php echo \app\Helpers\I18n::get('plugin.user_profile.stat_views'); ?></span>
            </div>
            <div class="up-stat-item">
                <span class="up-stat-value"><?php echo (int)$followCounts['followers']; ?></span>
                <span class="up-stat-label"><?php echo \app\Helpers\I18n::get('plugin.user_profile.stat_followers'); ?></span>
            </div>
            <div class="up-stat-item">
                <span class="up-stat-value"><?php echo (int)$followCounts['following']; ?></span>
                <span class="up-stat-label"><?php echo \app\Helpers\I18n::get('plugin.user_profile.stat_following'); ?></span>
            </div>
        </div>
    </div>

    <!-- 最近主题 -->
    <div class="up-section">
        <h2 class="up-section-title"><?php echo \app\Helpers\I18n::get('plugin.user_profile.recent_threads'); ?></h2>
        <?php if (empty($threads)): ?>
            <p class="up-empty"><?php echo \app\Helpers\I18n::get('plugin.user_profile.no_threads'); ?></p>
        <?php else: ?>
            <ul class="up-list">
                <?php foreach ($threads as $t): ?>
                <li class="up-list-item">
                    <a href="<?php echo $this->url('/thread/' . (int)$t['id']); ?>" class="up-list-title"><?php echo $this->e($t['title']); ?></a>
                    <span class="up-list-meta">
                        <span class="up-list-cat"><?php echo $this->e($t['category_name'] ?? \app\Helpers\I18n::get('plugin.user_profile.uncategorized')); ?></span>
                        <span><?php echo $this->timeAgo($t['created_at']); ?></span>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- 最近博客 -->
    <div class="up-section">
        <h2 class="up-section-title"><?php echo \app\Helpers\I18n::get('plugin.user_profile.recent_blogs'); ?></h2>
        <?php if (empty($blogs)): ?>
            <p class="up-empty"><?php echo \app\Helpers\I18n::get('plugin.user_profile.no_blogs'); ?></p>
        <?php else: ?>
            <ul class="up-list">
                <?php foreach ($blogs as $b): ?>
                <li class="up-list-item">
                    <a href="<?php echo $this->url('/blog/' . (int)$b['id']); ?>" class="up-list-title"><?php echo $this->e($b['title']); ?></a>
                    <span class="up-list-meta">
                        <span class="up-list-cat"><?php echo $this->e($b['category_name'] ?? \app\Helpers\I18n::get('plugin.user_profile.uncategorized')); ?></span>
                        <span><?php echo $this->timeAgo($b['created_at']); ?></span>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<?php $this->endSection(); ?>