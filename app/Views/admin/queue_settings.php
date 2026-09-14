<?php
/**
 * 后台任务队列设置视图 — 三种调度模式选择 + 队列状态面板 + 手动立即处理
 * @file app/Views/admin/queue_settings.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.queue_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php
$settings = $this->getData('settings', []);
$queueStats = $this->getData('queue_stats', ['pending' => 0, 'done' => 0, 'failed' => 0]);
$msg = $this->getData('msg', '');
$error = $this->getData('error', '');
$csrfToken = $this->e($this->getData('csrfToken'));
$queueMode = $settings['queue_mode'] ?? 'sync';
$modeLabels = ['sync' => $this->t('admin.queue_sync'), 'cron' => $this->t('admin.queue_cron'), 'cli' => $this->t('admin.queue_cli')];
?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'settings_queue']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('sync', 16); ?> <?php echo $this->t('admin.queue_title'); ?></h1>
        </div>
        <?php if ($msg): ?><div class="alert alert-success"><?php echo $this->e($msg); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo $this->e($error); ?></div><?php endif; ?>

        <div class="admin-section admin-form-lg">
            <h3 class="admin-section-title"><?php echo $this->t('admin.queue_engine'); ?></h3>
            <p class="mn-fs-13 c-666"><?php echo $this->t('admin.queue_hint'); ?></p>

            <form method="POST" action="<?php echo $this->url('/admin/settings/queue'); ?>"
                  x-data="{ currentMode: '<?php echo $this->e($queueMode); ?>', selectedMode: '<?php echo $this->e($queueMode); ?>' }"
                  x-on:submit="if (currentMode === 'sync' && selectedMode !== 'sync' && !confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.queue_mode_switch_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) { $event.preventDefault(); }">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="save_mode">

                <div class="form-group">
                    <label class="maintenance-label"><?php echo $this->t('admin.queue_mode_label'); ?></label>
                    <select name="queue_mode" x-model="selectedMode" class="form-input w-320">
                        <option value="sync" <?php echo $queueMode === 'sync' ? 'selected' : ''; ?>><?php echo $this->t('admin.queue_sync'); ?></option>
                        <option value="cron" <?php echo $queueMode === 'cron' ? 'selected' : ''; ?>><?php echo $this->t('admin.queue_cron'); ?></option>
                        <option value="cli" <?php echo $queueMode === 'cli' ? 'selected' : ''; ?>><?php echo $this->t('admin.queue_cli'); ?></option>
                    </select>
                </div>

                <!-- 模式一：同步直写 -->
                <div class="queue-mode-card" x-show="selectedMode === 'sync'" x-cloak>
                    <h3 class="mn-fs-15 mn-fw-600"><?php echo $this->t('admin.queue_mode1_title'); ?></h3>
                    <p class="mn-fs-13 c-666"><b><?php echo $this->t('admin.queue_mode1_scene'); ?></b><?php echo $this->t('admin.queue_mode1_scene_val'); ?></p>
                    <p class="mn-fs-13 c-666"><b><?php echo $this->t('admin.queue_mode1_behavior'); ?></b><?php echo $this->t('admin.queue_mode1_behavior_val'); ?></p>
                    <p class="mn-fs-13 c-666"><b><?php echo $this->t('admin.queue_mode1_cost'); ?></b><?php echo $this->t('admin.queue_mode1_cost_val'); ?></p>
                </div>

                <!-- 模式二：Cron 定时触发 -->
                <div class="queue-mode-card" x-show="selectedMode === 'cron'" x-cloak>
                    <h3 class="mn-fs-15 mn-fw-600"><?php echo $this->t('admin.queue_mode2_title'); ?></h3>
                    <p class="mn-fs-13 c-666"><b><?php echo $this->t('admin.queue_mode1_scene'); ?></b><?php echo $this->t('admin.queue_mode2_scene_val'); ?></p>
                    <p class="mn-fs-13 c-666"><b><?php echo $this->t('admin.queue_mode1_behavior'); ?></b><?php echo $this->t('admin.queue_mode2_behavior_val'); ?></p>
                    <p class="mn-fs-13 c-666"><b><?php echo $this->t('admin.queue_mode2_guide'); ?></b></p>
                    <div class="nginx-snippet">
                        <?php echo $this->t('admin.queue_mode2_cmd_hint'); ?><br>
                        <code>* * * * * php /网站根目录/cli/worker.php --once &gt;&gt; /dev/null 2&gt;&amp;1</code><br>
                        <span class="mn-fs-12 c-999"><?php echo $this->t('admin.queue_mode2_cmd_tip'); ?></span>
                    </div>
                    <p class="mn-fs-12 c-999"><?php echo $this->t('admin.queue_mode2_alt'); ?></p>
                </div>

                <!-- 模式三：CLI 常驻消费 -->
                <div class="queue-mode-card" x-show="selectedMode === 'cli'" x-cloak>
                    <h3 class="mn-fs-15 mn-fw-600"><?php echo $this->t('admin.queue_mode3_title'); ?></h3>
                    <p class="mn-fs-13 c-666"><b><?php echo $this->t('admin.queue_mode1_scene'); ?></b><?php echo $this->t('admin.queue_mode3_scene_val'); ?></p>
                    <p class="mn-fs-13 c-666"><b><?php echo $this->t('admin.queue_mode1_behavior'); ?></b><?php echo $this->t('admin.queue_mode3_behavior_val'); ?></p>
                    <p class="mn-fs-13 c-666"><b><?php echo $this->t('admin.queue_mode2_guide'); ?></b><?php echo $this->t('admin.queue_mode3_guide_val'); ?></p>
                </div>

                <button type="submit" class="btn btn-primary"><?php echo $this->t('admin.plugin_save_config'); ?></button>
            </form>
        </div>

        <!-- 队列面板：轻量级状态指示器 -->
        <div class="admin-section">
            <h3 class="admin-section-title"><?php echo $this->t('admin.queue_panel'); ?></h3>
            <div class="queue-panel">
                <span class="queue-stat"><?php echo '⏳'; ?> <?php echo $this->t('admin.queue_pending'); ?><b><?php echo (int)$queueStats['pending']; ?></b> <?php echo $this->t('common.items'); ?></span>
                <span class="queue-stat"><?php echo '✅'; ?> <?php echo $this->t('admin.queue_done'); ?><b><?php echo (int)$queueStats['done']; ?></b> <?php echo $this->t('common.items'); ?></span>
                <span class="queue-stat"><?php echo '📦'; ?> <?php echo $this->t('admin.queue_current_mode'); ?><?php echo $this->e($modeLabels[$queueMode] ?? $this->t('admin.queue_sync')); ?></span>
                <span class="queue-stat queue-stat-failed" style="<?php echo (int)$queueStats['failed'] > 0 ? '' : 'display:none;'; ?>"><?php echo '⚠️'; ?> <?php echo $this->t('admin.queue_failed'); ?><b><?php echo (int)$queueStats['failed']; ?></b> <?php echo $this->t('common.items'); ?></span>
            </div>
            <form method="POST" action="<?php echo $this->url('/admin/settings/queue'); ?>" class="mn-mt-12 mn-inline-block" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.queue_flush_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="flush">
                <button type="submit" class="btn btn-secondary"><?php echo $this->icon('refresh', 14); ?> <?php echo $this->t('admin.queue_flush_btn'); ?></button>
            </form>
        </div>
    </main>
</div>
<?php $this->endSection(); ?>