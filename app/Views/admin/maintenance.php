<?php
/**
 * 后台维护工具视图 — 缓存清理、搜索索引重建、数据统计
 * @file app/Views/admin/maintenance.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.mt_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php
$message = $this->getData('message', '');
$error = $this->getData('error', '');
$csrfToken = $this->e($this->getData('csrfToken'));
?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'maintenance']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('wrench', 16); ?> <?php echo $this->t('admin.mt_title'); ?></h1>
        </div>
        <?php if ($message): ?><div class="alert alert-success"><?php echo $this->e($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo $this->e($error); ?></div><?php endif; ?>

        <div class="admin-grid-2">
            <!-- 标签重建 -->
            <div class="admin-section">
                <h3 class="admin-section-title"><?php echo $this->icon('tag', 16); ?> <?php echo $this->t('admin.mt_rebuild_tags'); ?></h3>
                <p class="mn-fs-13 c-666 mn-mb-15">
                    <?php echo $this->t('admin.mt_rebuild_tags_hint'); ?>
                </p>
                <table class="admin-table admin-table-kv mn-mb-15">
                    <tr><td class="mn-fw-500"><?php echo $this->t('admin.mt_func'); ?></td>
                        <td><?php echo $this->t('admin.mt_rebuild_tags_func'); ?></td></tr>
                    <tr><td class="mn-fw-500"><?php echo $this->t('admin.mt_applicable'); ?></td><td><?php echo $this->t('admin.mt_all_db_types'); ?></td></tr>
                </table>
                <form method="POST" action="<?php echo $this->url('/admin/maintenance'); ?>" class="mn-mb-0" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.mt_rebuild_tags_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="rebuild_tags">
                    <button type="submit" class="btn btn-primary">
                        <?php echo $this->icon('add', 14); ?> <?php echo $this->t('admin.mt_rebuild_tags_btn'); ?>
                    </button>
                </form>
            </div>

            <!-- 更新缓存 -->
            <div class="admin-section">
                <h3 class="admin-section-title"><?php echo $this->icon('save', 16); ?> <?php echo $this->t('admin.mt_clear_cache'); ?></h3>
                <p class="mn-fs-13 c-666 mn-mb-15">
                    <?php echo $this->t('admin.mt_clear_cache_hint'); ?>
                </p>
                <table class="admin-table admin-table-kv mn-mb-15">
                    <tr><td class="mn-fw-500"><?php echo $this->t('admin.mt_func'); ?></td>
                        <td><code class="mn-fs-12">protected/settings_cache.php</code></td></tr>
                    <tr><td class="mn-fw-500"><?php echo $this->t('admin.mt_applicable'); ?></td>
                        <td><?php echo $this->t('admin.mt_clear_cache_scope'); ?></td></tr>
                </table>
                <form method="POST" action="<?php echo $this->url('/admin/maintenance'); ?>" class="mn-mb-0" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.mt_clear_cache_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="clear_cache">
                    <button type="submit" class="btn btn-primary">
                        <?php echo $this->icon('add', 14); ?> <?php echo $this->t('admin.mt_clear_cache_btn'); ?>
                    </button>
                </form>
            </div>

            <!-- 重建统计数据 -->
            <div class="admin-section">
                <h3 class="admin-section-title"><?php echo $this->icon('categories', 16); ?> <?php echo $this->t('admin.mt_rebuild_stats'); ?></h3>
                <p class="mn-fs-13 c-666 mn-mb-15">
                    <?php echo $this->t('admin.mt_rebuild_stats_hint'); ?>
                </p>
                <table class="admin-table admin-table-kv mn-mb-15">
                    <tr><td class="mn-fw-500"><?php echo $this->t('admin.mt_func'); ?></td>
                        <td><?php echo $this->t('admin.mt_rebuild_stats_items'); ?></td></tr>
                    <tr><td class="mn-fw-500"><?php echo $this->t('admin.mt_applicable'); ?></td>
                        <td><?php echo $this->t('admin.mt_rebuild_stats_mode'); ?></td></tr>
                </table>
                <form method="POST" action="<?php echo $this->url('/admin/maintenance'); ?>" class="mn-mb-0" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.mt_rebuild_stats_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="runtime_rebuild">
                    <button type="submit" class="btn btn-primary">
                        <?php echo $this->icon('add', 14); ?> <?php echo $this->t('admin.mt_rebuild_stats_btn'); ?>
                    </button>
                </form>
            </div>

            <!-- 清理孤儿附件 -->
            <div class="admin-section">
                <h3 class="admin-section-title"><?php echo $this->icon('trash', 16); ?> <?php echo $this->t('admin.mt_cleanup_attachments'); ?></h3>
                <p class="mn-fs-13 c-666 mn-mb-15">
                    <?php echo $this->t('admin.mt_cleanup_hint'); ?>
                </p>
                <table class="admin-table admin-table-kv mn-mb-15">
                    <tr><td class="mn-fw-500"><?php echo $this->t('admin.mt_cleanup_scope'); ?></td><td><code><?php echo $this->e(UPLOAD_PATH); ?></code></td></tr>
                    <tr><td class="mn-fw-500"><?php echo $this->t('admin.mt_cleanup_safety'); ?></td><td><?php echo $this->t('admin.mt_cleanup_safety_hint'); ?></td></tr>
                </table>
                <form method="POST" action="<?php echo $this->url('/admin/maintenance'); ?>" class="mn-mb-0" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.mt_cleanup_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="cleanup_attachments">
                    <button type="submit" class="btn btn-warning">
                        <?php echo $this->icon('trash', 14); ?> <?php echo $this->t('admin.mt_cleanup_btn'); ?>
                    </button>
                </form>
            </div>

        </div>
    </main>
</div>
<?php $this->endSection(); ?>