<?php
/**
 * 后台分类管理视图 — 板块分类列表、新增/编辑/排序
 * @file app/Views/admin/categories.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.categories_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php
$categories = $this->getData('categories', []);
$editCategory = $this->getData('editCategory');
$error = $this->getData('error');
$success = $this->getData('success', '');
$csrfToken = $this->e($this->getData('csrfToken'));
$threadCounts = $this->getData('threadCounts', []);
?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'categories']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('categories', 16); ?> <?php echo $this->t('admin.categories_title'); ?></h1>
        </div>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $this->e($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $this->e($error); ?></div>
        <?php endif; ?>

        <!-- 添加/编辑表单 -->
        <div class="admin-section">
            <h3 class="admin-section-title"><?php echo $editCategory ? $this->t('admin.edit_category') : $this->t('admin.add_category'); ?></h3>
            <form method="POST" action="<?php echo $this->url('/admin/categories' . ($editCategory ? '?id=' . (int)$editCategory['id'] : '')); ?>">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <div class="form-group">
                    <label><?php echo $this->t('admin.icon'); ?></label>
                    <div class="mn-flex mn-gap-4 mn-flex-wrap">
                        <?php
                        $presetIcons = ['forum','chat','code','star','heart','image','tags','announcement','gift','search','flag','lock','pinned','blog','trophy'];
                        $currentIcon = $editCategory['icon'] ?? 'forum';
                        foreach ($presetIcons as $iconName):
                            $isActive = $iconName === $currentIcon;
                        ?>
                        <label class="mn-flex mn-items-center mn-gap-2 mn-cursor-pointer mn-fs-13 cat-chip<?php echo $isActive ? ' cat-chip-active' : ''; ?>">
                            <input type="radio" name="icon" value="<?php echo $iconName; ?>" <?php echo $isActive ? 'checked' : ''; ?>>
                            <?php echo $this->icon($iconName, 16); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <label class="mn-fs-12 c-999 mn-flex mn-items-center mn-gap-4">
                        <input type="checkbox" name="show_icon" value="1" <?php echo !isset($editCategory['show_icon']) || $editCategory['show_icon'] ? 'checked' : ''; ?> class="checkbox-inline">
                        <?php echo $this->t('admin.show_icon'); ?>
                    </label>
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('admin.category_name'); ?></label>
                    <input type="text" name="name" value="<?php echo $this->e($editCategory['name'] ?? ''); ?>" required class="form-input">
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('admin.category_description'); ?></label>
                    <textarea name="description" rows="3" class="form-input"><?php echo $this->e($editCategory['description'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('admin.sort_order'); ?></label>
                    <input type="number" name="sort_order" value="<?php echo (int)($editCategory['sort_order'] ?? 0); ?>" class="form-input">
                </div>
                <div class="mn-flex mn-gap-10">
                    <button type="submit" class="btn btn-primary"><?php echo $editCategory ? $this->t('common.save') : $this->t('admin.add_category'); ?></button>
                    <?php if ($editCategory): ?>
                        <a href="<?php echo $this->url('/admin/categories'); ?>" class="btn btn-secondary"><?php echo $this->t('admin.cancel_edit'); ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- 分类列表 -->
        <div class="admin-section">
            <h3 class="admin-section-title"><?php echo $this->t('admin.existing_categories'); ?></h3>
            <?php if (empty($categories)): ?>
                <div class="empty-state"><?php echo $this->t('admin.no_categories'); ?></div>
            <?php else: ?>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th class="mn-text-center w-50"><?php echo $this->t('admin.icon'); ?></th>
                            <th><?php echo $this->t('admin.name'); ?></th>
                            <th><?php echo $this->t('admin.description'); ?></th>
                            <th><?php echo $this->t('admin.sort'); ?></th>
                            <th><?php echo $this->t('admin.thread_count'); ?></th>
                            <th><?php echo $this->t('common.actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($categories as $cat): ?>
                        <?php $cid = (int)$cat['id']; ?>
                        <tr>
                            <td><?php echo $cid; ?></td>
                            <td class="mn-text-center"><?php if (!empty($cat['show_icon'])): ?><?php echo $this->icon($cat['icon'] ?? 'forum', 18); ?><?php endif; ?></td>
                            <td><strong><?php echo $this->e($cat['name']); ?></strong></td>
                            <td class="c-666"><?php echo $this->e($cat['description'] ?? ''); ?></td>
                            <td><?php echo (int)($cat['sort_order'] ?? 0); ?></td>
                            <td><?php echo (int)($threadCounts[$cid] ?? 0); ?></td>
                            <td class="actions">
                                <a href="<?php echo $this->url('/admin/categories?action=edit&id=' . $cid); ?>" class="btn-edit"><?php echo $this->t('common.edit'); ?></a>
                                <form method="POST" class="mn-inline-block" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.delete_category_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $cid; ?>">
                                    <button type="submit" class="btn-delete"><?php echo $this->t('common.delete'); ?></button>
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
<?php $this->endSection(); ?>