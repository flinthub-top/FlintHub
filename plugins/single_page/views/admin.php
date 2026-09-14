<?php
/**
 * 单页/链接管理插件 后台管理视图
 * @file plugins/single_page/views/admin.php
 * @package Plugin\SinglePage
 */
$I = function (string $k, array $p = []) { return \app\Helpers\I18n::get($k, $p); };
?>
<?php $this->section('title'); ?><?php echo $I('plugin.single_page.title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('css'); ?>
<link rel="stylesheet" href="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/single_page/assets/style.css">
<?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar'); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('file', 16); ?> <?php echo $I('plugin.single_page.title'); ?></h1>
        </div>

        <?php $error = $this->getData('error', ''); $success = $this->getData('success', ''); ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $this->e($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $this->e($success); ?></div>
        <?php endif; ?>

        <?php $editing = $this->getData('editing'); $isEdit = !empty($editing); ?>

        <!-- 添加 / 编辑 -->
        <div class="admin-section sp-form-card">
            <h2><?php echo $isEdit ? $I('plugin.single_page.edit') : $I('plugin.single_page.add'); ?></h2>
            <form method="post" action="<?php echo $this->url($isEdit ? '/admin/single-page/edit' : '/admin/single-page/add'); ?>" class="sp-form">
                <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                <?php if ($isEdit): ?>
                <input type="hidden" name="id" value="<?php echo (int)$editing['id']; ?>">
                <?php endif; ?>

                <div class="sp-form-row">
                    <label class="sp-label"><?php echo $I('plugin.single_page.field_type'); ?></label>
                    <select name="type" id="sp-type" class="form-input sp-input">
                        <option value="page" <?php echo (!$isEdit || $editing['type'] === 'page') ? 'selected' : ''; ?>><?php echo $I('plugin.single_page.type_page'); ?></option>
                        <option value="link" <?php echo ($isEdit && $editing['type'] === 'link') ? 'selected' : ''; ?>><?php echo $I('plugin.single_page.type_link'); ?></option>
                    </select>
                </div>

                <div class="sp-form-row">
                    <label class="sp-label"><?php echo $I('plugin.single_page.field_title'); ?></label>
                    <input type="text" name="title" class="form-input sp-input" value="<?php echo $this->e($editing['title'] ?? ''); ?>" maxlength="120" required>
                </div>

                <div class="sp-form-row sp-field-page" data-sp-field="page">
                    <label class="sp-label"><?php echo $I('plugin.single_page.field_slug'); ?></label>
                    <input type="text" name="slug" class="form-input sp-input" value="<?php echo $this->e($editing['slug'] ?? ''); ?>" maxlength="64" placeholder="about">
                    <p class="sp-help"><?php echo $I('plugin.single_page.slug_hint'); ?></p>
                </div>

                <div class="sp-form-row sp-field-page" data-sp-field="page">
                    <label class="sp-label"><?php echo $I('plugin.single_page.field_content'); ?></label>
                    <textarea name="content" id="content" data-editor="content" rows="12" class="form-input sp-input sp-textarea"><?php echo $this->e($this->decodeContent($editing['content'] ?? '')); ?></textarea>
                    <p class="sp-help"><?php echo $I('plugin.single_page.content_hint'); ?></p>
                </div>

                <div class="sp-form-row sp-field-link" data-sp-field="link">
                    <label class="sp-label"><?php echo $I('plugin.single_page.field_url'); ?></label>
                    <input type="text" name="url" class="form-input sp-input" value="<?php echo $this->e($editing['url'] ?? ''); ?>" maxlength="500" placeholder="https://example.com">
                    <p class="sp-help"><?php echo $I('plugin.single_page.url_hint'); ?></p>
                </div>

                <div class="sp-form-row">
                    <label class="sp-label"><?php echo $I('plugin.single_page.field_sort'); ?></label>
                    <input type="number" name="sort_order" class="form-input sp-input sp-input-sort" value="<?php echo (int)($editing['sort_order'] ?? 0); ?>" min="0">
                </div>

                <div class="sp-form-row sp-form-inline">
                    <label class="sp-check">
                        <input type="checkbox" name="is_public" value="1" <?php echo (!isset($editing['is_public']) || (int)$editing['is_public'] === 1) ? 'checked' : ''; ?> class="checkbox-inline">
                        <?php echo $I('plugin.single_page.field_public'); ?>
                    </label>
                </div>

                <div class="sp-form-row sp-form-actions">
                    <button type="submit" class="btn btn-primary"><?php echo $isEdit ? $I('plugin.single_page.save') : $I('plugin.single_page.add_btn'); ?></button>
                    <?php if ($isEdit): ?>
                    <a href="<?php echo $this->url('/admin/single-page'); ?>" class="btn"><?php echo $I('plugin.single_page.cancel'); ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- 列表 -->
        <div class="admin-section">
            <h2><?php echo $I('plugin.single_page.list', ['count' => count($this->getData('items', []))]); ?></h2>
            <?php $items = $this->getData('items', []); ?>
            <?php if (empty($items)): ?>
                <p class="sp-empty"><?php echo $I('plugin.single_page.empty'); ?></p>
            <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="sp-th-sort"><?php echo $I('plugin.single_page.th_sort'); ?></th>
                        <th><?php echo $I('plugin.single_page.th_type'); ?></th>
                        <th><?php echo $I('plugin.single_page.th_title'); ?></th>
                        <th><?php echo $I('plugin.single_page.th_target'); ?></th>
                        <th><?php echo $I('plugin.single_page.th_public'); ?></th>
                        <th class="sp-th-actions"><?php echo $I('plugin.single_page.th_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?php echo (int)$item['sort_order']; ?></td>
                        <td>
                            <?php if ($item['type'] === 'link'): ?>
                            <span class="sp-badge sp-badge-link"><?php echo $I('plugin.single_page.type_link'); ?></span>
                            <?php else: ?>
                            <span class="sp-badge sp-badge-page"><?php echo $I('plugin.single_page.type_page'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><strong><?php echo $this->e($item['title']); ?></strong></td>
                        <td class="sp-target">
                            <?php if ($item['type'] === 'link'): ?>
                                <a href="<?php echo $this->e($item['url']); ?>" target="_blank" rel="noopener"><?php echo $this->e(mb_substr($item['url'], 0, 50)); ?></a>
                            <?php else: ?>
                                <code>/page/<?php echo $this->e($item['slug']); ?></code>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$item['is_public'] === 1): ?>
                            <span class="sp-ok"><?php echo $I('plugin.single_page.public_yes'); ?></span>
                            <?php else: ?>
                            <span class="sp-off"><?php echo $I('plugin.single_page.public_no'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="sp-actions">
                            <a href="<?php echo $this->url('/admin/single-page?edit=' . (int)$item['id']); ?>" class="btn btn-sm"><?php echo $I('plugin.single_page.edit'); ?></a>
                            <form method="post" action="<?php echo $this->url('/admin/single-page/delete'); ?>" class="sp-inline sp-delete-form" data-confirm="<?php echo $this->e($I('plugin.single_page.delete_confirm')); ?>">
                                <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                                <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger"><?php echo $I('plugin.single_page.delete'); ?></button>
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
<script src="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/single_page/assets/script.js"></script>
<?php $this->endSection(); ?>
