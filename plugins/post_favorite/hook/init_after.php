<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 帖子收藏钩子 — 初始化后确保数据表存在（使用 activate 的 SHOW TABLES，速度更快）
 * @file plugins/post_favorite/hook/init_after.php
 * @package Plugin\PostFavorite
 * @version 1.1.0
 */

if (\app\Helpers\Plugin::isActivated('post_favorite')) {
    // 性能优化：缓存文件守卫——表已存在时每请求 0 查询（首次建表后写入标记）
    $cacheFile = __DIR__ . '/../../../protected/.post_favorite_table_cache';
    if (file_exists($cacheFile)) return;
    try {
        \Plugin\PostFavorite\Plugin::activate();
        file_put_contents($cacheFile, '1', LOCK_EX);
    } catch (\Throwable $e) {
        \error_log('post_favorite init error: ' . $e->getMessage());
    }
}
