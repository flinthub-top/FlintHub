<?php
/**
 * FlintHub — 社区治理插件 个人主页用户卡片举报按钮
 * @file plugins/mod_system/hook/profile_user_card_after.php
 * @package Plugin\ModSystem
 * @version 1.0.0
 *
 * 由核心 profile/index.php 注入：hook('profile_user_card_after', ['user'=>$user])
 */
if (!isset($user)) return;
if (!\app\Helpers\Auth::isLoggedIn()) return;

$uid = (int)($user['id'] ?? 0);
if ($uid <= 0) return;

echo \Plugin\ModSystem\Plugin::reportButtonHtml('user', $uid, $uid);