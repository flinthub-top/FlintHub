<?php
/**
 * 标签聚合视图 — 某标签下的帖子列表与分页
 * @file app/Views/tags/show.php
 */
$tag = $this->getData('tag', []);
$threads = $this->getData('threads', []);
$page = (int)$this->getData('page', 1);
$totalPages = (int)$this->getData('totalPages', 1);
$total = (int)$this->getData('total', 0);
?>
<?php $this->section('title'); ?><?php echo $this->t('tags.threads_with_tag', ['name' => $this->e($tag['name'] ?? '')]); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section">
    <div class="mn-section-header">
        <h2><?php echo $this->icon('tag', 16); ?> #<?php echo $this->e($tag['name'] ?? ''); ?></h2>
        <span class="mn-fs-13 mn-text-muted"><?php echo $this->t('tags.total_threads', ['count' => $total]); ?></span>
    </div>
    <?php if (empty($threads)): ?>
    <div class="mn-empty"><?php echo $this->t('tags.no_threads'); ?></div>
    <?php else: ?>
        <?php foreach ($threads as $thread):
            $tid = (int)($thread['id'] ?? 0);
            $username = $this->e($thread['username'] ?? '');
            $avatarPath = $thread['avatar'] ?? '';
            $titleColor = !empty($thread['color']) ? $thread['color'] : '';
            $level = (int)($thread['level'] ?? 1);
        ?>
        <div class="mn-row">
            <div class="mn-row-avatar">
                <?php if ($avatarPath): ?>
                    <img src="<?php echo $this->e(\UPLOAD_URL . $avatarPath); ?>" alt="" class="mn-row-avatar-img">
                <?php else: ?>
                    <div class="mn-row-avatar-placeholder"><?php echo $this->e(mb_substr($username, 0, 1)); ?></div>
                <?php endif; ?>
            </div>
            <div class="mn-row-main">
                <div class="mn-row-title">
                    <a href="<?php echo $this->url('/thread/' . $tid); ?>"<?php if ($titleColor): ?> style="color:<?php echo $this->e($titleColor); ?>;font-weight:600;"<?php endif; ?>><?php echo $this->e($thread['title'] ?? ''); ?></a>
                </div>
                <div class="mn-row-meta">
                    <a href="<?php echo $this->url('/forum/category/' . (int)($thread['category_id'] ?? 0)); ?>" class="mn-tag mn-tag-cat mn-cat-mobile"><?php echo $this->e($thread['category_name'] ?? ''); ?></a>
                    <span class="mn-row-author"><?php echo $username; ?></span>
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
        <?php echo $this->pagination($page, $totalPages, '/tag/' . (int)($tag['id'] ?? 0) . '?page={page}'); ?>
    <?php endif; ?>
</div>
<?php $this->endSection(); ?>