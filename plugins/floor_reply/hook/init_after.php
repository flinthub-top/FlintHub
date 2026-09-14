<?php
/**
 * 楼中楼插件 初始化后建表守卫
 * @file plugins/floor_reply/hook/init_after.php
 * @package Plugin\FloorReply
 * @version 1.0.0
 */

if (\app\Helpers\Plugin::isActivated('floor_reply')) {
    $cacheFile = __DIR__ . '/../../../protected/.floor_reply_table_cache';
    if (file_exists($cacheFile)) return;

    try {
        \Plugin\FloorReply\Plugin::activate();
        file_put_contents($cacheFile, '1', LOCK_EX);
    } catch (\Throwable $e) {
        \error_log('floor_reply init error: ' . $e->getMessage());
    }
}
