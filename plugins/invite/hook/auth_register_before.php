<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 邀请注册钩子 — 注册前校验邀请码
 * @file plugins/invite/hook/auth_register_before.php
 * @package Plugin\Invite
 * @version 1.0.0
 */

$inviteCode = trim($_POST['invite_code'] ?? '');
if ($inviteCode === '') {
    $GLOBALS['auth_register_error'] = \app\Helpers\I18n::get('plugin.invite.reg_required');
    return;
}

try {
    $db = \app\Helpers\Plugin::db('invite'); // [SplitDB] 独立库
    $stmt = $db->prepare('SELECT * FROM invites WHERE code = :code AND used_by_user_id IS NULL AND (expires_at IS NULL OR expires_at > :now)');
    $stmt->execute([':code' => $inviteCode, ':now' => date('Y-m-d H:i:s')]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    \error_log('invite auth_register_before error: ' . $e->getMessage());
    $row = false;
}
if (!$row) {
    $GLOBALS['auth_register_error'] = \app\Helpers\I18n::get('plugin.invite.reg_invalid');
    return;
}

// 校验通过，将邀请码 ID 存入 session，供注册后使用
$_SESSION['validated_invite_id'] = (int)$row['id'];
