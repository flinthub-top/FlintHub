<?php
/**
 * 收件箱视图 — 收到的私信列表、未读标记
 * @file app/Views/message/inbox.php
 */
$messages = $this->getData('messages', []);
$unreadCount = (int)$this->getData('unreadCount', 0);
$csrfToken = $this->e($this->getData('csrfToken'));
?>
<?php $this->section('title'); ?><?php echo $this->t('message.inbox'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section">
    <div class="mn-section-header">
        <h2><?php echo $this->icon('message', 16); ?> <?php echo $this->t('message.inbox'); ?> <?php if ($unreadCount > 0): ?><span class="mn-fs-12 mn-text-error mn-fw-600">(<?php echo $unreadCount; ?> <?php echo $this->t('message.unread_short'); ?>)</span><?php endif; ?></h2>
        <a href="<?php echo $this->url('/message/compose'); ?>" class="mn-btn mn-btn-primary mn-btn-sm"><?php echo $this->icon('write', 12); ?> <?php echo $this->t('message.write'); ?></a>
    </div>
    <div class="mn-flex-center mn-border-bottom">
        <a href="<?php echo $this->url('/message/inbox'); ?>" class="mn-section-tab mn-active mn-p-10-20"><?php echo $this->t('message.inbox'); ?></a>
        <a href="<?php echo $this->url('/message/sent'); ?>" class="mn-section-tab mn-p-10-20"><?php echo $this->t('message.sent'); ?></a>
    </div>
    <?php if (empty($messages)): ?>
    <div class="mn-empty"><?php echo $this->t('message.no_messages'); ?></div>
    <?php else: ?>
        <?php foreach ($messages as $msg): ?>
        <div class="mn-row" style="<?php echo empty($msg['is_read']) ? 'background:var(--mn-primary-subtle);' : ''; ?>">
            <div class="mn-row-main">
                <div class="mn-row-title">
                    <a href="<?php echo $this->url('/message/view/' . (int)$msg['id']); ?>"><?php echo $this->e($msg['subject'] ?? '(' . $this->t('message.no_subject') . ')'); ?></a>
                    <?php if (empty($msg['is_read'])): ?><span class="mn-unread-dot"></span><?php endif; ?>
                </div>
                <div class="mn-row-meta">
                    <span class="mn-row-author"><?php echo $this->e($msg['sender_username'] ?? ''); ?></span>
                    <span class="mn-row-stat"><?php echo $this->icon('time', 11); ?> <?php echo $this->timeAgo($msg['created_at'] ?? ''); ?></span>
                </div>
            </div>
            <form method="POST" class="mn-inline" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('js.confirm_delete')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="message_id" value="<?php echo (int)$msg['id']; ?>">
                <button type="submit" class="mn-btn mn-btn-sm mn-border-none mn-bg-none mn-cursor-pointer mn-text-muted mn-fs-12"><?php echo $this->icon('delete', 12); ?></button>
            </form>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php $page = (int)$this->getData('page', 1); $totalPages = (int)$this->getData('totalPages', 1); ?>
    <?php echo $this->pagination($page, $totalPages, '/message/inbox?page={page}'); ?>
</div>
<?php $this->endSection(); ?>