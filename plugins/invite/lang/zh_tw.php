<?php
/**
 * 邀請註冊外掛語言包 — 繁體中文
 * 外掛語言包放入 plugins/{外掛名}/lang/{語言}.php 即自動生效（核心 I18n 自動合併，無需鉤子）
 * @file plugins/invite/lang/zh_tw.php
 */
return [
    'plugin.invite.title'       => '我的邀請',
    'plugin.invite.nav'         => '邀請註冊',
    'plugin.invite.valid'       => '有效邀請碼',
    'plugin.invite.used'        => '已使用',
    'plugin.invite.cur_points'  => '目前積分',
    'plugin.invite.generate'    => '產生邀請碼',
    'plugin.invite.cost_points' => '消耗積分',
    'plugin.invite.expires_days'=> '有效期（天）',
    'plugin.invite.generate_btn'=> '產生邀請碼',
    'plugin.invite.records'     => '邀請記錄',
    'plugin.invite.empty'       => '還沒有產生過邀請碼',
    'plugin.invite.th_code'     => '邀請碼',
    'plugin.invite.th_cost'     => '消耗積分',
    'plugin.invite.th_status'   => '狀態',
    'plugin.invite.th_invitee'  => '被邀請人',
    'plugin.invite.th_created'  => '建立時間',
    'plugin.invite.status_used' => '✓ 已使用',
    'plugin.invite.status_expired' => '已過期',
    'plugin.invite.status_valid'   => '● 有效',

    // 控制器訊息
    'plugin.invite.err_points'  => '積分不足，目前積分：{cur}，需要：{need}',
    'plugin.invite.success'     => '邀請碼 <strong>{code}</strong> 已產生，消耗 {cost} 積分，{days} 天內有效',
    'plugin.invite.err_gen'     => '產生失敗，請稍後重試',

    // 註冊鉤子
    'plugin.invite.reg_required' => '邀請註冊已開啟，請輸入邀請碼',
    'plugin.invite.reg_invalid'  => '邀請碼無效或已過期',
    'plugin.invite.field_label'  => '邀請碼',
    'plugin.invite.field_ph'     => '請輸入邀請碼',
];
