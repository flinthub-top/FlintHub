<?php
/**
 * FlintHub — 任务中心插件 初始化后建表守卫
 * @file plugins/task_center/hook/init_after.php
 * @package Plugin\TaskCenter
 * @version 1.0.0
 */

if (\app\Helpers\Plugin::isActivated('task_center')) {
    $cacheFile = __DIR__ . '/../../../protected/.task_center_table_cache';
    if (file_exists($cacheFile)) return;

    try {
        \Plugin\TaskCenter\Plugin::activate();
        file_put_contents($cacheFile, '1', LOCK_EX);
    } catch (\Throwable $e) {
        \error_log('task_center init error: ' . $e->getMessage());
    }
}
