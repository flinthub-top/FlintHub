<?php
/**
 * 后台管理员验证视图 — 操作前密码确认弹窗
 * @file app/Views/admin/verify.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.verify_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php $error = $this->getData('error'); $csrfToken = $this->e($this->getData('csrfToken')); ?>
<div class="admin-verify-wrapper">
    <div class="auth-box">
        <h2><?php echo $this->icon('verify', 18); ?> <?php echo $this->t('admin.verify_title'); ?></h2>
        <p class="mn-fs-14 c-64748b mn-text-center mn-mb-20">
            <?php echo $this->t('admin.verify_hint'); ?><br>
            <small class="c-94a3b8"><?php echo $this->t('admin.verify_hint2'); ?></small>
        </p>
        <?php if ($error): ?>
        <div class="alert alert-error"><?php echo $this->e($error); ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
            <div class="form-group">
                <label for="password"><?php echo $this->t('admin.current_password'); ?></label>
                <input type="password" id="password" name="password" required autofocus class="form-input">
            </div>
            <button type="submit" class="btn btn-primary btn-verify"><?php echo $this->t('admin.confirm_identity'); ?></button>
        </form>
        <div class="mn-text-center mn-mt-15">
            <p><a href="<?php echo $this->url('/'); ?>" class="c-primary mn-text-decoration-none"><?php echo $this->t('nav.home'); ?></a></p>
        </div>
    </div>
</div>
<?php $this->endSection(); ?>