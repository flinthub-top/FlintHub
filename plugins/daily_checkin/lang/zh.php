<?php
/**
 * 每日签到插件语言包 — 简体中文
 * 说明：插件语言包放入 plugins/{插件名}/lang/{语言}.php 即自动生效（核心 I18n 自动合并，无需钩子）
 * @file plugins/daily_checkin/lang/zh.php
 */
return [
    // 页面与导航
    'plugin.daily_checkin.title'      => '每日签到',
    'plugin.daily_checkin.subtitle'   => '坚持签到，累积积分',
    'plugin.daily_checkin.nav'        => '签到',

    // 积分概览
    'plugin.daily_checkin.points_current' => '当前积分',
    'plugin.daily_checkin.total_checkins' => '累计签到',
    'plugin.daily_checkin.consecutive'    => '连续天数',

    // 签到按钮
    'plugin.daily_checkin.checked_today' => '今日已签到',
    'plugin.daily_checkin.checked'       => '已签到',
    'plugin.daily_checkin.checkin_now'   => '立即签到',

    // 奖励规则
    'plugin.daily_checkin.rules_title' => '签到奖励规则',
    'plugin.daily_checkin.rules_base'  => '基础奖励：<strong>{points}</strong> 积分',
    'plugin.daily_checkin.rules_bonus' => '连签奖励：<strong>{base} + (连续天数 - 1) × {bonus}</strong> 积分',
    'plugin.daily_checkin.rules_cap'   => '日签上限：<strong>{max}</strong> 积分',

    // 签到日历
    'plugin.daily_checkin.calendar_title' => '{month} 签到日历',
    'plugin.daily_checkin.month_format'   => '{year}年{month}月',
    'plugin.daily_checkin.week_sun' => '日',
    'plugin.daily_checkin.week_mon' => '一',
    'plugin.daily_checkin.week_tue' => '二',
    'plugin.daily_checkin.week_wed' => '三',
    'plugin.daily_checkin.week_thu' => '四',
    'plugin.daily_checkin.week_fri' => '五',
    'plugin.daily_checkin.week_sat' => '六',

    // 消息提示
    'plugin.daily_checkin.msg_already' => '今天已经签到过了哦！',
    'plugin.daily_checkin.msg_success' => '签到成功！获得 {points} 积分，已连续签到 {consecutive} 天 🎉',
    'plugin.daily_checkin.msg_success_persist' => '获得 {points} 积分，已连续签到 {consecutive} 天',
    'plugin.daily_checkin.msg_fail'    => '签到失败，请稍后重试',
];
