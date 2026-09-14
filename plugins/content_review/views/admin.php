<?php
/**
 * 内容审核后台管理视图 — 待审列表、通过/驳回、设置
 * @file app/Views/plugins/content_review/admin.php
 */
?>
<?php $this->section('title'); ?><?php echo \app\Helpers\I18n::get('plugin.content_review.title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar'); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('check', 18); ?> <?php echo \app\Helpers\I18n::get('plugin.content_review.title'); ?></h1>
        </div>

        <?php $error = $this->getData('error', ''); $success = $this->getData('success', ''); ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $this->e($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $this->e($success); ?></div>
        <?php endif; ?>

        <!-- 审核开关设置 -->
        <div class="admin-section">
            <h2><?php echo \app\Helpers\I18n::get('plugin.content_review.settings'); ?></h2>
            <form method="post" action="<?php echo $this->url('/admin/content-review/settings'); ?>" class="cr-settings-form">
                <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                <div class="cr-settings-row">
                    <label class="cr-toggle-label">
                        <input type="checkbox" name="review_thread" value="1" <?php echo ($this->getData('config')['review_thread'] ?? '1') === '1' ? 'checked' : ''; ?> class="checkbox-inline">
                        <span><?php echo \app\Helpers\I18n::get('plugin.content_review.review_thread'); ?></span>
                    </label>
                    <span class="cr-toggle-desc"><?php echo \app\Helpers\I18n::get('plugin.content_review.review_thread_desc'); ?></span>
                </div>
                <div class="cr-settings-row">
                    <label class="cr-toggle-label">
                        <input type="checkbox" name="review_post" value="1" <?php echo ($this->getData('config')['review_post'] ?? '1') === '1' ? 'checked' : ''; ?> class="checkbox-inline">
                        <span><?php echo \app\Helpers\I18n::get('plugin.content_review.review_post'); ?></span>
                    </label>
                    <span class="cr-toggle-desc"><?php echo \app\Helpers\I18n::get('plugin.content_review.review_post_desc'); ?></span>
                </div>
                <div class="cr-settings-row">
                    <label class="cr-toggle-label">
                        <input type="checkbox" name="review_avatar" value="1" <?php echo ($this->getData('config')['review_avatar'] ?? '0') === '1' ? 'checked' : ''; ?> class="checkbox-inline">
                        <span><?php echo \app\Helpers\I18n::get('plugin.content_review.review_avatar'); ?></span>
                    </label>
                    <span class="cr-toggle-desc"><?php echo \app\Helpers\I18n::get('plugin.content_review.review_avatar_desc'); ?></span>
                </div>
                <div class="cr-settings-row">
                    <label class="cr-toggle-label">
                        <input type="checkbox" name="review_register" value="1" <?php echo ($this->getData('config')['review_register'] ?? '0') === '1' ? 'checked' : ''; ?> class="checkbox-inline">
                        <span><?php echo \app\Helpers\I18n::get('plugin.content_review.review_register'); ?></span>
                    </label>
                    <span class="cr-toggle-desc"><?php echo \app\Helpers\I18n::get('plugin.content_review.review_register_desc'); ?></span>
                </div>

                <!-- 免审用户组（帖子和回复） -->
                <div class="cr-settings-divider"></div>
                <div class="cr-settings-subtitle"><?php echo \app\Helpers\I18n::get('plugin.content_review.exempt_groups_thread'); ?></div>
                <div class="cr-settings-desc"><?php echo \app\Helpers\I18n::get('plugin.content_review.exempt_groups_thread_desc'); ?></div>
                <div class="cr-groups-grid">
                <?php $groups = $this->getData('groups', []); ?>
                <?php foreach ($groups as $g): ?>
                    <label class="cr-group-checkbox <?php echo (int)$g['id'] === 4 ? 'cr-group-disabled' : ''; ?>">
                        <input type="checkbox" name="trusted_groups[]" value="<?php echo (int)$g['id']; ?>"
                            <?php echo !empty($g['is_exempt']) ? 'checked' : ''; ?>
                            <?php echo (int)$g['id'] === 4 ? 'disabled' : ''; ?>>
                        <span class="cr-group-badge" style="--cr-badge-bg:<?php echo $this->e($g['color'] ?? '#666'); ?>;"><?php echo $this->e($g['name']); ?></span>
                    </label>
                <?php endforeach; ?>
                </div>

                <!-- 免审用户组（头像） -->
                <div class="cr-settings-divider"></div>
                <div class="cr-settings-subtitle"><?php echo \app\Helpers\I18n::get('plugin.content_review.exempt_groups_avatar'); ?></div>
                <div class="cr-settings-desc"><?php echo \app\Helpers\I18n::get('plugin.content_review.exempt_groups_avatar_desc'); ?></div>
                <div class="cr-groups-grid">
                <?php $avatarGroups = $this->getData('avatarGroups', []); ?>
                <?php foreach ($avatarGroups as $g): ?>
                    <label class="cr-group-checkbox <?php echo (int)$g['id'] === 4 ? 'cr-group-disabled' : ''; ?>">
                        <input type="checkbox" name="trusted_groups_avatar[]" value="<?php echo (int)$g['id']; ?>"
                            <?php echo !empty($g['is_exempt']) ? 'checked' : ''; ?>
                            <?php echo (int)$g['id'] === 4 ? 'disabled' : ''; ?>>
                        <span class="cr-group-badge" style="--cr-badge-bg:<?php echo $this->e($g['color'] ?? '#666'); ?>;"><?php echo $this->e($g['name']); ?></span>
                    </label>
                <?php endforeach; ?>
                </div>

                <!-- 免审用户组（注册） -->
                <div class="cr-settings-divider"></div>
                <div class="cr-settings-subtitle"><?php echo \app\Helpers\I18n::get('plugin.content_review.exempt_groups_register'); ?></div>
                <div class="cr-settings-desc"><?php echo \app\Helpers\I18n::get('plugin.content_review.exempt_groups_register_desc'); ?></div>
                <div class="cr-groups-grid">
                <?php $registerGroups = $this->getData('registerGroups', []); ?>
                <?php foreach ($registerGroups as $g): ?>
                    <label class="cr-group-checkbox <?php echo (int)$g['id'] === 4 ? 'cr-group-disabled' : ''; ?>">
                        <input type="checkbox" name="trusted_groups_register[]" value="<?php echo (int)$g['id']; ?>"
                            <?php echo !empty($g['is_exempt']) ? 'checked' : ''; ?>
                            <?php echo (int)$g['id'] === 4 ? 'disabled' : ''; ?>>
                        <span class="cr-group-badge" style="--cr-badge-bg:<?php echo $this->e($g['color'] ?? '#666'); ?>;"><?php echo $this->e($g['name']); ?></span>
                    </label>
                <?php endforeach; ?>
                </div>

                <!-- 分版块审核（帖子） -->
                <div class="cr-settings-divider"></div>
                <div class="cr-settings-subtitle"><?php echo \app\Helpers\I18n::get('plugin.content_review.review_categories'); ?></div>
                <div class="cr-settings-desc"><?php echo \app\Helpers\I18n::get('plugin.content_review.review_categories_desc'); ?></div>
                <div class="cr-groups-grid">
                <?php $categories = $this->getData('categories', []); $config = $this->getData('config', []); $reviewCats = $config['review_categories'] ?? []; ?>
                <?php foreach ($categories as $cat): ?>
                    <label class="cr-group-checkbox">
                        <input type="checkbox" name="review_categories[]" value="<?php echo (int)$cat['id']; ?>"
                            <?php echo in_array((string)(int)$cat['id'], $reviewCats) ? 'checked' : ''; ?>>
                        <span class="cr-group-badge"><?php echo $this->e($cat['name']); ?></span>
                    </label>
                <?php endforeach; ?>
                </div>

                <div class="mt-10">
                    <button type="submit" class="btn btn-primary btn-sm"><?php echo \app\Helpers\I18n::get('plugin.content_review.save'); ?></button>
                </div>
            </form>
        </div>

        <!-- 待审核列表 -->
        <?php $pending = $this->getData('pending', []); ?>
        <div class="admin-section">
            <h2><?php echo \app\Helpers\I18n::get('plugin.content_review.pending', ['count' => count($pending)]); ?></h2>
            <?php if (empty($pending)): ?>
                <div class="empty-state"><p><?php echo \app\Helpers\I18n::get('plugin.content_review.no_pending'); ?></p></div>
            <?php else: ?>
            <form method="post" action="<?php echo $this->url('/admin/content-review/batch-approve'); ?>" id="pendingForm" class="cr-inline-form" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('plugin.content_review.batch_approve_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                <div class="mb-10">
                    <label class="mn-fs-13 mn-cursor-pointer">
                        <input type="checkbox" id="checkAllPending"
                           x-data
                           x-on:change="document.querySelectorAll('#pendingForm input[name=\'ids[]\']').forEach(c => c.checked = $el.checked)">
                        <span class="mn-ml-4"><?php echo \app\Helpers\I18n::get('plugin.content_review.select_all'); ?></span>
                    </label>
                    <button type="submit" class="btn btn-sm btn-success mn-ml-8"><?php echo \app\Helpers\I18n::get('plugin.content_review.batch_approve'); ?></button>
                    <button type="button" class="btn btn-sm btn-warning" x-data x-on:click="batchReject()"><?php echo \app\Helpers\I18n::get('plugin.content_review.batch_reject'); ?></button>
                    <input type="hidden" name="batch_reject" id="batchRejectInput" value="">
                </div>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="cr-w-30"></th>
                        <th class="cr-nowrap cr-w-70"><?php echo \app\Helpers\I18n::get('plugin.content_review.th_type'); ?></th>
                        <th><?php echo \app\Helpers\I18n::get('plugin.content_review.th_summary'); ?></th>
                        <th class="cr-w-90"><?php echo \app\Helpers\I18n::get('plugin.content_review.th_submitter'); ?></th>
                        <th class="cr-w-130"><?php echo \app\Helpers\I18n::get('plugin.content_review.th_time'); ?></th>
                        <th class="cr-w-215"><?php echo \app\Helpers\I18n::get('plugin.content_review.th_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $item):
                    $typeLabel = ['thread' => \app\Helpers\I18n::get('plugin.content_review.type_thread'), 'post' => \app\Helpers\I18n::get('plugin.content_review.type_post'), 'avatar' => \app\Helpers\I18n::get('plugin.content_review.type_avatar'), 'register' => \app\Helpers\I18n::get('plugin.content_review.type_register')];
                    $label = $typeLabel[$item['target_type']] ?? $item['target_type'];
                    $preview = \Plugin\ContentReview\Plugin::getContentPreview($item['target_type'], (int)$item['target_id']);
                    $viewUrl = '';
                    if ($item['target_type'] === 'thread') {
                        $viewUrl = '/thread/' . (int)$item['target_id'];
                    } elseif ($item['target_type'] === 'post') {
                        $viewUrl = \Plugin\ContentReview\Plugin::getPostThreadUrl((int)$item['target_id']);
                    }
                ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?php echo (int)$item['id']; ?>"></td>
                        <td class="cr-nowrap"><span class="badge badge-warning"><?php echo $this->e($label); ?></span></td>
                        <td>
                            <div class="cr-preview-text"><?php echo $this->e($preview); ?></div>
                            <?php if ($viewUrl): ?>
                            <a href="<?php echo $viewUrl; ?>" target="_blank" class="cr-view-link"><?php echo $this->icon('eye', 12); ?> <?php echo \app\Helpers\I18n::get('plugin.content_review.view_full'); ?></a>
                            <?php endif; ?>
                        </td>
                        <td><small><?php echo $this->e($item['username'] ?? '-'); ?></small></td>
                        <td><small class="c-999"><?php echo $this->e(date('Y-m-d H:i', strtotime($item['created_at'] ?? 'now'))); ?></small></td>
                        <td class="cr-actions cr-nowrap">
                            <button type="button" class="btn btn-sm btn-success" data-action="approve" data-id="<?php echo (int)$item['id']; ?>">
                                <?php echo $this->icon('check', 12) ?? '✓'; ?> <?php echo \app\Helpers\I18n::get('plugin.content_review.approve'); ?>
                            </button>
                            <button type="button" class="btn btn-sm btn-warning" data-action="reject" data-id="<?php echo (int)$item['id']; ?>">
                                <?php echo $this->icon('x', 12) ?? '✕'; ?> <?php echo \app\Helpers\I18n::get('plugin.content_review.reject'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </form>
            <?php endif; ?>
        </div>

        <!-- 驳回弹窗（Alpine.js 控制显隐） -->
        <div class="cr-modal-overlay" id="rejectModal"
             x-data="{ open: false }"
             x-show="open"
             x-on:keydown.escape.window="open = false"
             x-on:click="open = false"
             x-cloak>
            <div class="cr-modal" x-on:click.stop>
                <div class="cr-modal-header">
                    <h2><?php echo \app\Helpers\I18n::get('plugin.content_review.reject_title'); ?></h2>
                    <button type="button" class="cr-modal-close" x-on:click="open = false">&times;</button>
                </div>
                <form method="post" action="<?php echo $this->url('/admin/content-review/reject'); ?>">
                    <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                    <input type="hidden" name="id" id="reject_id" value="">
                    <div class="cr-modal-body">
                        <textarea name="reason" id="reject_reason" rows="3" class="form-input" placeholder="<?php echo \app\Helpers\I18n::get('plugin.content_review.reject_ph'); ?>"></textarea>
                    </div>
                    <div class="cr-modal-footer">
                        <button type="button" class="btn btn-secondary" x-on:click="open = false"><?php echo \app\Helpers\I18n::get('plugin.content_review.cancel'); ?></button>
                        <button type="submit" class="btn btn-warning"><?php echo \app\Helpers\I18n::get('plugin.content_review.confirm_reject'); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 已审记录 -->
        <?php $reviewed = $this->getData('reviewed', []); $total = $this->getData('total', 0); ?>
        <?php if ($total > 0): ?>
        <div class="admin-section">
            <h2><?php echo \app\Helpers\I18n::get('plugin.content_review.reviewed', ['count' => (int)$total]); ?></h2>

            <!-- 批量删除表单（包裹表格，以便提交 checkboxes） -->
            <form method="post" action="<?php echo $this->url('/admin/content-review/batch-delete'); ?>" id="batchForm" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('plugin.content_review.batch_delete_confirm', ['count' => '__N__'])), ENT_QUOTES, 'UTF-8'); ?>.replace('__N__', document.querySelectorAll('#batchForm input[name=\'ids[]\']:checked').length))) return; $el.submit()">
                <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                <div class="mb-10">
                    <label class="mn-fs-13 mn-cursor-pointer">
                        <input type="checkbox" id="checkAll"
                           x-data
                           x-on:change="document.querySelectorAll('#batchForm input[name=\'ids[]\']').forEach(c => c.checked = $el.checked)">
                        <span class="mn-ml-4"><?php echo \app\Helpers\I18n::get('plugin.content_review.select_all'); ?></span>
                    </label>
                    <button type="submit" class="btn btn-sm btn-warning mn-ml-8"><?php echo \app\Helpers\I18n::get('plugin.content_review.batch_delete'); ?></button>
                </div>

            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="cr-w-30"></th>
                        <th class="cr-nowrap cr-w-60"><?php echo \app\Helpers\I18n::get('plugin.content_review.th_type'); ?></th>
                        <th><?php echo \app\Helpers\I18n::get('plugin.content_review.th_summary'); ?></th>
                        <th class="cr-w-80"><?php echo \app\Helpers\I18n::get('plugin.content_review.th_submitter'); ?></th>
                        <th class="cr-nowrap cr-w-70"><?php echo \app\Helpers\I18n::get('plugin.content_review.th_result'); ?></th>
                        <th class="cr-w-80"><?php echo \app\Helpers\I18n::get('plugin.content_review.th_reviewer'); ?></th>
                        <th class="cr-w-140"><?php echo \app\Helpers\I18n::get('plugin.content_review.th_reviewed_at'); ?></th>
                        <th class="cr-w-60"><?php echo \app\Helpers\I18n::get('plugin.content_review.th_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($reviewed as $item):
                    $typeLabel = ['thread' => \app\Helpers\I18n::get('plugin.content_review.type_thread'), 'post' => \app\Helpers\I18n::get('plugin.content_review.type_post'), 'avatar' => \app\Helpers\I18n::get('plugin.content_review.type_avatar'), 'register' => \app\Helpers\I18n::get('plugin.content_review.type_register')];
                    $label = $typeLabel[$item['target_type']] ?? $item['target_type'];
                    $preview = \Plugin\ContentReview\Plugin::getContentPreview($item['target_type'], (int)$item['target_id']);
                ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?php echo (int)$item['id']; ?>"></td>
                        <td class="cr-nowrap"><span class="badge badge-default"><?php echo $this->e($label); ?></span></td>
                        <td><small><?php echo $this->e($preview); ?></small></td>
                        <td><small><?php echo $this->e($item['username'] ?? '-'); ?></small></td>
                        <td>
                            <?php if ($item['status'] === 'approved'): ?>
                            <span class="badge badge-success"><?php echo \app\Helpers\I18n::get('plugin.content_review.result_approved'); ?></span>
                            <?php else: ?>
                            <span class="badge badge-danger"><?php echo \app\Helpers\I18n::get('plugin.content_review.result_rejected'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><small><?php echo $this->e($item['reviewer_name'] ?? '-'); ?></small></td>
                        <td><small class="c-999"><?php echo $this->e(date('Y-m-d H:i', strtotime($item['reviewed_at'] ?? ''))); ?></small></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-warning cr-btn-delete" data-action="delete" data-id="<?php echo (int)$item['id']; ?>">
                                <?php echo $this->icon('trash', 12) ?? '🗑'; ?> <?php echo \app\Helpers\I18n::get('plugin.content_review.delete'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </form>

            <?php $totalPages = $this->getData('totalPages', 1); $page = $this->getData('page', 1); ?>
            <?php echo $this->pagination($page, $totalPages, '/admin/content-review?page={page}', ['style' => 'admin', 'class' => 'mt-15']); ?>
        </div>
        <?php endif; ?>
    </main>
</div>

<link rel="stylesheet" href="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/content_review/assets/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/style.css') ?: 1; ?>">
<script src="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/content_review/assets/script.js?v=<?php echo @filemtime(__DIR__ . '/../assets/script.js') ?: 1; ?>"></script>
<?php $this->endSection(); ?>
