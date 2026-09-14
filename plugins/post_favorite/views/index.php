<?php
/**
 * 我的收藏视图 — 收藏帖子列表与取消收藏（post_favorite 插件）
 * @file app/Views/plugins/post_favorite/index.php
 */
$favorites = $this->getData('favorites', []);
$totalPages = (int)$this->getData('totalPages', 1);
$page = (int)$this->getData('page', 1);
$csrfToken = $this->e($this->getData('csrfToken'));
?>
<?php $this->section('title'); ?><?php echo \app\Helpers\I18n::get('plugin.post_favorite.title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section">
    <div class="mn-section-header">
        <h2><?php echo $this->icon('star', 16); ?> <?php echo \app\Helpers\I18n::get('plugin.post_favorite.title'); ?> <span class="mn-fs-13 mn-text-muted mn-fw-normal"><?php echo \app\Helpers\I18n::get('plugin.post_favorite.count_suffix', ['count' => (int)$this->getData('totalCount')]); ?></span></h2>
        <a href="/" class="mn-btn mn-btn-sm"><?php echo $this->icon('back', 12); ?> <?php echo \app\Helpers\I18n::get('plugin.post_favorite.back_home'); ?></a>
    </div>
    <?php if (empty($favorites)): ?>
    <div class="mn-empty">
        <p class="mn-fs-28 mn-mb-8"><?php echo $this->icon('star', 36); ?></p>
        <p><?php echo \app\Helpers\I18n::get('plugin.post_favorite.empty'); ?></p>
        <p class="mn-mt-8"><a href="/" class="mn-btn mn-btn-primary"><?php echo \app\Helpers\I18n::get('plugin.post_favorite.go_home'); ?></a></p>
    </div>
    <?php else: ?>
        <?php foreach ($favorites as $fav): ?>
        <div class="mn-row">
            <div class="mn-row-main">
                <div class="mn-row-title">
                    <a href="<?php echo $this->url('/thread/' . (int)$fav['thread_id']); ?>"><?php echo $this->e($fav['title'] ?? \app\Helpers\I18n::get('plugin.post_favorite.deleted')); ?></a>
                </div>
                <div class="mn-row-meta">
                    <?php if (!empty($fav['username'])): ?>
                    <span class="mn-row-author"><?php echo $this->e($fav['username']); ?></span>
                    <?php endif; ?>
                    <span class="mn-row-stat"><?php echo $this->icon('time', 11); ?> <?php echo date('m-d', strtotime($fav['fav_time'])); ?></span>
                    <span class="mn-row-stat"><?php echo $this->icon('reply', 11); ?> <?php echo (int)$fav['reply_count']; ?></span>
                    <span class="mn-row-stat"><?php echo $this->icon('views', 11); ?> <?php echo (int)$fav['view_count']; ?></span>
                </div>
            </div>
            <form method="post" action="/favorite/toggle" class="mn-inline" hx-post="/favorite/toggle" hx-target="closest .mn-row" hx-swap="outerHTML">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="thread_id" value="<?php echo (int)$fav['thread_id']; ?>">
                <button type="submit" class="mn-btn mn-btn-sm mn-text-error mn-border-none mn-bg-none mn-cursor-pointer mn-fs-18" title="<?php echo \app\Helpers\I18n::get('plugin.post_favorite.unfavorite'); ?>">&times;</button>
            </form>
        </div>
        <?php endforeach; ?>
        <?php echo $this->pagination($page, $totalPages, '/favorites?page={page}'); ?>
    <?php endif; ?>
</div>
<?php $this->endSection(); ?>