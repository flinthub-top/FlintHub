<?php
/**
 * FlintHub — 多语言支持核心助手（i18n）
 *
 * 语言包：根目录 lang/{lang}.php（纯数据数组，懒加载，不参与 PSR-4 自动加载）
 * 判定顺序（§3.2）：URL ?lang= > Cookie flinthub_lang > Session lang >
 *   后台设置 site_lang > 浏览器 Accept-Language > 中文兜底
 * 回退链：目标语言缺 key → 中文 → key 本身（永不白屏/报错）
 *
 * @file app/Helpers/I18n.php
 * @package app\Helpers
 */

namespace app\Helpers {
    class I18n
    {
        /** @var array<string,array> 已加载语言包缓存（lang => dict） */
        private static array $loaded = [];

        /** @var string|null 当前语言（首次访问时探测并缓存） */
        private static ?string $current = null;

        /** 默认语言 */
        public const DEFAULT_LANG = 'zh';

        /** Cookie 名与有效期（秒） */
        public const COOKIE_NAME = 'flinthub_lang';
        public const COOKIE_TTL = 2592000; // 30 天

        /** 语言目录绝对路径（根目录 lang/） */
        public static function dir(): string
        {
            return dirname(__DIR__, 2) . '/lang';
        }

        /**
         * 懒加载语言包（进程内缓存，不重复 require）
         */
        public static function load(string $lang): array
        {
            $lang = self::sanitize($lang);
            if (isset(self::$loaded[$lang])) {
                return self::$loaded[$lang];
            }
            $file = self::dir() . '/' . $lang . '.php';
            $dict = is_file($file) ? (array)require $file : [];
            // 自动合并已启用插件的语言包（核心键优先，插件键不覆盖核心键）
            self::mergePluginLangs($lang, $dict);
            self::$loaded[$lang] = $dict;
            return self::$loaded[$lang];
        }

        /**
         * 合并已启用插件的语言包：遍历 plugins/ 下已启用插件，
         * 若 plugins/{插件名}/lang/{lang}.php 存在则自动并入（插件无需写任何钩子代码）。
         * 合并使用数组联合运算：核心键优先，插件仅补充缺失键。
         */
        private static function mergePluginLangs(string $lang, array &$dict): void
        {
            $pluginsRoot = dirname(self::dir()) . '/plugins';
            foreach (glob($pluginsRoot . '/*') ?: [] as $dir) {
                if (!is_dir($dir)) continue;
                // 仅合并已启用插件
                $cfgFile = $dir . '/plugin.json';
                if (!is_file($cfgFile)) continue;
                $cfg = @json_decode((string)@file_get_contents($cfgFile), true);
                if (empty($cfg['activated'])) continue;
                $langFile = $dir . '/lang/' . $lang . '.php';
                if (!is_file($langFile)) continue;
                $extra = (array)require $langFile;
                if ($extra) {
                    $dict = $dict + $extra; // 核心优先：左侧已有键不被右侧覆盖
                }
            }
        }

        /**
         * 手动注册额外语言文案（供特殊场景使用；插件语言包已由 load() 自动合并）
         * 合并规则：已存在的键优先，$dict 仅补充缺失键，不会覆盖核心/已注册文案
         */
        public static function registerExtra(string $lang, array $dict): void
        {
            $lang = self::sanitize($lang);
            if (!isset(self::$loaded[$lang])) {
                self::load($lang);
            }
            self::$loaded[$lang] = self::$loaded[$lang] + $dict;
        }

        /**
         * 可用语言列表（扫描 lang/ 目录，文件名即语言码）
         */
        public static function available(): array
        {
            $langs = [];
            foreach (glob(self::dir() . '/*.php') ?: [] as $f) {
                $langs[] = basename($f, '.php');
            }
            sort($langs);
            return $langs;
        }

        /**
         * 取当前语言（探测结果进程内缓存）
         */
        public static function current(): string
        {
            if (self::$current === null) {
                self::$current = self::detect();
            }
            return self::$current;
        }

        /**
         * 设置当前语言并持久化（Cookie + Session）
         * 非法语言回退默认 zh；返回是否切换成功
         */
        public static function set(string $lang): bool
        {
            $lang = self::sanitize($lang);
            if (!in_array($lang, self::available(), true)) {
                $lang = self::DEFAULT_LANG;
            }
            self::$current = $lang;

            if (PHP_SAPI !== 'cli' && !headers_sent()) {
                setcookie(self::COOKIE_NAME, $lang, time() + self::COOKIE_TTL, '/', '', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', true);
                $_COOKIE[self::COOKIE_NAME] = $lang; // 当前请求立即生效
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['lang'] = $lang;
            }
            return true;
        }

        /**
         * 取当前语言文案；缺 key 依次回退中文 → key 本身
         *
         * @param string $key    语义化 key（如 forum.latest）
         * @param array  $params 占位符替换（{name} => 值）
         */
        public static function get(string $key, array $params = []): string
        {
            $lang = self::current();
            $dict = self::load($lang);
            $text = $dict[$key] ?? null;

            if ($text === null && $lang !== self::DEFAULT_LANG) {
                $text = self::load(self::DEFAULT_LANG)[$key] ?? null;
            }
            if ($text === null) {
                return $key; // 永不白屏
            }
            if ($params) {
                foreach ($params as $k => $v) {
                    $text = str_replace('{' . $k . '}', (string)$v, $text);
                }
            }
            return $text;
        }

        /**
         * [i18n] JS 端文案子集：仅返回 js.* 前缀 key（供前端 Alpine.store('i18n') 注入，避免全量输出）
         */
        public static function jsSubset(): array
        {
            $lang = self::current();
            $dict = self::load($lang);
            $subset = [];
            foreach ($dict as $k => $v) {
                if (strpos($k, 'js.') === 0 || strpos($k, 'lang.') === 0) {
                    $subset[$k] = $v;
                }
            }
            // 中文兜底：en 缺 key 时回退中文（保证前端 __t 永不返回空）
            if ($lang !== self::DEFAULT_LANG) {
                $zh = self::load(self::DEFAULT_LANG);
                foreach ($subset as $k => $v) {
                    if ($v === '' && isset($zh[$k])) {
                        $subset[$k] = $zh[$k];
                    }
                }
            }
            return $subset;
        }

        /**
         * 语言码白名单清洗（防路径穿越：lang/ 后仅允许字母数字下划线）
         */
        private static function sanitize(string $lang): string
        {
            $lang = strtolower(trim($lang));
            return preg_match('/^[a-z][a-z0-9_-]*$/', $lang) ? $lang : self::DEFAULT_LANG;
        }

        /**
         * 探测当前语言（§3.2 判定顺序）
         */
        private static function detect(): string
        {
            // 1. URL 参数（htmx 局部请求 /api/* 也通过 lang= 传递语言标识）
            if (isset($_GET['lang'])) {
                $l = self::sanitize((string)$_GET['lang']);
                if (in_array($l, self::available(), true)) return $l;
            }
            // 2. Cookie
            if (isset($_COOKIE[self::COOKIE_NAME])) {
                $l = self::sanitize((string)$_COOKIE[self::COOKIE_NAME]);
                if (in_array($l, self::available(), true)) return $l;
            }
            // 3. Session
            if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['lang'])) {
                $l = self::sanitize((string)$_SESSION['lang']);
                if (in_array($l, self::available(), true)) return $l;
            }
            // 4. 后台默认设置（Settings 未初始化时静默跳过）
            try {
                $siteLang = self::sanitize((string)Settings::get('site_lang', self::DEFAULT_LANG));
                if (in_array($siteLang, self::available(), true)) return $siteLang;
            } catch (\Throwable $e) {
                // 表尚未初始化时忽略
            }
            // 5. 浏览器语言（仅当没有任何 Cookie/Session 记录时探测）
            $accept = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
            if ($accept !== '') {
                $first = strtolower(trim(explode(',', $accept)[0] ?? ''));
                if (strpos($first, 'en') === 0 && in_array('en', self::available(), true)) {
                    return 'en';
                }
            }
            // 6. 中文兜底
            return self::DEFAULT_LANG;
        }
    }
}

namespace {
    /**
     * 全局翻译快捷函数（控制器/Helper 层使用，等价 I18n::get）
     */
    if (!function_exists('t')) {
        function t(string $key, array $params = []): string
        {
            return \app\Helpers\I18n::get($key, $params);
        }
    }
}
