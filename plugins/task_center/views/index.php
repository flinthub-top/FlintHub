<?php
/**
 * 任务中心插件 前台视图（任务列表 + 领取）
 * @file plugins/task_center/views/index.php
 * @package Plugin\TaskCenter
 */
$items = $this->getData('items', []);
$points = (int)$this->getData('points', 0);
$I = function (string $k, array $p = []) { return \app\Helpers\I18n::get($k, $p); };
?>
<?php $this->section('title'); ?><?php echo $I('plugin.task_center.title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section tc-wrap">
    <div class="tc-head">
        <h1 class="mn-fs-20 mn-fw-600"><?php echo $this->icon('clipboard-list', 18); ?> <?php echo $I('plugin.task_center.title'); ?></h1>
        <span class="tc-points"><?php echo $I('plugin.task_center.my_points', ['count' => $points]); ?></span>
    </div>

    <?php $flashMsg = $this->getData('flashMsg', ''); $flashType = $this->getData('flashType', ''); ?>
    <?php if ($flashMsg !== ''): ?>
    <div class="mn-alert <?php echo $flashType === 'success' ? 'mn-alert-success' : 'mn-alert-error'; ?> tc-flash"><?php echo $this->e($flashMsg); ?></div>
    <?php endif; ?>

    <?php if (empty($items)): ?>
        <p class="tc-empty"><?php echo $I('plugin.task_center.empty'); ?></p>
    <?php else: ?>
    <?php foreach ($items as $it): ?>
        <?php
        $task = $it['task'];
        $title = (string)$task['title'] !== '' ? $I((string)$task['title']) : $I('plugin.task_center.type_' . (string)$task['type']);
        $desc = (string)$task['description'] !== '' ? $I((string)$task['description']) : '';
        $status = $it['status'];
        ?>
        <div class="tc-card<?php echo $status === 'dep' ? ' tc-dep' : ''; ?>">
            <div class="tc-icon"><?php echo $this->icon((string)$task['icon'], 22); ?></div>
            <div class="tc-body">
                <div class="tc-title-row">
                    <span class="tc-title"><?php echo $this->e($title); ?></span>
                    <?php if ($it['daily']): ?>
                    <span class="tc-daily"><?php echo $I('plugin.task_center.daily'); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($desc !== ''): ?>
                <p class="tc-desc"><?php echo $this->e($desc); ?></p>
                <?php endif; ?>
                <?php if ($status !== 'dep'): ?>
                <div class="tc-progress">
                    <div class="tc-progress-track"><div class="tc-progress-fill" data-progress="<?php echo (int)$it['percent']; ?>"></div></div>
                    <span class="tc-progress-text"><?php echo $I('plugin.task_center.progress', ['cur' => (int)$it['progress'], 'target' => (int)$it['target']]); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <div class="tc-side">
                <span class="tc-reward"><?php echo $this->icon('coins-alt', 12); ?> +<?php echo (int)$task['reward_points']; ?> <?php echo $I('plugin.task_center.reward_unit'); ?></span>
                <?php if ($status === 'can_claim'): ?>
                <form method="post" action="<?php echo $this->url('/task-center/claim'); ?>" class="tc-inline">
                    <input type="hidden" name="csrf" value="<?php echo $this->e($this->getData('csrfToken')); ?>">
                    <input type="hidden" name="task_id" value="<?php echo (int)$task['id']; ?>">
                    <button type="submit" class="mn-btn mn-btn-sm mn-btn-primary"><?php echo $I('plugin.task_center.claim'); ?></button>
                </form>
                <?php elseif ($status === 'claimed'): ?>
                <span class="tc-claimed"><?php echo $this->icon('check-circle', 12); ?> <?php echo $I('plugin.task_center.claimed'); ?></span>
                <?php elseif ($status === 'dep'): ?>
                <span class="tc-dep-tag"><?php echo $I('plugin.task_center.dep_unavailable'); ?></span>
                <?php else: ?>
                <span class="tc-pending"><?php echo $I('plugin.task_center.pending'); ?></span>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<script src="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/task_center/assets/script.js"></script>
<?php $this->endSection(); ?>
