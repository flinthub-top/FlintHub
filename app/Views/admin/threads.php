<?php
/**
 * 后台帖子管理视图 — 帖子列表、搜索、批量管理
 * @file app/Views/admin/threads.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.threads_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php
$threads = $this->getData('threads', []);
$csrfToken = $this->e($this->getData('csrfToken'));
$page = $this->getData('page', 1);
$totalPages = $this->getData('totalPages', 1);
$totalThreads = $this->getData('totalThreads', 0);
$categories = $this->getData('categories', []);
// 分类筛选：回显下拉框选中态（0/非法 = 全部）
$filterCategoryId = (int)$this->getData('filterCategoryId', 0);
?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'threads']); ?>
    <main class="admin-content">
        <div class="admin-header mn-flex-between">
            <h1><?php echo $this->icon('threads', 16); ?> <?php echo $this->t('admin.threads_title'); ?> <span class="mn-fs-14 mn-fw-400 c-999"><?php echo $this->t('admin.total_threads', ['count' => $totalThreads]); ?></span></h1>
        </div>

        <?php $msg = $_GET['msg'] ?? ''; if (preg_match('/^deleted_(\d+)$/', $msg, $m)): ?>
            <div class="alert alert-success"><?php echo $this->t('admin.batch_deleted', ['count' => (int)$m[1]]); ?></div>
        <?php elseif (preg_match('/^moved_(\d+)$/', $msg, $m)): ?>
            <div class="alert alert-success"><?php echo $this->t('admin.batch_moved', ['count' => (int)$m[1]]); ?></div>
        <?php endif; ?>

        <div class="mn-mb-10 mn-flex mn-gap-10 mn-items-center mn-flex-wrap">
            <!-- 分类筛选：即时跳转（0=全部），翻页/批量操作均保留此参数 -->
            <select class="form-input w-160" onchange="location.href='<?php echo $this->url('/admin/threads'); ?>?category_id=' + this.value">
                <option value="0" <?php echo $filterCategoryId === 0 ? 'selected' : ''; ?>><?php echo $this->t('forum.category_all'); ?></option>
                <?php foreach ($categories as $c): ?>
                <option value="<?php echo (int)$c['id']; ?>" <?php echo $filterCategoryId === (int)$c['id'] ? 'selected' : ''; ?>><?php echo $this->e($c['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <label class="mn-flex mn-items-center mn-gap-4 mn-fs-13 mn-cursor-pointer">
                <input type="checkbox" id="select-all"
                       x-data
                       x-on:change="document.querySelectorAll('.thread-checkbox').forEach(c => c.checked = $el.checked); updateBatchBtn()">
                <?php echo $this->t('admin.select_all'); ?>
            </label>
            <button type="button" class="btn-delete" id="batch-delete-btn" disabled><?php echo $this->t('admin.batch_delete'); ?></button>
            <!-- 移动到（select+按钮）：窄屏时整体换到下一行 -->
            <span class="mn-flex mn-gap-10 mn-items-center">
                <select id="move-category" class="form-input w-160">
                    <option value=""><?php echo $this->t('admin.move_to'); ?></option>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?php echo (int)$c['id']; ?>"><?php echo $this->e($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn-save" id="batch-move-btn" disabled><?php echo $this->t('admin.move_btn'); ?></button>
            </span>
        </div>
        <table class="admin-table admin-table-mobile-hide-id">
            <thead>
                <tr>
                    <th class="admin-w-40"><input type="checkbox" disabled></th>
                    <th class="admin-w-50">ID</th>
                    <th><?php echo $this->t('common.title'); ?></th>
                    <th class="admin-w-80"><?php echo $this->t('admin.author'); ?></th>
                    <th class="admin-w-120"><?php echo $this->t('common.category'); ?></th>
                    <th class="admin-w-150"><?php echo $this->t('admin.time'); ?></th>
                    <th class="admin-w-120"><?php echo $this->t('common.actions'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($threads as $t): ?>
                <tr>
                    <td><input type="checkbox" value="<?php echo (int)$t['id']; ?>" class="thread-checkbox" onchange="updateBatchBtn()"></td>
                    <td><?php echo (int)$t['id']; ?></td>
                    <td class="mobile-col-title">
                        <div class="mn-flex-center mn-gap-5 mn-flex-wrap">
                            <?php if (!empty($t['is_pinned'])): ?><span class="tag tag-hot tag-xs"><?php echo $this->icon('pinned', 11); ?> <?php echo $this->t('common.pinned'); ?></span><?php endif; ?>
                            <?php if (!empty($t['is_highlighted'])): ?><span class="tag tag-danger tag-xs"><?php echo $this->icon('highlight', 11); ?> <?php echo $this->t('forum.highlighted'); ?></span><?php endif; ?>
                            <a href="<?php echo $this->url('/thread/' . (int)$t['id']); ?>" class="mn-text-decoration-none mn-fw-500" style="color:<?php echo $this->e($t['color'] ?: 'var(--text-dark)'); ?><?php if (!empty($t['color'])): ?>;font-weight:600<?php endif; ?>"><?php echo $this->e(mb_substr($t['title'] ?? '', 0, 40)); ?></a>
                        </div>
                    </td>
                    <td class="mn-text-nowrap mobile-col-meta"><?php echo $this->e($t['username'] ?? ''); ?></td>
                    <td class="mn-text-nowrap mobile-col-meta"><?php echo $this->e($t['category_name'] ?? ''); ?></td>
                    <td class="mn-fs-12 c-999 mn-text-nowrap mobile-col-meta"><?php echo !empty($t['created_at']) ? date('Y-m-d H:i', strtotime($t['created_at'])) : ''; ?></td>
                    <td class="actions mn-text-nowrap">
                        <a href="<?php echo $this->url('/admin/threads?action=edit&id=' . (int)$t['id']); ?>" class="btn-edit"><?php echo $this->t('common.edit'); ?></a>
                        <form method="POST" class="mn-inline-block" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('js.confirm_delete_thread')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                            <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                            <button type="submit" class="btn-delete"><?php echo $this->t('common.delete'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <script>
        function updateBatchBtn() {
            var boxes = document.querySelectorAll('.thread-checkbox:checked');
            document.getElementById('batch-delete-btn').disabled = boxes.length === 0;
            document.getElementById('batch-move-btn').disabled = boxes.length === 0;
        }
        function batchDelete() {
            var boxes = document.querySelectorAll('.thread-checkbox:checked');
            if (boxes.length === 0) return;
            if (!confirm(<?php echo json_encode(\app\Helpers\I18n::get('admin.batch_delete_confirm', ['count' => '__N__'])); ?>.replace('__N__', boxes.length))) return;
            var ids = [];
            for (var i = 0; i < boxes.length; i++) ids.push(boxes[i].value);
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?php echo $this->url('/admin/threads'); ?>';
            form.appendChild(createHidden('csrf', '<?php echo $csrfToken; ?>'));
            form.appendChild(createHidden('action', 'batch_delete'));
            form.appendChild(createHidden('category_id', '<?php echo (int)$filterCategoryId; ?>'));
            for (var j = 0; j < ids.length; j++) form.appendChild(createHidden('thread_ids[]', ids[j]));
            document.body.appendChild(form);
            form.submit();
        }
        function batchMove() {
            var boxes = document.querySelectorAll('.thread-checkbox:checked');
            var target = document.getElementById('move-category');
            if (boxes.length === 0 || !target.value) return;
            if (!confirm(<?php echo json_encode(\app\Helpers\I18n::get('admin.batch_move_confirm', ['count' => '__N__', 'name' => '__NAME__'])); ?>.replace('__N__', boxes.length).replace('__NAME__', target.options[target.selectedIndex].text))) return;
            var ids = [];
            for (var i = 0; i < boxes.length; i++) ids.push(boxes[i].value);
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?php echo $this->url('/admin/threads'); ?>';
            form.appendChild(createHidden('csrf', '<?php echo $csrfToken; ?>'));
            form.appendChild(createHidden('action', 'batch_move'));
            form.appendChild(createHidden('category_id', '<?php echo (int)$filterCategoryId; ?>'));
            form.appendChild(createHidden('target_category_id', target.value));
            for (var j = 0; j < ids.length; j++) form.appendChild(createHidden('thread_ids[]', ids[j]));
            document.body.appendChild(form);
            form.submit();
        }
        function createHidden(name, value) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            return input;
        }
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('batch-delete-btn')?.addEventListener('click', batchDelete);
            document.getElementById('batch-move-btn')?.addEventListener('click', batchMove);
        });
        </script>

        <?php $pageUrl = '/admin/threads?category_id=' . (int)$filterCategoryId . '&amp;page={page}'; ?>
        <?php echo $this->pagination($page, $totalPages, $pageUrl, ['style' => 'admin', 'class' => 'mt-20']); ?>
    </main>
</div>
<?php $this->endSection(); ?>