<?php
/**
 * 后台站点模式设置视图 — 门户(Portal) / 论坛 / 博客 三选一
 * portal = 双开(门户聚合); forum = 仅论坛; blog = 仅博客
 * @file app/Views/admin/settings_mode.php
 */
$settings = $this->getData('settings', []);
$csrfToken = $this->e($this->getData('csrfToken'));
$msg = $this->getData('msg', '');
$mode = (string)($settings['site_mode'] ?? 'portal');
if (!in_array($mode, ['portal', 'forum', 'blog'], true)) $mode = 'portal';
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.nav_site_mode'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'settings_mode']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('columns', 16); ?> <?php echo $this->t('admin.nav_site_mode'); ?></h1>
        </div>
        <?php if ($msg): ?><div class="alert alert-success"><?php echo $this->e($msg); ?></div><?php endif; ?>

        <div class="admin-section">
            <p class="mn-fs-13 c-666 mn-mb-12"><?php echo $this->t('admin.site_mode_intro'); ?></p>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">

                <div class="mode-grid">
                    <!-- ===== 门户模式(Portal) ===== -->
                    <label class="mode-card">
                        <input type="radio" name="site_mode" value="portal" <?php echo $mode === 'portal' ? 'checked' : ''; ?>>
                        <span class="mode-card-icon"><?php echo $this->icon('columns', 22); ?></span>
                        <span class="mode-card-title"><?php echo $this->t('admin.site_mode_portal'); ?></span>
                        <span class="mode-card-desc"><?php echo $this->t('admin.site_mode_portal_desc'); ?></span>
                        <span class="mode-card-hint"><?php echo $this->t('admin.site_mode_portal_hint'); ?></span>
                    </label>

                    <!-- ===== 论坛模式 ===== -->
                    <label class="mode-card">
                        <input type="radio" name="site_mode" value="forum" <?php echo $mode === 'forum' ? 'checked' : ''; ?>>
                        <span class="mode-card-icon"><?php echo $this->icon('forum', 22); ?></span>
                        <span class="mode-card-title"><?php echo $this->t('admin.site_mode_forum'); ?></span>
                        <span class="mode-card-desc"><?php echo $this->t('admin.site_mode_forum_desc'); ?></span>
                        <span class="mode-card-hint"><?php echo $this->t('admin.site_mode_forum_hint'); ?></span>
                    </label>

                    <!-- ===== 博客模式 ===== -->
                    <label class="mode-card">
                        <input type="radio" name="site_mode" value="blog" <?php echo $mode === 'blog' ? 'checked' : ''; ?>>
                        <span class="mode-card-icon"><?php echo $this->icon('blog', 22); ?></span>
                        <span class="mode-card-title"><?php echo $this->t('admin.site_mode_blog'); ?></span>
                        <span class="mode-card-desc"><?php echo $this->t('admin.site_mode_blog_desc'); ?></span>
                        <span class="mode-card-hint"><?php echo $this->t('admin.site_mode_blog_hint'); ?></span>
                    </label>
                </div>

                <div class="rebuild-box mode-note">
                    <p class="mn-m-0 mn-fs-12 c-999">
                        <?php echo $this->icon('info', 14); ?> <?php echo $this->t('admin.site_mode_note'); ?>
                    </p>
                </div>

                <button type="submit" class="btn btn-primary"><?php echo $this->t('admin.save_settings'); ?></button>
            </form>
        </div>
    </main>
</div>
<?php $this->endSection(); ?>