<?php
/**
 * 勋章中心 — 勋章墙视图（Modern 主题）
 * @file plugins/medal/views/index.php
 */
$medals = $this->getData('medals', []);
$ownedIds = $this->getData('ownedIds', []);
$wearingIds = $this->getData('wearingIds', []);
$wearLimit = (int)$this->getData('wearLimit', 3);
$newGranted = (int)$this->getData('newGranted', 0);
$isOwner = (bool)$this->getData('isOwner', false);
$csrfToken = $this->getData('csrfToken');
$ownedSet = array_fill_keys(array_map('intval', $ownedIds), true);
?>
<?php $this->section('title'); ?><?php echo \app\Helpers\I18n::get('plugin.medal.center_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('css'); ?>
<link rel="stylesheet" href="<?php echo $this->url('/plugins/medal/assets/style.css?v=' . (@filemtime(__DIR__ . '/../assets/style.css') ?: 1)); ?>">
<?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section medal-page">
    <div class="mn-section-header">
        <h2><?php echo $this->icon('award', 16); ?> <?php echo \app\Helpers\I18n::get('plugin.medal.center_title'); ?></h2>
    </div>
    <div class="medal-page-body mn-p-20">

        <?php if ($newGranted > 0): ?>
        <div class="mn-alert mn-alert-success"><?php echo \app\Helpers\I18n::get('plugin.medal.congrats', ['count' => $newGranted]); ?></div>
        <?php endif; ?>

        <?php if ($isOwner): ?>
        <!-- 我的勋章 + 佩戴设置 -->
        <div class="medal-my-section">
            <h3 class="medal-section-title"><?php echo $this->icon('user', 14); ?> <?php echo \app\Helpers\I18n::get('plugin.medal.my_medals', ['limit' => $wearLimit]); ?></h3>
            <?php if (empty($ownedIds)): ?>
            <p class="medal-empty mn-text-muted"><?php echo \app\Helpers\I18n::get('plugin.medal.no_medals'); ?></p>
            <?php else: ?>
            <p class="medal-hint"><?php echo \app\Helpers\I18n::get('plugin.medal.wear_hint'); ?></p>
            <div class="medal-my-grid">
                <?php foreach ($medals as $m): $mid = (int)$m['id']; if (!isset($ownedSet[$mid])) continue; ?>
                <label class="medal-pick<?php echo isset($wearingIds[$mid]) ? ' medal-pick-on' : ''; ?>">
                    <input type="checkbox" class="medal-pick-input" value="<?php echo $mid; ?>" <?php echo isset($wearingIds[$mid]) ? 'checked' : ''; ?>>
                    <?php echo \Plugin\Medal\Plugin::renderMedal($m, 40); ?>
                    <span class="medal-pick-name"><?php echo $this->e($m['name'] ?? ''); ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <span class="mn-fs-12 mn-text-muted medal-wear-msg" id="medalWearMsg" data-limit="<?php echo (int)$wearLimit; ?>" data-msg-limit="<?php echo $this->e(\app\Helpers\I18n::get('plugin.medal.limit_msg', ['limit' => $wearLimit])); ?>"></span>
            <?php endif; ?>
        </div>
        <hr class="medal-divider">
        <?php endif; ?>

        <!-- 全部勋章 -->
        <h3 class="medal-section-title"><?php echo $this->icon('trophy', 14); ?> <?php echo \app\Helpers\I18n::get('plugin.medal.all_medals'); ?></h3>
        <?php if (empty($medals)): ?>
        <p class="medal-empty mn-text-muted"><?php echo \app\Helpers\I18n::get('plugin.medal.none_set'); ?></p>
        <?php else: ?>
        <div class="medal-all-grid">
            <?php foreach ($medals as $m): $mid = (int)$m['id']; $owned = isset($ownedSet[$mid]); ?>
            <div class="medal-card<?php echo $owned ? ' medal-card-owned' : ' medal-card-locked'; ?>">
                <div class="medal-card-icon"><?php echo \Plugin\Medal\Plugin::renderMedal($m, 48); ?></div>
                <div class="medal-card-name"><?php echo $this->e($m['name'] ?? ''); ?></div>
                <div class="medal-card-desc"><?php echo $this->e($m['description'] ?? ''); ?></div>
                <div class="medal-card-cond">
                    <?php if ($owned): ?>
                    <span class="medal-cond-owned"><?php echo $this->icon('check-circle', 12); ?> <?php echo \app\Helpers\I18n::get('plugin.medal.owned'); ?></span>
                    <?php else: ?>
                    <span class="medal-cond-txt"><?php echo $this->e(\Plugin\Medal\Plugin::conditionLabel($m)); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/medal/assets/script.js?v=<?php echo @filemtime(__DIR__ . '/../assets/script.js') ?: 1; ?>"></script>
<?php $this->endSection(); ?>
