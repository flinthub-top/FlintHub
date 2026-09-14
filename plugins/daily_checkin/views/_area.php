<?php
/**
 * 每日签到插件 — 签到区域局部模板（Modern 主题，index 页面与 htmx 局部刷新共用）
 * @file assets/themes/modern/templates/plugins/daily_checkin/_area.php
 */
?>
<div class="mn-section dc-modern-wrap" id="checkinArea">
    <div class="dc-modern-body">
        <!-- 签到头部 -->
        <div class="mn-mb-20">
            <div class="mn-text-primary mn-mb-8"><?php echo $this->icon('calendar', 48); ?></div>
            <h1 class="mn-fs-22 mn-fw-700 dc-modern-title"><?php echo \app\Helpers\I18n::get('plugin.daily_checkin.title'); ?></h1>
            <p class="mn-text-muted mn-fs-14 dc-modern-subtitle"><?php echo \app\Helpers\I18n::get('plugin.daily_checkin.subtitle'); ?></p>
        </div>

        <!-- 消息提示 -->
        <?php $msg = $_SESSION['checkin_msg'] ?? ''; $success = $_SESSION['checkin_success'] ?? false; unset($_SESSION['checkin_msg'], $_SESSION['checkin_success'], $_SESSION['checkin_points'], $_SESSION['checkin_consecutive']); ?>
        <?php if ($msg): ?>
        <div class="mn-alert dc-modern-alert <?php echo $success ? 'mn-alert-success' : 'dc-modern-alert-warning'; ?>">
            <?php echo $this->e($msg); ?>
        </div>
        <?php endif; ?>

        <!-- 积分概览 -->
        <div class="mn-flex mn-gap-12 mn-mb-24">
            <div class="mn-flex-1 mn-bg-body mn-rounded-10 mn-p-16 mn-text-center mn-border">
                <div class="mn-fs-22 mn-fw-700 mn-text-primary"><?php echo $this->getData('points', 0); ?></div>
                <div class="mn-fs-13 mn-text-muted mn-mt-4"><?php echo \app\Helpers\I18n::get('plugin.daily_checkin.points_current'); ?></div>
            </div>
            <div class="mn-flex-1 mn-bg-body mn-rounded-10 mn-p-16 mn-text-center mn-border">
                <div class="mn-fs-22 mn-fw-700 mn-text-primary"><?php echo $this->getData('total_checkins', 0); ?></div>
                <div class="mn-fs-13 mn-text-muted mn-mt-4"><?php echo \app\Helpers\I18n::get('plugin.daily_checkin.total_checkins'); ?></div>
            </div>
            <div class="mn-flex-1 mn-bg-body mn-rounded-10 mn-p-16 mn-text-center mn-border">
                <div class="mn-fs-22 mn-fw-700 mn-text-primary"><?php echo $this->getData('consecutive', 0); ?></div>
                <div class="mn-fs-13 mn-text-muted mn-mt-4"><?php echo \app\Helpers\I18n::get('plugin.daily_checkin.consecutive'); ?></div>
            </div>
        </div>

        <!-- 签到按钮 -->
        <div class="mn-text-center mn-mb-24">
            <?php if ($this->getData('checkin_today', false)): ?>
                <button class="mn-btn mn-btn-primary dc-modern-btn-checked" disabled>
                    <?php echo $this->icon('check', 18); ?> <?php echo \app\Helpers\I18n::get('plugin.daily_checkin.checked_today'); ?>
                </button>
            <?php else: ?>
                <form method="post" action="<?php echo $this->url('/checkin'); ?>"
                              hx-post="<?php echo $this->url('/checkin'); ?>" hx-target="#checkinArea" hx-swap="outerHTML">
                    <input type="hidden" name="csrf" value="<?php echo $this->getData('csrfToken'); ?>">
                    <button type="submit" class="mn-btn mn-btn-primary mn-p-12-48 mn-fs-16 mn-rounded-pill">
                        <?php echo $this->icon('check', 18); ?> <?php echo \app\Helpers\I18n::get('plugin.daily_checkin.checkin_now'); ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <!-- 签到奖励说明 -->
        <div class="mn-mb-24 mn-p-16 mn-bg-body mn-rounded-10 mn-border">
            <h3 class="mn-fs-15 dc-modern-rules-title"><?php echo \app\Helpers\I18n::get('plugin.daily_checkin.rules_title'); ?></h3>
            <div class="mn-flex-col mn-gap-8">
                <div class="mn-flex-center mn-gap-8 mn-fs-14 mn-text-muted">
                    <span class="mn-fs-18"><?php echo $this->icon('gift', 18); ?></span>
                    <span><?php echo \app\Helpers\I18n::get('plugin.daily_checkin.rules_base', ['points' => (int)$this->getData('points_base', 2)]); ?></span>
                </div>
                <div class="mn-flex-center mn-gap-8 mn-fs-14 mn-text-muted">
                    <span class="mn-fs-18"><?php echo $this->icon('trophy', 18); ?></span>
                    <span><?php echo \app\Helpers\I18n::get('plugin.daily_checkin.rules_bonus', ['base' => (int)$this->getData('points_base', 2), 'bonus' => (int)$this->getData('points_bonus', 1)]); ?></span>
                </div>
                <div class="mn-flex-center mn-gap-8 mn-fs-14 mn-text-muted">
                    <span class="mn-fs-18"><?php echo $this->icon('star', 18); ?></span>
                    <span><?php echo \app\Helpers\I18n::get('plugin.daily_checkin.rules_cap', ['max' => 50]); ?></span>
                </div>
            </div>
        </div>

        <!-- 本月签到日历 -->
        <div>
            <div class="mn-flex-between mn-mb-12">
                <h3 class="mn-fs-15 dc-modern-cal-title"><?php echo \app\Helpers\I18n::get('plugin.daily_checkin.calendar_title', ['month' => $this->e($this->getData('month', ''))]); ?></h3>
                <div class="mn-flex-center mn-gap-6">
                    <?php $prevMonth = $this->getData('prev_month', ''); $nextMonth = $this->getData('next_month', ''); ?>
                    <?php if ($prevMonth): ?>
                        <a href="<?php echo $this->url('/checkin?month=' . $this->e($prevMonth)); ?>" class="mn-btn mn-btn-sm dc-modern-nav-btn">‹</a>
                    <?php else: ?>
                        <span class="mn-btn mn-btn-sm dc-modern-nav-btn dc-modern-nav-btn-disabled">‹</span>
                    <?php endif; ?>
                    <span class="mn-fs-13 mn-text-muted dc-modern-month-label"><?php echo $this->e($this->getData('month', '')); ?></span>
                    <?php if ($nextMonth): ?>
                        <a href="<?php echo $this->url('/checkin?month=' . $this->e($nextMonth)); ?>" class="mn-btn mn-btn-sm dc-modern-nav-btn">›</a>
                    <?php else: ?>
                        <span class="mn-btn mn-btn-sm dc-modern-nav-btn dc-modern-nav-btn-disabled">›</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mn-fs-12 mn-text-muted dc-modern-cal-header">
                <span><?php echo \app\Helpers\I18n::get('plugin.daily_checkin.week_sun'); ?></span><span><?php echo \app\Helpers\I18n::get('plugin.daily_checkin.week_mon'); ?></span><span><?php echo \app\Helpers\I18n::get('plugin.daily_checkin.week_tue'); ?></span><span><?php echo \app\Helpers\I18n::get('plugin.daily_checkin.week_wed'); ?></span><span><?php echo \app\Helpers\I18n::get('plugin.daily_checkin.week_thu'); ?></span><span><?php echo \app\Helpers\I18n::get('plugin.daily_checkin.week_fri'); ?></span><span><?php echo \app\Helpers\I18n::get('plugin.daily_checkin.week_sat'); ?></span>
            </div>
            <div class="dc-modern-cal-grid">
                <?php $calendar = $this->getData('calendar', []); ?>
                <?php $firstDay = !empty($calendar) ? date('w', strtotime($calendar[0]['date'])) : 0; ?>
                <?php for ($i = 0; $i < $firstDay; $i++): ?>
                    <div class="dc-modern-cal-empty"></div>
                <?php endfor; ?>
                <?php foreach ($calendar as $day):
                    $isChecked = $day['checked'];
                    $isToday = $day['is_today'];
                    $isFuture = $day['is_future'];
                ?>
                    <div class="dc-modern-cal-day<?php echo $isToday ? ' dc-modern-cal-day-today' : ($isFuture ? ' dc-modern-cal-day-future' : ''); ?>">
                        <?php if ($isChecked): ?>
                            <span class="dc-modern-checked-num<?php echo $isToday ? ' dc-modern-checked-today' : ''; ?>"><?php echo $day['day']; ?></span>
                        <?php else: ?>
                            <span class="dc-modern-day-num"><?php echo $day['day']; ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
