<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * SEO 钩子 — 初始化后惰性检测（默认配置在插件激活时已写入，无需每请求重复执行）
 * @file plugins/seo/hook/init_after.php
 * @package Plugin\Seo
 * @version 1.1.0
 */
// SEO 设置使用 Settings 缓存系统，激活插件时已将默认值写入 DB。
// 每请求调用 activate() 会导致 4 次冗余的 Settings::update() + buildCache()，
// 产生大量不必要的 SELECT * FROM settings 和文件写入，因此禁掉。
