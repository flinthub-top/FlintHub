<?php
/**
 * 搜索视图 — 关键词检索帖子/博客/用户、分页
 * @file app/Views/search/index.php
 */
$keyword = $this->getData('keyword', '');
$type = $this->getData('type', 'all');
$timeRange = $this->getData('timeRange', 'all'); // all / 30d / 180d / 365d
$results = $this->getData('results', []);
$threadResults = $this->getData('threadResults', []);
$blogResults = $this->getData('blogResults', []);
$userResults = $this->getData('userResults', []);
$threadTotal = (int)$this->getData('threadTotal', 0);
$blogTotal = (int)$this->getData('blogTotal', 0);
$userTotal = (int)$this->getData('userTotal', 0);
$page = (int)$this->getData('page', 1);
$totalPages = (int)$this->getData('totalPages', 1);
$count = (int)$this->getData('count', 0);
?>
<?php $this->section('title'); ?><?php echo $this->t('search.title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section">
    <div class="mn-section-header">
        <h2><?php echo $this->icon('search', 16); ?> <?php echo $this->t('search.title'); ?></h2>
    </div>
    <div class="mn-p-20">
        <form action="<?php echo $this->url('/search'); ?>" method="GET" class="mn-flex-center mn-gap-10 mn-mb-20">
            <input type="text" name="keyword" value="<?php echo $this->e($keyword); ?>" placeholder="<?php echo $this->t('search.placeholder'); ?>" required class="mn-input mn-maxw-400">
            <input type="hidden" name="type" value="<?php echo $this->e($type); ?>">
            <select name="time_range" class="mn-input mn-w-auto">
                <option value="all" <?php echo $timeRange === 'all' ? 'selected' : ''; ?>><?php echo $this->t('search.time_all'); ?></option>
                <option value="30d" <?php echo $timeRange === '30d' ? 'selected' : ''; ?>><?php echo $this->t('search.time_30d'); ?></option>
                <option value="180d" <?php echo $timeRange === '180d' ? 'selected' : ''; ?>><?php echo $this->t('search.time_180d'); ?></option>
                <option value="365d" <?php echo $timeRange === '365d' ? 'selected' : ''; ?>><?php echo $this->t('search.time_365d'); ?></option>
            </select>
            <button type="submit" class="mn-btn mn-btn-primary"><?php echo $this->icon('search', 12); ?><span class="mn-desktop-only"> <?php echo $this->t('common.search'); ?></span></button>
        </form>
        <?php if ($keyword !== ''): ?>
        <div class="mn-flex-center mn-gap-4 mn-mb-16 mn-flex-wrap">
            <a href="?keyword=<?php echo urlencode($keyword); ?>&type=all&time_range=<?php echo urlencode($timeRange); ?>" class="mn-section-tab <?php echo $type === 'all' ? 'mn-active' : ''; ?>"><?php echo $this->t('common.all'); ?> (<?php echo $threadTotal + $blogTotal; ?>)</a>
            <a href="?keyword=<?php echo urlencode($keyword); ?>&type=threads&time_range=<?php echo urlencode($timeRange); ?>" class="mn-section-tab <?php echo $type === 'threads' ? 'mn-active' : ''; ?>"><?php echo $this->t('search.threads'); ?> (<?php echo $threadTotal; ?>)</a>
            <a href="?keyword=<?php echo urlencode($keyword); ?>&type=blogs&time_range=<?php echo urlencode($timeRange); ?>" class="mn-section-tab <?php echo $type === 'blogs' ? 'mn-active' : ''; ?>"><?php echo $this->t('search.blogs'); ?> (<?php echo $blogTotal; ?>)</a>
        </div>

        <?php if ($count === 0): ?>
        <div class="mn-empty"><?php echo $this->t('search.no_results_for', ['keyword' => $this->e($keyword)]); ?></div>
        <?php else: ?>

            <?php if ($type === 'all'): ?>
            <!-- ====== 全部模式：分类预览，不分页 ====== -->

            <?php if (!empty($threadResults)): ?>
            <div class="mn-mb-24">
                <div class="mn-flex-center mn-gap-8 mn-mb-12">
                    <h3 class="mn-fs-14 mn-fw-600"><?php echo $this->icon('forum', 14); ?> <?php echo $this->t('search.threads'); ?></h3>
                    <?php if ($threadTotal > count($threadResults)): ?>
                    <a href="?keyword=<?php echo urlencode($keyword); ?>&type=threads" class="mn-fs-12 mn-text-link" style="margin-left:auto;"><?php echo $this->t('search.view_all', ['count' => $threadTotal]); ?> →</a>
                    <?php endif; ?>
                </div>
                <?php foreach ($threadResults as $item): ?>
                <div class="mn-row mn-py-10">
                    <div class="mn-row-main">
                        <div class="mn-row-title">
                            <?php if (!empty($item['is_pinned'])): ?><span class="mn-tag mn-tag-pin" style="font-size:12px;padding:1px 6px;"><?php echo $this->t('common.pinned'); ?></span><?php endif; ?>
                            <?php if (!empty($item['is_highlighted'])): ?><span class="mn-tag mn-tag-hot" style="font-size:12px;padding:1px 6px;"><?php echo $this->t('forum.highlighted'); ?></span><?php endif; ?>
                            <a href="<?php echo $this->url('/thread/' . (int)$item['id']); ?>"><?php echo $this->highlightSearchTerms($this->e($item['title']), $keyword); ?></a>
                        </div>
                        <div class="mn-fs-13 mn-text-secondary mn-mt-4"><?php echo $this->e(mb_substr(strip_tags($item['content'] ?? ''), 0, 120)); ?></div>
                        <div class="mn-row-meta mn-mt-4">
                            <span class="mn-row-author"><?php echo $this->e($item['username'] ?? ''); ?></span>
                            <span class="mn-row-stat"><?php echo $this->icon('time', 11); ?> <?php echo $this->timeAgo($item['created_at'] ?? ''); ?></span>
                            <span class="mn-row-stat"><?php echo $this->icon('views', 11); ?> <?php echo (int)($item['view_count'] ?? 0); ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($blogResults)): ?>
            <div class="mn-mb-24">
                <div class="mn-flex-center mn-gap-8 mn-mb-12">
                    <h3 class="mn-fs-14 mn-fw-600"><?php echo $this->icon('blog', 14); ?> <?php echo $this->t('blog.posts'); ?></h3>
                    <?php if ($blogTotal > count($blogResults)): ?>
                    <a href="?keyword=<?php echo urlencode($keyword); ?>&type=blogs" class="mn-fs-12 mn-text-link" style="margin-left:auto;"><?php echo $this->t('search.view_all', ['count' => $blogTotal]); ?> →</a>
                    <?php endif; ?>
                </div>
                <?php foreach ($blogResults as $item): ?>
                <div class="mn-row mn-py-10">
                    <div class="mn-row-main">
                        <div class="mn-row-title">
                            <a href="<?php echo $this->url('/blog/' . (int)$item['id']); ?>"><?php echo $this->highlightSearchTerms($this->e($item['title']), $keyword); ?></a>
                        </div>
                        <div class="mn-fs-13 mn-text-secondary mn-mt-4"><?php echo $this->e(mb_substr(strip_tags($item['content'] ?? ''), 0, 120)); ?></div>
                        <div class="mn-row-meta mn-mt-4">
                            <span class="mn-row-author"><?php echo $this->e($item['username'] ?? ''); ?></span>
                            <span class="mn-row-stat"><?php echo $this->icon('time', 11); ?> <?php echo $this->timeAgo($item['created_at'] ?? ''); ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <!-- ====== 具体标签模式：分页显示 ====== -->
            <?php foreach ($results as $item):
                $typeLabel = $item['result_type'] ?? '';
                if ($typeLabel === 'user') continue;
                $title = $this->e($item['title'] ?? '');
                $content = $this->e(mb_substr(strip_tags($item['content'] ?? ''), 0, 150));
                $url = $typeLabel === 'thread' ? $this->url('/thread/'.(int)$item['id']) : $this->url('/blog/'.(int)$item['id']);
            ?>
            <div class="mn-row mn-py-12">
                <div class="mn-row-main">
                    <div class="mn-row-title">
                        <span class="mn-tag mn-tag-cat"><?php echo $typeLabel === 'thread' ? $this->t('search.thread') : $this->t('search.blog'); ?></span>
                        <a href="<?php echo $url; ?>"><?php echo $title; ?></a>
                    </div>
                    <?php if ($content): ?>
                    <div class="mn-fs-13 mn-text-secondary mn-mt-4"><?php echo $this->highlightSearchTerms($content, $keyword); ?></div>
                    <?php endif; ?>
                    <div class="mn-row-meta mn-mt-4">
                        <?php if (!empty($item['username'])): ?><span class="mn-row-author"><?php echo $this->e($item['username']); ?></span><?php endif; ?>
                        <?php if (!empty($item['created_at'])): ?><span class="mn-row-stat"><?php echo $this->icon('time', 11); ?> <?php echo $this->timeAgo($item['created_at']); ?></span><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <?php echo $this->pagination($page, $totalPages, '?keyword=' . urlencode($keyword) . '&type=' . $type . '&page={page}'); ?>
            <?php endif; ?>

        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php $this->endSection(); ?>