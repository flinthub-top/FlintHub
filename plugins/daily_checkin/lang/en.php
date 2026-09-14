<?php
/**
 * Daily Check-in Plugin Language Pack — English
 * Plugins' language packs go in plugins/{plugin}/lang/{lang}.php and are auto-merged by core I18n (no hooks needed)
 * @file plugins/daily_checkin/lang/en.php
 */
return [
    // Page & nav
    'plugin.daily_checkin.title'      => 'Daily Check-in',
    'plugin.daily_checkin.subtitle'   => 'Keep checking in to earn points',
    'plugin.daily_checkin.nav'        => 'Check-in',

    // Points overview
    'plugin.daily_checkin.points_current' => 'Current Points',
    'plugin.daily_checkin.total_checkins' => 'Total Check-ins',
    'plugin.daily_checkin.consecutive'    => 'Consecutive Days',

    // Check-in button
    'plugin.daily_checkin.checked_today' => 'Checked in today',
    'plugin.daily_checkin.checked'       => 'Checked in',
    'plugin.daily_checkin.checkin_now'   => 'Check in now',

    // Reward rules
    'plugin.daily_checkin.rules_title' => 'Check-in Rewards',
    'plugin.daily_checkin.rules_base'  => 'Base reward: <strong>{points}</strong> points',
    'plugin.daily_checkin.rules_bonus' => 'Streak bonus: <strong>{base} + (consecutive days - 1) × {bonus}</strong> points',
    'plugin.daily_checkin.rules_cap'   => 'Daily cap: <strong>{max}</strong> points',

    // Calendar
    'plugin.daily_checkin.calendar_title' => '{month} check-in calendar',
    'plugin.daily_checkin.month_format'   => '{month}/{year}',
    'plugin.daily_checkin.week_sun' => 'Su',
    'plugin.daily_checkin.week_mon' => 'Mo',
    'plugin.daily_checkin.week_tue' => 'Tu',
    'plugin.daily_checkin.week_wed' => 'We',
    'plugin.daily_checkin.week_thu' => 'Th',
    'plugin.daily_checkin.week_fri' => 'Fr',
    'plugin.daily_checkin.week_sat' => 'Sa',

    // Messages
    'plugin.daily_checkin.msg_already' => 'You have already checked in today!',
    'plugin.daily_checkin.msg_success' => 'Check-in successful! Earned {points} points, {consecutive} consecutive days 🎉',
    'plugin.daily_checkin.msg_success_persist' => 'Earned {points} points, {consecutive} consecutive days',
    'plugin.daily_checkin.msg_fail'    => 'Check-in failed, please try again later.',
];
