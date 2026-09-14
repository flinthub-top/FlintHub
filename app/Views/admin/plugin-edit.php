<?php
/**
 * 后台插件编辑视图 — 插件配置文件在线编辑
 * @file app/Views/admin/plugin-edit.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.plugin_edit_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar'); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><a href="<?php echo $this->url('/admin/plugins'); ?>" class="back-link"><?php echo $this->icon('back', 14); ?> <?php echo $this->t('common.back'); ?></a> <?php echo $this->t('admin.plugin_edit_title'); ?></h1>
        </div>

        <?php $plugin = $this->getData('plugin', []); $pluginName = $this->getData('pluginName', ''); ?>
        <?php $error = $this->getData('error', ''); $success = $this->getData('success', ''); ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $this->e($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $this->e($success); ?></div>
        <?php endif; ?>

        <!-- 基本信息 -->
        <div class="plugin-edit-card">
            <h3 class="admin-section-title"><?php echo $this->t('admin.plugin_basic_info'); ?></h3>
            <form method="post" action="<?php echo $this->url('/admin/plugins/edit/' . $this->e($pluginName)); ?>">
                <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">

                <div class="form-group">
                    <label><?php echo $this->t('admin.plugin_dir_name'); ?></label>
                    <input type="text" value="<?php echo $this->e($pluginName); ?>" disabled class="form-control input-disabled">
                    <p class="form-help"><?php echo $this->t('admin.plugin_dir_fixed'); ?></p>
                </div>

                <div class="form-group">
                    <label for="name"><?php echo $this->t('admin.plugin_name'); ?></label>
                    <input type="text" name="name" id="name" value="<?php echo $this->e($plugin['name'] ?? ''); ?>" class="form-control" required>
                </div>

                <div class="form-group">
                    <label><?php echo $this->t('admin.plugin_version'); ?></label>
                    <input type="text" value="v<?php echo $this->e($plugin['version'] ?? '--'); ?>" disabled class="form-control input-disabled">
                    <p class="form-help"><?php echo $this->t('admin.plugin_version_fixed'); ?></p>
                </div>

                <div class="form-group">
                    <label for="author"><?php echo $this->t('admin.plugin_author'); ?></label>
                    <input type="text" name="author" id="author" value="<?php echo $this->e($plugin['author'] ?? ''); ?>" class="form-control">
                </div>

                <div class="form-group">
                    <label for="description"><?php echo $this->t('admin.plugin_desc'); ?></label>
                    <textarea name="description" id="description" class="form-control" rows="3"><?php echo $this->e($plugin['description'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="category"><?php echo $this->t('admin.plugin_category'); ?></label>
                    <?php $curCat = $plugin['category'] ?? 'default'; ?>
                    <select name="category" id="category" class="form-control">
                        <?php foreach (['system', 'community', 'content', 'entertainment', 'default'] as $c): ?>
                        <option value="<?php echo $this->e($c); ?>"<?php echo $c === $curCat ? ' selected' : ''; ?>><?php echo $this->e($this->t('admin.plugin_cat_' . $c)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-help"><?php echo $this->t('admin.plugin_category_hint'); ?></p>
                </div>

                <div class="form-group">
                    <label><?php echo $this->t('admin.plugin_status'); ?></label>
                    <p class="p-6-0">
                        <?php if (!empty($plugin['activated'])): ?>
                            <span class="badge badge-success badge-md"><?php echo $this->t('admin.plugin_enabled'); ?></span>
                        <?php else: ?>
                            <span class="badge badge-default badge-md"><?php echo $this->t('admin.plugin_disabled'); ?></span>
                        <?php endif; ?>
                    </p>
                </div>

                <div class="form-group mn-mt-20">
                    <button type="submit" class="btn btn-primary"><?php echo $this->t('admin.plugin_save_config'); ?></button>
                </div>
            </form>
        </div>

        <!-- 已注册的钩子（仅显示路径，不泄露源码） -->
        <div class="plugin-edit-card mn-mt-16">
            <h3 class="admin-section-title"><?php echo $this->t('admin.plugin_registered_hooks', ['count' => count($this->getData('hookFiles', []))]); ?></h3>
            <?php $hookFiles = $this->getData('hookFiles', []); ?>
            <?php if (empty($hookFiles)): ?>
                <p class="mn-text-muted p-12-0"><?php echo $this->t('admin.plugin_no_hooks'); ?></p>
            <?php else: ?>
                <?php foreach ($hookFiles as $hookName => $hookInfo): ?>
                <div class="plugin-hook-item">
                    <div class="plugin-hook-header">
                        <span class="plugin-hook-name"><?php echo $this->e($hookName); ?></span>
                        <span class="plugin-hook-path"><?php echo $this->e($hookInfo['path']); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Plugin.php 主类（仅显示存在状态，不泄露源码） -->
        <?php if ($this->getData('hasPluginClass', false)): ?>
        <div class="plugin-edit-card mn-mt-16">
            <h3 class="admin-section-title"><?php echo $this->t('admin.plugin_main_class'); ?></h3>
            <p class="mn-text-muted p-8-0"><?php echo $this->t('admin.plugin_main_class_hint'); ?></p>
        </div>
        <?php endif; ?>
    </main>
</div>

<?php $this->endSection(); ?>