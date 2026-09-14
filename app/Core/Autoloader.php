<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * PSR-4 风格自动加载器 — 支持 app\ 和 Plugin\ 命名空间
 * @file app/Core/Autoloader.php
 * @package app\Core
 */

spl_autoload_register(function ($class) {
    $camelToSnake = function ($str) {
        return ltrim(strtolower(preg_replace('/[A-Z]/', '_$0', $str)), '_');
    };

    $prefix = 'app\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) === 0) {
        $relativeClass = substr($class, $len);
        // 过滤路径穿越：禁止类名中包含 ..（与 Plugin 分支同款；PHP 类名规范本不允许，纵深防御）
        if (strpos($relativeClass, '..') !== false) {
            return;
        }
        $file = __DIR__ . '/../' . str_replace('\\', '/', $relativeClass) . '.php';
        // realpath 校验：文件必须落在 app 根目录内，防兄弟目录/软链绕过（与 Plugin 分支同款）
        $realFile = realpath($file);
        $realBase = realpath(__DIR__ . '/../');
        $realBaseSafe = $realBase !== false ? rtrim($realBase, '/\\') . DIRECTORY_SEPARATOR : '';
        if ($realFile === false || $realBaseSafe === '' || strpos($realFile, $realBaseSafe) !== 0) {
            return;
        }
        if (file_exists($realFile)) {
            require_once $realFile;
            return;
        }
    }

    $prefix = 'Plugin\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) === 0) {
        $relativeClass = substr($class, $len);

        $parts = explode('\\', $relativeClass);
        $pluginDir = $parts[0] ?? '';
        $rest = isset($parts[1]) ? implode('/', array_slice($parts, 1)) : '';

        $paths = [
            $pluginDir . '/' . $rest . '.php',
            $camelToSnake($pluginDir) . '/' . $rest . '.php',
            strtolower($pluginDir) . '/' . strtolower($rest) . '.php',
        ];

        $baseDir = __DIR__ . '/../../plugins/';
        foreach ($paths as $path) {
            // 过滤路径穿越：禁止插件类名中包含 ..
            if (\strpos($path, '..') !== false) {
                continue;
            }
            $file = $baseDir . $path;
            // realpath 验证，防止兄弟目录绕过
            $realFile = realpath($file);
            $realBase = realpath($baseDir);
            $realBaseSafe = $realBase !== false ? rtrim($realBase, '/\\') . DIRECTORY_SEPARATOR : '';
            if ($realFile === false || $realBaseSafe === '' || strpos($realFile, $realBaseSafe) !== 0) {
                continue;
            }
            if (file_exists($realFile)) {
                require_once $realFile;
                return;
            }
        }
    }
});
