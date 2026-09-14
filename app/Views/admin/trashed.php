<?php
/**
 * 后台回收站视图 — 已删除帖子/博客列表、恢复、彻底删除
 * @file app/Views/admin/trashed.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.trashed_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php
$trashed = $this->getData('trashed', []);
$page = $this->getData('page', 1);
$totalPages = $this->getData('totalPages', 1);
$totalTrashed = $this->getData('totalTrashed', 0);
$msg = $this->getData('msg');
$csrfToken = $this->e($this->getData('csrfToken'));
?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'trashed']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('trash', 18); ?> <?php echo $this->t('admin.trashed_title'); ?></h1>
            <span class="mn-fs-13 c-999"><?php echo $this->t('admin.trashed_total', ['count' => (int)$totalTrashed]); ?></span>
        </div>
        <?php if ($msg === 'restored'): ?><div class="alert alert-success"><?php echo $this->t('admin.restored'); ?></div><?php endif; ?>
        <?php if ($msg === 'deleted'): ?><div class="alert alert-success"><?php echo $this->t('admin.permanently_deleted'); ?></div><?php endif; ?>
        <?php if (empty($trashed)): ?>
        <div class="admin-section mn-text-center admin-empty-state">
            <p class="c-999 mn-fs-15"><?php echo $this->t('admin.trash_empty'); ?></p>
        </div>
        <?php else: ?>
        <div class="admin-section admin-scroll">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="admin-w-40">ID</th>
                        <th><?php echo $this->t('common.title'); ?></th>
                        <th class="admin-w-100"><?php echo $this->t('admin.author'); ?></th>
                        <th class="admin-w-80"><?php echo $this->t('common.category'); ?></th>
                        <th class="admin-w-140"><?php echo $this->t('admin.delete_time'); ?></th>
                        <th class="admin-w-160"><?php echo $this->t('common.actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($trashed as $t): ?>
                    <tr>
                        <td class="mn-text-center c-999 mn-fs-13"><?php echo (int)$t['id']; ?></td>
                        <td><a href="<?php echo $this->url('/thread/' . (int)$t['id']); ?>" class="c-333 mn-text-decoration-none" target="_blank"><?php echo $this->e($t['title'] ?? ''); ?></a></td>
                        <td class="mn-fs-13 c-666"><?php echo $this->e($t['username'] ?? '-'); ?></td>
                        <td class="mn-fs-13 c-666"><?php echo $this->e($t['category_name'] ?? '-'); ?></td>
                        <td class="mn-fs-13 c-999"><?php echo $this->e(date('Y-m-d H:i', strtotime($t['deleted_at'] ?? 'now'))); ?></td>
                        <td class="mn-text-center">
                            <form method="POST" class="mn-inline-block">
                                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="thread_id" value="<?php echo (int)$t['id']; ?>">
                                <button type="submit" name="sub_action" value="restore" class="btn-restore"><?php echo $this->t('admin.restore'); ?></button>
                                <button type="submit" name="sub_action" value="hard_delete" class="btn-delete" onclick="return confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.permanent_delete_confirm')), ENT_QUOTES, 'UTF-8'); ?>)"><?php echo $this->t('admin.permanent_delete'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php echo $this->pagination($page, $totalPages, '/admin/trashed?page={page}', ['style' => 'admin', 'class' => 'mt-15']); ?>
        <?php endif; ?>
    </main>
</div>
<?php $this->endSection(); ?>