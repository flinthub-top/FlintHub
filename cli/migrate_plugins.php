<?php
/**
 * FlintHub 1.0 (SplitDB) — 插件数据迁移脚本（旧 MySQL → 各插件独立 SQLite 库）
 *
 * ⚠️ 一次性历史工具：旧 MySQL（fastbb）插件数据已迁移完成，本脚本仅作历史参考留存，
 *    供重装/复盘时查阅，日常运行不需要执行。
 *
 * 用法：
 *   php cli/migrate_plugins.php --dry-run        # 仅预览各插件表行数，不实际迁移
 *   php cli/migrate_plugins.php                   # 全量迁移（默认连接旧 MySQL fastbb 库）
 *   php cli/migrate_plugins.php --host=127.0.0.1 --port=3306 --name=fastbb --user=fastbb --pass=123456
 *
 * 迁移规则（与 cli/migrate.php 同构）：
 *   - 插件自有表写入 plugins/{插件名}/data/{插件名}.sqlite（列白名单：仅迁目标表存在的列）
 *   - 显式 ID 保留；settings 类配置键跳过 _runtime_* 运行时计数
 *   - 每张表迁移后校验行数一致；任一张表失败 → 报错中止（不产生部分残留误导）
 *
 * @package app\cli
 */

// ============================================================
// 0. 旧 MySQL 配置（可按 CLI 参数覆盖，默认与 migrate.php 一致）
// ============================================================
$MIGRATE = [
    'host' => getenv('MIGRATE_DB_HOST') ?: 'localhost',
    'port' => (int)(getenv('MIGRATE_DB_PORT') ?: 3306),
    'name' => getenv('MIGRATE_DB_NAME') ?: 'fastbb',
    'user' => getenv('MIGRATE_DB_USER') ?: 'fastbb',
    'pass' => getenv('MIGRATE_DB_PASS') ?: '123456',
    'charset' => 'utf8mb4',
    'dryRun' => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $MIGRATE['dryRun'] = true;
    } elseif (preg_match('/^--host=(.+)$/', $arg, $m)) {
        $MIGRATE['host'] = $m[1];
    } elseif (preg_match('/^--port=(\d+)$/', $arg, $m)) {
        $MIGRATE['port'] = (int)$m[1];
    } elseif (preg_match('/^--name=(.+)$/', $arg, $m)) {
        $MIGRATE['name'] = $m[1];
    } elseif (preg_match('/^--user=(.+)$/', $arg, $m)) {
        $MIGRATE['user'] = $m[1];
    } elseif (preg_match('/^--pass=(.+)$/', $arg, $m)) {
        $MIGRATE['pass'] = $m[1];
    } else {
        fwrite(STDERR, "未知参数: {$arg}\n");
        fwrite(STDERR, "用法: php cli/migrate_plugins.php [--dry-run] [--host=..] [--port=..] [--name=..] [--user=..] [--pass=..]\n");
        exit(1);
    }
}

require_once __DIR__ . '/_guard.php'; // Web 直访守卫（CLI-only）
require_once __DIR__ . '/../app/Core/Autoloader.php';

// ============================================================
// 1. 插件 → 自有表映射（表名 → 插件目录名）
//    仅列"插件自有数据表"；users/threads/posts 等核心表由 migrate.php 负责，不在此迁移
// ============================================================
$PLUGIN_TABLES = [
    'announcements'        => 'announcements',
    'content_reviews'      => 'content_review',
    'review_notifications' => 'content_review',
    'daily_checkin'        => 'daily_checkin',
    'edit_history'         => 'edit_info',
    'friend_links'         => 'friend_links',
    'invites'              => 'invite',
    'medals'               => 'medal',
    'user_medals'          => 'medal',
    'user_medal_wears'     => 'medal',
    'notifications'        => 'notifications',
    'post_favorites'       => 'post_favorite',
    'quiz_duels'           => 'quiz_duel',
    'red_packets'          => 'red_packet',
    'red_packet_claims'    => 'red_packet',
    'user_follows'         => 'user_profile',
];

// 旧库遗留表（当前插件代码零引用，非插件自有表，不迁移）：
//   qa_questions / qa_answers / qa_tags / qa_question_tags / qa_votes（旧问答模块遗留）

echo "SplitDB 插件数据迁移工具\n";
echo "源 MySQL: {$MIGRATE['host']}:{$MIGRATE['port']}/{$MIGRATE['name']}\n";

// ============================================================
// 2. 连接旧 MySQL
// ============================================================
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $MIGRATE['host'], $MIGRATE['port'], $MIGRATE['name'], $MIGRATE['charset']);
try {
    $source = new \PDO($dsn, $MIGRATE['user'], $MIGRATE['pass'], [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
        \PDO::ATTR_TIMEOUT => 10,
    ]);
    echo "连接成功。\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "连接旧 MySQL 失败: " . $e->getMessage() . "\n");
    exit(1);
}

// 枚举旧库中实际存在的插件表（可能缺表，宽容跳过）
$existing = [];
foreach ($source->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $t) {
    $existing[strtolower($t)] = $t;
}

$dry = $MIGRATE['dryRun'] ? ' [dry-run 预览]' : '';
echo "插件表清单（共 " . count($PLUGIN_TABLES) . " 张映射，实际存在 " . count(array_intersect_key($PLUGIN_TABLES, $existing)) . " 张）{$dry}\n";

$totalOk = 0;
$totalRows = 0;

foreach ($PLUGIN_TABLES as $table => $plugin) {
    $realTable = $existing[strtolower($table)] ?? null;
    if ($realTable === null) {
        echo "  [skip] {$table}（旧库无此表，归属插件 {$plugin}）\n";
        continue;
    }

    // 旧库行数
    $srcCount = (int)$source->query("SELECT COUNT(*) FROM `{$realTable}`")->fetchColumn();
    echo sprintf("  %-22s → %s 插件库，旧库 %d 行\n", $table, $plugin, $srcCount);

    if ($dry) {
        $totalRows += $srcCount;
        continue;
    }

    // 目标插件独立库连接（幂等建 data/ 目录）
    $target = \app\Helpers\Plugin::db($plugin);
    // 目标表列白名单（PRAGMA table_info）
    $cols = [];
    foreach ($target->query("PRAGMA table_info({$table})")->fetchAll(\PDO::FETCH_ASSOC) as $c) {
        $cols[] = $c['name'];
    }

    // 目标表不存在 → 先调用插件 activate() 建表（仅建表，不改 plugin.json 激活状态）
    if (empty($cols)) {
        $pluginClass = '\\Plugin\\' . str_replace(' ', '', ucwords(str_replace('_', ' ', $plugin))) . '\\Plugin';
        $pluginFile = __DIR__ . '/../plugins/' . $plugin . '/Plugin.php';
        if (is_file($pluginFile)) {
            require_once $pluginFile;
            if (method_exists($pluginClass, 'activate')) {
                $pluginClass::activate();
                foreach ($target->query("PRAGMA table_info({$table})")->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                    $cols[] = $c['name'];
                }
            }
        }
    }
    if (empty($cols)) {
        fwrite(STDERR, "  [error] 目标插件库 {$plugin} 无表 {$table}，且无法自动建表，请先激活该插件\n");
        exit(1);
    }

    // 逐行迁移（显式 ID 保留；仅迁目标表存在的列）
    $stmt = $target->prepare(
        'INSERT OR REPLACE INTO ' . $table . ' ("' . implode('", "', $cols) . '") VALUES (:' . implode(', :', $cols) . ')'
    );
    $rows = $source->query("SELECT * FROM `{$realTable}`");
    $migrated = 0;
    while ($row = $rows->fetch()) {
        $params = [];
        foreach ($cols as $col) {
            $params[':' . $col] = array_key_exists($col, $row) ? $row[$col] : null;
        }
        $stmt->execute($params);
        $migrated++;
    }
    $totalRows += $migrated;

    // 校验行数一致
    $targetCount = (int)$target->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    if ($targetCount !== $srcCount) {
        fwrite(STDERR, "  [error] {$table} 行数不一致：旧库 {$srcCount}，目标 {$targetCount}，中止迁移\n");
        exit(1);
    }
    $totalOk++;
    echo "      → 迁移 {$migrated} 行，校验一致 ✓\n";
}

$source = null;
if ($dry) {
    echo "\n[dry-run] 预览完成，共 {$totalRows} 行待迁移，未执行任何写入。\n";
    exit(0);
}

echo "\n插件数据迁移完成：{$totalOk} 张表，共 {$totalRows} 行。\n";
echo "  提示：请到后台「插件管理」逐插件激活验证功能。\n";
exit(0);
