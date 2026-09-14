<?php
/**
 * 每日簽到外掛語言包 — 繁體中文
 * 說明：外掛語言包放入 plugins/{外掛名}/lang/{語言}.php 即自動生效（核心 I18n 自動合併，無需鉤子）
 * @file plugins/daily_checkin/lang/zh_tw.php
 */
return [
    // 頁面與導覽
    'plugin.daily_checkin.title'      => '每日簽到',
    'plugin.daily_checkin.subtitle'   => '堅持簽到，累積積分',
    'plugin.daily_checkin.nav'        => '簽到',

    // 積分概覽
    'plugin.daily_checkin.points_current' => '目前積分',
    'plugin.daily_checkin.total_checkins' => '累計簽到',
    'plugin.daily_checkin.consecutive'    => '連續天數',

    // 簽到按鈕
    'plugin.daily_checkin.checked_today' => '今日已簽到',
    'plugin.daily_checkin.checked'       => '已簽到',
    'plugin.daily_checkin.checkin_now'   => '立即簽到',

    // 獎勵規則
    'plugin.daily_checkin.rules_title' => '簽到獎勵規則',
    'plugin.daily_checkin.rules_base'  => '基礎獎勵：<strong>{points}</strong> 積分',
    'plugin.daily_checkin.rules_bonus' => '連簽獎勵：<strong>{base} + (連續天數 - 1) × {bonus}</strong> 積分',
    'plugin.daily_checkin.rules_cap'   => '日簽上限：<strong>{max}</strong> 積分',

    // 簽到日曆
    'plugin.daily_checkin.calendar_title' => '{month} 簽到日曆',
    'plugin.daily_checkin.month_format'   => '{year}年{month}月',
    'plugin.daily_checkin.week_sun' => '日',
    'plugin.daily_checkin.week_mon' => '一',
    'plugin.daily_checkin.week_tue' => '二',
    'plugin.daily_checkin.week_wed' => '三',
    'plugin.daily_checkin.week_thu' => '四',
    'plugin.daily_checkin.week_fri' => '五',
    'plugin.daily_checkin.week_sat' => '六',

    // 訊息提示
    'plugin.daily_checkin.msg_already' => '今天已經簽到過了喔！',
    'plugin.daily_checkin.msg_success' => '簽到成功！獲得 {points} 積分，已連續簽到 {consecutive} 天 🎉',
    'plugin.daily_checkin.msg_success_persist' => '獲得 {points} 積分，已連續簽到 {consecutive} 天',
    'plugin.daily_checkin.msg_fail'    => '簽到失敗，請稍後重試',
];
