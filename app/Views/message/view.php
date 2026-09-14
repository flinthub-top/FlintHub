<?php
/**
 * 消息详情视图 — 查看私信内容、回复入口
 * @file app/Views/message/view.php
 */
$message = $this->getData('message', []);
$isReceiver = (int)($message['receiver_id'] ?? 0) === (int)($this->getData('currentUser')['id'] ?? 0);
?>
<?php $this->section('title'); ?><?php echo $this->t('message.view'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section">
    <div class="mn-section-header">
        <h2><?php echo $this->icon('message', 15); ?> <?php echo $this->e($message['subject'] ?? '(' . $this->t('message.no_subject') . ')'); ?></h2>
        <div class="mn-flex-center mn-gap-6">
            <a href="<?php echo $this->url('/message/inbox'); ?>" class="mn-btn mn-btn-sm"><?php echo $this->icon('back', 12); ?><span class="btn-label"> <?php echo $this->t('common.back'); ?></span></a>
            <?php if ($isReceiver): ?>
            <a href="<?php echo $this->url('/message/compose?reply_to=' . (int)($message['sender_id'] ?? 0)); ?>" class="mn-btn mn-btn-sm mn-btn-primary"><?php echo $this->icon('reply', 12); ?><span class="btn-label"> <?php echo $this->t('common.reply'); ?></span></a>
            <?php endif; ?>
        </div>
    </div>
    <div class="mn-p-16-20 mn-border-bottom">
        <div class="mn-flex-center mn-gap-10">
            <div>
                <div class="mn-fs-14 mn-fw-600"><?php echo $isReceiver ? $this->e($message['sender_username'] ?? '') : $this->e($message['receiver_username'] ?? ''); ?></div>
                <div class="mn-fs-12 mn-text-muted mn-mt-2"><?php echo $this->icon('time', 11); ?> <?php echo $this->e($message['created_at'] ?? ''); ?></div>
            </div>
        </div>
    </div>
    <div class="mn-p-20 mn-fs-14 mn-lh-17">
        <?php echo nl2br($this->e($message['content'] ?? '')); ?>
    </div>
</div>
<?php $this->endSection(); ?>