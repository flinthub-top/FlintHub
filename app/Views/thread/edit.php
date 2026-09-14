<?php
/**
 * 编辑帖子视图 — 标题/内容/分类/标签/附件（作者或管理员）
 * @file app/Views/thread/edit.php
 */
$thread = $this->getData('thread', []);
$error = $this->getData('error');
$categories = $this->getData('categories', []);
$isAdmin = $this->getData('isAdmin');
?>
<?php $this->section('title'); ?><?php echo $this->t('thread.edit_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<style>
    /* 帖子标题预设色板：与后台 thread_edit.php 一致，6 个预设色（仅管理员可见） */
    .title-color-palette { display: inline-flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .title-color-palette label { display: inline-flex; align-items: center; gap: 4px; cursor: pointer; font-size: 13px; }
    .title-color-palette input[type="radio"] {
        appearance: none; -webkit-appearance: none;
        width: 20px; height: 20px; border-radius: 50%;
        border: 2px solid #d1d5db; cursor: pointer; padding: 0; margin: 0; vertical-align: middle;
    }
    .title-color-palette input[type="radio"]:checked {
        border-color: var(--mn-primary, #4f6ef7);
        box-shadow: 0 0 0 2px rgba(79, 110, 247, .2);
    }
</style>
<div class="mn-section">
    <div class="mn-section-header">
        <h2><?php echo $this->icon('edit', 16); ?> <?php echo $this->t('thread.edit_title'); ?></h2>
    </div>
    <div class="mn-p-20">
        <?php if ($error): ?>
        <div class="mn-alert mn-alert-error"><?php echo $this->e($error); ?></div>
        <?php endif; ?>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?php echo $this->e($this->getData('csrfToken')); ?>">
            <div class="mn-flex mn-gap-12" style="flex-wrap:wrap;">
                <?php if (!empty($categories)): ?>
                <div style="flex:1;min-width:180px;">
                    <label class="mn-label"><?php echo $this->t('common.category'); ?></label>
                    <select name="category_id" class="mn-select mn-w-full">
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo (int)$cat['id'] === (int)($thread['category_id'] ?? 0) ? 'selected' : ''; ?>><?php echo $this->e($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div style="flex:3;min-width:280px;">
                    <label class="mn-label"><?php echo $this->t('common.title'); ?></label>
                    <input type="text" name="title" required maxlength="<?php echo (int)\app\Helpers\Settings::get('limit_thread_title', '200'); ?>" value="<?php echo $this->e($thread['title'] ?? ''); ?>" class="mn-input">
                </div>
            </div>
            <?php if (!empty($isAdmin)): ?>
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo $this->t('thread.color'); ?></label>
                <span class="title-color-palette">
                    <?php
                    $presetColors = [
                        ''        => '#f0f3ff', // 无（默认，继承主题色）
                        '#dc2626' => '#dc2626', // 红
                        '#d97706' => '#d97706', // 橙
                        '#16a34a' => '#16a34a', // 绿
                        '#2563eb' => '#2563eb', // 蓝
                        '#7c3aed' => '#7c3aed', // 紫
                    ];
                    $curColor = $thread['color'] ?? '';
                    foreach ($presetColors as $val => $swatch):
                        $checked = ($curColor === $val) ? ' checked' : '';
                        $swatchBg = $val === '' ? 'linear-gradient(135deg,#f0f3ff 45%,#dc2626 46%,#dc2626 54%,#f0f3ff 55%)' : $swatch;
                    ?>
                    <label title="<?php echo $val === '' ? '无颜色' : $val; ?>">
                        <input type="radio" name="color" value="<?php echo $this->e($val); ?>"<?php echo $checked; ?> style="background:<?php echo $swatchBg; ?>;">
                    </label>
                    <?php endforeach; ?>
                </span>
            </div>
            <?php endif; ?>
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo $this->t('common.content'); ?></label>
                <textarea name="content" id="content" data-editor="content" rows="15" class="mn-textarea" required><?php echo $this->e($this->decodeContent($thread['content'] ?? '')); ?></textarea>
            </div>
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo $this->t('post.tags_label'); ?></label>
                <input type="text" name="tags" placeholder="<?php echo $this->t('post.tags_placeholder'); ?>" value="<?php echo $this->e($this->getData('tagsStr', '')); ?>" class="mn-input mn-maxw-400">
            </div>
            <div class="mn-flex-center mn-gap-14" style="flex-wrap:wrap;">
                <label class="mn-flex-center mn-gap-4 mn-fs-13 mn-text-secondary mn-cursor-pointer">
                    <input type="checkbox" name="reply_to_view" value="1" <?php echo !empty($thread['reply_to_view']) ? 'checked' : ''; ?>>
                    <?php echo $this->icon('reply-lock', 13); ?> <?php echo $this->t('post.reply_to_view'); ?>
                </label>
                <label class="mn-btn mn-btn-sm mn-cursor-pointer">
                    <input type="file" name="attachments[]" multiple onchange="document.getElementById('file-name').textContent='<?php echo $this->t('post.select_file_prefix'); ?> '+Array.from(this.files).map(f=>f.name).join(', ')" style="display:none;">
                    <?php echo $this->icon('attachment', 12); ?> <?php echo $this->t('post.attach_files'); ?>
                </label>
                <span id="file-name" class="mn-fs-12 mn-text-muted"></span>
            </div>
            <?php $attachments = $this->getData('attachments', []); ?>
            <?php if (!empty($attachments)): ?>
            <div class="mn-mt-12">
                <?php foreach ($attachments as $att): ?>
                <div class="mn-flex-center mn-gap-8 mn-py-6 mn-fs-13" style="border-bottom:1px solid var(--mn-border-light,#f0f1f3);">
                    <input type="checkbox" name="delete_attachments[]" value="<?php echo (int)$att['id']; ?>">
                    <span class="mn-flex-1 mn-text-truncate"><?php echo $this->e($att['original_name']); ?></span>
                    <span class="mn-text-muted mn-fs-12">(<?php echo round((int)$att['file_size'] / 1024, 1); ?> KB)</span>
                    <a href="<?php echo $this->e(\UPLOAD_URL . $att['filename']); ?>" target="_blank" class="mn-text-secondary mn-fs-12" rel="noopener"><?php echo $this->t('common.download'); ?></a>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="mn-text-muted mn-fs-12 mn-mt-8"><?php echo $this->t('post.attachment_hint'); ?></div>
            <?php endif; ?>
            <div class="mn-flex-center mn-gap-10 mn-mt-16">
                <button type="submit" class="mn-btn mn-btn-primary"><?php echo $this->icon('save', 12); ?> <?php echo $this->t('common.save'); ?></button>
                <a href="<?php echo $this->url('/thread/' . (int)($thread['id'] ?? 0)); ?>" class="mn-btn"><?php echo $this->icon('back', 12); ?> <?php echo $this->t('post.back_to_thread'); ?></a>
            </div>
        </form>
    </div>
</div>
<?php $this->endSection(); ?>