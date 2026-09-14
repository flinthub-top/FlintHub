<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 后台等级管理控制器 — 积分等级配置、经验值规则
 * @file app/Controllers/Admin/LevelController.php
 * @package app\Controllers\Admin
 */

namespace app\Controllers\Admin;

use app\Helpers\Csrf;
use app\Helpers\Points;

class LevelController extends BaseController
{
    public function index()
    {
        Points::ensureTable();

        $msg = ''; $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $action = $_POST['action'] ?? '';
            if ($action === 'save') {
                $level = (int)($_POST['level'] ?? 0);
                $title = trim($_POST['title'] ?? '');
                $points = (int)($_POST['required_points'] ?? 0);
                $icon = trim($_POST['icon'] ?? '');
                $color = trim($_POST['color'] ?? '#999');
                if ($level < 1 || $level > 99) { $error = \app\Helpers\I18n::get('admin.level_range_invalid'); }
                elseif (empty($title)) { $error = \app\Helpers\I18n::get('admin.level_title_required'); }
                elseif ($points < 0) { $error = \app\Helpers\I18n::get('admin.level_points_negative'); }
                else {
                    Points::saveLevelConfig($level, $title, $points, $icon, $color);
                    $msg = $level > 0 ? \app\Helpers\I18n::get('admin.level_saved', ['level' => $level]) : '';
                }
            } elseif ($action === 'delete') {
                $level = (int)($_POST['level'] ?? 0);
                if ($level > 0) { Points::deleteLevelConfig($level); $msg = \app\Helpers\I18n::get('admin.level_deleted', ['level' => $level]); }
            }
            $this->redirect('/admin/levels' . ($error ? '?error=' . urlencode($error) : ''));
        }

        $levels = Points::getAllConfigs();
        $error = htmlspecialchars($_GET['error'] ?? '', ENT_QUOTES, 'UTF-8');
        $this->view('admin/levels', ['levels' => $levels, 'msg' => $msg, 'error' => $error, '__nav_active' => 'levels']);
    }
}
