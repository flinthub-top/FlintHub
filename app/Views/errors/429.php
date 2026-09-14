<?php
/**
 * 429 错误页视图 — 操作过于频繁（限流提示，前台/插件共用）
 * 走主布局（与 404 风格一致）；由 RateLimiter::hit() 页面场景渲染
 * @file app/Views/errors/429.php
 */
$retryAfter = (int)$this->getData('retry_after', 30);
?>
<?php $this->section('title'); ?><?php echo $this->t('error.429'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section">
    <div class="mn-text-center" style="padding:60px 20px;">
        <div class="mn-fs-48 mn-fw-700" style="color:var(--mn-warning, #d97706);">429</div>
        <h1 class="mn-fs-20 mn-fw-600 mn-mt-12"><?php echo $this->t('error.429_title'); ?></h1>
        <p class="mn-text-muted mn-fs-14 mn-mt-8"><?php echo $this->t('error.429_hint', ['sec' => $retryAfter]); ?></p>
        <div class="mn-flex-center mn-gap-12" style="justify-content:center;margin-top:48px;">
            <a href="<?php echo $this->url('/'); ?>" class="mn-btn mn-btn-primary"><?php echo $this->icon('home', 14); ?> <?php echo $this->t('nav.home'); ?></a>
        </div>
    </div>
</div>
<?php $this->endSection(); ?>
