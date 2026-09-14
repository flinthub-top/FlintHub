<?php
/**
 * 红包插件钩子 — 发帖页"附带红包"折叠表单（post/create.php 提交按钮前的 post_create_extra 锚点）
 * 字段名 rp_total_points / rp_total_count / rp_expire_hours，由 thread_create_after 钩子读取创建红包。
 * 发帖表单本身含 csrf 隐藏域，此处无需重复输出 token。
 * @file plugins/red_packet/hook/post_create_extra.php
 * @package Plugin\RedPacket
 * @version 1.1.0
 */

// 未登录不发红包（发帖页本身要求登录，此处仅兜底）
if (!\app\Helpers\Auth::isLoggedIn()) return;

$I = function (string $k, array $p = []) { return \app\Helpers\I18n::get($k, $p); };
$bp = \defined('BASE_PATH') ? BASE_PATH : '';
$cssVer = @filemtime(__DIR__ . '/../assets/style.css') ?: 1;
?>
<link rel="stylesheet" href="<?php echo $bp; ?>/plugins/red_packet/assets/style.css?v=<?php echo $cssVer; ?>">
<div class="rp-thread-create">
    <details class="rp-thread-create-details">
        <summary class="rp-thread-create-summary">
            <?php echo \app\Helpers\I18n::get('plugin.red_packet.create'); ?> 🧧
            <span class="rp-thread-create-hint"><?php echo $I('plugin.red_packet.thread_create_hint'); ?></span>
        </summary>
        <div class="rp-thread-create-body">
            <div class="mn-flex mn-gap-10 mn-flex-wrap">
                <div class="rp-thread-create-field">
                    <label class="mn-label"><?php echo $I('plugin.red_packet.total_points'); ?></label>
                    <input type="number" name="rp_total_points" min="1" max="99999" class="mn-input rp-field-w">
                </div>
                <div class="rp-thread-create-field">
                    <label class="mn-label"><?php echo $I('plugin.red_packet.total_count'); ?></label>
                    <input type="number" name="rp_total_count" min="1" max="100" class="mn-input rp-field-w">
                </div>
                <div class="rp-thread-create-field">
                    <label class="mn-label"><?php echo $I('plugin.red_packet.expire'); ?></label>
                    <select name="rp_expire_hours" class="mn-select rp-field-w">
                        <option value="1"><?php echo $I('plugin.red_packet.expire_hour', ['n' => 1]); ?></option>
                        <option value="6"><?php echo $I('plugin.red_packet.expire_hour', ['n' => 6]); ?></option>
                        <option value="24" selected><?php echo $I('plugin.red_packet.expire_hour', ['n' => 24]); ?></option>
                        <option value="48"><?php echo $I('plugin.red_packet.expire_hour', ['n' => 48]); ?></option>
                        <option value="72"><?php echo $I('plugin.red_packet.expire_hour', ['n' => 72]); ?></option>
                        <option value="168"><?php echo $I('plugin.red_packet.expire_day', ['n' => 7]); ?></option>
                    </select>
                </div>
            </div>
            <p class="mn-fs-12 mn-text-muted mn-mt-6"><?php echo $I('plugin.red_packet.thread_create_min_hint'); ?></p>
        </div>
    </details>
</div>
