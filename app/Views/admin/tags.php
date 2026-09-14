<?php
/**
 * 后台标签管理视图 — 标签列表、编辑、合并/删除
 * @file app/Views/admin/tags.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.tags_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php
$tags = $this->getData('tags', []);
$csrfToken = $this->e($this->getData('csrfToken'));
?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'tags']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('tag', 16); ?> <?php echo $this->t('admin.tags_title'); ?></h1>
        </div>
        <div class="admin-grid-2-1">
            <div class="admin-section">
                <h3 class="admin-section-title"><?php echo $this->t('admin.all_tags'); ?></h3>
                <table class="admin-table">
                    <thead>
                        <tr><th>ID</th><th><?php echo $this->t('admin.name'); ?></th><th><?php echo $this->t('admin.related_threads'); ?></th><th><?php echo $this->t('admin.create_time'); ?></th><th class="admin-w-160"><?php echo $this->t('common.actions'); ?></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tags as $tag): ?>
                        <tr>
                            <td><?php echo (int)$tag['id']; ?></td>
                            <td><span class="blog-category-tag"><?php echo $this->icon('tag', 13); ?> <?php echo $this->e($tag['name']); ?></span></td>
                            <td><?php echo (int)($tag['thread_count'] ?? 0); ?></td>
                            <td class="mn-fs-12 c-999"><?php echo $this->e($tag['created_at'] ?? ''); ?></td>
                            <td>
                                <form method="POST" class="mn-inline-block" id="rf_<?php echo (int)$tag['id']; ?>">
                                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="action" value="rename">
                                    <input type="hidden" name="tag_id" value="<?php echo (int)$tag['id']; ?>">
                                    <input type="hidden" name="new_name" id="rn_<?php echo (int)$tag['id']; ?>" value="">
                                    <button type="button" onclick="var n=prompt(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.new_tag_name')), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($tag['name']), ENT_QUOTES, 'UTF-8'); ?>);if(n&&n.trim()){document.getElementById('rn_<?php echo (int)$tag['id']; ?>').value=n.trim();this.form.submit();}"
                                            class="btn-sm bg-f0 border-ddd"><?php echo $this->t('admin.rename'); ?></button>
                                </form>
                                <form method="POST" class="mn-inline-block" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.delete_tag_confirm', ['name' => $tag['name']])), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int)$tag['id']; ?>">
                                    <button type="submit" class="btn-delete"><?php echo $this->t('common.delete'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="admin-section">
                <h3 class="admin-section-title"><?php echo $this->t('admin.add_tag'); ?></h3>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                    <div class="form-group">
                        <label><?php echo $this->t('admin.tag_name'); ?></label>
                        <input type="text" name="name" required class="form-input">
                    </div>
                    <button type="submit" class="btn btn-primary"><?php echo $this->t('admin.add'); ?></button>
                </form>
            </div>
        </div>
    </main>
</div>
<?php $this->endSection(); ?>