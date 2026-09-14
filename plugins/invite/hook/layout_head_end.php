<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 邀请注册钩子 — 在注册页面注入邀请码输入框
 * @file plugins/invite/hook/layout_head_end.php
 * @package Plugin\Invite
 * @version 1.0.0
 */

$bp = \defined('BASE_PATH') ? BASE_PATH : '';
if (!\app\Helpers\Auth::isLoggedIn() && strpos($_SERVER['REQUEST_URI'] ?? '', $bp . '/register') !== false) {
?>
<link rel="stylesheet" href="<?php echo $bp; ?>/plugins/invite/assets/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/style.css') ?: 1; ?>">
<div id="inviteConfig" hidden
     data-action="<?php echo $bp; ?>/register"
     data-label="<?php echo htmlspecialchars(\app\Helpers\I18n::get('plugin.invite.field_label'), ENT_QUOTES, 'UTF-8'); ?>"
     data-ph="<?php echo htmlspecialchars(\app\Helpers\I18n::get('plugin.invite.field_ph'), ENT_QUOTES, 'UTF-8'); ?>"></div>
<script src="<?php echo $bp; ?>/plugins/invite/assets/script.js?v=<?php echo @filemtime(__DIR__ . '/../assets/script.js') ?: 1; ?>"></script>
<?php
}
