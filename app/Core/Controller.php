<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 控制器基类 — 视图渲染、JSON 响应、重定向、权限检查
 * @file app/Core/Controller.php
 * @package app\Core
 */

namespace app\Core;

use app\Helpers\Auth;
use app\Helpers\Settings;
use app\Helpers\Theme;
use app\Helpers\Csrf;
use app\Helpers\RememberMe;

class Controller
{
    protected function view($template, $data = [])
    {
        // 在线状态已在 init.php 中统一更新

        $currentUser = Auth::getCurrentUser();
        $isLoggedIn = !empty($currentUser);
        $isAdmin = Auth::isAdmin();

        // 服务器端渲染耗时：index.php 接收请求 → 视图输出前；CLI/API 直访回退 REQUEST_TIME_FLOAT
        $renderTime = microtime(true) - (\defined('APP_START_TIME') ? \APP_START_TIME : ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)));

        $defaults = [
            'siteName' => htmlspecialchars(Settings::get('site_name', \DEFAULT_SITE_NAME), ENT_QUOTES, 'UTF-8'),
            'siteDescription' => htmlspecialchars(Settings::get('site_description', '一个简洁现代的PHP论坛'), ENT_QUOTES, 'UTF-8'),
            'currentUser' => $currentUser,
            'currentUsername' => $currentUser['username'] ?? '',
            'isLoggedIn' => $isLoggedIn,
            'isAdmin' => $isAdmin,
            'unreadCount' => $isLoggedIn ? Settings::getUnreadMessageCount() : 0,
            'renderTime' => $renderTime,
            'currentTheme' => Theme::getCurrent(),
            'csrfToken' => Csrf::token(),
            'currentNav' => $data['__nav_active'] ?? '',
            '__current_template' => $template,
        ];

        $this->setSecurityHeaders();

        try {
            \app\Helpers\Plugin::hook('controller_view_before', ['template' => $template, 'data' => &$data]);
        } catch (\Throwable $e) {
            \error_log('Plugin hook error (controller_view_before): ' . $e->getMessage());
        }

        $data = \array_merge($defaults, $data);

        $engine = new TemplateCompiler();
        $engine->extend('main');
        $engine->display($template, $data);
    }

    protected function viewRaw($template, $data = [])
    {
        RememberMe::check();
        // 安全响应头（与 view() 共用同一套，避免遗漏/不一致；CMS 引入 CSP 与 Permissions-Policy）
        $this->setSecurityHeaders();
        $engine = new TemplateCompiler();
        $engine->display($template, $data);
    }

    /**
     * 统一设置 HTML/JSON 页面安全响应头（view / viewRaw / json 共用）
     */
    protected function setSecurityHeaders(): void
    {
        \header('X-Content-Type-Options: nosniff');
        \header('X-Frame-Options: SAMEORIGIN');
        \header('Referrer-Policy: strict-origin-when-cross-origin');
        \header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-eval' 'unsafe-inline'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'");
        \header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            \header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    protected function json($data, $statusCode = 200)
    {
        \http_response_code($statusCode);
        // JSON 响应补 nosniff，防浏览器将 JSON 按 HTML/脚本 MIME 嗅探执行
        \header('X-Content-Type-Options: nosniff');
        \header('Content-Type: application/json; charset=utf-8');
        echo \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    protected function redirect($url)
    {
        // 防开放重定向：仅允许以 / 开头的相对路径（不含 // 协议相对 URL）
        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            $url = '/';
        }
        // 拼接 BASE_PATH（二级目录支持）
        $base = \defined('BASE_PATH') ? \BASE_PATH : '';
        if ($base !== '' && $url !== '') {
            $url = $base . $url;
        }
        // 断后：Session 写入前关闭主库连接上的残留游标
        \app\Core\Database::closeLastStatement();
        session_write_close();
        \header('Location: ' . $url);
        exit;
    }

    protected function currentUser()
    {
        return Auth::getCurrentUser();
    }

    protected function isLoggedIn()
    {
        return Auth::isLoggedIn();
    }

    protected function isAdmin()
    {
        return Auth::isAdmin();
    }

    protected function requireLogin()
    {
        $user = Auth::getCurrentUser();
        if (!$user) {
            // 记录登录来源（登录成功后回跳原页面）
            \app\Helpers\Auth::rememberRedirectAfter();
            $this->redirect('/login');
        }
    }

    protected function requireAdmin()
    {
        $this->requireLogin();
        if (!Auth::isAdmin()) {
            $this->redirect('/');
        }
    }

    /**
     * 要求管理员 30 分钟二次验证（前台 admin 越权操作专用）
     * 与后台 BaseController 口径一致：验证过期则跳转 /admin/verify，验证后回跳原页面
     */
    protected function requireAdminVerified(): void
    {
        $verifiedAt = $_SESSION['admin_verified_at'] ?? 0;
        // 超时由后台设置 admin_verify_timeout 动态控制（标准 1800s / 长任务 2592000s），读取时套上限
        $verifyTimeout = (int)\app\Helpers\Settings::get('admin_verify_timeout', '1800');
        $verifyTimeout = \min($verifyTimeout, 2592000);
        if ($verifiedAt < time() - $verifyTimeout) {
            $_SESSION['admin_redirect_after_verify'] = $_SERVER['REQUEST_URI'];
            session_write_close();
            header('Location: ' . (\defined('BASE_PATH') ? \BASE_PATH : '') . '/admin/verify');
            exit;
        }
    }
}
