<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * SEO 后台控制器 — SEO 设置管理
 * @file plugins/seo/AdminController.php
 * @package Plugin\Seo
 * @version 1.0.0
 */

namespace Plugin\Seo;

use app\Controllers\Admin\BaseController;
use app\Helpers\Csrf;

class AdminController extends BaseController
{
    public function index()
    {
        $settings = Plugin::getAllSettings();

        $error   = $_SESSION['seo_error'] ?? '';
        $success = $_SESSION['seo_success'] ?? '';
        unset($_SESSION['seo_error'], $_SESSION['seo_success']);

        // 计算站点 URL（从 SITE_URL 常量取）
        $siteUrl = rtrim(SITE_URL, '/');

        $this->view('plugins/seo/admin', [
            'settings'   => $settings,
            'error'      => $error,
            'success'    => $success,
            'siteUrl'    => $siteUrl,
            '__nav_active' => 'seo',
        ]);
    }

    public function save()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');

        $allowed = array_keys(Plugin::DEFAULTS);
        $data = [];
        foreach ($allowed as $key) {
            $data[$key] = trim($_POST[$key] ?? '');
        }

        Plugin::saveSettings($data);
        $_SESSION['seo_success'] = \app\Helpers\I18n::get('plugin.seo.saved');

        $this->redirect('/admin/seo');
    }
}
