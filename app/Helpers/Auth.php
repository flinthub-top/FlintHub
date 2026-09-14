<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 用户认证 — 登录状态、权限检查、当前用户缓存
 * @file app/Helpers/Auth.php
 * @package app\Helpers
 */

namespace app\Helpers;

class Auth
{
    private static bool $cacheDirty = false;

    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function isAdmin(): bool
    {
        // 以数据库为准：检查 role 和 group_id 双重确认
        $user = self::getCurrentUser();
        return $user !== null
            && ($user['role'] ?? '') === 'admin'
            && (int)($user['group_id'] ?? 1) === 4;
    }

    public static function getCurrentUser(): ?array
    {
        static $initialized = false;
        static $cached = null;

        // 如果有缓存清除标记，强制重新查询
        if (self::$cacheDirty) {
            $initialized = false;
            self::$cacheDirty = false;
        }

        if ($initialized) {
            return $cached;
        }

        $initialized = true;

        if (!self::isLoggedIn()) {
            return $cached = null;
        }

        $db = \app\Core\Database::getInstance();
        $user = $db->fetchOne(
            'SELECT id, username, email, avatar, signature, role, group_id, level, points, post_count, status, created_at, theme, email_verified, list_excerpt, list_excerpt_len FROM users WHERE id = :id',
            [':id' => $_SESSION['user_id']]
        );

        // 用户不存在（被删除等），清理 Session 防"已登录但无用户"的异常状态
        if (!$user) {
            $_SESSION = [];
            \session_destroy();
            return $cached = null;
        }

        return $cached = $user;
    }

    /**
     * 获取当前用户完整信息（含 password 字段）
     * 仅供必须校验密码的场景使用（ProfileController 改密码、VerifyController 二次验证）
     */
    public static function getCurrentUserWithPassword(): ?array
    {
        if (!self::isLoggedIn()) {
            return null;
        }

        $db = \app\Core\Database::getInstance();
        return $db->fetchOne(
            'SELECT id, username, email, password, avatar, signature, role, group_id, level, points, post_count, status, created_at, theme, email_verified FROM users WHERE id = :id',
            [':id' => $_SESSION['user_id']]
        );
    }

    public static function clearUserCache(): void
    {
        self::$cacheDirty = true;
    }

    /**
     * 记录登录来源页（登录成功后回跳用）
     *
     * 写入 $_SESSION['redirect_after']（存剔除 BASE_PATH 前缀的相对路径，回跳时
     * redirect() 会重新拼接 BASE_PATH，避免二级目录下双前缀）。来源优先级：
     *   htmx 请求 → HX-Current-URL 头（当前页面完整 URL，API 场景必须用它，
     *   因为 REQUEST_URI 是 /api/xxx）；普通请求 → REQUEST_URI；兜底 Referer。
     * 自动排除登录/注册/忘记密码等认证页自身，防止回跳死循环。
     * 已存在来源时不覆盖（连续跳转保留最初来源）。
     */
    public static function rememberRedirectAfter(): void
    {
        if (!empty($_SESSION['redirect_after'])) {
            return;
        }
        $from = '';
        if (!empty($_SERVER['HTTP_HX_CURRENT_URL'])) {
            $from = (string)$_SERVER['HTTP_HX_CURRENT_URL'];
        } elseif (isset($_SERVER['REQUEST_URI'])) {
            $from = (string)$_SERVER['REQUEST_URI'];
        } elseif (!empty($_SERVER['HTTP_REFERER'])) {
            $from = (string)$_SERVER['HTTP_REFERER'];
        }
        if ($from === '') {
            return;
        }
        // 仅取路径部分（去域名/查询串），且必须为站内相对路径
        $path = \parse_url($from, PHP_URL_PATH) ?: '';
        if ($path === '' || !\str_starts_with($path, '/') || \str_starts_with($path, '//')) {
            return;
        }
        // 剔除 BASE_PATH 前缀（存相对路径，防回跳时双前缀）
        $base = \defined('BASE_PATH') ? (string)BASE_PATH : '';
        if ($base !== '' && \str_starts_with($path, $base)) {
            $path = \substr($path, \strlen($base));
        }
        if ($path === '' || $path === '/') {
            return; // 首页无需回跳
        }
        if (self::isAuthPath($path)) {
            return;
        }
        $_SESSION['redirect_after'] = $path;
    }

    /**
     * 取出登录后回跳目标并清除（一次性消费）。
     * 仅返回安全站内相对路径，非法/认证页返回 ''（调用方回退首页）。
     */
    public static function consumeRedirectAfter(): string
    {
        $target = (string)($_SESSION['redirect_after'] ?? '');
        unset($_SESSION['redirect_after']);
        if ($target === '' || !\str_starts_with($target, '/') || \str_starts_with($target, '//')) {
            return '';
        }
        if (self::isAuthPath($target)) {
            return '';
        }
        return $target;
    }

    /**
     * 是否认证相关路径（登录/注册/忘记密码/登出等，回跳需排除）
     */
    private static function isAuthPath(string $path): bool
    {
        foreach (['/login', '/register', '/forgot', '/logout'] as $ex) {
            if ($path === $ex || \str_starts_with($path, $ex . '/')) {
                return true;
            }
        }
        return false;
    }

    public static function requireAdmin(): void
    {
        if (!self::isLoggedIn()) {
            // 记录登录来源（登录成功后回跳原页面）
            self::rememberRedirectAfter();
            header('Location: ' . (\defined('BASE_PATH') ? \BASE_PATH : '') . '/login');
            exit;
        }
        if (!self::isAdmin()) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            echo '<h1>403 - 权限不足</h1><p>您没有访问此页面的权限。</p>';
            exit;
        }

        $verified = $_SESSION['admin_verified_at'] ?? 0;
        // 超时由后台设置 admin_verify_timeout 动态控制（标准 1800s / 长任务 2592000s），限幅 60s ~ 7 天
        $verifyTimeout = (int)\app\Helpers\Settings::get('admin_verify_timeout', '1800');
        $verifyTimeout = \max(60, \min($verifyTimeout, 604800));
        if ($verified < time() - $verifyTimeout) {
            $_SESSION['admin_redirect_after_verify'] = $_SERVER['REQUEST_URI'];
            session_write_close();
            header('Location: ' . (\defined('BASE_PATH') ? \BASE_PATH : '') . '/admin/verify');
            exit;
        }
    }
}
