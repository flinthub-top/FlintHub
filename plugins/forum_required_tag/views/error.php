<?php
/**
 * 版块强制 Tag 插件 — 拦截错误页（route_before_dispatch 校验失败时渲染）
 * @file plugins/forum_required_tag/views/error.php
 */
$error = $this->getData('error', '');
$back = $this->getData('back', '/');
$bp = \defined('BASE_PATH') ? BASE_PATH : '';
?>
<?php $this->section('title'); ?><?php echo $this->t('plugin.forum_required_tag.err_page_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('css'); ?>
<link rel="stylesheet" href="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/forum_required_tag/assets/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/style.css') ?: 1; ?>">
<?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-container mn-py-40 mn-text-center">
    <div class="mn-alert mn-alert-error mn-maxw-480 frt-error-box">
        <div class="frt-error-icon"><?php echo $this->icon('ban', 28); ?></div>
        <h2 class="mn-mt-12 mn-mb-8"><?php echo $this->t('plugin.forum_required_tag.err_page_title'); ?></h2>
        <p class="mn-fs-14 mn-text-muted mn-mb-16"><?php echo $this->e($error); ?></p>
        <a href="<?php echo $bp; ?><?php echo $this->e($back); ?>" class="mn-btn mn-btn-primary"><?php echo $this->t('plugin.forum_required_tag.back_btn'); ?></a>
    </div>
</div>
<?php $this->endSection(); ?>
