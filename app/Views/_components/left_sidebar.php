<?php
/**
 * Modern 主题 — 左栏组件
 * 首页：论坛 + 博客 + 应用；论坛页：论坛 + 应用；博客页：博客 + 应用；其他页：仅应用
 */
$categories = $this->getData('categories', []);
$currentUri = $_SERVER['REQUEST_URI'] ?? '/';
$isForum = preg_match('#^/forum#', $currentUri) || preg_match('#^/thread#', $currentUri) || preg_match('#^/post#', $currentUri);
$isBlog = preg_match('#^/blog#', $currentUri);
$isHome = $currentUri === '/' || preg_match('#^\?preview_theme#', $currentUri) || preg_match('#^/\?preview_theme#', $currentUri);
// 按站点模式决定是否显示论坛/博客模块（单模式下首页即对应模块页）
$siteModeLeft = \app\Helpers\Settings::get('site_mode', 'portal');
$showForumModule = $isForum || ($isHome && $siteModeLeft !== 'blog');
$showBlogModule = $isBlog || ($isHome && $siteModeLeft !== 'forum');
$showBlogArchive = $isBlog || ($siteModeLeft === 'blog' && $isHome);
// 详情页（/thread/{id}、/blog/{id}）直接读视图数据中的分类（无则回退 URL 匹配）
$detailThread = $this->getData('thread');
$detailBlog = $this->getData('blog');
$currentForumCatId = !empty($detailThread) ? (int)($detailThread['category_id'] ?? 0) : 0;
$currentBlogCatId = !empty($detailBlog) ? (int)($detailBlog['category_id'] ?? 0) : 0;
?>
<?php if ($showForumModule): ?>
<!-- 社区论坛 -->
<div class="mn-panel">
    <h3 class="mn-panel-title"><?php echo $this->icon('forum', 12); ?> <?php echo $this->t('sidebar.forum_community'); ?></h3>
    <ul class="mn-cat-list">
        <li><a href="<?php echo $this->url('/forum'); ?>" class="<?php echo !$currentForumCatId && !preg_match('#^/forum/category/\d+#', $currentUri) && ($isForum || $isHome) ? 'mn-cat-active' : ''; ?>"><?php echo $this->icon('forum', 14); ?> <?php echo $this->t('forum.category_all'); ?></a></li>
        <?php if (!empty($categories)): ?>
            <?php foreach ($categories as $cat): ?>
            <?php $catUrl = '/forum/category/' . (int)($cat['id'] ?? 0); ?>
            <li>
                <a href="<?php echo $this->url($catUrl); ?>" class="<?php echo $currentForumCatId ? ((int)$cat['id'] === $currentForumCatId ? 'mn-cat-active' : '') : (strpos($currentUri, $catUrl) === 0 ? 'mn-cat-active' : ''); ?>">
                    <?php if (!empty($cat['show_icon'])): ?><?php echo $this->icon($cat['icon'] ?? 'forum', 14); ?> <?php endif; ?>
                    <?php echo $this->e($cat['name'] ?? ''); ?>
                </a>
            </li>
            <?php endforeach; ?>
        <?php endif; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($showBlogModule): ?>
<!-- 社区博客 -->
<div class="mn-panel">
    <h3 class="mn-panel-title"><?php echo $this->icon('blog', 12); ?> <?php echo $this->t('sidebar.blog_community'); ?></h3>
    <ul class="mn-cat-list">
        <li><a href="<?php echo $this->url('/blog'); ?>" class="<?php echo !$currentBlogCatId && !preg_match('#^/blog/category/\d+#', $currentUri) && !isset($_GET['archive']) ? 'mn-cat-active' : ''; ?>"><?php echo $this->icon('blog', 12); ?> <?php echo $this->t('sidebar.all_blogs'); ?></a></li>
        <?php
        $blogCategories = $this->getData('blogCategories', []);
        if (empty($blogCategories)) {
            $blogCategories = $this->getData('categories', []);
        }
        // 从 URL 路径中提取当前分类 ID，兼容 /blog/category/{id} 格式
        $currentCatId = 0;
        if (preg_match('#^/blog/category/(\d+)#', $currentUri, $m)) {
            $currentCatId = (int)$m[1];
        }
        if (!empty($blogCategories)):
            foreach ($blogCategories as $bcat):
        ?>
        <li><a href="<?php echo $this->url('/blog/category/' . (int)($bcat['id'] ?? 0)); ?>" class="<?php echo $currentBlogCatId ? ((int)($bcat['id'] ?? 0) === $currentBlogCatId ? 'mn-cat-active' : '') : ((int)($bcat['id'] ?? 0) === $currentCatId ? 'mn-cat-active' : ''); ?>"><?php echo $this->e($bcat['name'] ?? ''); ?></a></li>
        <?php
            endforeach;
        endif;
        ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($showBlogArchive): ?>
<!-- 博客归档 -->
<div class="mn-panel">
    <h3 class="mn-panel-title"><?php echo $this->icon('history', 12); ?> <?php echo $this->t('sidebar.blog_archive'); ?></h3>
    <ul class="mn-cat-list">
        <?php
        $archives = $this->getData('archives', []);
        $currentArchive = $_GET['archive'] ?? '';
        if (!empty($archives)):
            foreach ($archives as $arc):
                $month = $arc['month'] ?? '';
                $count = (int)($arc['cnt'] ?? 0);
                if ($month):
        ?>
        <li><a href="<?php echo $this->url('/blog?archive=' . $this->e($month)); ?>" class="<?php echo $month === $currentArchive ? 'mn-cat-active' : ''; ?>"><?php echo $this->e($month); ?> <span class="mn-cat-count"><?php echo $count; ?></span></a></li>
        <?php
                endif;
            endforeach;
        endif;
        ?>
    </ul>
</div>
<?php endif; ?>

<!-- 应用 -->
<div class="mn-panel">
    <h3 class="mn-panel-title"><?php echo $this->icon('plugin', 12); ?> <?php echo $this->t('sidebar.apps'); ?></h3>
    <ul class="mn-cat-list">
    <?php
\app\Helpers\Plugin::hook('nav_plugin_links', ['__nav_uri' => $currentUri]);
?>
    </ul>
</div>