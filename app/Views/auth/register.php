<?php
/**
 * 注册视图 — 用户名/密码/邮箱注册、验证码
 * @file app/Views/auth/register.php
 */
$error = $this->getData('error', '');
$success = $this->getData('success', '');
?>
<?php $this->section('title'); ?><?php echo $this->t('auth.register'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section mn-auth-box">
    <div class="mn-p-24 mn-py-30">
        <h2 class="mn-fs-20 mn-fw-600 mn-text-center mn-mb-24"><?php echo $this->t('auth.register'); ?></h2>
        <?php if ($success): ?><div class="mn-alert mn-alert-success"><?php echo $this->e($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="mn-alert mn-alert-error"><?php echo $this->e($error); ?></div><?php endif; ?>
        <?php if (!$success): ?>
        <form method="POST" action="<?php echo $this->url('/register'); ?>" id="regForm">
            <input type="hidden" name="csrf" value="<?php echo $this->e($this->getData('csrfToken')); ?>">
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo $this->t('auth.username'); ?></label>
                <input type="text" name="username" required maxlength="64" class="mn-input">
            </div>
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo $this->t('auth.email'); ?></label>
                <input type="email" name="email" required class="mn-input">
            </div>
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo $this->t('auth.password'); ?></label>
                <input type="password" name="password" id="regPassword" required minlength="6" class="mn-input" placeholder="<?php echo $this->t('auth.password_placeholder'); ?>">
                <div class="mn-mt-4" id="passwordStrength" style="display:none;">
                    <div class="mn-strength-track">
                        <div id="strengthBar" style="height:100%;width:0;border-radius:2px;transition:width .3s,background .3s;"></div>
                    </div>
                    <div id="strengthText" class="mn-fs-12 mn-text-muted mn-mt-4"></div>
                </div>
                <div class="mn-fs-12 mn-text-muted mn-mt-4"><?php echo $this->t('auth.password_hint'); ?></div>
            </div>
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo $this->t('auth.confirm_password'); ?></label>
                <input type="password" name="confirm_password" required minlength="6" class="mn-input" placeholder="<?php echo $this->t('auth.confirm_hint'); ?>">
            </div>
            <div class="mn-mb-16" data-pow-captcha style="display:none;">
                <label class="mn-label"><?php echo $this->t('auth.captcha'); ?></label>
                <div class="mn-flex-center mn-gap-10">
                    <input type="text" name="captcha" maxlength="4" class="mn-input mn-wp-100">
                    <img src="<?php echo $this->url('/api/captcha'); ?>" id="captchaImg" class="mn-rounded-6 mn-cursor-pointer" style="height:38px;" title="<?php echo $this->t('auth.captcha_refresh'); ?>">
                </div>
            </div>
            <button type="submit" class="mn-btn mn-btn-primary mn-btn-block"><?php echo $this->t('auth.register'); ?></button>
        </form>
        <div class="mn-text-center mn-mt-16 mn-fs-13 mn-text-muted">
            <?php echo $this->t('auth.has_account'); ?> <a href="<?php echo $this->url('/login'); ?>" class="mn-text-primary mn-fw-500"><?php echo $this->t('auth.login'); ?></a>
        </div>
        <?php endif; ?>
    </div>
</div>
<script>
document.getElementById('regPassword').addEventListener('input', function(){
    // 兜底：强度函数未加载时跳过
    if (typeof checkPasswordStrength === 'function') checkPasswordStrength(this.value);
});
document.getElementById('captchaImg')?.addEventListener('click', function(){ this.src='<?php echo $this->url('/api/captcha'); ?>?'+Math.random(); });
// PoW 绑定已移至 pow.js 内部自动执行，此处不再重复 bind
</script>
<?php $this->endSection(); ?>