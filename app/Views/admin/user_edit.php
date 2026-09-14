<?php
/**
 * 后台编辑用户视图 — 用户资料修改、用户组/状态变更
 * @file app/Views/admin/user_edit.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.edit_user'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php $u = $this->getData('user'); $csrfToken = $this->e($this->getData('csrfToken')); ?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'users']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->t('admin.edit_user'); ?></h1>
        </div>
        <div class="admin-section admin-form-sm">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <div class="form-group">
                    <label><?php echo $this->t('auth.username'); ?></label>
                    <input type="text" value="<?php echo $this->e($u['username']); ?>" disabled class="form-input input-disabled">
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('auth.email'); ?></label>
                    <input type="email" name="email" value="<?php echo $this->e($u['email'] ?? ''); ?>" required class="form-input">
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('admin.email_verify'); ?></label>
                    <?php if ((int)($u['email_verified'] ?? 0) === 1): ?>
                    <span class="badge badge-pin"><?php echo $this->t('admin.email_verified'); ?></span>
                    <?php else: ?>
                    <span class="badge"><?php echo $this->t('admin.email_not_verified'); ?></span>
                    <label style="display:block;font-weight:normal;">
                        <input type="checkbox" name="email_verified_approve" value="1" style="vertical-align:middle;">
                        <span style="vertical-align:middle;"><?php echo $this->t('admin.mark_verified_hint'); ?></span>
                    </label>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('admin.role'); ?></label>
                    <select name="role" class="form-input">
                        <option value="user" <?php echo ($u['role'] ?? '') === 'user' ? 'selected' : ''; ?>><?php echo $this->t('admin.role_user'); ?></option>
                        <option value="admin" <?php echo ($u['role'] ?? '') === 'admin' ? 'selected' : ''; ?>><?php echo $this->t('admin.role_admin'); ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('admin.user_group'); ?></label>
                    <select name="group_id" class="form-input">
                        <?php $groups = \app\Helpers\Permission::getGroups(); foreach ($groups as $g): ?>
                        <option value="<?php echo (int)$g['id']; ?>" <?php echo (int)($u['group_id'] ?? 1) === (int)$g['id'] ? 'selected' : ''; ?>>
                            <?php echo $this->e($g['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('common.status'); ?></label>
                    <select name="status" class="form-input">
                        <option value="active" <?php echo ($u['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>><?php echo $this->t('common.normal'); ?></option>
                        <option value="banned" <?php echo ($u['status'] ?? '') === 'banned' ? 'selected' : ''; ?>><?php echo $this->t('admin.banned'); ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('profile.points'); ?></label>
                    <input type="number" name="points" value="<?php echo (int)($u['points'] ?? 0); ?>" class="form-input">
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('admin.level'); ?> (Lv.)</label>
                    <input type="number" name="level" value="<?php echo (int)($u['level'] ?? 1); ?>" min="1" max="100" class="form-input">
                </div>
                <button type="submit" class="btn btn-primary"><?php echo $this->t('common.save'); ?></button>
                <a href="<?php echo $this->url('/admin/users'); ?>" class="btn btn-secondary"><?php echo $this->t('common.cancel'); ?></a>
            </form>
        </div>
    </main>
</div>
<?php $this->endSection(); ?>
