<?php
/**
 * 后台显示设置视图 — 分页（原有）+ 长度限制（Tab 分类）
 * @file app/Views/admin/settings_display.php
 */
$settings = $this->getData('settings', []);
$defaults = $this->getData('defaults', []);
$csrfToken = $this->e($this->getData('csrfToken'));
$msg = $this->getData('msg', '');
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.nav_display'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'settings_display']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('categories', 16); ?> <?php echo $this->t('admin.nav_display'); ?></h1>
        </div>
        <?php if ($msg): ?><div class="alert alert-success"><?php echo $this->e($msg); ?></div><?php endif; ?>
        <div class="admin-section admin-form-lg">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">

                <!-- ===== Tab 导航（纯 JS 切换，不刷新页面）===== -->
                <div class="admin-tabs" role="tablist">
                    <button type="button" class="admin-tab-btn active" data-tab="tab-paging" role="tab"><?php echo $this->t('admin.tab_paging'); ?></button>
                    <button type="button" class="admin-tab-btn" data-tab="tab-limits" role="tab"><?php echo $this->t('admin.tab_limits'); ?></button>
                </div>

                <!-- ===== Tab① 分页设置（原有内容）===== -->
                <div class="admin-tab-pane active" id="tab-paging" role="tabpanel">
                    <div class="tab-pane-head">
                        <p class="tab-pane-desc"><?php echo $this->t('admin.tab_paging_desc'); ?></p>
                    </div>

                    <h3 class="admin-section-title"><?php echo $this->t('admin.home_settings'); ?></h3>
                    <div class="form-group">
                        <label><?php echo $this->t('admin.home_threads_count'); ?></label>
                        <input type="number" name="home_threads_count" value="<?php echo (int)($settings['home_threads_count'] ?? 10); ?>" min="1" max="50" class="form-input">
                    </div>
                    <div class="form-group">
                        <label><?php echo $this->t('admin.home_blogs_count'); ?></label>
                        <input type="number" name="home_blogs_count" value="<?php echo (int)($settings['home_blogs_count'] ?? 5); ?>" min="1" max="50" class="form-input">
                    </div>

                    <hr class="admin-hr">
                    <h3 class="admin-section-title"><?php echo $this->t('admin.forum_settings'); ?></h3>
                    <div class="form-group">
                        <label><?php echo $this->t('admin.threads_per_page'); ?></label>
                        <input type="number" name="threads_per_page" value="<?php echo (int)($settings['threads_per_page'] ?? 20); ?>" min="5" max="100" class="form-input">
                    </div>
                    <div class="form-group">
                        <label><?php echo $this->t('admin.posts_per_page'); ?></label>
                        <input type="number" name="posts_per_page" value="<?php echo (int)($settings['posts_per_page'] ?? 20); ?>" min="5" max="100" class="form-input">
                    </div>

                    <hr class="admin-hr">
                    <h3 class="admin-section-title"><?php echo $this->t('admin.blog_settings'); ?></h3>
                    <div class="form-group">
                        <label><?php echo $this->t('admin.blogs_per_page'); ?></label>
                        <input type="number" name="blogs_per_page" value="<?php echo (int)($settings['blogs_per_page'] ?? 10); ?>" min="5" max="100" class="form-input">
                    </div>
                    <div class="form-group">
                        <label><?php echo $this->t('admin.blog_comments_per_page'); ?></label>
                        <input type="number" name="blog_comments_per_page" value="<?php echo (int)($settings['blog_comments_per_page'] ?? 20); ?>" min="5" max="100" class="form-input">
                    </div>
                </div>

                <!-- ===== Tab② 长度限制 ===== -->
                <div class="admin-tab-pane" id="tab-limits" role="tabpanel">
                    <div class="tab-pane-head">
                        <p class="tab-pane-desc"><?php echo $this->t('admin.tab_limits_desc'); ?></p>
                        <button type="button" class="btn btn-secondary btn-sm" data-reset-tab="tab-limits"><?php echo $this->t('admin.reset_defaults'); ?></button>
                    </div>
                    <div class="form-group">
                        <label><?php echo $this->t('admin.limit_thread_title'); ?></label>
                        <input type="number" name="limit_thread_title" min="1" max="10000"
                               value="<?php echo (int)($settings['limit_thread_title'] ?? $defaults['limit_thread_title']); ?>"
                               data-default="<?php echo (int)$defaults['limit_thread_title']; ?>" class="form-input">
                        <p class="mn-fs-12 c-999 mn-mt-4"><?php echo $this->t('admin.limit_thread_title_hint'); ?></p>
                    </div>
                    <div class="form-group">
                        <label><?php echo $this->t('admin.limit_user_signature'); ?></label>
                        <input type="number" name="limit_user_signature" min="1" max="10000"
                               value="<?php echo (int)($settings['limit_user_signature'] ?? $defaults['limit_user_signature']); ?>"
                               data-default="<?php echo (int)$defaults['limit_user_signature']; ?>" class="form-input">
                        <p class="mn-fs-12 c-999 mn-mt-4"><?php echo $this->t('admin.limit_user_signature_hint'); ?></p>
                    </div>
                    <div class="form-group">
                        <label><?php echo $this->t('admin.limit_search_query'); ?></label>
                        <input type="number" name="limit_search_query" min="1" max="10000"
                               value="<?php echo (int)($settings['limit_search_query'] ?? $defaults['limit_search_query']); ?>"
                               data-default="<?php echo (int)$defaults['limit_search_query']; ?>" class="form-input">
                        <p class="mn-fs-12 c-999 mn-mt-4"><?php echo $this->t('admin.limit_search_query_hint'); ?></p>
                    </div>
                    <div class="form-group">
                        <label><?php echo $this->t('admin.limit_tag_name'); ?></label>
                        <input type="number" name="limit_tag_name" min="1" max="10000"
                               value="<?php echo (int)($settings['limit_tag_name'] ?? $defaults['limit_tag_name']); ?>"
                               data-default="<?php echo (int)$defaults['limit_tag_name']; ?>" class="form-input">
                        <p class="mn-fs-12 c-999 mn-mt-4"><?php echo $this->t('admin.limit_tag_name_hint'); ?></p>
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