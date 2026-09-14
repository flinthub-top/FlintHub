<?php
/**
 * 版块强制 Tag 插件语言包 — English
 * Key convention: plugin.forum_required_tag.*; frontend interactive messages use *_act suffix
 * @file plugins/forum_required_tag/lang/en.php
 */
return [
    // ===== Admin page =====
    'plugin.forum_required_tag.admin_title' => 'Forum Required Tag',
    'plugin.forum_required_tag.tab_tags' => 'Required Tags',
    'plugin.forum_required_tag.tab_prefixes' => 'Title Prefixes',
    'plugin.forum_required_tag.tab_records' => 'Usage Records',
    'plugin.forum_required_tag.tab_tags_desc' => 'Users must pick one of the preset tags when posting in this forum, otherwise submission is blocked.',
    'plugin.forum_required_tag.tab_prefixes_desc' => 'Titles must start with a preset prefix (e.g. [Help]) when posting in this forum, otherwise submission is blocked.',
    'plugin.forum_required_tag.tab_records_desc' => 'Records of tags/prefixes actually used when posting, filterable and deletable.',
    'plugin.forum_required_tag.select_forum' => 'Select forum',
    'plugin.forum_required_tag.all_forums' => 'All forums',
    'plugin.forum_required_tag.tag_placeholder' => 'Enter tag name, e.g. help',
    'plugin.forum_required_tag.prefix_placeholder' => 'Enter prefix, e.g. help (no brackets needed)',
    'plugin.forum_required_tag.add_btn' => 'Add',
    'plugin.forum_required_tag.copy_from_label' => 'From forum:',
    'plugin.forum_required_tag.copy_to_label' => 'Copy to:',
    'plugin.forum_required_tag.copy_btn' => 'Copy config',
    'plugin.forum_required_tag.filter_btn' => 'Filter',
    'plugin.forum_required_tag.th_name' => 'Tag name',
    'plugin.forum_required_tag.th_prefix' => 'Title prefix',
    'plugin.forum_required_tag.th_status' => 'Status',
    'plugin.forum_required_tag.th_actions' => 'Actions',
    'plugin.forum_required_tag.th_thread' => 'Thread',
    'plugin.forum_required_tag.th_tag' => 'Tag',
    'plugin.forum_required_tag.th_user' => 'User',
    'plugin.forum_required_tag.th_time' => 'Time',
    'plugin.forum_required_tag.on' => 'Enabled',
    'plugin.forum_required_tag.off' => 'Disabled',
    'plugin.forum_required_tag.empty_tags' => 'No required tags configured for this forum yet.',
    'plugin.forum_required_tag.empty_prefixes' => 'No title prefixes configured for this forum yet.',
    'plugin.forum_required_tag.empty_records' => 'No usage records yet.',
    'plugin.forum_required_tag.record_count' => '{total} records',
    'plugin.forum_required_tag.delete_confirm' => 'Delete this item?',
    // ===== Admin feedback =====
    'plugin.forum_required_tag.msg_add_ok' => 'Added successfully.',
    'plugin.forum_required_tag.msg_add_dup' => 'Add failed: an item with the same name already exists for this forum.',
    'plugin.forum_required_tag.msg_copy_ok' => 'Copy done: {tags} tags and {prefixes} prefixes added (duplicates skipped).',
    // ===== Frontend block messages (*_act suffix) =====
    'plugin.forum_required_tag.err_tag_act' => 'Please select a tag before posting',
    'plugin.forum_required_tag.err_tag_invalid_act' => 'Please select an option from the preset tag list',
    'plugin.forum_required_tag.err_prefix_act' => 'Title must start with a preset prefix (e.g. [Help])',
    // ===== Frontend hints (read by script.js) =====
    'plugin.forum_required_tag.hint_tags' => 'This forum requires a tag:',
    'plugin.forum_required_tag.hint_prefix' => 'Title prefix:',
    // ===== Error page =====
    'plugin.forum_required_tag.err_page_title' => 'Cannot submit',
    'plugin.forum_required_tag.back_btn' => 'Back',
];
