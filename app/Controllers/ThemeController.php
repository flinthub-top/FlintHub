<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 主题前台控制器 — 用户主题切换、主题设置
 * @file app/Controllers/ThemeController.php
 * @package app\Controllers
 */

namespace app\Controllers;

use app\Core\Controller;
use app\Helpers\Theme;
use app\Helpers\Csrf;

class ThemeController extends Controller
{
    public function settings()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $theme = trim($_POST['theme'] ?? '');
            Theme::set($theme);

            // 用户级界面偏好：隐藏右栏 / 跟随夜间切换 / 列表内容模式（未登录存 cookie，登录写 users 表）
            $hideRightSidebar = isset($_POST['hide_right_sidebar']) ? 1 : 0;
            $nightAuto = isset($_POST['night_auto']) ? 1 : 0;
            // 列表内容模式：开关默认开；长度 50~200 字（限幅），默认 80
            $listExcerpt = isset($_POST['list_excerpt']) ? 1 : 0;
            $listExcerptLen = (int)($_POST['list_excerpt_len'] ?? 80);
            $listExcerptLen = max(50, min(200, $listExcerptLen));
            // 首页模式：跟随站点默认(follow) / 官网介绍(portal) / 社区首页(community)，白名单校验
            $homeMode = (string)($_POST['home_mode'] ?? 'follow');
            if (!in_array($homeMode, ['follow', 'portal', 'community'], true)) {
                $homeMode = 'follow';
            }
            if (\app\Helpers\Auth::isLoggedIn()) {
                try {
                    $db = \app\Core\Database::getInstance();
                    $db->query('UPDATE users SET hide_right_sidebar = :h, night_theme_auto = :n, list_excerpt = :le, list_excerpt_len = :lel, home_mode = :hm WHERE id = :id', [
                        ':h' => $hideRightSidebar, ':n' => $nightAuto, ':le' => $listExcerpt, ':lel' => $listExcerptLen, ':hm' => $homeMode, ':id' => $_SESSION['user_id'],
                    ]);
                } catch (\Exception $e) {
                    \error_log('ThemeController::settings() - ' . $e->getMessage());
                }
            }
            $this->setPrefCookie('site_hide_right_sidebar', (string)$hideRightSidebar);
            $this->setPrefCookie('site_night_auto', (string)$nightAuto);
            $this->setPrefCookie('site_list_excerpt', (string)$listExcerpt);
            $this->setPrefCookie('site_list_excerpt_len', (string)$listExcerptLen);
            $this->setPrefCookie('site_home_mode', $homeMode);

            $this->redirect('/theme-settings?success=1');
        }

        $availableThemes = Theme::getAvailable();
        $currentTheme = Theme::getCurrent();
        $success = isset($_GET['success']) ? \app\Helpers\I18n::get('theme.switched_success') : '';

        // 当前用户级偏好（登录读 users 表；未登录读 cookie）
        $hideRightSidebar = (int)$this->readPref('hide_right_sidebar');
        $nightAuto = (int)$this->readPref('night_auto');
        // 列表内容模式：未设置时默认开、长度默认 80（50~200 可调）
        $listExcerptRaw = $this->readPref('list_excerpt');
        $listExcerpt = $listExcerptRaw === '' ? 1 : (int)$listExcerptRaw;
        $listExcerptLenRaw = (int)$this->readPref('list_excerpt_len');
        $listExcerptLen = $listExcerptLenRaw >= 50 && $listExcerptLenRaw <= 200 ? $listExcerptLenRaw : 80;
        // 首页模式：未设置/非法值 → 跟随站点默认（follow）
        $homeMode = (string)$this->readPref('home_mode');
        if (!in_array($homeMode, ['follow', 'portal', 'community'], true)) {
            $homeMode = 'follow';
        }

        $this->view('theme/settings', [
            'availableThemes' => $availableThemes,
            'currentTheme' => $currentTheme,
            'success' => $success,
            'hideRightSidebar' => $hideRightSidebar,
            'nightAuto' => $nightAuto,
            'listExcerpt' => $listExcerpt,
            'listExcerptLen' => $listExcerptLen,
            'homeMode' => $homeMode,
        ]);
    }

    /**
     * 读取用户级偏好：登录读 users 表，未登录读 cookie
     */
    private function readPref(string $key): string
    {
        if (\app\Helpers\Auth::isLoggedIn()) {
            try {
                $db = \app\Core\Database::getInstance();
                $row = $db->fetchOne("SELECT hide_right_sidebar, night_theme_auto, list_excerpt, list_excerpt_len, home_mode FROM users WHERE id = :id", [':id' => $_SESSION['user_id']]);
                if ($key === 'hide_right_sidebar') {
                    return $row['hide_right_sidebar'] === null ? '' : (string)$row['hide_right_sidebar'];
                }
                if ($key === 'night_auto') {
                    return $row['night_theme_auto'] === null ? '' : (string)$row['night_theme_auto'];
                }
                if ($key === 'list_excerpt') {
                    return $row['list_excerpt'] === null ? '' : (string)$row['list_excerpt'];
                }
                if ($key === 'list_excerpt_len') {
                    return $row['list_excerpt_len'] === null ? '' : (string)$row['list_excerpt_len'];
                }
                return $row['home_mode'] === null ? '' : (string)$row['home_mode'];
            } catch (\Exception $e) {
                \error_log('ThemeController::readPref() - ' . $e->getMessage());
            }
        }
        $cookieKey = [
            'hide_right_sidebar' => 'site_hide_right_sidebar',
            'night_auto' => 'site_night_auto',
            'list_excerpt' => 'site_list_excerpt',
            'list_excerpt_len' => 'site_list_excerpt_len',
            'home_mode' => 'site_home_mode',
        ][$key] ?? '';
        return (string)($_COOKIE[$cookieKey] ?? '');
    }

    /**
     * 写偏好 cookie（游客偏好持久化；登录用户同时写 users 表）
     */
    private function setPrefCookie(string $name, string $value): void
    {
        \setcookie($name, $value, [
            'expires' => \time() + 86400 * 30,
            'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }

    /**
     * 删除偏好 cookie（用于清理已退役的导航布局覆盖 cookie）
     */
    private function clearPrefCookie(string $name): void
    {
        \setcookie($name, '', [
            'expires' => \time() - 3600,
            'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }

    /**
     * 布局开关（导航“布局”按钮）：翻转当前摘要显示态 → 写入与主题设置共用的偏好存储
     * （登录写 users.list_excerpt、游客写 site_list_excerpt cookie）→ PRG 回原页。
     * 同时清除旧版独立覆盖 cookie（site_layout_excerpt），消除“主题设置被按钮覆盖不生效”的歧义。
     */
    public function toggleLayout()
    {
        $next = (string)($_GET['next'] ?? '');
        // 仅允许站内相对路径（防开放重定向）：/ 开头且非 //、非 \
        if ($next === '' || $next[0] !== '/' || (isset($next[1]) && $next[1] === '/') || $next[0] === '\\') {
            $next = '/';
        }
        // 翻转“当前展示态”：导航显示带摘要(columns) → 点一次 = 隐藏；反之亦然
        $current = \app\Helpers\Theme::getListExcerptPref()['enabled'];
        $nextVal = $current ? 0 : 1;
        if (\app\Helpers\Auth::isLoggedIn()) {
            try {
                $db = \app\Core\Database::getInstance();
                $db->query('UPDATE users SET list_excerpt = :le WHERE id = :id', [':le' => $nextVal, ':id' => $_SESSION['user_id']]);
            } catch (\Exception $e) {
                \error_log('ThemeController::toggleLayout() - ' . $e->getMessage());
            }
        }
        $this->setPrefCookie('site_list_excerpt', (string)$nextVal);
        // 清理旧版覆盖 cookie：偏好已并入 users 表 / site_list_excerpt，残留会持续覆盖主题设置
        $this->clearPrefCookie('site_layout_excerpt');
        $this->redirect($next);
    }
}
