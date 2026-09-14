<?php
/**
 * 后台插件管理视图 — 卡片网格（2 列 × 4 行/页）+ 搜索 + 分页
 * @file app/Views/admin/plugins.php
 */
$plugins = $this->getData('plugins', []);
$query = (string)$this->getData('query', '');
$cat = (string)$this->getData('cat', 'all');
$categories = $this->getData('categories', []);
$catCounts = $this->getData('catCounts', ['all' => 0]);
$page = (int)$this->getData('page', 1);
$totalPages = (int)$this->getData('totalPages', 1);
$total = (int)$this->getData('total', 0);
$permissionEnforce = (bool)$this->getData('permissionEnforce', false);
// 权限键 → 徽标文案 i18n 键
$permLabels = [
    'net:fetch'         => 'admin.plugin_perm_net_fetch',
    'route:admin'       => 'admin.plugin_perm_route_admin',
    'system:settings'   => 'admin.plugin_perm_system_settings',
];
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.plugins_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar'); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('plugin', 16); ?> <?php echo $this->t('admin.plugins_title'); ?></h1>
        </div>

        <?php $flashMsg = $_SESSION['flash_msg'] ?? ''; $flashError = $_SESSION['flash_error'] ?? ''; unset($_SESSION['flash_msg'], $_SESSION['flash_error']); ?>
        <?php if ($flashMsg): ?>
            <div class="alert alert-success"><?php echo $this->e($flashMsg); ?></div>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <div class="alert alert-error"><?php echo $this->e($flashError); ?></div>
        <?php endif; ?>

        <!-- 安全提示：插件为受信任 PHP 代码，启用后拥有站点执行权限 -->
        <div class="alert alert-warning-custom">
            <?php echo $this->icon('info', 14); ?> <?php echo $this->t('admin.plugin_trust_notice'); ?>
        </div>

        <!-- 权限强制模式开关（默认警告模式） -->
        <div class="plugin-enforce-bar">
            <div class="plugin-enforce-info">
                <strong><?php echo $this->t('admin.plugin_enforce_label'); ?></strong>
                <span class="plugin-enforce-status<?php echo $permissionEnforce ? ' on' : ''; ?>">
                    <?php echo $this->t($permissionEnforce ? 'admin.plugin_enforce_on_msg' : 'admin.plugin_enforce_off_msg'); ?>
                </span>
                <span class="plugin-enforce-hint"><?php echo $this->t('admin.plugin_enforce_hint'); ?></span>
            </div>
            <form method="post" action="<?php echo $this->url('/admin/plugins/permission-enforce'); ?>" class="mn-inline-block">
                <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                <input type="hidden" name="enforce" value="<?php echo $permissionEnforce ? '0' : '1'; ?>">
                <button type="submit" class="btn btn-sm <?php echo $permissionEnforce ? 'btn-warning' : 'btn-primary'; ?>">
                    <?php echo $this->t($permissionEnforce ? 'admin.plugin_enforce_turn_off' : 'admin.plugin_enforce_turn_on'); ?>
                </button>
            </form>
        </div>

        <!-- 分类 Tab（链接式切换；搜索词保留在当前分类内） -->
        <div class="admin-tabs" role="tablist">
            <?php
            $tabItems = ['all' => $this->t('admin.plugin_cat_all')];
            foreach ($categories as $c) {
                $tabItems[$c] = $this->t('admin.plugin_cat_' . $c);
            }
            $tabQ = rawurlencode($query);
            foreach ($tabItems as $c => $label):
                $count = isset($catCounts[$c]) ? (int)$catCounts[$c] : 0;
                $cls = $c === $cat ? 'admin-tab-btn active' : 'admin-tab-btn';
                $url = '/admin/plugins?cat=' . rawurlencode($c) . ($tabQ !== '' ? '&q=' . $tabQ : '');
            ?>
            <a href="<?php echo $this->url($url); ?>" class="<?php echo $cls; ?>" role="tab" aria-selected="<?php echo $c === $cat ? 'true' : 'false'; ?>"><?php echo $this->e($label); ?> <span class="plugin-tab-count"><?php echo $count; ?></span></a>
            <?php endforeach; ?>
        </div>

        <!-- 搜索 -->
        <form method="get" action="<?php echo $this->url('/admin/plugins'); ?>" class="plugin-search-bar">
            <input type="hidden" name="cat" value="<?php echo $this->e($cat); ?>">
            <input type="text" name="q" value="<?php echo $this->e($query); ?>" placeholder="<?php echo $this->t('admin.plugin_search_placeholder'); ?>" class="form-input">
            <button type="submit" class="btn btn-primary"><?php echo $this->t('admin.plugin_search'); ?></button>
        </form>

        <div class="plugin-count-line">
            <?php echo $this->t('admin.plugin_count', ['count' => $total]); ?>
            <?php if ($query !== ''): ?>
            · <a href="<?php echo $this->url('/admin/plugins?cat=' . rawurlencode($cat)); ?>"><?php echo $this->t('admin.plugin_clear'); ?></a>
            <?php endif; ?>
        </div>

        <?php if (empty($plugins)): ?>
            <div class="empty-state">
                <p><?php echo $this->t($query !== '' ? 'admin.plugin_no_result' : 'admin.plugin_none'); ?></p>
                <?php if ($query !== ''): ?>
                <p><a href="<?php echo $this->url('/admin/plugins'); ?>" class="btn btn-sm"><?php echo $this->t('admin.plugin_clear'); ?></a></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
        <div class="plugin-grid">
            <?php foreach ($plugins as $name => $plugin): ?>
            <?php
            $activated = !empty($plugin['activated']);
            $icon = (string)($plugin['icon'] ?? 'plugin');
            $adminUrl = (string)($plugin['admin_url'] ?? '');
            ?>
            <div class="plugin-card">
                <div class="plugin-card-head">
                    <span class="plugin-card-icon<?php echo $activated ? '' : ' plugin-card-icon-off'; ?>"><?php echo $this->icon($icon, 18); ?></span>
                    <div class="plugin-card-title">
                        <div class="plugin-card-name"><?php echo $this->e($plugin['name'] ?? $name); ?></div>
                        <div class="plugin-card-desc" title="<?php echo $this->e($plugin['description'] ?? ''); ?>"><?php echo $this->e($plugin['description'] ?? '--'); ?></div>
                    </div>
                </div>
                <div class="plugin-card-meta">
                    <span class="plugin-card-cat"><?php echo $this->icon('folder', 12); ?> <?php echo $this->e($this->t('admin.plugin_cat_' . ($plugin['category'] ?? 'default'))); ?></span>
                    <span class="plugin-card-ver"><?php echo $this->icon('tag', 12); ?> v<?php echo $this->e($plugin['version'] ?? '--'); ?></span>
                    <span><?php echo $this->icon('user', 12); ?> <?php echo $this->e($plugin['author'] ?? '--'); ?></span>
                    <?php if ($activated): ?>
                    <span class="plugin-status-on"><?php echo $this->icon('check-circle', 12); ?> <?php echo $this->t('admin.plugin_enabled'); ?></span>
                    <?php else: ?>
                    <span class="plugin-status-off"><?php echo $this->icon('times-circle', 12); ?> <?php echo $this->t('admin.plugin_disabled'); ?></span>
                    <?php endif; ?>
                </div>
                <?php $perms = (array)($plugin['permissions'] ?? []); ?>
                <?php if ($perms): ?>
                <div class="plugin-perm-row">
                    <span class="plugin-perm-label"><?php echo $this->icon('key', 11); ?> <?php echo $this->t('admin.plugin_perms'); ?>:</span>
                    <?php foreach ($perms as $perm): ?>
                    <?php $permKey = (string)$perm; $label = isset($permLabels[$permKey]) ? $permLabels[$permKey] : ''; ?>
                    <span class="plugin-perm-badge<?php echo $permKey === 'net:fetch' || $permKey === 'system:settings' ? ' high' : ''; ?>" title="<?php echo $label !== '' ? $this->e($this->t($label)) : $this->e($permKey); ?>">
                        <?php echo $label !== '' ? $this->e($this->t($label)) : $this->e($permKey); ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="plugin-card-divider"></div>
                <div class="plugin-card-actions">
                    <?php if ($adminUrl !== ''): ?>
                    <a href="<?php echo $this->url($adminUrl); ?>" class="btn btn-sm btn-secondary"><?php echo $this->t('admin.plugin_settings'); ?></a>
                    <?php endif; ?>
                    <?php if ($activated): ?>
                    <form method="post" action="<?php echo $this->url('/admin/plugins/deactivate'); ?>" class="mn-inline-block">
                        <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                        <input type="hidden" name="name" value="<?php echo $this->e($name); ?>">
                        <input type="hidden" name="cat" value="<?php echo $this->e($cat); ?>">
                        <input type="hidden" name="q" value="<?php echo $this->e($query); ?>">
                        <input type="hidden" name="page" value="<?php echo $page; ?>">
                        <button type="submit" class="btn btn-sm btn-warning"><?php echo $this->t('admin.plugin_disable'); ?></button>
                    </form>
                    <?php else: ?>
                    <form method="post" action="<?php echo $this->url('/admin/plugins/activate'); ?>" class="mn-inline-block">
                        <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                        <input type="hidden" name="name" value="<?php echo $this->e($name); ?>">
                        <input type="hidden" name="cat" value="<?php echo $this->e($cat); ?>">
                        <input type="hidden" name="q" value="<?php echo $this->e($query); ?>">
                        <input type="hidden" name="page" value="<?php echo $page; ?>">
                        <button type="submit" class="btn btn-sm btn-success"><?php echo $this->t('admin.plugin_enable'); ?></button>
                    </form>
                    <?php endif; ?>
                    <a href="<?php echo $this->url('/admin/plugins/edit/' . $this->e($name)); ?>" class="btn btn-sm btn-secondary"><?php echo $this->t('admin.plugin_edit'); ?></a>
                    <form method="post" action="<?php echo $this->url('/admin/plugins/uninstall'); ?>" class="mn-inline-block" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.plugin_uninstall_confirm', ['name' => $plugin['name'] ?? $name])), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                        <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                        <input type="hidden" name="name" value="<?php echo $this->e($name); ?>">
                        <input type="hidden" name="cat" value="<?php echo $this->e($cat); ?>">
                        <input type="hidden" name="q" value="<?php echo $this->e($query); ?>">
                        <input type="hidden" name="page" value="<?php echo $page; ?>">
                        <button type="submit" class="btn btn-sm btn-danger"><?php echo $this->t('admin.plugin_uninstall'); ?></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php echo $this->pagination($page, $totalPages, '/admin/plugins?cat=' . rawurlencode($cat) . '&q=' . rawurlencode($query) . '&page={page}'); ?>
        <?php endif; ?>
    </main>
</div>
<?php $this->endSection(); ?>