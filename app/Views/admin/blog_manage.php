<?php
/**
 * 后台博客管理视图 — 博客文章列表、搜索、批量操作
 * @file app/Views/admin/blog_manage.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.blog_manage_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php
$blogs = $this->getData('blogs', []);
$csrfToken = $this->e($this->getData('csrfToken'));
$blogCategories = $this->getData('blogCategories', []);
?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'blog-manage']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('blog-manage', 16); ?> <?php echo $this->t('admin.blog_manage_title'); ?></h1>
        </div>

        <?php $msg = $_GET['msg'] ?? ''; if (preg_match('/^deleted_(\d+)$/', $msg, $m)): ?>
            <div class="alert alert-success"><?php echo $this->t('admin.blog_batch_deleted', ['count' => (int)$m[1]]); ?></div>
        <?php elseif (preg_match('/^moved_(\d+)$/', $msg, $m)): ?>
            <div class="alert alert-success"><?php echo $this->t('admin.blog_batch_moved', ['count' => (int)$m[1]]); ?></div>
        <?php endif; ?>

        <div class="mn-mb-10 mn-flex mn-gap-10 mn-items-center mn-flex-wrap">
            <label class="mn-flex mn-items-center mn-gap-4 mn-fs-13 mn-cursor-pointer">
                <input type="checkbox" id="select-all"
                       x-data
                       x-on:change="document.querySelectorAll('.blog-checkbox').forEach(c => c.checked = $el.checked); updateBatchBtn()">
                <?php echo $this->t('admin.select_all'); ?>
            </label>
            <button type="button" class="btn-delete" id="batch-delete-btn" disabled><?php echo $this->t('admin.batch_delete'); ?></button>
            <!-- 移动到（select+按钮）：窄屏时整体换到下一行 -->
            <span class="mn-flex mn-gap-10 mn-items-center">
                <select id="move-category" class="form-input w-160">
                    <option value=""><?php echo $this->t('admin.move_to'); ?></option>
                    <?php foreach ($blogCategories as $c): ?>
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
            <?php foreach ($blogs as $b): ?>
                <tr>
                    <td><input type="checkbox" value="<?php echo (int)$b['id']; ?>" class="blog-checkbox" onchange="updateBatchBtn()"></td>
                    <td><?php echo (int)$b['id']; ?></td>
                    <td class="mobile-col-title"><a href="<?php echo $this->url('/blog/' . (int)$b['id']); ?>" class="c-333 mn-text-decoration-none mn-fw-500"><?php echo $this->e(mb_substr($b['title'] ?? '', 0, 40)); ?></a></td>
                    <td class="mn-text-nowrap mobile-col-meta"><?php echo $this->e($b['username'] ?? ''); ?></td>
                    <td class="mn-text-nowrap mobile-col-meta"><?php echo $this->e($b['category_name'] ?? $this->t('blog.uncategorized')); ?></td>
                    <td class="mn-fs-12 c-999 mn-text-nowrap mobile-col-meta"><?php echo !empty($b['created_at']) ? date('Y-m-d H:i', strtotime($b['created_at'])) : ''; ?></td>
                    <td class="actions mn-text-nowrap">
                        <a href="<?php echo $this->url('/blog/' . (int)$b['id'] . '/edit'); ?>" class="btn-edit"><?php echo $this->t('common.edit'); ?></a>
                        <form method="POST" action="<?php echo $this->url('/blog/' . (int)$b['id'] . '/delete'); ?>" class="mn-inline-block" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('js.confirm_delete_blog')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                            <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                            <button type="submit" class="btn-delete"><?php echo $this->t('common.delete'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php $page = (int)$this->getData('page', 1); $totalPages = (int)$this->getData('totalPages', 1); ?>
        <?php echo $this->pagination($page, $totalPages, '/admin/blog-manage?page={page}', ['style' => 'admin', 'class' => 'mt-15']); ?>
    </main>
</div>
<script>
function updateBatchBtn() {
    var boxes = document.querySelectorAll('.blog-checkbox:checked');
    document.getElementById('batch-delete-btn').disabled = boxes.length === 0;
    document.getElementById('batch-move-btn').disabled = boxes.length === 0;
}
function batchDelete() {
    var boxes = document.querySelectorAll('.blog-checkbox:checked');
    if (boxes.length === 0) return;
    if (!confirm(<?php echo json_encode(\app\Helpers\I18n::get('admin.blog_batch_delete_confirm', ['count' => '__N__'])); ?>.replace('__N__', boxes.length))) return;
    var ids = [];
    for (var i = 0; i < boxes.length; i++) ids.push(boxes[i].value);
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?php echo $this->url('/admin/blog-manage'); ?>';
    form.appendChild(createHidden('csrf', '<?php echo $csrfToken; ?>'));
    form.appendChild(createHidden('action', 'batch_delete'));
    for (var j = 0; j < ids.length; j++) form.appendChild(createHidden('blog_ids[]', ids[j]));
    document.body.appendChild(form);
    form.submit();
}
function batchMove() {
    var boxes = document.querySelectorAll('.blog-checkbox:checked');
    var target = document.getElementById('move-category');
    if (boxes.length === 0 || !target.value) return;
    if (!confirm(<?php echo json_encode(\app\Helpers\I18n::get('admin.blog_batch_move_confirm', ['count' => '__N__', 'name' => '__NAME__'])); ?>.replace('__N__', boxes.length).replace('__NAME__', target.options[target.selectedIndex].text))) return;
    var ids = [];
    for (var i = 0; i < boxes.length; i++) ids.push(boxes[i].value);
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?php echo $this->url('/admin/blog-manage'); ?>';
    form.appendChild(createHidden('csrf', '<?php echo $csrfToken; ?>'));
    form.appendChild(createHidden('action', 'batch_move'));
    form.appendChild(createHidden('target_category_id', target.value));
    for (var j = 0; j < ids.length; j++) form.appendChild(createHidden('blog_ids[]', ids[j]));
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
<?php $this->endSection(); ?>