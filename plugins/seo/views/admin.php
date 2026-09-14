<?php
/**
 * SEO 设置管理后台视图
 * @file app/Views/plugins/seo/admin.php
 */
?>
<?php $this->section('title'); ?><?php echo \app\Helpers\I18n::get('plugin.seo.title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<link rel="stylesheet" href="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/seo/assets/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/style.css') ?: 1; ?>">
<div class="admin-container">
    <?php $this->include('admin/_sidebar'); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1>🔍 <?php echo \app\Helpers\I18n::get('plugin.seo.title'); ?></h1>
        </div>

        <?php $error = $this->getData('error', ''); $success = $this->getData('success', ''); ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo $this->e($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo $this->e($success); ?></div><?php endif; ?>

        <div class="admin-section seo-settings">
            <form method="POST" action="<?php echo $this->url('/admin/seo/save'); ?>">
                <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">

                <div class="seo-section">
                    <h3>📝 <?php echo \app\Helpers\I18n::get('plugin.seo.meta_section'); ?></h3>
                    <div class="form-group">
                        <label><?php echo \app\Helpers\I18n::get('plugin.seo.meta_keywords'); ?></label>
                        <input type="text" name="meta_keywords" value="<?php echo $this->e($settings['meta_keywords'] ?? ''); ?>" class="form-input" placeholder="关键词1, 关键词2, 关键词3">
                        <div class="form-hint"><?php echo \app\Helpers\I18n::get('plugin.seo.meta_keywords_hint'); ?></div>
                    </div>
                    <div class="form-group">
                        <label><?php echo \app\Helpers\I18n::get('plugin.seo.meta_description'); ?></label>
                        <textarea name="meta_description" rows="3" class="form-input" placeholder="<?php echo $this->e(\app\Helpers\I18n::get('plugin.seo.meta_description_ph')); ?>"><?php echo $this->e($settings['meta_description'] ?? ''); ?></textarea>
                        <div class="form-hint"><?php echo \app\Helpers\I18n::get('plugin.seo.meta_description_hint'); ?></div>
                    </div>
                </div>

                <div class="seo-section">
                    <h3>⚙️ <?php echo \app\Helpers\I18n::get('plugin.seo.head_section'); ?></h3>
                    <div class="form-group">
                        <label><?php echo \app\Helpers\I18n::get('plugin.seo.custom_head'); ?></label>
                        <textarea name="custom_head" rows="4" class="form-input" placeholder="&lt;meta name=&quot;google-site-verification&quot; content=&quot;...&quot;&#10;&lt;script src=&quot;https://example.com/analytics.js&quot;&gt;&lt;/script&gt;"><?php echo $this->e($settings['custom_head'] ?? ''); ?></textarea>
                        <div class="form-hint"><?php echo \app\Helpers\I18n::get('plugin.seo.custom_head_hint'); ?></div>
                    </div>
                </div>

                <div class="seo-section seo-section-info">
                    <h3>🔗 <?php echo \app\Helpers\I18n::get('plugin.seo.links_section'); ?></h3>
                    <div class="seo-links-text">
                        <div>📍 站点地图：<a href="/sitemap.xml" target="_blank"><?php echo $this->e($siteUrl ?? ''); ?>/sitemap.xml</a></div>
                        <div>🤖 robots.txt：<a href="/robots.txt" target="_blank"><?php echo $this->e($siteUrl ?? ''); ?>/robots.txt</a></div>
                    </div>
                </div>

                <div class="seo-save-row">
                    <button type="submit" class="btn btn-primary"><?php echo \app\Helpers\I18n::get('plugin.seo.save'); ?></button>
                </div>
            </form>
        </div>
    </main>
</div>
<?php $this->endSection(); ?>
