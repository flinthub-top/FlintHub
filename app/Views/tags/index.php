<?php
/**
 * 标签云视图 — 全部标签列表（按帖子数排序）
 * @file app/Views/tags/index.php
 */
$tags = $this->getData('tags', []);
?>
<?php $this->section('title'); ?><?php echo $this->t('tags.title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section">
    <div class="mn-section-header">
        <h2><?php echo $this->icon('tags', 16); ?> <?php echo $this->t('tags.title'); ?></h2>
    </div>
    <div class="mn-p-24-20">
        <?php if (empty($tags)): ?>
        <div class="mn-empty"><?php echo $this->t('tags.no_tags'); ?></div>
        <?php else:
            $tagMax = 1;
            foreach ($tags as $t) { $c = (int)($t['thread_count'] ?? 0); if ($c > $tagMax) $tagMax = $c; }
        ?>
        <div class="mn-flex-center mn-gap-8 mn-flex-wrap">
            <?php foreach ($tags as $tag):
                $count = (int)($tag['thread_count'] ?? 0);
                $size = 0.9 + ($count / max($tagMax, 1)) * 1.1;
            ?>
            <a href="<?php echo $this->url('/tag/' . (int)($tag['id'] ?? 0)); ?>" style="font-size:<?php echo $size; ?>em;padding:6px 14px;border-radius:6px;color:var(--mn-text-secondary);background:var(--mn-bg-body);border:1px solid var(--mn-border-light);transition:all 0.12s;text-decoration:none;" onmouseover="this.style.background='var(--mn-primary)';this.style.color='#fff';this.style.borderColor='var(--mn-primary)';" onmouseout="this.style.background='var(--mn-bg-body)';this.style.color='var(--mn-text-secondary)';this.style.borderColor='var(--mn-border-light)';">
                <?php echo $this->e($tag['name'] ?? ''); ?>
                <small style="font-size:0.7em;opacity:0.6;">(<?php echo $count; ?>)</small>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php $this->endSection(); ?>