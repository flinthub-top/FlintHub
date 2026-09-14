<?php
/**
 * 后台搜索设置视图
 * @file app/Views/admin/search_settings.php
 */
$settings = $this->getData('settings', []);
$csrfToken = $this->e($this->getData('csrfToken'));
$msg = $this->getData('msg', '');
$error = $this->getData('error', '');
$rebuildProgress = $this->getData('rebuild_progress', '');
$rebuildNext = $this->getData('rebuild_next', '');
$perPage = (int)($this->getData('per_page', 10));
$totalPages = (int)($this->getData('total_pages', 1));
$fullContent = !empty($settings['search_index_content']) && $settings['search_index_content'] === '1';
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.nav_search_settings'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'settings_search']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('search', 16); ?> <?php echo $this->t('admin.nav_search_settings'); ?></h1>
        </div>
        <?php if ($msg): ?><div class="alert alert-success"><?php echo $this->e($msg); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo $this->e($error); ?></div><?php endif; ?>

        <?php if ($rebuildNext): ?>
        <!-- 重建进度 -->
        <div class="admin-section admin-form-lg mn-text-center">
            <h3>⏳ <?php echo $this->t('admin.search_rebuilding'); ?></h3>
            <?php
                $currentPage = (int)($this->getData('current_page', 0));
                $totalPages = max(1, (int)($this->getData('total_pages', 1)));
                $percent = round($currentPage / $totalPages * 100);
            ?>
            <div class="rebuild-bar-wrap">
                <div class="rebuild-bar-fill" style="width:<?php echo $percent; ?>%;">
                    <span class="rebuild-bar-text"><?php echo $percent; ?>%</span>
                </div>
            </div>
            <p class="c-666 mn-fs-13"><?php echo $this->e($rebuildProgress); ?>（<?php echo $this->t('admin.search_rebuild_progress', ['current' => $currentPage, 'total' => $totalPages]); ?>）</p>
            <p class="c-999 mn-fs-12"><?php echo $this->t('admin.search_do_not_close'); ?></p>
            <meta http-equiv="refresh" content="3;url=<?php echo $rebuildNext; ?>">
        </div>
        <?php endif; ?>

        <!-- 索引开关 -->
        <div class="admin-section admin-form-lg">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="save_switch">

                <div class="form-group">
                    <label class="maintenance-label">
                        <input type="checkbox" name="search_index_content" value="1" <?php echo $fullContent ? 'checked' : ''; ?> class="checkbox-inline checkbox-lg">
                        <strong><?php echo $this->t('admin.search_index_title'); ?></strong>
                    </label>
                </div>

                <div class="form-group rebuild-box">
                    <p class="m-0-0-8 mn-fs-13 c-555">
                        <?php echo $this->icon('search', 14); ?> <strong><?php echo $this->t('admin.search_title_only'); ?></strong> <?php echo $this->t('admin.search_title_only_desc'); ?><br>
                        <?php echo $this->icon('search', 14); ?> <strong><?php echo $this->t('admin.search_title_content'); ?></strong> <?php echo $this->t('admin.search_title_content_desc'); ?>
                    </p>
                    <p class="mn-m-0 mn-fs-12 c-999">
                        <?php echo $this->icon('info', 14); ?> <strong><?php echo $this->t('admin.search_fulltext_advice'); ?></strong><?php echo $this->t('admin.search_fulltext_advice_val'); ?>
                    </p>
                </div>

                <button type="submit" class="btn btn-primary"><?php echo $this->t('admin.search_save_switch'); ?></button>
            </form>
        </div>

        <!-- 清理正文索引 -->
        <div class="admin-section admin-form-lg mn-mt-20">
            <h3 class="admin-section-title"><?php echo $this->t('admin.search_clean_content'); ?></h3>
            <div class="form-group rebuild-box">
                <p class="mn-m-0 mn-fs-13 c-555">
                    <?php echo $this->icon('info', 14); ?> <strong><?php echo $this->t('admin.search_clean_content'); ?></strong> <?php echo $this->t('admin.search_clean_hint'); ?>
                </p>
            </div>
            <form method="POST" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.search_clean_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="clean_content">
                <button type="submit" class="btn btn-del"><?php echo $this->icon('delete', 14); ?> <?php echo $this->t('admin.search_clean_btn'); ?></button>
            </form>
        </div>

        <!-- 搜索索引重建（重建 / 续建 / 停止） -->
        <div class="admin-section admin-form-lg mn-mt-20">
            <h3 class="admin-section-title"><?php echo $this->t('admin.search_rebuild_all'); ?></h3>
            <div class="form-group rebuild-box">
                <p class="m-0-0-8 mn-fs-13 c-555">
                    <?php echo $this->icon('search', 14); ?> <strong><?php echo $this->t('admin.search_rebuild_all'); ?></strong> <?php echo $this->t('admin.search_rebuild_hint'); ?>
                </p>
                <p class="mn-m-0 mn-fs-12 c-999">
                    <?php echo $this->icon('info', 14); ?> <?php echo $this->t('admin.search_rebuild_actions_hint'); ?>
                </p>
            </div>

            <!-- 每批条数选择条（独立一行）+ 按钮行：Alpine 同步 batch 到 rebuild/resume -->
            <div x-data="{ batch: <?php echo (int)$perPage; ?> }">
                <div class="form-group mn-mb-12">
                    <label class="mn-fs-13"><?php echo $this->t('admin.search_batch_size'); ?></label>
                    <select x-model.number="batch" class="per-page-select">
                        <option value="5" <?php echo $perPage === 5 ? 'selected' : ''; ?>><?php echo $this->t('admin.search_batch_low'); ?></option>
                        <option value="10" <?php echo $perPage === 10 ? 'selected' : ''; ?>><?php echo $this->t('admin.search_batch_n', ['n' => 10]); ?></option>
                        <option value="20" <?php echo $perPage === 20 ? 'selected' : ''; ?>><?php echo $this->t('admin.search_batch_n', ['n' => 20]); ?></option>
                        <option value="50" <?php echo $perPage === 50 ? 'selected' : ''; ?>><?php echo $this->t('admin.search_batch_n', ['n' => 50]); ?></option>
                        <option value="100" <?php echo $perPage === 100 ? 'selected' : ''; ?>><?php echo $this->t('admin.search_batch_high'); ?></option>
                    </select>
                </div>
                <div class="mn-flex mn-gap-10 mn-flex-wrap">
                    <!-- ① 重建（强制从头） -->
                    <form method="POST" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.search_rebuild_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                        <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="rebuild">
                        <input type="hidden" name="per_page" x-bind:value="batch">
                        <button type="submit" class="btn btn-primary"><?php echo $this->icon('maintenance', 14); ?> <?php echo $this->t('admin.search_rebuild_btn'); ?></button>
                    </form>

                    <!-- ② 续建（从断点继续） -->
                    <form method="POST" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.search_resume_confirm')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                        <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="resume">
                        <input type="hidden" name="per_page" x-bind:value="batch">
                        <button type="submit" class="btn btn-primary"><?php echo $this->icon('refresh', 14); ?> <?php echo $this->t('admin.search_resume_btn'); ?></button>
                    </form>

                    <!-- ③ 停止（中断并保留断点） -->
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="stop">
                        <button type="submit" class="btn btn-del"><?php echo $this->icon('pause', 14); ?> <?php echo $this->t('admin.search_stop_btn'); ?></button>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>
<?php $this->endSection(); ?>