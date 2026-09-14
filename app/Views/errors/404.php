<?php
/**
 * 404 错误页视图 — 页面不存在提示（前台/插件共用）
 * @file app/Views/errors/404.php
 */
$message = $this->getData('message', $this->t('error.not_found'));
?>
<?php $this->section('title'); ?><?php echo $this->t('error.404'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section">
    <div class="mn-text-center" style="padding:60px 20px;">
        <div class="mn-fs-48 mn-fw-700 mn-text-muted">404</div>
        <h1 class="mn-fs-20 mn-fw-600 mn-mt-12"><?php echo $this->e($message); ?></h1>
        <p class="mn-text-muted mn-fs-14 mn-mt-8"><?php echo $this->t('error.404_hint'); ?></p>
        <div class="mn-flex-center mn-gap-12" style="justify-content:center;margin-top:48px;">
            <a href="<?php echo $this->url('/'); ?>" class="mn-btn mn-btn-primary"><?php echo $this->icon('home', 14); ?> <?php echo $this->t('nav.home'); ?></a>
        </div>
    </div>
</div>
<?php $this->endSection(); ?>
