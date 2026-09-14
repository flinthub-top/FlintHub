<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 本地草稿保护插件主类
 * 纯前端实现：草稿只存浏览器 localStorage，无后端表、无独立库。
 * @file plugins/draft_saver/Plugin.php
 * @package Plugin\DraftSaver
 * @version 1.0.0
 */

namespace Plugin\DraftSaver;

class Plugin
{
    /**
     * 激活插件：纯前端方案，无需建表；实际生效靠 hook/layout_head_end.php 注入资源
     */
    public static function activate(): bool
    {
        return true;
    }

    public static function deactivate(): bool
    {
        return true;
    }

    /**
     * 卸载：浏览器 localStorage 中的草稿属用户侧数据，插件卸载不代为删除
     * （如需清空，用户可在浏览器开发者工具中删除 flinthub_draft_* 键）
     */
    public static function uninstall(): void
    {
        // 无后端数据可清理
    }
}
