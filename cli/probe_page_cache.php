<?php
/**
 * cli/probe_page_cache.php — 独立探针：直接读取首页缓存文件并输出，计时「启动→输出结束」
 *
 * 用途：排除 Autoloader / init.php / config.php / 路由分发 / 前置缓存类加载的影响，
 *       单独验证纯 PHP 读取并输出 data/runtime/pages/home_1.html 的真实耗时。
 * 不依赖 index.php / init.php / config.php / Autoloader，纯标准 PHP（无任何框架代码）。
 *
 * 用法：
 *   php cli/probe_page_cache.php             # 直接输出文件内容 + [probe] 计时报告
 *   php cli/probe_page_cache.php --quiet     # 不输出内容（ob 捕获），只打印 [probe] 报告
 *
 * 预期结果解读：
 *   - 总耗时 ≈ 0.01s 以下 → 纯 PHP 输出缓存极快，0.12s 的瓶颈应在路由分发 /
 *     前置缓存的 Autoloader 类加载 / Web 服务器连接层（而非文件读取）；
 *   - 总耗时 > 0.1s      → PHP 或文件读取本身在数据量大时存在开销，需进一步定位。
 *
 * 注意：本脚本的计时起点是文件内第一条语句，不含 PHP 解释器/CLI 进程启动
 *       （那部分属于 PHP-FPM/CLI 启动开销，可用 `php -r "echo 1;"` 另行测量基准）。
 */

// 计时起点：脚本第一条可执行语句
$tStart = microtime(true);

require_once __DIR__ . '/_guard.php'; // Web 直访守卫（CLI-only）

// 缓存文件路径：脚本位于 cli/ 下 → 站点根 data/runtime/pages/home_1.html
$cacheFile = __DIR__ . '/../data/runtime/pages/home_1.html';

// 浏览器（Web SAPI）下无 $argv，仅 CLI 支持 --quiet；此处加 SAPI 守卫保证浏览器访问安全
$quiet = (PHP_SAPI === 'cli') && in_array('--quiet', array_slice($argv, 1), true);

if (!is_file($cacheFile)) {
    fwrite(STDERR, "[probe] 缓存文件不存在: {$cacheFile}\n");
    fwrite(STDERR, "[probe] 请先访问一次首页生成缓存，再运行本脚本\n");
    exit(1);
}

// 模拟简化 HTTP 响应头（Web 环境下真实生效；CLI 下为无害空操作）
header('Content-Type: text/html; charset=utf-8');

// 直接输出文件内容（readfile 流式输出 = 前置缓存命中时的真实输出路径）
if ($quiet) {
    ob_start();
    $bytes = @readfile($cacheFile);
    $content = ob_get_clean();
    unset($content);
} else {
    $bytes = @readfile($cacheFile);
}

// 计时终点：输出结束
$tEnd = microtime(true);
$totalMs = ($tEnd - $tStart) * 1000;

$size = @filesize($cacheFile);

// ==================== [probe] 计时报告 ====================
printf("\n[probe] 文件: %s\n", $cacheFile);
printf("[probe] 大小: %d 字节（%.2f KB）\n", $size, $size / 1024);
printf("[probe] readfile 输出字节: %d（与文件一致=%s）\n", (int)$bytes, (int)$bytes === $size ? '是' : '否');
printf("[probe] 总耗时（启动→输出结束）: %.3f ms\n", $totalMs);
if ($totalMs < 10) {
    printf("[probe] 解读: <10ms → 纯 PHP 输出缓存极快，0.12s 瓶颈应在路由分发 / Autoloader 类加载 / 服务器连接层\n");
} elseif ($totalMs > 100) {
    printf("[probe] 解读: >100ms → PHP 或文件读取本身存在开销，需进一步定位\n");
} else {
    printf("[probe] 解读: 10~100ms 中间地带，建议与线上 curl 命中耗时（2.3ms）对比判断\n");
}
exit(0);
