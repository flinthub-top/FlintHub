<?php
/**
 * 操作日志查看视图 — audit_logs 分页列表 + 日期/操作类型筛选
 * @file app/Views/admin/logs/audit.php
 */
?>
<?php $this->section('title'); ?>操作日志 - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php
$items      = $this->getData('items', []);
$total      = (int)$this->getData('total', 0);
$page       = (int)$this->getData('page', 1);
$totalPages = (int)$this->getData('totalPages', 1);
$curAction  = (string)$this->getData('action', '');
$curDate    = (string)$this->getData('date', '');
$actions    = $this->getData('actions', []);
?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'logs_audit']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('list', 18); ?> 操作日志</h1>
        </div>

        <!-- 筛选表单（GET，无需 CSRF） -->
        <div class="admin-section">
            <form method="GET" action="<?php echo $this->url('/admin/logs/audit'); ?>" class="mn-flex mn-gap-10" style="align-items:flex-end">
                <div class="form-group" style="margin-bottom:0">
                    <label>操作类型</label>
                    <select name="action" class="form-input" style="max-width:200px">
                        <option value="">全部</option>
                        <?php foreach ($actions as $a): ?>
                            <option value="<?php echo $this->e($a); ?>" <?php echo ($curAction === $a) ? 'selected' : ''; ?>><?php echo $this->e($a); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label>日期</label>
                    <input type="date" name="date" class="form-input" style="max-width:180px" value="<?php echo $this->e($curDate); ?>">
                </div>
                <button type="submit" class="btn btn-primary"><?php echo $this->icon('search', 14); ?> 查询</button>
                <?php if ($curAction !== '' || $curDate !== ''): ?>
                <a href="<?php echo $this->url('/admin/logs/audit'); ?>" class="btn btn-secondary">清除筛选</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- 日志列表 -->
        <div class="admin-section">
            <h2><?php echo $this->icon('history', 14); ?> 日志记录（共 <?php echo number_format($total); ?> 条）</h2>
            <?php if (empty($items)): ?>
            <p class="c-999 mn-fs-13">暂无操作日志</p>
            <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr><th style="width:160px">时间</th><th>管理员</th><th>操作类型</th><th>目标</th><th style="width:140px">IP</th><th>详情</th></tr>
                </thead>
                <tbody>
                <?php foreach ($items as $log): ?>
                    <tr>
                        <td class="mn-fs-12 c-999"><?php echo $this->e((string)($log['created_at'] ?? '')); ?></td>
                        <td><?php echo $this->e((string)($log['username'] ?? '')) ?: '<span class="c-999">-</span>'; ?></td>
                        <td><span class="badge badge-info"><?php echo $this->e((string)($log['action'] ?? '')); ?></span></td>
                        <td class="mn-fs-12">
                            <?php echo $this->e((string)($log['target_type'] ?? '')); ?>
                            <?php if ((int)($log['target_id'] ?? 0) > 0): ?> #<?php echo (int)$log['target_id']; ?><?php endif; ?>
                        </td>
                        <td class="mn-fs-12 c-999"><?php echo $this->e((string)($log['ip'] ?? '')); ?></td>
                        <td class="mn-fs-12" style="max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo $this->e((string)($log['detail'] ?? '')); ?>">
                            <?php echo $this->e((string)($log['detail'] ?? '')); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php
            $qs = [];
            if ($curAction !== '') $qs[] = 'action=' . urlencode($curAction);
            if ($curDate !== '')   $qs[] = 'date=' . urlencode($curDate);
            $baseUrl = '/admin/logs/audit?' . (implode('&', $qs) ? implode('&', $qs) . '&' : '') . 'page={page}';
            echo $this->pagination($page, $totalPages, $baseUrl, ['class' => 'mt-15']);
            ?>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php $this->endSection(); ?>