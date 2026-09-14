<?php
/**
 * FlintHub — 社区治理插件 登录拦截
 * @file plugins/mod_system/hook/auth_login_check.php
 * @package Plugin\ModSystem
 * @version 1.0.0
 *
 * 由核心 AuthController(login) 在密码校验成功后、写 Session 前触发（见核心注入）。
 * 被封禁用户：设置 GLOBALS 标记，核心据此拒绝登录并提示。
 */
if (!isset($user)) return;

$uid = (int)($user['id'] ?? 0);
if ($uid <= 0) return;

if (\Plugin\ModSystem\Plugin::isBanned($uid)) {
    $GLOBALS['mod_system_login_blocked'] = true;
    $GLOBALS['mod_system_login_blocked_uid'] = $uid;
}