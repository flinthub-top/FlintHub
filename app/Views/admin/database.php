<?php
/**
 * 后台数据库管理视图 — 备份列表、备份创建/恢复/下载
 * @file app/Views/admin/database.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.database_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php
$backups = $this->getData('backups', []);
$dbSize = $this->getData('dbSize', 0);
$dbPath = $this->getData('dbPath', '');
$success = $this->getData('success', false);
$error = $this->getData('error', '');
$page = (int)$this->getData('page', 1);
$totalPages = (int)$this->getData('totalPages', 1);
$total = (int)$this->getData('total', 0);
$csrfToken = $this->e($this->getData('csrfToken'));

/**
 * 智能格式化文件大小
 */
function formatSize($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'database']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('save', 16); ?> <?php echo $this->t('admin.nav_backup'); ?></h1>
        </div>
        <?php
        $successMsg = '';
        if ($success === 'backup') $successMsg = \app\Helpers\I18n::get('admin.db_backup_success');
        elseif ($success === 'import') $successMsg = \app\Helpers\I18n::get('admin.db_import_success');
        elseif ($success === 'restore') $successMsg = \app\Helpers\I18n::get('admin.db_restore_success');
        elseif ($success === 'optimize') $successMsg = \app\Helpers\I18n::get('admin.db_optimize_success');
        elseif ($success === 'delete') $successMsg = \app\Helpers\I18n::get('admin.db_delete_success');
        elseif ($success === 'extern_merge_done') $successMsg = \app\Helpers\I18n::get('admin.extern_merge_done_msg');
        elseif ($success === 'extern_merge_stopped') $successMsg = \app\Helpers\I18n::get('admin.extern_merge_stopped_msg');
        ?>
        <?php if ($successMsg): ?><div class="alert alert-success"><?php echo $successMsg; ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo $this->e($error); ?></div><?php endif; ?>
        <div class="admin-grid-2">
            <div class="admin-section">
                <h3 class="admin-section-title"><?php echo $this->t('admin.db_info'); ?></h3>
                <table class="admin-table">
                    <tr><td class="mn-fw-500"><?php echo $this->t('admin.db_type'); ?></td><td>SplitDB（SQLite 分片）</td></tr>
                    <?php if ($dbPath): ?><tr><td class="mn-fw-500"><?php echo $this->t('admin.db_path'); ?></td><td class="mn-fs-12 c-999"><?php echo $this->e($dbPath); ?></td></tr><?php endif; ?>
                    <tr><td class="mn-fw-500"><?php echo $this->t('admin.db_size'); ?></td><td><?php echo formatSize($dbSize); ?></td></tr>
                </table>
                <div class="mn-mt-15 mn-flex mn-gap-10">
                    <form method="POST" action="<?php echo $this->url('/admin/database?action=optimize'); ?>" class="mn-inline-block" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.db_optimize_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                        <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                        <button type="submit" class="btn btn-primary"><?php echo $this->icon('add', 14); ?> <?php echo $this->t('admin.db_optimize'); ?></button>
                    </form>
                    <form method="POST" action="<?php echo $this->url('/admin/database?action=backup'); ?>" class="mn-inline-block" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.db_backup_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                        <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                        <button type="submit" class="btn btn-secondary"><?php echo $this->icon('save', 14); ?> <?php echo $this->t('common.backup'); ?></button>
                    </form>
                </div>
                <div class="mn-mt-10 mn-fs-12 c-999"><?php echo $this->icon('info', 12); ?> <?php echo $this->t('admin.db_optimize_hint'); ?></div>
            </div>
            <div class="admin-section">
                <h3 class="admin-section-title"><?php echo $this->t('admin.db_restore'); ?></h3>
                <form method="POST" action="<?php echo $this->url('/admin/database?action=import'); ?>" enctype="multipart/form-data" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.db_import_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                    <div class="form-group">
                        <label><?php echo $this->t('admin.db_upload_hint'); ?></label>
                        <input type="file" name="backup_file" accept=".zip,.sqlite" required class="form-input">
                        <small class="mn-fs-12 c-999"><?php echo $this->t('admin.db_file_format'); ?></small>
                    </div>
                    <button type="submit" class="btn btn-warning"><?php echo $this->icon('upload', 14); ?> <?php echo $this->t('admin.db_upload_import'); ?></button>
                </form>
            </div>
        </div>
        <?php
        // ===== extern 正文合并（.txt → .bin）状态 =====
        $externMerge = $this->getData('externMerge', null);
        $emRunning = is_array($externMerge) && !empty($externMerge['running']);
        $emIdle    = is_array($externMerge) && !empty($externMerge['idle']);
        $emDone    = (int)($externMerge['done'] ?? 0);
        $emTotal   = (int)($externMerge['total'] ?? 0);
        $emPercent = $emTotal > 0 ? (int)round($emDone / $emTotal * 100) : 0;
        $emNextUrl = $externMerge['nextUrl'] ?? '';
        ?>
        <div class="admin-section">
            <h3 class="admin-section-title"><?php echo $this->icon('file-archive', 14); ?> <?php echo $this->t('admin.extern_merge_title'); ?></h3>
            <div class="mn-fs-12 c-999 mn-mb-10"><?php echo $this->t('admin.extern_merge_hint'); ?></div>

            <?php if ($emRunning): ?>
            <!-- 进行中：进度 + meta refresh 自动续批 -->
            <div class="rebuild-bar-wrap">
                <div class="rebuild-bar-fill" style="width:<?php echo $emPercent; ?>%;">
                    <span class="rebuild-bar-text"><?php echo $emPercent; ?>%</span>
                </div>
            </div>
            <p class="c-666 mn-fs-13"><?php echo $this->t('admin.extern_merge_progress', ['done' => $emDone, 'total' => $emTotal]); ?></p>
            <p class="c-999 mn-fs-12"><?php echo $this->t('admin.extern_merge_do_not_close'); ?></p>
            <?php if ($emNextUrl): ?><meta http-equiv="refresh" content="3;url=<?php echo $this->e($emNextUrl); ?>"><?php endif; ?>
            <form method="POST" action="<?php echo $this->url('/admin/database?action=extern_merge_stop'); ?>" class="mn-inline-block mn-mt-10" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.extern_merge_stop_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <button type="submit" class="btn btn-danger"><?php echo $this->t('admin.extern_merge_stop'); ?></button>
            </form>

            <?php elseif ($emIdle): ?>
            <!-- 有断点未完成：可继续 / 停止 -->
            <p class="c-666 mn-fs-13"><?php echo $this->t('admin.extern_merge_idle_hint', ['done' => $emDone, 'total' => $emTotal]); ?></p>
            <div class="mn-mt-10 mn-flex mn-gap-10 mn-flex-wrap">
                <form method="POST" action="<?php echo $this->url('/admin/database?action=extern_merge_resume'); ?>" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                    <select name="extern_merge_per_page" class="form-input select-narrow">
                        <option value="5">5</option>
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                    </select>
                    <button type="submit" class="btn btn-primary"><?php echo $this->t('admin.extern_merge_resume'); ?></button>
                </form>
                <form method="POST" action="<?php echo $this->url('/admin/database?action=extern_merge_stop'); ?>" class="mn-inline-block" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.extern_merge_stop_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                    <button type="submit" class="btn btn-danger"><?php echo $this->t('admin.extern_merge_stop'); ?></button>
                </form>
            </div>

            <?php else: ?>
            <!-- 无任务：扫描并开始 -->
            <form method="POST" action="<?php echo $this->url('/admin/database?action=extern_merge_start'); ?>" class="mn-mt-10" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.extern_merge_start_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <span class="mn-fs-13 c-666"><?php echo $this->t('admin.extern_merge_per_batch'); ?></span>
                <select name="extern_merge_per_page" class="form-input select-narrow">
                    <option value="5">5</option>
                    <option value="10" selected>10</option>
                    <option value="20">20</option>
                    <option value="50">50</option>
                </select>
                <button type="submit" class="btn btn-primary"><?php echo $this->t('admin.extern_merge_start'); ?></button>
            </form>
            <?php endif; ?>
        </div>
        <div class="admin-section">
            <h3 class="admin-section-title"><?php echo $this->t('admin.db_backup_list'); ?></h3>
            <?php if (empty($backups)): ?>
            <p class="c-999 mn-fs-13"><?php echo $this->t('admin.db_no_backup'); ?></p>
            <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr><th><?php echo $this->t('admin.db_filename'); ?></th><th><?php echo $this->t('admin.db_size'); ?></th><th><?php echo $this->t('admin.time'); ?></th><th><?php echo $this->t('common.actions'); ?></th></tr>
                </thead>
                <tbody>
                <?php foreach ($backups as $b): ?>
                    <tr>
                        <td><?php echo $this->e($b['name']); ?></td>
                        <td><?php echo formatSize($b['size']); ?></td>
                        <td class="mn-fs-12 c-999"><?php echo date('Y-m-d H:i', $b['time']); ?></td>
                        <td class="mn-flex mn-gap-5">
                            <form method="POST" action="<?php echo $this->url('/admin/database?action=download'); ?>" class="mn-inline-block" x-data x-on:submit.prevent="$el.submit()">
                                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="file" value="<?php echo $this->e($b['name']); ?>">
                                <button type="submit" class="btn btn-sm btn-primary" title="<?php echo $this->t('common.download'); ?>"><?php echo $this->icon('download', 13); ?></button>
                            </form>
                            <form method="POST" action="<?php echo $this->url('/admin/database?action=restore'); ?>" class="mn-inline-block" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.db_restore_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="backup_file" value="<?php echo $this->e($b['name']); ?>">
                                <button type="submit" class="btn btn-sm btn-warning" title="<?php echo $this->t('admin.restore'); ?>"><?php echo $this->icon('upload', 13); ?></button>
                            </form>
                            <form method="POST" action="<?php echo $this->url('/admin/database?action=delete'); ?>" class="mn-inline-block" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.db_delete_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="file" value="<?php echo $this->e($b['name']); ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="<?php echo $this->t('common.delete'); ?>"><?php echo $this->icon('close', 13); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php echo $this->pagination($page, $totalPages, '/admin/database?page={page}', ['style' => 'admin', 'class' => 'mt-15']); ?>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php $this->endSection(); ?>