<?php
/**
 * 发表新帖视图 — 分类、标题、内容、标签、附件、回复可见
 * @file app/Views/post/create.php
 */
$categories = $this->getData('categories', []);
$error = $this->getData('error');
$form = $this->getData('form', ['title' => '', 'content' => '', 'category_id' => 0]);
$hasPermission = $this->getData('hasPermission', true);
?>
<?php $this->section('title'); ?><?php echo $this->t('post.create'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>

<?php if (!$hasPermission): ?>
<div class="mn-section">
    <div class="mn-empty">
        <?php if ($error): ?>
        <p class="mn-alert mn-alert-error"><?php echo $this->e($error); ?></p>
        <?php else: ?>
        <p><?php echo $this->t('post.no_permission'); ?></p>
        <?php endif; ?>
        <p class="mn-mt-12"><a href="<?php echo $this->url('/forum'); ?>" class="mn-btn mn-btn-primary"><?php echo $this->icon('forum', 12); ?> <?php echo $this->t('nav.back_to_forum'); ?></a></p>
    </div>
</div>
<?php elseif (!empty($categories)): ?>
<div class="mn-section">
    <div class="mn-section-header">
        <h2><?php echo $this->icon('new-post', 16); ?> <?php echo $this->t('post.create'); ?></h2>
    </div>
    <div class="mn-p-20">
        <?php if ($error): ?>
        <div class="mn-alert mn-alert-error"><?php echo $this->e($error); ?></div>
        <?php endif; ?>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?php echo $this->e($this->getData('csrfToken')); ?>">
            <div class="mn-flex mn-gap-12" style="flex-wrap:wrap;">
                <div style="flex:1;min-width:180px;">
                    <label class="mn-label"><?php echo $this->t('common.category'); ?></label>
                    <select name="category_id" class="mn-select mn-w-full">
                        <option value=""><?php echo $this->t('post.select_category'); ?></option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo (int)($form['category_id'] ?? 0) === (int)$cat['id'] ? 'selected' : ''; ?>>
                            <?php echo $this->e($cat['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex:3;min-width:280px;">
                    <label class="mn-label"><?php echo $this->t('common.title'); ?></label>
                    <input type="text" name="title" required maxlength="<?php echo (int)\app\Helpers\Settings::get('limit_thread_title', '200'); ?>" value="<?php echo $this->e($form['title'] ?? ''); ?>" class="mn-input">
                </div>
            </div>
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo $this->t('common.content'); ?></label>
                <textarea name="content" id="content" data-editor="content" rows="15" class="mn-textarea" required><?php echo $this->e($form['content'] ?? ''); ?></textarea>
            </div>
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo $this->t('post.tags_label'); ?></label>
                <input type="text" name="tags" placeholder="<?php echo $this->t('post.tags_placeholder'); ?>" class="mn-input mn-maxw-400">
            </div>
            <div class="mn-flex-center mn-gap-14" style="flex-wrap:wrap;">
                <label class="mn-flex-center mn-gap-4 mn-fs-13 mn-text-secondary mn-cursor-pointer">
                    <input type="checkbox" name="reply_to_view" value="1" class="mn-input-auto">
                    <?php echo $this->icon('reply-lock', 13); ?> <?php echo $this->t('post.reply_to_view'); ?>
                </label>
                <label class="mn-btn mn-btn-sm mn-cursor-pointer">
                    <input type="file" name="attachments[]" multiple onchange="document.getElementById('file-name').textContent='<?php echo $this->t('post.select_file_prefix'); ?> '+Array.from(this.files).map(f=>f.name).join(', ')" style="display:none;">
                    <?php echo $this->icon('attachment', 12); ?> <?php echo $this->t('post.attach_files'); ?>
                </label>
                <span id="file-name" class="mn-fs-12 mn-text-muted" style="word-break:break-all;"></span>
            </div>
            <?php \app\Helpers\Plugin::hook('post_create_extra'); ?>
            <div class="mn-flex-center mn-gap-10 mn-mt-16">
                <button type="submit" class="mn-btn mn-btn-primary"><?php echo $this->icon('new-post', 12); ?> <?php echo $this->t('post.publish'); ?></button>
                <a href="<?php echo $this->url('/forum'); ?>" class="mn-btn"><?php echo $this->icon('forum', 12); ?> <?php echo $this->t('nav.back_to_forum'); ?></a>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
<div class="mn-section">
    <div class="mn-empty">
        <p><?php echo $this->t('post.no_category'); ?></p>
        <p class="mn-mt-8"><a href="<?php echo $this->url('/forum'); ?>" class="mn-btn mn-btn-primary"><?php echo $this->t('nav.back_to_forum'); ?></a></p>
    </div>
</div>
<?php endif; ?>
<?php $this->endSection(); ?>