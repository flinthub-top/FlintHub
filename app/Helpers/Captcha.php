<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 验证码生成 — 图片验证码、哈希校验
 * @file app/Helpers/Captcha.php
 * @package app\Helpers
 */

namespace app\Helpers;

class Captcha
{
    /**
     * 获取验证码盐值（存储在 settings 表中，首次自动生成）
     */
    public static function getSalt(): string
    {
        try {
            $db = \app\Core\Database::getInstance();
            $row = $db->fetchOne('SELECT value FROM settings WHERE "key" = :k', [':k' => 'captcha_salt']);
            if ($row && !empty($row['value'])) {
                return $row['value'];
            }
            // 首次使用，随机生成
            $salt = \bin2hex(\random_bytes(16));
            // SQLite UPSERT（ON CONFLICT 兼容 3.33）替代 ON DUPLICATE KEY UPDATE
            $db->query("INSERT INTO settings (\"key\", value) VALUES ('captcha_salt', :v) ON CONFLICT(\"key\") DO UPDATE SET value = :v2",
                [':v' => $salt, ':v2' => $salt]);
            return $salt;
        } catch (\Exception $e) {
            // fallback: 如果 settings 表不可用，使用基于时间的随机盐
            return \hash('sha256', 'FlintHubCaptcha_' . \__DIR__);
        }
    }

    public static function verify(?string $input): bool
    {
        if (!isset($_SESSION['captcha_hash']) || !isset($_SESSION['captcha_time'])) return false;

        if (\time() - $_SESSION['captcha_time'] > 180) {
            unset($_SESSION['captcha_hash'], $_SESSION['captcha_time']);
            return false;
        }

        $expected = $_SESSION['captcha_hash'];
        $inputHash = \hash('sha256', \strtolower(\trim($input ?? '')) . self::getSalt());
        unset($_SESSION['captcha_hash'], $_SESSION['captcha_time']);

        return \hash_equals($expected, $inputHash);
    }
}
