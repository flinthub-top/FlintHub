<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 编辑信息钩子 — 初始化后惰性建表（正常请求 0 查询，激活插件时已建表）
 * @file plugins/edit_info/hook/init_after.php
 * @package Plugin\EditInfo
 * @version 1.1.0
 */
// 插件激活时已建表，无需每请求重复建表；
// 仅当表被意外删除时，通过编辑钩子中的惰性检测自动重建。
