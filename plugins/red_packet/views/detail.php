<?php
/**
 * 红包详情视图 — 抢红包、领取记录（red_packet 插件）
 * @file app/Views/plugins/red_packet/detail.php
 */
$packet = $this->getData('packet', []);
$claims = $this->getData('claims', []);
$myClaim = $this->getData('my_claim');
$isExpired = $this->getData('is_expired', false);
$isEmpty = $this->getData('is_empty', false);
$isCreator = $this->getData('is_creator', false);
$grabPoints = $_SESSION['redpacket_grab_points'] ?? 0;
unset($_SESSION['redpacket_grab_points']);
$msg = $_SESSION['redpacket_msg'] ?? ''; $success = $_SESSION['redpacket_success'] ?? false; unset($_SESSION['redpacket_msg'], $_SESSION['redpacket_success']);
?>
<?php $this->section('title'); ?><?php echo \app\Helpers\I18n::get('plugin.red_packet.detail_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section rp-modern-container">
    <?php if ($msg): ?>
    <div class="mn-alert rp-modern-alert <?php echo $success ? 'rp-modern-alert-success' : 'rp-modern-alert-error'; ?>"><?php echo $this->e($msg); ?></div>
    <?php endif; ?>
    <div class="rp-modern-body">
        <div class="rp-modern-card">
            <div class="rp-modern-card-bg"></div>
            <?php if ($myClaim): ?>
            <div class="rp-modern-card-content">
                <div class="rp-modern-creator"><?php echo \app\Helpers\I18n::get('plugin.red_packet.creator_pkt', ['name' => $this->e($packet['creator_name'] ?? \app\Helpers\I18n::get('plugin.red_packet.unknown'))]); ?></div>
                <div class="rp-modern-amount"><?php echo (int)$myClaim['points']; ?></div>
                <div class="rp-modern-unit"><?php echo \app\Helpers\I18n::get('plugin.red_packet.points_unit'); ?></div>
                <div class="rp-modern-msg"><?php echo \app\Helpers\I18n::get('plugin.red_packet.deposited'); ?></div>
            </div>
            <?php else: ?>
            <div class="rp-modern-card-content">
                <div class="rp-modern-creator-lg"><?php echo $this->e($packet['creator_name'] ?? \app\Helpers\I18n::get('plugin.red_packet.unknown')); ?></div>
                <div class="rp-modern-label"><?php echo \app\Helpers\I18n::get('plugin.red_packet.sent'); ?></div>
                <div class="rp-modern-gift"><?php echo $this->icon('gift', 48); ?></div>
                <?php if ($isExpired || $isEmpty): ?>
                <div class="rp-modern-gone"><?php echo $isExpired ? \app\Helpers\I18n::get('plugin.red_packet.expired_pkt') : \app\Helpers\I18n::get('plugin.red_packet.empty_pkt'); ?></div>
                <?php else: ?>
                <form method="post" action="<?php echo $this->url('/redpacket/' . (int)$packet['id'] . '/grab'); ?>">
                    <input type="hidden" name="csrf" value="<?php echo $this->e($this->getData('csrfToken')); ?>">
                    <button type="submit" class="rp-modern-grab-btn"><?php echo \app\Helpers\I18n::get('plugin.red_packet.open'); ?></button>
                </form>
                <div class="rp-modern-hint"><?php echo \app\Helpers\I18n::get('plugin.red_packet.open_hint'); ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="mn-flex-center mn-gap-16 mn-mb-12 mn-justify-center">
            <div class="mn-text-center"><div class="mn-fs-20 mn-fw-700"><?php echo (int)$packet['remaining_count']; ?></div><div class="mn-fs-12 mn-text-muted"><?php echo \app\Helpers\I18n::get('plugin.red_packet.rem_count'); ?></div></div>
            <div class="mn-text-center"><div class="mn-fs-20 mn-fw-700"><?php echo (int)$packet['remaining_points']; ?></div><div class="mn-fs-12 mn-text-muted"><?php echo \app\Helpers\I18n::get('plugin.red_packet.rem_points'); ?></div></div>
            <div class="mn-text-center"><div class="mn-fs-20 mn-fw-700"><?php echo (int)$packet['total_count']; ?></div><div class="mn-fs-12 mn-text-muted"><?php echo \app\Helpers\I18n::get('plugin.red_packet.total_count_lbl'); ?></div></div>
        </div>
        <div class="mn-fs-12 mn-text-muted"><?php echo $isExpired ? \app\Helpers\I18n::get('plugin.red_packet.expired_at', ['time' => $this->e($packet['expires_at'])]) : \app\Helpers\I18n::get('plugin.red_packet.valid_until', ['time' => $this->e($packet['expires_at'])]); ?></div>
        <?php if (!empty($packet['thread_id'])): ?>
        <div class="mn-mt-8">
            <a href="<?php echo $this->url('/thread/' . (int)$packet['thread_id']); ?>" class="mn-btn mn-btn-sm"><?php echo $this->icon('forum', 12); ?> <?php echo \app\Helpers\I18n::get('plugin.red_packet.from_thread', ['title' => $this->e($packet['thread_title'] ?? '')]); ?></a>
        </div>
        <?php endif; ?>
    </div>
    <div class="rp-modern-claims-section">
        <h3 class="rp-modern-claims-title"><?php echo \app\Helpers\I18n::get('plugin.red_packet.claims_title'); ?> <span class="mn-fs-13 mn-text-muted mn-fw-normal"><?php echo \app\Helpers\I18n::get('plugin.red_packet.claims_people', ['count' => count($claims)]); ?></span></h3>
        <?php if (empty($claims)): ?>
        <div class="mn-text-center mn-p-16 mn-fs-13 mn-text-muted"><?php echo \app\Helpers\I18n::get('plugin.red_packet.no_claims'); ?></div>
        <?php else:
            $bestPoints = 0;
            foreach ($claims as $c) { if ((int)$c['points'] > $bestPoints) $bestPoints = (int)$c['points']; }
        ?>
            <?php foreach ($claims as $c): $isBest = (int)$c['points'] >= $bestPoints && $bestPoints > 0; $name = $c['username'] ?? \app\Helpers\I18n::get('plugin.red_packet.unknown'); ?>
            <div class="mn-flex-between rp-modern-claim-item<?php echo $isBest ? ' rp-modern-claim-best' : ''; ?>">
                <div class="mn-flex-center mn-gap-10">
                    <?php if (!empty($c['avatar'])): ?>
                    <img src="<?php echo $this->e(\UPLOAD_URL . $c['avatar']); ?>" alt="" class="mn-wp-36 mn-hp-36 mn-rounded-4 mn-object-cover">
                    <?php else: ?>
                    <div class="rp-modern-avatar-placeholder"><?php echo $this->e(mb_substr($name, 0, 1)); ?></div>
                    <?php endif; ?>
                    <div><div class="rp-modern-claim-name"><?php echo $this->e($name); ?><?php if ($isBest): ?> <span class="mn-tag rp-modern-best-tag"><?php echo \app\Helpers\I18n::get('plugin.red_packet.best_hand'); ?></span><?php endif; ?></div><div class="mn-fs-12 mn-text-muted"><?php echo $this->e($c['created_at']); ?></div></div>
                </div>
                <div class="mn-fs-15 mn-fw-700 mn-text-error">+<?php echo (int)$c['points']; ?></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <div class="mn-text-center mn-mt-16"><a href="<?php echo $this->url('/redpacket'); ?>" class="mn-btn mn-btn-sm"><?php echo \app\Helpers\I18n::get('plugin.red_packet.back_list'); ?></a></div>
    </div>
</div>
<link rel="stylesheet" href="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/red_packet/assets/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/style.css') ?: 1; ?>">
<?php $this->endSection(); ?>