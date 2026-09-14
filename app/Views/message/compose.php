<?php
/**
 * 发送消息视图 — 站内私信撰写（收件人/主题/内容）
 * @file app/Views/message/compose.php
 */
$error = $this->getData('error', '');
$success = $this->getData('success', '');
$receiverName = $this->getData('receiverName', '');
$csrfToken = $this->e($this->getData('csrfToken'));
?>
<?php $this->section('title'); ?><?php echo $this->t('message.compose'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section">
    <div class="mn-section-header">
        <h2><?php echo $this->icon('write', 16); ?> <?php echo $this->t('message.compose'); ?></h2>
        <a href="<?php echo $this->url('/message/inbox'); ?>" class="mn-btn mn-btn-sm"><?php echo $this->icon('back', 12); ?> <?php echo $this->t('message.back_inbox'); ?></a>
    </div>
    <div class="mn-p-20">
        <?php if ($success): ?><div class="mn-alert mn-alert-success"><?php echo $this->e($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="mn-alert mn-alert-error"><?php echo $this->e($error); ?></div><?php endif; ?>
        <?php if (!$success): ?>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
            <div class="mn-mb-12">
                <label class="mn-label"><?php echo $this->t('message.to'); ?></label>
                <input type="text" name="receiver" value="<?php echo $this->e($receiverName); ?>" required class="mn-input mn-maxw-300">
            </div>
            <div class="mn-mb-12">
                <label class="mn-label"><?php echo $this->t('message.subject'); ?></label>
                <input type="text" name="subject" required maxlength="200" class="mn-input">
            </div>
            <div class="mn-mb-14">
                <label class="mn-label"><?php echo $this->t('message.content'); ?></label>
                <textarea name="content" id="content" data-editor="content" rows="8" class="mn-textarea" required></textarea>
            </div>
            <button type="submit" class="mn-btn mn-btn-primary"><?php echo $this->icon('send', 12); ?> <?php echo $this->t('message.send'); ?></button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php $this->endSection(); ?>