<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 记住登录状态 — Cookie Token 自动登录验证（多端独立 token，JSON 数组存储）
 * @file app/Helpers/RememberMe.php
 * @package app\Helpers
 */

namespace app\Helpers;

class RememberMe
{
    /** 每用户最多记住登录 token 数（超限淘汰最旧，防无限膨胀） */
    const MAX_TOKENS = 10;

    /**
     * 请求级自动登录：Cookie token 命中 users.remember_token 数组任一元素即恢复会话。
     * 命中后只轮换"当前匹配到的那个 token"（按值在事务内重新定位），其他端互不影响。
     */
    public static function check(): void
    {
        if (\app\Helpers\Auth::isLoggedIn()) {
            return;
        }

        if (!isset($_COOKIE['remember_token'])) {
            return;
        }

        $token = $_COOKIE['remember_token'];
        if ($token === '') {
            return;
        }

        try {
            // 只信任成对下发的 remember_user_id 定位用户；缺失视为异常，不再按 token 反查
            // （JSON 数组无法用 WHERE remember_token = :token 等值匹配）
            $userId = isset($_COOKIE['remember_user_id']) ? (int)$_COOKIE['remember_user_id'] : 0;
            if ($userId <= 0) {
                self::logMismatch(0, '缺少 remember_user_id Cookie，无法定位用户');
                self::clearCookies();
                return;
            }

            $db = \app\Core\Database::getInstance();
            $user = $db->fetchOne('SELECT * FROM users WHERE id = :id', [':id' => $userId]);
            if (!$user) {
                self::logMismatch($userId, '用户不存在');
                self::clearCookies();
                return;
            }

            $hashes = self::parseTokens((string)($user['remember_token'] ?? ''));
            if ($hashes === []) {
                self::logMismatch($userId, '无有效 token（已全部吊销/淘汰）');
                self::clearCookies();
                return;
            }

            $hashedToken = hash('sha256', $token);
            $matched = false;
            foreach ($hashes as $h) {
                if (hash_equals((string)$h, $hashedToken)) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                self::logMismatch($userId, 'token 失配（被篡改/吊销/淘汰）');
                self::clearCookies();
                return;
            }

            // === Token 轮换：只轮换匹配到的那一个，其他端不受影响（防互踢的关键） ===
            $newToken = \bin2hex(\random_bytes(32));
            $newHashed = \hash('sha256', $newToken);
            if (!self::mutateTokens($userId, function (array &$hashes) use ($hashedToken, $newHashed) {
                foreach ($hashes as $i => $h) {
                    if (hash_equals((string)$h, $hashedToken)) {
                        $hashes[$i] = $newHashed;
                        return;
                    }
                }
            }, true)) {
                self::clearCookies();
                return;
            }

            session_regenerate_id(true);

            $_SESSION['user_id'] = (int)$user['id'];

            // 更新客户端 Cookie 为新 token
            $cookieLifetime = 86400 * 30;
            $cookieExpire = \time() + $cookieLifetime;
            $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
            \setcookie('remember_token', $newToken, [
                'expires' => $cookieExpire, 'path' => '/',
                'httponly' => true, 'secure' => $secure, 'samesite' => 'Lax',
            ]);
            \setcookie('remember_user_id', (int)$user['id'], [
                'expires' => $cookieExpire, 'path' => '/',
                'httponly' => true, 'secure' => $secure, 'samesite' => 'Lax',
            ]);
        } catch (\Exception $e) {
            self::clearCookies();
            \error_log('Auto login error: ' . $e->getMessage());
        }
    }

    /**
     * 登录勾选"记住我"时追加一个新 token（追加到数组尾部，不覆盖其他端）
     */
    public static function appendToken(int $userId, string $token): bool
    {
        $hashed = \hash('sha256', $token);
        return self::mutateTokens($userId, function (array &$hashes) use ($hashed) {
            $hashes[] = $hashed;
        });
    }

    /**
     * 移除指定设备 token（登出本端用；按值匹配，不影响其他端）
     */
    public static function removeToken(int $userId, string $token): bool
    {
        $hashed = \hash('sha256', $token);
        return self::mutateTokens($userId, function (array &$hashes) use ($hashed) {
            $hashes = array_values(array_filter($hashes, function ($h) use ($hashed) {
                return !hash_equals((string)$h, $hashed);
            }));
        });
    }

    /**
     * 吊销该用户全部记住登录（重置密码 / 封禁时调用）
     */
    public static function clearTokens(int $userId): bool
    {
        $db = \app\Core\Database::getInstance();
        try {
            $db->query(
                'UPDATE users SET remember_token = :t WHERE id = :id',
                [':t' => '[]', ':id' => $userId]
            );
            return true;
        } catch (\Throwable $e) {
            \error_log('RememberMe clearTokens error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 读-改-写核心（事务内）：读当前数组 → $mutator 原地修改 → 去重/类型过滤/上限淘汰 → 写回。
     * 全程在一个事务里，避免多端并发读改写互相覆盖导致丢 token。
     *
     * @param callable $mutator function(array &$hashes): void
     * @param bool     $touchLastLogin 是否同步更新 last_login（自动登录轮换时）
     */
    private static function mutateTokens(int $userId, callable $mutator, bool $touchLastLogin = false): bool
    {
        $db = \app\Core\Database::getInstance();
        try {
            // BEGIN IMMEDIATE：事务起始即抢写锁，串行化同用户并发读-改-写——
            // 避免 deferred 事务下两并发事务各读旧数组、后提交者覆盖先提交者（丢 token / 误踢）。
            // 注意：PDO::beginTransaction() 不支持指定隔离级别，须用 exec 起原始事务，
            // 收尾同样用 exec('COMMIT'/'ROLLBACK')——PDO-SQLite 对 exec 起的原始事务
            // 不置内部事务标志，混用 PDO::commit()/rollBack() 会抛 "no active transaction"。
            $db->getConnection()->exec('BEGIN IMMEDIATE');

            $row = $db->fetchOne('SELECT remember_token FROM users WHERE id = :id', [':id' => $userId]);
            $hashes = self::parseTokens((string)($row['remember_token'] ?? ''));

            $mutator($hashes);

            // 类型过滤 + 去重 + 上限淘汰（最旧 = 数组头部）
            $hashes = array_values(array_unique(array_filter($hashes, 'is_string')));
            while (count($hashes) > self::MAX_TOKENS) {
                array_shift($hashes);
            }

            $json = \json_encode($hashes);
            if ($json === false) {
                $db->getConnection()->exec('ROLLBACK');
                return false;
            }

            $params = [':t' => $json, ':id' => $userId];
            $sql = 'UPDATE users SET remember_token = :t WHERE id = :id';
            if ($touchLastLogin) {
                $sql = 'UPDATE users SET remember_token = :t, last_login = :ts WHERE id = :id';
                $params[':ts'] = \date('Y-m-d H:i:s');
            }
            $db->query($sql, $params);

            $db->getConnection()->exec('COMMIT');
            return true;
        } catch (\Throwable $e) {
            try { $db->getConnection()->exec('ROLLBACK'); } catch (\Throwable $e2) {}
            \error_log('RememberMe mutateTokens error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 解析存储值：
     * - JSON 数组 → hash 列表（元素须为 64 字符 sha256 hex，超长/畸形元素过滤）
     * - 存量裸 hash（升级前单值数据）→ 仅当为 64 字符 sha256 hex 时兼容为单元素数组
     * - 空值 / '[]' → 空数组
     */
    private static function parseTokens(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '[]') {
            return [];
        }
        $decoded = \json_decode($raw, true);
        if (\is_array($decoded)) {
            // JSON 分支元素过滤：仅保留 64 字符 sha256 hex 字符串，
            // 防历史/脏数据里的超长元素经 json_encode 写回时撑大 DB 字段
            return array_values(array_filter($decoded, function ($v) {
                return \is_string($v) && strlen($v) === 64;
            }));
        }
        // 裸串兼容分支：旧版裸 hash 为 64 字符 sha256 hex；
        // 长度不符（超长/畸形/被篡改）一律视为无效，防止塞入数组后被 json_encode 写回膨胀
        return (strlen($raw) === 64) ? [$raw] : [];
    }

    /**
     * 失配告警：写审计日志（target_id 传 cookie 里的 user_id 留痕，IP/UA 由 AuditLog 自动捕获）
     */
    private static function logMismatch(int $userId, string $reason): void
    {
        try {
            \app\Helpers\AuditLog::log('remember_fail', 'user', $userId, '记住登录校验失败：' . $reason);
        } catch (\Throwable $e) {
            \error_log('RememberMe audit log error: ' . $e->getMessage());
        }
    }

    public static function clearCookies(): void
    {
        foreach (['remember_token', 'remember_user_id'] as $name) {
            if (isset($_COOKIE[$name])) {
                \setcookie($name, '', [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        }
    }
}
