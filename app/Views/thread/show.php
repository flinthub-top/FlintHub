<?php
/**
 * Modern 主题 — 帖子详情页
 * 三栏：左栏分类 + 中栏帖子+回复 + 右栏信息
 */
$thread = $this->getData('thread');
$posts = $this->getData('posts', []);
$page = $this->getData('page', 1);
$totalPages = $this->getData('totalPages', 1);
$totalPosts = $this->getData('totalPosts', 0);
$perPage = $this->getData('perPage', 20);
$attachments = $this->getData('attachments', []);
$replyToView = $this->getData('replyToView', false);
$hasReplied = $this->getData('hasReplied', false);
$isLoggedIn = $this->getData('isLoggedIn');
$currentUser = $this->getData('currentUser');
$postVotes = $this->getData('postVotes', []);
$postAttachments = $this->getData('postAttachments', []);
$csrfToken = $this->e($this->getData('csrfToken'));
$threadId = (int)$thread['id'];
$canModerate = $isLoggedIn && ($currentUser['id'] == $thread['user_id'] || $this->getData('isAdmin'));
$threadTags = $this->getData('threadTags', []);
$renderedContent = $this->getData('_renderedContent', '');
$threadTitleColors = [
    '#dc2626' => 'thread-title-color-red',
    '#d97706' => 'thread-title-color-amber',
    '#16a34a' => 'thread-title-color-green',
    '#2563eb' => 'thread-title-color-blue',
    '#7c3aed' => 'thread-title-color-purple',
];
$threadTitleClass = $threadTitleColors[(string)($thread['color'] ?? '')] ?? '';
$threadCollapseText = \app\Helpers\I18n::current() === 'en' ? 'Collapse full text' : '收起全文';
?>
<?php $this->section('title'); ?><?php echo $this->e($thread['title'] ?? ''); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<!-- ====== 提示消息 ====== -->
<?php $msg = $_GET['msg'] ?? ''; ?>
<?php if ($msg === 'thread_updated'): ?><div class="mn-alert mn-alert-success"><?php echo $this->t('thread.updated'); ?></div><?php endif; ?>
<?php if ($msg === 'post_deleted'): ?><div class="mn-alert mn-alert-success"><?php echo $this->t('thread.post_deleted'); ?></div><?php endif; ?>
<?php if ($msg === 'post_updated'): ?><div class="mn-alert mn-alert-success"><?php echo $this->t('thread.post_updated'); ?></div><?php endif; ?>
<?php if ($msg === 'bucket_full'): ?><div class="mn-alert mn-alert-error"><?php echo $this->t('thread.bucket_full'); ?></div><?php endif; ?>
<?php if ($msg === 'archived_no_reply'): ?><div class="mn-alert mn-alert-error"><?php echo $this->t('thread.archived_no_reply'); ?></div><?php endif; ?>

<!-- ====== 归档横幅 ====== -->
<?php $isArchived = (bool)$this->getData('isArchived', false); ?>
<?php if ($isArchived): ?>
<div class="mn-alert mn-alert-warning">
    <?php echo $this->icon('archive', 15); ?>
    <?php echo $this->t('thread.archived', ['days' => (int)$this->getData('archiveDays', 0)]); ?>
    <?php if (!empty($this->getData('isAdmin'))): ?><?php echo $this->t('thread.archived_admin'); ?><?php endif; ?>
</div>
<?php endif; ?>

<!-- ====== 主帖 ====== -->
<div class="mn-section"
     <?php if (!($replyToView && !$hasReplied)): ?>
     data-quote-user="<?php echo $this->e($thread['username'] ?? ''); ?>"
     data-quote-uid="<?php echo (int)($thread['user_id'] ?? 0); ?>"
     data-quote-content="<?php echo htmlspecialchars(mb_substr(strip_tags($thread['content'] ?? ''), 0, 500), ENT_QUOTES, 'UTF-8'); ?>"
     <?php endif; ?>>

    <!-- 标题栏 -->
    <div class="mn-section-header">
        <div>
            <h1 class="mn-fs-16 mn-fw-600 mn-lh-14 thread-title <?php echo $this->e($threadTitleClass); ?>">
                <?php if (!empty($thread['category_name'])): ?><a href="<?php echo $this->url('/forum/category/' . (int)($thread['category_id'] ?? 0)); ?>" class="mn-tag mn-tag-cat"><?php echo $this->e($thread['category_name']); ?></a><?php endif; ?>
                <?php if (!empty($thread['is_pinned'])): ?><span class="mn-tag mn-tag-hot"><?php echo $this->icon('pinned', 12); ?> <?php echo $this->t('common.pinned'); ?></span><?php endif; ?>
                <?php if (!empty($thread['is_highlighted'])): ?><span class="mn-tag mn-tag-elite"><?php echo $this->icon('star', 12); ?> <?php echo $this->t('forum.highlighted'); ?></span><?php endif; ?>
                <?php if (!empty($thread['_pending_review'])): ?><span class="mn-tag mn-tag-warning"><?php echo $this->t('common.pending_review'); ?></span><?php endif; ?>
                <?php echo $this->e($thread['title'] ?? ''); ?>
            </h1>
            <?php if (!empty($threadTags)): ?>
            <div class="mn-flex mn-gap-4 mn-flex-wrap thread-tags-row">
                <?php foreach ($threadTags as $tag): ?>
                <a href="<?php echo $this->url('/tag/' . (int)($tag['id'] ?? 0)); ?>" class="mn-tag mn-tag-primary mn-text-decoration-none">#<?php echo $this->e($tag['name'] ?? ''); ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 用户信息栏 -->
    <div class="mn-flex-between mn-border-bottom thread-info-bar">
        <div class="mn-flex-center mn-gap-10">
            <?php if (!empty($thread['avatar'])): ?>
                <img src="<?php echo $this->e(\UPLOAD_URL . $thread['avatar']); ?>" alt="" class="mn-rounded-4 thread-avatar-img">
            <?php else: ?>
                <div class="mn-rounded-4 mn-flex-center thread-avatar-placeholder"><?php echo $this->e(mb_substr($thread['username'] ?? '', 0, 1)); ?></div>
            <?php endif; ?>
            <div>
                <div class="mn-flex-center mn-gap-6">
                    <a href="<?php echo $this->url('/u/' . (int)$thread['user_id']); ?>" class="mn-fs-14 mn-fw-600 mn-text-decoration-none mn-color-inherit"><?php echo $this->e($thread['username'] ?? ''); ?></a>
                    <?php $wearingMedals = $this->getData('wearingMedals', []); echo $wearingMedals[(int)$thread['user_id']] ?? ''; ?>
                    <?php $pmTitles = $this->getData('pmTitles', []); echo $pmTitles[(int)$thread['user_id']] ?? ''; ?>
                    <?php $ulBadges = $this->getData('ulBadges', []); echo $ulBadges[(int)$thread['user_id']] ?? ''; ?>
                </div>
                <div class="mn-fs-12 mn-text-muted mn-mt-2">
                    <?php echo $this->icon('time', 11); ?> <?php echo date('Y-m-d H:i:s', strtotime($thread['created_at'] ?? 'now')); ?>
                </div>
            </div>
        </div>
        <?php if ($canModerate): ?>
        <div class="mn-flex-center mn-gap-6">
            <a href="<?php echo $this->url('/thread/' . $threadId . '/edit'); ?>" class="mn-btn mn-btn-sm"><?php echo $this->icon('edit', 12); ?><span class="mn-desktop-only"> <?php echo $this->t('common.edit'); ?></span></a>
            <form method="POST" action="<?php echo $this->url('/thread/' . $threadId . '/delete'); ?>" class="thread-inline-form" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('js.confirm_delete_thread')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <button type="submit" class="mn-btn mn-btn-sm mn-text-error"><?php echo $this->icon('delete', 12); ?><span class="mn-desktop-only"> <?php echo $this->t('common.delete'); ?></span></button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- 帖子内容 -->
    <div class="mn-p-20">
        <?php if ($replyToView && !$hasReplied && $thread['user_id'] != ($currentUser['id'] ?? 0)): ?>
            <div class="mn-text-center thread-reply-to-view">
                <p class="mn-fs-28 mn-mb-10"><?php echo $this->icon('reply-lock', 36); ?></p>
                <p class="mn-text-muted"><?php echo $this->t('thread.reply_to_view'); ?></p>
                <p class="mn-fs-13 mn-text-muted"><?php echo $this->t('thread.reply_to_view_hint'); ?></p>
            </div>
        <?php else: ?>
            <div
                class="thread-body-shell"
                data-thread-collapse
                x-data="{ expanded: false, hasOverflow: false }"
                x-init="$nextTick(() => { const max = parseFloat(getComputedStyle($refs.threadBody).getPropertyValue('--thread-body-collapsed-height')) || 600; hasOverflow = $refs.threadBody.scrollHeight > max; })"
                x-on:resize.window="$nextTick(() => { const max = parseFloat(getComputedStyle($refs.threadBody).getPropertyValue('--thread-body-collapsed-height')) || 600; hasOverflow = $refs.threadBody.scrollHeight > max; })">
                <div
                    class="post-text-content thread-body thread-body--collapsed"
                    id="post-body-<?php echo $threadId; ?>"
                    x-ref="threadBody"
                    :class="{ 'thread-body--collapsed': !expanded && hasOverflow, 'thread-body--expanded': expanded }">
                    <?php echo $renderedContent; ?>
                </div>
                <div class="thread-expand-bar" x-show="hasOverflow" x-cloak>
                    <button type="button" class="mn-btn mn-btn-primary thread-expand-btn" x-on:click="expanded = !expanded" :aria-expanded="expanded.toString()">
                        <span x-show="!expanded"><?php echo $this->t('thread.read_more'); ?> ▾</span>
                        <span x-show="expanded"><?php echo $this->e($threadCollapseText); ?> ▴</span>
                    </button>
                </div>
            </div>
            <?php $tei = $this->getData('threadEditInfo'); if ($tei): ?>
            <div class="mn-fs-12 mn-text-muted mn-border-dashed thread-edit-info">
                <?php echo $this->icon('edit', 11); ?> <?php echo $this->t('thread.last_edited', ['username' => $this->e($tei['username'] ?? $this->t('common.unknown')), 'time' => date('Y-m-d H:i', strtotime($tei['edited_at']))]); ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- 附件 -->
        <?php if (!empty($attachments)): ?>
        <div class="mn-border-top thread-attachment-section">
            <h4 class="mn-fs-13 mn-fw-600 mn-mb-8 mn-text-secondary"><?php echo $this->t('thread.attachments'); ?></h4>
            <?php foreach ($attachments as $att):
                $safeFile = basename($att['filename']);
                $fileUrl = $this->url('/attachment/' . (int)$att['id']);
                $ext = strtolower(pathinfo($safeFile, PATHINFO_EXTENSION));
                $isImage = in_array($ext, ['jpg','jpeg','png','gif','webp','bmp']);
            ?>
            <?php if ($isImage): ?>
            <div class="mn-inline-block thread-attachment-item" data-attachment-id="<?php echo (int)$att['id']; ?>">
                <a href="javascript:void(0)" data-action="view-image">
                    <img src="<?php echo $this->e($fileUrl); ?>" alt="<?php echo $this->e($att['original_name'] ?? $safeFile); ?>" class="attachment-image mn-rounded-4 mn-border thread-attachment-img">
                </a>
                <div class="mn-fs-11 mn-text-muted mn-text-center"><?php echo $this->e($att['original_name'] ?? $safeFile); ?> (<?php echo round(((int)($att['file_size'] ?? 0)) / 1024, 1); ?> KB)</div>
            </div>
            <?php else: ?>
            <div class="mn-mb-6" data-attachment-id="<?php echo (int)$att['id']; ?>">
                <a href="<?php echo $this->e($fileUrl); ?>" target="_blank" class="mn-fs-13 mn-text-link"><?php echo $this->icon('attachment', 12); ?> <?php echo $this->e($att['original_name'] ?? $safeFile); ?> <span class="mn-text-muted mn-fs-11">(<?php echo round(((int)($att['file_size'] ?? 0)) / 1024, 1); ?> KB)</span></a>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- 插件锚点：主帖内嵌红包等（正文/附件后、签名前） -->
        <?php \app\Helpers\Plugin::hook('thread_redpacket_area', ['thread' => $thread]); ?>

        <!-- 签名 -->
        <?php if (!empty($thread['signature'])): ?>
        <div class="mn-border-dashed mn-fs-12 mn-text-muted thread-signature">
            <?php echo $this->icon('quote', 13); ?> <?php echo nl2br($this->e($thread['signature'])); ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- 统计操作栏 -->
    <div class="mn-flex-between mn-border-top thread-info-bar">
        <div class="mn-flex-center mn-gap-10">
            <?php $tv = $this->getData('threadVote'); ?>
            <span class="vote-buttons mn-flex-center mn-gap-4" data-type="thread" data-id="<?php echo $threadId; ?>">
                <button class="vote-btn vote-up<?php echo (isset($tv['user_vote']) && $tv['user_vote'] === 1) ? ' active' : ''; ?> vote-btn-clean" hx-post="/api/vote" hx-target="closest .vote-buttons" hx-swap="outerHTML" hx-vals='{"type":"thread","id":"<?php echo $threadId; ?>","vote":"1","csrf":"<?php echo $this->e($this->getData('csrfToken', '')); ?>"}' title="<?php echo $this->t('thread.like'); ?>"><?php echo $this->icon('like', 14); ?> <span class="vote-count"><?php echo (int)($tv['likes'] ?? 0); ?></span></button>
            </span>
            <?php if ($isLoggedIn): ?>
            <a href="javascript:void(0)" data-action="quote" class="mn-fs-12 mn-text-muted mn-flex-center mn-gap-3"><?php echo $this->icon('quote', 12); ?> <?php echo $this->t('thread.quote'); ?></a>
            <?php endif; ?>
            <span class="mn-text-muted">|</span>
            <span class="mn-fs-13 mn-text-muted mn-flex-center mn-gap-4"><?php echo $this->icon('views', 13); ?><span class="mn-hide-mobile"> <?php echo $this->t('thread.view_label'); ?> </span><?php echo (int)($thread['view_count'] ?? 0); ?><span class="mn-hide-mobile"> <?php echo $this->t('thread.count_unit'); ?></span></span>
            <span class="mn-text-muted">|</span>
            <span class="mn-fs-13 mn-text-muted mn-flex-center mn-gap-4"><?php echo $this->icon('reply', 13); ?><span class="mn-hide-mobile"> <?php echo $this->t('thread.reply_label'); ?> </span><?php echo (int)($thread['reply_count'] ?? 0); ?><span class="mn-hide-mobile"> <?php echo $this->t('thread.count_unit'); ?></span></span>
        </div>
        <!-- 右侧：收藏按钮 + 主帖举报按钮。收藏由 post_favorite JS 注入（order:1），
             举报按钮（thread_operation_after 钩子）紧随其后（order:2），靠右对齐 -->
        <div class="mn-flex-center mn-gap-4 thread-info-bar-right">
            <?php \app\Helpers\Plugin::hook('thread_operation_after', ['thread' => $thread]); ?>
        </div>
    </div>
</div>

<!-- ====== 回复列表 ====== -->
<div id="replies" class="mn-section" hx-boost="true" hx-target="#replies" hx-select="#replies" hx-swap="outerHTML" hx-push-url="true">
    <div class="mn-section-header">
        <h2 class="mn-fs-14 mn-fw-600"><?php echo $this->icon('reply', 14); ?> <?php echo $this->t('thread.reply_all'); ?> (<?php echo (int)$totalPosts; ?>)</h2>
    </div>

    <?php if (empty($posts)): ?>
    <div class="mn-empty"><?php echo $this->t('thread.no_replies'); ?></div>
    <?php else: ?>
        <?php foreach ($posts as $i => $post):
            $pid = (int)$post['id'];
            $floor = ($page - 1) * $perPage + $i + 1;
            $canEditReply = $isLoggedIn && ($currentUser['id'] == $post['user_id'] || $this->getData('isAdmin'));
        ?>
        <div data-quote-user="<?php echo $this->e($post['username'] ?? ''); ?>"
             data-quote-uid="<?php echo (int)($post['user_id'] ?? 0); ?>"
             data-quote-content="<?php echo htmlspecialchars(mb_substr(strip_tags($post['content'] ?? ''), 0, 500), ENT_QUOTES, 'UTF-8'); ?>"
             class="mn-border-bottom" hx-disinherit="*">

            <!-- 用户信息栏（与主题贴一致） -->
            <div class="mn-flex-between mn-border-bottom thread-post-user-bar">
                <div class="mn-flex-center mn-gap-10">
                    <?php if (!empty($post['avatar'])): ?>
                        <img src="<?php echo $this->e(\UPLOAD_URL . $post['avatar']); ?>" alt="" class="mn-rounded-4 thread-avatar-img">
                    <?php else: ?>
                        <div class="mn-rounded-4 mn-flex-center thread-avatar-placeholder"><?php echo $this->e(mb_substr($post['username'] ?? '', 0, 1)); ?></div>
                    <?php endif; ?>
                    <div>
                        <div class="mn-flex-center mn-gap-6">
                            <a href="<?php echo $this->url('/u/' . (int)$post['user_id']); ?>" class="mn-fs-14 mn-fw-600 mn-text-decoration-none mn-color-inherit"><?php echo $this->e($post['username'] ?? ''); ?></a>
                            <?php echo $wearingMedals[(int)$post['user_id']] ?? ''; ?>
                            <?php echo $pmTitles[(int)$post['user_id']] ?? ''; ?>
                            <?php echo $ulBadges[(int)$post['user_id']] ?? ''; ?>
                        </div>
                        <div class="mn-fs-12 mn-text-muted mn-mt-2">
                            <?php echo $this->icon('time', 11); ?> <?php echo date('Y-m-d H:i:s', strtotime($post['created_at'] ?? 'now')); ?>
                        </div>
                    </div>
                </div>
                <?php if ($canEditReply): ?>
                <div class="mn-flex-center mn-gap-6">
                    <a href="<?php echo $this->url('/post/' . $pid . '/edit'); ?>" class="mn-btn mn-btn-sm btn-compact"><?php echo $this->icon('edit', 11); ?><span class="mn-desktop-only"> <?php echo $this->t('common.edit'); ?></span></a>
                    <form method="POST" action="<?php echo $this->url('/thread/' . $threadId . '/delete'); ?>" class="thread-inline-form" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('js.confirm_delete_post')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                        <input type="hidden" name="post_id" value="<?php echo $pid; ?>">
                        <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                        <button type="submit" class="mn-btn mn-btn-sm btn-compact mn-text-error"><?php echo $this->icon('delete', 11); ?><span class="mn-desktop-only"> <?php echo $this->t('common.delete'); ?></span></button>
                    </form>
                </div>
                <?php endif; ?>
                <?php \app\Helpers\Plugin::hook('post_operation_after', ['post' => $post]); ?>
            </div>

            <!-- 回复内容（与主题贴一致） -->
            <div class="mn-p-20">
                <div class="post-text-content mn-fs-14 mn-lh-17 post-text-color">
                    <?php echo $post['_rendered'] ?? ''; ?>
                </div>

                <!-- 回复附件 -->
                <?php if (!empty($postAttachments[$pid])): ?>
                <div class="mn-border-top thread-post-attachment">
                    <?php foreach ($postAttachments[$pid] as $att):
                        $safeFile = basename($att['filename']);
                        $fileUrl = $this->url('/attachment/' . (int)$att['id']);
                        $ext = strtolower(pathinfo($safeFile, PATHINFO_EXTENSION));
                        $isImage = in_array($ext, ['jpg','jpeg','png','gif','webp','bmp']);
                    ?>
                    <?php if ($isImage): ?>
                    <div class="mn-inline-block thread-attachment-item" data-attachment-id="<?php echo (int)$att['id']; ?>">
                        <a href="javascript:void(0)" data-action="view-image"><img src="<?php echo $this->e($fileUrl); ?>" alt="" class="mn-rounded-4 mn-border thread-attachment-img"></a>
                    </div>
                    <?php else: ?>
                    <div class="mn-mb-4" data-attachment-id="<?php echo (int)$att['id']; ?>">
                        <a href="<?php echo $this->e($fileUrl); ?>" target="_blank" class="mn-fs-12"><?php echo $this->icon('attachment', 11); ?> <?php echo $this->e($att['original_name'] ?? $safeFile); ?></a>
                    </div>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- 签名 -->
                <?php if (!empty($post['signature'])): ?>
                <div class="mn-border-dashed mn-fs-12 mn-text-muted thread-post-signature">
                    <?php echo $this->icon('quote', 12); ?> <?php echo nl2br($this->e($post['signature'])); ?>
                </div>
                <?php endif; ?>

                <!-- 投票+引用 -->
                <?php
                $pv = $postVotes[$pid] ?? ['likes' => 0, 'user_vote' => 0];
                ?>
                <div class="mn-border-top mn-flex-center thread-vote-actions">
                    <span class="vote-buttons mn-flex-center mn-gap-4" data-type="post" data-id="<?php echo $pid; ?>">
                        <button class="vote-btn vote-up<?php echo ($pv['user_vote'] === 1) ? ' active' : ''; ?> vote-btn-clean-sm" hx-post="/api/vote" hx-target="closest .vote-buttons" hx-swap="outerHTML" hx-vals='{"type":"post","id":"<?php echo $pid; ?>","vote":"1","csrf":"<?php echo $this->e($this->getData('csrfToken', '')); ?>"}' title="<?php echo $this->t('thread.like'); ?>"><?php echo $this->icon('like', 12); ?> <span class="vote-count"><?php echo (int)$pv['likes']; ?></span></button>
                    </span>
                    <span class="mn-text-muted">|</span>
                    <a href="javascript:void(0)" data-action="quote" class="mn-fs-12 mn-text-muted mn-flex-center mn-gap-3"><?php echo $this->icon('quote', 12); ?> <?php echo $this->t('thread.quote'); ?></a>
                    <span class="mn-flex-center mn-gap-6 mn-ml-auto">
                        <?php \app\Helpers\Plugin::hook('post_report_button', ['post' => $post]); ?>
                        <span class="mn-fs-12 mn-text-muted">#<?php echo $floor; ?> <?php echo $this->t('blog.floor'); ?></span>
                    </span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ====== 分页 ====== -->
<?php echo $this->pagination($page, $totalPages, '/thread/' . $threadId . '?page={page}', ['class' => 'thread-pagination']); ?>

<!-- ====== 回复表单 ====== -->
<?php if ($isLoggedIn): ?>
    <?php $canReply = $this->getData('canReply', false); ?>
    <?php // 归档帖非管理员：不显示回复框，改为归档提示（管理员豁免）
    $isAdminUser = !empty($this->getData('isAdmin')); ?>
    <?php if ($canReply && ($isArchived && !$isAdminUser ? false : true)): ?>
<div class="mn-section">
    <div class="mn-section-header">
        <h2 class="mn-fs-14 mn-fw-600"><?php echo $this->icon('reply', 14); ?> <?php echo $this->t('thread.reply'); ?></h2>
    </div>
    <div class="mn-p-20">
        <form method="POST" action="<?php echo $this->url('/thread/' . $threadId); ?>" enctype="multipart/form-data" id="replyForm">
            <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
            <textarea name="content" id="content" data-editor="content" rows="6" class="mn-textarea mn-w-full" required></textarea>
            <div class="mn-flex-col mn-gap-10 mn-items-start thread-reply-form-actions">
                <label class="mn-btn mn-btn-sm mn-cursor-pointer">
                    <input type="file" name="reply_attachments[]" multiple data-action="reply-attachments" data-label-prefix="<?php echo $this->e($this->t('post.select_file_prefix')); ?>" class="mn-d-none">
                    <?php echo $this->icon('attachment', 12); ?> <?php echo $this->t('post.attach_files'); ?>
                </label>
                <span id="reply-file-name" class="mn-fs-12 mn-text-muted thread-file-name"></span>
                <button type="submit" class="mn-btn mn-btn-primary"><?php echo $this->icon('reply', 12); ?> <?php echo $this->t('post.submit'); ?></button>
            </div>
        </form>
    </div>
</div>
    <?php elseif ($isArchived && !$isAdminUser): ?>
<div class="mn-section">
    <div class="mn-empty">
        <p class="mn-text-secondary"><?php echo $this->t('thread.archived_no_reply_hint', ['days' => (int)$this->getData('archiveDays', 0)]); ?></p>
    </div>
</div>
    <?php else: ?>
<div class="mn-section">
    <div class="mn-empty">
        <p class="mn-text-secondary"><?php echo $this->t('thread.no_permission'); ?></p>
    </div>
</div>
    <?php endif; ?>
<?php else: ?>
<div class="mn-section">
    <div class="mn-empty">
        <p class="mn-text-secondary"><?php echo $this->t('thread.login_to_reply') . ' <a href="' . $this->url('/login') . '" class="mn-text-primary mn-fw-600">' . $this->t('auth.login') . '</a>'; ?></p>
    </div>
</div>
<?php endif; ?>

<!-- ====== 浮动操作按钮 ====== -->
<div class="mn-flex-col mn-flex-center thread-float-bar">
    <?php $catId = (int)($thread['category_id'] ?? 0); ?>
    <a href="<?php echo $this->url('/forum/category/' . $catId); ?>" class="mn-btn mn-btn-sm mn-rounded-50 mn-flex-center thread-float-btn" title="<?php echo $this->t('thread.back_to_category'); ?>">
        <?php echo $this->icon('back', 18); ?>
        <span class="mn-desktop-only mn-d-none"><?php echo $this->t('common.back'); ?></span>
    </a>
    <?php if ($isLoggedIn): ?>
    <a href="<?php echo $this->url('/post/new?category_id=' . $catId); ?>" class="mn-btn mn-btn-sm mn-rounded-50 mn-flex-center thread-float-btn" title="<?php echo $this->t('forum.new_thread'); ?>">
        <?php echo $this->icon('new-post', 18); ?>
        <span class="mn-desktop-only mn-d-none"><?php echo $this->t('forum.new_thread'); ?></span>
    </a>
    <a href="#replyForm" data-action="scroll-reply" class="mn-btn mn-btn-primary mn-btn-sm mn-rounded-50 mn-flex-center thread-float-btn-primary" title="<?php echo $this->t('thread.reply'); ?>">
        <?php echo $this->icon('reply', 18); ?>
        <span class="mn-desktop-only mn-d-none"><?php echo $this->t('thread.reply'); ?></span>
    </a>
    <?php endif; ?>
</div>

<!-- 图片灯箱 -->
<div class="image-lightbox" id="imageLightbox">
    <img id="lightboxImg" src="" alt="">
    <span class="lb-close" data-action="close-lightbox">×</span>
</div>
<script src="<?php echo $this->asset('js/thread.js'); ?>"></script>
<?php $this->endSection(); ?>