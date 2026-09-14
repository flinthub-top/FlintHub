<?php
/**
 * 邀请注册插件语言包 — 简体中文
 * 插件语言包放入 plugins/{插件名}/lang/{语言}.php 即自动生效（核心 I18n 自动合并，无需钩子）
 * @file plugins/invite/lang/zh.php
 */
return [
    'plugin.invite.title'       => '我的邀请',
    'plugin.invite.nav'         => '邀请注册',
    'plugin.invite.valid'       => '有效邀请码',
    'plugin.invite.used'        => '已使用',
    'plugin.invite.cur_points'  => '当前积分',
    'plugin.invite.generate'    => '生成邀请码',
    'plugin.invite.cost_points' => '消耗积分',
    'plugin.invite.expires_days'=> '有效期（天）',
    'plugin.invite.generate_btn'=> '生成邀请码',
    'plugin.invite.records'     => '邀请记录',
    'plugin.invite.empty'       => '还没有生成过邀请码',
    'plugin.invite.th_code'     => '邀请码',
    'plugin.invite.th_cost'     => '消耗积分',
    'plugin.invite.th_status'   => '状态',
    'plugin.invite.th_invitee'  => '被邀请人',
    'plugin.invite.th_created'  => '创建时间',
    'plugin.invite.status_used' => '✓ 已使用',
    'plugin.invite.status_expired' => '已过期',
    'plugin.invite.status_valid'   => '● 有效',

    // 控制器消息
    'plugin.invite.err_points'  => '积分不足，当前积分：{cur}，需要：{need}',
    'plugin.invite.success'     => '邀请码 <strong>{code}</strong> 已生成，消耗 {cost} 积分，{days} 天内有效',
    'plugin.invite.err_gen'     => '生成失败，请稍后重试',

    // 注册钩子
    'plugin.invite.reg_required' => '邀请注册已开启，请输入邀请码',
    'plugin.invite.reg_invalid'  => '邀请码无效或已过期',
    'plugin.invite.field_label'  => '邀请码',
    'plugin.invite.field_ph'     => '请输入邀请码',
];
