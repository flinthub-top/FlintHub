<?php
/**
 * 单页/链接管理插件 前台单页视图（干净布局）
 * @file plugins/single_page/views/page.php
 * @package Plugin\SinglePage
 */
$page = $this->getData('page', []);
?>
<?php $this->section('title'); ?><?php echo $this->e($page['title'] ?? ''); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('css'); ?>
<link rel="stylesheet" href="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/single_page/assets/style.css">
<?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section">
    <div class="mn-p-24-20-20">
        <h1 class="mn-fs-20 mn-fw-600 mn-lh-14 mn-mb-16"><?php echo $this->e($page['title'] ?? ''); ?></h1>
        <div class="post-text-content post-text-color mn-fs-15 mn-lh-18">
            <?php echo $this->formatPostContent($this->decodeContent($page['content'] ?? '')); ?>
        </div>
    </div>
</div>
<?php $this->endSection(); ?>
