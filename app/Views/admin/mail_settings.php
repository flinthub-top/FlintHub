<?php
/**
 * 后台邮件设置视图 — SMTP 配置、邮件测试
 * @file app/Views/admin/mail_settings.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.nav_mail'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php $settings = $this->getData('settings', []); $csrfToken = $this->e($this->getData('csrfToken'));
$msg = $this->getData('msg', ''); ?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'mail_settings']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('mail', 16); ?> <?php echo $this->t('admin.mail_title'); ?></h1>
        </div>
        <?php if ($msg): ?><div class="alert alert-success"><?php echo $this->e($msg); ?></div><?php endif; ?>
        <div class="admin-section admin-form-lg">
            <p class="mn-fs-13 c-666 mn-mb-15"><?php echo $this->t('admin.mail_hint'); ?></p>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">

                <div class="form-group">
                    <label><?php echo $this->t('admin.mail_driver'); ?></label>
                    <select name="mail_driver" class="form-input select-auto">
                        <option value="smtp" <?php echo ($settings['mail_driver'] ?? 'smtp') === 'smtp' ? 'selected' : ''; ?>>SMTP</option>
                        <option value="mail" <?php echo ($settings['mail_driver'] ?? '') === 'mail' ? 'selected' : ''; ?>><?php echo $this->t('admin.mail_php'); ?></option>
                    </select>
                </div>
                <div class="form-row two-cols">
                    <div class="form-group">
                        <label><?php echo $this->t('admin.mail_host'); ?></label>
                        <input type="text" name="mail_host" value="<?php echo $this->e($settings['mail_host'] ?? 'smtp.qq.com'); ?>" class="form-input" placeholder="smtp.qq.com">
                    </div>
                    <div class="form-group">
                        <label><?php echo $this->t('admin.mail_port'); ?></label>
                        <input type="number" name="mail_port" value="<?php echo $this->e($settings['mail_port'] ?? '465'); ?>" class="form-input" placeholder="465">
                    </div>
                </div>
                <div class="form-row two-cols">
                    <div class="form-group">
                        <label><?php echo $this->t('admin.mail_encryption'); ?></label>
                        <select name="mail_encryption" class="form-input select-auto">
                            <option value="ssl" <?php echo ($settings['mail_encryption'] ?? 'ssl') === 'ssl' ? 'selected' : ''; ?>><?php echo $this->t('admin.mail_ssl'); ?></option>
                            <option value="tls" <?php echo ($settings['mail_encryption'] ?? '') === 'tls' ? 'selected' : ''; ?>><?php echo $this->t('admin.mail_tls'); ?></option>
                            <option value="null" <?php echo ($settings['mail_encryption'] ?? '') === 'null' ? 'selected' : ''; ?>><?php echo $this->t('admin.mail_none'); ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><?php echo $this->t('admin.mail_from_name'); ?></label>
                        <input type="text" name="mail_from_name" value="<?php echo $this->e($settings['mail_from_name'] ?? 'FlintHub 论坛'); ?>" class="form-input">
                    </div>
                </div>
                <div class="form-row two-cols">
                    <div class="form-group">
                        <label><?php echo $this->t('admin.mail_user'); ?></label>
                        <input type="text" name="mail_user" value="<?php echo $this->e($settings['mail_user'] ?? ''); ?>" class="form-input" placeholder="your@qq.com">
                    </div>
                    <div class="form-group">
                        <label><?php echo $this->t('admin.mail_from_addr'); ?></label>
                        <input type="text" name="mail_from_addr" value="<?php echo $this->e($settings['mail_from_addr'] ?? ''); ?>" class="form-input" placeholder="<?php echo $this->t('admin.mail_from_addr_hint'); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('admin.mail_pass'); ?></label>
                    <input type="password" name="mail_pass" value="" class="form-input" placeholder="<?php echo $this->t('admin.mail_pass_placeholder'); ?>" autocomplete="off">
                    <p class="mn-fs-12 c-999 mn-mt-4"><?php echo $this->t('admin.mail_pass_hint'); ?></p>
                </div>
                <button type="submit" class="btn btn-primary"><?php echo $this->t('admin.save_settings'); ?></button>
            </form>
        </div>
    </main>
</div>
<?php $this->endSection(); ?>