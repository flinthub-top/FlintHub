<?php
/**
 * 红包列表视图 — 发红包、可抢红包、我的红包（red_packet 插件）
 * @file app/Views/plugins/red_packet/index.php
 */
$msg = $_SESSION['redpacket_msg'] ?? ''; $success = $_SESSION['redpacket_success'] ?? false; unset($_SESSION['redpacket_msg'], $_SESSION['redpacket_success'], $_SESSION['redpacket_grab_points']);
$available = $this->getData('available', []);
$myPackets = $this->getData('my_packets', []);
$now = $this->getData('now', date('Y-m-d H:i:s'));
?>
<?php $this->section('title'); ?><?php echo \app\Helpers\I18n::get('plugin.red_packet.title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section rp-modern-container">
    <div class="mn-p-24 mn-text-center">
        <div class="mn-text-error mn-mb-8"><?php echo $this->icon('gift', 48); ?></div>
        <h1 class="mn-fs-22 mn-fw-700 rp-modern-index-title"><?php echo \app\Helpers\I18n::get('plugin.red_packet.title'); ?></h1>
        <p class="mn-text-muted mn-fs-14 rp-modern-index-subtitle"><?php echo \app\Helpers\I18n::get('plugin.red_packet.subtitle'); ?></p>
        <div class="rp-modern-points-badge"><?php echo \app\Helpers\I18n::get('plugin.red_packet.my_points'); ?><strong class="rp-modern-points-val"><?php echo (int)$this->getData('points', 0); ?></strong></div>
    </div>
    <?php if ($msg): ?>
    <div class="mn-alert rp-modern-alert-index <?php echo $success ? 'rp-modern-alert-success' : 'rp-modern-alert-error'; ?>"><?php echo $this->e($msg); ?></div>
    <?php endif; ?>
    <div class="mn-bg-body mn-rounded-10 mn-border rp-modern-create-card">
        <div class="mn-flex-center mn-gap-8 mn-mb-12"><span class="mn-fs-15"><?php echo $this->icon('gift', 16); ?></span><span class="mn-fs-15 mn-fw-600"><?php echo \app\Helpers\I18n::get('plugin.red_packet.create'); ?></span></div>
        <form method="post" action="<?php echo $this->url('/redpacket/create'); ?>">
            <input type="hidden" name="csrf" value="<?php echo $this->e($this->getData('csrfToken')); ?>">
            <div class="mn-flex mn-gap-10 mn-mb-10">
                <div class="mn-flex-1"><label class="mn-fs-12 mn-text-muted mn-block mn-mb-2"><?php echo \app\Helpers\I18n::get('plugin.red_packet.total_points'); ?></label><input type="number" name="total_points" min="1" max="99999" required class="mn-input rp-modern-form-input"></div>
                <div class="mn-flex-1"><label class="mn-fs-12 mn-text-muted mn-block mn-mb-2"><?php echo \app\Helpers\I18n::get('plugin.red_packet.total_count'); ?></label><input type="number" name="total_count" min="1" max="100" required class="mn-input rp-modern-form-input"></div>
            </div>
            <div class="mn-flex mn-gap-10 mn-mb-10">
                <div class="mn-flex-1"><label class="mn-fs-12 mn-text-muted mn-block mn-mb-2"><?php echo \app\Helpers\I18n::get('plugin.red_packet.expire'); ?></label><select name="expire_hours" class="mn-select rp-modern-form-select"><option value="1"><?php echo \app\Helpers\I18n::get('plugin.red_packet.expire_hour', ['n' => 1]); ?></option><option value="6"><?php echo \app\Helpers\I18n::get('plugin.red_packet.expire_hour', ['n' => 6]); ?></option><option value="24" selected><?php echo \app\Helpers\I18n::get('plugin.red_packet.expire_hour', ['n' => 24]); ?></option><option value="48"><?php echo \app\Helpers\I18n::get('plugin.red_packet.expire_hour', ['n' => 48]); ?></option><option value="72"><?php echo \app\Helpers\I18n::get('plugin.red_packet.expire_hour', ['n' => 72]); ?></option><option value="168"><?php echo \app\Helpers\I18n::get('plugin.red_packet.expire_day', ['n' => 7]); ?></option></select></div>
                <div class="mn-flex-1"><div class="mn-fs-11 mn-text-muted mn-mt-18"><?php echo \app\Helpers\I18n::get('plugin.red_packet.min_hint'); ?></div></div>
            </div>
            <button type="submit" class="mn-btn mn-w-full mn-fs-15 rp-modern-submit-btn"><?php echo $this->icon('gift', 14); ?> <?php echo \app\Helpers\I18n::get('plugin.red_packet.submit'); ?></button>
        </form>
    </div>
    <div class="rp-modern-lists-wrap">
        <h3 class="mn-fs-15 mn-fw-600 mn-mb-12"><?php echo $this->icon('list', 14); ?> <?php echo \app\Helpers\I18n::get('plugin.red_packet.available'); ?></h3>
        <?php if (empty($available)): ?>
        <div class="mn-text-center rp-modern-empty"><p class="mn-fs-28 mn-mb-8"><?php echo $this->icon('gift', 36); ?></p><p><?php echo \app\Helpers\I18n::get('plugin.red_packet.empty'); ?></p><p class="mn-fs-13"><?php echo \app\Helpers\I18n::get('plugin.red_packet.empty_hint'); ?></p></div>
        <?php else: ?>
            <?php foreach ($available as $pkt): ?>
            <a href="<?php echo $this->url('/redpacket/' . (int)$pkt['id']); ?>" class="mn-flex-between rp-modern-packet-item">
                <div class="mn-flex-center mn-gap-10">
                    <span class="mn-text-error mn-fs-20"><?php echo $this->icon('gift', 20); ?></span>
                    <div><div class="mn-fs-14 mn-fw-500"><?php echo \app\Helpers\I18n::get('plugin.red_packet.creator_pkt', ['name' => $this->e($pkt['creator_name'] ?? \app\Helpers\I18n::get('plugin.red_packet.unknown'))]); ?></div><div class="mn-fs-12 mn-text-muted"><?php echo \app\Helpers\I18n::get('plugin.red_packet.remaining', ['count' => (int)$pkt['remaining_count'], 'total' => (int)$pkt['total_count'], 'points' => (int)$pkt['total_points']]); ?></div></div>
                </div>
                <span class="rp-modern-claim-tag <?php echo !empty($pkt['already_claimed']) ? 'rp-modern-claim-tag-done' : 'rp-modern-claim-tag-go'; ?>"><?php echo !empty($pkt['already_claimed']) ? \app\Helpers\I18n::get('plugin.red_packet.claimed') : \app\Helpers\I18n::get('plugin.red_packet.grab'); ?></span>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>
        <h3 class="mn-fs-15 mn-fw-600 rp-modern-list-title"><?php echo $this->icon('share', 14); ?> <?php echo \app\Helpers\I18n::get('plugin.red_packet.my_packets'); ?></h3>
        <?php if (empty($myPackets)): ?>
        <div class="mn-text-center mn-p-16 mn-text-muted mn-fs-13"><?php echo \app\Helpers\I18n::get('plugin.red_packet.no_packets'); ?></div>
        <?php else: ?>
            <?php foreach ($myPackets as $pkt): ?>
            <a href="<?php echo $this->url('/redpacket/' . (int)$pkt['id']); ?>" class="mn-flex-between rp-modern-packet-item">
                <div class="mn-flex-center mn-gap-10">
                    <span class="mn-text-error mn-fs-20"><?php echo $this->icon('gift', 20); ?></span>
                    <div><div class="mn-fs-14 mn-fw-500"><?php echo \app\Helpers\I18n::get('plugin.red_packet.pts_pkt', ['points' => (int)$pkt['total_points']]); ?></div><div class="mn-fs-12 mn-text-muted"><?php echo (int)$pkt['total_count']; ?> 个 · <?php echo $now > $pkt['expires_at'] ? \app\Helpers\I18n::get('plugin.red_packet.expired') : ((int)$pkt['remaining_count'] <= 0 ? \app\Helpers\I18n::get('plugin.red_packet.finished') : \app\Helpers\I18n::get('plugin.red_packet.remaining_n', ['count' => (int)$pkt['remaining_count']])); ?></div></div>
                </div>
                <small class="mn-text-muted"><?php echo substr($pkt['created_at'], 5, 11); ?></small>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<link rel="stylesheet" href="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/red_packet/assets/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/style.css') ?: 1; ?>">
<?php $this->endSection(); ?>