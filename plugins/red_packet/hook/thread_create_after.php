<?php
/**
 * 红包插件钩子 — 发帖附带红包
 * 核心在发帖成功后触发 thread_create_after，注入 thread_id / user_id / category_id；
 * 读取发帖表单中的红包字段（rp_total_points / rp_total_count / rp_expire_hours），
 * 校验通过则创建关联该帖的红包；未填写则静默跳过（不影响发帖）。
 * @file plugins/red_packet/hook/thread_create_after.php
 * @package Plugin\RedPacket
 * @version 1.1.0
 */

$rpThreadId = (int)($params['thread_id'] ?? 0);
$rpUserId = (int)($params['user_id'] ?? 0);
if ($rpThreadId <= 0 || $rpUserId <= 0) return;

// 未填写红包字段 → 跳过（发帖不受影响）
$rpTotal = (int)($_POST['rp_total_points'] ?? 0);
$rpCount = (int)($_POST['rp_total_count'] ?? 0);
$rpHours = (int)($_POST['rp_expire_hours'] ?? 0);
if ($rpTotal <= 0 || $rpCount <= 0) return;

try {
    // 与 /redpacket 创建同规则校验：总积分 ≥ 份数、份数 1-100、有效期 1-168 小时
    if ($rpTotal < $rpCount) return;
    if ($rpCount < 1 || $rpCount > 100) return;
    if ($rpHours < 1 || $rpHours > 168) $rpHours = 24;

    // 帖子标题快照（thread_create_after 仅传 thread_id/user_id/category_id，
    // 标题从发帖表单取——post/create.php 字段名 title，与核心入库字段一致）
    $rpTitle = trim((string)($_POST['title'] ?? ''));

    \Plugin\RedPacket\Plugin::createForThread(
        $rpUserId,
        $rpTotal,
        $rpCount,
        $rpHours,
        $rpThreadId,
        $rpTitle
    );
} catch (\Throwable $e) {
    // 发红包失败不影响发帖主流程（积分不足等已在 createForThread 内回滚），仅记录日志
    \error_log('red_packet thread_create_after: ' . $e->getMessage());
}
