<?php
/**
 * 社区治理插件 — 「封禁配置」面板（admin_index 内嵌片段）
 * @file plugins/mod_system/views/_config_panel.php
 * @package Plugin\ModSystem
 */
use app\Helpers\I18n as _T;

$I         = function (string $k, array $p = []) { return _T::get($k, $p); };
$settings  = $this->getData('settings', []);
$csrfToken = $this->e($this->getData('csrfToken'));
$success   = (string)$this->getData('config_success', '');
?>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?php echo $this->e($success); ?></div>
<?php endif; ?>

<form method="POST" action="<?php echo $this->url('/admin/mod-system/config/save'); ?>" class="card mn-p-15">
    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">

    <div class="form-group mb-10">
        <label class="mn-fs-14 mn-fw-500"><?php echo $I('plugin.mod_system.cfg_auto_audit_count'); ?></label>
        <input type="number" name="auto_audit_count" class="form-input w-140" min="1" max="100"
               value="<?php echo (int)($settings['auto_audit_count'] ?? 3); ?>">
        <div class="mn-fs-12 c-999 mn-mt-2"><?php echo $I('plugin.mod_system.cfg_auto_audit_count_help'); ?></div>
    </div>

    <div class="form-group mb-10">
        <label class="mn-fs-14 mn-fw-500"><?php echo $I('plugin.mod_system.cfg_daily_limit'); ?></label>
        <input type="number" name="daily_limit" class="form-input w-140" min="1" max="1000"
               value="<?php echo (int)($settings['daily_limit'] ?? 5); ?>">
        <div class="mn-fs-12 c-999 mn-mt-2"><?php echo $I('plugin.mod_system.cfg_daily_limit_help'); ?></div>
    </div>

    <div class="form-group mb-10">
        <label class="mn-fs-14 mn-fw-500"><?php echo $I('plugin.mod_system.cfg_cooldown'); ?></label>
        <input type="number" name="cooldown" class="form-input w-140" min="0" max="86400"
               value="<?php echo (int)($settings['cooldown'] ?? 300); ?>">
        <div class="mn-fs-12 c-999 mn-mt-2"><?php echo $I('plugin.mod_system.cfg_cooldown_help'); ?></div>
    </div>

    <div class="form-group mb-10">
        <label class="mn-fs-14 mn-fw-500"><?php echo $I('plugin.mod_system.cfg_ban_days'); ?></label>
        <input type="number" name="ban_days" class="form-input w-140" min="0" max="3650"
               value="<?php echo (int)($settings['ban_days'] ?? 7); ?>">
        <div class="mn-fs-12 c-999 mn-mt-2"><?php echo $I('plugin.mod_system.cfg_ban_days_help'); ?></div>
    </div>

    <div class="form-group mb-10">
        <label class="mn-fs-14 mn-fw-500"><?php echo $I('plugin.mod_system.cfg_ban_group_id'); ?></label>
        <input type="number" name="ban_group_id" class="form-input w-140" min="0"
               value="<?php echo (int)($settings['ban_group_id'] ?? 0); ?>">
        <div class="mn-fs-12 c-999 mn-mt-2"><?php echo $I('plugin.mod_system.cfg_ban_group_id_help'); ?></div>
    </div>

    <div class="form-group mb-10">
        <label class="mn-fs-14 mn-fw-500"><?php echo $I('plugin.mod_system.cfg_moderator_group_ids'); ?></label>
        <input type="text" name="moderator_group_ids" class="form-input"
               placeholder="3,5" value="<?php echo $this->e((string)($settings['moderator_group_ids'] ?? '')); ?>">
        <div class="mn-fs-12 c-999 mn-mt-2"><?php echo $I('plugin.mod_system.cfg_moderator_group_ids_help'); ?></div>
    </div>

    <div class="form-group mn-mb-15">
        <label class="mn-fs-14 mn-fw-500">
            <input type="checkbox" name="notify_enabled" value="1"
                   <?php echo ($settings['notify_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
            <?php echo $I('plugin.mod_system.cfg_notify_enabled'); ?>
        </label>
        <div class="mn-fs-12 c-999 mn-mt-2"><?php echo $I('plugin.mod_system.cfg_notify_enabled_help'); ?></div>
    </div>

    <button type="submit" class="btn-save"><?php echo $I('plugin.mod_system.save'); ?></button>
</form>