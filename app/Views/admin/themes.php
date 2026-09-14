<?php
/**
 * 后台主题管理视图 — 主题列表、预览、切换、自定义 CSS
 * @file app/Views/admin/themes.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.themes_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php
$themes = $this->getData('themes', []);
$currentDefault = $this->getData('currentDefault', '');
$customCss = $this->getData('customCss', '');
$hideRightSidebar = (int)$this->getData('hideRightSidebar', 0);
$nightAuto = (int)$this->getData('nightAuto', 0);
$nightStart = (int)$this->getData('nightStart', 19);
$nightEnd = (int)$this->getData('nightEnd', 7);
$csrfToken = $this->e($this->getData('csrfToken'));
$success = $this->getData('success', '');
$error = $this->getData('error', '');

$successMessages = [
    'default' => \app\Helpers\I18n::get('admin.theme_default_updated'),
    'css' => \app\Helpers\I18n::get('admin.theme_css_saved'),
    'options' => \app\Helpers\I18n::get('admin.theme_options_saved'),
];
$errorMessages = [
    'invalid' => \app\Helpers\I18n::get('admin.theme_invalid'),
];
?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'themes']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('theme', 16); ?> <?php echo $this->t('admin.themes_title'); ?></h1>
        </div>

        <?php if ($success && isset($successMessages[$success])): ?>
            <div class="alert alert-success"><?php echo $this->e($successMessages[$success]); ?></div>
        <?php endif; ?>
        <?php if ($error && isset($errorMessages[$error])): ?>
            <div class="alert alert-error"><?php echo $this->e($errorMessages[$error]); ?></div>
        <?php endif; ?>

        <!-- 主题列表 -->
        <div class="admin-section">
            <h3 class="admin-section-title"><?php echo $this->t('admin.available_themes'); ?></h3>
            <?php
            $groups = ['classic' => $this->t('admin.theme_group_classic'), 'modern' => $this->t('admin.theme_group_modern')];
            $grouped = [];
            foreach ($themes as $themeKey => $themeInfo) {
                $g = $themeInfo['group'] ?? 'templates';
                $grouped[$g][] = ['key' => $themeKey, 'info' => $themeInfo];
            }
            ?>
            <?php foreach ($groups as $groupId => $groupLabel): ?>
                <?php if (empty($grouped[$groupId])) continue; ?>
                <div class="theme-group">
                    <div class="theme-group-header">
                        <span class="theme-group-icon"><?php echo $groupId === 'modern' ? '✦' : '◆'; ?></span>
                        <h3 class="theme-group-title"><?php echo $groupLabel; ?></h3>
                        <span class="theme-group-count"><?php echo count($grouped[$groupId]); ?></span>
                    </div>
                    <div class="theme-grid">
                        <?php foreach ($grouped[$groupId] as $item): ?>
                            <?php $themeKey = $item['key']; $theme = $item['info']; ?>
                            <?php $isCurrent = $themeKey === $currentDefault; ?>
                            <?php $previewUrl = $themeKey !== '' ? '/' . '?preview_theme=' . urlencode($themeKey) : '/'; ?>
                            <div class="theme-card <?php echo $isCurrent ? 'theme-card-active' : ''; ?>">
                                <div class="theme-card-header" style="background:<?php echo $this->e($theme['color'] ?? '#667eea'); ?>;">
                                    <div class="theme-card-name"><?php echo $this->e($theme['name'] ?? $this->t('admin.theme_unnamed')); ?></div>
                                    <?php if ($isCurrent): ?>
                                        <span class="theme-badge"><?php echo $this->t('admin.theme_default_badge'); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="theme-card-body">
                                    <div class="theme-card-meta">
                                        <span>v<?php echo $this->e($theme['version'] ?? '1.0'); ?></span>
                                        <span>by <?php echo $this->e($theme['author'] ?? 'Unknown'); ?></span>
                                    </div>
                                    <div class="theme-card-desc"><?php echo $this->e($theme['description'] ?? ''); ?></div>
                                    <div class="theme-card-actions">
                                        <?php if (!$isCurrent): ?>
                                            <form method="POST" action="<?php echo $this->url('/admin/themes/default'); ?>" class="form-inline">
                                                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                                                <input type="hidden" name="theme_key" value="<?php echo $this->e($themeKey); ?>">
                                                <button type="submit" class="btn btn-sm btn-primary"><?php echo $this->t('admin.theme_set_default'); ?></button>
                                            </form>
                                        <?php else: ?>
                                            <span class="btn btn-sm btn-disabled"><?php echo $this->t('admin.theme_current_default'); ?></span>
                                        <?php endif; ?>
                                        <a href="<?php echo $this->e($previewUrl); ?>" target="_blank" class="btn btn-sm btn-secondary"><?php echo $this->t('admin.theme_preview'); ?></a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- 界面选项：隐藏右栏 / 夜间自动切换 -->
        <div class="admin-section admin-form-md">
            <h3 class="admin-section-title"><?php echo $this->t('admin.theme_interface_options'); ?></h3>
            <p class="c-666 mn-fs-12 mn-mb-15"><?php echo $this->t('admin.theme_interface_options_hint'); ?></p>
            <form method="POST" action="<?php echo $this->url('/admin/themes/options'); ?>">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <div class="form-group">
                    <label class="checkbox-inline">
                        <input type="checkbox" name="hide_right_sidebar" value="1" <?php echo $hideRightSidebar ? 'checked' : ''; ?>> <?php echo $this->t('admin.theme_hide_right_sidebar'); ?>
                    </label>
                    <p class="c-666 mn-fs-12"><?php echo $this->t('admin.theme_hide_right_sidebar_hint'); ?></p>
                </div>
                <div class="form-group">
                    <label class="checkbox-inline">
                        <input type="checkbox" name="night_auto" value="1" <?php echo $nightAuto ? 'checked' : ''; ?>> <?php echo $this->t('admin.theme_night_auto'); ?>
                    </label>
                    <p class="c-666 mn-fs-12"><?php echo $this->t('admin.theme_night_auto_hint'); ?></p>
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('admin.theme_night_window'); ?></label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="number" name="night_start" min="0" max="23" value="<?php echo $nightStart; ?>" class="form-input" style="width:90px;" title="<?php echo $this->t('admin.theme_night_start'); ?>">
                        <span>—</span>
                        <input type="number" name="night_end" min="0" max="23" value="<?php echo $nightEnd; ?>" class="form-input" style="width:90px;" title="<?php echo $this->t('admin.theme_night_end'); ?>">
                    </div>
                    <p class="c-666 mn-fs-12"><?php echo $this->t('admin.theme_night_window_hint'); ?></p>
                </div>
                <div class="form-group">
                    <label class="checkbox-inline">
                        <input type="checkbox" name="list_excerpt" value="1" <?php echo $listExcerptDefault ? 'checked' : ''; ?>> <?php echo $this->t('admin.theme_list_excerpt'); ?>
                    </label>
                    <p class="c-666 mn-fs-12"><?php echo $this->t('admin.theme_list_excerpt_hint'); ?></p>
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('admin.theme_list_excerpt_len'); ?></label>
                    <input type="number" name="list_excerpt_len" min="50" max="200" value="<?php echo $listExcerptLenDefault; ?>" class="form-input" style="width:90px;">
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('admin.theme_home_mode'); ?></label>
                    <select name="home_mode" class="form-input" style="max-width:220px;">
                        <option value="portal"<?php echo $homeModeDefault === 'portal' ? ' selected' : ''; ?>><?php echo $this->t('admin.theme_home_mode_portal'); ?></option>
                        <option value="community"<?php echo $homeModeDefault === 'community' ? ' selected' : ''; ?>><?php echo $this->t('admin.theme_home_mode_community'); ?></option>
                    </select>
                    <p class="c-666 mn-fs-12"><?php echo $this->t('admin.theme_home_mode_hint'); ?></p>
                </div>
                <button type="submit" class="btn btn-primary"><?php echo $this->t('admin.save_style'); ?></button>
            </form>
        </div>

        <!-- 自定义 CSS -->
        <div class="admin-section admin-form-md">
            <h3 class="admin-section-title"><?php echo $this->t('admin.custom_css'); ?></h3>
            <p class="c-666 mn-fs-12 mn-mb-15"><?php echo $this->t('admin.custom_css_hint'); ?></p>
            <form method="POST" action="<?php echo $this->url('/admin/themes/css'); ?>">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <div class="form-group">
                    <textarea name="custom_css" rows="12" class="form-input code-input"><?php echo $this->e($customCss); ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary"><?php echo $this->t('admin.save_style'); ?></button>
            </form>
        </div>
    </main>
</div>
<?php $this->endSection(); ?>