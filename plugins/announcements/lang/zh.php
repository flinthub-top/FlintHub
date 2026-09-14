<?php
/**
 * 站点公告插件语言包 — 简体中文
 * 插件语言包放入 plugins/{插件名}/lang/{语言}.php 即自动生效（核心 I18n 自动合并，无需钩子）
 * @file plugins/announcements/lang/zh.php
 */
return [
    'plugin.announcements.title'       => '站点公告管理',
    'plugin.announcements.nav'         => '站点公告',
    'plugin.announcements.add'         => '添加公告',
    'plugin.announcements.add_btn'     => '添加公告',
    'plugin.announcements.content'     => '公告内容',
    'plugin.announcements.content_ph'  => '输入公告内容',
    'plugin.announcements.url'         => '链接（可选）',
    'plugin.announcements.url_ph'      => '点击公告跳转到的链接，如 https://example.com',
    'plugin.announcements.style'       => '颜色样式',
    'plugin.announcements.sort'        => '排序',
    'plugin.announcements.display_mode'=> '显示模式',
    'plugin.announcements.mode_hint'   => '滚动模式下，多条公告会循环滚动显示',
    'plugin.announcements.mode_normal' => '普通堆叠',
    'plugin.announcements.mode_scroll' => '上下滚动',
    'plugin.announcements.save'        => '保存',
    'plugin.announcements.existing'    => '已有公告（共 {count} 条）',
    'plugin.announcements.empty'       => '暂无公告',
    'plugin.announcements.edit'        => '编辑',
    'plugin.announcements.edit_title'  => '编辑公告',
    'plugin.announcements.delete'      => '删除',
    'plugin.announcements.delete_confirm' => '确定删除？',
    'plugin.announcements.cancel'      => '取消',
    'plugin.announcements.close'       => '关闭',

    // 控制器消息
    'plugin.announcements.msg_scroll'   => '已切换为滚动模式',
    'plugin.announcements.msg_normal'   => '已切换为普通模式',
    'plugin.announcements.msg_empty'    => '公告内容不能为空',
    'plugin.announcements.msg_added'    => '公告已添加',
    'plugin.announcements.msg_param'    => '参数错误',
    'plugin.announcements.msg_updated'  => '公告已更新',
    'plugin.announcements.msg_deleted'  => '公告已删除',
];
