<?php
/**
 * 勋章管理后台视图 — 勋章 CRUD + 手动颁发/回收
 * @file plugins/medal/views/admin.php
 */
$medals = $this->getData('medals', []);
$countMap = $this->getData('countMap', []);
$error = $this->getData('error', '');
$success = $this->getData('success', '');
$csrf = $this->getData('csrfToken');
?>
<?php $this->section('title'); ?><?php echo \app\Helpers\I18n::get('plugin.medal.title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('css'); ?>
<link rel="stylesheet" href="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/medal/assets/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/style.css') ?: 1; ?>">
<?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar'); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('award', 18); ?> <?php echo \app\Helpers\I18n::get('plugin.medal.title'); ?></h1>
        </div>

        <?php if ($error): ?><div class="alert alert-error"><?php echo $this->e($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo $this->e($success); ?></div><?php endif; ?>

        <div class="admin-section">
            <h2><?php echo \app\Helpers\I18n::get('plugin.medal.add'); ?></h2>
            <form method="post" action="<?php echo $this->url('/admin/medals/add'); ?>">
                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                <div class="medal-form-row">
                    <div class="form-group medal-form-grow">
                        <label><?php echo \app\Helpers\I18n::get('plugin.medal.name'); ?> <span class="c-danger">*</span></label>
                        <input type="text" name="name" class="form-input" required maxlength="50" placeholder="<?php echo \app\Helpers\I18n::get('plugin.medal.name_ph'); ?>">
                    </div>
                    <div class="form-group medal-w-140">
                        <label><?php echo \app\Helpers\I18n::get('plugin.medal.icon'); ?></label>
                        <input type="text" name="icon" class="form-input" value="award" maxlength="50" placeholder="<?php echo \app\Helpers\I18n::get('plugin.medal.icon_ph'); ?>">
                    </div>
                    <div class="form-group medal-w-110">
                        <label><?php echo \app\Helpers\I18n::get('plugin.medal.color'); ?></label>
                        <input type="color" name="color" class="form-input medal-color-input" value="#f59e0b">
                    </div>
                    <div class="form-group medal-w-90">
                        <label><?php echo \app\Helpers\I18n::get('plugin.medal.sort'); ?></label>
                        <input type="number" name="sort" class="form-input" value="0">
                    </div>
                    <div class="form-group medal-pt-26">
                        <label class="checkbox-inline medal-fw-400">
                            <input type="checkbox" name="status" checked> <?php echo \app\Helpers\I18n::get('plugin.medal.enable'); ?>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label><?php echo \app\Helpers\I18n::get('plugin.medal.desc'); ?></label>
                    <input type="text" name="description" class="form-input" maxlength="255" placeholder="<?php echo \app\Helpers\I18n::get('plugin.medal.desc_ph'); ?>">
                </div>
                <div class="medal-form-row">
                    <div class="form-group medal-w-200">
                        <label><?php echo \app\Helpers\I18n::get('plugin.medal.condition'); ?></label>
                        <select name="condition_type" id="condType" class="form-input">
                            <option value="manual"><?php echo \app\Helpers\I18n::get('plugin.medal.cond_manual'); ?></option>
                            <option value="thread_count"><?php echo \app\Helpers\I18n::get('plugin.medal.cond_threads'); ?></option>
                            <option value="post_count"><?php echo \app\Helpers\I18n::get('plugin.medal.cond_posts'); ?></option>
                            <option value="reg_days"><?php echo \app\Helpers\I18n::get('plugin.medal.cond_days'); ?></option>
                            <option value="points"><?php echo \app\Helpers\I18n::get('plugin.medal.cond_points'); ?></option>
                        </select>
                    </div>
                    <div class="form-group medal-w-120">
                        <label><?php echo \app\Helpers\I18n::get('plugin.medal.cond_value'); ?></label>
                        <input type="number" name="condition_value" class="form-input" value="0" min="0" id="condValue">
                    </div>
                    <div class="form-group medal-w-260">
                        <label><?php echo \app\Helpers\I18n::get('plugin.medal.image'); ?></label>
                        <input type="text" name="image" class="form-input" placeholder="<?php echo \app\Helpers\I18n::get('plugin.medal.image_ph'); ?>">
                    </div>
                    <div class="form-group medal-pt-26">
                        <button type="submit" class="btn btn-primary"><?php echo \app\Helpers\I18n::get('plugin.medal.add_btn'); ?></button>
                    </div>
                </div>
            </form>
        </div>

        <div class="admin-section">
            <h2><?php echo \app\Helpers\I18n::get('plugin.medal.existing', ['count' => count($medals)]); ?></h2>
            <?php if (empty($medals)): ?>
                <div class="empty-state"><p><?php echo \app\Helpers\I18n::get('plugin.medal.empty'); ?></p></div>
            <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="medal-w-56"><?php echo \app\Helpers\I18n::get('plugin.medal.th_id'); ?></th>
                        <th class="medal-w-64"><?php echo \app\Helpers\I18n::get('plugin.medal.th_icon'); ?></th>
                        <th><?php echo \app\Helpers\I18n::get('plugin.medal.name'); ?></th>
                        <th><?php echo \app\Helpers\I18n::get('plugin.medal.th_cond'); ?></th>
                        <th class="medal-w-70"><?php echo \app\Helpers\I18n::get('plugin.medal.th_holders'); ?></th>
                        <th class="medal-w-70"><?php echo \app\Helpers\I18n::get('plugin.medal.th_status'); ?></th>
                        <th class="medal-w-170"><?php echo \app\Helpers\I18n::get('plugin.medal.th_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($medals as $m): $mid = (int)$m['id']; ?>
                    <tr>
                        <td><?php echo $mid; ?></td>
                        <td><?php echo \Plugin\Medal\Plugin::renderMedal($m, 26); ?></td>
                        <td>
                            <strong><?php echo $this->e($m['name'] ?? ''); ?></strong>
                            <div class="c-999 mn-fs-12"><?php echo $this->e(mb_substr((string)($m['description'] ?? ''), 0, 40)); ?></div>
                        </td>
                        <td class="mn-fs-13"><?php echo $this->e(\Plugin\Medal\Plugin::conditionLabel($m)); ?></td>
                        <td><?php echo \app\Helpers\I18n::get('plugin.medal.people', ['count' => (int)($countMap[$mid] ?? 0)]); ?></td>
                        <td><?php echo !empty($m['status']) ? '<span class="mn-text-success">' . \app\Helpers\I18n::get('plugin.medal.on') . '</span>' : '<span class="c-999">' . \app\Helpers\I18n::get('plugin.medal.off') . '</span>'; ?></td>
                        <td>
                            <div class="medal-actions">
                                <button type="button" class="btn btn-sm btn-secondary medal-edit-btn" data-medal='<?php echo htmlspecialchars(json_encode($m, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>'><?php echo \app\Helpers\I18n::get('plugin.medal.edit'); ?></button>
                                <form method="post" action="<?php echo $this->url('/admin/medals/delete'); ?>" class="medal-inline" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('plugin.medal.delete_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="id" value="<?php echo $mid; ?>">
                                    <button type="submit" class="btn btn-sm btn-warning"><?php echo \app\Helpers\I18n::get('plugin.medal.delete'); ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="admin-section">
            <h2><?php echo \app\Helpers\I18n::get('plugin.medal.grant_revoke'); ?></h2>
            <p class="c-999 mn-fs-12 medal-mb-10"><?php echo \app\Helpers\I18n::get('plugin.medal.grant_hint'); ?></p>
            <div class="medal-gap-20">
                <form method="post" action="<?php echo $this->url('/admin/medals/grant'); ?>" class="medal-form-row-end">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <div class="form-group medal-m-0">
                        <label><?php echo \app\Helpers\I18n::get('plugin.medal.medal'); ?></label>
                        <select name="medal_id" class="form-input medal-w-150">
                            <?php foreach ($medals as $m): ?>
                            <option value="<?php echo (int)$m['id']; ?>"><?php echo $this->e($m['name'] ?? ''); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group medal-m-0">
                        <label><?php echo \app\Helpers\I18n::get('plugin.medal.username'); ?></label>
                        <input type="text" name="username" class="form-input medal-w-140" required placeholder="<?php echo \app\Helpers\I18n::get('plugin.medal.username_ph'); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary"><?php echo \app\Helpers\I18n::get('plugin.medal.grant_btn'); ?></button>
                </form>
                <form method="post" action="<?php echo $this->url('/admin/medals/revoke'); ?>" class="medal-form-row-end">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <div class="form-group medal-m-0">
                        <label><?php echo \app\Helpers\I18n::get('plugin.medal.medal'); ?></label>
                        <select name="medal_id" class="form-input medal-w-150">
                            <?php foreach ($medals as $m): ?>
                            <option value="<?php echo (int)$m['id']; ?>"><?php echo $this->e($m['name'] ?? ''); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group medal-m-0">
                        <label><?php echo \app\Helpers\I18n::get('plugin.medal.username'); ?></label>
                        <input type="text" name="username" class="form-input medal-w-140" required placeholder="<?php echo \app\Helpers\I18n::get('plugin.medal.username_ph'); ?>">
                    </div>
                    <button type="submit" class="btn btn-warning"><?php echo \app\Helpers\I18n::get('plugin.medal.revoke_btn'); ?></button>
                </form>
            </div>
        </div>
    </main>
</div>

<!-- 编辑弹窗（Alpine.js 控制显隐） -->
<div class="fl-modal-overlay" id="editModal"
     x-data="{ open: false }"
     x-show="open"
     x-on:keydown.escape.window="open = false"
     x-on:click="open = false"
     x-cloak>
    <div class="fl-modal medal-modal-edit" x-on:click.stop>
        <div class="fl-modal-header">
            <h2><?php echo \app\Helpers\I18n::get('plugin.medal.edit_title'); ?></h2>
            <button type="button" class="fl-modal-close" x-on:click="open = false">&times;</button>
        </div>
        <form method="post" action="<?php echo $this->url('/admin/medals/edit'); ?>">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <input type="hidden" name="id" id="edit_id" value="">
            <div class="fl-modal-body">
                <div class="fl-modal-row">
                    <div class="fl-modal-field fl-modal-field-sm">
                        <label><?php echo \app\Helpers\I18n::get('plugin.medal.name'); ?> <span class="c-danger">*</span></label>
                        <input type="text" name="name" id="edit_name" class="form-input" required maxlength="50">
                    </div>
                    <div class="fl-modal-field fl-modal-field-sm">
                        <label><?php echo \app\Helpers\I18n::get('plugin.medal.icon'); ?></label>
                        <input type="text" name="icon" id="edit_icon" class="form-input" maxlength="50">
                    </div>
                </div>
                <div class="fl-modal-row">
                    <div class="fl-modal-field fl-modal-field-sm">
                        <label><?php echo \app\Helpers\I18n::get('plugin.medal.color'); ?></label>
                        <input type="color" name="color" id="edit_color" class="form-input medal-color-input">
                    </div>
                    <div class="fl-modal-field fl-modal-field-sm">
                        <label><?php echo \app\Helpers\I18n::get('plugin.medal.sort'); ?></label>
                        <input type="number" name="sort" id="edit_sort" class="form-input" value="0">
                    </div>
                </div>
                <div class="fl-modal-field">
                    <label><?php echo \app\Helpers\I18n::get('plugin.medal.desc'); ?></label>
                    <input type="text" name="description" id="edit_description" class="form-input" maxlength="255">
                </div>
                <div class="fl-modal-row">
                    <div class="fl-modal-field fl-modal-field-sm">
                        <label><?php echo \app\Helpers\I18n::get('plugin.medal.condition'); ?></label>
                        <select name="condition_type" id="edit_condition_type" class="form-input">
                            <option value="manual"><?php echo \app\Helpers\I18n::get('plugin.medal.cond_manual'); ?></option>
                            <option value="thread_count"><?php echo \app\Helpers\I18n::get('plugin.medal.cond_threads'); ?></option>
                            <option value="post_count"><?php echo \app\Helpers\I18n::get('plugin.medal.cond_posts'); ?></option>
                            <option value="reg_days"><?php echo \app\Helpers\I18n::get('plugin.medal.cond_days'); ?></option>
                            <option value="points"><?php echo \app\Helpers\I18n::get('plugin.medal.cond_points'); ?></option>
                        </select>
                    </div>
                    <div class="fl-modal-field fl-modal-field-sm">
                        <label><?php echo \app\Helpers\I18n::get('plugin.medal.cond_value'); ?></label>
                        <input type="number" name="condition_value" id="edit_condition_value" class="form-input" value="0" min="0">
                    </div>
                </div>
                <div class="fl-modal-field">
                    <label><?php echo \app\Helpers\I18n::get('plugin.medal.image'); ?></label>
                    <input type="text" name="image" id="edit_image" class="form-input" placeholder="<?php echo \app\Helpers\I18n::get('plugin.medal.image_ph'); ?>">
                </div>
                <div class="fl-modal-field">
                    <label class="checkbox-inline medal-fw-400">
                        <input type="checkbox" name="status" id="edit_status" checked> <?php echo \app\Helpers\I18n::get('plugin.medal.enable'); ?>
                    </label>
                </div>
            </div>
            <div class="fl-modal-footer">
                <button type="button" class="btn btn-secondary" x-on:click="open = false"><?php echo \app\Helpers\I18n::get('plugin.medal.cancel'); ?></button>
                <button type="submit" class="btn btn-primary"><?php echo \app\Helpers\I18n::get('plugin.medal.save'); ?></button>
            </div>
        </form>
    </div>
</div>

<script src="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/medal/assets/script.js?v=<?php echo @filemtime(__DIR__ . '/../assets/script.js') ?: 1; ?>"></script>
<?php $this->endSection(); ?>
