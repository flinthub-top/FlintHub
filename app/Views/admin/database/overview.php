<?php
/**
 * SplitDB 引擎概览视图（只读监控）— 分片分布 / 总帖子数 / 建议桶数 / 当前季度
 * @file app/Views/admin/database/overview.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.ov_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php
$bucketSize      = (int)$this->getData('bucketSize', 32);
$quarter         = $this->getData('quarter', '');
$bucketStats     = $this->getData('bucketStats', []);
$totalThreads    = (int)$this->getData('totalThreads', 0);
$suggested       = (int)$this->getData('suggestedBuckets', 32);

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
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'database_overview']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('database', 18); ?> <?php echo $this->t('admin.ov_title'); ?></h1>
        </div>

        <div class="alert alert-info">
            <?php echo $this->icon('settings', 14); ?> <?php echo $this->t('admin.ov_redirect_hint'); ?>
            <a href="<?php echo $this->url('/admin/database/settings'); ?>"><?php echo $this->t('admin.ov_settings'); ?></a> 页。
        </div>

        <!-- 引擎概览（只读） -->
        <div class="admin-section">
            <h3 class="admin-section-title"><?php echo $this->icon('info', 14); ?> <?php echo $this->t('admin.ov_engine_status'); ?>
                <a href="<?php echo $this->url('/admin/database/overview?refresh_stats=1'); ?>" class="btn btn-sm btn-secondary section-action" title="<?php echo $this->t('admin.ov_refresh_stats_title'); ?>"><?php echo $this->icon('refresh', 13); ?> <?php echo $this->t('admin.ov_refresh_stats'); ?></a>
            </h3>
            <table class="admin-table">
                <tr>
                    <td class="mn-fw-500 col-label"><?php echo $this->t('admin.ov_current_bucket'); ?></td>
                    <td><?php echo $bucketSize; ?> <?php echo $this->t('admin.ov_bucket_unit'); ?></td>
                    <td class="mn-fw-500 col-label"><?php echo $this->t('admin.ov_current_quarter'); ?></td>
                    <td><?php echo $this->e($quarter); ?></td>
                </tr>
                <tr>
                    <td class="mn-fw-500"><?php echo $this->t('admin.ov_total_threads'); ?></td>
                    <td><?php echo number_format($totalThreads); ?></td>
                    <td class="mn-fw-500"><?php echo $this->t('admin.ov_suggested_buckets'); ?></td>
                    <td>
                        <?php echo $suggested; ?> <?php echo $this->t('admin.ov_bucket_unit'); ?>
                        <?php if ($suggested > $bucketSize): ?>
                            <span class="badge badge-warning mn-ml-8"><?php echo $this->t('admin.ov_upgrade_hint', ['n' => $suggested]); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>

        <!-- 分片分布统计 -->
        <div class="admin-section">
            <h3 class="admin-section-title"><?php echo $this->icon('database', 14); ?> <?php echo $this->t('admin.ov_bucket_stats', ['quarter' => $this->e($quarter)]); ?></h3>
            <?php if (!empty($bucketStats)): ?>
            <table class="admin-table">
                <thead>
                    <tr><th><?php echo $this->t('admin.ov_bucket_no'); ?></th><th><?php echo $this->t('admin.ov_topic'); ?></th><th><?php echo $this->t('admin.ov_reply'); ?></th><th><?php echo $this->t('admin.ov_file_size'); ?></th></tr>
                </thead>
                <tbody>
                <?php foreach ($bucketStats as $s): ?>
                    <tr>
                        <td><?php echo $this->e($s['bucket']); ?></td>
                        <td><?php echo ($s['topic'] >= 0) ? number_format($s['topic']) : '<span class="text-error">' . $this->t('admin.ov_read_error') . '</span>'; ?></td>
                        <td><?php echo ($s['reply'] >= 0) ? number_format($s['reply']) : '<span class="text-error">' . $this->t('admin.ov_read_error') . '</span>'; ?></td>
                        <td class="mn-fs-12 c-999"><?php echo formatSize($s['size']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="c-999 mn-fs-13 mn-mt-10"><?php echo $this->t('admin.ov_empty_quarter'); ?></p>
            <?php endif; ?>
            <p class="mn-fs-12 c-999 mn-mt-10"><?php echo $this->t('admin.ov_cache_hint'); ?></p>
        </div>
    </main>
</div>
<?php $this->endSection(); ?>