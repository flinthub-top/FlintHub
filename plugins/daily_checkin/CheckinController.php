<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 每日签到控制器 — 签到/补签、签到记录、积分发放
 * @file plugins/daily_checkin/CheckinController.php
 * @package Plugin\DailyCheckin
 * @version 1.0.0
 */

namespace Plugin\DailyCheckin;

use app\Core\Controller;
use app\Helpers\Auth;
use app\Helpers\Csrf;
use app\Helpers\Settings;

class CheckinController extends Controller
{
    /** [SplitDB] 插件独立库连接（签到记录表） */
    public static function db(): \PDO
    {
        return \app\Helpers\Plugin::db('daily_checkin');
    }

    /**
     * 显示签到页面
     */
    public function index()
    {
        $this->requireLogin();

        $user = Auth::getCurrentUser();
        $db = self::db();
        $today = date('Y-m-d');

        // 日历翻页：‹ › 链接携带 ?month=YYYY-MM，需透传给 buildAreaData
        // （内部会校验格式并钳制 2020-01 ~ 当前月，非法/缺失值自动回落当月）
        $requestMonth = isset($_GET['month']) ? trim((string)$_GET['month']) : '';

        $data = $this->buildAreaData($user, $db, $today, $requestMonth);
        $data['__nav_active'] = 'checkin';

        $this->view('plugins/daily_checkin/index', $data);
    }

    /**
     * 组装签到区域数据（index 页面与 htmx 局部刷新共用）
     */
    private function buildAreaData(array $user, $db, string $today, string $requestMonth = '')
    {
        $data = [];

        try {
            // 获取请求的月份（默认当月）
            if ($requestMonth === '' || !preg_match('/^\d{4}-\d{2}$/', $requestMonth)) {
                $requestMonth = date('Y-m');
            }
            // 防超出合理范围（2020-01 ~ 当前月）
            $maxMonth = date('Y-m');
            if ($requestMonth > $maxMonth) $requestMonth = $maxMonth;
            if ($requestMonth < '2020-01') $requestMonth = '2020-01';

            $yearMonth = $requestMonth;
            $monthStart = $yearMonth . '-01';
            $monthEnd = date('Y-m-t', strtotime($monthStart));
            $monthLabel = \app\Helpers\I18n::get('plugin.daily_checkin.month_format', ['year' => date('Y', strtotime($monthStart)), 'month' => date('m', strtotime($monthStart))]);

            // 上月 / 下月
            $prevMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
            $nextMonth = date('Y-m', strtotime($monthStart . ' +1 month'));
            if ($prevMonth < '2020-01') $prevMonth = '';
            if ($nextMonth > $maxMonth) $nextMonth = '';

            // 今日签到情况
            $stmt = $db->prepare("SELECT * FROM daily_checkin WHERE user_id = :uid AND checkin_date = :date");
            $stmt->execute([':uid' => $user['id'], ':date' => $today]);
            $todayCheckin = $stmt->fetch(\PDO::FETCH_ASSOC);
            $data['checkin_today'] = !empty($todayCheckin);

            // 签到统计
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM daily_checkin WHERE user_id = :uid");
            $stmt->execute([':uid' => $user['id']]);
            $totalCheckins = $stmt->fetch(\PDO::FETCH_ASSOC);
            $data['total_checkins'] = (int)($totalCheckins['cnt'] ?? 0);

            // 获取指定月份的签到记录
            $stmt = $db->prepare(
                "SELECT checkin_date, points, consecutive_days FROM daily_checkin 
                 WHERE user_id = :uid AND checkin_date >= :start AND checkin_date <= :end 
                 ORDER BY checkin_date DESC"
            );
            $stmt->execute([':uid' => $user['id'], ':start' => $monthStart, ':end' => $monthEnd]);
            $monthRecords = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $data['month_records'] = $monthRecords;

            // 生成日历数据
            $calendar = [];
            $todayTimestamp = time();
            $monthDays = date('t', strtotime($monthStart));
            for ($d = 1; $d <= $monthDays; $d++) {
                $date = sprintf('%s-%02d', $yearMonth, $d);
                $checked = false;
                foreach ($monthRecords as $r) {
                    if ($r['checkin_date'] === $date) {
                        $checked = true;
                        break;
                    }
                }
                $calendar[] = [
                    'day' => $d,
                    'date' => $date,
                    'checked' => $checked,
                    'is_today' => ($date === $today),
                    'is_future' => (strtotime($date) > $todayTimestamp),
                ];
            }
            $data['calendar'] = $calendar;
            $data['month'] = $monthLabel;
            $data['today'] = $today;
            $data['consecutive'] = $todayCheckin['consecutive_days'] ?? 0;
            $data['points'] = $user['points'] ?? 0;
            $data['prev_month'] = $prevMonth;
            $data['next_month'] = $nextMonth;
            $data['current_month'] = $yearMonth;

            // 签到奖励配置
            $data['points_base'] = (int)Settings::get('checkin_points_base', 2);
            $data['points_bonus'] = (int)Settings::get('checkin_points_bonus', 1);
        } catch (\Throwable $e) {
            $data['checkin_today'] = false;
            $data['total_checkins'] = 0;
            $data['calendar'] = [];
            $data['month'] = \app\Helpers\I18n::get('plugin.daily_checkin.month_format', ['year' => date('Y'), 'month' => date('m')]);
            $data['today'] = $today;
            $data['points'] = $user['points'] ?? 0;
            $data['points_base'] = 2;
            $data['points_bonus'] = 1;
        }

        return $data;
    }

    /**
     * 执行签到
     */
    public function doCheckin()
    {
        $this->requireLogin();

        Csrf::verifyOrDie($_POST['csrf'] ?? '');

        $user = Auth::getCurrentUser();
        $db = self::db(); // [SplitDB] 签到记录独立库
        $coreDb = \app\Core\Database::getInstance(); // 核心库（users 积分）
        $today = date('Y-m-d');
        $isHtmx = !empty($_SERVER['HTTP_HX_REQUEST']);
        // 紧凑模式：右栏侧栏小部件触发的签到，返回紧凑模板 `_sidebar`（而非整页 `_area`）
        $compact = ($_POST['widget'] ?? '') === 'sidebar';
        $areaView = $compact ? 'plugins/daily_checkin/_sidebar' : 'plugins/daily_checkin/_area';

        try {
            // 检查今日是否已签到
            $stmt = $db->prepare("SELECT id FROM daily_checkin WHERE user_id = :uid AND checkin_date = :date");
            $stmt->execute([':uid' => $user['id'], ':date' => $today]);
            $exists = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($exists) {
                if ($isHtmx) {
                    $_SESSION['checkin_msg'] = \app\Helpers\I18n::get('plugin.daily_checkin.msg_already');
                    $_SESSION['checkin_success'] = false;
                    $data = $this->buildAreaData($user, $db, $today);
                    $data['csrfToken'] = Csrf::token();
                    $this->viewRaw($areaView, $data);
                    exit;
                }
                $_SESSION['checkin_msg'] = \app\Helpers\I18n::get('plugin.daily_checkin.msg_already');
                $_SESSION['checkin_success'] = false;
                header('Location: /checkin');
                exit;
            }

            // 获取上次签到日期，计算连续天数
            $stmt = $db->prepare(
                "SELECT checkin_date, consecutive_days FROM daily_checkin 
                 WHERE user_id = :uid ORDER BY checkin_date DESC LIMIT 1"
            );
            $stmt->execute([':uid' => $user['id']]);
            $lastCheckin = $stmt->fetch(\PDO::FETCH_ASSOC);

            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $consecutive = 0;
            if ($lastCheckin && $lastCheckin['checkin_date'] === $yesterday) {
                $consecutive = (int)$lastCheckin['consecutive_days'] + 1;
            } else {
                $consecutive = 1;
            }

            // 计算积分
            $pointsBase = (int)Settings::get('checkin_points_base', 2);
            $pointsBonus = (int)Settings::get('checkin_points_bonus', 1);
            $pointsEarned = $pointsBase + ($consecutive - 1) * $pointsBonus;
            if ($pointsEarned > 50) $pointsEarned = 50; // 上限

            // 写入签到记录
            $stmt = $db->prepare(
                "INSERT INTO daily_checkin (user_id, checkin_date, points, consecutive_days, created_at) 
                 VALUES (:uid, :date, :points, :consecutive, :now)"
            );
            $stmt->execute([
                ':uid' => $user['id'],
                ':date' => $today,
                ':points' => $pointsEarned,
                ':consecutive' => $consecutive,
                ':now' => date('Y-m-d H:i:s'),
            ]);

            // 更新用户积分（核心库 users 表）
            $coreDb->query(
                "UPDATE users SET points = COALESCE(points, 0) + :points WHERE id = :uid",
                [':points' => $pointsEarned, ':uid' => $user['id']]
            );

            // 使用运行时计数器记录总签到次数
            \app\Helpers\Settings::runtimeIncr('_checkin_total');

            if ($isHtmx) {
                // htmx 请求：重渲染完整签到区域（含成功提示、积分/日历更新）
                $_SESSION['checkin_msg'] = \app\Helpers\I18n::get('plugin.daily_checkin.msg_success_persist', ['points' => $pointsEarned, 'consecutive' => $consecutive]);
                $_SESSION['checkin_success'] = true;
                $_SESSION['checkin_points'] = $pointsEarned;
                $_SESSION['checkin_consecutive'] = $consecutive;
                unset($_SESSION['_checkin_' . $user['id']]);
                // 刷新积分快照（签到后数据库已更新，$user 仍是旧值）
                $fresh = $coreDb->fetchOne("SELECT points FROM users WHERE id = :id", [':id' => $user['id']]);
                $user['points'] = $fresh['points'] ?? (($user['points'] ?? 0) + $pointsEarned);
                $data = $this->buildAreaData($user, $db, $today);
                $data['csrfToken'] = Csrf::token();
                $this->viewRaw($areaView, $data);
                exit;
            }

            $_SESSION['checkin_msg'] = \app\Helpers\I18n::get('plugin.daily_checkin.msg_success_persist', ['points' => $pointsEarned, 'consecutive' => $consecutive]);
            $_SESSION['checkin_success'] = true;
            $_SESSION['checkin_points'] = $pointsEarned;
            $_SESSION['checkin_consecutive'] = $consecutive;
            // 清除 Session 缓存，确保重定向后显示已签到状态
            unset($_SESSION['_checkin_' . $user['id']]);

        } catch (\Throwable $e) {
            if ($isHtmx) {
                $_SESSION['checkin_msg'] = \app\Helpers\I18n::get('plugin.daily_checkin.msg_fail');
                $_SESSION['checkin_success'] = false;
                $data = $this->buildAreaData($user, $db, $today);
                $data['csrfToken'] = Csrf::token();
                $this->viewRaw($areaView, $data);
                exit;
            }
            $_SESSION['checkin_msg'] = \app\Helpers\I18n::get('plugin.daily_checkin.msg_fail');
            $_SESSION['checkin_success'] = false;
        }

        header('Location: /checkin');
        exit;
    }
}
