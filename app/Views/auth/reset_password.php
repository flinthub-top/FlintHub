<?php
/**
 * 重置密码视图 — 通过 Token 设置新密码
 * @file app/Views/auth/reset_password.php
 */
$error = $this->getData('error', '');
$success = $this->getData('success', '');
$tokenValid = $this->getData('tokenValid', false);
$token = $this->getData('token', '');
?>
<?php $this->section('title'); ?><?php echo $this->t('auth.reset_password'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section mn-auth-box">
    <div class="mn-p-24 mn-py-30">
        <h2 class="mn-fs-20 mn-fw-600 mn-text-center mn-mb-24"><?php echo $this->t('auth.reset_password'); ?></h2>
        <?php if ($success): ?><div class="mn-alert mn-alert-success"><?php echo $this->e($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="mn-alert mn-alert-error"><?php echo $this->e($error); ?></div><?php endif; ?>
        <?php if ($tokenValid && !$success): ?>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?php echo $this->e($this->getData('csrfToken')); ?>">
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo $this->t('auth.new_password'); ?></label>
                <input type="password" name="password" id="resetPassword" required minlength="6" class="mn-input" placeholder="<?php echo $this->t('auth.password_placeholder'); ?>">
                <div class="mn-mt-4" id="passwordStrength" style="display:none;">
                    <div class="mn-strength-track">
                        <div id="strengthBar" style="height:100%;width:0;border-radius:2px;transition:width .3s,background .3s;"></div>
                    </div>
                    <div id="strengthText" class="mn-fs-12 mn-text-muted mn-mt-4"></div>
                </div>
                <div class="mn-fs-12 mn-text-muted mn-mt-4"><?php echo $this->t('auth.password_hint'); ?></div>
            </div>
            <div class="mn-mb-16">
                <label class="mn-label"><?php echo $this->t('auth.confirm_new_password'); ?></label>
                <input type="password" name="confirm_password" required minlength="6" class="mn-input" placeholder="<?php echo $this->t('auth.confirm_new_hint'); ?>">
            </div>
            <button type="submit" class="mn-btn mn-btn-primary mn-btn-block"><?php echo $this->t('auth.reset_password'); ?></button>
        </form>
        <?php elseif (!$success): ?>
        <div class="mn-text-center"><a href="<?php echo $this->url('/forgot-password'); ?>" class="mn-btn"><?php echo $this->t('auth.reapply'); ?></a></div>
        <?php endif; ?>
    </div>
</div>
<script>
document.getElementById('resetPassword').addEventListener('input', function(){
    checkPasswordStrength(this.value);
});
</script>
<?php $this->endSection(); ?>