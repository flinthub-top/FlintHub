<?php
/**
 * SplitDB 引擎设置视图（操作型）— 桶数调整 / 自动扩容 / 扩容记录 / 一致性检查
 * @file app/Views/admin/database/settings.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.db_settings_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php
$bucketSize      = (int)$this->getData('bucketSize', 32);
$configWritable  = (bool)$this->getData('configWritable', false);
$autoExpand      = $this->getData('autoExpand', ['enabled' => '0', 'threshold' => '0', 'lastAt' => 0]);
$autoExpandMsg   = $this->getData('autoExpandMsg', '');
$expandRecords   = $this->getData('expandRecords', []);
$consistency     = $this->getData('consistency', null);
$totalThreads    = (int)$this->getData('totalThreads', 0);
$totalPosts      = (int)$this->getData('totalPosts', 0);   // 数据总量（主题+回复），用于"适用人群提示条"
$bucketCapacity  = (int)$this->getData('bucketCapacity', 50000);   // 单桶最大安全主题帖数
$archiveDays     = (int)$this->getData('archiveDays', 365);        // 归档天数（0=关闭）
$error           = $this->getData('error', '');
$success         = $this->getData('success', '');
$csrfToken       = $this->e($this->getData('csrfToken'));

$successMsg = '';
if ($success === 'buckets')   $successMsg = \app\Helpers\I18n::get('admin.db_success_buckets');
elseif ($success === 'autoexpand') $successMsg = \app\Helpers\I18n::get('admin.db_success_autoexpand');
elseif ($success === 'capacity')   $successMsg = \app\Helpers\I18n::get('admin.db_success_capacity');
elseif ($success === 'archive')    $successMsg = \app\Helpers\I18n::get('admin.db_success_archive');

$actionLabels = [
    'auto_expand'  => \app\Helpers\I18n::get('admin.db_action_auto'),
    'admin_action' => \app\Helpers\I18n::get('admin.db_action_manual'),
];
?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'database_settings']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('settings', 18); ?> <?php echo $this->t('admin.db_settings_title'); ?></h1>
        </div>
        <?php if ($successMsg): ?><div class="alert alert-success"><?php echo $successMsg; ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo $this->e($error); ?></div><?php endif; ?>
        <?php if ($autoExpandMsg): ?><div class="alert alert-success"><?php echo $this->e($autoExpandMsg); ?></div><?php endif; ?>

        <!-- 适用人群提示条：普通站长先看这里（≤10 万无需关注本页；>10 万再逐项阅读） -->
        <?php
        $laymanCount = number_format($totalPosts);
        if ($totalPosts <= 100000) {
            $laymanMsg = \app\Helpers\I18n::get('admin.db_layman_comfort', ['count' => $laymanCount]);
            $laymanCls = 'alert-success';
        } else {
            $laymanMsg = \app\Helpers\I18n::get('admin.db_layman_attention', ['count' => $laymanCount]);
            $laymanCls = 'alert-warning';
        }
        ?>
        <div class="alert <?php echo $laymanCls; ?>"><?php echo $laymanMsg; ?></div>
        <p class="mn-fs-13 c-999 mn-mb-15"><?php echo $this->t('admin.db_layman_intro'); ?></p>

        <!-- ⚠️ 全局操作注意事项（误操作防护） -->
        <div class="admin-warn-bar">
            <b><?php echo $this->t('admin.db_warn_title'); ?></b>
            <ul>
                <li><b><?php echo $this->t('admin.db_warn_irreversible'); ?></b><?php echo $this->t('admin.db_warn_irreversible_val'); ?></li>
                <li><b><?php echo $this->t('admin.db_warn_route'); ?></b><?php echo $this->t('admin.db_warn_route_val'); ?></li>
                <li><b><?php echo $this->t('admin.db_warn_effect'); ?></b><?php echo $this->t('admin.db_warn_effect_val'); ?></li>
                <li><b><?php echo $this->t('admin.db_warn_archive'); ?></b><?php echo $this->t('admin.db_warn_archive_val'); ?></li>
                <li><b><?php echo $this->t('admin.db_warn_config'); ?></b><?php echo $this->t('admin.db_warn_config_val'); ?></li>
                <li><?php echo $this->t('admin.db_warn_audit'); ?></li>
            </ul>
        </div>

        <!-- ① 桶数动态调整（核心操作区） -->
        <div class="admin-section">
            <h3 class="admin-section-title"><?php echo $this->icon('settings', 14); ?> <?php echo $this->t('admin.db_bucket_adjust'); ?></h3>
            <p class="mn-fs-13 c-999"><?php echo $this->t('admin.db_bucket_adjust_hint'); ?></p>
            <p class="mn-fs-12 c-999 mn-mb-10"><?php echo $this->t('admin.db_layman_bucket'); ?></p>
            <div class="admin-warn-bar">
                ⚠️ <b><?php echo $this->t('admin.db_bucket_warn'); ?></b><?php echo $this->t('admin.db_bucket_warn_val'); ?>
            </div>
            <form method="POST" action="<?php echo $this->url('/admin/database/settings'); ?>" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.db_bucket_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="save_buckets">
                <div class="form-group">
                    <label><?php echo $this->t('admin.db_target_buckets'); ?></label>
                    <select name="bucket_size" class="form-input select-narrow">
                        <?php foreach ([32, 64, 128, 256] as $opt): ?>
                            <option value="<?php echo $opt; ?>" <?php echo ($bucketSize === $opt) ? 'selected' : ''; ?>><?php echo $opt; ?> <?php echo $this->t('admin.ov_bucket_unit'); ?><?php echo ($bucketSize === $opt) ? ' ' . $this->t('admin.db_bucket_current') : ''; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!$configWritable): ?>
                    <div class="alert alert-error mn-mb-10"><?php echo $this->t('admin.db_config_not_writable'); ?></div>
                    <button type="submit" class="btn btn-primary" disabled title="config.php 不可写"><?php echo $this->icon('save', 14); ?> <?php echo $this->t('admin.db_update_buckets_unavail'); ?></button>
                <?php else: ?>
                    <button type="submit" class="btn btn-primary"><?php echo $this->icon('save', 14); ?> <?php echo $this->t('admin.db_update_buckets'); ?></button>
                <?php endif; ?>
            </form>
        </div>

        <!-- ② 自动扩容设置（可选配置） -->
        <div class="admin-section">
            <h3 class="admin-section-title"><?php echo $this->icon('sync', 14); ?> <?php echo $this->t('admin.db_autoexpand'); ?></h3>
            <p class="mn-fs-13 c-999"><?php echo $this->t('admin.db_autoexpand_trigger'); ?></p>
            <div class="admin-warn-bar">
                ⚠️ <b><?php echo $this->t('admin.db_autoexpand_warn'); ?></b><?php echo $this->t('admin.db_autoexpand_warn_val'); ?>
            </div>
            <form method="POST" action="<?php echo $this->url('/admin/database/settings'); ?>">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="save_auto_expand">
                <div class="form-group">
                    <label><?php echo $this->t('admin.db_autoexpand_label'); ?></label>
                    <select name="auto_expand_enabled" class="form-input select-narrow">
                        <option value="0" <?php echo ($autoExpand['enabled'] === '0') ? 'selected' : ''; ?>><?php echo $this->t('admin.db_off_recommended'); ?></option>
                        <option value="1" <?php echo ($autoExpand['enabled'] === '1') ? 'selected' : ''; ?>><?php echo $this->t('admin.db_on'); ?></option>
                    </select>
                    <small class="mn-fs-12 c-999"><?php echo $this->t('admin.db_autoexpand_threshold_hint'); ?></small>
                </div>
                <?php if ((int)$autoExpand['lastAt'] > 0): ?>
                <div class="form-group">
                    <small class="mn-fs-12 c-999"><?php echo $this->t('admin.db_last_autoexpand'); ?><?php echo date('Y-m-d H:i:s', (int)$autoExpand['lastAt']); ?></small>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-secondary"><?php echo $this->icon('save', 14); ?> <?php echo $this->t('admin.db_save_settings'); ?></button>
            </form>
        </div>

        <!-- ③ 单桶容量（智能扩容阈值） -->
        <div class="admin-section">
            <h3 class="admin-section-title"><?php echo $this->icon('database', 14); ?> <?php echo $this->t('admin.db_capacity_title'); ?></h3>
            <p class="mn-fs-13 c-999"><?php echo $this->t('admin.db_capacity_hint'); ?></p>
            <div class="admin-warn-bar">
                ⚠️ <b><?php echo $this->t('admin.db_capacity_warn'); ?></b><?php echo $this->t('admin.db_capacity_warn_val'); ?>
            </div>
            <form method="POST" action="<?php echo $this->url('/admin/database/settings'); ?>">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="save_capacity">
                <div class="form-group">
                    <label><?php echo $this->t('admin.db_capacity_label'); ?></label>
                    <select name="bucket_safe_capacity" class="form-input select-narrow">
                        <?php foreach ([30000, 50000, 80000, 100000] as $opt): ?>
                            <option value="<?php echo $opt; ?>" <?php echo ($bucketCapacity === $opt) ? 'selected' : ''; ?>><?php echo number_format($opt); ?> <?php echo $this->t('common.items'); ?><?php echo ($bucketCapacity === $opt) ? ' ' . $this->t('admin.db_bucket_current') : ''; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="mn-fs-12 c-999"><?php echo $this->t('admin.db_capacity_rule'); ?></small>
                </div>
                <button type="submit" class="btn btn-secondary"><?php echo $this->icon('save', 14); ?> <?php echo $this->t('admin.db_save_capacity'); ?></button>
            </form>
        </div>

        <!-- ④ 旧帖归档设置 -->
        <div class="admin-section">
            <h3 class="admin-section-title"><?php echo $this->icon('archive', 14); ?> <?php echo $this->t('admin.db_archive_title'); ?></h3>
            <p class="mn-fs-13 c-999"><?php echo $this->t('admin.db_archive_hint'); ?></p>
            <div class="admin-warn-bar">
                ⚠️ <b><?php echo $this->t('admin.db_archive_warn'); ?></b><?php echo $this->t('admin.db_archive_warn_val'); ?>
            </div>
            <form method="POST" action="<?php echo $this->url('/admin/database/settings'); ?>">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="save_archive">
                <div class="form-group">
                    <label><?php echo $this->t('admin.db_archive_days'); ?></label>
                    <select name="archive_after_days" class="form-input select-narrow">
                        <option value="0" <?php echo ($archiveDays === 0) ? 'selected' : ''; ?>><?php echo $this->t('admin.db_archive_off'); ?></option>
                        <option value="180" <?php echo ($archiveDays === 180) ? 'selected' : ''; ?>><?php echo $this->t('admin.db_archive_days_180'); ?></option>
                        <option value="365" <?php echo ($archiveDays === 365) ? 'selected' : ''; ?>><?php echo $this->t('admin.db_archive_days_365'); ?></option>
                        <option value="custom" <?php echo ($archiveDays !== 0 && $archiveDays !== 180 && $archiveDays !== 365) ? 'selected' : ''; ?>><?php echo $this->t('admin.db_archive_custom'); ?></option>
                    </select>
                    <input type="number" name="archive_after_days_custom" min="1" max="3650" class="form-input" style="max-width:120px;display:<?php echo ($archiveDays !== 0 && $archiveDays !== 180 && $archiveDays !== 365) ? 'inline-block' : 'none'; ?>;margin-left:8px" placeholder="<?php echo $this->t('admin.db_archive_days_placeholder'); ?>" value="<?php echo ($archiveDays !== 0 && $archiveDays !== 180 && $archiveDays !== 365) ? $archiveDays : ''; ?>">
                    <script>
                    (function(){
                        var sel = document.querySelector('select[name="archive_after_days"]');
                        var num = document.querySelector('input[name="archive_after_days_custom"]');
                        if (!sel || !num) return;
                        sel.addEventListener('change', function(){
                            num.style.display = (sel.value === 'custom') ? 'inline-block' : 'none';
                            if (sel.value !== 'custom') num.value = '';
                        });
                    })();
                    </script>
                </div>
                <button type="submit" class="btn btn-secondary"><?php echo $this->icon('save', 14); ?> <?php echo $this->t('admin.db_save_archive'); ?></button>
            </form>
        </div>

        <!-- ⑤ 扩容记录 -->
        <div class="admin-section">
            <h3 class="admin-section-title"><?php echo $this->icon('history', 14); ?> <?php echo $this->t('admin.db_expand_records'); ?></h3>
            <?php if (!empty($expandRecords)): ?>
            <table class="admin-table">
                <thead><tr><th><?php echo $this->t('admin.db_records_time'); ?></th><th><?php echo $this->t('admin.db_records_operator'); ?></th><th><?php echo $this->t('admin.db_records_type'); ?></th><th><?php echo $this->t('admin.db_records_detail'); ?></th><th><?php echo $this->t('admin.db_records_ip'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($expandRecords as $r): ?>
                    <tr>
                        <td class="mn-fs-12 c-999"><?php echo $this->e((string)($r['created_at'] ?? '')); ?></td>
                        <td><?php echo $this->e((string)($r['username'] ?? '')); ?></td>
                        <td><?php echo $this->e($actionLabels[$r['action'] ?? ''] ?? ($r['action'] ?? '')); ?></td>
                        <td><?php echo $this->e((string)($r['detail'] ?? '')); ?></td>
                        <td class="mn-fs-12 c-999"><?php echo $this->e((string)($r['ip'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="mn-fs-12 c-999 mn-mt-10"><?php echo $this->t('admin.db_records_hint'); ?></p>
            <?php else: ?>
            <p class="c-999 mn-fs-13"><?php echo $this->t('admin.db_no_records'); ?></p>
            <?php endif; ?>
        </div>

        <!-- ④ 一致性检查（诊断工具） -->
        <div class="admin-section">
            <h3 class="admin-section-title"><?php echo $this->icon('warning', 14); ?> <?php echo $this->t('admin.db_consistency'); ?></h3>
            <p class="mn-fs-13 c-999"><?php echo $this->t('admin.db_consistency_hint'); ?></p>
            <div class="admin-warn-bar">
                ⚠️ <b><?php echo $this->t('admin.db_consistency_warn'); ?></b><?php echo $this->t('admin.db_consistency_warn_val'); ?>
            </div>
            <form method="POST" action="<?php echo $this->url('/admin/database/settings'); ?>" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.db_consistency_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="consistency">
                <div class="form-group">
                    <label><?php echo $this->t('admin.db_consistency_scope'); ?></label>
                    <select name="scope" class="form-input select-narrow">
                        <option value="current" <?php echo ($consistency['scope'] ?? 'current') === 'current' ? 'selected' : ''; ?>><?php echo $this->t('admin.db_scope_current'); ?></option>
                        <option value="all" <?php echo ($consistency['scope'] ?? '') === 'all' ? 'selected' : ''; ?>><?php echo $this->t('admin.db_scope_all'); ?></option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"><?php echo $this->icon('warning', 14); ?> <?php echo $this->t('admin.db_start_check'); ?></button>
            </form>

            <?php if (is_array($consistency)): ?>
            <?php
                $strict = ($consistency['scope'] ?? 'current') === 'all';
                $topicBad = $strict && !$consistency['topicMatch'];
                $replyBad = $strict && !$consistency['replyMatch'];
            ?>
            <table class="admin-table mn-mt-15">
                <thead><tr><th><?php echo $this->t('admin.db_check_item'); ?></th><th><?php echo $this->t('admin.db_check_bucket_sum'); ?></th><th><?php echo $this->t('admin.db_check_idx_count'); ?></th><th><?php echo $this->t('admin.db_check_result'); ?></th></tr></thead>
                <tbody>
                    <tr>
                        <td class="mn-fw-500"><?php echo $this->t('admin.ov_topic'); ?></td>
                        <td><?php echo number_format($consistency['bucketTopic']); ?></td>
                        <td><?php echo number_format($consistency['idxTopic']); ?></td>
                        <td>
                            <?php if ($strict): ?>
                                <?php if ($topicBad): ?>
                                    <span class="text-error mn-fw-600"><?php echo $this->t('admin.db_check_mismatch'); ?></span>
                                <?php else: ?>
                                    <span class="text-success mn-fw-600"><?php echo $this->t('admin.db_check_match'); ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="mn-fs-12 c-999"><?php echo $this->t('admin.db_check_ref'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="mn-fw-500"><?php echo $this->t('admin.ov_reply'); ?></td>
                        <td><?php echo number_format($consistency['bucketReply']); ?></td>
                        <td><?php echo number_format($consistency['idxReply']); ?></td>
                        <td>
                            <?php if ($strict): ?>
                                <?php if ($replyBad): ?>
                                    <span class="text-error mn-fw-600"><?php echo $this->t('admin.db_check_mismatch'); ?></span>
                                <?php else: ?>
                                    <span class="text-success mn-fw-600"><?php echo $this->t('admin.db_check_match'); ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="mn-fs-12 c-999"><?php echo $this->t('admin.db_check_ref'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="mn-fs-12 c-999 mn-mt-10"><?php echo $this->t('admin.db_check_bucket_files', ['count' => (int)$consistency['bucketFiles']]); ?></p>
            <?php if (!empty($consistency['errors'])): ?>
                <div class="alert alert-error mn-mt-10"><?php echo $this->t('admin.db_check_errors'); ?><?php echo $this->e(implode('、', $consistency['errors'])); ?></div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php $this->endSection(); ?>