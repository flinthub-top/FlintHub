<?php
/**
 * 后台版块权限视图 — 用户组/版块访问权限配置
 * @file app/Views/admin/permissions.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.nav_permissions'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php
$groups = $this->getData('groups', []);
$categories = $this->getData('categories', []);
$selectedCategoryId = $this->getData('selectedCategoryId', 0);
$currentPerms = $this->getData('currentPerms', []);
$msg = $this->getData('msg', '');
$csrfToken = $this->e($this->getData('csrfToken'));
?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'permissions']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('lock', 16); ?> <?php echo $this->t('admin.permissions_title2'); ?></h1>
        </div>
        <?php if ($msg === 'saved'): ?><div class="alert alert-success"><?php echo $this->t('admin.permissions_saved'); ?></div><?php endif; ?>
        <?php $error = $this->getData('error', ''); if ($error): ?><div class="alert alert-error"><?php echo $this->e($error); ?></div><?php endif; ?>

        <div class="admin-section">
            <form method="GET" action="<?php echo $this->url('/admin/permissions'); ?>" class="mn-flex mn-gap-10 mn-items-center mn-mb-20">
                <label class="mn-fs-13 whitespace-nowrap"><?php echo $this->t('admin.select_board'); ?></label>
                <select name="category_id" class="form-input w-180" onchange="this.form.submit()">
                    <option value=""><?php echo $this->t('admin.please_select_board'); ?></option>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?php echo (int)$c['id']; ?>" <?php echo (int)$c['id'] === $selectedCategoryId ? 'selected' : ''; ?>>
                        <?php echo $this->e($c['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <?php if ($selectedCategoryId > 0 && !empty($groups)): ?>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="category_id" value="<?php echo $selectedCategoryId; ?>">
                <table class="admin-table admin-table-perms">
                    <thead>
                        <tr>
                            <th><?php echo $this->t('admin.group_name'); ?></th>
                            <th class="mn-text-center"><?php echo $this->t('admin.perm_view'); ?></th>
                            <th class="mn-text-center"><?php echo $this->t('admin.perm_post'); ?></th>
                            <th class="mn-text-center"><?php echo $this->t('admin.perm_reply'); ?></th>
                            <th class="mn-text-center"><?php echo $this->t('admin.perm_attach'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groups as $g): ?>
                        <?php $perm = $currentPerms[$g['id']] ?? []; ?>
                        <tr>
                            <td><span class="lv-badge badge-pill" style="background:<?php echo $this->e($g['color'] ?? '#666'); ?>;"><?php echo $this->e($g['name']); ?></span></td>
                            <td class="mn-text-center"><input type="checkbox" name="perms[<?php echo (int)$g['id']; ?>][can_view]" value="1" <?php echo ((int)($perm['can_view'] ?? 1) === 1) ? 'checked' : ''; ?>></td>
                            <td class="mn-text-center"><input type="checkbox" name="perms[<?php echo (int)$g['id']; ?>][can_post]" value="1" <?php echo ((int)($perm['can_post'] ?? 1) === 1) ? 'checked' : ''; ?>></td>
                            <td class="mn-text-center"><input type="checkbox" name="perms[<?php echo (int)$g['id']; ?>][can_reply]" value="1" <?php echo ((int)($perm['can_reply'] ?? 1) === 1) ? 'checked' : ''; ?>></td>
                            <td class="mn-text-center"><input type="checkbox" name="perms[<?php echo (int)$g['id']; ?>][can_attach]" value="1" <?php echo ((int)($perm['can_attach'] ?? 1) === 1) ? 'checked' : ''; ?>></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="mn-mt-10"><button type="submit" class="btn-save"><?php echo $this->t('admin.save_permissions'); ?></button></div>
            </form>

            <div class="mn-mt-20 mn-p-15 bg-light mn-rounded-8 mn-fs-13 c-666">
                <strong><?php echo $this->t('admin.perm_note_title'); ?></strong><br>
                • <?php echo $this->t('admin.perm_note_admin'); ?><br>
                • <?php echo $this->t('admin.perm_note_unchecked'); ?><br>
                • <?php echo $this->t('admin.perm_note_guest'); ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php $this->endSection(); ?>