<?php
/**
 * 每日签到插件 — 紧凑侧栏小部件（Modern 主题，右栏“发帖”按钮位）
 * 初始渲染仅依赖控制器视图钩子注入的 checkin_today（不增加首页查询）；
 * 点击后经 htmx POST /checkin，由 CheckinController 紧凑渲染回本模板并原地替换。
 * @file plugins/daily_checkin/views/_sidebar.php
 */
?>
<link rel="stylesheet" href="<?php echo \defined('BASE_PATH') ? BASE_PATH : ''; ?>/plugins/daily_checkin/assets/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/style.css') ?: 1; ?>">
<div id="checkinSidebar" class="mn-flex-1">
    <?php $msg = $_SESSION['checkin_msg'] ?? ''; $success = $_SESSION['checkin_success'] ?? false;
    $c = (int)$this->getData('consecutive', 0); unset($_SESSION['checkin_msg'], $_SESSION['checkin_success'], $_SESSION['checkin_points'], $_SESSION['checkin_consecutive']); ?>
    <?php if (($this->getData('checkin_today') ?? false)): ?>
        <a href="<?php echo $this->url('/checkin'); ?>" class="mn-btn mn-btn-sm mn-flex-1 mn-w-full mn-text-center" title="<?php echo $c > 1 ? '连续签到 ' . $c . ' 天' : ''; ?>">
            <?php echo $this->icon('check', 12); ?> <?php echo \app\Helpers\I18n::get('plugin.daily_checkin.checked'); ?>
        </a>
    <?php else: ?>
        <form method="post" action="<?php echo $this->url('/checkin'); ?>" hx-post="<?php echo $this->url('/checkin'); ?>" hx-target="#checkinSidebar" hx-swap="outerHTML" class="mn-flex-1">
            <input type="hidden" name="csrf" value="<?php echo $this->e(\app\Helpers\Csrf::token()); ?>">
            <input type="hidden" name="widget" value="sidebar">
            <button type="submit" class="mn-btn mn-btn-sm mn-btn-primary mn-flex-1 mn-w-full mn-text-center"><?php echo $this->icon('check', 12); ?> <?php echo \app\Helpers\I18n::get('plugin.daily_checkin.checkin_now'); ?></button>
        </form>
    <?php endif; ?>
</div>
<?php if ($msg): ?>
<!-- 签到反馈：out-of-band 原位替换用户卡下方的“每日签到”提示行 -->
<div id="checkinHint" hx-swap-oob="true" class="mn-border-t mn-mt-12 mn-pt-10 mn-fs-12 mn-text-center dc-checkin-hint mn-<?php echo $success ? 'text-success' : 'text-warning'; ?>"><?php echo $this->e($msg); ?></div>
<?php endif; ?>