<?php
/**
 * Modern 主题 — 论坛首页
 * 三栏：左栏分类 + 中栏帖子列表 + 右栏信息
 */
$latestThreads = $this->getData('latestThreads', []);
$threadVotes = $this->getData('threadVotes', []);
$categories = $this->getData('categories', []);
$tags = $this->getData('tags', []);
$page = (int)$this->getData('page', 1);
$totalPages = (int)$this->getData('totalPages', 1);
$total = (int)$this->getData('total', 0);
$type = $this->getData('type', 'latest');
$isLoggedIn = $this->getData('isLoggedIn');
$showExcerpt = (bool)$this->getData('showExcerpt', false);
?>
<?php $this->section('title'); ?><?php if ($this->getData('is_home_delegate')): ?><?php echo $this->e($this->getData('siteName')); ?><?php else: ?><?php echo $this->t('forum.title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php endif; ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>

<!-- ====== 公告 ====== -->
<?php
\app\Helpers\Plugin::hook('announcements_display');
?>

<?php
$pinnedThreads = [];
$normalThreads = [];
foreach ($latestThreads as $thread) {
    if (!empty($thread['is_pinned'])) {
        $pinnedThreads[] = $thread;
    } else {
        $normalThreads[] = $thread;
    }
}
?>

<?php if (!empty($pinnedThreads)): ?>
<!-- ====== 置顶主题 ====== -->
<div class="mn-section" style="margin-bottom:10px;">
    <div class="mn-section-header">
        <div class="mn-flex-center mn-gap-8">
            <h2 class="mn-fs-13"><?php echo $this->icon('pinned', 14); ?> <?php echo $this->t('forum.pinned'); ?></h2>
        </div>
    </div>
    <?php foreach ($pinnedThreads as $thread):
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
        <?php if (!$hasExcerpt): ?>
        <div class="mn-row-avatar">
                            <?php if ($avatarPath): ?>
                                <img src="<?php echo $this->e(\UPLOAD_URL . $avatarPath); ?>" alt="" class="mn-row-avatar-img">
                            <?php else: ?>
                                <div class="mn-row-avatar-placeholder"><?php echo $this->e(mb_substr($username, 0, 1)); ?></div>
                            <?php endif; ?>
                        </div>
        <?php endif; ?>
        <div class="mn-row-main">
            <div class="mn-row-title">
                <span class="mn-tag mn-tag-hot"><?php echo $this->icon('pinned', 10); ?> <?php echo $this->t('common.pinned'); ?></span>
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
        <div class="mn-row-right">
            <a href="<?php echo $this->url('/forum/category/' . (int)($thread['category_id'] ?? 0)); ?>" class="mn-tag mn-tag-cat"><?php echo $this->e($thread['category_name'] ?? ''); ?></a>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ====== 论坛帖子列表 ====== -->
<div class="mn-section">
    <div class="mn-section-header">
        <div class="mn-flex-center mn-gap-8">
            <h2><?php echo $this->icon('forum', 16); ?> <?php echo $this->t('forum.title'); ?></h2>
        </div>
        <div class="mn-flex-center mn-gap-4">
            <a href="<?php echo $this->url('/forum'); ?>" class="mn-section-tab <?php echo $type === 'latest' ? 'mn-active' : ''; ?>"><?php echo $this->t('forum.latest'); ?></a>
            <a href="<?php echo $this->url('/forum?type=highlighted'); ?>" class="mn-section-tab <?php echo $type === 'highlighted' ? 'mn-active' : ''; ?>"><?php echo $this->t('forum.highlighted'); ?></a>
        </div>
    </div>

    <?php if (empty($normalThreads)): ?>
    <div class="mn-empty">
        <p><?php echo $type === 'highlighted' ? $this->t('forum.no_highlighted') : $this->t('forum.no_threads'); ?></p>
        <?php if ($isLoggedIn): ?>
        <p class="mn-mt-8"><a href="<?php echo $this->url('/post/new'); ?>" class="mn-btn mn-btn-primary"><?php echo $this->icon('new-post', 12); ?> <?php echo $this->t('thread.new_thread'); ?></a></p>
        <?php endif; ?>
    </div>
    <?php else: ?>
        <?php foreach ($normalThreads as $thread):
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
            <?php if (!$hasExcerpt): ?>
            <div class="mn-row-avatar">
                                <?php if ($avatarPath): ?>
                                    <img src="<?php echo $this->e(\UPLOAD_URL . $avatarPath); ?>" alt="" class="mn-row-avatar-img">
                                <?php else: ?>
                                    <div class="mn-row-avatar-placeholder"><?php echo $this->e(mb_substr($username, 0, 1)); ?></div>
                                <?php endif; ?>
                            </div>
            <?php endif; ?>
            <div class="mn-row-main">
                <div class="mn-row-title">
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
            <div class="mn-row-right">
                <a href="<?php echo $this->url('/forum/category/' . (int)($thread['category_id'] ?? 0)); ?>" class="mn-tag mn-tag-cat"><?php echo $this->e($thread['category_name'] ?? ''); ?></a>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- 分页 -->
        <?php
        // 分页：上一页/下一页携带 keyset 游标（after/before），页码数字直达走 OFFSET；
        //   游标模式下 $page 恒为 1；边界守卫：第 1 页不提供 prevUrl、最后一页不提供 nextUrl
        $cursorNext = $this->getData('cursorNext');
        $cursorPrev = $this->getData('cursorPrev');
        $cursorMode = (bool)$this->getData('cursorMode', false);
        $prevPage = max(1, $page - 1);
        $nextPage = $page + 1;
        $pagerOpts = [];
        if ($cursorPrev !== null && ($cursorMode || $page > 1)) {
            $pagerOpts['prevUrl'] = $this->url('/forum' . ($type === 'highlighted' ? '?type=highlighted&amp;' : '?') . 'page=' . $prevPage . '&amp;before=' . implode('-', $cursorPrev));
        }
        if ($cursorNext !== null && ($cursorMode || $page < $totalPages)) {
            $pagerOpts['nextUrl'] = $this->url('/forum' . ($type === 'highlighted' ? '?type=highlighted&amp;' : '?') . 'page=' . $nextPage . '&amp;after=' . implode('-', $cursorNext));
        }
        echo $this->pagination($page, $totalPages, $type === 'highlighted' ? '/forum?type=highlighted&amp;page={page}' : '/forum?page={page}', $pagerOpts);
        ?>
    <?php endif; ?>
</div>

<!-- ====== 浮动操作按钮 ====== -->
<div class="mn-flex-col mn-flex-center thread-float-bar">
    <a href="<?php echo $this->url('/'); ?>" class="mn-btn mn-btn-sm mn-rounded-50 mn-flex-center thread-float-btn" title="<?php echo $this->t('common.back'); ?>">
        <?php echo $this->icon('back', 18); ?>
        <span class="mn-desktop-only mn-d-none"><?php echo $this->t('common.back'); ?></span>
    </a>
    <?php if ($isLoggedIn): ?>
    <a href="<?php echo $this->url('/post/new'); ?>" class="mn-btn mn-btn-sm mn-rounded-50 mn-flex-center thread-float-btn" title="<?php echo $this->t('forum.new_thread'); ?>">
        <?php echo $this->icon('new-post', 18); ?>
        <span class="mn-desktop-only mn-d-none"><?php echo $this->t('forum.new_thread'); ?></span>
    </a>
    <?php endif; ?>
</div>

<?php $this->endSection(); ?>