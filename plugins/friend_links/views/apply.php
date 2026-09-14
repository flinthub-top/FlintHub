<?php
/**
 * 友链申请视图 — 提交友情链接申请（friend_links 插件）
 * @file app/Views/plugins/friend_links/apply.php
 */
$error = $this->getData('error', ''); $success = $this->getData('success', '');
?>
<?php $this->section('title'); ?><?php echo \app\Helpers\I18n::get('plugin.friend_links.apply_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('css'); ?>
<link rel="stylesheet" href="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/friend_links/assets/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/style.css') ?: 1; ?>">
<?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section mn-maxw-500 fl-apply-wrap">
    <div class="mn-p-24 mn-py-30">
        <h2 class="mn-fs-20 mn-fw-600 fl-apply-title"><?php echo $this->icon('link', 18); ?> <?php echo \app\Helpers\I18n::get('plugin.friend_links.apply_title'); ?></h2>
        <p class="mn-fs-13 mn-text-muted fl-apply-sub"><?php echo \app\Helpers\I18n::get('plugin.friend_links.apply_sub'); ?></p>
        <?php if ($error): ?><div class="mn-alert mn-alert-error"><?php echo $this->e($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="mn-alert mn-alert-success"><?php echo $this->e($success); ?></div><?php endif; ?>
        <?php if (!$success): ?>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?php echo $this->e($this->getData('csrfToken')); ?>">
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo \app\Helpers\I18n::get('plugin.friend_links.apply_name'); ?> <span class="mn-text-error">*</span></label>
                <input type="text" name="name" required class="mn-input" placeholder="<?php echo \app\Helpers\I18n::get('plugin.friend_links.apply_name_ph'); ?>" maxlength="100">
            </div>
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo \app\Helpers\I18n::get('plugin.friend_links.apply_url'); ?> <span class="mn-text-error">*</span></label>
                <input type="url" name="url" required class="mn-input" placeholder="<?php echo \app\Helpers\I18n::get('plugin.friend_links.apply_url_ph'); ?>">
            </div>
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo \app\Helpers\I18n::get('plugin.friend_links.apply_desc'); ?></label>
                <input type="text" name="description" class="mn-input" placeholder="<?php echo \app\Helpers\I18n::get('plugin.friend_links.apply_desc_ph'); ?>" maxlength="200">
            </div>
            <div class="mn-mb-16">
                <label class="mn-label"><?php echo \app\Helpers\I18n::get('plugin.friend_links.apply_logo'); ?></label>
                <input type="url" name="logo" class="mn-input" placeholder="<?php echo \app\Helpers\I18n::get('plugin.friend_links.apply_logo_ph'); ?>">
                <p class="mn-fs-12 mn-text-muted fl-apply-hint"><?php echo \app\Helpers\I18n::get('plugin.friend_links.apply_logo_hint'); ?></p>
            </div>
            <button type="submit" class="mn-btn mn-btn-primary mn-w-full mn-fs-15 mn-p-10"><?php echo \app\Helpers\I18n::get('plugin.friend_links.apply_submit'); ?></button>
        </form>
        <?php endif; ?>
        <p class="mn-text-center mn-mt-16"><a href="/" class="mn-fs-13 mn-text-muted"><?php echo $this->icon('back', 12); ?> <?php echo \app\Helpers\I18n::get('plugin.friend_links.back_home'); ?></a></p>
    </div>
</div>
<?php $this->endSection(); ?>