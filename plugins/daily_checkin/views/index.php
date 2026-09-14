<?php
/**
 * 每日签到插件视图 — Modern 主题版
 */
?>
<?php $this->section('title'); ?><?php echo \app\Helpers\I18n::get('plugin.daily_checkin.title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php $this->include('plugins/daily_checkin/_area'); ?>
<link rel="stylesheet" href="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/daily_checkin/assets/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/style.css') ?: 1; ?>">
<?php $this->endSection(); ?>
