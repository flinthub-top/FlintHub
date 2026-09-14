<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 勋章中心前台控制器 — 勋章墙 + 佩戴设置
 * @file plugins/medal/MedalController.php
 * @package Plugin\Medal
 * @version 1.0.0
 */

namespace Plugin\Medal;

use app\Core\Controller;
use app\Helpers\Csrf;

class MedalController extends Controller
{
    /**
     * 勋章墙：全部勋章 + 我的勋章 + 佩戴选择
     */
    public function index()
    {
        $currentUser = $this->currentUser();
        $userId = $currentUser ? (int)$currentUser['id'] : 0;

        $medals = Plugin::getMedals(true);
        $ownedIds = $userId > 0 ? Plugin::getUserMedalIds($userId) : [];
        $wearing = $userId > 0 ? Plugin::getWearingMedals($userId) : [];
        $wearingIds = array_map(function ($m) { return (int)$m['id']; }, $wearing);
        $wearingIds = array_fill_keys($wearingIds, true);

        // 惰性检查自动规则（登录用户每次访问勋章墙核对一次）
        $newGranted = 0;
        if ($userId > 0) {
            $newGranted = Plugin::checkAutoRules($userId);
            if ($newGranted > 0) {
                // 重新拉取
                $medals = Plugin::getMedals(true);
                $ownedIds = Plugin::getUserMedalIds($userId);
            }
        }

        $this->view('plugins/medal/index', [
            'medals' => $medals,
            'ownedIds' => $ownedIds,
            'wearingIds' => $wearingIds,
            'wearLimit' => Plugin::WEAR_LIMIT,
            'newGranted' => $newGranted,
            'isOwner' => $userId > 0,
            '__nav_active' => '',
        ]);
    }

    /**
     * 保存佩戴（AJAX POST）
     * 入参：medal_ids[]（最多 3 个）
     */
    public function wear()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'msg' => \app\Helpers\I18n::get('plugin.medal.msg_method')], 405);
        }
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $this->requireLogin();
        $currentUser = $this->currentUser();
        $userId = (int)$currentUser['id'];

        $medalIds = isset($_POST['medal_ids']) && is_array($_POST['medal_ids'])
            ? array_map('intval', $_POST['medal_ids'])
            : [];

        [$ok, $msg] = Plugin::setWearing($userId, $medalIds);
        $this->json(['ok' => $ok, 'msg' => $msg]);
    }
}
