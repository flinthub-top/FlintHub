<?php
/**
 * FlintHub 1.0 (SplitDB) — 高频列表页静态 HTML 缓存
 *
 * 访问触发式缓存：首页/版块列表页渲染结果落 data/runtime/pages/{name}.html，
 * 命中直接 echo 输出（完全绕过数据库查询与视图渲染）。
 *
 * 永久缓存：不按 TTL 过期，仅在发帖/回帖时由 invalidate() 主动失效；
 * 仅游客生效（登录用户含用户态数据不缓存）；index.php 入口前置缓存；命中同样输出安全响应头。
 *
 * @file app/Helpers/PageCache.php
 * @package app\Helpers
 */

namespace app\Helpers;

class PageCache
{
    /** 缓存目录相对 DATA_PATH */
    public const DIR_REL = 'runtime/pages';

    /**
     * 缓存目录绝对路径
     */
    public static function dir(): string
    {
        return (rtrim(\app\SplitDB\ShardRouter::dataPath(), '/\\')) . '/' . self::DIR_REL;
    }

    /**
     * 缓存文件绝对路径
     */
    public static function path(string $name): string
    {
        return self::dir() . '/' . $name . '.html';
    }

    /**
     * [i18n] 语言分键：非默认语言在缓存名后追加语言后缀（zh 不分键，存量缓存零影响）
     * 保证英文游客不会命中中文缓存页（§3.4）
     */
    private static function langKey(string $name): string
    {
        $lang = \app\Helpers\I18n::current();
        if ($lang === '' || $lang === \app\Helpers\I18n::DEFAULT_LANG) {
            return $name;
        }
        return $name . '_' . $lang;
    }

    /**
     * 用户偏好分键（列表内容模式）：默认偏好（开 + 80 字）沿用原缓存键（存量缓存零影响），
     * 非默认偏好追加签名后缀 → 不同偏好的游客命中各自缓存页，避免偏好被缓存固化。
     */
    private static function prefKey(string $name): string
    {
        $pref = \app\Helpers\Theme::getListExcerptPref();
        if ($pref['enabled'] && $pref['len'] === 80) {
            return $name;
        }
        return $name . '_e' . ($pref['enabled'] ? '1' : '0') . 'l' . $pref['len'];
    }

    /**
     * 命中缓存：输出安全头 + 缓存内容，返回 true（调用方应直接 return/exit，绕过 DB/渲染）
     *
     * 永久缓存：无 TTL 过期，仅在发帖/回帖时 invalidate() 主动失效。
     */
    public static function serve(string $name): bool
    {
        $p = self::dir() . '/' . self::langKey(self::prefKey($name)) . '.html';
        if (!is_file($p)) {
            return false;
        }
        // 与 Controller::view 一致的安全响应头
        \header('X-Content-Type-Options: nosniff');
        \header('X-Frame-Options: SAMEORIGIN');
        \header('Referrer-Policy: strict-origin-when-cross-origin');
        \header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-eval' 'unsafe-inline'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'");
        \header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            \header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        echo @file_get_contents($p);
        return true;
    }

    /**
     * 在 ob_start 中执行渲染回调 → 落盘缓存 → 输出
     */
    public static function render(callable $fn, string $name): void
    {
        ob_start();
        try {
            $fn();
        } catch (\Throwable $e) {
            ob_end_clean(); // 渲染异常：丢弃缓冲，不写缓存
            throw $e;
        }
        $html = (string)ob_get_clean();
        echo $html;
        if ($html !== '') {
            $dir = self::dir();
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents($dir . '/' . self::langKey(self::prefKey($name)) . '.html', $html, LOCK_EX);
        }
    }

    /**
     * 主动失效：删除 pages/ 目录下全部缓存文件（发帖/回帖后调用，新帖立即可见）
     */
    public static function invalidate(): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.html') ?: [] as $f) {
            @unlink($f);
        }
    }
}
