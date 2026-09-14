<?php
/**
 * 通知中心视图 — 通知列表（未读高亮/全选删除/分页）
 * 通知插件内置化后的核心视图
 * @file app/Views/notifications/index.php
 */
$notifications = $this->getData('notifications', []);
$page = $this->getData('page', 1);
$totalPages = $this->getData('totalPages', 1);
$total = $this->getData('total', 0);

// 类型 → 图标 映射（含 mod_system 原始变体：整改D 视图层补齐）
$iconMap = [
    'reply' => 'reply',
    'vote' => 'star',
    'welcome' => 'user',
    'points' => 'money',
    'review_pending' => 'clock',
    'review_approved' => 'check',
    'review_rejected' => 'close',
    'blacklist' => 'close',
    'mod_report' => 'warning',
    'mod_reported' => 'close',
    'mod_report_new' => 'warning',
    'mod_auto_audit' => 'warning',
    'mod_report_result' => 'warning',
];
// 类型 → 背景色 映射
$colorMap = [
    'vote' => 'var(--mn-warning)',
    'points' => 'var(--mn-success)',
    'review_approved' => 'var(--mn-success)',
    'review_rejected' => 'var(--mn-error)',
    'blacklist' => 'var(--mn-error)',
    'mod_report' => 'var(--mn-warning)',
    'mod_reported' => 'var(--mn-error)',
    'mod_report_new' => 'var(--mn-warning)',
    'mod_auto_audit' => 'var(--mn-warning)',
    'mod_report_result' => 'var(--mn-warning)',
];
?>
<?php $this->section('title'); ?><?php echo \app\Helpers\I18n::get('plugin.notifications.title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('css'); ?>
<link rel="stylesheet" href="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/assets/css/notifications.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/notifications.css') ?: 1; ?>">
<?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section">
    <div class="mn-section-header">
        <h2><?php echo $this->icon('bell', 16); ?> <?php echo \app\Helpers\I18n::get('plugin.notifications.title'); ?> <?php if ($total > 0): ?><span class="mn-fs-12 mn-text-muted"><?php echo \app\Helpers\I18n::get('plugin.notifications.count_suffix', ['count' => $total]); ?></span><?php endif; ?></h2>
    </div>
    <div class="mn-p-20">
        <?php if (empty($notifications)): ?>
        <div class="mn-empty"><?php echo \app\Helpers\I18n::get('plugin.notifications.empty'); ?></div>
        <?php else: ?>
        <form method="post" action="<?php echo $this->url('/notifications'); ?>" id="notiForm" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('plugin.notifications.delete_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
            <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
            <div class="mn-flex-center mn-gap-10 mn-mb-12">
                <label class="mn-fs-13 mn-cursor-pointer"><input type="checkbox" id="checkAll" x-data x-on:change="document.querySelectorAll('#notiForm input[name=\'ids[]\']').forEach(c => c.checked = $el.checked)"> <span class="mn-ml-4"><?php echo \app\Helpers\I18n::get('plugin.notifications.select_all'); ?></span></label>
                <button type="submit" class="mn-btn mn-btn-sm mn-text-warning"><?php echo \app\Helpers\I18n::get('plugin.notifications.delete_sel'); ?></button>
            </div>
            <?php foreach ($notifications as $n): ?>
            <?php
            $t = $n['type'] ?? '';
            $ic = $this->icon($iconMap[$t] ?? 'info', 12);
            $col = $colorMap[$t] ?? 'var(--mn-primary)';
            ?>
            <div class="mn-flex-center mn-gap-10 mn-p-12 mn-rounded-8 mn-mb-8 noti-card<?php echo empty($n['is_read']) ? ' noti-unread' : ''; ?>">
                <input type="checkbox" name="ids[]" value="<?php echo (int)$n['id']; ?>" class="mn-accent-primary">
                <div class="mn-icon-circle noti-circle" style="--noti-bg:<?php echo $col; ?>;">
                    <span class="mn-text-white mn-fs-12"><?php echo $ic; ?></span>
                </div>
                <div class="mn-flex-1">
                    <div class="mn-fs-14"><?php if (!empty($n['link'])): ?><a href="<?php echo $this->e($n['link']); ?>" class="mn-text"><?php endif; ?><?php echo $this->e($n['title']); ?><?php if (!empty($n['link'])): ?></a><?php endif; ?></div>
                    <?php if (!empty($n['summary'])): ?>
                    <div class="mn-fs-12 mn-text-muted mn-mt-2"><?php echo $this->e($n['summary']); ?></div>
                    <?php endif; ?>
                    <div class="mn-fs-12 mn-text-muted mn-mt-2"><?php echo $this->e(date('Y-m-d H:i', strtotime($n['created_at']))); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </form>
        <?php echo $this->pagination($page, $totalPages, '/notifications?page={page}'); ?>
        <?php endif; ?>
        <div class="mn-mt-12"><a href="/" class="mn-btn mn-btn-sm"><?php echo $this->icon('back', 12); ?> <?php echo \app\Helpers\I18n::get('plugin.notifications.back_home'); ?></a></div>
    </div>
</div>
<?php $this->endSection(); ?>