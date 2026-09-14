<?php
/**
 * 后台用户管理视图 — 用户列表、搜索、批量操作
 * @file app/Views/admin/users.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.users_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php
$users = $this->getData('users', []);
$editUser = $this->getData('editUser');
$error = $this->getData('error');
$success = $this->getData('success');
$csrfToken = $this->e($this->getData('csrfToken'));
// 后台用户查询：筛选条件（回显用）
$filters = $this->getData('filters', []);
$fQ = (string)($filters['q'] ?? '');
$fField = (string)($filters['field'] ?? 'all');
$fRole = (string)($filters['role'] ?? '');
$fStatus = (string)($filters['status'] ?? '');
?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'users']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('user', 16); ?> <?php echo $this->t('admin.users_title'); ?></h1>
        </div>
        <?php if ($success): ?><div class="alert alert-success"><?php echo $this->e($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo $this->e($error); ?></div><?php endif; ?>
        <?php if ($editUser): ?>
        <div class="admin-section admin-form-sm">
            <h3 class="admin-section-title"><?php echo $this->t('admin.edit_user'); ?></h3>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <div class="form-group">
                    <label><?php echo $this->t('auth.username'); ?></label>
                    <input type="text" value="<?php echo $this->e($editUser['username']); ?>" disabled class="form-input input-disabled">
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('auth.email'); ?></label>
                    <input type="email" name="email" value="<?php echo $this->e($editUser['email'] ?? ''); ?>" required class="form-input">
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('admin.role'); ?></label>
                    <select name="role" class="form-input">
                        <option value="user" <?php echo ($editUser['role'] ?? '') === 'user' ? 'selected' : ''; ?>><?php echo $this->t('admin.role_user'); ?></option>
                        <option value="admin" <?php echo ($editUser['role'] ?? '') === 'admin' ? 'selected' : ''; ?>><?php echo $this->t('admin.role_admin'); ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('profile.points'); ?></label>
                    <input type="number" name="points" value="<?php echo (int)($editUser['points'] ?? 0); ?>" class="form-input">
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('admin.level'); ?></label>
                    <input type="number" name="level" value="<?php echo (int)($editUser['level'] ?? 1); ?>" min="1" class="form-input">
                </div>
                <div class="mn-mt-15"><button type="submit" class="btn btn-primary"><?php echo $this->t('common.save'); ?></button> <a href="<?php echo $this->url('/admin/users'); ?>" class="btn btn-secondary"><?php echo $this->t('common.cancel'); ?></a></div>
            </form>
        </div>
        <?php else: ?>
        <form method="GET" action="<?php echo $this->url('/admin/users'); ?>" class="admin-filter-form" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:16px;">
            <input type="text" name="q" value="<?php echo $this->e($fQ); ?>" placeholder="<?php echo $this->t('admin.users_search_placeholder'); ?>" class="form-input" style="width:220px;flex:0 0 auto;">
            <select name="field" class="form-input" style="width:auto;">
                <option value="all" <?php echo $fField === 'all' ? 'selected' : ''; ?>><?php echo $this->t('common.all'); ?></option>
                <option value="username" <?php echo $fField === 'username' ? 'selected' : ''; ?>><?php echo $this->t('auth.username'); ?></option>
                <option value="email" <?php echo $fField === 'email' ? 'selected' : ''; ?>><?php echo $this->t('auth.email'); ?></option>
            </select>
            <select name="role" class="form-input" style="width:auto;">
                <option value="" <?php echo $fRole === '' ? 'selected' : ''; ?>><?php echo $this->t('admin.all_roles'); ?></option>
                <option value="user" <?php echo $fRole === 'user' ? 'selected' : ''; ?>><?php echo $this->t('admin.role_user'); ?></option>
                <option value="admin" <?php echo $fRole === 'admin' ? 'selected' : ''; ?>><?php echo $this->t('admin.role_admin'); ?></option>
            </select>
            <select name="status" class="form-input" style="width:auto;">
                <option value="" <?php echo $fStatus === '' ? 'selected' : ''; ?>><?php echo $this->t('admin.all_status'); ?></option>
                <option value="active" <?php echo $fStatus === 'active' ? 'selected' : ''; ?>><?php echo $this->t('common.normal'); ?></option>
                <option value="banned" <?php echo $fStatus === 'banned' ? 'selected' : ''; ?>><?php echo $this->t('admin.banned'); ?></option>
            </select>
            <button type="submit" class="btn btn-primary"><?php echo $this->t('admin.search_btn'); ?></button>
            <a href="<?php echo $this->url('/admin/users'); ?>" class="btn btn-secondary"><?php echo $this->t('admin.reset_btn'); ?></a>
        </form>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th><?php echo $this->t('auth.username'); ?></th>
                    <th><?php echo $this->t('auth.email'); ?></th>
                    <th><?php echo $this->t('admin.role'); ?></th>
                    <th><?php echo $this->t('admin.post_count'); ?></th>
                    <th><?php echo $this->t('profile.points'); ?></th>
                    <th><?php echo $this->t('admin.level'); ?></th>
                    <th><?php echo $this->t('admin.reg_time'); ?></th>
                    <th><?php echo $this->t('common.actions'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?php echo (int)$u['id']; ?></td>
                    <td><strong><?php echo $this->e($u['username']); ?></strong></td>
                    <td><?php echo $this->e($u['email']); ?></td>
                    <td><?php if (($u['role'] ?? '') === 'admin'): ?><span class="badge badge-pin"><?php echo $this->t('admin.role_admin'); ?></span><?php else: ?><?php echo $this->t('admin.role_user'); ?><?php endif; ?></td>
                    <td><?php echo (int)($u['post_count'] ?? 0); ?></td>
                    <td><?php echo (int)($u['points'] ?? 0); ?></td>
                    <td><?php echo (int)($u['level'] ?? 1); ?></td>
                    <td class="mn-fs-12 c-999"><?php echo !empty($u['created_at']) ? date('Y-m-d H:i', strtotime($u['created_at'])) : ''; ?></td>
                    <td class="actions">
                        <a href="<?php echo $this->url('/admin/users?action=edit&id=' . (int)$u['id']); ?>" class="btn-edit"><?php echo $this->t('common.edit'); ?></a>
                        <?php if ((int)$u['id'] !== (int)($this->getData('currentUser')['id'] ?? 0)): ?>
                        <form method="POST" class="mn-inline-block" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.delete_user_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                            <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                            <button type="submit" class="btn-delete"><?php echo $this->t('common.delete'); ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php $page = (int)$this->getData('page', 1); $totalPages = (int)$this->getData('totalPages', 1); ?>
        <?php $pageUrl = '/admin/users?q=' . rawurlencode($fQ) . '&amp;field=' . rawurlencode($fField) . '&amp;role=' . rawurlencode($fRole) . '&amp;status=' . rawurlencode($fStatus) . '&amp;page={page}'; ?>
        <?php echo $this->pagination($page, $totalPages, $pageUrl, ['style' => 'admin', 'class' => 'mt-15']); ?>
        <?php endif; ?>
    </main>
</div>
<?php $this->endSection(); ?>