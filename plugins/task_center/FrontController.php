<?php
/**
 * FlintHub — 任务中心插件 前台控制器
 * /task-center 任务列表（惰性统计刷新 + 状态装饰）+ 领取奖励
 * @file plugins/task_center/FrontController.php
 * @package Plugin\TaskCenter
 * @version 1.0.0
 */

namespace Plugin\TaskCenter;

use app\Core\Controller;
use app\Helpers\Csrf;
use app\Helpers\I18n;

class FrontController extends Controller
{
    public function index()
    {
        $this->requireLogin();
        $currentUser = $this->currentUser();
        $userId = (int)($currentUser['id'] ?? 0);

        // 建表幂等（前台首访时兜底，避免仅靠 init_after 缓存守卫）
        \app\Helpers\Plugin::ensureSchema('task_center', \Plugin\TaskCenter\Plugin::ddl());
        \Plugin\TaskCenter\Plugin::seedDefaults();

        $tasks = \Plugin\TaskCenter\Plugin::getTasks(true);
        $depOff = \Plugin\TaskCenter\Plugin::refreshLazyProgress($userId);
        $rows = \Plugin\TaskCenter\Plugin::getProgressMap($userId);
        $today = date('Y-m-d');

        // 状态装饰：dep / claimed / can_claim / progress
        $items = [];
        foreach ($tasks as $t) {
            $tid = (int)$t['id'];
            $row = $rows[$tid] ?? null;
            $target = (int)$t['target'];
            $daily = (int)$t['daily_reset'] === 1;
            $progress = (int)($row['progress'] ?? 0);
            $claimed = (int)($row['claimed'] ?? 0) === 1;
            $completedAt = (string)($row['completed_at'] ?? '');
            $doneToday = !$daily || \substr($completedAt, 0, 10) === $today;

            $status = 'progress';
            if (isset($depOff[$tid])) {
                $status = 'dep';
            } elseif ($claimed) {
                $status = 'claimed';
            } elseif ($doneToday && $progress >= $target) {
                $status = 'can_claim';
            }

            $items[] = [
                'task' => $t,
                'progress' => ($daily && !$doneToday) ? 0 : $progress, // 跨天未领的每日任务展示归零
                'target' => $target,
                'daily' => $daily,
                'status' => $status,
                'percent' => $target > 0 ? \min(100, (int)round(($daily && !$doneToday ? 0 : $progress) / $target * 100)) : 0,
            ];
        }

        $flashMsg = $_SESSION['tc_flash_msg'] ?? '';
        $flashType = $_SESSION['tc_flash_type'] ?? '';
        unset($_SESSION['tc_flash_msg'], $_SESSION['tc_flash_type']);

        $this->view('plugins/task_center/index', [
            'items' => $items,
            'points' => (int)($currentUser['points'] ?? 0),
            'flashMsg' => $flashMsg,
            'flashType' => $flashType,
            '__nav_active' => 'task-center',
        ]);
    }

    public function claim()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $this->requireLogin();
        $currentUser = $this->currentUser();
        $userId = (int)($currentUser['id'] ?? 0);
        $taskId = (int)($_POST['task_id'] ?? 0);

        list($ok, $error) = \Plugin\TaskCenter\Plugin::claim($userId, $taskId);
        if ($ok) {
            $_SESSION['tc_flash_msg'] = I18n::get('plugin.task_center.msg_claimed');
            $_SESSION['tc_flash_type'] = 'success';
        } else {
            $_SESSION['tc_flash_msg'] = I18n::get($error);
            $_SESSION['tc_flash_type'] = 'error';
        }
        $this->redirect('/task-center');
    }
}
