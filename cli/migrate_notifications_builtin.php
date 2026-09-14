<?php
/**
 * FlintHub — 通知内置化数据迁移（一次性）
 *
 * 用途：把原「通知中心」插件独立库 plugins/notifications/data/notifications.sqlite
 *       的记录迁入核心 business.sqlite 的 notifications 表；迁移成功后把旧库重命名为 .migrated。
 *
 * 说明：
 *   - 幂等：目标表不存在则先 CREATE IF NOT EXISTS；拷贝用 INSERT OR IGNORE（按 id 防重）；
 *     可重复运行，不会产生重复数据。
 *   - 只迁统一通知库；content_review 的 review_notifications 为临时「待审」通知，
 *     审核结果均已由 approve/reject 写入统一库，历史待审记录随表废弃（不回迁）。
 *
 * 用法：php cli/migrate_notifications_builtin.php
 * @package app\cli
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../app/Core/Autoloader.php';

$root = rtrim(SPLITDB_DATA_PATH, '/\\');
$src  = dirname(__DIR__) . '/plugins/notifications/data/notifications.sqlite';
$dst  = $root . '/meta/business.sqlite';

if (!is_file($src)) {
    echo "没有发现旧通知库（{$src}），跳过迁移。\n";
    exit(0);
}

$core = new \PDO('sqlite:' . $dst);
$core->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

// 目标表幂等建表
$core->exec(
    "CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        title TEXT NOT NULL,
        link TEXT,
        actor_name TEXT NOT NULL DEFAULT '',
        summary TEXT,
        related_id INTEGER NOT NULL DEFAULT 0,
        related_type TEXT NOT NULL DEFAULT '',
        is_read INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL
    )"
);

$old = new \PDO('sqlite:' . $src, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
$rows = $old->query(
    'SELECT id, user_id, type, title, link, actor_name, summary, related_id, related_type, is_read, created_at
     FROM notifications'
);

$insert = $core->prepare(
    'INSERT OR IGNORE INTO notifications
        (id, user_id, type, title, link, actor_name, summary, related_id, related_type, is_read, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$count = 0;
$core->exec('BEGIN');
try {
    if ($rows !== false) {
        foreach ($rows as $r) {
            $insert->execute([
                (int)$r['id'], (int)$r['user_id'], (string)$r['type'], (string)$r['title'],
                $r['link'] ?? null, (string)($r['actor_name'] ?? ''), $r['summary'] ?? null,
                (int)($r['related_id'] ?? 0), (string)($r['related_type'] ?? ''), (int)($r['is_read'] ?? 0),
                (string)$r['created_at'],
            ]);
            $count++;
        }
    }
    $core->exec('COMMIT');
} catch (\Throwable $e) {
    $core->exec('ROLLBACK');
    fwrite(STDERR, '[FAIL] 迁移写入失败：' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// 先释放源库连接（否则 Windows 上 sqlite 文件被占用，rename 失败）
unset($rows, $old);
gc_collect_cycles();

// 旧库改名留档（内容备份 + 防重启再次迁移）
rename($src, $src . '.migrated');

echo "通知内置化迁移完成：共迁移 {$count} 条；旧库已重命名为 notifications.sqlite.migrated\n";
echo "目标位置：{$dst}\n";
exit(0);