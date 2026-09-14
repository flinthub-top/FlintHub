<?php
/**
 * 编辑回复视图 — 回复内容修改、附件管理
 * @file app/Views/post/edit.php
 */
$post = $this->getData('post', []);
$thread = $this->getData('thread', []);
$error = $this->getData('error');
?>
<?php $this->section('title'); ?><?php echo $this->t('post.edit_reply'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section">
    <div class="mn-section-header">
        <h2><?php echo $this->icon('edit', 16); ?> <?php echo $this->t('post.edit_reply'); ?></h2>
    </div>
    <div class="mn-p-20">
        <?php if ($error): ?>
        <div class="mn-alert mn-alert-error"><?php echo $this->e($error); ?></div>
        <?php endif; ?>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?php echo $this->e($this->getData('csrfToken')); ?>">
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo $this->t('common.content'); ?></label>
                <textarea name="content" id="content" data-editor="content" rows="15" class="mn-textarea" required><?php echo $this->e($this->decodeContent($post['content'] ?? '')); ?></textarea>
            </div>
            <div class="mn-mb-14">
                <label class="mn-btn mn-btn-sm mn-cursor-pointer">
                    <input type="file" name="reply_attachments[]" multiple onchange="document.getElementById('file-name').textContent='<?php echo $this->t('post.select_file_prefix'); ?> '+Array.from(this.files).map(f=>f.name).join(', ')" style="display:none;">
                    <?php echo $this->icon('attachment', 12); ?> <?php echo $this->t('post.attach_files'); ?>
                </label>
                <span id="file-name" class="mn-fs-12 mn-text-muted" style="margin-left:8px;"></span>
            </div>
            <?php $attachments = $this->getData('attachments', []); ?>
            <?php if (!empty($attachments)): ?>
            <div class="mn-mb-14">
                <?php foreach ($attachments as $att): ?>
                <div class="mn-flex-center mn-gap-8 mn-py-6 mn-fs-13" style="border-bottom:1px solid var(--mn-border-light,#f0f1f3);">
                    <input type="checkbox" name="delete_attachments[]" value="<?php echo (int)$att['id']; ?>">
                    <span class="mn-flex-1 mn-text-truncate"><?php echo $this->e($att['original_name']); ?></span>
                    <span class="mn-text-muted mn-fs-12">(<?php echo round((int)$att['file_size'] / 1024, 1); ?> KB)</span>
                    <a href="<?php echo $this->e(\UPLOAD_URL . $att['filename']); ?>" target="_blank" class="mn-text-secondary mn-fs-12" rel="noopener"><?php echo $this->t('common.download'); ?></a>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="mn-text-muted mn-fs-12 mn-mb-14"><?php echo $this->t('post.attachment_hint'); ?></div>
            <?php endif; ?>
            <div class="mn-flex-center mn-gap-10">
                <button type="submit" class="mn-btn mn-btn-primary"><?php echo $this->icon('save', 12); ?> <?php echo $this->t('common.save'); ?></button>
                <a href="<?php echo $this->url('/thread/' . (int)($thread['id'] ?? 0)); ?>" class="mn-btn"><?php echo $this->icon('back', 12); ?> <?php echo $this->t('post.back_to_thread'); ?></a>
            </div>
        </form>
    </div>
</div>
<?php $this->endSection(); ?>