<?php
/**
 * 版块强制 Tag 插件語言包 — 繁體中文
 * 鍵名約定：plugin.forum_required_tag.*；前台互動提示用 *_act 後綴，後台表單校驗無後綴
 * @file plugins/forum_required_tag/lang/zh_tw.php
 */
return [
    // ===== 後台管理頁 =====
    'plugin.forum_required_tag.admin_title' => '版塊強制 Tag',
    'plugin.forum_required_tag.tab_tags' => '強制 Tag 管理',
    'plugin.forum_required_tag.tab_prefixes' => '標題前綴管理',
    'plugin.forum_required_tag.tab_records' => '使用記錄',
    'plugin.forum_required_tag.tab_tags_desc' => '用戶在該版塊發文時，必須從預設清單中選擇一個 Tag，否則無法提交。',
    'plugin.forum_required_tag.tab_prefixes_desc' => '用戶在該版塊發文時，標題必須以預設前綴開頭（如 [求助]），否則無法提交。',
    'plugin.forum_required_tag.tab_records_desc' => '展示各版塊發文時實際使用的強制 Tag / 標題前綴記錄，可篩選與刪除。',
    'plugin.forum_required_tag.select_forum' => '選擇版塊',
    'plugin.forum_required_tag.all_forums' => '全部版塊',
    'plugin.forum_required_tag.tag_placeholder' => '輸入 Tag 名稱，如 求助',
    'plugin.forum_required_tag.prefix_placeholder' => '輸入前綴名，如 求助（無需方括號）',
    'plugin.forum_required_tag.add_btn' => '新增',
    'plugin.forum_required_tag.copy_from_label' => '從版塊：',
    'plugin.forum_required_tag.copy_to_label' => '複製到：',
    'plugin.forum_required_tag.copy_btn' => '複製設定',
    'plugin.forum_required_tag.filter_btn' => '篩選',
    'plugin.forum_required_tag.th_name' => 'Tag 名稱',
    'plugin.forum_required_tag.th_prefix' => '標題前綴',
    'plugin.forum_required_tag.th_status' => '狀態',
    'plugin.forum_required_tag.th_actions' => '操作',
    'plugin.forum_required_tag.th_thread' => '帖子',
    'plugin.forum_required_tag.th_tag' => 'Tag',
    'plugin.forum_required_tag.th_user' => '發文人',
    'plugin.forum_required_tag.th_time' => '時間',
    'plugin.forum_required_tag.on' => '啟用',
    'plugin.forum_required_tag.off' => '停用',
    'plugin.forum_required_tag.empty_tags' => '該版塊尚未設定強制 Tag，設定後發文將強制選擇。',
    'plugin.forum_required_tag.empty_prefixes' => '該版塊尚未設定標題前綴，設定後發文標題將強制帶前綴。',
    'plugin.forum_required_tag.empty_records' => '暫無使用記錄。',
    'plugin.forum_required_tag.record_count' => '共 {total} 條記錄',
    'plugin.forum_required_tag.delete_confirm' => '確定刪除該項嗎？',
    // ===== 後台操作回饋 =====
    'plugin.forum_required_tag.msg_add_ok' => '新增成功。',
    'plugin.forum_required_tag.msg_add_dup' => '新增失敗：該版塊已存在同名設定。',
    'plugin.forum_required_tag.msg_copy_ok' => '複製完成：新增 Tag {tags} 個、前綴 {prefixes} 個（重複項已跳過）。',
    // ===== 前台攔截提示（*_act 後綴）=====
    'plugin.forum_required_tag.err_tag_act' => '請選擇一個 Tag 後再發布',
    'plugin.forum_required_tag.err_tag_invalid_act' => '請選擇預設的 Tag 清單中的選項',
    'plugin.forum_required_tag.err_prefix_act' => '標題需以預設前綴開頭（如 [求助]）',
    // ===== 前台提示條（供 script.js 讀取）=====
    'plugin.forum_required_tag.hint_tags' => '本版塊需選擇 Tag：',
    'plugin.forum_required_tag.hint_prefix' => '標題前綴：',
    // ===== 錯誤頁 =====
    'plugin.forum_required_tag.err_page_title' => '無法提交',
    'plugin.forum_required_tag.back_btn' => '返回',
];
