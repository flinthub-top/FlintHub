<?php
/**
 * 后台系统设置视图 — 常规（站点状态/功能开关/性能/安全验证）+ 积分 + 限流 + 附件（Tab 分类）
 * @file app/Views/admin/settings_system.php
 */
$settings = $this->getData('settings', []);
$defaults = $this->getData('defaults', []);
$csrfToken = $this->e($this->getData('csrfToken'));
$msg = $this->getData('msg', '');
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.nav_system_settings'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'settings_system']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('settings', 16); ?> <?php echo $this->t('admin.nav_system_settings'); ?></h1>
        </div>
        <?php if ($msg): ?><div class="alert alert-success"><?php echo $this->e($msg); ?></div><?php endif; ?>

        <div class="admin-section admin-form-lg">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">

                <!-- ===== Tab 导航（纯 JS 切换，不刷新页面）===== -->
                <div class="admin-tabs" role="tablist">
                    <button type="button" class="admin-tab-btn active" data-tab="tab-general" role="tab"><?php echo $this->t('admin.tab_general'); ?></button>
                    <button type="button" class="admin-tab-btn" data-tab="tab-points" role="tab"><?php echo $this->t('admin.tab_points'); ?></button>
                    <button type="button" class="admin-tab-btn" data-tab="tab-rate" role="tab"><?php echo $this->t('admin.tab_rate'); ?></button>
                    <button type="button" class="admin-tab-btn" data-tab="tab-attachment" role="tab"><?php echo $this->t('admin.tab_attachment'); ?></button>
                </div>

                <!-- ===== Tab① 常规设置（原有内容）===== -->
                <div class="admin-tab-pane active" id="tab-general" role="tabpanel">
                    <div class="tab-pane-head">
                        <p class="tab-pane-desc"><?php echo $this->t('admin.tab_general_desc'); ?></p>
                    </div>

                    <h3 class="admin-section-title"><?php echo $this->t('admin.system_site_status'); ?></h3>
                    <div class="form-group maintenance-box">
                        <label class="maintenance-label">
                            <input type="checkbox" name="site_closed" value="1" <?php echo !empty($settings['site_closed']) && $settings['site_closed'] === '1' ? 'checked' : ''; ?> class="checkbox-inline checkbox-lg">
                            <?php echo $this->t('admin.site_closed'); ?>
                        </label>
                        <p class="mn-fs-13 c-666 maintenance-desc">
                            <?php echo $this->t('admin.site_closed_hint'); ?>
                        </p>
                    </div>

                    <hr class="admin-hr">

                    <h3 class="admin-section-title"><?php echo $this->t('admin.feature_switches'); ?></h3>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="allow_registration" value="1" <?php echo !empty($settings['allow_registration']) && $settings['allow_registration'] !== '0' ? 'checked' : ''; ?> class="checkbox-inline">
                            <?php echo $this->t('admin.allow_registration'); ?>
                        </label>
                        <p class="mn-fs-12 c-999 mn-mt-4"><?php echo $this->t('admin.system_allow_registration_hint'); ?></p>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="allow_attachments" value="1" <?php echo !empty($settings['allow_attachments']) && $settings['allow_attachments'] !== '0' ? 'checked' : ''; ?> class="checkbox-inline">
                            <?php echo $this->t('admin.allow_attachments'); ?>
                        </label>
                        <p class="mn-fs-12 c-999 mn-mt-4"><?php echo $this->t('admin.system_allow_attachments_hint'); ?></p>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="message_enabled" value="1" <?php echo !empty($settings['message_enabled']) && $settings['message_enabled'] === '1' ? 'checked' : ''; ?> class="checkbox-inline">
                            <?php echo $this->t('admin.enable_message'); ?>
                        </label>
                        <p class="mn-fs-12 c-999 mn-mt-4"><?php echo $this->t('admin.system_enable_message_hint'); ?></p>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="email_verify_enabled" value="1" <?php echo (($settings['email_verify_enabled'] ?? '1') === '1') ? 'checked' : ''; ?> class="checkbox-inline">
                            <?php echo $this->t('admin.enable_email_verify'); ?>
                        </label>
                        <p class="mn-fs-12 c-999 mn-mt-4"><?php echo $this->t('admin.enable_email_verify_hint'); ?></p>
                    </div>

                    <hr class="admin-hr">

                    <h3 class="admin-section-title"><?php echo $this->t('admin.system_performance'); ?></h3>
                    <div class="form-group maintenance-box">
                        <label class="maintenance-label">
                            <input type="checkbox" name="enable_gzip" value="1" <?php echo !empty($settings['enable_gzip']) && $settings['enable_gzip'] === '1' ? 'checked' : ''; ?> class="checkbox-inline checkbox-lg">
                            <?php echo $this->t('admin.enable_gzip'); ?>
                        </label>
                        <p class="mn-fs-13 c-666 maintenance-desc">
                            <?php echo $this->t('admin.enable_gzip_hint'); ?>
                        </p>
                    </div>

                    <hr class="admin-hr">

                    <h3 class="admin-section-title"><?php echo $this->t('admin.verify_mode_title'); ?></h3>
                    <?php $verifyMode = (int)($settings['admin_verify_timeout'] ?? 1800); ?>
                    <div class="form-group">
                        <label class="maintenance-label">
                            <input type="radio" name="admin_verify_timeout" value="1800" <?php echo $verifyMode === 1800 ? 'checked' : ''; ?> class="checkbox-inline">
                            <strong><?php echo $this->t('admin.verify_mode_standard'); ?></strong>
                        </label>
                        <p class="m-0-0-8 mn-fs-12 c-999"><?php echo $this->t('admin.verify_mode_standard_desc'); ?></p>
                    </div>
                    <div class="form-group">
                        <label class="maintenance-label">
                            <input type="radio" name="admin_verify_timeout" value="2592000" <?php echo $verifyMode === 2592000 ? 'checked' : ''; ?> class="checkbox-inline">
                            <strong><?php echo $this->t('admin.verify_mode_long'); ?></strong>
                        </label>
                        <p class="m-0-0-8 mn-fs-12 c-999"><?php echo $this->t('admin.verify_mode_long_desc'); ?></p>
                    </div>
                    <div class="form-group rebuild-box">
                        <p class="mn-m-0 mn-fs-12 c-999">
                            <?php echo $this->icon('info', 14); ?> <?php echo $this->t('admin.verify_mode_advice'); ?>
                        </p>
                    </div>
                </div>

                <!-- ===== Tab② 积分设置 ===== -->
                <div class="admin-tab-pane" id="tab-points" role="tabpanel">
                    <div class="tab-pane-head">
                        <p class="tab-pane-desc"><?php echo $this->t('admin.tab_points_desc'); ?></p>
                        <button type="button" class="btn btn-secondary btn-sm" data-reset-tab="tab-points"><?php echo $this->t('admin.reset_defaults'); ?></button>
                    </div>
                    <div class="form-group">
                        <label><?php echo $this->t('admin.points_thread_create'); ?></label>
                        <input type="number" name="points_thread_create" min="0" max="10000"
                               value="<?php echo (int)($settings['points_thread_create'] ?? $defaults['points_thread_create']); ?>"
                               data-default="<?php echo (int)$defaults['points_thread_create']; ?>" class="form-input">
                        <p class="mn-fs-12 c-999 mn-mt-4"><?php echo $this->t('admin.points_thread_create_hint'); ?></p>
                    </div>
                    <div class="form-group">
                        <label><?php echo $this->t('admin.points_post_create'); ?></label>
                        <input type="number" name="points_post_create" min="0" max="10000"
                               value="<?php echo (int)($settings['points_post_create'] ?? $defaults['points_post_create']); ?>"
                               data-default="<?php echo (int)$defaults['points_post_create']; ?>" class="form-input">
                        <p class="mn-fs-12 c-999 mn-mt-4"><?php echo $this->t('admin.points_post_create_hint'); ?></p>
                    </div>
                    <div class="form-group">
                        <label><?php echo $this->t('admin.points_vote_received'); ?></label>
                        <input type="number" name="points_vote_received" min="0" max="10000"
                               value="<?php echo (int)($settings['points_vote_received'] ?? $defaults['points_vote_received']); ?>"
                               data-default="<?php echo (int)$defaults['points_vote_received']; ?>" class="form-input">
                        <p class="mn-fs-12 c-999 mn-mt-4"><?php echo $this->t('admin.points_vote_received_hint'); ?></p>
                    </div>
                </div>

                <!-- ===== Tab③ 限流设置（<details> 折叠分组）===== -->
                <div class="admin-tab-pane" id="tab-rate" role="tabpanel">
                    <div class="tab-pane-head">
                        <p class="tab-pane-desc"><?php echo $this->t('admin.tab_rate_desc'); ?></p>
                        <button type="button" class="btn btn-secondary btn-sm" data-reset-tab="tab-rate"><?php echo $this->t('admin.reset_defaults'); ?></button>
                    </div>
                    <?php
                    // 13 组限流：动作标识 → [i18n 键, max 默认, window 默认]
                    $rateGroups = [
                        'reply' => ['admin.rate_reply', 3, 30],
                        'create_thread' => ['admin.rate_create_thread', 5, 600],
                        'search' => ['admin.rate_search', 8, 30],
                        'message_send' => ['admin.rate_message_send', 10, 60],
                        'blog_comment' => ['admin.rate_blog_comment', 10, 60],
                        'vote' => ['admin.rate_vote', 30, 60],
                        'upload' => ['admin.rate_upload', 5, 60],
                        'fetch_image' => ['admin.rate_fetch_image', 20, 60],
                        'post_body' => ['admin.rate_post_body', 30, 60],
                        'login' => ['admin.rate_login', 20, 900],
                        'register' => ['admin.rate_register', 3, 3600],
                        'forgot_password' => ['admin.rate_forgot_password', 3, 3600],
                        'resend_verify' => ['admin.rate_resend_verify', 3, 3600],
                    ];
                    foreach ($rateGroups as $action => [$labelKey, $defMax, $defWin]):
                        $curMax = (int)($settings["rate_{$action}_max"] ?? $defaults["rate_{$action}_max"]);
                        $curWin = (int)($settings["rate_{$action}_window"] ?? $defaults["rate_{$action}_window"]);
                    ?>
                    <details class="rate-details">
                        <summary><?php echo $this->t($labelKey); ?></summary>
                        <div class="rate-details-body">
                            <div class="rate-field">
                                <label><?php echo $this->t('admin.rate_max'); ?></label>
                                <input type="number" name="rate_<?php echo $action; ?>_max" min="1" max="100000"
                                       value="<?php echo $curMax; ?>"
                                       data-default="<?php echo $defMax; ?>" class="form-input">
                            </div>
                            <div class="rate-field">
                                <label><?php echo $this->t('admin.rate_window'); ?></label>
                                <input type="number" name="rate_<?php echo $action; ?>_window" min="1" max="86400"
                                       value="<?php echo $curWin; ?>"
                                       data-default="<?php echo $defWin; ?>" class="form-input">
                            </div>
                        </div>
                    </details>
                    <?php endforeach; ?>
                    <p class="mn-fs-12 c-999 mn-mt-4"><?php echo $this->t('admin.tab_rate_note'); ?></p>
                </div>

                <!-- ===== Tab④ 附件设置 ===== -->
                <div class="admin-tab-pane" id="tab-attachment" role="tabpanel">
                    <div class="tab-pane-head">
                        <p class="tab-pane-desc"><?php echo $this->t('admin.tab_attachment_desc'); ?></p>
                        <button type="button" class="btn btn-secondary btn-sm" data-reset-tab="tab-attachment"><?php echo $this->t('admin.reset_defaults'); ?></button>
                    </div>
                    <div class="form-group">
                        <label><?php echo $this->t('admin.attachment_max_per_post'); ?></label>
                        <input type="number" name="attachment_max_per_post" min="1" max="100"
                               value="<?php echo (int)($settings['attachment_max_per_post'] ?? $defaults['attachment_max_per_post']); ?>"
                               data-default="<?php echo (int)$defaults['attachment_max_per_post']; ?>" class="form-input">
                        <p class="mn-fs-12 c-999 mn-mt-4"><?php echo $this->t('admin.attachment_max_per_post_hint'); ?></p>
                    </div>
                    <div class="form-group">
                        <label><?php echo $this->t('admin.attachment_max_size'); ?></label>
                        <input type="number" name="attachment_max_size" min="1024" max="<?php echo (int)MAX_FILE_SIZE * 10; ?>"
                               value="<?php echo (int)($settings['attachment_max_size'] ?? $defaults['attachment_max_size']); ?>"
                               data-default="<?php echo (int)$defaults['attachment_max_size']; ?>" class="form-input">
                        <p class="mn-fs-12 c-999 mn-mt-4"><?php echo $this->t('admin.attachment_max_size_hint', ['size' => number_format((int)MAX_FILE_SIZE)]); ?></p>
                    </div>
                    <div class="form-group">
                        <label><?php echo $this->t('admin.attachment_allowed_ext'); ?></label>
                        <input type="text" name="attachment_allowed_ext"
                               value="<?php echo $this->e($settings['attachment_allowed_ext'] ?? $defaults['attachment_allowed_ext']); ?>"
                               data-default="<?php echo $this->e($defaults['attachment_allowed_ext']); ?>" class="form-input">
                        <p class="mn-fs-12 c-999 mn-mt-4"><?php echo $this->t('admin.attachment_allowed_ext_hint'); ?></p>
                        <p class="mn-fs-12 c-warn mn-mt-4"><?php echo $this->t('admin.attachment_allowed_ext_warn'); ?></p>
                    </div>
                </div>

                <p class="mn-fs-13 c-666 mn-mt-16"><?php echo $this->icon('info', 14); ?> <?php echo $this->t('admin.settings_instant_effect'); ?></p>
                <button type="submit" class="btn btn-primary"><?php echo $this->t('admin.save_settings'); ?></button>
            </form>
        </div>
    </main>
</div>

<script>
/* Tab 切换（纯 JS，不刷新页面）；「恢复默认值」将当前 Tab 内全部配置项重置为默认值（需点保存生效） */
(function() {
    var tabs = document.querySelectorAll('.admin-tab-btn');
    var panes = document.querySelectorAll('.admin-tab-pane');
    if (!tabs.length || !panes.length) return;

    function activate(tabId) {
        panes.forEach(function(pane) { pane.classList.toggle('active', pane.id === tabId); });
        tabs.forEach(function(btn) { btn.classList.toggle('active', btn.getAttribute('data-tab') === tabId); });
    }
    tabs.forEach(function(btn) {
        btn.addEventListener('click', function() { activate(btn.getAttribute('data-tab')); });
    });

    document.querySelectorAll('[data-reset-tab]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var pane = document.getElementById(btn.getAttribute('data-reset-tab'));
            if (!pane) return;
            pane.querySelectorAll('[data-default]').forEach(function(input) {
                input.value = input.getAttribute('data-default');
            });
        });
    });
})();
</script>
<?php $this->endSection(); ?>