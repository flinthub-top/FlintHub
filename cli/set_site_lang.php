<?php
/**
 * FlintHub 1.0 — 设置站点默认语言（多语言改造 update/6）
 *
 * 用途：
 *   管理员在服务器上运行本脚本，把 settings 表的 site_lang 键设置为站点默认语言
 *   （语言判定顺序第 4 项：URL ?lang= > Cookie > Session > 后台 site_lang > 浏览器 > 中文兜底）。
 *   不改任何表结构——settings 为既有 KV 表，仅 UPSERT 一行。
 *
 * 用法：
 *   php cli/set_site_lang.php             # 查看当前站点默认语言
 *   php cli/set_site_lang.php zh          # 设为中文（默认）
 *   php cli/set_site_lang.php en          # 设为英文
 *
 * 说明：
 *   语言码白名单 = lang/ 目录下实际存在的语言包（I18n::available()），非法值拒绝写入；
 *   写入后自动重建 Settings 缓存（protected/settings_cache.php），立即生效，无需重启。
 *
 * @package app\cli
 */

// 读取 config.php（含 SPLITDB_DATA_PATH 等），与 app/init.php 加载链一致
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_guard.php'; // Web 直访守卫（CLI-only）
require_once __DIR__ . '/../app/Core/Autoloader.php';

use app\Helpers\I18n;
use app\Helpers\Settings;
use app\Models\Setting;

$lang = $argv[1] ?? '';

// ---- 查看当前值 ----
if ($lang === '') {
    $current = Settings::get('site_lang', I18n::DEFAULT_LANG);
    echo "当前站点默认语言: {$current}\n";
    echo "可用语言: " . implode(', ', I18n::available()) . "\n";
    echo "用法: php cli/set_site_lang.php [zh|en]\n";
    exit(0);
}

// ---- 写入 ----
$available = I18n::available();
if (!in_array($lang, $available, true)) {
    fwrite(STDERR, "非法语言码 '{$lang}'（白名单: " . implode(', ', $available) . "）\n");
    exit(1);
}

try {
    (new Setting())->setValue('site_lang', $lang);
    Settings::buildCache(); // 重建 settings_cache.php，令 Settings::get 立即返回新值
    echo "站点默认语言已设置为: {$lang}\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "写入失败: " . $e->getMessage() . "\n");
    exit(1);
}
