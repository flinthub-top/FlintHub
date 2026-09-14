<?php
/**
 * 后台用户组管理视图 — 用户组列表、新增/编辑表单、权限分配
 * @file app/Views/admin/groups.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.groups_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php
$groups = $this->getData('groups', []);
$error = $this->getData('error', '');
$success = $this->getData('success', '');
$csrfToken = $this->e($this->getData('csrfToken'));
?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'groups']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('users', 16); ?> <?php echo $this->t('admin.groups_title'); ?></h1>
        </div>
        <?php if ($error): ?><div class="alert alert-error"><?php echo $this->e($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo $this->e($success); ?></div><?php endif; ?>

        <table class="admin-table">
            <thead>
                <tr>
                    <th class="admin-w-60">ID</th>
                    <th><?php echo $this->t('admin.group_name'); ?></th>
                    <th class="admin-w-80"><?php echo $this->t('admin.color'); ?></th>
                    <th class="admin-w-100"><?php echo $this->t('admin.default_group'); ?></th>
                    <th class="admin-w-130"><?php echo $this->t('common.actions'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($groups as $g): ?>
                <tr>
                    <td><?php echo (int)$g['id']; ?></td>
                    <td><span class="lv-badge badge-pill" style="background:<?php echo $this->e($g['color'] ?? '#666'); ?>;"><?php echo $this->e($g['name']); ?></span></td>
                    <td><code><?php echo $this->e($g['color']); ?></code></td>
                    <td><?php echo !empty($g['is_default']) ? '✅' : ''; ?></td>
                    <td class="actions">
                        <button class="btn-edit" onclick="editGroup(<?php echo (int)$g['id']; ?>, <?php echo htmlspecialchars(json_encode($g['name'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($g['color'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo (int)($g['is_default'] ?? 0); ?>)"><?php echo $this->t('common.edit'); ?></button>
                        <?php if ((int)$g['id'] > 4): ?>
                        <form method="POST" class="mn-inline-block" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.delete_group_confirm', ['name' => $g['name']])), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                            <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int)$g['id']; ?>">
                            <button type="submit" class="btn-delete"><?php echo $this->t('common.delete'); ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <hr class="admin-hr">
        <h3 class="mn-mb-15 mn-fs-16"><?php echo $this->t('admin.add_edit_group'); ?></h3>
        <form method="POST" class="level-form-grid">
            <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="form_id" value="0">
            <div>
                <label class="label-inline"><?php echo $this->t('admin.group_name'); ?></label>
                <input type="text" name="name" id="form_name" required maxlength="50" class="form-input" placeholder="<?php echo $this->t('admin.group_placeholder'); ?>">
            </div>
            <div>
                <label class="label-inline"><?php echo $this->t('admin.color'); ?></label>
                <div class="mn-flex mn-gap-4">
                    <input type="color" id="form_color_picker" value="#666666" class="input-color">
                    <input type="text" name="color" id="form_color" maxlength="20" placeholder="#666" class="form-input min-w-80">
                </div>
            </div>
            <div>
                <label class="label-inline"><?php echo $this->t('admin.default_group'); ?></label>
                <label class="mn-flex mn-items-center mn-gap-4 mn-fs-13 mn-pt-2">
                    <input type="checkbox" name="is_default" value="1" id="form_is_default">
                    <?php echo $this->t('admin.set_default_hint'); ?>
                </label>
            </div>
            <div class="align-self-end"><button type="submit" class="btn-save"><?php echo $this->t('common.save'); ?></button></div>
        </form>
    </main>
</div>
<script>
function editGroup(id, name, color, isDefault) {
    document.getElementById('form_id').value = id;
    document.getElementById('form_name').value = name;
    document.getElementById('form_color').value = color;
    document.getElementById('form_color_picker').value = color;
    document.getElementById('form_is_default').checked = isDefault === 1;
    window.scrollTo({top: document.querySelector('.level-form-grid').offsetTop - 20, behavior: 'smooth'});
}
document.getElementById('form_color_picker').addEventListener('input', function() {
    document.getElementById('form_color').value = this.value;
});
document.getElementById('form_color').addEventListener('input', function() {
    if (/^#[0-9a-f]{6}$/i.test(this.value)) document.getElementById('form_color_picker').value = this.value;
});
</script>
<?php $this->endSection(); ?>