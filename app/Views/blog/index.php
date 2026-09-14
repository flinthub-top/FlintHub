<?php
/**
 * 博客列表视图 — 博客中心、分类筛选、归档、分页
 * @file app/Views/blog/index.php
 */
$blogs = $this->getData('blogs', []);
$blogCategories = $this->getData('blogCategories', []);
$page = (int)$this->getData('page', 1);
$totalPages = (int)$this->getData('totalPages', 1);
$categoryId = (int)$this->getData('categoryId', 0);
$archives = $this->getData('archives', []);
$archive = $this->getData('archive', '');
$totalBlogsCount = (int)$this->getData('totalBlogsCount', 0);
$totalCategories = (int)$this->getData('totalCategories', 0);
$currentUri = $_SERVER['REQUEST_URI'] ?? '/';
?>
<?php $this->section('title'); ?><?php if ($this->getData('is_home_delegate')): ?><?php echo $this->e($this->getData('siteName')); ?><?php else: ?><?php echo $this->t('blog.title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php endif; ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<!-- ====== 公告 ====== -->
<?php
\app\Helpers\Plugin::hook('announcements_display');
?>
<div class="mn-section">
    <div class="mn-section-header">
        <h2><?php echo $this->icon('blog', 16); ?> <?php echo $this->t('blog.center'); ?></h2>
        <?php if ($this->getData('isAdmin')): ?>
        <a href="<?php echo $this->url('/blog/new'); ?>" class="mn-btn mn-btn-primary mn-btn-sm"><?php echo $this->icon('write', 12); ?> <?php echo $this->t('blog.new'); ?></a>
        <?php endif; ?>
    </div>
    <!-- 移动端博客分类横向滚动条 -->
    <div class="mn-mobile-cats">
        <a href="<?php echo $this->url('/blog'); ?>" class="<?php echo $categoryId === 0 ? 'mn-mobile-cat-active' : ''; ?>"><?php echo $this->t('common.all'); ?></a>
        <?php foreach ($blogCategories as $bcat): ?>
        <a href="<?php echo $this->url('/blog/category/' . (int)($bcat['id'] ?? 0)); ?>" class="<?php echo (int)($bcat['id'] ?? 0) === $categoryId ? 'mn-mobile-cat-active' : ''; ?>"><?php echo $this->e($bcat['name'] ?? ''); ?></a>
        <?php endforeach; ?>
    </div>
    <?php if (empty($blogs)): ?>
    <div class="mn-empty"><?php echo $this->t('blog.no_posts'); ?></div>
    <?php else: ?>
        <?php foreach ($blogs as $blog):
            $bid = (int)($blog['id'] ?? 0);
            $username = $this->e($blog['username'] ?? '');
            $avatarPath = $blog['avatar'] ?? '';
            $title = $this->e($blog['title'] ?? '');
            $excerpt = $this->e(mb_substr(strip_tags($blog['content'] ?? ''), 0, 150));
            $cover = $blog['cover_image'] ?? '';
        ?>
        <div class="mn-blog-item">
            <div class="mn-flex mn-blog-row mn-gap-10 mn-flex-nowrap">
                <?php if (!empty($cover)): ?>
                <a href="<?php echo $this->url('/blog/' . $bid); ?>" class="mn-blog-cover" style="background-image:url('<?php echo $this->e(\UPLOAD_URL . $cover); ?>');"></a>
                <?php endif; ?>
                <div class="mn-flex-1">
                    <div class="mn-blog-title">
                        <a href="<?php echo $this->url('/blog/' . $bid); ?>"><?php echo $title; ?></a>
                    </div>
                    <div class="mn-blog-excerpt"><?php echo $excerpt; ?>...</div>
                    <div class="mn-blog-meta">
                        <?php if (!empty($blog['category_name'])): ?>
                            <a href="<?php echo $this->url('/blog/category/' . (int)($blog['category_id'] ?? 0)); ?>" class="mn-tag mn-tag-cat"><?php echo $this->e($blog['category_name']); ?></a>
                        <?php endif; ?>
                        <span class="mn-row-author">
                            <?php if ($avatarPath): ?>
                                <img src="<?php echo $this->e(\UPLOAD_URL . $avatarPath); ?>" alt="" class="mn-wp-18 mn-hp-18 mn-rounded-4 mn-object-cover">
                            <?php endif; ?>
                            <?php echo $username; ?>
                        </span>
                        <span><?php echo $this->icon('time', 11); ?> <?php echo $this->timeAgo($blog['created_at'] ?? ''); ?></span>
                        <span><?php echo $this->icon('views', 11); ?> <?php echo (int)($blog['view_count'] ?? 0); ?></span>
                        <?php if ((int)($blog['comment_count'] ?? 0) > 0): ?>
                        <span><?php echo $this->icon('reply', 11); ?> <?php echo (int)($blog['comment_count'] ?? 0); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php echo $this->pagination($page, $totalPages, '/blog' . ($categoryId ? '/category/' . $categoryId : '') . '?page={page}'); ?>
    <?php endif; ?>
</div>

<!-- ====== 浮动操作按钮 ====== -->
<div class="mn-flex-col mn-flex-center thread-float-bar">
    <a href="<?php echo $this->url('/'); ?>" class="mn-btn mn-btn-sm mn-rounded-50 mn-flex-center thread-float-btn" title="<?php echo $this->t('common.back'); ?>">
        <?php echo $this->icon('back', 18); ?>
        <span class="mn-desktop-only mn-d-none"><?php echo $this->t('common.back'); ?></span>
    </a>
</div>
<?php $this->endSection(); ?>