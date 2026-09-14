<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 频率限制 — 基于 Session + IP 文件缓存的请求限流
 * @file app/Helpers/RateLimiter.php
 * @package app\Helpers
 */

namespace app\Helpers;

class RateLimiter
{
    /** @var array<string, array> IP 限流运行时缓存 */
    private static array $ipCache = [];
    /** @var int 文件缓存读取次数统计 */
    private static int $fileReadCount = 0;
    /** @var int 文件缓存写入次数统计 */
    private static int $fileWriteCount = 0;

    /**
     * 获取文件缓存读取次数
     */
    public static function getFileReadCount(): int
    {
        return self::$fileReadCount;
    }

    /**
     * 获取文件缓存写入次数
     */
    public static function getFileWriteCount(): int
    {
        return self::$fileWriteCount;
    }

    /**
     * 获取 IP 限流缓存目录
     */
    private static function getIpCacheDir(): string
    {
        $dir = \dirname(__DIR__, 2) . '/protected/rate_cache';
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * 从文件读取 IP 限流记录
     */
    private static function readIpRecords(string $key): array
    {
        self::$fileReadCount++;
        $cacheDir = self::getIpCacheDir();
        $file = $cacheDir . '/ip_' . \md5($key) . '.cache';
        if (!\file_exists($file)) {
            return [];
        }
        $data = @\file_get_contents($file);
        if ($data === false) {
            return [];
        }
        // 用 json_decode 读取（消除 unserialize 反序列化面）；旧 serialize 格式缓存无法解析 → 返回 [] 自动失效
        $records = @\json_decode($data, true);
        if (!\is_array($records)) {
            return [];
        }
        return $records;
    }

    /**
     * 写入 IP 限流记录到文件
     *
     * 抢锁失败不直接放行：有限退避重试 + 锁内重读合并，防高并发限流穿透 / Lost Update；
     * 重试后仍抢不到锁返回 false，由调用方降级到 Session 级强制计数兜底。
     * 参数 $records 必须是「本次新增的时间戳列表」（通常为 [$now]），切勿传完整历史列表
     * （会导致记录指数膨胀、限流阈值被压缩）。
     */
    private const MAX_LOCK_RETRIES = 3;
    private const LOCK_RETRY_DELAY_US = 15000; // 15ms 抖动步进

    private static function writeIpRecords(string $key, array $records): void
    {
        self::$fileWriteCount++;
        $cacheDir = self::getIpCacheDir();
        $file = $cacheDir . '/ip_' . \md5($key) . '.cache';

        // 有限退避重试抢锁：高并发下避免直接放弃写入导致限流穿透
        $fh = @\fopen($file, 'c+b');
        if ($fh === false) {
            return; // 无法打开缓存文件 → 交由 Session 级兜底
        }
        $locked = false;
        for ($i = 0; $i <= self::MAX_LOCK_RETRIES; $i++) {
            if (@\flock($fh, \LOCK_EX | \LOCK_NB)) {
                $locked = true;
                break;
            }
            \usleep(self::LOCK_RETRY_DELAY_US);
            \clearstatcache(true, $file);
        }

        if ($locked) {
            try {
                // 锁内重读磁盘最新记录再合并本次 timestamp，防 Lost Update
                $latest = self::readUnderLock($fh);
                foreach ($records as $ts) {
                    if (\is_numeric($ts)) $latest[] = (int)$ts;
                }
                \ftruncate($fh, 0);
                \rewind($fh);
                \fwrite($fh, \json_encode($latest, JSON_UNESCAPED_UNICODE));
                \fflush($fh);
            } finally {
                \flock($fh, \LOCK_UN);
            }
        }
        \fclose($fh);
        // 抢锁始终失败：不阻塞，Session 级计数在 check() 中已兜底
    }

    /**
     * 在当前独占锁内读取已有记录（上游已持 LOCK_EX，避免与 readIpRecords 独立加锁）
     */
    private static function readUnderLock($fh): array
    {
        \fseek($fh, 0);
        $data = \stream_get_contents($fh);
        if ($data === false || $data === '') return [];
        $records = @\json_decode($data, true);
        return \is_array($records) ? $records : [];
    }

    /**
     * 只读地判断当前 IP 在窗口内是否已超限（不写入任何记录）
     * 用于风控/验证码触发判定，避免 GET 接口（如 /api/pow）污染限流计数
     *
     * @param string $action  操作标识
     * @param int $maxRequests  窗口内最大请求数
     * @param int $windowSeconds  时间窗口（秒）
     * @return bool  true=已超限/高风险, false=未超限
     */
    public static function isLimited(string $action, int $maxRequests = 10, int $windowSeconds = 60): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ipKey = '_ip_rate_' . $action . '_' . $ip;
        $now = \time();

        $records = self::readIpRecords($ipKey);
        $records = \array_filter($records, function ($ts) use ($now, $windowSeconds) {
            return ($now - $ts) < $windowSeconds;
        });
        if (\count($records) >= $maxRequests) {
            return true;
        }

        // Session 级只读判断
        $key = self::buildKey($action);
        if (!isset($_SESSION[$key])) {
            return false;
        }
        $valid = \array_filter($_SESSION[$key], function ($ts) use ($now, $windowSeconds) {
            return ($now - $ts) < $windowSeconds;
        });
        return \count($valid) >= $maxRequests;
    }

    /**
     * 检查当前请求是否超过频率限制
     * 基于 Session + IP 双重标识（IP 级别兜底防 Session 绕过）
     *
     * @param string $action  操作标识（如 'vote', 'upload'）
     * @param int $maxRequests  窗口内最大请求数
     * @param int $windowSeconds  时间窗口（秒）
     * @return bool  true=允许, false=超限
     */
    public static function check(string $action, int $maxRequests = 10, int $windowSeconds = 60): bool
    {
        // IP 级限流（使用文件缓存，跨 PHP-FPM 工作进程持久化存储）
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ipKey = '_ip_rate_' . $action . '_' . $ip;
        $now = \time();

        $records = self::readIpRecords($ipKey);
        $records = \array_filter($records, function ($ts) use ($now, $windowSeconds) {
            return ($now - $ts) < $windowSeconds;
        });
        if (\count($records) >= $maxRequests) {
            return false;
        }
        // 只传「本次新增时间戳」而非完整列表：锁内合并只追加 $now 可防 Lost Update，且记录不膨胀
        self::writeIpRecords($ipKey, [$now]);

        // Session 级限流
        $key = self::buildKey($action);
        $now = \time();

        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }

        $_SESSION[$key] = \array_filter($_SESSION[$key], function ($ts) use ($now, $windowSeconds) {
            return ($now - $ts) < $windowSeconds;
        });

        if (\count($_SESSION[$key]) >= $maxRequests) {
            return false;
        }

        $_SESSION[$key][] = $now;
        return true;
    }

    /**
     * 检查并直接终止请求（返回 429 状态码）
     *
     * 双模式输出：API/htmx 场景（$forceJson 或带 AJAX 头）→ 输出 JSON；普通页面请求 → 输出友好 HTML 429 页
     *
     * @param string $action  操作标识
     * @param int $maxRequests  窗口内最大请求数
     * @param int $windowSeconds  时间窗口（秒）
     * @param bool $forceJson  强制 JSON 输出（API 控制器显式传 true）
     */
    public static function hit(string $action, int $maxRequests = 10, int $windowSeconds = 60, bool $forceJson = false): void
    {
        if (!self::check($action, $maxRequests, $windowSeconds)) {
            \http_response_code(429);
            \header('Retry-After: ' . $windowSeconds);

            $isAjax = ($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true'
                   || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

            if ($forceJson || $isAjax) {
                \header('Content-Type: application/json; charset=utf-8');
                echo \json_encode([
                    'success' => false,
                    'error' => '操作过于频繁，请稍后再试',
                    'retry_after' => $windowSeconds,
                ], JSON_UNESCAPED_UNICODE);
            } else {
                // 页面场景走主布局渲染 429 视图（与 404 同款风格，含导航/主题样式）
                \header('Content-Type: text/html; charset=utf-8');
                $view = new \app\Core\TemplateCompiler();
                $view->extend('main');
                $view->display('errors/429', ['retry_after' => $windowSeconds]);
            }
            exit;
        }
    }

    /**
     * 读取指定动作的限流阈值（max/window）——来自后台「限流设置」，未配置时回退默认值
     *
     * 配置键约定：rate_{$action}_max / rate_{$action}_window（后台「系统设置 → 限流设置」Tab 维护）。
     * 默认值与改造前的硬编码值完全一致，保证升级零行为变化。
     *
     * @param string $action  操作标识（如 'vote', 'upload'）
     * @param int $defMax     默认窗口内最大请求数（未配置/非法时回退）
     * @param int $defWindow  默认时间窗口（秒）
     * @return array{0:int,1:int} [maxRequests, windowSeconds]
     */
    public static function config(string $action, int $defMax, int $defWindow): array
    {
        return [
            (int)Settings::get("rate_{$action}_max", (string)$defMax),
            (int)Settings::get("rate_{$action}_window", (string)$defWindow),
        ];
    }

    /**
     * 按后台配置的阈值执行限流拦截（hit 的便捷包装）
     *
     * 等价于 config() 取阈值后调用 hit()，消除调用点重复样板。
     * 默认阈值 = 当前硬编码值，未配置时行为与改造前完全一致。
     *
     * @param string $action   操作标识
     * @param int $defMax      默认窗口内最大请求数
     * @param int $defWindow   默认时间窗口（秒）
     * @param bool $forceJson  true 强制 JSON 输出（API 控制器使用）
     */
    public static function hitConfig(string $action, int $defMax, int $defWindow, bool $forceJson = false): void
    {
        [$max, $win] = self::config($action, $defMax, $defWindow);
        self::hit($action, $max, $win, $forceJson);
    }

    /**
     * 构建 Session key（含 IP 前缀，防同 Session 多 IP 绕过）
     */
    private static function buildKey(string $action): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return '_rate_limit_' . $action . '_' . \crc32($ip);
    }
}
