<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 后台主题管理控制器 — 主题安装/切换、自定义 CSS、模板编辑
 * @file app/Controllers/Admin/ThemeController.php
 * @package app\Controllers\Admin
 */

namespace app\Controllers\Admin;

use app\Helpers\Csrf;
use app\Helpers\Theme;
use app\Helpers\Settings;
use app\Models\Setting;

class ThemeController extends BaseController
{
    public function index()
    {
        $themes = Theme::getAvailable();
        $currentDefault = Settings::get('default_theme');
        $settingModel = new Setting();
        $customCss = $settingModel->getValue('custom_css', '');

        // 界面选项（隐藏右栏 / 夜间自动切换暗夜星辰 / 列表内容模式默认值）
        $hideRightSidebar = (int)$settingModel->getValue('theme_hide_right_sidebar', '0');
        $nightAuto = (int)$settingModel->getValue('theme_night_auto', '0');
        $nightStart = (int)$settingModel->getValue('theme_night_start', '19');
        $nightEnd = (int)$settingModel->getValue('theme_night_end', '7');
        // 列表内容模式站点默认：开关默认开；默认长度 50~200，默认 80（前台用户可覆盖）
        $listExcerptDefault = (int)$settingModel->getValue('theme_list_excerpt', '1');
        $listExcerptLenDefault = (int)$settingModel->getValue('theme_list_excerpt_len', '80');
        // 首页模式站点默认：portal=官网介绍（默认）/ community=社区首页（仅 site_mode=portal 生效）
        $homeModeDefault = (string)$settingModel->getValue('default_home_mode', 'portal');
        if (!in_array($homeModeDefault, ['portal', 'community'], true)) {
            $homeModeDefault = 'portal';
        }

        $success = htmlspecialchars($_GET['success'] ?? '', ENT_QUOTES, 'UTF-8');
        $error = htmlspecialchars($_GET['error'] ?? '', ENT_QUOTES, 'UTF-8');

        $this->view('admin/themes', [
            'themes' => $themes,
            'currentDefault' => $currentDefault,
            'customCss' => $customCss,
            'hideRightSidebar' => $hideRightSidebar,
            'nightAuto' => $nightAuto,
            'nightStart' => $nightStart,
            'nightEnd' => $nightEnd,
            'listExcerptDefault' => $listExcerptDefault,
            'listExcerptLenDefault' => $listExcerptLenDefault,
            'homeModeDefault' => $homeModeDefault,
            'success' => $success,
            'error' => $error,
            '__nav_active' => 'themes',
        ]);
    }

    public function setDefault()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');

        $themeKey = trim($_POST['theme_key'] ?? '');
        $themes = Theme::getAvailable();

        if ($themeKey === '' || isset($themes[$themeKey])) {
            $settingModel = new Setting();
            $settingModel->setValue('default_theme', $themeKey);
            Settings::buildCache();
            $this->redirect('/admin/themes?success=default');
        }

        $this->redirect('/admin/themes?error=invalid');
    }

    public function saveCss()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');

        $css = $_POST['custom_css'] ?? '';
        // 只允许基本 CSS 字符，过滤潜在危险内容
        $css = preg_replace('/<[^>]*>/', '', $css); // 移除 HTML 标签
        $css = str_ireplace('</style>', '', $css);   // 大小写不敏感，防 </STYLE> 绕过
        $css = str_ireplace('@import', '', $css);    // 阻止 @import 外部资源
        $css = preg_replace('/expression\s*\(/i', '', $css); // 阻止 CSS expression XSS
        // 阻止危险 URL 与旧式 CSS 行为注入（纵深防御，防止保存后被任何输出点渲染）
        $css = preg_replace('/url\s*\(\s*(?:"|\')?\s*javascript:/i', 'url(about:blank)', $css); // url(javascript:...) → 惰性替换为无害 URL
        $css = str_ireplace('behavior', '', $css);   // 阻止 IE CSS behavior（绑定 HTC/脚本）
        $css = str_ireplace('-moz-binding', '', $css); // 阻止 Firefox XBL 绑定（moz-binding）

        $settingModel = new Setting();
        $settingModel->setValue('custom_css', $css);
        Settings::buildCache();

        $this->redirect('/admin/themes?success=css');
    }

    /**
     * 保存界面选项：隐藏右侧栏（站点默认）+ 夜间自动切换暗夜星辰（开关 + 起止小时）
     * + 列表内容模式站点默认（开关 + 默认长度，前台用户可覆盖）
     */
    public function saveOptions()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');

        $hideRightSidebar = isset($_POST['hide_right_sidebar']) ? 1 : 0;
        $nightAuto = isset($_POST['night_auto']) ? 1 : 0;
        $nightStart = max(0, min(23, (int)($_POST['night_start'] ?? 19)));
        $nightEnd = max(0, min(23, (int)($_POST['night_end'] ?? 7)));
        $listExcerptDefault = isset($_POST['list_excerpt']) ? 1 : 0;
        $listExcerptLenDefault = max(50, min(200, (int)($_POST['list_excerpt_len'] ?? 80)));
        // 首页模式站点默认：白名单 portal（官网介绍）/ community（社区首页），默认 portal
        $homeModeDefault = (string)($_POST['home_mode'] ?? 'portal');
        if (!in_array($homeModeDefault, ['portal', 'community'], true)) {
            $homeModeDefault = 'portal';
        }

        $settingModel = new Setting();
        $settingModel->setValue('theme_hide_right_sidebar', (string)$hideRightSidebar);
        $settingModel->setValue('theme_night_auto', (string)$nightAuto);
        $settingModel->setValue('theme_night_start', (string)$nightStart);
        $settingModel->setValue('theme_night_end', (string)$nightEnd);
        $settingModel->setValue('theme_list_excerpt', (string)$listExcerptDefault);
        $settingModel->setValue('theme_list_excerpt_len', (string)$listExcerptLenDefault);
        $settingModel->setValue('default_home_mode', $homeModeDefault);
        Settings::buildCache();
        // 首页模式切换影响首页渲染，清空全部游客静态缓存防旧版面
        \app\Helpers\PageCache::invalidate();

        $this->redirect('/admin/themes?success=options');
    }

    /**
     * 上传主题（ZIP 包）— 单模板体系下已禁用
     */
    public function upload()
    {
        $this->redirect('/admin/themes?error=invalid');
    }

    /**
     * 删除主题 — 单模板体系下已禁用
     */
    public function delete()
    {
        $this->redirect('/admin/themes?error=invalid');
    }
}
