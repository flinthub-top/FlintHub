<?php
/**
 * 登录视图 — 用户名/密码登录、验证码、锁定提示
 * @file app/Views/auth/login.php
 */
$error = $this->getData('error');
$locked = $this->getData('locked', false);
$lockRemaining = (int)$this->getData('lockRemaining', 0);
$hasCsrf = $this->getData('hasCsrf', false);
?>
<?php $this->section('title'); ?><?php echo $this->t('auth.login'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section mn-auth-box">
    <div class="mn-p-24 mn-py-30">
        <h2 class="mn-fs-20 mn-fw-600 mn-text-center mn-mb-24"><?php echo $this->t('auth.login'); ?></h2>
        <?php if (isset($_GET['verified'])): ?><div class="mn-alert mn-alert-success"><?php echo $this->t('auth.email_verify_success') . '，' . $this->t('auth.login'); ?>。</div><?php endif; ?>
        <?php if ($error): ?><div class="mn-alert mn-alert-error"><?php echo $this->e($error); ?></div><?php endif; ?>
        <form method="POST" action="<?php echo $this->url('/login'); ?>" autocomplete="off" id="loginForm">
            <?php if ($hasCsrf): ?><input type="hidden" name="csrf" value="<?php echo $this->e($this->getData('csrfToken')); ?>"><?php endif; ?>
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo $this->t('auth.username'); ?></label>
                <input type="text" name="username" required autofocus maxlength="64" class="mn-input">
            </div>
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo $this->t('auth.password'); ?></label>
                <input type="password" name="password" required class="mn-input">
                <div style="text-align:right;margin-top:4px;"><a href="<?php echo $this->url('/forgot-password'); ?>" class="mn-fs-12 mn-text-muted"><?php echo $this->t('auth.forgot_password'); ?></a></div>
            </div>
            <div class="mn-flex-center mn-gap-6 mn-mb-14">
                <input type="checkbox" name="remember" value="1" id="remember" style="accent-color:var(--mn-primary);">
                <label for="remember" class="mn-fs-13 mn-text-secondary mn-cursor-pointer"><?php echo $this->t('auth.remember_me'); ?></label>
            </div>
            <div class="mn-mb-16" data-pow-captcha style="display:none;">
                <label class="mn-label"><?php echo $this->t('auth.captcha'); ?></label>
                <div class="mn-flex-center mn-gap-10">
                    <input type="text" name="captcha" maxlength="4" class="mn-input mn-wp-100">
                    <img src="<?php echo $this->url('/api/captcha'); ?>" id="captchaImg" class="mn-rounded-6 mn-cursor-pointer" style="height:38px;" title="<?php echo $this->t('auth.captcha_refresh'); ?>">
                </div>
                <div class="mn-fs-11 mn-text-muted" style="margin-top:3px;"><?php echo $this->t('auth.captcha_refresh'); ?></div>
            </div>
            <button type="submit" <?php echo $locked ? 'disabled' : ''; ?> class="mn-btn mn-btn-primary mn-w-full mn-fs-15" style="padding:10px;">
                <?php echo $locked ? $this->t('auth.locked') : $this->t('auth.login'); ?>
            </button>
        </form>
        <?php if (\app\Helpers\Settings::get('allow_registration', '1') === '1'): ?>
        <div class="mn-text-center mn-mt-16 mn-fs-13 mn-text-muted">
            <?php echo $this->t('auth.no_account'); ?> <a href="<?php echo $this->url('/register'); ?>" class="mn-text-primary mn-fw-500"><?php echo $this->t('auth.register_now'); ?></a>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php if ($locked && $lockRemaining > 0): ?>
<script>
(function(){
    var remaining = <?php echo $lockRemaining; ?>;
    var btn = document.querySelector('button[type="submit"]');
    var timer = setInterval(function(){
        remaining--;
        if (remaining <= 0) {
            clearInterval(timer);
            btn.disabled = false;
            btn.textContent = <?php echo json_encode(\app\Helpers\I18n::get('auth.login')); ?>;
            btn.className = 'mn-btn mn-btn-primary mn-w-full mn-fs-15';
        } else {
            var m = Math.floor(remaining / 60);
            var s = remaining % 60;
            btn.textContent = <?php echo json_encode(\app\Helpers\I18n::get('auth.lock_countdown')); ?> + ' ' + (m>0?m+<?php echo json_encode(\app\Helpers\I18n::get('time.minutes_short')); ?>:'') + s + <?php echo json_encode(\app\Helpers\I18n::get('time.seconds_short')); ?>;
        }
    }, 1000);
})();
</script>
<?php endif; ?>
<script>
(function(){
    // 验证码图片点击刷新
    var capImg = document.getElementById('captchaImg');
    if (capImg) capImg.addEventListener('click', function(){ this.src='<?php echo $this->url('/api/captcha'); ?>?'+Math.random(); });
})();
</script>
<script>
    // PoW 绑定已移至 pow.js 内部自动执行，此处不再重复 bind
</script>
<?php $this->endSection(); ?>