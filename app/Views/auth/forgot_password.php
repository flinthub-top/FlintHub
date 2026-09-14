<?php
/**
 * 忘记密码视图 — 输入邮箱发送重置链接
 * @file app/Views/auth/forgot_password.php
 */
$error = $this->getData('error', '');
$success = $this->getData('success', '');
?>
<?php $this->section('title'); ?><?php echo $this->t('auth.forgot_password'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section mn-auth-box">
    <div class="mn-p-24 mn-py-30">
        <h2 class="mn-fs-20 mn-fw-600 mn-text-center mn-mb-24"><?php echo $this->t('auth.forgot_title'); ?></h2>
        <?php if ($success): ?><div class="mn-alert mn-alert-success"><?php echo $this->e($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="mn-alert mn-alert-error"><?php echo $this->e($error); ?></div><?php endif; ?>
        <?php if (!$success): ?>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?php echo $this->e($this->getData('csrfToken')); ?>">
            <div class="mn-mb-16">
                <label class="mn-label"><?php echo $this->t('auth.register_email'); ?></label>
                <input type="email" name="email" required class="mn-input">
            </div>
            <button type="submit" class="mn-btn mn-btn-primary mn-btn-block"><?php echo $this->t('auth.send_reset_mail'); ?></button>
        </form>
        <div class="mn-text-center mn-mt-16">
            <a href="<?php echo $this->url('/login'); ?>" class="mn-fs-13 mn-text-primary"><?php echo $this->t('auth.back_to_login'); ?></a>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php $this->endSection(); ?>