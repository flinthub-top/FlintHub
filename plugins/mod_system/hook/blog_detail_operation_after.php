<?php
/**
 * FlintHub — 社区治理插件 博客详情正文操作区举报按钮
 * @file plugins/mod_system/hook/blog_detail_operation_after.php
 * @package Plugin\ModSystem
 * @version 1.0.0
 *
 * 由核心 blog/detail.php 注入：hook('blog_detail_operation_after', ['blog'=>$blog])
 */
if (!isset($blog)) return;
if (!\app\Helpers\Auth::isLoggedIn()) return;

$bid = (int)($blog['id'] ?? 0);
$uid = (int)($blog['user_id'] ?? 0);
if ($bid <= 0) return;

echo \Plugin\ModSystem\Plugin::reportButtonHtml('blog', $bid, $uid);