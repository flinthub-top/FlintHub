<?php
/**
 * 博客编辑视图 — 写博客/编辑博客（管理员）
 * @file app/Views/blog/edit.php
 */
$blog = $this->getData('blog');
$categories = $this->getData('blogCategories', []);
$error = $this->getData('error');
$isNew = $this->getData('isNew', true);
$isAdmin = $this->getData('isAdmin');
?>
<?php $this->section('title'); ?><?php echo $isNew ? $this->t('blog.new') : $this->t('blog.edit'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section">
    <div class="mn-section-header">
        <h2><?php echo $this->icon($isNew ? 'write' : 'edit', 16); ?> <?php echo $isNew ? $this->t('blog.new') : $this->t('blog.edit'); ?></h2>
    </div>
    <div class="mn-p-20">
        <?php if ($error): ?><div class="mn-alert mn-alert-error"><?php echo $this->e($error); ?></div><?php endif; ?>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?php echo $this->e($this->getData('csrfToken')); ?>">
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo $this->t('common.title'); ?></label>
                <input type="text" name="title" required maxlength="<?php echo (int)\app\Helpers\Settings::get('limit_thread_title', '200'); ?>" value="<?php echo $this->e($blog['title'] ?? ''); ?>" class="mn-input">
            </div>
            <?php if (!empty($categories)): ?>
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo $this->t('common.category'); ?></label>
                <select name="category_id" class="mn-select mn-maxw-300">
                    <option value=""><?php echo $this->t('blog.uncategorized'); ?></option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo (int)$cat['id']; ?>" <?php echo $blog && (int)$cat['id'] === (int)($blog['category_id'] ?? 0) ? 'selected' : ''; ?>><?php echo $this->e($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo $this->t('common.content'); ?></label>
                <textarea name="content" id="content" data-editor="content" rows="20" class="mn-textarea" required><?php echo $this->e($this->decodeContent($blog['content'] ?? '')); ?></textarea>
            </div>
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo $this->t('blog.cover_image'); ?></label>
                <div class="mn-flex mn-gap-10 mn-flex-wrap">
                    <select name="cover_template" id="cover_template" class="mn-select mn-maxw-300">
                        <option value=""><?php echo $this->t('blog.cover_none'); ?></option>
                        <?php foreach (\app\Helpers\CoverTemplate::templates() as $tpl => $labelKey): ?>
                        <option value="<?php echo $this->e($tpl); ?>"><?php echo $this->t($labelKey); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="file" name="cover_image" accept="image/*" class="mn-input mn-maxw-400" style="padding:6px 12px;">
                </div>
                <div class="mn-mt-8">
                    <label class="mn-label"><?php echo $this->t('blog.cover_preview'); ?></label>
                    <div id="cover_preview_wrap" class="mn-mt-4">
                        <?php if (!empty($blog['cover_image'])): ?>
                        <img src="<?php echo $this->e(\UPLOAD_URL . $blog['cover_image']); ?>" alt="" class="mn-cover-current" style="width:320px;height:200px;object-fit:cover;border-radius:6px;border:1px solid var(--mn-border,#e2e8f0);">
                        <?php endif; ?>
                    </div>
                    <div class="mn-muted mn-mt-4" style="font-size:12px;"><?php echo $this->t('blog.cover_hint'); ?></div>
                </div>
            </div>
            <div class="mn-flex-center mn-gap-10">
                <button type="submit" class="mn-btn mn-btn-primary"><?php echo $this->icon('save', 12); ?> <?php echo $isNew ? $this->t('blog.publish_btn') : $this->t('common.save'); ?></button>
                <a href="<?php echo $isNew ? $this->url('/blog') : $this->url('/blog/' . (int)($blog['id'] ?? 0)); ?>" class="mn-btn"><?php echo $this->icon('back', 12); ?> <?php echo $this->t('common.back'); ?></a>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    // 封面模板实时预览：选择模板/输入标题时防抖拉取 /api/cover-preview（只生成不落盘）
    var titleInput = document.querySelector('input[name="title"]');
    var tplSelect = document.getElementById('cover_template');
    var previewWrap = document.getElementById('cover_preview_wrap');
    if (!titleInput || !tplSelect || !previewWrap) return;
    var author = <?php echo \json_encode((string)$this->getData('currentUsername', ''), JSON_UNESCAPED_UNICODE); ?>;
    var date = <?php echo \json_encode(\date('Y-m-d')); ?>;
    var timer = null;
    function update() {
        var tpl = tplSelect.value;
        if (!tpl) return; // 未选模板不生成预览（保留当前封面显示）
        var title = titleInput.value.trim();
        var url = (window.BASE_PATH || '') + '/api/cover-preview?template=' + encodeURIComponent(tpl)
            + '&title=' + encodeURIComponent(title)
            + '&author=' + encodeURIComponent(author)
            + '&date=' + encodeURIComponent(date);
        previewWrap.innerHTML = '<img src="' + url + '" alt="" style="width:320px;height:200px;object-fit:cover;border-radius:6px;border:1px solid var(--mn-border,#e2e8f0);">';
    }
    titleInput.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(update, 300); });
    tplSelect.addEventListener('change', update);
})();
</script>
<?php $this->endSection(); ?>