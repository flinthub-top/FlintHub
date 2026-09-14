<?php
/**
 * 勋章中心钩子 — points_mall 商城兑换勋章事件（§11.6 钩子事件解耦：两插件零直接引用）
 * 由 points_mall redeem() 兑换 medal 类型商品时触发；发放成功后置 GLOBALS 标记供发起方判定。
 * @file plugins/medal/hook/points_mall_medal_redeem.php
 * @package Plugin\Medal
 * @version 1.0.0
 */

$uid = (int)($params['user_id'] ?? 0);
$medalId = (int)($params['medal_id'] ?? 0);
$source = (string)($params['source'] ?? 'shop');
if ($uid <= 0 || $medalId <= 0) return;

try {
    if (\Plugin\Medal\Plugin::grant($uid, $medalId, $source)) {
        $GLOBALS['points_mall_medal_granted'] = true;
    }
} catch (\Throwable $e) {
    \error_log('medal points_mall_medal_redeem error: ' . $e->getMessage());
}
