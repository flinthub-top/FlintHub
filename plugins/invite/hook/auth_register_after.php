<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 邀请注册钩子 — 注册成功后标记邀请码已使用
 * @file plugins/invite/hook/auth_register_after.php
 * @package Plugin\Invite
 * @version 1.0.0
 */

$inviteId = $_SESSION['validated_invite_id'] ?? 0;
if ($inviteId > 0) {
    unset($_SESSION['validated_invite_id']);
    try {
        $db = \app\Helpers\Plugin::db('invite'); // [SplitDB] 独立库
        $db->prepare('UPDATE invites SET used_by_user_id = :uid, used_at = :now WHERE id = :id')
           ->execute([':uid' => $user_id, ':id' => $inviteId, ':now' => date('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {
        \error_log('invite auth_register_after error: ' . $e->getMessage());
    }
}
