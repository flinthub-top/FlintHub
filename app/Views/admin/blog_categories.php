<?php
/**
 * 后台博客分类视图 — 博客分类增删改、排序
 * @file app/Views/admin/blog_categories.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.blog_categories_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php
$categories = $this->getData('categories', []);
$editCategory = $this->getData('editCategory');
$csrfToken = $this->e($this->getData('csrfToken'));
$blogCounts = $this->getData('blogCounts', []);
?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'blog-categories']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('blog-cat', 16); ?> <?php echo $this->t('admin.blog_categories_title'); ?></h1>
        </div>

        <!-- 添加/编辑表单 -->
        <div class="admin-section">
            <h3 class="admin-section-title"><?php echo $editCategory ? $this->t('admin.edit_category') : $this->t('admin.add_category'); ?></h3>
            <form method="POST" action="<?php echo $this->url('/admin/blog-categories' . ($editCategory ? '?id=' . (int)$editCategory['id'] : '')); ?>">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
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
                        <a href="<?php echo $this->url('/admin/blog-categories'); ?>" class="btn btn-secondary"><?php echo $this->t('admin.cancel_edit'); ?></a>
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
                            <th><?php echo $this->t('admin.name'); ?></th>
                            <th><?php echo $this->t('admin.description'); ?></th>
                            <th><?php echo $this->t('admin.sort'); ?></th>
                            <th><?php echo $this->t('admin.blog_articles'); ?></th>
                            <th><?php echo $this->t('common.actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($categories as $cat): ?>
                        <?php $cid = (int)$cat['id']; ?>
                        <tr>
                            <td><?php echo $cid; ?></td>
                            <td><strong><?php echo $this->e($cat['name']); ?></strong></td>
                            <td class="c-666"><?php echo $this->e($cat['description'] ?? ''); ?></td>
                            <td><?php echo (int)($cat['sort_order'] ?? 0); ?></td>
                            <td><?php echo (int)($blogCounts[$cid] ?? 0); ?></td>
                            <td class="actions">
                                <a href="<?php echo $this->url('/admin/blog-categories?action=edit_form&id=' . $cid); ?>" class="btn-edit"><?php echo $this->t('common.edit'); ?></a>
                                <form method="POST" class="mn-inline-block" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.delete_blogcat_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
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