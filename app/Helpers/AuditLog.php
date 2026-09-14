<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 审计日志 — 自动建表、关键操作记录、IP/UA 追踪
 * @file app/Helpers/AuditLog.php
 * @package app\Helpers
 */

namespace app\Helpers;

class AuditLog
{
    /** @var string 日志保留天数 */
    const RETENTION_DAYS = 90;

    /** @var bool 表是否已初始化 */
    private static bool $tableChecked = false;

    /**
     * 受信反代网段（IP 以逗号分隔的列表）：
     * 仅当直连来源 REMOTE_ADDR 落在这些网段内，才采信 X-Forwarded-For，
     * 否则忽略 XFF，记录直连 IP——防攻击者伪造审计 IP 嫁祸他人。
     * 含本机反代(nginx/caddy/php-cgi)、常见内网段与 CGNAT。
     */
    private const TRUSTED_PROXIES = [
        '127.0.0.1/32', '::1',                  // 本机反代
        '10.0.0.0/8', '172.16.0.0/12',       // RFC1918
        '192.168.0.0/16',
        '100.64.0.0/10',                      // CGNAT
    ];

    /**
     * 判断直连 IP 是否落在受信代理网段内（支持 IPv4 CIDR 与 ::1 字面量）
     */
    private static function isTrustedProxy(string $ip): bool
    {
        if (!\filter_var($ip, \FILTER_VALIDATE_IP)) {
            return false;
        }
        foreach (self::TRUSTED_PROXIES as $range) {
            if (\strpos($range, '/') === false) {
                if ($ip === $range) return true;          // 无 CIDR → 精确匹配（含 ::1 字面量）
                continue;
            }
            // 仅 IPv4 CIDR 走位运算；IPv6 网段普通环境用不上，受信列表已含 ::1 字面量
            if (\strpos($ip, ':') === false && self::ipV4InCidr($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    /**
     * IPv4 CIDR 匹配（整数位运算，无需扩展）
     */
    private static function ipV4InCidr(string $ip, string $range): bool
    {
        [$subnet, $bits] = \explode('/', $range, 2);
        if (!\filter_var($subnet, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) || !\ctype_digit($bits)) {
            return false;
        }
        $ipLong    = \ip2long($ip);
        $subnetLong = \ip2long($subnet);
        $mask      = $bits === '0' ? 0 : (int)(0xFFFFFFFF << (32 - (int)$bits) & 0xFFFFFFFF);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * 获取客户端真实 IP（支持 CDN/反代）
     * XFF 可被伪造，仅用于审计展示不参与鉴权/限流；
     * 仅当直连来源 REMOTE_ADDR 落在受信代理网段内才采信 XFF，否则恒记直连 IP，防审计 IP 伪造
     */
    private static function getClientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($remote !== '' && self::isTrustedProxy($remote) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // 取 XFF 最左侧（客户端最近一跳）IP
            $first = \trim((string)\strtok($_SERVER['HTTP_X_FORWARDED_FOR'], ','));
            if (\filter_var($first, \FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return $remote;
    }

    /**
     * 确保 audit_logs 表存在（幂等）
     */
    private static function ensureTable(): void
    {
        if (self::$tableChecked) return;
        self::$tableChecked = true;

        try {
            $db = \app\Core\Database::getInstance();
            // audit_logs 表由 Schema::bootstrap 统一创建（幂等兜底，不重建）
            $db->fetchOne('SELECT COUNT(*) as cnt FROM audit_logs');
        } catch (\Throwable $e) {
            \error_log('AuditLog::ensureTable failed: ' . $e->getMessage());
        }
    }

    /**
     * 记录一条审计日志
     * @param string $action      操作类型（login/logout/delete/update/create/ban/admin_action）
     * @param string $target_type 目标类型（thread/post/user/blog/setting/...）
     * @param int    $target_id   目标 ID
     * @param string $detail      操作详情（不超过 500 字）
     */
    public static function log(string $action, string $target_type = '', int $target_id = 0, string $detail = ''): void
    {
        self::ensureTable();

        try {
            $userId = 0;
            $username = '';
            if (isset($_SESSION['user_id'])) {
                $userId = (int)$_SESSION['user_id'];
                // 从缓存获取用户名，避免查库开销
                $username = $_SESSION['audit_username'] ?? '';
            }

            $db = \app\Core\Database::getInstance();
            $db->query(
                "INSERT INTO audit_logs (user_id, username, action, target_type, target_id, detail, ip, user_agent, created_at)
                 VALUES (:uid, :username, :action, :target_type, :target_id, :detail, :ip, :ua, :created_at)",
                [
                    ':uid'        => $userId,
                    ':username'   => mb_substr($username, 0, 100),
                    ':action'     => mb_substr($action, 0, 50),
                    ':target_type'=> mb_substr($target_type, 0, 50),
                    ':target_id'  => $target_id,
                    ':detail'     => mb_substr($detail, 0, 500),
                    ':ip'         => self::getClientIp(),
                    ':ua'         => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                    // created_at 原漏列导致时间恒为 NULL（建表亦无默认值）；显式写入本地时间
                    ':created_at' => date('Y-m-d H:i:s'),
                ]
            );
        } catch (\Throwable $e) {
            // 审计日志失败不应影响主流程，静默记录
            \error_log('AuditLog::log failed: ' . $e->getMessage());
        }
    }

    /**
     * 查询审计日志
     * @param string $action      按操作类型筛选（空=全部）
     * @param int    $userId      按用户 ID 筛选（0=全部）
     * @param int    $page        页码（从 1 开始）
     * @param int    $perPage     每页条数
     * @param string $date        按日期筛选（YYYY-MM-DD，空=全部；created_at LIKE 前缀命中 idx_audit_logs_created）
     * @return array ['items' => [...], 'total' => int]
     */
    public static function query(string $action = '', int $userId = 0, int $page = 1, int $perPage = 50, string $date = ''): array
    {
        self::ensureTable();

        try {
            $db = \app\Core\Database::getInstance();
            $where = '1=1';
            $params = [];

            if ($action !== '') {
                $where .= ' AND action = :action';
                $params[':action'] = $action;
            }
            if ($userId > 0) {
                $where .= ' AND user_id = :uid';
                $params[':uid'] = $userId;
            }
            if ($date !== '') {
                $where .= ' AND created_at LIKE :date_prefix';
                $params[':date_prefix'] = $date . '%';
            }

            $count = $db->fetchOne("SELECT COUNT(*) as cnt FROM audit_logs WHERE {$where}", $params);
            $total = (int)($count['cnt'] ?? 0);

            $offset = max(0, ($page - 1) * $perPage);
            $items = $db->fetchAll(
                "SELECT * FROM audit_logs WHERE {$where} ORDER BY created_at DESC LIMIT :limit OFFSET :offset",
                $params + [':limit' => $perPage, ':offset' => $offset]
            );

            return ['items' => $items, 'total' => $total];
        } catch (\Throwable $e) {
            \error_log('AuditLog::query failed: ' . $e->getMessage());
            return ['items' => [], 'total' => 0];
        }
    }

    /**
     * 清理超过保留天数的旧日志（可 cron 调用）
     */
    public static function purgeOldLogs(): int
    {
        self::ensureTable();

        try {
            $db = \app\Core\Database::getInstance();
            // SQLite：日期比较用 PHP 计算截止时间（不依赖 DATE_SUB/NOW）
            $cutoff = date('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400);
            $result = $db->query(
                "DELETE FROM audit_logs WHERE created_at < :cutoff",
                [':cutoff' => $cutoff]
            );
            return $result ? $result->rowCount() : 0;
        } catch (\Throwable $e) {
            \error_log('AuditLog::purgeOldLogs failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 设置当前会话中的审计用户名（在登录成功后调用）
     */
    public static function setSessionUsername(string $username): void
    {
        $_SESSION['audit_username'] = $username;
    }
}
