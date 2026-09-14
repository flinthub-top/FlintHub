<?php
/**
 * 版块强制 Tag 插件语言包 — 简体中文
 * 键名约定：plugin.forum_required_tag.*；前台交互提示用 *_act 后缀，后台表单校验无后缀
 * @file plugins/forum_required_tag/lang/zh.php
 */
return [
    // ===== 后台管理页 =====
    'plugin.forum_required_tag.admin_title' => '版块强制 Tag',
    'plugin.forum_required_tag.tab_tags' => '强制 Tag 管理',
    'plugin.forum_required_tag.tab_prefixes' => '标题前缀管理',
    'plugin.forum_required_tag.tab_records' => '使用记录',
    'plugin.forum_required_tag.tab_tags_desc' => '用户在该版块发帖时，必须从预设列表中选择一个 Tag，否则无法提交。',
    'plugin.forum_required_tag.tab_prefixes_desc' => '用户在该版块发帖时，标题必须以预设前缀开头（如 [求助]），否则无法提交。',
    'plugin.forum_required_tag.tab_records_desc' => '展示各版块发帖时实际使用的强制 Tag / 标题前缀记录，可筛选与删除。',
    'plugin.forum_required_tag.select_forum' => '选择版块',
    'plugin.forum_required_tag.all_forums' => '全部版块',
    'plugin.forum_required_tag.tag_placeholder' => '输入 Tag 名称，如 求助',
    'plugin.forum_required_tag.prefix_placeholder' => '输入前缀名，如 求助（无需方括号）',
    'plugin.forum_required_tag.add_btn' => '添加',
    'plugin.forum_required_tag.copy_from_label' => '从版块：',
    'plugin.forum_required_tag.copy_to_label' => '复制到：',
    'plugin.forum_required_tag.copy_btn' => '复制配置',
    'plugin.forum_required_tag.filter_btn' => '筛选',
    'plugin.forum_required_tag.th_name' => 'Tag 名称',
    'plugin.forum_required_tag.th_prefix' => '标题前缀',
    'plugin.forum_required_tag.th_status' => '状态',
    'plugin.forum_required_tag.th_actions' => '操作',
    'plugin.forum_required_tag.th_thread' => '帖子',
    'plugin.forum_required_tag.th_tag' => 'Tag',
    'plugin.forum_required_tag.th_user' => '发帖人',
    'plugin.forum_required_tag.th_time' => '时间',
    'plugin.forum_required_tag.on' => '启用',
    'plugin.forum_required_tag.off' => '禁用',
    'plugin.forum_required_tag.empty_tags' => '该版块尚未配置强制 Tag，配置后发帖将强制选择。',
    'plugin.forum_required_tag.empty_prefixes' => '该版块尚未配置标题前缀，配置后发帖标题将强制带前缀。',
    'plugin.forum_required_tag.empty_records' => '暂无使用记录。',
    'plugin.forum_required_tag.record_count' => '共 {total} 条记录',
    'plugin.forum_required_tag.delete_confirm' => '确定删除该项吗？',
    // ===== 后台操作反馈 =====
    'plugin.forum_required_tag.msg_add_ok' => '添加成功。',
    'plugin.forum_required_tag.msg_add_dup' => '添加失败：该版块已存在同名配置。',
    'plugin.forum_required_tag.msg_copy_ok' => '复制完成：新增 Tag {tags} 个、前缀 {prefixes} 个（重复项已跳过）。',
    // ===== 前台拦截提示（*_act 后缀）=====
    'plugin.forum_required_tag.err_tag_act' => '请选择一个 Tag 后再发布',
    'plugin.forum_required_tag.err_tag_invalid_act' => '请选择预设的 Tag 列表中的选项',
    'plugin.forum_required_tag.err_prefix_act' => '标题需以预设前缀开头（如 [求助]）',
    // ===== 前台提示条（供 script.js 读取）=====
    'plugin.forum_required_tag.hint_tags' => '本版块需选择 Tag：',
    'plugin.forum_required_tag.hint_prefix' => '标题前缀：',
    // ===== 错误页 =====
    'plugin.forum_required_tag.err_page_title' => '无法提交',
    'plugin.forum_required_tag.back_btn' => '返回',
];
