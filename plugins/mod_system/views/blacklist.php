<?php
/**
 * 社区治理插件 前台小黑屋公示页（公开）
 * @file plugins/mod_system/views/blacklist.php
 */
$items = $this->getData('items', []);
?>
<?php $this->section('title'); ?><?php echo \app\Helpers\I18n::get('plugin.mod_system.admin_bans'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php $cssVer = @filemtime(__DIR__ . '/../assets/style.css') ?: 1; ?>
<link rel="stylesheet" href="<?php echo $this->url('/plugins/mod_system/assets/style.css'); ?>?v=<?php echo $cssVer; ?>">
<div class="mn-section mn-p-20">
    <h1 class="mn-fs-20 mn-fw-700 mn-mb-16"><?php echo $this->icon('lock', 16); ?> <?php echo \app\Helpers\I18n::get('plugin.mod_system.admin_bans'); ?></h1>
    <p class="mn-fs-13 mn-text-muted mn-mb-16"><?php echo \app\Helpers\I18n::get('plugin.mod_system.blacklist_desc'); ?></p>

    <?php if (empty($items)): ?>
        <p class="mn-text-muted mn-py-16"><?php echo \app\Helpers\I18n::get('plugin.mod_system.blacklist_empty'); ?></p>
    <?php else: ?>
        <div class="mod-blacklist-table-wrap">
                <table class="mod-blacklist-table">
                    <thead>
                        <tr>
                            <th><?php echo \app\Helpers\I18n::get('plugin.mod_system.ban_user'); ?></th>
                            <th><?php echo \app\Helpers\I18n::get('plugin.mod_system.ban_reason'); ?></th>
                            <th><?php echo \app\Helpers\I18n::get('plugin.mod_system.ban_by'); ?></th>
                            <th><?php echo \app\Helpers\I18n::get('plugin.mod_system.ban_time'); ?></th>
                            <th><?php echo \app\Helpers\I18n::get('plugin.mod_system.ban_duration'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $b): ?>
                        <tr>
                            <td class="mn-fs-15 mn-fw-600"><?php echo $this->e($b['username']); ?></td>
                            <td><?php echo $this->e($b['reason'] !== '' ? $b['reason'] : \app\Helpers\I18n::get('plugin.mod_system.blacklist_no_reason')); ?></td>
                            <td class="mn-text-muted"><?php echo $this->e($b['banned_by']); ?></td>
                            <td class="mn-text-muted"><?php echo $this->e($b['banned_at']); ?></td>
                            <td><?php echo $this->e($b['is_permanent'] ? \app\Helpers\I18n::get('plugin.mod_system.ban_no_expiry') : $b['expires_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
</div>
<?php $this->endSection(); ?>