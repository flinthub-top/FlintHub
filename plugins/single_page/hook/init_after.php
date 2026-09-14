<?php
/**
 * FlintHub — 单页/链接管理插件 初始化后建表守卫
 * 沿用 friend_links 的缓存标记模式：表已存在时每请求 0 查询
 * @file plugins/single_page/hook/init_after.php
 * @package Plugin\SinglePage
 * @version 1.0.0
 */

if (\app\Helpers\Plugin::isActivated('single_page')) {
    $cacheFile = __DIR__ . '/../../../protected/.single_page_table_cache';
    if (file_exists($cacheFile)) return;

    try {
        \Plugin\SinglePage\Plugin::activate();
        file_put_contents($cacheFile, '1', LOCK_EX);
    } catch (\Throwable $e) {
        \error_log('single_page init error: ' . $e->getMessage());
    }
}
