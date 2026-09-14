<?php
/**
 * 任务中心插件 后台管理视图
 * @file plugins/task_center/views/admin.php
 * @package Plugin\TaskCenter
 */
$I = function (string $k, array $p = []) { return \app\Helpers\I18n::get($k, $p); };
$types = $this->getData('types', []);
$typeLabels = [];
foreach ($types as $t) $typeLabels[$t] = $I('plugin.task_center.type_' . $t);
$signInModes = $this->getData('signInModes', []);
$profileModes = $this->getData('profileModes', []);
$icons = $this->getData('icons', []);
?>
<?php $this->section('title'); ?><?php echo $I('plugin.task_center.admin_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('css'); ?>
<link rel="stylesheet" href="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/task_center/assets/style.css">
<?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar'); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('clipboard-list', 16); ?> <?php echo $I('plugin.task_center.admin_title'); ?></h1>
        </div>

        <?php $error = $this->getData('error', ''); $success = $this->getData('success', ''); ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $this->e($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $this->e($success); ?></div>
        <?php endif; ?>

        <?php $editing = $this->getData('editing'); $isEdit = !empty($editing); ?>
        <?php $depStatus = $this->getData('depStatus', []); ?>

        <!-- 添加 / 编辑任务 -->
        <div class="admin-section tc-form-card">
            <h2><?php echo $isEdit ? $I('plugin.task_center.edit_task') : $I('plugin.task_center.add_task'); ?></h2>
            <form method="post" action="<?php echo $this->url($isEdit ? '/admin/task-center/edit' : '/admin/task-center/add'); ?>" class="tc-form">
                <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                <?php if ($isEdit): ?>
                <input type="hidden" name="id" value="<?php echo (int)$editing['id']; ?>">
                <?php endif; ?>

                <div class="tc-form-row">
                    <label class="tc-label"><?php echo $I('plugin.task_center.field_type'); ?></label>
                    <select name="type" id="tc-type" class="form-input tc-input">
                        <?php foreach ($typeLabels as $tv => $label): ?>
                        <option value="<?php echo $this->e($tv); ?>" <?php echo (!$isEdit && $tv === 'register') || ($isEdit && $editing['type'] === $tv) ? 'selected' : ''; ?>><?php echo $this->e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="tc-form-row">
                    <label class="tc-label"><?php echo $I('plugin.task_center.field_title'); ?></label>
                    <input type="text" name="title" class="form-input tc-input" value="<?php echo $this->e($editing['title'] ?? ''); ?>" maxlength="120" placeholder="<?php echo $I('plugin.task_center.title_hint'); ?>">
                </div>

                <div class="tc-form-row">
                    <label class="tc-label"><?php echo $I('plugin.task_center.field_desc'); ?></label>
                    <textarea name="description" rows="2" class="form-input tc-input"><?php echo $this->e($editing['description'] ?? ''); ?></textarea>
                </div>

                <div class="tc-form-row tc-form-inline">
                    <div class="tc-form-col">
                        <label class="tc-label"><?php echo $I('plugin.task_center.field_target'); ?></label>
                        <input type="number" name="target" class="form-input tc-input tc-input-sm" value="<?php echo (int)($editing['target'] ?? 1); ?>" min="1" required>
                        <p class="tc-help"><?php echo $I('plugin.task_center.target_hint'); ?></p>
                    </div>
                    <div class="tc-form-col">
                        <label class="tc-label"><?php echo $I('plugin.task_center.field_reward'); ?></label>
                        <input type="number" name="reward_points" class="form-input tc-input tc-input-sm" value="<?php echo (int)($editing['reward_points'] ?? 0); ?>" min="0" required>
                    </div>
                    <div class="tc-form-col">
                        <label class="tc-label"><?php echo $I('plugin.task_center.field_sort'); ?></label>
                        <input type="number" name="sort_order" class="form-input tc-input tc-input-sm" value="<?php echo (int)($editing['sort_order'] ?? 0); ?>" min="0">
                    </div>
                </div>

                <!-- 签到统计口径（type=sign_in 时显示） -->
                <div class="tc-form-row tc-field" data-tc-field="sign_in_mode">
                    <label class="tc-label"><?php echo $I('plugin.task_center.field_sign_in_mode'); ?></label>
                    <select name="sign_in_mode" class="form-input tc-input">
                        <?php foreach ($signInModes as $m): ?>
                        <option value="<?php echo $this->e($m); ?>" <?php echo ($isEdit && $editing['type'] === 'sign_in' && $editing['sign_in_mode'] === $m) || (!$isEdit && $m === 'total') ? 'selected' : ''; ?>><?php echo $I('plugin.task_center.sign_in_mode_' . $m); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 资料完善口径（type=profile 时显示） -->
                <div class="tc-form-row tc-field" data-tc-field="profile_mode">
                    <label class="tc-label"><?php echo $I('plugin.task_center.field_profile_mode'); ?></label>
                    <select name="profile_mode" class="form-input tc-input">
                        <?php foreach ($profileModes as $m): ?>
                        <option value="<?php echo $this->e($m); ?>" <?php echo ($isEdit && $editing['type'] === 'profile' && $editing['profile_mode'] === $m) || (!$isEdit && $m === 'all') ? 'selected' : ''; ?>><?php echo $I('plugin.task_center.profile_mode_' . $m); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="tc-form-row">
                    <label class="tc-label"><?php echo $I('plugin.task_center.field_icon'); ?></label>
                    <select name="icon" class="form-input tc-input">
                        <?php foreach ($icons as $ic): ?>
                        <option value="<?php echo $this->e($ic); ?>" <?php echo ($isEdit && $editing['icon'] === $ic) || (!$isEdit && $ic === 'clipboard-list') ? 'selected' : ''; ?>><?php echo $this->e($ic); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="tc-form-row tc-form-inline">
                    <label class="tc-check">
                        <input type="checkbox" name="daily_reset" value="1" <?php echo ($isEdit && (int)$editing['daily_reset'] === 1) ? 'checked' : ''; ?> class="checkbox-inline">
                        <?php echo $I('plugin.task_center.field_daily'); ?>
                    </label>
                    <label class="tc-check">
                        <input type="checkbox" name="enabled" value="1" <?php echo (!isset($editing['enabled']) || (int)$editing['enabled'] === 1) ? 'checked' : ''; ?> class="checkbox-inline">
                        <?php echo $I('plugin.task_center.field_enabled'); ?>
                    </label>
                </div>

                <div class="tc-form-row tc-form-actions">
                    <button type="submit" class="btn btn-primary"><?php echo $isEdit ? $I('plugin.task_center.save') : $I('plugin.task_center.add_btn'); ?></button>
                    <?php if ($isEdit): ?>
                    <a href="<?php echo $this->url('/admin/task-center'); ?>" class="btn"><?php echo $I('plugin.task_center.cancel'); ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- 任务列表 -->
        <div class="admin-section">
            <h2><?php echo $I('plugin.task_center.task_list', ['count' => count($this->getData('tasks', []))]); ?></h2>
            <?php $tasks = $this->getData('tasks', []); $claimCounts = $this->getData('claimCounts', []); ?>
            <?php if (empty($tasks)): ?>
                <p class="tc-empty"><?php echo $I('plugin.task_center.empty_tasks'); ?></p>
            <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="tc-th-id">ID</th>
                        <th><?php echo $I('plugin.task_center.th_type'); ?></th>
                        <th><?php echo $I('plugin.task_center.th_title'); ?></th>
                        <th><?php echo $I('plugin.task_center.th_target'); ?></th>
                        <th><?php echo $I('plugin.task_center.th_reward'); ?></th>
                        <th><?php echo $I('plugin.task_center.th_daily'); ?></th>
                        <th><?php echo $I('plugin.task_center.th_claimed'); ?></th>
                        <th><?php echo $I('plugin.task_center.th_status'); ?></th>
                        <th class="tc-th-actions"><?php echo $I('plugin.task_center.th_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tasks as $t): ?>
                    <?php
                    $tTitle = (string)$t['title'] !== '' ? $I((string)$t['title']) : $I('plugin.task_center.type_' . (string)$t['type']);
                    $depOff = isset($depStatus[$t['type']]) && !$depStatus[$t['type']];
                    ?>
                    <tr>
                        <td><?php echo (int)$t['id']; ?></td>
                        <td><span class="tc-badge tc-badge-<?php echo $this->e($t['type']); ?>"><?php echo $this->e($typeLabels[$t['type']] ?? $t['type']); ?></span></td>
                        <td>
                            <strong><?php echo $this->e($tTitle); ?></strong>
                            <?php if ($depOff): ?>
                            <span class="tc-dep-warn" title="<?php echo $I('plugin.task_center.dep_unavailable'); ?>">⚠ <?php echo $this->e($t['type'] === 'sign_in' ? 'daily_checkin' : 'invite'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (int)$t['target']; ?></td>
                        <td><?php echo (int)$t['reward_points']; ?></td>
                        <td><?php echo (int)$t['daily_reset'] === 1 ? $I('plugin.task_center.yes') : $I('plugin.task_center.no'); ?></td>
                        <td><?php echo (int)($claimCounts[(int)$t['id']] ?? 0); ?></td>
                        <td>
                            <?php if ((int)$t['enabled'] === 1): ?>
                            <span class="tc-ok"><?php echo $I('plugin.task_center.on'); ?></span>
                            <?php else: ?>
                            <span class="tc-off"><?php echo $I('plugin.task_center.off'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="tc-actions">
                            <a href="<?php echo $this->url('/admin/task-center?edit=' . (int)$t['id']); ?>" class="btn btn-sm"><?php echo $I('plugin.task_center.edit'); ?></a>
                            <form method="post" action="<?php echo $this->url('/admin/task-center/delete'); ?>" class="tc-inline tc-delete-form" data-confirm="<?php echo $this->e($I('plugin.task_center.delete_confirm')); ?>">
                                <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                                <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger"><?php echo $I('plugin.task_center.delete'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </main>
</div>
<script src="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/task_center/assets/script.js"></script>
<?php $this->endSection(); ?>
