<?php
/**
 * 博客详情视图 — 文章内容、作者信息、评论列表与分页
 * @file app/Views/blog/detail.php
 */
$blog = $this->getData('blog', []);
$comments = $this->getData('comments', []);
$blogCategories = $this->getData('blogCategories', []);
$totalComments = (int)$this->getData('totalComments', 0);
$commentPage = (int)$this->getData('commentPage', 1);
$totalCommentPages = (int)$this->getData('totalCommentPages', 1);
$commentPerPage = (int)$this->getData('commentPerPage', 20);
$isLoggedIn = $this->getData('isLoggedIn');
$isAdmin = $this->getData('isAdmin');
$currentUser = $this->getData('currentUser');
$csrfToken = $this->e($this->getData('csrfToken'));
$bid = (int)($blog['id'] ?? 0);
$isAuthor = $isLoggedIn && (int)($currentUser['id'] ?? 0) === (int)($blog['user_id'] ?? 0);
?>
<?php $this->section('title'); ?><?php echo $this->e($blog['title'] ?? ''); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section">
    <div class="mn-p-24-20-20">
        <h1 class="mn-fs-20 mn-fw-600 mn-lh-14 mn-mb-10"><?php echo $this->e($blog['title'] ?? ''); ?></h1>
        <div class="mn-flex-center mn-gap-12 mn-fs-12 mn-text-muted mn-mb-20 mn-border-bottom mn-pb-16 mn-flex-wrap">
            <?php if (!empty($blog['category_name'])): ?>
            <a href="<?php echo $this->url('/blog/category/' . (int)($blog['category_id'] ?? 0)); ?>" class="mn-tag mn-tag-cat"><?php echo $this->e($blog['category_name']); ?></a>
            <?php endif; ?>
            <span class="mn-flex-center mn-gap-4"><?php echo $this->icon('user', 11); ?> <?php echo $this->e($blog['username'] ?? ''); ?><?php $pmTitles = $this->getData('pmTitles', []); echo $pmTitles[(int)($blog['user_id'] ?? 0)] ?? ''; ?></span>
            <span class="mn-flex-center mn-gap-4"><?php echo $this->icon('time', 11); ?> <?php echo date('Y-m-d H:i', strtotime($blog['created_at'] ?? 'now')); ?></span>
            <span class="mn-flex-center mn-gap-4 mn-hide-mobile"><?php echo $this->icon('views', 11); ?> <?php echo (int)($blog['view_count'] ?? 0); ?></span>
            <span class="mn-flex-center mn-gap-4 mn-hide-mobile"><?php echo $this->icon('reply', 11); ?> <?php echo (int)($blog['comment_count'] ?? 0); ?></span>
            <?php if ($isAuthor || $isAdmin): ?>
            <div class="mn-flex-center mn-gap-8 mn-basis-full mn-justify-end mn-mt-8">
                <a href="<?php echo $this->url('/blog/' . $bid . '/edit'); ?>" class="mn-btn mn-btn-sm mn-btn-outline" title="<?php echo $this->t('common.edit'); ?>"><?php echo $this->icon('edit', 12); ?><span class="mn-hide-mobile"> <?php echo $this->t('common.edit'); ?></span></a>
                <form method="POST" action="<?php echo $this->url('/blog/' . $bid . '/delete'); ?>" class="mn-inline-block" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('js.confirm_delete_blog')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                    <button type="submit" class="mn-btn mn-btn-sm mn-btn-danger" title="<?php echo $this->t('common.delete'); ?>"><?php echo $this->icon('close', 12); ?><span class="mn-hide-mobile"> <?php echo $this->t('common.delete'); ?></span></button>
                </form>
            </div>
            <?php endif; ?>
            <?php \app\Helpers\Plugin::hook('blog_detail_operation_after', ['blog' => $blog]); ?>
        </div>
        <?php if (!empty($blog['cover_image'])): ?>
        <!-- 封面图（模板生成的 SVG 封面或上传封面）：位于正文上方，16:6 比例 object-fit 统一裁切 -->
        <div class="mn-mb-20">
            <img src="<?php echo $this->e(\UPLOAD_URL . $blog['cover_image']); ?>" alt="<?php echo $this->e($blog['title'] ?? ''); ?>" style="width:100%;aspect-ratio:16/6;object-fit:cover;border-radius:8px;display:block;">
        </div>
        <?php endif; ?>
        <div class="post-text-content mn-fs-15 mn-lh-18 post-text-color">
            <?php echo $this->formatPostContent($this->decodeContent($blog['content'] ?? '')); ?>
        </div>
    </div>
</div>
<?php if (!empty($comments)): ?>
<div class="mn-section" id="comments">
    <div class="mn-section-header">
        <h2 class="mn-fs-14 mn-fw-600"><?php echo $this->icon('reply', 14); ?> <?php echo $this->t('blog.comments', ['count' => $totalComments]); ?></h2>
    </div>
    <?php foreach ($comments as $i => $comment): ?>
    <?php $floor = ($commentPage - 1) * $commentPerPage + $i + 1; ?>
    <div class="mn-row blog-comment-row mn-items-stretch">
        <div class="mn-row-main">
            <!-- 评论元信息条：小头像 + 用户名 / 时间，右侧楼层号，与内容分隔 -->
            <div class="mn-reply-header">
                <div class="mn-flex-center mn-gap-8">
                    <?php if (!empty($comment['avatar'])): ?>
                        <img src="<?php echo $this->e(\UPLOAD_URL . $comment['avatar']); ?>" alt="" class="mn-row-avatar-img mn-avatar-22">
                    <?php else: ?>
                        <div class="mn-row-avatar-placeholder mn-avatar-22-placeholder"><?php echo $this->e(mb_substr($comment['username'] ?? '', 0, 1)); ?></div>
                    <?php endif; ?>
                    <span class="mn-fs-14 mn-fw-600"><?php echo $this->e($comment['username'] ?? ''); ?></span>
                    <span class="mn-fs-12 mn-text-muted"><?php echo $this->icon('time', 11); ?> <?php echo $this->timeAgo($comment['created_at'] ?? ''); ?></span>
                </div>
                <span class="mn-fs-12 mn-text-muted">#<?php echo $floor; ?> <?php echo $this->t('blog.floor'); ?></span>
            </div>
            <div class="mn-fs-14 mn-lh-17"><?php echo nl2br($this->e($comment['content'] ?? '')); ?></div>
            <?php \app\Helpers\Plugin::hook('blog_comment_operation_after', ['comment' => $comment]); ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php echo $this->pagination($commentPage, $totalCommentPages, '/blog/' . $bid . '?comment_page={page}#comments'); ?>
</div>
<?php endif; ?>
<?php if ($isLoggedIn): ?>
<div class="mn-section" id="comment-form">
    <div class="mn-section-header">
        <h2 class="mn-fs-14 mn-fw-600"><?php echo $this->icon('reply', 14); ?> <?php echo $this->t('blog.write_comment'); ?></h2>
    </div>
    <div class="mn-p-20">
        <form method="POST" action="<?php echo $this->url('/blog/' . $bid); ?>">
            <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
            <textarea name="content" rows="5" class="mn-textarea" required></textarea>
            <button type="submit" class="mn-btn mn-btn-primary mn-mt-12"><?php echo $this->icon('reply', 12); ?> <?php echo $this->t('blog.submit_comment'); ?></button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- 图片灯箱（博客正文图片点击查看大图） -->
<div class="image-lightbox" id="imageLightbox" onclick="closeLightbox(event)">
    <img id="lightboxImg" src="" alt="">
    <span class="lb-close" onclick="closeLightbox(event)">×</span>
</div>
<script src="<?php echo $this->asset('js/thread.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.post-text-content img').forEach(function(img) {
        img.style.cursor = 'zoom-in';
        img.addEventListener('click', function() {
            viewImage(this);
        });
    });
});
</script>

<!-- ====== 浮动操作按钮 ====== -->
<div class="mn-flex-col mn-flex-center thread-float-bar">
    <a href="<?php echo $this->url('/blog'); ?>" class="mn-btn mn-btn-sm mn-rounded-50 mn-flex-center thread-float-btn" title="<?php echo $this->t('common.back'); ?>">
        <?php echo $this->icon('back', 18); ?>
        <span class="mn-desktop-only mn-d-none"><?php echo $this->t('common.back'); ?></span>
    </a>
    <?php if ($isLoggedIn): ?>
    <a href="javascript:void(0)" onclick="document.getElementById('comment-form').scrollIntoView({behavior:'smooth'})" class="mn-btn mn-btn-primary mn-btn-sm mn-rounded-50 mn-flex-center thread-float-btn-primary" title="<?php echo $this->t('blog.comment'); ?>">
        <?php echo $this->icon('reply', 18); ?>
        <span class="mn-desktop-only mn-d-none"><?php echo $this->t('blog.comment'); ?></span>
    </a>
    <?php endif; ?>
</div>
<?php $this->endSection(); ?>