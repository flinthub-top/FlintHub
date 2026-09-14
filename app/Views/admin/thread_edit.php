<?php
/**
 * 后台编辑帖子视图 — 帖子内容修改、分类变更
 * @file app/Views/admin/thread_edit.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('thread.edit_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<style>
    /* 帖子标题预设色板：6 个预设色 */
    .title-color-palette { display: inline-flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .title-color-palette label { display: inline-flex; align-items: center; gap: 4px; cursor: pointer; font-size: 13px; color: #666; }
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
<?php
$thread = $this->getData('thread');
$categories = $this->getData('categories', []);
$csrfToken = $this->e($this->getData('csrfToken'));
?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'threads']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->t('thread.edit_title'); ?></h1>
        </div>
        <div class="admin-section admin-form-lg">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <div class="form-group">
                    <label><?php echo $this->t('common.title'); ?></label>
                    <input type="text" name="title" value="<?php echo $this->e($thread['title'] ?? ''); ?>" required class="form-input">
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('common.category'); ?></label>
                    <select name="category_id" class="form-input">
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo (int)($thread['category_id'] ?? 0) === (int)$cat['id'] ? 'selected' : ''; ?>><?php echo $this->e($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('common.content'); ?></label>
                    <textarea name="content" id="content" data-editor="content" rows="10" class="form-input"><?php echo $this->e($this->decodeContent($thread['content'] ?? '')); ?></textarea>
                </div>
                <div class="mn-flex mn-gap-15 mn-mb-15">
                    <label class="mn-fs-13 c-666 mn-cursor-pointer">
                        <input type="checkbox" name="is_pinned" value="1" <?php echo !empty($thread['is_pinned']) ? 'checked' : ''; ?> class="checkbox-inline">
                        <?php echo $this->icon('pinned', 14); ?> <?php echo $this->t('common.pinned'); ?>
                    </label>
                    <label class="mn-fs-13 c-666 mn-cursor-pointer">
                        <input type="checkbox" name="is_highlighted" value="1" <?php echo !empty($thread['is_highlighted']) ? 'checked' : ''; ?> class="checkbox-inline">
                        <?php echo $this->icon('highlight', 14); ?> <?php echo $this->t('forum.highlighted'); ?>
                    </label>
                    <div class="mn-fs-13 c-666">
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
                                $checked = ($curColor === $val) ? 'checked' : '';
                                $swatchBg = $val === '' ? 'linear-gradient(135deg,#f0f3ff 45%,#dc2626 46%,#dc2626 54%,#f0f3ff 55%)' : $swatch;
                            ?>
                            <label title="<?php echo $val === '' ? '无颜色' : $val; ?>">
                                <input type="radio" name="color" value="<?php echo $this->e($val); ?>" <?php echo $checked; ?> style="background:<?php echo $swatchBg; ?>;">
                            </label>
                            <?php endforeach; ?>
                        </span>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><?php echo $this->t('common.save'); ?></button>
                <a href="<?php echo $this->url('/admin/threads'); ?>" class="btn btn-secondary"><?php echo $this->t('common.cancel'); ?></a>
            </form>
        </div>
    </main>
</div>
<?php $this->endSection(); ?>