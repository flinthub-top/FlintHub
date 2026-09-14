<?php
/**
 * FlintHub — cli 脚本 Web 直访统一守卫
 * cli/ 目录脚本含迁移/归档/队列消费等管理操作，此前无 SAPI 守卫、无 Nginx 防护，
 * 可被 Web 直接访问触发真实写操作。
 * 本文件被 cli/ 全部脚本在加载框架前 require，非命令行（web/cgi 等 SAPI）
 * 一律 403 退出；CLI（cron / php -f / supervisor）不受影响。
 * @file cli/_guard.php
 * @package app\cli
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: CLI-only script.');
}
