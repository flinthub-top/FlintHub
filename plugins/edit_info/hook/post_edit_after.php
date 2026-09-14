<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 编辑信息钩子 — 记录帖子回复的编辑信息（惰性建表，正常编辑 0 建表查询）
 * @file plugins/edit_info/hook/post_edit_after.php
 * @package Plugin\EditInfo
 * @version 1.1.0
 *
 * 可用变量：
 *   $post_id    — 被编辑的回复 ID
 *   $thread_id  — 所属帖子 ID
 *   $user_id    — 编辑人 ID
 */

if (empty($post_id) || empty($user_id)) return;

try {
    $db = \app\Helpers\Plugin::db('edit_info');
    // 直接插入（表存在时正常执行，0 额外查询）
    $db->prepare(
        'INSERT INTO edit_history (target_type, target_id, edited_by, edited_at) VALUES (:type, :id, :uid, :now)'
    )->execute([':type' => 'post', ':id' => $post_id, ':uid' => $user_id, ':now' => date('Y-m-d H:i:s')]);
} catch (\Throwable $e) {
    // 表不存在则建表后重试（[SplitDB] SQLite DDL，独立库）
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS edit_history (
            id INTEGER PRIMARY KEY,
            target_type TEXT NOT NULL,
            target_id INTEGER NOT NULL,
            edited_by INTEGER NOT NULL,
            edited_at TEXT NOT NULL
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_edit_history_target ON edit_history (target_type, target_id)');

        $db->prepare(
            'INSERT INTO edit_history (target_type, target_id, edited_by, edited_at) VALUES (:type, :id, :uid, :now)'
        )->execute([':type' => 'post', ':id' => $post_id, ':uid' => $user_id, ':now' => date('Y-m-d H:i:s')]);
    } catch (\Throwable $e2) {
        \error_log('edit_info post_edit_after error: ' . $e2->getMessage());
    }
}
