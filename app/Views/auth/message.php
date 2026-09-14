<?php
/**
 * 认证消息视图 — 提示页（成功/失败信息展示）
 * @file app/Views/auth/message.php
 */
$title = $this->getData('title', '');
$message = $this->getData('message', '');
?>
<?php $this->section('title'); ?><?php echo $this->e($title); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section mn-maxw-500" style="margin:40px auto;">
    <div style="padding:40px 24px;text-align:center;">
        <h2 class="mn-fs-18 mn-fw-600 mn-mb-12"><?php echo $this->e($title); ?></h2>
        <p class="mn-fs-14 mn-text-secondary mn-mb-20"><?php echo $this->e($message); ?></p>
        <a href="<?php echo $this->url('/'); ?>" class="mn-btn mn-btn-primary"><?php echo $this->icon('home', 12); ?> <?php echo $this->t('nav.home'); ?></a>
    </div>
</div>
<?php $this->endSection(); ?>