<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 主题管理 — 元数据解析、主题列表、主题切换
 * @file app/Helpers/Theme.php
 * @package app\Helpers
 */

namespace app\Helpers;

class Theme
{
    private static $cachedThemes = null;

    public static function parseMetadata(string $metaFile): ?array
    {
        if (!\file_exists($metaFile)) return null;

        $content = \file_get_contents($metaFile);
        $metadata = [];

        $fields = [
            'Theme Name' => 'name', 'Theme URI' => 'uri', 'Author' => 'author',
            'Description' => 'description', 'Version' => 'version',
            'Color' => 'color', 'Preview' => 'preview',
        ];

        foreach ($fields as $field => $key) {
            if (\preg_match('/' . \preg_quote($field, '/') . ':\s*([^\r\n]+)/i', $content, $match)) {
                $metadata[$key] = trim($match[1]);
            }
        }

        if (empty($metadata['name'])) return null;
        if (empty($metadata['uri'])) $metadata['uri'] = \basename(\dirname($metaFile));

        $metadata['color'] = $metadata['color'] ?? '#0066cc';
        $metadata['description'] = $metadata['description'] ?? '';
        $metadata['author'] = $metadata['author'] ?? 'Unknown';
        $metadata['version'] = $metadata['version'] ?? '1.0';

        return $metadata;
    }

    public static function getAvailable(): array
    {
        if (self::$cachedThemes !== null) return self::$cachedThemes;

        $themes = [];
        $themesDir = \dirname(__DIR__, 2) . '/assets/themes';

        if (\is_dir($themesDir)) {
            foreach (\scandir($themesDir) as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $themeDir = $themesDir . '/' . $entry;
                if (!\is_dir($themeDir)) continue;

                // 查找配置信息：优先 config.json，其次 style.css 头部注释
                $metaFile = $themeDir . '/config.json';
                $cssFile = $themeDir . '/style.css';

                if (\file_exists($metaFile)) {
                    $json = \json_decode(\file_get_contents($metaFile), true);
                    if ($json && !empty($json['name'])) {
                        $json['uri'] = $entry;
                        $json['color'] = $json['color'] ?? '#0066cc';
                        $json['description'] = $json['description'] ?? '';
                        $json['author'] = $json['author'] ?? 'Unknown';
                        $json['version'] = $json['version'] ?? '1.0';
                        $themes[$entry] = $json;
                        continue;
                    }
                }

                if (\file_exists($cssFile)) {
                    $meta = self::parseMetadata($cssFile);
                    if ($meta !== null) {
                        $meta['uri'] = $entry;
                        $themes[$entry] = $meta;
                    }
                }

                // 自动发现：无 config.json / style.css 元数据，但目录内含 .php 模板 → 自动注册为主题
                if (!isset($themes[$entry]) && self::dirHasTemplates($themeDir)) {
                    $themes[$entry] = [
                        'name' => $entry,
                        'uri' => $entry,
                        'color' => '#0066cc',
                        'description' => '模板主题（自动发现）',
                        'author' => 'Unknown',
                        'version' => '1.0',
                    ];
                }
            }
        }

        // 兼容旧版：扫描 CSS 主题文件
        $cssDir = \dirname(__DIR__, 2) . '/assets/css/themes';
        if (\is_dir($cssDir)) {
            foreach (\scandir($cssDir) as $file) {
                if (\pathinfo($file, PATHINFO_EXTENSION) !== 'css') continue;
                $uri = \pathinfo($file, PATHINFO_FILENAME);
                if (isset($themes[$uri])) continue;
                $meta = self::parseMetadata($cssDir . '/' . $file);
                if ($meta !== null) $themes[$uri] = $meta;
            }
        }

        // 单模板体系：modern 为基础主题，硬编码元数据（样式已固化到 assets/css/modern.css）
        if (!isset($themes['modern'])) {
            $themes['modern'] = [
                'name' => 'Modern 风格',
                'uri' => 'modern',
                'color' => '#4f6ef7',
                'description' => 'Modern 单模板体系默认主题（样式已固化到 assets/css/modern.css）',
                'author' => 'FlintHub',
                'version' => '1.0',
                'group' => 'modern',
            ];
        }

        // 为每个主题标注分组
        foreach ($themes as $key => $info) {
            if (strpos($key, 'modern') === 0) {
                $themes[$key]['group'] = 'modern';
            } else {
                $themes[$key]['group'] = 'classic';
            }
        }

        return self::$cachedThemes = $themes;
    }

    /**
     * 判断目录下是否含 .php 模板文件（递归），用于自动发现无元数据的模板主题
     */
    private static function dirHasTemplates(string $dir): bool
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                return true;
            }
        }
        return false;
    }

    public static function getCurrent(): string
    {
        // 预览模式：仅本次请求生效，不影响其他用户（始终优先，夜间切换不打断预览）
        if (isset($_GET['preview_theme']) && !empty($_GET['preview_theme'])) {
            $available = self::getAvailable();
            $preview = $_GET['preview_theme'];
            if (isset($available[$preview])) {
                return $preview;
            }
        }

        // 用户偏好（登录时一次读取：主题 + 夜间跟随 + 隐藏右栏）
        $userTheme = '';
        $userNightPref = null; // NULL=跟随站点默认；0=显式退出；1=显式加入
        if (\app\Helpers\Auth::isLoggedIn()) {
            try {
                $db = \app\Core\Database::getInstance();
                $user = $db->fetchOne('SELECT theme, night_theme_auto FROM users WHERE id = :id', [':id' => $_SESSION['user_id']]);
                if ($user) {
                    $userTheme = (string)($user['theme'] ?? '');
                    $userNightPref = $user['night_theme_auto'] ?? null;
                }
            } catch (\Exception $e) {
                \error_log('Theme::getCurrent() - ' . $e->getMessage());
            }
        } else {
            // 游客：cookie 优先（site_night_auto='0'/'1'），无 cookie 跟随站点默认
            $cookieNight = (string)($_COOKIE['site_night_auto'] ?? '');
            if ($cookieNight === '0' || $cookieNight === '1') {
                $userNightPref = (int)$cookieNight;
            }
        }

        // 夜间自动切换：站点总闸开启 + 用户未显式退出 → 夜间时段强制 modern-night（覆盖用户手动选择）
        if (self::nightTimeActive($userNightPref)) {
            return 'modern-night';
        }

        // 用户手动选择的主题
        if ($userTheme !== '') return $userTheme;

        if (isset($_COOKIE['site_theme']) && $_COOKIE['site_theme'] !== '') {
            $theme = $_COOKIE['site_theme'];
            $available = self::getAvailable();
            if (isset($available[$theme])) return $theme;
        }

        // 从站点设置读取默认主题
        $default = \app\Helpers\Settings::get('default_theme');
        if (!empty($default)) return $default;

        return \defined('DEFAULT_THEME') ? DEFAULT_THEME : '';
    }

    /**
     * 列表内容模式偏好（用户级，可覆盖站点默认）：
     * 站点默认取后台「界面、主题管理」配置（theme_list_excerpt 默认开 / theme_list_excerpt_len 默认 80）；
     * 登录用户 users 表非 NULL、游客 cookie 有合法值 → 用户显式设置覆盖站点默认；
     * 用户未设置 → 跟随站点默认。
     * 登录用户复用 Auth::getCurrentUser() 的每请求缓存（已含 list_excerpt/list_excerpt_len 字段），零额外查询。
     * @return array ['enabled' => bool, 'len' => int]
     */
    public static function getListExcerptPref(): array
    {
        // 站点默认（后台可配）
        $enabled = ((int)\app\Helpers\Settings::get('theme_list_excerpt', '1') === 1);
        $len = max(50, min(200, (int)\app\Helpers\Settings::get('theme_list_excerpt_len', '80')));

        // 用户显式设置（覆盖站点默认）；null = 未设置 → 跟随站点默认
        $userEnabled = null;
        $userLen = null;
        if (\app\Helpers\Auth::isLoggedIn()) {
            try {
                $user = \app\Helpers\Auth::getCurrentUser();
                if ($user && isset($user['list_excerpt']) && $user['list_excerpt'] !== null) {
                    $userEnabled = (int)$user['list_excerpt'] === 1;
                }
                if ($user && isset($user['list_excerpt_len']) && $user['list_excerpt_len'] !== null) {
                    $userLen = max(50, min(200, (int)$user['list_excerpt_len']));
                }
            } catch (\Exception $e) {
                \error_log('Theme::getListExcerptPref() - ' . $e->getMessage());
            }
        } else {
            $c = (string)($_COOKIE['site_list_excerpt'] ?? '');
            if ($c === '0' || $c === '1') {
                $userEnabled = $c === '1';
            }
            $cl = (int)($_COOKIE['site_list_excerpt_len'] ?? 0);
            if ($cl >= 50 && $cl <= 200) {
                $userLen = $cl;
            }
        }

        if ($userEnabled !== null) {
            $enabled = $userEnabled;
        }
        if ($userLen !== null) {
            $len = $userLen;
        }

        return ['enabled' => $enabled, 'len' => $len];
    }

    /**
     * 导航按钮展示的当前摘要态：与主题设置同口径（用户偏好 → 站点默认），
     * 登录用户读 currentUser 缓存、游客读 cookie，均无额外 DB 查询（可全站每页调用）。
     */
    public static function layoutExcerptNavState(): bool
    {
        return self::getListExcerptPref()['enabled'];
    }

    /**
     * 首页模式解析（用户偏好可覆盖站点默认）：
     * 站点默认取后台「界面、主题管理 → 界面选项」的 default_home_mode（默认 portal = 官网介绍）；
     * 登录用户 users.home_mode、游客 cookie site_home_mode 为 follow 或缺省 → 跟随站点默认；
     * 用户显式选 portal（官网介绍）/ community（社区首页）→ 覆盖站点默认。
     * 仅 site_mode=portal（门户）时生效，forum/blog 单开模式由 HomeController 直接委托模块页。
     * @return string 'portal' | 'community'
     */
    public static function getHomeMode(): string
    {
        // 站点默认（后台可配）：白名单 portal/community，默认 portal
        $siteDefault = (string)\app\Helpers\Settings::get('default_home_mode', 'portal');
        if (!in_array($siteDefault, ['portal', 'community'], true)) {
            $siteDefault = 'portal';
        }

        // 用户显式设置（覆盖站点默认）；'' = 未设置/跟随 → 用站点默认
        $userMode = '';
        if (\app\Helpers\Auth::isLoggedIn()) {
            try {
                $db = \app\Core\Database::getInstance();
                $user = $db->fetchOne('SELECT home_mode FROM users WHERE id = :id', [':id' => $_SESSION['user_id']]);
                if ($user && $user['home_mode'] !== null) {
                    $userMode = (string)$user['home_mode'];
                }
            } catch (\Exception $e) {
                \error_log('Theme::getHomeMode() - ' . $e->getMessage());
            }
        } else {
            $userMode = (string)($_COOKIE['site_home_mode'] ?? '');
        }

        if (in_array($userMode, ['portal', 'community'], true)) {
            return $userMode;
        }
        return $siteDefault;
    }

    /**
     * 夜间自动切换是否生效
     * 站点总闸 theme_night_auto=1 才可能生效；用户级 night_theme_auto 覆盖：
     *   NULL=跟随站点默认，0=显式退出（该用户不参与），1=显式加入
     * @param int|string|null $userPref users.night_theme_auto 值
     */
    public static function nightTimeActive($userPref = null): bool
    {
        // 站点总闸
        if ((int)\app\Helpers\Settings::get('theme_night_auto', '0') !== 1) {
            return false;
        }
        // 用户显式退出
        if ($userPref === 0 || $userPref === '0') {
            return false;
        }
        return self::isNightHour();
    }

    /**
     * 当前小时是否落在夜间区间（支持跨午夜，如 19:00-7:00）
     * 时间段配置：theme_night_start / theme_night_end（0-23 小时）
     * @param int|null $hour 测试可注入小时（默认取当前服务器时间）
     */
    private static function isNightHour(?int $hour = null): bool
    {
        $start = (int)\app\Helpers\Settings::get('theme_night_start', '19');
        $end = (int)\app\Helpers\Settings::get('theme_night_end', '7');
        $start = max(0, min(23, $start));
        $end = max(0, min(23, $end));
        if ($start === $end) return false; // 无效区间（起点=终点）

        $hour = $hour ?? (int)date('G');
        if ($start < $end) {
            return $hour >= $start && $hour < $end; // 同日区间（如 6:00-18:00）
        }
        return $hour >= $start || $hour < $end;     // 跨午夜区间（如 19:00-7:00）
    }

    /**
     * 是否隐藏右侧栏：站点设置 theme_hide_right_sidebar 为默认，
     * 用户级 hide_right_sidebar 覆盖（NULL=跟随站点默认）；游客读 cookie site_hide_right_sidebar
     */
    public static function hideRightSidebar(): bool
    {
        $site = (int)\app\Helpers\Settings::get('theme_hide_right_sidebar', '0') === 1;

        // 游客：cookie 优先（site_hide_right_sidebar='0'/'1'），无 cookie 跟随站点默认
        if (!\app\Helpers\Auth::isLoggedIn()) {
            $cookie = (string)($_COOKIE['site_hide_right_sidebar'] ?? '');
            if ($cookie === '0' || $cookie === '1') {
                return $cookie === '1';
            }
            return $site;
        }

        try {
            $db = \app\Core\Database::getInstance();
            $row = $db->fetchOne('SELECT hide_right_sidebar FROM users WHERE id = :id', [':id' => $_SESSION['user_id']]);
            $pref = $row['hide_right_sidebar'] ?? null;
            if ($pref === null) return $site; // 跟随站点默认
            return (int)$pref === 1;
        } catch (\Exception $e) {
            \error_log('Theme::hideRightSidebar() - ' . $e->getMessage());
            return $site;
        }
    }

    public static function set(string $theme): bool
    {
        $available = self::getAvailable();
        if ($theme !== '' && !isset($available[$theme])) {
            \error_log("Invalid theme: {$theme}");
            return false;
        }

        \setcookie('site_theme', $theme, [
            'expires' => \time() + 86400 * 30,
            'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        ]);

        if (\app\Helpers\Auth::isLoggedIn()) {
            $db = \app\Core\Database::getInstance();
            $db->query('UPDATE users SET theme = :theme WHERE id = :id', [
                ':theme' => $theme, ':id' => $_SESSION['user_id'],
            ]);
        }

        return true;
    }
}
