<?php
/**
 * 后台基本信息设置视图
 * @file app/Views/admin/settings_basic.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.nav_basic'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php $settings = $this->getData('settings', []); $csrfToken = $this->e($this->getData('csrfToken'));
$msg = $this->getData('msg', ''); ?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'settings_basic']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('id-card', 16); ?> <?php echo $this->t('admin.nav_basic'); ?></h1>
        </div>
        <?php if ($msg): ?><div class="alert alert-success"><?php echo $this->e($msg); ?></div><?php endif; ?>
        <?php // 建议扩容提示（≥80% 命中时显示）
        $suggestExpand = $this->getData('suggestExpand', []);
        if (!empty($suggestExpand['suggest'])): ?>
        <div class="alert alert-warning">
            ⚠️ <b><?php echo $this->t('admin.suggest_expand_title'); ?></b>：<?php echo $this->t('admin.suggest_expand_hint', ['load' => number_format((int)$suggestExpand['maxLoad']), 'capacity' => number_format((int)$suggestExpand['capacity']), 'ratio' => (int)$suggestExpand['ratio']]); ?>
            <?php echo $this->t('admin.suggest_expand_action'); ?><a href="<?php echo $this->url('/admin/database/settings'); ?>"><?php echo $this->t('admin.suggest_expand_go'); ?></a>
        </div>
        <?php endif; ?>
        <div class="admin-section admin-form-lg">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">

                <div class="form-group">
                    <label><?php echo $this->t('admin.site_name'); ?></label>
                    <input type="text" name="site_name" value="<?php echo $this->e($settings['site_name'] ?? ''); ?>" class="form-input">
                </div>
                <div class="form-group">
                    <label><?php echo $this->t('admin.site_description'); ?></label>
                    <textarea name="site_description" rows="3" class="form-input"><?php echo $this->e($settings['site_description'] ?? ''); ?></textarea>
                </div>

                <!-- [i18n] 默认语言：影响语言判定顺序第 4 项（URL ?lang= > Cookie > Session > 后台默认 > 浏览器 > 中文兜底） -->
                <div class="form-group">
                    <label><?php echo $this->t('admin.site_lang'); ?></label>
                    <select name="site_lang" class="form-input">
                        <?php foreach (\app\Helpers\I18n::available() as $lang): ?>
                        <option value="<?php echo $this->e($lang); ?>" <?php echo (($settings['site_lang'] ?? \app\Helpers\I18n::DEFAULT_LANG) === $lang) ? 'selected' : ''; ?>><?php echo $this->t('lang.' . $lang); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mn-fs-12 c-999 mn-mt-4"><?php echo $this->t('admin.site_lang_hint'); ?></p>
                </div>

                <hr class="admin-hr">
                <h3 class="admin-section-title"><?php echo $this->t('admin.deploy_settings'); ?></h3>

                <div class="form-group">
                    <label><?php echo $this->t('admin.site_base_path'); ?></label>
                    <input type="text" name="base_path" value="<?php echo $this->e($settings['base_path'] ?? ''); ?>" class="form-input" placeholder="<?php echo $this->t('admin.site_base_path_placeholder'); ?>">
                    <p class="mn-fs-12 c-999 mn-mt-4"><?php echo $this->t('admin.site_base_path_hint'); ?></p>
                </div>

                <button type="submit" class="btn btn-primary"><?php echo $this->t('admin.save_settings'); ?></button>
            </form>
        </div>
    </main>
</div>
<?php $this->endSection(); ?>