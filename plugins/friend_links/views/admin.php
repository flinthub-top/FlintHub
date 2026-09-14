<?php
/**
 * 友情链接插件后台管理视图 — 友链增删改、排序、审核
 * @file app/Views/plugins/friend_links/admin.php
 */
?>
<?php $this->section('title'); ?><?php echo \app\Helpers\I18n::get('plugin.friend_links.title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar'); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo \app\Helpers\I18n::get('plugin.friend_links.title'); ?></h1>
        </div>

        <?php $error = $this->getData('error', ''); $success = $this->getData('success', ''); ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $this->e($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $this->e($success); ?></div>
        <?php endif; ?>

        <!-- 申请开关 -->
        <div class="admin-section">
            <form method="post" action="<?php echo $this->url('/admin/friend-links/toggle-apply'); ?>" class="fl-flex-center-gap">
                <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                <label class="fl-label-check">
                    <input type="checkbox" name="apply_enabled" value="1" x-data x-on:change="$el.form.submit()" <?php echo $this->getData('applyEnabled') ? 'checked' : ''; ?> class="checkbox-inline checkbox-lg">
                    <?php echo \app\Helpers\I18n::get('plugin.friend_links.apply_enable'); ?>
                </label>
                <span class="mn-fs-13 c-999"><?php echo \app\Helpers\I18n::get('plugin.friend_links.apply_hint'); ?></span>
            </form>
        </div>

        <!-- 待审核列表 -->
        <?php $pending = $this->getData('pending', []); if (!empty($pending) && $this->getData('applyEnabled')): ?>
        <div class="admin-section">
            <h2><?php echo \app\Helpers\I18n::get('plugin.friend_links.pending', ['count' => count($pending)]); ?></h2>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th><?php echo \app\Helpers\I18n::get('plugin.friend_links.th_name'); ?></th>
                        <th><?php echo \app\Helpers\I18n::get('plugin.friend_links.th_url'); ?></th>
                        <th><?php echo \app\Helpers\I18n::get('plugin.friend_links.th_applicant'); ?></th>
                        <th><?php echo \app\Helpers\I18n::get('plugin.friend_links.th_time'); ?></th>
                        <th class="fl-w-160"><?php echo \app\Helpers\I18n::get('plugin.friend_links.th_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $link): ?>
                    <tr>
                        <td><strong><?php echo $this->e($link['name']); ?></strong></td>
                        <td><a href="<?php echo $this->e($link['url']); ?>" target="_blank" class="link-primary fl-fs-12"><?php echo $this->e(mb_substr($link['url'], 0, 40)); ?></a></td>
                        <td><small><?php echo $this->e($link['applicant_name'] ?? \app\Helpers\I18n::get('plugin.friend_links.guest')); ?></small></td>
                        <td><small class="c-999"><?php echo $this->e(date('Y-m-d H:i', strtotime($link['applied_at'] ?? 'now'))); ?></small></td>
                        <td>
                            <form method="post" action="<?php echo $this->url('/admin/friend-links/approve'); ?>" class="fl-inline">
                                <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                                <input type="hidden" name="id" value="<?php echo (int)$link['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-primary"><?php echo \app\Helpers\I18n::get('plugin.friend_links.approve'); ?></button>
                            </form>
                            <form method="post" action="<?php echo $this->url('/admin/friend-links/reject'); ?>" class="fl-inline" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('plugin.friend_links.reject_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                                <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                                <input type="hidden" name="id" value="<?php echo (int)$link['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-warning"><?php echo \app\Helpers\I18n::get('plugin.friend_links.reject'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- 添加链接 -->
        <div class="admin-section">
            <h2><?php echo \app\Helpers\I18n::get('plugin.friend_links.add'); ?></h2>
            <form method="post" action="<?php echo $this->url('/admin/friend-links/add'); ?>" class="fl-form">
                <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                <div class="fl-form-row">
                    <div class="form-group mn-flex-1">
                        <label><?php echo \app\Helpers\I18n::get('plugin.friend_links.name'); ?> <span class="c-danger">*</span></label>
                        <input type="text" name="name" required class="form-input" placeholder="<?php echo \app\Helpers\I18n::get('plugin.friend_links.name_ph'); ?>">
                    </div>
                    <div class="form-group mn-flex-1">
                        <label><?php echo \app\Helpers\I18n::get('plugin.friend_links.url'); ?> <span class="c-danger">*</span></label>
                        <input type="url" name="url" required class="form-input" placeholder="<?php echo \app\Helpers\I18n::get('plugin.friend_links.url_ph'); ?>">
                    </div>
                </div>
                <div class="fl-form-row">
                    <div class="form-group mn-flex-1">
                        <label><?php echo \app\Helpers\I18n::get('plugin.friend_links.desc'); ?></label>
                        <input type="text" name="description" class="form-input" placeholder="<?php echo \app\Helpers\I18n::get('plugin.friend_links.desc_ph'); ?>">
                    </div>
                    <div class="form-group mn-flex-1">
                        <label><?php echo \app\Helpers\I18n::get('plugin.friend_links.logo'); ?></label>
                        <input type="url" name="logo" class="form-input" placeholder="<?php echo \app\Helpers\I18n::get('plugin.friend_links.logo_ph'); ?>">
                    </div>
                </div>
                <div class="fl-form-row">
                    <div class="form-group fl-w-120">
                        <label><?php echo \app\Helpers\I18n::get('plugin.friend_links.sort'); ?></label>
                        <input type="number" name="sort_order" value="0" class="form-input fl-w-100">
                    </div>
                    <div class="form-group fl-pt-24">
                        <button type="submit" class="btn btn-primary"><?php echo \app\Helpers\I18n::get('plugin.friend_links.add_btn'); ?></button>
                    </div>
                </div>
            </form>
        </div>

        <!-- 链接列表 -->
        <div class="admin-section">
            <h2><?php echo \app\Helpers\I18n::get('plugin.friend_links.list', ['count' => count($this->getData('links', []))]); ?></h2>
            <?php $links = $this->getData('links', []); ?>
            <?php if (empty($links)): ?>
                <div class="empty-state"><p><?php echo \app\Helpers\I18n::get('plugin.friend_links.empty'); ?></p></div>
            <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="fl-w-70"><?php echo \app\Helpers\I18n::get('plugin.friend_links.th_sort'); ?></th>
                        <th><?php echo \app\Helpers\I18n::get('plugin.friend_links.th_name'); ?></th>
                        <th><?php echo \app\Helpers\I18n::get('plugin.friend_links.th_url'); ?></th>
                        <th><?php echo \app\Helpers\I18n::get('plugin.friend_links.desc'); ?></th>
                        <th class="fl-w-80"><?php echo \app\Helpers\I18n::get('plugin.friend_links.th_visible'); ?></th>
                        <th class="fl-w-170"><?php echo \app\Helpers\I18n::get('plugin.friend_links.th_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($links as $link): ?>
                    <tr>
                        <td class="text-center"><?php echo (int)$link['sort_order']; ?></td>
                        <td>
                            <strong><?php echo $this->e($link['name']); ?></strong>
                            <?php if (!empty($link['logo'])): ?>
                            <br><small class="c-999">logo: <?php echo $this->e(mb_substr($link['logo'], 0, 30)); ?>...</small>
                            <?php endif; ?>
                        </td>
                        <td><a href="<?php echo $this->e($link['url']); ?>" target="_blank" class="link-primary fl-fs-12"><?php echo $this->e(mb_substr($link['url'], 0, 40)); ?></a></td>
                        <td><small class="c-666"><?php echo $this->e($link['description'] ?? ''); ?></small></td>
                        <td>
                            <?php if (!empty($link['is_visible'])): ?>
                            <span class="badge badge-success"><?php echo \app\Helpers\I18n::get('plugin.friend_links.show'); ?></span>
                            <?php else: ?>
                            <span class="badge badge-default"><?php echo \app\Helpers\I18n::get('plugin.friend_links.hide'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-secondary fl-edit-btn" data-link='<?php echo htmlspecialchars(json_encode($link, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>'><?php echo \app\Helpers\I18n::get('plugin.friend_links.edit'); ?></button>
                            <form method="post" action="<?php echo $this->url('/admin/friend-links/delete'); ?>" class="fl-inline" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('plugin.friend_links.delete_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                                <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                                <input type="hidden" name="id" value="<?php echo (int)$link['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-warning"><?php echo \app\Helpers\I18n::get('plugin.friend_links.delete'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
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
            <h2><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> <?php echo \app\Helpers\I18n::get('plugin.friend_links.edit_title'); ?></h2>
            <button type="button" class="fl-modal-close" x-on:click="open = false">&times;</button>
        </div>
        <form method="post" action="<?php echo $this->url('/admin/friend-links/edit'); ?>">
            <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
            <input type="hidden" name="id" id="edit_id" value="">
            <div class="fl-modal-body">
                <div class="fl-modal-row">
                    <div class="fl-modal-field">
                        <label><?php echo \app\Helpers\I18n::get('plugin.friend_links.name'); ?> <span class="c-danger">*</span></label>
                        <input type="text" name="name" id="edit_name" required class="form-input" placeholder="输入网站名称">
                    </div>
                    <div class="fl-modal-field">
                        <label><?php echo \app\Helpers\I18n::get('plugin.friend_links.url'); ?> <span class="c-danger">*</span></label>
                        <input type="url" name="url" id="edit_url" required class="form-input" placeholder="https://example.com">
                    </div>
                </div>
                <div class="fl-modal-field">
                    <label><?php echo \app\Helpers\I18n::get('plugin.friend_links.desc'); ?></label>
                    <input type="text" name="description" id="edit_desc" class="form-input" placeholder="简短描述（可选）">
                </div>
                <div class="fl-modal-field">
                    <label><?php echo \app\Helpers\I18n::get('plugin.friend_links.logo'); ?></label>
                    <input type="url" name="logo" id="edit_logo" class="form-input" placeholder="https://example.com/logo.png（可选）">
                </div>
                <div class="fl-modal-row">
                    <div class="fl-modal-field fl-modal-field-sm">
                        <label><?php echo \app\Helpers\I18n::get('plugin.friend_links.sort'); ?></label>
                        <input type="number" name="sort_order" id="edit_sort" value="0" class="form-input" placeholder="0">
                    </div>
                    <div class="fl-modal-field fl-modal-field-sm">
                        <label><?php echo \app\Helpers\I18n::get('plugin.friend_links.th_visible'); ?></label>
                        <select name="is_visible" id="edit_visible" class="form-input">
                            <option value="1"><?php echo \app\Helpers\I18n::get('plugin.friend_links.show'); ?></option>
                            <option value="0"><?php echo \app\Helpers\I18n::get('plugin.friend_links.hide'); ?></option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="fl-modal-footer">
                <button type="button" class="btn btn-secondary" x-on:click="open = false"><?php echo \app\Helpers\I18n::get('plugin.friend_links.cancel'); ?></button>
                <button type="submit" class="btn btn-primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="fl-check-icon"><polyline points="20 6 9 17 4 12"/></svg>
                    保存
                </button>
            </div>
        </form>
    </div>
</div>

<link rel="stylesheet" href="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/friend_links/assets/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/style.css') ?: 1; ?>">
<script src="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/friend_links/assets/script.js?v=<?php echo @filemtime(__DIR__ . '/../assets/script.js') ?: 1; ?>"></script>
<?php $this->endSection(); ?>
