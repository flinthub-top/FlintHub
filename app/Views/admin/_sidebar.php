<?php
/**
 * 后台侧边栏视图 — 左侧导航菜单
 * @file app/Views/admin/_sidebar.php
 */
?>
<aside class="admin-sidebar">
    <nav class="admin-nav">
        <a href="<?php echo $this->url('/admin'); ?>" class="<?php echo ($this->getData('__nav_active') ?? '') === 'dashboard' ? 'active' : ''; ?>"><?php echo $this->icon('dashboard', 16); ?> <?php echo $this->t('admin.nav_dashboard'); ?></a>
        <div class="nav-group-header"><?php echo $this->icon('settings', 14); ?> <?php echo $this->t('admin.nav_site_settings'); ?></div>
        <a href="<?php echo $this->url('/admin/settings/basic'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'settings_basic' ? 'active' : ''; ?>"><?php echo $this->icon('id-card', 14); ?> <?php echo $this->t('admin.nav_basic'); ?></a>
        <a href="<?php echo $this->url('/admin/settings/mode'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'settings_mode' ? 'active' : ''; ?>"><?php echo $this->icon('columns', 14); ?> <?php echo $this->t('admin.nav_site_mode'); ?></a>
        <a href="<?php echo $this->url('/admin/settings/system'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'settings_system' ? 'active' : ''; ?>"><?php echo $this->icon('settings', 14); ?> <?php echo $this->t('admin.nav_system_settings'); ?></a>
        <a href="<?php echo $this->url('/admin/settings/display'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'settings_display' ? 'active' : ''; ?>"><?php echo $this->icon('categories', 14); ?> <?php echo $this->t('admin.nav_display'); ?></a>
        <a href="<?php echo $this->url('/admin/settings/mail'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'mail_settings' ? 'active' : ''; ?>"><?php echo $this->icon('mail', 14); ?> <?php echo $this->t('admin.nav_mail'); ?></a>
        <a href="<?php echo $this->url('/admin/settings/search'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'settings_search' ? 'active' : ''; ?>"><?php echo $this->icon('search', 14); ?> <?php echo $this->t('admin.nav_search_settings'); ?></a>
        <a href="<?php echo $this->url('/admin/settings/queue'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'settings_queue' ? 'active' : ''; ?>"><?php echo $this->icon('sync', 14); ?> <?php echo $this->t('admin.nav_queue'); ?></a>

        <div class="nav-group-header"><?php echo $this->icon('categories', 14); ?> <?php echo $this->t('admin.nav_forum'); ?></div>
        <a href="<?php echo $this->url('/admin/categories'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'categories' ? 'active' : ''; ?>"><?php echo $this->icon('categories', 14); ?> <?php echo $this->t('admin.categories_title'); ?></a>
        <a href="<?php echo $this->url('/admin/permissions'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'permissions' ? 'active' : ''; ?>"><?php echo $this->icon('lock', 14); ?> <?php echo $this->t('admin.nav_permissions'); ?></a>
        <a href="<?php echo $this->url('/admin/threads'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'threads' ? 'active' : ''; ?>"><?php echo $this->icon('threads', 14); ?> <?php echo $this->t('admin.threads_title'); ?></a>
        <a href="<?php echo $this->url('/admin/tags'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'tags' ? 'active' : ''; ?>"><?php echo $this->icon('tag', 14); ?> <?php echo $this->t('admin.tags_title'); ?></a>
        <a href="<?php echo $this->url('/admin/trashed'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'trashed' ? 'active' : ''; ?>"><?php echo $this->icon('trash', 14); ?> <?php echo $this->t('admin.trashed_title'); ?></a>

        <div class="nav-group-header"><?php echo $this->icon('blog', 14); ?> <?php echo $this->t('admin.nav_blog'); ?></div>
        <a href="<?php echo $this->url('/admin/blog-categories'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'blog-categories' ? 'active' : ''; ?>"><?php echo $this->icon('blog-cat', 14); ?> <?php echo $this->t('admin.nav_blog_cats'); ?></a>
        <a href="<?php echo $this->url('/admin/blog-manage'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'blog-manage' ? 'active' : ''; ?>"><?php echo $this->icon('blog-manage', 14); ?> <?php echo $this->t('admin.blog_manage_title'); ?></a>

        <div class="nav-group-header"><?php echo $this->icon('users', 14); ?> <?php echo $this->t('admin.nav_users'); ?></div>
        <a href="<?php echo $this->url('/admin/users'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'users' ? 'active' : ''; ?>"><?php echo $this->icon('user', 14); ?> <?php echo $this->t('admin.nav_user_list'); ?></a>
        <a href="<?php echo $this->url('/admin/groups'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'groups' ? 'active' : ''; ?>"><?php echo $this->icon('users', 14); ?> <?php echo $this->t('admin.nav_groups'); ?></a>
        <a href="<?php echo $this->url('/admin/levels'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'levels' ? 'active' : ''; ?>"><?php echo $this->icon('levels', 14); ?> <?php echo $this->t('admin.nav_levels'); ?></a>

        <div class="nav-group-header"><?php echo $this->icon('database', 14); ?> <?php echo $this->t('admin.nav_data'); ?></div>
        <a href="<?php echo $this->url('/admin/database/overview'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'database_overview' ? 'active' : ''; ?>"><?php echo $this->icon('database', 14); ?> SplitDB <?php echo $this->t('admin.nav_dashboard'); ?></a>
        <a href="<?php echo $this->url('/admin/database/settings'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'database_settings' ? 'active' : ''; ?>"><?php echo $this->icon('settings', 14); ?> SplitDB <?php echo $this->t('admin.settings_title'); ?></a>
        <a href="<?php echo $this->url('/admin/database'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'database' ? 'active' : ''; ?>"><?php echo $this->icon('save', 14); ?> <?php echo $this->t('admin.nav_backup'); ?></a>

        <div class="nav-group-header"><?php echo $this->icon('wrench', 14); ?> <?php echo $this->t('admin.nav_tools'); ?></div>
        <a href="<?php echo $this->url('/admin/logs/audit'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'logs_audit' ? 'active' : ''; ?>"><?php echo $this->icon('list', 14); ?> <?php echo $this->t('admin.logs_title'); ?></a>
        <a href="<?php echo $this->url('/admin/maintenance'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'maintenance' ? 'active' : ''; ?>"><?php echo $this->icon('wrench', 14); ?> <?php echo $this->t('admin.nav_maintenance'); ?></a>

        <div class="nav-group-header"><?php echo $this->icon('theme', 14); ?> <?php echo $this->t('admin.nav_appearance'); ?></div>
        <a href="<?php echo $this->url('/admin/themes'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'themes' ? 'active' : ''; ?>"><?php echo $this->icon('theme', 14); ?> <?php echo $this->t('admin.themes_title'); ?></a>

        <div class="nav-group-header"><?php echo $this->icon('settings', 14); ?> <?php echo $this->t('admin.nav_ext'); ?></div>
        <a href="<?php echo $this->url('/admin/plugins'); ?>" class="sub-link <?php echo ($this->getData('__nav_active') ?? '') === 'plugins' ? 'active' : ''; ?>"><?php echo $this->icon('plugin', 14); ?> <?php echo $this->t('admin.plugins_title'); ?></a>
<?php
\app\Helpers\Plugin::hook('admin_sidebar_links');
?>

        <a href="<?php echo $this->url('/'); ?>" class="back-link"><?php echo $this->icon('back', 14); ?> <?php echo $this->t('nav.back_to_site'); ?></a>

<script>
/* 同步执行：在浏览器首次绘制之前标记好折叠/展开状态，消除页面跳动 */
(function() {
    var nav = document.querySelector('.admin-sidebar .admin-nav');
    if (!nav) return;
    var currentPath = window.location.pathname;
    var headers = nav.querySelectorAll('.nav-group-header');
    headers.forEach(function(header) {
        /* 收集该 header 下方的子链接，直到下一个 header 或非 sub-link 元素 */
        var items = [];
        var el = header.nextElementSibling;
        while (el) {
            if (el.classList.contains('nav-group-header')) break;
            if (el.tagName === 'A' && !el.classList.contains('sub-link')) break;
            if (el.classList.contains('sub-link')) {
                el.classList.add('group-item');
                items.push(el);
            }
            el = el.nextElementSibling;
        }
        /* 自动展开：active 类或 href 匹配当前路径 */
        var hasActive = false;
        items.forEach(function(item) {
            if (item.classList.contains('active')) {
                hasActive = true;
            } else if (item.getAttribute('href') === currentPath) {
                item.classList.add('active');
                hasActive = true;
            }
        });
        if (hasActive) {
            header.classList.add('expanded');
            items.forEach(function(item) { item.classList.add('group-open'); });
        }
        /* 点击折叠/展开（事件绑定可以延迟，不影响首绘） */
    });
    /* 在首次内容绘制后绑定点击事件 */
    requestAnimationFrame(function() {
        headers.forEach(function(header) {
            var el = header.nextElementSibling;
            var items = [];
            while (el) {
                if (el.classList.contains('nav-group-header')) break;
                if (el.tagName === 'A' && !el.classList.contains('sub-link')) break;
                if (el.classList.contains('sub-link')) items.push(el);
                el = el.nextElementSibling;
            }
            header.addEventListener('click', function(e) {
                e.preventDefault();
                header.classList.toggle('expanded');
                items.forEach(function(item) {
                    item.classList.toggle('group-open');
                });
            });
        });
    });
})();
</script>
    </nav>
</aside>
