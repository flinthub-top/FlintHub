<?php
/**
 * Modern 主题 — 首页
 * 三栏：左栏分类 + 中栏内容 + 右栏信息
 */
$latestThreads = $this->getData('latestThreads', []);
$latestBlogs = $this->getData('latestBlogs', []);
$threadVotes = $this->getData('threadVotes', []);
$isLoggedIn = $this->getData('isLoggedIn');
$showExcerpt = (bool)$this->getData('showExcerpt', false);
$currentUri = $_SERVER['REQUEST_URI'] ?? '/';
?>
<?php $this->section('title'); ?><?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>

<!-- ====== 公告 ====== -->
<?php
\app\Helpers\Plugin::hook('announcements_display');
?>

<!-- ====== 论坛社区 ====== -->
<div class="mn-section">
    <div class="mn-section-header">
        <div class="mn-flex-center mn-gap-8">
            <h2><?php echo $this->icon('forum', 16); ?> <?php echo $this->t('forum.title'); ?></h2>
        </div>
        <a href="<?php echo $this->url('/forum'); ?>" class="mn-section-more"><?php echo $this->icon('forward', 12); ?> <?php echo $this->t('common.more'); ?></a>
    </div>

    <?php if (empty($latestThreads)): ?>
    <div class="mn-empty"><?php echo $this->t('forum.no_threads'); ?></div>
    <?php else: ?>
        <?php foreach ($latestThreads as $thread):
            $tid = (int)($thread['id'] ?? 0);
            $username = $this->e($thread['username'] ?? '');
            $avatarPath = $thread['avatar'] ?? '';
            $titleColor = !empty($thread['color']) ? $thread['color'] : '';
            $level = (int)($thread['level'] ?? 1);
            // 有摘要的行：大头像收起、改在元信息前显示小头像（博客列表样式）；无摘要保持原布局（2026-09-05）
            $thumbs = $thread['excerpt_images'] ?? [];
            $hasExcerpt = $showExcerpt && (!empty($thread['excerpt']) || !empty($thumbs));
        ?>
        <div class="mn-row">
            <!-- 左侧头像 -->
            <?php if (!$hasExcerpt): ?>
            <div class="mn-row-avatar">
                <?php if ($avatarPath): ?>
                    <img src="<?php echo $this->e(\UPLOAD_URL . $avatarPath); ?>" alt="" class="mn-row-avatar-img">
                <?php else: ?>
                    <div class="mn-row-avatar-placeholder"><?php echo $this->e(mb_substr($username, 0, 1)); ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- 中间标题+元信息 -->
            <div class="mn-row-main">
                <div class="mn-row-title">
                    <?php if (!empty($thread['is_pinned'])): ?><span class="mn-tag mn-tag-hot"><?php echo $this->icon('pinned', 10); ?> <?php echo $this->t('common.pinned'); ?></span><?php endif; ?>
                    <?php if (!empty($thread['is_highlighted'])): ?><span class="mn-tag mn-tag-elite"><?php echo $this->icon('star', 10); ?> <?php echo $this->t('forum.highlighted'); ?></span><?php endif; ?>
                    <?php if (!empty($thread['_pending_review'])): ?><span class="mn-tag mn-tag-warning"><?php echo $this->t('common.pending_review'); ?></span><?php endif; ?>
                    <a href="<?php echo $this->url('/thread/' . $tid); ?>"<?php if ($titleColor): ?> style="color:<?php echo $this->e($titleColor); ?>;font-weight:600;"<?php endif; ?>>
                        <?php echo $this->e($thread['title'] ?? ''); ?>
                    </a>
                </div>
                <?php if ($hasExcerpt): ?>
                <div class="mn-row-excerpt">
                    <?php if (!empty($thread['excerpt'])): ?><span class="mn-row-excerpt-text"><?php echo $this->e($thread['excerpt']); ?>…</span><?php endif; ?>
                    <?php if (!empty($thumbs)): ?>
                    <span class="mn-excerpt-thumbs<?php echo count($thumbs) === 1 ? ' mn-excerpt-thumbs-single' : ''; ?>">
                        <?php foreach ($thumbs as $imgUrl): ?>
                        <img src="<?php echo $this->e($imgUrl); ?>" alt="" class="mn-excerpt-thumb" loading="lazy">
                        <?php endforeach; ?>
                    </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="mn-row-meta">
                    <a href="<?php echo $this->url('/forum/category/' . (int)($thread['category_id'] ?? 0)); ?>" class="mn-tag mn-tag-cat mn-cat-mobile"><?php echo $this->e($thread['category_name'] ?? ''); ?></a>
                    <?php if ($hasExcerpt): ?>
                    <span class="mn-row-meta-avatar">
                        <?php if ($avatarPath): ?>
                            <img src="<?php echo $this->e(\UPLOAD_URL . $avatarPath); ?>" alt="" class="mn-wp-18 mn-hp-18 mn-rounded-4 mn-object-cover" loading="lazy">
                        <?php else: ?>
                            <span class="mn-row-meta-avatar-ph"><?php echo $this->e(mb_substr($username, 0, 1)); ?></span>
                        <?php endif; ?>
                    </span>
                    <?php endif; ?>
                    <span class="mn-row-author"><?php echo $username; ?></span>
                    <?php $ulListBadges = $this->getData('ulListBadges', []); echo $ulListBadges[(int)($thread['user_id'] ?? 0)] ?? ''; ?>
                    <span class="mn-row-stat"><?php echo $this->icon('time', 11); ?> <?php echo $this->timeAgo($thread['created_at'] ?? ''); ?></span>
                    <span class="mn-row-stat mn-hide-mobile"><?php echo $this->icon('views', 11); ?> <?php echo (int)($thread['view_count'] ?? 0); ?></span>
                    <span class="mn-row-stat"><?php echo $this->icon('reply', 11); ?> <?php echo (int)($thread['reply_count'] ?? 0); ?></span>
                </div>
            </div>

            <!-- 右侧：分类 -->
            <div class="mn-row-right">
                <a href="<?php echo $this->url('/forum/category/' . (int)($thread['category_id'] ?? 0)); ?>" class="mn-tag mn-tag-cat"><?php echo $this->e($thread['category_name'] ?? ''); ?></a>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ====== 博客中心 ====== -->
<?php if (!empty($latestBlogs)): ?>
<div class="mn-section">
    <div class="mn-section-header">
        <div class="mn-flex-center mn-gap-8">
            <h2><?php echo $this->icon('blog', 16); ?> <?php echo $this->t('blog.title'); ?></h2>
        </div>
        <a href="<?php echo $this->url('/blog'); ?>" class="mn-section-more"><?php echo $this->icon('forward', 12); ?> <?php echo $this->t('common.more'); ?></a>
    </div>

    <?php foreach ($latestBlogs as $blog):
        $bid = (int)($blog['id'] ?? 0);
        $username = $this->e($blog['username'] ?? '');
        $avatarPath = $blog['avatar'] ?? '';
        $title = $this->e($blog['title'] ?? '');
        $excerpt = $this->e(mb_substr(strip_tags($blog['content'] ?? ''), 0, 120));
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
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php $this->endSection(); ?>