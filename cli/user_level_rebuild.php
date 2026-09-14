<?php
/**
 * FlintHub — 信任等级插件：流水全量重建 / 历史回灌
 *
 * 用途：
 *   插件启用的那一刻，`ul_logs` 是空的——存量用户的帖子、回复、点赞都不在流水里，
 *   等级会被算成 TL0。本脚本从**核心权威表**反推历史流水并回灌，然后重算等级缓存。
 *
 * 回灌口径（与实时钩子完全对齐，见 hook/route_after_dispatch.php、hook/vote_after.php）：
 *   | 指标       | 核心来源                              | 说明                          |
 *   |-----------|--------------------------------------|-------------------------------|
 *   | topic     | threads(user_id, created_at)          | 每帖一条，ref_id = thread_id   |
 *   | reply     | posts(user_id, created_at)            | 每回复一条，ref_id = post_id   |
 *   | like_topic| thread_votes(thread→author)           | vote=1 且非自赞                |
 *   | like_reply| post_votes(post→author)               | vote=1 且非自赞                |
 *   | recv_like | 上述两者的被赞方                       | 镜像一条                       |
 *   | read      | viewed_replies(user_id, thread_id)     | 浏览过的主题                   |
 *
 *   **不回灌 visit**：访问流水没有历史来源（核心不留浏览轨迹），只能从脚本运行日起累积。
 *   这是有意的——伪造历史访问天数会让等级虚高且无法审计。
 *
 * 用法：
 *   php cli/user_level_rebuild.php --dry-run     # 只统计与演练，不写库（默认建议先跑）
 *   php cli/user_level_rebuild.php               # 执行回灌（幂等，可重复运行）
 *   php cli/user_level_rebuild.php --reset       # 先清空 ul_logs 再全量重建（危险，需 --force）
 *   php cli/user_level_rebuild.php --reset --force
 *   php cli/user_level_rebuild.php --no-levels   # 只回灌流水，不重算 ul_users 等级缓存
 *   php cli/user_level_rebuild.php --batch=2000  # 单批写入行数（默认 1000）
 *
 * 幂等性：
 *   ul_logs 主键为 (user_id, type, ref_id, src_uid)，回灌统一用 `INSERT OR IGNORE`，
 *   重复运行不会产生重复行，也不会覆盖实时钩子已写入的行。
 *
 * @file cli/user_level_rebuild.php
 * @package app\cli
 */

// CLI 下 $_SERVER 没有 Web 变量，config.php 读 SERVER_PORT/HTTPS 会触发 Warning，
// 且它内部的 ini_set(session.*) 在 CLI 恒失败。这些与回灌逻辑无关，提前屏蔽。
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
$_SERVER['SERVER_PORT']  = $_SERVER['SERVER_PORT']  ?? '80';
$_SERVER['HTTP_HOST']    = $_SERVER['HTTP_HOST']    ?? 'localhost';
$_SERVER['REQUEST_URI']  = $_SERVER['REQUEST_URI']  ?? '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../app/Core/Autoloader.php';

use app\Core\Database;
use Plugin\user_level\Plugin;

// ---- 参数解析 ----
$opt = ['dry' => false, 'reset' => false, 'force' => false, 'levels' => true, 'batch' => 1000];
foreach ($argv as $a) {
    if ($a === '--dry-run' || $a === '-n') $opt['dry'] = true;
    elseif ($a === '--reset')             $opt['reset'] = true;
    elseif ($a === '--force')             $opt['force'] = true;
    elseif ($a === '--no-levels')         $opt['levels'] = false;
    elseif (preg_match('/^--batch=(\d+)$/', $a, $m)) $opt['batch'] = max(100, min(20000, (int)$m[1]));
}

echo "==================================================\n";
echo " 信任等级插件 — 流水全量重建 / 历史回灌\n";
echo "==================================================\n";
echo ' 模式      : ' . ($opt['dry'] ? "演练（--dry-run，不写库）" : "实写") . "\n";
echo ' 清空重建  : ' . ($opt['reset'] ? '是（先 DELETE ul_logs）' : '否（叠加回灌）') . "\n";
echo ' 重算等级  : ' . ($opt['levels'] ? '是' : '否') . "\n";
if ($opt['reset'] && !$opt['dry'] && !$opt['force']) {
    echo "\n[中止] --reset 会清空 ul_logs。请同时加 --force 确认这是你的本意。\n";
    exit(1);
}
echo "\n";

$core = Database::getInstance();
$ul = Plugin::db();

// ---- 确保插件表存在（首次部署时脚本可能先于前台激活运行）----
Plugin::ensureTables();

// ========================================================================
//  0. 清空（可选）
// ========================================================================
if ($opt['reset'] && !$opt['dry']) {
    $before = (int)$ul->query('SELECT COUNT(*) FROM ul_logs')->fetchColumn();
    $ul->exec('DELETE FROM ul_logs');
    echo "[0] 已清空 ul_logs（原有 {$before} 行）\n\n";
}

// ========================================================================
//  1. 统计核心侧待回灌数据量
// ========================================================================
echo "[1] 盘点核心权威表\n";
// 统计口径必须与下方回灌口径完全一致（含 JOIN / deleted_at / 自赞排除），
// 否则「盘点 3 行、回灌 2 条」会让人以为脚本漏跑了。
$counts = [];
$countSql = [
    'topic'      => 'SELECT COUNT(*) AS c FROM threads WHERE deleted_at IS NULL',
    'reply'      => 'SELECT COUNT(*) AS c FROM posts WHERE deleted_at IS NULL',
    // 自赞（actor = 作者）在回灌时被排除，盘点同样排除
    'like_topic' => 'SELECT COUNT(*) AS c FROM thread_votes v JOIN threads t ON t.id = v.thread_id
                     WHERE v.vote = 1 AND t.deleted_at IS NULL AND v.user_id <> t.user_id',
    'like_reply' => 'SELECT COUNT(*) AS c FROM post_votes v JOIN posts p ON p.id = v.post_id
                     WHERE v.vote = 1 AND p.deleted_at IS NULL AND v.user_id <> p.user_id',
    'read'       => 'SELECT COUNT(*) AS c FROM viewed_replies',
];
foreach ($countSql as $k => $sql) {
    try {
        $row = $core->fetchOne($sql);
        $counts[$k] = (int)($row['c'] ?? 0);
    } catch (\Throwable $e) {
        $counts[$k] = -1;
        echo "    !! {$k} 源不可用: {$e->getMessage()}\n";
    }
}
foreach ($counts as $k => $v) {
    printf("    %-11s %s\n", $k, $v < 0 ? '（跳过：源表缺失）' : number_format($v) . ' 行');
}

// ========================================================================
//  2. 逐指标回灌
// ========================================================================
/** 批量写入累加器：攒够一批再落库，减少 SQLite 事务开销 */
class RebuildWriter
{
    private \PDO $db;
    private bool $dry;
    private int $batch;
    public int $written = 0;
    public int $pending = 0;
    private array $buf = [];
    private \PDOStatement $stmt;

    public function __construct(\PDO $db, bool $dry, int $batch)
    {
        $this->db = $db;
        $this->dry = $dry;
        $this->batch = $batch;
        $this->stmt = $db->prepare(
            'INSERT OR IGNORE INTO ul_logs (user_id, type, ref_id, src_uid, day, created_at)
             VALUES (:uid, :t, :ref, :src, :day, :now)'
        );
    }

    public function add(int $uid, string $type, int $refId, int $srcUid, string $day, int $ts): void
    {
        if ($uid <= 0 || $refId <= 0) return;
        $this->buf[] = [$uid, $type, $refId, $srcUid, $day, $ts];
        $this->pending++;
        if (count($this->buf) >= $this->batch) $this->flush();
    }

    public function flush(): void
    {
        if (empty($this->buf) || $this->dry) { $this->buf = []; return; }
        $this->db->beginTransaction();
        try {
            foreach ($this->buf as $r) {
                $this->stmt->execute([
                    ':uid' => $r[0], ':t' => $r[1], ':ref' => $r[2],
                    ':src' => $r[3], ':day' => $r[4], ':now' => $r[5],
                ]);
                $this->written += $this->stmt->rowCount();
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->buf = [];
    }
}

$w = new RebuildWriter($ul, $opt['dry'], $opt['batch']);

/** created_at 兼容 INTEGER 与 TEXT 两种存法，统一成 Y-m-d */
function ulDayFrom($raw): string
{
    if ($raw === null || $raw === '') return date('Y-m-d');
    if (is_numeric($raw)) {
        $ts = (int)$raw;
        if ($ts > 9999999999) $ts = (int)($ts / 1000); // 毫秒
        return $ts > 0 ? date('Y-m-d', $ts) : date('Y-m-d');
    }
    $ts = strtotime((string)$raw);
    return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
}

function ulTsFrom($raw): int
{
    if ($raw === null || $raw === '') return time();
    if (is_numeric($raw)) {
        $ts = (int)$raw;
        if ($ts > 9999999999) $ts = (int)($ts / 1000);
        return $ts > 0 ? $ts : time();
    }
    return strtotime((string)$raw) ?: time();
}

echo "\n[2] 回灌流水\n";

// ---- 2.1 topic：帖子的作者 + 时间 ----
if ($counts['topic'] > 0) {
    $n = 0;
    foreach ($core->fetchAll('SELECT id, user_id, created_at FROM threads WHERE deleted_at IS NULL') as $r) {
        $w->add((int)$r['user_id'], 'topic', (int)$r['id'], 0, ulDayFrom($r['created_at']), ulTsFrom($r['created_at']));
        $n++;
    }
    echo "    topic      {$n} 条\n";
} else {
    echo "    topic      0 条（跳过）\n";
}

// ---- 2.2 reply：回复的作者 + 时间 ----
if ($counts['reply'] > 0) {
    $n = 0;
    foreach ($core->fetchAll('SELECT id, user_id, created_at FROM posts WHERE deleted_at IS NULL') as $r) {
        $w->add((int)$r['user_id'], 'reply', (int)$r['id'], 0, ulDayFrom($r['created_at']), ulTsFrom($r['created_at']));
        $n++;
    }
    echo "    reply      {$n} 条\n";
} else {
    echo "    reply      0 条（跳过）\n";
}

// ---- 2.3 like_topic + recv_like：主题点赞（需 join 出作者，排除自赞）----
if ($counts['like_topic'] > 0) {
    $n = 0; $skip = 0;
    $sql = 'SELECT v.thread_id AS ref_id, v.user_id AS actor, t.user_id AS owner, v.created_at AS ct
            FROM thread_votes v JOIN threads t ON t.id = v.thread_id
            WHERE v.vote = 1 AND t.deleted_at IS NULL';
    foreach ($core->fetchAll($sql) as $r) {
        $actor = (int)$r['actor']; $owner = (int)$r['owner'];
        if ($actor === $owner) { $skip++; continue; } // 自赞不计（与 vote_after 一致）
        $day = ulDayFrom($r['ct']); $ts = ulTsFrom($r['ct']);
        $w->add($actor, 'like_topic', (int)$r['ref_id'], $owner, $day, $ts);
        if ($owner > 0) $w->add($owner, 'recv_like', (int)$r['ref_id'], $actor, $day, $ts);
        $n++;
    }
    echo "    like_topic {$n} 条（跳过自赞 {$skip} 条）\n";
} else {
    echo "    like_topic 0 条（跳过）\n";
}

// ---- 2.4 like_reply + recv_like：回复点赞 ----
if ($counts['like_reply'] > 0) {
    $n = 0; $skip = 0;
    $sql = 'SELECT v.post_id AS ref_id, v.user_id AS actor, p.user_id AS owner, v.created_at AS ct
            FROM post_votes v JOIN posts p ON p.id = v.post_id
            WHERE v.vote = 1 AND p.deleted_at IS NULL';
    foreach ($core->fetchAll($sql) as $r) {
        $actor = (int)$r['actor']; $owner = (int)$r['owner'];
        if ($actor === $owner) { $skip++; continue; }
        $day = ulDayFrom($r['ct']); $ts = ulTsFrom($r['ct']);
        $w->add($actor, 'like_reply', (int)$r['ref_id'], $owner, $day, $ts);
        if ($owner > 0) $w->add($owner, 'recv_like', (int)$r['ref_id'], $actor, $day, $ts);
        $n++;
    }
    echo "    like_reply {$n} 条（跳过自赞 {$skip} 条）\n";
} else {
    echo "    like_reply 0 条（跳过）\n";
}

// ---- 2.5 read：浏览过的主题 ----
if ($counts['read'] > 0) {
    $n = 0;
    foreach ($core->fetchAll('SELECT thread_id, user_id, created_at FROM viewed_replies') as $r) {
        $w->add((int)$r['user_id'], 'read', (int)$r['thread_id'], 0, ulDayFrom($r['created_at']), ulTsFrom($r['created_at']));
        $n++;
    }
    echo "    read       {$n} 条\n";
} else {
    echo "    read       0 条（跳过）\n";
}

$w->flush();
echo "\n    待写入 {$w->pending} 行，实际新增 {$w->written} 行"
    . ($opt['dry'] ? "（演练模式，未落库）" : "（已忽略主键重复的既有行）") . "\n";

// ========================================================================
//  3. 重算等级缓存（ul_users）
// ========================================================================
if ($opt['levels'] && !$opt['dry']) {
    echo "\n[3] 重算用户等级缓存\n";
    $uids = $ul->query('SELECT DISTINCT user_id FROM ul_logs WHERE user_id > 0')->fetchAll(\PDO::FETCH_COLUMN);
    $total = count($uids);
    echo "    涉及用户 {$total} 位\n";
    $done = 0; $lvDist = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0];
    foreach ($uids as $uid) {
        try {
            $lv = Plugin::syncAndCache((int)$uid);
            $lvDist[max(0, min(4, $lv))]++;
        } catch (\Throwable $e) {
            echo "    !! uid={$uid} 重算失败: {$e->getMessage()}\n";
        }
        $done++;
        if ($done % 200 === 0) {
            echo "    进度 {$done}/{$total}\n";
        }
    }
    echo "    完成 {$done} 位\n";
    echo "    等级分布：TL0={$lvDist[0]} TL1={$lvDist[1]} TL2={$lvDist[2]} TL3={$lvDist[3]} TL4={$lvDist[4]}\n";
} elseif ($opt['levels'] && $opt['dry']) {
    echo "\n[3] 演练模式：跳过等级重算\n";
} else {
    echo "\n[3] 已指定 --no-levels：跳过等级重算\n";
}

// ========================================================================
//  4. 结果自检
// ========================================================================
echo "\n[4] 流水分布自检\n";
try {
    $rows = $ul->query('SELECT type, COUNT(*) AS c FROM ul_logs GROUP BY type ORDER BY type')->fetchAll(\PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "    （ul_logs 为空）\n";
    }
    foreach ($rows as $r) {
        printf("    %-11s %s\n", $r['type'], number_format((int)$r['c']));
    }
    $totalRows = (int)$ul->query('SELECT COUNT(*) FROM ul_logs')->fetchColumn();
    echo "    ------------------------\n";
    echo '    合计        ' . number_format($totalRows) . "\n";
} catch (\Throwable $e) {
    echo "    自检失败: {$e->getMessage()}\n";
}

echo "\n完成。" . ($opt['dry'] ? "（演练模式，未改动任何数据）" : '') . "\n";
echo "提示：等级缓存显示在 /u/level；若前台仍显示旧数据，清 data/runtime/pages/ 静态缓存。\n";
exit(0);
