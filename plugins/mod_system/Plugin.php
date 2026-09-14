<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 社区治理插件主类 — 配置管理、数据表、封禁服务、通知、举报工具
 * @file plugins/mod_system/Plugin.php
 * @package Plugin\ModSystem
 * @version 1.0.0
 */

namespace Plugin\ModSystem;

use app\Helpers\Settings;
use app\Helpers\Auth;

class Plugin
{
    /** 进程内建表已执行标记（自愈：首次访问自动建表，避免未手动激活/新部署时"no such table" 500） */
    private static bool $schemaReady = false;

    /** 配置键前缀（存核心库 settings 表） */
    const PREFIX = 'modsystem_';

    /** 默认配置 */
    const DEFAULTS = [
        'auto_audit_count'        => '3',   // 同内容被举报 N 次后台自动标红预警
        'daily_limit'             => '5',   // 每用户每日最多举报次数
        'cooldown'                => '300', // 同一用户对同一内容举报冷却秒数
        'notify_enabled'          => '1',   // 通知中心联动开关
        'ban_group_id'            => '',    // 封禁用户组 ID（后台手动填，不硬编码）
        'ban_days'                => '7',   // 小黑屋默认封禁天数（0/空=永久）
        'moderator_group_ids'     => '',    // （预留）可处理举报的高管用户组 ID 多选框
    ];

    /** 举报分类枚举 */
    const CATEGORIES = ['spam', 'porn', 'illegal', 'attack', 'flood', 'fake', 'other'];

    /** 状态常量 */
    const STATUS_PENDING   = 0; // 待处理
    const STATUS_HANDLED   = 1; // 已处理
    const STATUS_DISMISSED = 2; // 已驳回

    /** 目标类型 */
    const TARGETS = ['thread', 'post', 'blog', 'blog_comment', 'user'];

    /** [SplitDB] 插件独立库连接（plugins/mod_system/data/mod_system.sqlite） */
    public static function db(): \PDO
    {
        // 自愈：首次访问自动建表（幂等），保证上传文件即可用，无需手动激活或拷贝 sqlite
        if (!self::$schemaReady) {
            self::buildSchema();
            self::$schemaReady = true;
        }
        return \app\Helpers\Plugin::db('mod_system');
    }

    // ========================================================================
    //  配置
    // ========================================================================

    /**
     * 激活插件：建表 + 注册默认配置
     */
    public static function activate(): bool
    {
        self::buildSchema();
        foreach (self::DEFAULTS as $key => $value) {
            $dbKey = self::PREFIX . $key;
            if (Settings::get($dbKey) === '') {
                Settings::update($dbKey, $value);
            }
        }
        Settings::clearCache();
        return true;
    }

    public static function deactivate(): bool
    {
        return true;
    }

    /**
     * 卸载插件：删表 + 删配置
     */
    public static function uninstall(): void
    {
        self::db()->exec('DROP TABLE IF EXISTS mod_reports');
        self::db()->exec('DROP TABLE IF EXISTS mod_bans');
        self::db()->exec('DROP TABLE IF EXISTS mod_logs');

        $db = \app\Core\Database::getInstance();
        foreach (self::DEFAULTS as $key => $value) {
            $db->query('DELETE FROM settings WHERE "key" = :k', [':k' => self::PREFIX . $key]);
        }
        Settings::clearCache();
    }

    /**
     * 建表（幂等）
     */
    public static function buildSchema(): void
    {
        $reports = "CREATE TABLE IF NOT EXISTS mod_reports (
            id INTEGER PRIMARY KEY,
            reporter_id INTEGER NOT NULL,
            target_type TEXT NOT NULL,
            target_id INTEGER NOT NULL,
            target_uid INTEGER NOT NULL,
            category TEXT NOT NULL,
            note TEXT DEFAULT '',
            is_anonymous INTEGER NOT NULL DEFAULT 0,
            ip TEXT DEFAULT '',
            status INTEGER NOT NULL DEFAULT 0,
            handle_action TEXT DEFAULT '',
            handler_id INTEGER DEFAULT NULL,
            handled_at TEXT DEFAULT NULL,
            created_at TEXT NOT NULL
        )";
        \app\Helpers\Plugin::ensureSchema('mod_system', $reports);

        $bans = "CREATE TABLE IF NOT EXISTS mod_bans (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            reason TEXT DEFAULT '',
            banned_by INTEGER DEFAULT NULL,
            banned_at TEXT NOT NULL,
            expires_at TEXT DEFAULT NULL,
            original_group_id INTEGER DEFAULT NULL,
            status INTEGER NOT NULL DEFAULT 1,
            unbanned_by INTEGER DEFAULT NULL,
            unbanned_at TEXT DEFAULT NULL
        )";
        \app\Helpers\Plugin::ensureSchema('mod_system', $bans);

        $logs = "CREATE TABLE IF NOT EXISTS mod_logs (
            id INTEGER PRIMARY KEY,
            report_id INTEGER DEFAULT NULL,
            user_id INTEGER DEFAULT NULL,
            target_uid INTEGER DEFAULT NULL,
            action TEXT NOT NULL,
            detail TEXT DEFAULT '',
            created_at TEXT NOT NULL
        )";
        \app\Helpers\Plugin::ensureSchema('mod_system', $logs);
    }

    /**
     * 获取所有配置（含默认值兜底）
     */
    public static function getAllSettings(): array
    {
        $result = [];
        foreach (self::DEFAULTS as $key => $default) {
            $val = Settings::get(self::PREFIX . $key);
            $result[$key] = $val !== '' ? $val : $default;
        }
        return $result;
    }

    /**
     * 批量保存配置
     */
    public static function saveSettings(array $data): void
    {
        foreach (self::DEFAULTS as $key => $default) {
            if (array_key_exists($key, $data)) {
                Settings::update(self::PREFIX . $key, (string)$data[$key]);
            }
        }
        Settings::clearCache();
    }

    /**
     * 获取单个配置
     */
    public static function getSetting(string $key, string $default = ''): string
    {
        return Settings::get(self::PREFIX . $key, $default);
    }

    // ========================================================================
    //  举报按钮渲染（供各操作区钩子复用）
    // ========================================================================

    /**
     * 生成举报按钮 + 内联弹窗 HTML（登录用户才输出；Alpine 控制弹窗显隐，htmx 提交）
     * @param string $type thread/post/blog/blog_comment/user
     * @param int    $id   目标内容 ID（user 用则传用户 ID）
     * @param int    $uid  被举报用户 ID
     * @param string $targetName 目标简名，用于弹窗标题（可空）
     */
    public static function reportButtonHtml(string $type, int $id, int $uid, string $targetName = ''): string
    {
        if (!Auth::isLoggedIn()) return '';
        if (!in_array($type, self::TARGETS, true)) return '';

        // 防举报自己
        $me = (int)($_SESSION['user_id'] ?? 0);
        if ($type === 'user' && $uid === $me) return '';
        if (in_array($type, ['thread', 'post', 'blog', 'blog_comment'], true) && $uid === $me) {
            return '';
        }

        $csrf = \app\Helpers\Csrf::token();
        $base = \defined('BASE_PATH') ? \BASE_PATH : '';
        $title = \app\Helpers\I18n::get('plugin.mod_system.report_title');
        $labelCat = \app\Helpers\I18n::get('plugin.mod_system.report_category');
        $notes = \app\Helpers\I18n::get('plugin.mod_system.report_note');
        $ph = \app\Helpers\I18n::get('plugin.mod_system.report_note_placeholder');
        $anon = \app\Helpers\I18n::get('plugin.mod_system.report_anonymous');
        $cancel = \app\Helpers\I18n::get('plugin.mod_system.report_cancel');
        $submit = \app\Helpers\I18n::get('plugin.mod_system.report_submit');
        $catSpam = \app\Helpers\I18n::get('plugin.mod_system.cat.spam');
        $catPorn = \app\Helpers\I18n::get('plugin.mod_system.cat.porn');
        $catIllegal = \app\Helpers\I18n::get('plugin.mod_system.cat.illegal');
        $catAttack = \app\Helpers\I18n::get('plugin.mod_system.cat.attack');
        $catFlood = \app\Helpers\I18n::get('plugin.mod_system.cat.flood');
        $catFake = \app\Helpers\I18n::get('plugin.mod_system.cat.fake');
        $catOther = \app\Helpers\I18n::get('plugin.mod_system.cat.other');

        $id = (int)$id;
        $uid = (int)$uid;

        // 举报弹窗样式/脚本由插件独立 assets 提供，每页仅注入一次 <link>/<script>
        // 资源加 filemtime 版本号：浏览器缓存旧版 CSS/JS 时强制拉新（与 post_favorite 同规范）
        $cssVer = @filemtime(__DIR__ . '/assets/style.css') ?: 1;
        $jsVer  = @filemtime(__DIR__ . '/assets/script.js') ?: 1;
        $cssOnce = '';
        if (empty($GLOBALS['__mod_report_css_printed'])) {
            $GLOBALS['__mod_report_css_printed'] = true;
            $cssOnce = '<link rel="stylesheet" href="' . $base . '/plugins/mod_system/assets/style.css?v=' . $cssVer . '">';
        }
        $jsOnce = '';
        if (empty($GLOBALS['__mod_report_js_printed'])) {
            $GLOBALS['__mod_report_js_printed'] = true;
            $jsOnce = '<script src="' . $base . '/plugins/mod_system/assets/script.js?v=' . $jsVer . '"></script>';
        }

        return <<<HTML
{$cssOnce}{$jsOnce}<span x-data="{open:false}" class="mn-report">
<button type="button" class="mn-link-report mn-fs-12 mn-text-muted mn-flex-center" x-on:click="open=true"
        title="{$title}"><i class="fa mn-report-icon">&#xf024;</i></button>
<div x-show="open" x-transition x-cloak class="mn-report-overlay"
     data-endpoint="{$base}/mod-system/report" x-on:click.self="open=false">
    <div class="mn-report-dialog" x-data="modReportForm({type:'{$type}',id:{$id},uid:{$uid}})"
         x-on:click.stop>
        <div class="mn-report-head">
            <span>{$title}</span>
            <button type="button" class="mn-report-close" x-on:click="open=false">&times;</button>
        </div>
        <form x-on:submit.prevent="submit()" id="modReportForm_{$type}_{$id}">
            <input type="hidden" name="csrf" value="{$csrf}">
            <input type="hidden" name="target_type" :value="type">
            <input type="hidden" name="target_id" :value="id">
            <input type="hidden" name="target_uid" :value="uid">
            <div class="mn-report-field">
                <label>{$labelCat}</label>
                <div class="mn-report-cats">
                    <label><input type="radio" name="category" value="spam" checked> {$catSpam}</label>
                    <label><input type="radio" name="category" value="porn"> {$catPorn}</label>
                    <label><input type="radio" name="category" value="illegal"> {$catIllegal}</label>
                    <label><input type="radio" name="category" value="attack"> {$catAttack}</label>
                    <label><input type="radio" name="category" value="flood"> {$catFlood}</label>
                    <label><input type="radio" name="category" value="fake"> {$catFake}</label>
                    <label><input type="radio" name="category" value="other"> {$catOther}</label>
                </div>
            </div>
            <label class="mn-report-field">
                <span class="mn-report-label">{$notes}</span>
                <textarea name="note" rows="3" maxlength="200" required
                          class="mn-textarea" placeholder="{$ph}"></textarea>
            </label>
            <label class="mn-report-anon">
                <input type="checkbox" name="anonymous" value="1"> {$anon}
            </label>
            <div class="mn-report-msg" x-show="msg.length" x-text="msg" x-cloak></div>
            <div class="mn-report-actions">
                <button type="button" class="mn-btn" x-on:click="open=false">{$cancel}</button>
                <button type="submit" class="mn-btn mn-btn-primary" :disabled="busy">
                    <span x-show="!busy">{$submit}</span><span x-show="busy">...</span>
                </button>
            </div>
        </form>
    </div>
</div>
</span>
HTML;
    }

    // ========================================================================
    //  封禁服务
    // ========================================================================

    /**
     * 判断用户是否处于封禁状态（附带懒解封）
     */
    public static function isBanned(int $uid): bool
    {
        $uid = (int)$uid;
        $db = self::db();
        $stmt = $db->prepare("SELECT id, expires_at, original_group_id FROM mod_bans WHERE user_id = :u AND status = 1");
        $stmt->execute([':u' => $uid]);
        $ban = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$ban) return false;

        // 懒解封：到期自动恢复
        if (!empty($ban['expires_at']) && strtotime($ban['expires_at']) <= time()) {
            self::autoUnban((int)$ban['id'], (int)$ban['original_group_id']);
            return false;
        }
        return true;
    }

    /**
     * 获取用户当前封禁记录（未到期才返回）
     */
    public static function getActiveBan(int $uid): ?array
    {
        $uid = (int)$uid;
        $db = self::db();
        $stmt = $db->prepare("SELECT * FROM mod_bans WHERE user_id = :u AND status = 1");
        $stmt->execute([':u' => $uid]);
        $ban = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$ban) return null;
        if (!empty($ban['expires_at']) && strtotime($ban['expires_at']) <= time()) {
            self::autoUnban((int)$ban['id'], (int)$ban['original_group_id']);
            return null;
        }
        return $ban;
    }

    /**
     * 执行封禁：记小黑屋 + 迁入封禁组
     * @param int    $uid 被禁用户
     * @param string $reason 原因
     * @param int    $adminId 处理人
     * @param int    $days 封禁天数，0=永久
     */
    public static function ban(int $uid, string $reason, int $adminId, int $days = 0): void
    {
        $uid = (int)$uid;
        $days = max(0, (int)$days);

        // 记录原用户组
        $coreDb = \app\Core\Database::getInstance();
        $urow = $coreDb->fetchOne('SELECT id, group_id FROM users WHERE id = :id', [':id' => $uid]);
        $originalGroupId = $urow['group_id'] ?? null;

        $now = date('Y-m-d H:i:s');
        $expires = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;

        $db = self::db();
        $stmt = $db->prepare(
            "INSERT INTO mod_bans (user_id, reason, banned_by, banned_at, expires_at, original_group_id, status)
             VALUES (:u, :r, :b, :now, :e, :og, 1)"
        );
        $stmt->execute([
            ':u' => $uid, ':r' => $reason, ':b' => $adminId, ':now' => $now,
            ':e' => $expires, ':og' => $originalGroupId,
        ]);

        // 迁入封禁用户组（配置了才迁，否则仅记表）——H-4/P2：走 User Model，不绕模型直写
        $banGroupId = (int)self::getSetting('ban_group_id');
        if ($banGroupId > 0) {
            (new \app\Models\User())->updateGroupId($uid, $banGroupId);
        }

        // 封禁即失效页面缓存，保证前台提示即时一致
        try { \app\Helpers\PageCache::invalidate(); } catch (\Throwable $e) {}
    }

    /**
     * 手动解封：删记录 + 恢复原组
     */
    public static function unban(int $accountId, int $adminId): void
    {
        $db = self::db();
        $stmt = $db->prepare("SELECT id, user_id, original_group_id FROM mod_bans WHERE id = :id AND status = 1");
        $stmt->execute([':id' => $accountId]);
        $ban = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$ban) return;

        $coreDb = \app\Core\Database::getInstance();
        $now = date('Y-m-d H:i:s');
        $db->prepare("UPDATE mod_bans SET status = 0, unbanned_by = :ub, unbanned_at = :ua WHERE id = :id")
            ->execute([':ub' => $adminId, ':ua' => $now, ':id' => $accountId]);

        // 恢复原用户组——H-4/P2：走 User Model，不绕模型直写
        if (!empty($ban['original_group_id'])) {
            (new \app\Models\User())->updateGroupId((int)$ban['user_id'], (int)$ban['original_group_id']);
        }

        try { \app\Helpers\PageCache::invalidate(); } catch (\Throwable $e) {}
    }

    /**
     * 自动解封（懒触发）：按记录 ID 恢复原组
     */
    private static function autoUnban(int $banRecordId, ?int $originalGroupId): void
    {
        $db = self::db();
        $now = date('Y-m-d H:i:s');
        $db->prepare("UPDATE mod_bans SET status = 0, unbanned_at = :ua WHERE id = :id AND status = 1")
            ->execute([':ua' => $now, ':id' => $banRecordId]);

        if ($originalGroupId) {
            $stmt = $db->prepare("SELECT user_id FROM mod_bans WHERE id = :id");
            $stmt->execute([':id' => $banRecordId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                // H-4/P2：走 User Model，不绕模型直写
                (new \app\Models\User())->updateGroupId((int)$row['user_id'], (int)$originalGroupId);
            }
        }
        try { \app\Helpers\PageCache::invalidate(); } catch (\Throwable $e) {}
    }

    // ========================================================================
    //  举报工具
    // ========================================================================

    /**
     * 获取某一目标（target_type+target_id）的待处理举报数
     */
    public static function countPendingReports(string $targetType, int $targetId): int
    {
        $db = self::db();
        $stmt = $db->prepare("SELECT COUNT(*) c FROM mod_reports WHERE target_type = :tt AND target_id = :ti AND status = 0");
        $stmt->execute([':tt' => $targetType, ':ti' => (int)$targetId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int)($row['c'] ?? 0);
    }

    /**
     * 获取管理员用户 ID 列表（role=admin && group_id=4）
     */
    public static function adminIds(): array
    {
        $db = \app\Core\Database::getInstance();
        $rows = $db->fetchAll("SELECT id FROM users WHERE role = 'admin' AND group_id = 4");
        $ids = [];
        foreach ($rows as $r) $ids[] = (int)$r['id'];
        return $ids;
    }

    // ========================================================================
    //  通知
    // ========================================================================

    /**
     * 写入一条通知（通知内置化：写入核心 notifications 表，统一通知中心单一入口）
     * 类型保留语义前缀 'mod_' + $type（mod_reported/mod_report_new/mod_auto_audit/mod_report_result），
     * 前台通知视图按此映射图标/分类；写入失败静默降级，不 500。
     */
    public static function notify(int $toUid, string $type, string $title, string $content, string $url = ''): void
    {
        if (Settings::get(self::PREFIX . 'notify_enabled', '1') !== '1') return;
        try {
            \app\Helpers\Notification::add(
                (int)$toUid,
                'mod_' . $type,
                $title,
                $url,
                '',
                $content
            );
        } catch (\Throwable $e) {
            \error_log('[mod_system] notify error: ' . $e->getMessage());
        }
    }
}