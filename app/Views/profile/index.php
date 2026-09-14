<?php
/**
 * 个人中心视图 — 资料、统计、Tab 切换（信息/密码/头像）
 * @file app/Views/profile/index.php
 */
$user = $this->getData('user');
$error = $this->getData('error');
$success = $this->getData('success');
$userThreads = $this->getData('userThreads', []);
$userBlogs = $this->getData('userBlogs', []);
$stats = $this->getData('stats', []);
$currentUser = $this->getData('currentUser');
?>
<?php $this->section('title'); ?><?php echo $this->t('profile.title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php if ($error): ?><div class="mn-alert mn-alert-error"><?php echo $this->e($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="mn-alert mn-alert-success"><?php echo $this->e($success); ?></div><?php endif; ?>
<div class="mn-section">
    <div class="mn-section-header">
        <h2><?php echo $this->icon('profile', 16); ?> <?php echo $this->t('profile.title'); ?></h2>
    </div>
    <div class="mn-p-20" x-data="{ tab: 'info' }">
        <div class="mn-flex-center mn-gap-16 mn-mb-20 mn-border-bottom" style="padding-bottom:20px;">
            <?php if (!empty($user['avatar'])): ?>
                <img src="<?php echo $this->e(UPLOAD_URL . $user['avatar']); ?>" alt="" class="mn-rounded-4 profile-avatar-img">
            <?php else: ?>
                <div class="mn-rounded-4 mn-flex-center profile-avatar-placeholder"><?php echo $this->e(mb_substr($user['username'] ?? '', 0, 1)); ?></div>
            <?php endif; ?>
            <div>
                <div class="mn-fs-16 mn-fw-600 profile-username"><?php echo $this->e($user['username'] ?? ''); ?></div>
                <div class="mn-fs-12 mn-text-muted"><?php echo $this->icon('calendar', 11); ?> <?php echo $this->t('profile.joined'); ?> <?php echo !empty($user['created_at']) ? date('Y-m-d', strtotime($user['created_at'])) : ''; ?></div>
            </div>
        </div>
        <div class="profile-stats-grid">
            <div class="mn-text-center mn-bg-body mn-rounded-8 profile-stat-card"><div class="mn-fs-22 mn-fw-700 profile-stat-value"><?php echo (int)($stats['total_threads'] ?? 0); ?></div><div class="mn-fs-12 mn-text-muted profile-stat-label"><?php echo $this->t('profile.stat_threads'); ?></div></div>
            <div class="mn-text-center mn-bg-body mn-rounded-8 profile-stat-card"><div class="mn-fs-22 mn-fw-700 profile-stat-value"><?php echo (int)($stats['total_replies'] ?? 0); ?></div><div class="mn-fs-12 mn-text-muted profile-stat-label"><?php echo $this->t('profile.stat_replies'); ?></div></div>
            <div class="mn-text-center mn-bg-body mn-rounded-8 profile-stat-card"><div class="mn-fs-22 mn-fw-700 profile-stat-value"><?php echo (int)($stats['total_views'] ?? 0); ?></div><div class="mn-fs-12 mn-text-muted profile-stat-label"><?php echo $this->t('profile.stat_views'); ?></div></div>
            <div class="mn-text-center mn-bg-body mn-rounded-8 profile-stat-card"><div class="mn-fs-22 mn-fw-700 profile-stat-value"><?php echo (int)($stats['total_blogs'] ?? 0); ?></div><div class="mn-fs-12 mn-text-muted profile-stat-label"><?php echo $this->t('profile.stat_blogs'); ?></div></div>
        </div>
        <div class="mn-flex mn-gap-12 profile-tab-nav">
            <a href="#info" class="mn-btn mn-btn-sm" x-on:click.prevent="tab = 'info'" :class="{ 'mn-btn-primary': tab === 'info' }"><?php echo $this->icon('user', 12); ?> <?php echo $this->t('profile.tab_info'); ?></a>
            <a href="#password" class="mn-btn mn-btn-sm" x-on:click.prevent="tab = 'password'" :class="{ 'mn-btn-primary': tab === 'password' }"><?php echo $this->icon('lock', 12); ?> <?php echo $this->t('profile.tab_password'); ?></a>
            <a href="#avatar" class="mn-btn mn-btn-sm" x-on:click.prevent="tab = 'avatar'" :class="{ 'mn-btn-primary': tab === 'avatar' }"><?php echo $this->icon('image', 12); ?> <?php echo $this->t('profile.tab_avatar'); ?></a>
        </div>
        <div x-show="tab === 'info'" class="profile-tab">
            <h3 class="mn-fs-15 mn-fw-600 mn-mb-14"><?php echo $this->t('profile.tab_info'); ?></h3>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo $this->e($this->getData('csrfToken')); ?>">
                <input type="hidden" name="action" value="update_profile">
                <div class="mn-mb-12">
                    <label class="form-label"><?php echo $this->t('auth.username'); ?></label>
                    <input type="text" value="<?php echo $this->e($user['username'] ?? ''); ?>" disabled class="mn-input mn-bg-body">
                </div>
                <div class="mn-mb-12">
                    <label for="email" class="form-label"><?php echo $this->t('auth.email'); ?></label>
                    <input type="email" id="email" name="email" value="<?php echo $this->e($user['email'] ?? ''); ?>" required class="mn-input">
                </div>
                <div class="mn-mb-14">
                    <label for="signature" class="form-label"><?php echo $this->t('profile.signature'); ?></label>
                    <textarea id="signature" name="signature" rows="3" class="mn-textarea mn-maxw-500"><?php echo $this->e($user['signature'] ?? ''); ?></textarea>
                </div>
                <button type="submit" class="mn-btn mn-btn-primary"><?php echo $this->icon('save', 12); ?> <?php echo $this->t('common.save'); ?></button>
            </form>
        </div>
        <div x-show="tab === 'password'" class="profile-tab">
            <h3 class="mn-fs-15 mn-fw-600 mn-mb-14"><?php echo $this->t('profile.tab_password'); ?></h3>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo $this->e($this->getData('csrfToken')); ?>">
                <input type="hidden" name="action" value="change_password">
                <div class="mn-mb-12"><label class="form-label"><?php echo $this->t('profile.old_password'); ?></label><input type="password" name="old_password" required class="mn-input mn-maxw-300"></div>
                <div class="mn-mb-12"><label class="form-label"><?php echo $this->t('auth.new_password'); ?></label><input type="password" name="new_password" required class="mn-input mn-maxw-300"></div>
                <div class="mn-mb-14"><label class="form-label"><?php echo $this->t('auth.confirm_new_password'); ?></label><input type="password" name="confirm_password" required class="mn-input mn-maxw-300"></div>
                <button type="submit" class="mn-btn mn-btn-primary"><?php echo $this->icon('save', 12); ?> <?php echo $this->t('profile.tab_password'); ?></button>
            </form>
        </div>
        <div x-show="tab === 'avatar'" class="profile-tab">
            <h3 class="mn-fs-15 mn-fw-600 mn-mb-14"><?php echo $this->t('profile.tab_avatar'); ?></h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?php echo $this->e($this->getData('csrfToken')); ?>">
                <input type="hidden" name="action" value="upload_avatar">
                <div class="mn-mb-14">
                    <label for="avatar" class="form-label"><?php echo $this->t('profile.choose_avatar'); ?></label>
                    <input type="file" id="avatar" name="avatar" accept="image/*" required class="mn-input mn-maxw-400" style="padding:6px 12px;">
                    <div class="mn-fs-11 mn-text-muted" style="margin-top:3px;"><?php echo $this->t('profile.avatar_hint'); ?></div>
                </div>
                <button type="submit" class="mn-btn mn-btn-primary"><?php echo $this->icon('upload', 12); ?> <?php echo $this->t('profile.upload_avatar'); ?></button>
            </form>
        </div>
        <?php if (!empty($userThreads) || !empty($userBlogs)): ?>
        <div class="profile-recent">
            <h3 class="profile-recent-title"><?php echo $this->t('profile.recent_posts'); ?></h3>
            <?php if (!empty($userThreads)): foreach (array_slice($userThreads, 0, 5) as $thread): ?>
            <div class="mn-row profile-recent-item">
                <div class="mn-row-main"><a href="<?php echo $this->url('/thread/' . (int)$thread['id']); ?>"><?php echo $this->e($thread['title'] ?? ''); ?></a><div class="profile-recent-meta"><?php echo $this->timeAgo($thread['created_at'] ?? ''); ?> · <?php echo $this->icon('views', 11); ?> <?php echo (int)($thread['view_count'] ?? 0); ?></div></div>
            </div>
            <?php endforeach; endif; ?>
            <?php if (!empty($userBlogs)): foreach (array_slice($userBlogs, 0, 5) as $blog): ?>
            <div class="mn-row profile-recent-item">
                <div class="mn-row-main"><a href="<?php echo $this->url('/blog/' . (int)$blog['id']); ?>"><?php echo $this->e($blog['title'] ?? ''); ?></a><div class="profile-recent-meta"><?php echo $this->timeAgo($blog['created_at'] ?? ''); ?></div></div>
            </div>
            <?php endforeach; endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php $this->endSection(); ?>