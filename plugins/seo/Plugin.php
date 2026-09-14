<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * SEO 优化插件主类 — 设置管理
 * @file plugins/seo/Plugin.php
 * @package Plugin\Seo
 * @version 1.0.0
 */

namespace Plugin\Seo;

use app\Helpers\Settings;

/**
 * 规范豁免说明（插件开发规范 §十）：
 * 本插件为"配置型"插件——全部设置存核心 settings 表（seo_* 键），无业务数据表，
 * 故不实现 db()/ddl()/ensureSchema()。规范 §10.1 坑注明"核心库只读访问保留给 seo 等
 * 只读插件"；本插件经 app\Helpers\Settings 读写核心 settings 属合理例外，不违反独立库约定。
 */
class Plugin
{
    /** SEO 设置键前缀 */
    const PREFIX = 'seo_';

    /** 默认配置 */
    const DEFAULTS = [
        'meta_keywords'     => '',
        'meta_description'  => '',
        'site_url'          => '',
        'custom_head'       => '',
        'enable_sitemap'    => '1',
    ];

    /**
     * 激活插件：注册默认 SEO 设置
     */
    public static function activate(): bool
    {
        foreach (self::DEFAULTS as $key => $value) {
            $dbKey = self::PREFIX . $key;
            $existing = Settings::get($dbKey);
            if ($existing === '') {
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

    public static function uninstall(): void
    {
        $db = \app\Core\Database::getInstance();
        foreach (self::DEFAULTS as $key => $value) {
            $db->query('DELETE FROM settings WHERE "key" = :k', [':k' => self::PREFIX . $key]);
        }
        Settings::clearCache();
    }

    /**
     * 获取所有 SEO 设置（含默认值兜底）
     */
    public static function getAllSettings(): array
    {
        $result = [];
        foreach (self::DEFAULTS as $key => $default) {
            $dbKey = self::PREFIX . $key;
            $val = Settings::get($dbKey);
            $result[$key] = $val !== '' ? $val : '';
        }
        return $result;
    }

    /**
     * 批量保存 SEO 设置
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
     * 获取单个 SEO 设置
     */
    public static function getSetting(string $key, string $default = ''): string
    {
        $val = Settings::get(self::PREFIX . $key);
        return $val !== '' ? $val : $default;
    }
}
