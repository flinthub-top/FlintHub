<?php
/**
 * 公告管理后台视图 — 添加/编辑/删除公告
 * @file app/Views/plugins/announcements/admin.php
 */
?>
<?php $this->section('title'); ?><?php echo \app\Helpers\I18n::get('plugin.announcements.title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('css'); ?>
<link rel="stylesheet" href="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/announcements/assets/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/style.css') ?: 1; ?>">
<?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar'); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1>📢 <?php echo \app\Helpers\I18n::get('plugin.announcements.title'); ?></h1>
        </div>

        <?php $error = $this->getData('error', ''); $success = $this->getData('success', ''); ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo $this->e($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo $this->e($success); ?></div><?php endif; ?>

        <div class="admin-section">
            <h2><?php echo \app\Helpers\I18n::get('plugin.announcements.add'); ?></h2>
            <form method="post" action="<?php echo $this->url('/admin/announcements/add'); ?>">
                <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                <div class="form-group">
                    <label><?php echo \app\Helpers\I18n::get('plugin.announcements.content'); ?> <span class="c-danger">*</span></label>
                    <textarea name="content" rows="3" class="form-input" required placeholder="<?php echo \app\Helpers\I18n::get('plugin.announcements.content_ph'); ?>"></textarea>
                </div>
                <div class="form-group">
                    <label><?php echo \app\Helpers\I18n::get('plugin.announcements.url'); ?></label>
                    <input type="url" name="url" class="form-input" placeholder="<?php echo \app\Helpers\I18n::get('plugin.announcements.url_ph'); ?>">
                </div>
                <div class="mn-flex mn-gap-12 mn-mb-12">
                    <div class="form-group ann-w-160">
                        <label><?php echo \app\Helpers\I18n::get('plugin.announcements.style'); ?></label>
                        <select name="style" class="form-input">
                            <?php foreach ($this->getData('styles', []) as $k => $v): ?>
                            <option value="<?php echo $this->e($k); ?>"><?php echo $this->e($v['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group ann-w-120">
                        <label><?php echo \app\Helpers\I18n::get('plugin.announcements.sort'); ?></label>
                        <input type="number" name="sort_order" value="0" class="form-input ann-w-100">
                    </div>
                    <div class="form-group ann-pt-24">
                        <button type="submit" class="btn btn-primary"><?php echo \app\Helpers\I18n::get('plugin.announcements.add_btn'); ?></button>
                    </div>
                </div>
            </form>
        </div>

        <div class="admin-section">
            <h2><?php echo \app\Helpers\I18n::get('plugin.announcements.display_mode'); ?></h2>
            <p class="c-999 mn-fs-12 ann-mb-10"><?php echo \app\Helpers\I18n::get('plugin.announcements.mode_hint'); ?></p>
            <form method="post" action="<?php echo $this->url('/admin/announcements/set-mode'); ?>" class="ann-flex-center-wrap">
                <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                <select name="mode" class="form-input ann-w-140">
                    <option value="normal"<?php echo $this->getData('mode') === 'normal' ? ' selected' : ''; ?>><?php echo \app\Helpers\I18n::get('plugin.announcements.mode_normal'); ?></option>
                    <option value="scroll"<?php echo $this->getData('mode') === 'scroll' ? ' selected' : ''; ?>><?php echo \app\Helpers\I18n::get('plugin.announcements.mode_scroll'); ?></option>
                </select>
                <button type="submit" class="btn btn-secondary btn-sm"><?php echo \app\Helpers\I18n::get('plugin.announcements.save'); ?></button>
            </form>
        </div>

        <div class="admin-section">
            <h2><?php echo \app\Helpers\I18n::get('plugin.announcements.existing', ['count' => count($this->getData('announcements', []))]); ?></h2>
            <?php $list = $this->getData('announcements', []); $styles = $this->getData('styles', []); ?>
            <?php if (empty($list)): ?>
                <div class="empty-state"><p><?php echo \app\Helpers\I18n::get('plugin.announcements.empty'); ?></p></div>
            <?php else: ?>
            <div class="ann-flex-col">
                <?php foreach ($list as $ann):
                    $s = $styles[$ann['style']] ?? $styles['yellow'];
                    $preview = mb_substr(strip_tags(\app\Helpers\Content::decode($ann['content'])), 0, 80); ?>
                <div class="announcement-list-item ann-style-<?php echo isset($styles[$ann['style']]) ? $ann['style'] : 'yellow'; ?>">
                    <span class="ann-admin-content ann-content-flex"><?php echo $this->e($preview); ?><?php if (mb_strlen($preview) >= 80): ?>…<?php endif; ?></span>
                    <span class="ann-admin-actions">
                        <span class="ann-label-sub">[<?php echo $this->e($s['label']); ?>]</span>
                        <button type="button" class="btn btn-sm btn-secondary ann-edit-btn" data-ann='<?php echo htmlspecialchars(json_encode($ann, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>'><?php echo \app\Helpers\I18n::get('plugin.announcements.edit'); ?></button>
                        <form method="post" action="<?php echo $this->url('/admin/announcements/delete'); ?>" class="ann-shrink-0" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('plugin.announcements.delete_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                            <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                            <input type="hidden" name="id" value="<?php echo (int)$ann['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-warning"><?php echo \app\Helpers\I18n::get('plugin.announcements.delete'); ?></button>
                        </form>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- 编辑弹窗（Alpine.js 控制显隐） -->
<div class="fl-modal-overlay" id="editModal"
     x-data="{ open: false }"
     x-show="open"
     x-on:keydown.escape.window="open = false"
     x-on:click="open = false"
     x-cloak>
    <div class="fl-modal" x-on:click.stop>
        <div class="fl-modal-header">
            <h2><?php echo \app\Helpers\I18n::get('plugin.announcements.edit_title'); ?></h2>
            <button type="button" class="fl-modal-close" x-on:click="open = false">&times;</button>
        </div>
        <form method="post" action="<?php echo $this->url('/admin/announcements/edit'); ?>">
            <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
            <input type="hidden" name="id" id="edit_id" value="">
            <div class="fl-modal-body">
                <div class="fl-modal-field">
                    <label><?php echo \app\Helpers\I18n::get('plugin.announcements.content'); ?> <span class="c-danger">*</span></label>
                    <textarea name="content" id="edit_content" rows="3" class="form-input" required></textarea>
                </div>
                <div class="fl-modal-field">
                    <label><?php echo \app\Helpers\I18n::get('plugin.announcements.url'); ?></label>
                    <input type="url" name="url" id="edit_url" class="form-input" placeholder="https://example.com">
                </div>
                <div class="fl-modal-row">
                    <div class="fl-modal-field fl-modal-field-sm">
                        <label><?php echo \app\Helpers\I18n::get('plugin.announcements.style'); ?></label>
                        <select name="style" id="edit_style" class="form-input">
                            <?php foreach ($styles as $k => $v): ?>
                            <option value="<?php echo $this->e($k); ?>"><?php echo $this->e($v['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fl-modal-field fl-modal-field-sm">
                        <label><?php echo \app\Helpers\I18n::get('plugin.announcements.sort'); ?></label>
                        <input type="number" name="sort_order" id="edit_sort" value="0" class="form-input">
                    </div>
                </div>
            </div>
            <div class="fl-modal-footer">
                <button type="button" class="btn btn-secondary" x-on:click="open = false"><?php echo \app\Helpers\I18n::get('plugin.announcements.cancel'); ?></button>
                <button type="submit" class="btn btn-primary"><?php echo \app\Helpers\I18n::get('plugin.announcements.save'); ?></button>
            </div>
        </form>
    </div>
</div>

<script src="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/announcements/assets/script.js?v=<?php echo @filemtime(__DIR__ . '/../assets/script.js') ?: 1; ?>"></script>
<?php $this->endSection(); ?>
