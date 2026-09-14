<?php
/**
 * 邀请码视图 — 我的邀请：生成/查看邀请码（invite 插件）
 * @file app/Views/invite/index.php
 */
$invites = $this->getData('invites', []);
$totalUsed = $this->getData('totalUsed', 0);
$totalValid = $this->getData('totalValid', 0);
$error = $this->getData('error', '');
$success = $this->getData('success', '');
$csrfToken = $this->e($this->getData('csrfToken'));
?>
<?php $this->section('title'); ?><?php echo \app\Helpers\I18n::get('plugin.invite.title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('css'); ?>
<link rel="stylesheet" href="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/invite/assets/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/style.css') ?: 1; ?>">
<?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section">
    <div class="mn-section-header">
        <h2><?php echo $this->icon('gift', 16); ?> <?php echo \app\Helpers\I18n::get('plugin.invite.title'); ?></h2>
    </div>
    <div class="mn-p-20">
        <div class="mn-grid-3 mn-mb-20">
            <div class="mn-text-center mn-p-16 mn-bg-body mn-rounded-8"><div class="mn-fs-24 mn-fw-700 mn-text-primary"><?php echo (int)$totalValid; ?></div><div class="mn-fs-12 mn-text-muted mn-mt-2"><?php echo \app\Helpers\I18n::get('plugin.invite.valid'); ?></div></div>
            <div class="mn-text-center mn-p-16 mn-bg-body mn-rounded-8"><div class="mn-fs-24 mn-fw-700 mn-text-success"><?php echo (int)$totalUsed; ?></div><div class="mn-fs-12 mn-text-muted mn-mt-2"><?php echo \app\Helpers\I18n::get('plugin.invite.used'); ?></div></div>
            <div class="mn-text-center mn-p-16 mn-bg-body mn-rounded-8"><div class="mn-fs-24 mn-fw-700 mn-text-warning"><?php echo (int)($this->getData('currentUser')['points'] ?? 0); ?></div><div class="mn-fs-12 mn-text-muted mn-mt-2"><?php echo \app\Helpers\I18n::get('plugin.invite.cur_points'); ?></div></div>
        </div>
        <?php if ($error): ?><div class="mn-alert mn-alert-error"><?php echo $this->e($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="mn-alert mn-alert-success"><?php echo $this->e($success); ?></div><?php endif; ?>
        <div class="mn-p-16 mn-bg-body mn-rounded-8 mn-mb-20">
            <h3 class="mn-fs-14 mn-fw-600 mn-mb-12"><?php echo \app\Helpers\I18n::get('plugin.invite.generate'); ?></h3>
            <form method="POST" class="mn-flex-center mn-gap-10 mn-flex-wrap">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="generate">
                <div><label class="mn-fs-12 mn-text-muted mn-block mn-mb-2"><?php echo \app\Helpers\I18n::get('plugin.invite.cost_points'); ?></label><input type="number" name="points_cost" value="10" min="1" max="999" class="mn-input mn-wp-100"></div>
                <div><label class="mn-fs-12 mn-text-muted mn-block mn-mb-2"><?php echo \app\Helpers\I18n::get('plugin.invite.expires_days'); ?></label><input type="number" name="expires_days" value="30" min="1" max="365" class="mn-input mn-wp-100"></div>
                <button type="submit" class="mn-btn mn-btn-primary mn-mt-16"><?php echo $this->icon('gift', 12); ?> <?php echo \app\Helpers\I18n::get('plugin.invite.generate_btn'); ?></button>
            </form>
        </div>
        <h3 class="mn-fs-14 mn-fw-600 mn-mb-12"><?php echo \app\Helpers\I18n::get('plugin.invite.records'); ?></h3>
        <?php if (empty($invites)): ?>
        <div class="mn-empty"><?php echo \app\Helpers\I18n::get('plugin.invite.empty'); ?></div>
        <?php else: ?>
        <table class="mn-table">
            <thead><tr class="mn-border-bottom-2"><?php $ths = [\app\Helpers\I18n::get('plugin.invite.th_code'),\app\Helpers\I18n::get('plugin.invite.th_cost'),\app\Helpers\I18n::get('plugin.invite.th_status'),\app\Helpers\I18n::get('plugin.invite.th_invitee'),\app\Helpers\I18n::get('plugin.invite.th_created')]; foreach($ths as $th): ?><th class="mn-text-left mn-p-8-6 mn-text-muted"><?php echo $th; ?></th><?php endforeach; ?></tr></thead>
            <tbody>
                <?php foreach ($invites as $inv):
                    $isUsed = !empty($inv['used_by_user_id']);
                    $isExpired = !$isUsed && !empty($inv['expires_at']) && strtotime($inv['expires_at']) < time();
                    if ($isUsed): $status = '<span class="mn-text-success">' . \app\Helpers\I18n::get('plugin.invite.status_used') . '</span>';
                    elseif ($isExpired): $status = '<span class="mn-text-muted">' . \app\Helpers\I18n::get('plugin.invite.status_expired') . '</span>';
                    else: $status = '<span class="mn-text-primary">' . \app\Helpers\I18n::get('plugin.invite.status_valid') . '</span>';
                    endif;
                ?>
                <tr class="mn-border-bottom">
                    <td class="mn-p-10-6 invite-code"><?php echo $this->e($inv['code']); ?></td>
                    <td class="mn-p-10-6"><?php echo (int)$inv['points_cost']; ?></td>
                    <td class="mn-p-10-6"><?php echo $status; ?></td>
                    <td class="mn-p-10-6 mn-text-muted"><?php echo $this->e($inv['used_by_username'] ?? '-'); ?></td>
                    <td class="mn-p-10-6 mn-text-muted mn-fs-12"><?php echo date('Y-m-d', strtotime($inv['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php $this->endSection(); ?>