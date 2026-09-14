<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * CSRF 防护 — Token 生成、验证、自动轮换
 * @file app/Helpers/Csrf.php
 * @package app\Helpers
 */

namespace app\Helpers;

class Csrf
{
    public static function token(): string
    {
        // 30 分钟自动轮换
        if (empty($_SESSION['csrf']) || empty($_SESSION['csrf_time']) || time() - $_SESSION['csrf_time'] > 1800) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_time'] = time();
        }
        return $_SESSION['csrf'];
    }

    public static function verify(?string $token): bool
    {
        if (!isset($_SESSION['csrf'])) return false;
        // 仅校验不轮换，防并发 AJAX 请求因 Token 刷新导致 403；轮换仅在登录/注销时显式调用
        return hash_equals($_SESSION['csrf'], (string)($token ?? ''));
    }

    public static function verifyOrDie(?string $token): void
    {
        if (!self::verify($token)) {
            http_response_code(403);
            echo '<h1>403 - CSRF 验证失败</h1>';
            exit;
        }
    }

    /**
     * 手动轮换 Token——仅在用户登录/注销时调用
     */
    public static function rotate(): void
    {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    /**
     * 一次性消费 Token——敏感操作（修改密码/删除帖子等）成功后调用，
     * 防被窃取的 Token 在会话期内无限重放；下次 token() 时自动重新生成
     */
    public static function consume(): void
    {
        unset($_SESSION['csrf'], $_SESSION['csrf_time']);
    }
}
