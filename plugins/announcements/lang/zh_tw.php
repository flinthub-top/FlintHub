<?php
/**
 * 站點公告外掛語言包 — 繁體中文
 * 外掛語言包放入 plugins/{外掛名}/lang/{語言}.php 即自動生效（核心 I18n 自動合併，無需鉤子）
 * @file plugins/announcements/lang/zh_tw.php
 */
return [
    'plugin.announcements.title'       => '站點公告管理',
    'plugin.announcements.nav'         => '站點公告',
    'plugin.announcements.add'         => '新增公告',
    'plugin.announcements.add_btn'     => '新增公告',
    'plugin.announcements.content'     => '公告內容',
    'plugin.announcements.content_ph'  => '輸入公告內容',
    'plugin.announcements.url'         => '連結（選填）',
    'plugin.announcements.url_ph'      => '點擊公告跳轉到的連結，如 https://example.com',
    'plugin.announcements.style'       => '顏色樣式',
    'plugin.announcements.sort'        => '排序',
    'plugin.announcements.display_mode'=> '顯示模式',
    'plugin.announcements.mode_hint'   => '滾動模式下，多條公告會循環滾動顯示',
    'plugin.announcements.mode_normal' => '普通堆疊',
    'plugin.announcements.mode_scroll' => '上下滾動',
    'plugin.announcements.save'        => '儲存',
    'plugin.announcements.existing'    => '已有公告（共 {count} 條）',
    'plugin.announcements.empty'       => '暫無公告',
    'plugin.announcements.edit'        => '編輯',
    'plugin.announcements.edit_title'  => '編輯公告',
    'plugin.announcements.delete'      => '刪除',
    'plugin.announcements.delete_confirm' => '確定刪除？',
    'plugin.announcements.cancel'      => '取消',
    'plugin.announcements.close'       => '關閉',

    // 控制器訊息
    'plugin.announcements.msg_scroll'   => '已切換為滾動模式',
    'plugin.announcements.msg_normal'   => '已切換為普通模式',
    'plugin.announcements.msg_empty'    => '公告內容不能為空',
    'plugin.announcements.msg_added'    => '公告已新增',
    'plugin.announcements.msg_param'    => '參數錯誤',
    'plugin.announcements.msg_updated'  => '公告已更新',
    'plugin.announcements.msg_deleted'  => '公告已刪除',
];
