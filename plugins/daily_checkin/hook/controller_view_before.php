<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 每日签到钩子 — 注入签到数据到视图中
 * @file plugins/daily_checkin/hook/controller_view_before.php
 * @package Plugin\DailyCheckin
 * @version 1.1.0
 */

use app\Helpers\Auth;

if (\app\Helpers\Plugin::isActivated('daily_checkin')) {
    if (Auth::isLoggedIn()) {
        $user = Auth::getCurrentUser();
        if ($user) {
            $userId = (int)$user['id'];
            $today = date('Y-m-d');

            try {
                // [SplitDB] 签到记录表在插件独立库
                $db = \app\Helpers\Plugin::db('daily_checkin');
                $stmt = $db->prepare("SELECT points, consecutive_days FROM daily_checkin WHERE user_id = :uid AND checkin_date = :date");
                $stmt->execute([':uid' => $userId, ':date' => $today]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                $data['checkin_today'] = !empty($row);
                $data['checkin_today_points'] = $row['points'] ?? 0;
                $data['checkin_consecutive'] = $row['consecutive_days'] ?? 0;
            } catch (\Throwable $e) {
                $data['checkin_today'] = false;
                $data['checkin_today_points'] = 0;
                $data['checkin_consecutive'] = 0;
            }

            $data['checkin_points'] = $user['points'] ?? 0;
        }
    }
}
