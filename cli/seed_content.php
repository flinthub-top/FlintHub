<?php
/**
 * FlintHub 1.0 — 测试内容生成器（生成数据作为本站正式内容保留）
 *
 * 基于 SplitDB 批量导入路径（与 cli/migrate.php 同构）：
 *   extern 原子写 → 桶 topic/reply 真相源 → main_index 索引行 → 推进 global_id 游标
 * 内容确定性生成：mt_srand(种子 + id)，断点续跑内容一致。
 *
 * 用法（严格串行，每阶段可分批断点续跑）：
 *   php cli/seed_content.php --phase users    --limit 10000
 *   php cli/seed_content.php --phase threads  --limit 20000
 *   php cli/seed_content.php --phase replies  --limit 20000
 *   php cli/seed_content.php --phase blogs    --limit 8000
 *   php cli/seed_content.php --phase finalize
 *   php cli/seed_content.php --phase report
 *
 * 可选 --seed N 覆盖随机种子（默认 20260814）
 *
 * @package app\cli
 */

require_once __DIR__ . '/_guard.php'; // Web 直访守卫（CLI-only）
require_once __DIR__ . '/../app/Core/Autoloader.php';

use app\SplitDB\ExternStorage;
use app\SplitDB\Schema;
use app\SplitDB\ShardRouter;
use app\Helpers\Segmenter;

// ==================== 参数解析 ====================
$phase = 'report';
$limit = 10000;
$seed  = 20260814;
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if (strpos($arg, '--phase=') === 0)      { $phase = substr($arg, 8); }
    elseif ($arg === '--phase' && isset($args[$i + 1])) { $phase = $args[++$i]; }
    elseif (strpos($arg, '--limit=') === 0)  { $limit = (int)substr($arg, 8); }
    elseif ($arg === '--limit' && isset($args[$i + 1])) { $limit = (int)$args[++$i]; }
    elseif (strpos($arg, '--seed=') === 0)   { $seed = (int)substr($arg, 7); }
    elseif ($arg === '--seed' && isset($args[$i + 1])) { $seed = (int)$args[++$i]; }
}
if (!in_array($phase, ['users', 'threads', 'replies', 'blogs', 'search', 'finalize', 'report'], true)) {
    fwrite(STDERR, "未知阶段: {$phase}\n");
    exit(1);
}

$SEED = $seed;
$DATA = require __DIR__ . '/seed_content_data.php';

$dataPath = ShardRouter::dataPath();
$gid = Schema::globalIdDb();
$mi = Schema::mainIndexDb();
$biz = Schema::businessDb();

// ==================== 确定性工具 ====================

/** 重置随机源（同一 id 永远得到同一内容） */
function rng(int $id): void { mt_srand($GLOBALS['SEED'] + $id); }

/** 从数组按确定性随机取一个 */
function pick(array $arr, int $id, int $salt = 0): string
{
    rng($id + $salt * 7919);
    return $arr[mt_rand(0, count($arr) - 1)];
}

/** 槽位替换：{词性} 占位符从素材对应池抽取（必须用 /u 支持中文占位符，如 {语言}/{数字}） */
function fillSlots(string $tpl, int $id, array $slotPools): string
{
    $salt = 0;
    return preg_replace_callback('/\{([^}]+)\}/u', function ($m) use ($id, $slotPools, &$salt) {
        $k = trim($m[1]);
        if (isset($slotPools[$k]) && is_array($slotPools[$k]) && count($slotPools[$k]) > 0) {
            $salt++; // 每个槽位独立确定性盐，避免同文同值
            return pick($slotPools[$k], $id + $salt * 131, 13);
        }
        return $m[0]; // 未匹配到槽位池则保留原文（不应发生，素材库已覆盖全部占位符）
    }, $tpl);
}

/** 确定性随机时间戳（$minTs ~ $maxTs 之间） */
function randTs(int $id, int $minTs, int $maxTs): int
{
    rng($id + 555);
    return mt_rand($minTs, $maxTs);
}

/** HTML 转义（正文/标题安全） */
function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ==================== 分类计划（严格按用户比例，帖子总量 20 万） ====================
// 论坛帖 96%（192000）：站点公告1% / 程序发布15% / 插件模板20% / 精品专区30% / 聊天灌水30%
// 博客 4%（8000）：blog_cat 1 开发记录 55% / 2 日常杂记 45%
// （技术文章/内测版块 未分配比例 → 0 帖，报告中说明）

const THREAD_TOTAL   = 192000;   // 论坛帖总数
const THREAD_START   = 161;      // 当前 topic 游标 160，新帖从 161 起
const BLOG_TOTAL     = 8000;     // 博客总数

/** 论坛帖分类序数映射表（按 k=0..191999） */
function catForK(int $k): int
{
    if ($k < 2000)   return 1;   // 站点公告
    if ($k < 32000)  return 2;   // 程序发布
    if ($k < 72000)  return 3;   // 插件/模板
    if ($k < 132000) return 4;   // 精品专区
    return 5;                    // 聊天灌水
}

/** 确定性回复计划：帖子 id → 回复条数（0=无回复） */
function replyPlan(int $id): int
{
    $cat = catForK($id - THREAD_START);
    rng($id + 777);
    $r = mt_rand(0, 99);
    if ($cat === 5)                 return $r < 45 ? mt_rand(1, 8) : 0;  // 灌水 45% 有回复
    if ($cat === 2 || $cat === 3 || $cat === 4) return $r < 15 ? mt_rand(1, 3) : 0; // 技术类 15%
    if ($cat === 1)                 return $r < 5  ? mt_rand(1, 2) : 0;  // 公告 5%
    return 0;
}

/** 确定性最后回复时间：帖创建后 1h~30d 内，且不晚于 now */
function replyLastTs(int $id, int $createTs): int
{
    rng($id + 999);
    $max = min(time(), $createTs + 30 * 86400);
    if ($max <= $createTs + 3600) return $createTs + 3600;
    return mt_rand($createTs + 3600, $max);
}

// ==================== 正文生成 ====================

/**
 * 生成帖子 HTML 正文（2~5 段组合 + 可选代码块/引用）
 */
function genThreadContent(int $id, int $cat): string
{
    $pool = $GLOBALS['DATA']['bodies'][$cat] ?? $GLOBALS['DATA']['bodies'][5];
    $slots = $GLOBALS['DATA']['slots'] ?? [];
    rng($id + 333);
    $n = mt_rand(2, 5);
    $parts = [];
    for ($i = 0; $i < $n; $i++) {
        $tpl = pick($pool, $id + $i * 101, 3);
        $parts[] = '<p>' . fillSlots($tpl, $id + $i * 101, $slots) . '</p>';
    }
    // 技术类帖子部分附代码块
    if (($cat === 2 || $cat === 3) && mt_rand(0, 99) < 40 && !empty($GLOBALS['DATA']['codes'])) {
        $code = pick($GLOBALS['DATA']['codes'], $id, 5);
        $parts[] = '<pre><code>' . esc($code) . '</code></pre>';
    }
    if (($cat === 4) && mt_rand(0, 99) < 25 && !empty($GLOBALS['DATA']['quotes'])) {
        $parts[] = '<blockquote>' . fillSlots(pick($GLOBALS['DATA']['quotes'], $id, 7), $id, $slots) . '</blockquote>';
    }
    if ($cat === 5 && mt_rand(0, 99) < 30) {
        $parts[] = '<p>（来自 ' . esc(pick($GLOBALS['DATA']['nick_cn_suffix'], $id, 11)) . '的日常碎碎念）</p>';
    }
    return implode("\n", $parts);
}

function genTitle(int $id, int $cat): string
{
    $pool = $GLOBALS['DATA']['titles'][$cat] ?? [];
    $slots = $GLOBALS['DATA']['slots'] ?? [];
    rng($id + 222);
    if (empty($pool)) return '默认标题 #' . $id;
    $tpl = $pool[mt_rand(0, count($pool) - 1)];
    $t = fillSlots($tpl, $id, $slots);
    // 部分标题带编号后缀，增加多样性
    if (mt_rand(0, 99) < 12) {
        $t .= '（' . mt_rand(2, 99) . '）';
    }
    return $t;
}

function genReplyContent(int $id, int $cat, int $threadId): string
{
    if ($cat === 1) $pool = $GLOBALS['DATA']['replies']['announce'] ?? [];
    elseif ($cat === 5) $pool = $GLOBALS['DATA']['replies']['chat'] ?? [];
    else $pool = $GLOBALS['DATA']['replies']['tech'] ?? [];
    if (empty($pool)) $pool = $GLOBALS['DATA']['replies']['chat'];
    $tpl = pick($pool, $threadId + $id, 17);
    return '<p>' . fillSlots($tpl, $threadId + $id, $GLOBALS['DATA']['slots'] ?? []) . '</p>';
}

function genBlogContent(int $id, int $bcat): string
{
    $pool = $GLOBALS['DATA']['blog_bodies'][$bcat] ?? $GLOBALS['DATA']['blog_bodies'][1];
    $slots = $GLOBALS['DATA']['slots'] ?? [];
    rng($id + 444);
    $n = mt_rand(3, 7);
    $parts = [];
    for ($i = 0; $i < $n; $i++) {
        $tpl = pick($pool, $id + $i * 101, 4);
        $parts[] = '<p>' . fillSlots($tpl, $id + $i * 101, $slots) . '</p>';
    }
    if ($bcat === 1 && mt_rand(0, 99) < 35 && !empty($GLOBALS['DATA']['codes'])) {
        $parts[] = '<pre><code>' . esc(pick($GLOBALS['DATA']['codes'], $id, 6)) . '</code></pre>';
    }
    return implode("\n", $parts);
}

function genBlogTitle(int $id, int $bcat): string
{
    $pool = $GLOBALS['DATA']['blog_titles'][$bcat] ?? $GLOBALS['DATA']['blog_titles'][1];
    $tpl = pick($pool, $id, 9);
    return fillSlots($tpl, $id, $GLOBALS['DATA']['slots'] ?? []);
}

// ==================== 用户昵称/邮箱/签名 ====================

/**
 * 确定性昵称生成（同一 id 永远同一名字）。
 * 采用 id 索引映射到各池组合，空间远大于 5 万；唯一性由调用方 used 集合兜底。
 */
function genUsername(int $id): string
{
    $d = $GLOBALS['DATA'];
    $P = count($d['nick_cn_prefix']);
    $S = count($d['nick_cn_suffix']);
    $E = count($d['nick_en']);
    $Em = count($d['nick_emoji']);
    $V = count($d['nick_veteran']);

    $style = $id % 5;
    switch ($style) {
        case 0: // 中文：前缀 + 后缀（id 唯一分解，0..P*S 内完全唯一）
            return $d['nick_cn_prefix'][$id % $P] . $d['nick_cn_suffix'][intdiv($id, $P) % $S];
        case 1: // 英文：base + 段位数字（id 唯一分解）
            $e = $d['nick_en'][$id % $E];
            $q = intdiv($id, $E);
            return $q > 0 ? $e . $q : $e;
        case 2: // 英文 + 下划线 + 数字（多样式）
            $e = $d['nick_en'][intdiv($id, 7) % $E];
            return $e . '_' . ($id % 997 + 10);
        case 3: // Emoji + 中文后缀（空间 40×170，超出追加段位）
            $em = $d['nick_emoji'][$id % $Em];
            $b = $d['nick_cn_suffix'][intdiv($id, $Em) % $S];
            $slot = intdiv($id, $Em * $S);
            return $slot > 0 ? $em . $b . $slot : $em . $b;
        default: // 老站长/生活家 + 数字
            $v = $d['nick_veteran'][$id % $V];
            $q = intdiv($id, $V);
            return $q > 0 ? $v . ($id % 999 + 1) : $v;
    }
}

function genEmail(int $id): string
{
    $d = $GLOBALS['DATA'];
    rng($id + 888);
    $domains = ['qq.com', '163.com', 'gmail.com', 'outlook.com', '126.com', 'foxmail.com', 'hotmail.com', 'sina.com'];
    $local = pick($d['nick_en'], $id, 8);
    $local = strtolower(preg_replace('/[^a-z0-9]/i', '', $local));
    return $local . mt_rand(100, 9999) . $id . '@' . $domains[mt_rand(0, count($domains) - 1)];
}

function genSignature(int $id): string
{
    return pick($GLOBALS['DATA']['signatures'], $id, 10);
}

/** 生成确定性 SVG 头像（assets/uploads/seed_avatars/{id}.svg），返回相对路径 */
function writeAvatar(int $id, string $username): string
{
    rng($id + 12345);
    $h1 = mt_rand(0, 360); $h2 = ($h1 + mt_rand(40, 120)) % 360;
    $c1 = sprintf('hsl(%d,%d%%,%d%%)', $h1, mt_rand(45, 75), mt_rand(40, 60));
    $c2 = sprintf('hsl(%d,%d%%,%d%%)', $h2, mt_rand(45, 75), mt_rand(55, 75));
    $letter = mb_substr($username, 0, 1, 'UTF-8');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96">'
        . '<rect width="96" height="96" rx="14" fill="' . $c1 . '"/>'
        . '<circle cx="' . mt_rand(20, 76) . '" cy="' . mt_rand(16, 60) . '" r="' . mt_rand(8, 26) . '" fill="' . $c2 . '" opacity="0.55"/>'
        . '<text x="48" y="62" font-size="40" font-family="sans-serif" font-weight="bold" text-anchor="middle" fill="#ffffff">'
        . esc($letter) . '</text></svg>';
    $dir = dirname(__DIR__) . '/assets/uploads/seed_avatars';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $file = $dir . '/' . $id . '.svg';
    file_put_contents($file, $svg, LOCK_EX);
    return 'seed_avatars/' . $id . '.svg';
}

// ==================== 索引辅助 ====================

/** 标题分词 → search_index 批量行（与 Search::indexThread 一致：权重 3，正文按设置开关；带 created_at） */
function titleTokens(int $targetId, int $type, string $title, string $createdAt = ''): array
{
    $rows = [];
    foreach (Segmenter::tokenize($title) as $tok) {
        $rows[] = [$tok['token'], $type, $targetId, $tok['weight'], $createdAt];
    }
    return $rows;
}

// ==================== PHASE: users ====================

function phaseUsers(int $limit): void
{
    $biz = Schema::businessDb();
    $maxId = (int)$biz->query('SELECT COALESCE(MAX(id),0) FROM users')->fetchColumn();
    $startId = $maxId + 1;

    $sharedPass = password_hash('SeedTest@2026', PASSWORD_DEFAULT); // 种子用户统一测试密码
    $now = time();

    $ins = $biz->prepare(
        'INSERT INTO users (id, username, password, email, avatar, signature, role, status, group_id,
                            post_count, points, level, theme, remember_token, last_active_at, last_login,
                            email_verified, email_verify_token, email_verify_token_sent_at, created_at)
         VALUES (:id, :username, :password, :email, :avatar, :signature, :role, :status, :group_id,
                 :post_count, :points, :level, :theme, :remember_token, :last_active_at, :last_login,
                 :email_verified, :email_verify_token, :email_verify_token_sent_at, :created_at)'
    );

    $used = [];  // 昵称唯一性
    $existing = $biz->query('SELECT username FROM users')->fetchAll(\PDO::FETCH_COLUMN);
    foreach ($existing as $u) { $used[$u] = true; }

    $batch = 0;
    $inserted = 0;
    $biz->beginTransaction();
    for ($i = 0; $i < $limit; $i++) {
        $id = $startId + $i;
        $username = genUsername($id);
        // 唯一性兜底：确定性映射极少冲突，冲突时追加 '_' + id（id 全局唯一必不撞）
        $n = 0;
        while (isset($used[$username]) && $n < 20) {
            $username = genUsername($id) . '_' . ($id + $n * 10007);
            $n++;
        }
        $used[$username] = true;

        $avatar = writeAvatar($id, $username);
        $createdAt = randTs($id, strtotime('2025-06-01'), $now - 86400);
        $lastActive = randTs($id + 1, $createdAt, $now);
        $points = mt_rand(0, 600);
        $level = levelForPoints($points);

        $ins->execute([
            ':id' => $id, ':username' => $username, ':password' => $sharedPass,
            ':email' => genEmail($id), ':avatar' => $avatar, ':signature' => genSignature($id),
            ':role' => 'user', ':status' => 'active', ':group_id' => 1,
            ':post_count' => 0, ':points' => $points, ':level' => $level,
            ':theme' => '', ':remember_token' => null,
            ':last_active_at' => date('Y-m-d H:i:s', $lastActive),
            ':last_login' => date('Y-m-d H:i:s', $lastActive),
            ':email_verified' => 1, ':email_verify_token' => null, ':email_verify_token_sent_at' => null,
            ':created_at' => date('Y-m-d H:i:s', $createdAt),
        ]);
        $inserted++;
        if (++$batch >= 200) { $biz->commit(); $biz->beginTransaction(); $batch = 0; }
    }
    $biz->commit();
    $endId = $startId + $limit - 1;

    echo "users: 新增 {$inserted}（id {$startId}~{$endId}），当前总数 "
        . (int)$biz->query('SELECT COUNT(*) FROM users')->fetchColumn() . "\n";
}

function levelForPoints(int $points): int
{
    $thresholds = [0, 10, 40, 90, 160, 250, 360, 490, 640, 810, 1000];
    $lv = 1;
    foreach ($thresholds as $i => $t) {
        if ($points >= $t) { $lv = $i + 1; }
    }
    return $lv;
}

// ==================== PHASE: threads ====================

function phaseThreads(int $limit): void
{
    $dataPath = ShardRouter::dataPath();
    $gid = Schema::globalIdDb();
    $mi = Schema::mainIndexDb();
    $biz = Schema::businessDb();

    $startId = (int)$gid->query("SELECT last_id FROM id_generator WHERE table_name='topic'")->fetchColumn() + 1;
    $endId = $startId + $limit - 1;
    $userIds = $biz->query('SELECT id FROM users ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    if (empty($userIds)) { fwrite(STDERR, "无用户，请先执行 --phase users\n"); exit(1); }

    // 事务分批：按 (quarter,bucket) 分组桶写；脚本自持 PDO 引用避免 DBFactory LRU 重建
    $bucketPdo = [];   // bucket_path => PDO（自持引用，防 LRU 淘汰重建导致事务错位）
    $bucketRows = [];  // bucket_path => 当前事务内行数（分批提交）
    $searchBuf = [];

    $insTopic = [];   // bucket_path => PDOStatement（预编译缓存）
    $insIndex = $mi->prepare(
        'INSERT OR REPLACE INTO topic_index (id, uid, title, create_time, last_reply_time, view_count, reply_count,
                                  status, category_id, is_pinned, is_highlighted, color, reply_to_view,
                                  deleted_at, bucket_path)
         VALUES (:id, :uid, :title, :ct, :lrt, :vc, :rc, :st, :cid, :pin, :hl, :color, :rtv, NULL, :bpath)'
    );
    $mi->exec('BEGIN'); // main_index 批处理事务（每 500 条提交一次）
    $miRows = 0;

    $ok = 0; $t0 = microtime(true);
    for ($id = $startId; $id <= $endId; $id++) {
        $k = $id - THREAD_START;
        if ($k < 0 || $k >= THREAD_TOTAL) { echo "threads: 超出计划区间，终止\n"; break; }
        $cat = catForK($k);
        $uid = pick($userIds, $id, 21);
        $title = genTitle($id, $cat);
        $content = genThreadContent($id, $cat);

        $createTs = randTs($id, strtotime('2025-10-01'), min(time(), strtotime('2026-08-13')));
        $quarter = ShardRouter::quarter($createTs);
        $bucket = ShardRouter::bucket($id);
        $bucketPath = ShardRouter::bucketRel($quarter, $bucket);

        // ① extern 原子写
        $externRel = ExternStorage::write($dataPath, $id, $bucket, 'topic', $content, $createTs);

        // ② 桶 topic 行（分批事务；脚本自持 PDO 引用，避免 DBFactory LRU 重建导致事务错位）
        $rc = replyPlan($id);
        $lrt = $rc > 0 ? replyLastTs($id, $createTs) : $createTs;
        if (!isset($bucketPdo[$bucketPath])) {
            $bdb = Schema::bucketDb($quarter, $bucket, $dataPath);
            $bucketPdo[$bucketPath] = $bdb;
            $bucketRows[$bucketPath] = 0;
            $insTopic[$bucketPath] = $bdb->prepare(
                'INSERT OR REPLACE INTO topic (id, uid, category_id, title, create_time, update_time, last_reply_time,
                                    view_count, reply_count, status, is_pinned, is_highlighted, color,
                                    reply_to_view, deleted_at, extern_path)
                 VALUES (:id, :uid, :cid, :title, :ct, :ut, :lrt, :vc, :rc, :st, :pin, :hl, :color, :rtv, NULL, :extern)'
            );
            $bdb->exec('BEGIN'); // 首次接触桶即开启写事务
        }
        $insTopic[$bucketPath]->execute([
            ':id' => $id, ':uid' => $uid, ':cid' => $cat, ':title' => $title,
            ':ct' => $createTs, ':ut' => $createTs, ':lrt' => $lrt,
            ':vc' => randTs($id, 0, 1500), ':rc' => $rc, ':st' => 0,
            ':pin' => 0, ':hl' => ($cat === 4 && $k % 5 === 0) ? 1 : 0,
            ':color' => '', ':rtv' => 0, ':extern' => $externRel,
        ]);
        if (++$bucketRows[$bucketPath] >= 200) {
            $bucketPdo[$bucketPath]->exec('COMMIT');
            $bucketPdo[$bucketPath]->exec('BEGIN');
            $bucketRows[$bucketPath] = 0;
        }

        // ③ main_index 索引行
        $insIndex->execute([
            ':id' => $id, ':uid' => $uid, ':title' => $title, ':ct' => $createTs, ':lrt' => $lrt,
            ':vc' => 0, ':rc' => $rc, ':st' => 0, ':cid' => $cat, ':pin' => 0,
            ':hl' => ($cat === 4 && $k % 5 === 0) ? 1 : 0, ':color' => '', ':rtv' => 0,
            ':bpath' => $bucketPath,
        ]);

        // ④ main_index 批处理事务：每 500 条提交一次（search_index 由独立 --phase search 统一重建，勿在此写入）
        if (++$miRows >= 500) {
            $mi->exec('COMMIT');
            $mi->exec('BEGIN');
            $miRows = 0;
        }

        $ok++;
        if ($ok % 5000 === 0) {
            printf("threads: %d / 区间 %d~%d（%.1fs）\n", $ok, $startId, $endId, microtime(true) - $t0);
        }
    }

    // 收尾：桶事务提交（用自持连接）+ main_index 事务提交 + 游标推进
    foreach ($bucketPdo as $key => $pdo) {
        // 只要接触过的桶都曾 BEGIN（含空批次），统一 COMMIT
        $pdo->exec('COMMIT');
    }
    $mi->exec('COMMIT'); // main_index 最后一批事务提交
    foreach ($bucketRows as $key => &$rows) { $rows = []; }

    advanceCursor('topic', $endId);
    echo "threads: 完成 {$ok} 条（id {$startId}~{$endId}），游标 topic={$endId}\n";
}

/** 倒排行批量落库（[P21] 按 created_at 路由季度分文件，分文件单事务） */
function flushSearchIndex(array $buf): void
{
    if (empty($buf)) return;
    $groups = [];
    foreach ($buf as $r) {
        $q = \app\SplitDB\SearchIndexStore::quarterOf((string)($r[4] ?? ''));
        $groups[$q][] = $r;
    }
    foreach ($groups as $q => $rows) {
        $pdo = \app\SplitDB\SearchIndexStore::db($q);
        $pdo->exec('BEGIN');
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO search_index (token, type, target_id, weight, created_at) VALUES (:t, :tp, :id, :w, :ct)');
        foreach ($rows as $r) {
            $stmt->execute([
                ':t' => (string)$r[0], ':tp' => (int)$r[1], ':id' => (int)$r[2],
                ':w' => (int)$r[3], ':ct' => (string)($r[4] ?? ''),
            ]);
        }
        $pdo->exec('COMMIT');
    }
}

function advanceCursor(string $table, int $maxId): void
{
    $gid = Schema::globalIdDb();
    $gid->prepare('INSERT OR IGNORE INTO id_generator (table_name, last_id) VALUES (:t, 0)')->execute([':t' => $table]);
    $gid->prepare('UPDATE id_generator SET last_id = :max WHERE table_name = :t AND last_id < :max')
        ->execute([':t' => $table, ':max' => $maxId]);
}

// ==================== PHASE: replies ====================

function phaseReplies(int $limit): void
{
    $dataPath = ShardRouter::dataPath();
    $gid = Schema::globalIdDb();
    $mi = Schema::mainIndexDb();
    $biz = Schema::businessDb();

    $replyCursor = (int)$gid->query("SELECT last_id FROM id_generator WHERE table_name='reply'")->fetchColumn();
    $topicCursor = (int)$gid->query("SELECT last_id FROM id_generator WHERE table_name='topic'")->fetchColumn();
    $userIds = $biz->query('SELECT id FROM users ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    if (empty($userIds)) { fwrite(STDERR, "无用户\n"); exit(1); }

    // 读取本批帖子：需要 create_time + bucket_path + category（用于回复内容风格）
    $startThread = THREAD_START;
    $endThread = $topicCursor;
    $threads = $mi->query(
        'SELECT id, uid, create_time, category_id, bucket_path, reply_count FROM topic_index
         WHERE id >= ' . THREAD_START . ' AND id <= ' . (int)$endThread . ' ORDER BY id'
    )->fetchAll(\PDO::FETCH_ASSOC);

    $insReply = [];      // bucket_key => PDOStatement
    $bucketCnt = [];     // bucket_key => int
    $bucketPdo = [];     // bucket_key => PDO（自持引用，防 LRU 重建）
    $insRIdx = $mi->prepare(
        'INSERT INTO reply_index (id, pid, uid, create_time, update_time, status, bucket_path)
         VALUES (:id, :pid, :uid, :ct, :ut, :st, :bpath)'
    );
    $searchBuf = [];
    $written = 0; $t0 = microtime(true);

    foreach ($threads as $th) {
        $tid = (int)$th['id'];
        $plan = (int)$th['reply_count'];
        if ($plan <= 0) continue;
        $cat = (int)$th['category_id'];
        $createTs = (int)$th['create_time'];
        $bucketPath = $th['bucket_path'];

        // 已写回复数（断点续跑：reply_index 里 pid=tid 的已有行数）
        $done = (int)$mi->query('SELECT COUNT(*) FROM reply_index WHERE pid = ' . $tid)->fetchColumn();
        $remain = $plan - $done;
        if ($remain <= 0) continue;

        $bucketKey = $bucketPath;
        if (!isset($insReply[$bucketKey])) {
            $bdb = Schema::bucketFromPath($bucketPath, $dataPath);
            $bucketPdo[$bucketKey] = $bdb;
            $insReply[$bucketKey] = $bdb->prepare(
                'INSERT INTO reply (id, pid, uid, create_time, update_time, status, deleted_at, extern_path)
                 VALUES (:id, :pid, :uid, :ct, :ut, :st, NULL, :extern)'
            );
            $bucketCnt[$bucketKey] = 0;
            $bdb->exec('BEGIN'); // 首次接触桶即开启写事务
        }

        for ($r = 0; $r < $remain; $r++) {
            $rid = $replyCursor + 1;
            $replyCursor = $rid;
            $ruid = pick($userIds, $rid, 23);
            $rTs = randTs($rid, $createTs + 1800, min(time(), $createTs + 30 * 86400));
            $content = genReplyContent($rid, $cat, $tid);
            $bucket = ShardRouter::bucketFromPath($bucketPath);
            $externRel = ExternStorage::write($dataPath, $rid, $bucket, 'reply', $content, $rTs);

            $insReply[$bucketKey]->execute([
                ':id' => $rid, ':pid' => $tid, ':uid' => $ruid,
                ':ct' => $rTs, ':ut' => $rTs, ':st' => 0, ':extern' => $externRel,
            ]);
            $insRIdx->execute([
                ':id' => $rid, ':pid' => $tid, ':uid' => $ruid,
                ':ct' => $rTs, ':ut' => $rTs, ':st' => 0, ':bpath' => $bucketPath,
            ]);
            $written++;
            if (++$bucketCnt[$bucketKey] >= 200) {
                $bucketPdo[$bucketKey]->exec('COMMIT'); $bucketPdo[$bucketKey]->exec('BEGIN'); $bucketCnt[$bucketKey] = 0;
            }
            if ($written >= $limit) { break 2; }
        }
    }

    // 收尾
    foreach ($bucketPdo as $key => $pdo) {
        // 接触过的桶都曾 BEGIN，统一 COMMIT（含空批次）
        $pdo->exec('COMMIT');
    }
    flushSearchIndex($searchBuf);
    advanceCursor('reply', $replyCursor);
    echo "replies: 新增 {$written} 条（游标 reply={$replyCursor}），耗时 "
        . round(microtime(true) - $t0, 1) . "s\n";
}

// ==================== PHASE: search（独立重建倒排索引，仅标题分词） ====================

/**
 * 重建倒排索引（只分词标题，与系统 search_index_content=0 语义一致）。
 * 分批断点续跑：进度存 runtime/seed_search_progress.json（线程 max id / 博客 max id）。
 * 用法：php cli/seed_content.php --phase search --limit 50000   # 每批最多处理 5 万条记录
 */
function phaseSearch(int $limit): void
{
    $mi = Schema::mainIndexDb();
    $biz = Schema::businessDb();
    $dataPath = ShardRouter::dataPath();
    $progFile = $dataPath . '/runtime/seed_search_progress.json';

    // 进度：['threads' => maxId, 'blogs' => maxId]
    $prog = ['threads' => 0, 'blogs' => 0];
    if (is_file($progFile)) {
        $tmp = json_decode((string)file_get_contents($progFile), true);
        if (is_array($tmp)) { $prog = $tmp; }
    } else {
        // 首次运行：以现有搜索索引进度为起点（幂等 INSERT OR IGNORE，不重复处理；季度文件 max target_id）
        $prog['threads'] = \app\SplitDB\SearchIndexStore::maxTargetId(1);
        $prog['blogs']   = \app\SplitDB\SearchIndexStore::maxTargetId(2);
    }

    // 事务 + 单行预编译插入（每 20000 行提交一次）：prepare 仅 1 次/季度文件，
    // 避免多行批量 SQL 的 PDO 解析开销（500 行/2500 参数实测更慢）
    // 写入按 created_at 路由季度分文件（SearchIndexStore）
    $ins = [];      // quarter => PDOStatement
    $pdoMap = [];   // quarter => PDO
    $txOpen = [];   // quarter => bool
    $txCount = [];  // quarter => int
    $write = function (array $row) use (&$ins, &$pdoMap, &$txOpen, &$txCount) {
        $q = \app\SplitDB\SearchIndexStore::quarterOf((string)($row[4] ?? ''));
        if (!isset($pdoMap[$q])) {
            $pdoMap[$q] = \app\SplitDB\SearchIndexStore::db($q);
            $ins[$q] = $pdoMap[$q]->prepare('INSERT OR IGNORE INTO search_index (token, type, target_id, weight, created_at) VALUES (:t, :tp, :id, :w, :ct)');
            $txCount[$q] = 0;
        }
        if (empty($txOpen[$q])) { $pdoMap[$q]->exec('BEGIN'); $txOpen[$q] = true; }
        $ins[$q]->execute([':t' => $row[0], ':tp' => $row[1], ':id' => $row[2], ':w' => $row[3], ':ct' => $row[4]]);
        if (++$txCount[$q] >= 20000) {
            $pdoMap[$q]->exec('COMMIT');
            $txOpen[$q] = false;
            $txCount[$q] = 0;
        }
    };
    $commitAll = function () use (&$pdoMap, &$txOpen) {
        foreach ($pdoMap as $q => $pdo) {
            if (!empty($txOpen[$q])) {
                $pdo->exec('COMMIT');
                $txOpen[$q] = false;
            }
        }
    };

    $t0 = microtime(true);
    $done = 0;

    // ---- 帖子（type=1，仅标题） ----
    $start = (int)$prog['threads'];
    $ids = $mi->query(
        'SELECT id, title, create_time FROM topic_index WHERE id > ' . $start . ' ORDER BY id LIMIT ' . (int)$limit
    )->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($ids as $r) {
        $ct = date('Y-m-d H:i:s', (int)$r['create_time']); // 与 Thread::ts 格式一致
        foreach (titleTokens((int)$r['id'], 1, (string)$r['title'], $ct) as $row) {
            $write($row);
        }
        $done++;
        $prog['threads'] = (int)$r['id'];
        if ($done % 10000 === 0) {
            $commitAll();
            file_put_contents($progFile, json_encode($prog), LOCK_EX);
            printf("search: 帖子 %d（到 id %d, %.1fs）\n", $done, $prog['threads'], microtime(true) - $t0);
        }
    }
    $commitAll();
    file_put_contents($progFile, json_encode($prog), LOCK_EX);

    // ---- 博客（type=2，仅标题） ----
    $bStart = (int)$prog['blogs'];
    $blogs = $biz->query(
        'SELECT id, title, created_at FROM blogs WHERE id > ' . $bStart . ' ORDER BY id LIMIT ' . (int)$limit
    )->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($blogs as $r) {
        foreach (titleTokens((int)$r['id'], 2, (string)$r['title'], (string)($r['created_at'] ?? '')) as $row) {
            $write($row);
        }
        $done++;
        $prog['blogs'] = (int)$r['id'];
        if ($done % 10000 === 0) {
            $commitAll();
            file_put_contents($progFile, json_encode($prog), LOCK_EX);
            printf("search: 博客 %d（到 id %d, %.1fs）\n", $done, $prog['blogs'], microtime(true) - $t0);
        }
    }
    $commitAll();
    file_put_contents($progFile, json_encode($prog), LOCK_EX);

    // 完成判定：帖子与博客都跑完则清除进度文件
    $maxThread = (int)$mi->query('SELECT COALESCE(MAX(id),0) FROM topic_index')->fetchColumn();
    $maxBlog   = (int)$biz->query('SELECT COALESCE(MAX(id),0) FROM blogs')->fetchColumn();
    if ($prog['threads'] >= $maxThread && $prog['blogs'] >= $maxBlog) {
        @unlink($progFile);
        $total = \app\SplitDB\SearchIndexStore::countAll();
        echo "search: ✅ 全量重建完成 帖子 {$maxThread} + 博客 {$maxBlog}，搜索索引（季度分文件）共 {$total} 行，耗时 "
            . round(microtime(true) - $t0, 1) . "s\n";
    } else {
        echo "search: 本批处理 {$done} 条（帖子到 {$prog['threads']}，博客到 {$prog['blogs']}），继续执行可断点续跑\n";
    }
}

// ==================== PHASE: blogs ====================

function phaseBlogs(int $limit): void
{
    $biz = Schema::businessDb();
    $startId = (int)$biz->query('SELECT COALESCE(MAX(id),0) FROM blogs')->fetchColumn() + 1;
    $userIds = $biz->query('SELECT id FROM users ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    if (empty($userIds)) { fwrite(STDERR, "无用户\n"); exit(1); }

    $ins = $biz->prepare(
        'INSERT INTO blogs (id, category_id, user_id, title, content, cover_image, view_count,
                            comment_count, updated_at, created_at)
         VALUES (:id, :cid, :uid, :title, :content, :cover, :vc, :cc, :ut, :ct)'
    );
    $searchBuf = [];
    $now = time();
    $biz->beginTransaction(); $batch = 0;
    for ($i = 0; $i < $limit; $i++) {
        $id = $startId + $i;
        $bcat = ($id % 100) < 55 ? 1 : 2; // 开发记录 55% / 日常杂记 45%
        $uid = pick($userIds, $id, 31);
        $title = genBlogTitle($id, $bcat);
        $content = genBlogContent($id, $bcat);
        $createdTs = randTs($id, strtotime('2025-08-01'), $now - 3600);
        $vc = randTs($id, 0, 800);
        $cc = ($id % 4 === 0) ? mt_rand(1, 12) : 0;
        $ins->execute([
            ':id' => $id, ':cid' => $bcat, ':uid' => $uid, ':title' => $title, ':content' => $content,
            ':cover' => '', ':vc' => $vc, ':cc' => $cc,
            ':ut' => date('Y-m-d H:i:s', $createdTs), ':ct' => date('Y-m-d H:i:s', $createdTs),
        ]);
        foreach (titleTokens($id, 2, $title) as $row) { $searchBuf[] = $row; }
        if (++$batch >= 200) { $biz->commit(); $biz->beginTransaction(); $batch = 0; }
        if (count($searchBuf) >= 300) { flushSearchIndex($searchBuf, $biz); $searchBuf = []; }
    }
    $biz->commit();
    flushSearchIndex($searchBuf, $biz);
    $endId = $startId + $limit - 1;
    echo "blogs: 新增 {$limit} 条（id {$startId}~{$endId}）\n";
}

// ==================== PHASE: finalize ====================

function phaseFinalize(): void
{
    $biz = Schema::businessDb();
    $mi = Schema::mainIndexDb();

    // 1. 用户 post_count = 主题数 + 回复数（topic_index/reply_index 在 main_index 库，先聚合再回写）
    $counts = [];
    foreach ($mi->query('SELECT uid, COUNT(*) c FROM topic_index GROUP BY uid') as $r) {
        $counts[(int)$r['uid']] = (int)$r['c'];
    }
    foreach ($mi->query('SELECT uid, COUNT(*) c FROM reply_index GROUP BY uid') as $r) {
        $counts[(int)$r['uid']] = ($counts[(int)$r['uid']] ?? 0) + (int)$r['c'];
    }
    $updPost = $biz->prepare('UPDATE users SET post_count = :pc WHERE id = :id');
    $biz->beginTransaction();
    foreach ($counts as $uid => $c) {
        $updPost->execute([':pc' => $c, ':id' => $uid]);
    }
    $biz->commit();
    echo '  已回写 ' . count($counts) . ' 个用户的 post_count' . "\n";

    // 2. points / level 按发帖量联动
    $biz->query(
        'UPDATE users SET points = post_count * 2 + (id % 7)'
    );
    $rows = $biz->query('SELECT id, points FROM users')->fetchAll(\PDO::FETCH_ASSOC);
    $upd = $biz->prepare('UPDATE users SET level = :lv WHERE id = :id');
    $biz->beginTransaction();
    foreach ($rows as $r) {
        $upd->execute([':lv' => levelForPoints((int)$r['points']), ':id' => (int)$r['id']]);
    }
    $biz->commit();

    // 3. 全站统计（total_* 运行时键落库）
    $stats = [
        'total_users'   => (int)$biz->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'total_threads' => (int)$mi->query('SELECT COUNT(*) FROM topic_index WHERE deleted_at IS NULL')->fetchColumn(),
        'total_posts'   => (int)$mi->query('SELECT COUNT(*) FROM reply_index')->fetchColumn(),
        'total_blogs'   => (int)$biz->query('SELECT COUNT(*) FROM blogs')->fetchColumn(),
    ];
    foreach ($stats as $k => $v) {
        $stmt = $biz->prepare('INSERT INTO settings ("key", value) VALUES (:k, :v) ON CONFLICT("key") DO UPDATE SET value = :v2');
        $stmt->execute([':k' => '_runtime_' . $k, ':v' => (string)$v, ':v2' => (string)$v]);
    }
    foreach ($stats as $k => $v) { echo "  {$k} = {$v}\n"; }
    echo "finalize: 完成\n";
}

// ==================== PHASE: report ====================

function phaseReport(): void
{
    $dataPath = ShardRouter::dataPath();
    $biz = Schema::businessDb();
    $mi = Schema::mainIndexDb();

    // 1. 32 桶分布（bucket/active/*）
    echo "\n===== 分片分布（bucket/active/）=====\n";
    $bucketAgg = array_fill(0, ShardRouter::bucketSize(), 0);
    $quarterSummary = [];
    foreach (glob($dataPath . '/bucket/active/*') as $qDir) {
        $quarter = basename($qDir);
        $rows = 0; $files = 0;
        $perBucket = [];
        for ($b = 0; $b < ShardRouter::bucketSize(); $b++) {
            $f = $qDir . '/' . $b . '.sqlite';
            if (is_file($f)) {
                $files++;
                $db = Schema::bucketDb($quarter, $b, $dataPath);
                $c = (int)$db->query('SELECT COUNT(*) FROM topic')->fetchColumn();
                $r2 = (int)$db->query('SELECT COUNT(*) FROM reply')->fetchColumn();
                $perBucket[$b] = $c;
                $bucketAgg[$b] += $c;
                $rows += $c;
            } else {
                $perBucket[$b] = 0;
            }
        }
        $quarterSummary[$quarter] = ['topic' => $rows, 'files' => $files];
    }
    // 输出每季度摘要 + 每桶聚合
    foreach ($quarterSummary as $q => $s) {
        echo "  {$q}: topic {$s['topic']} 行 / {$s['files']} 个桶文件\n";
    }
    echo "  桶号分布（topic 行数，聚合全部季度）:\n";
    $line = '';
    foreach ($bucketAgg as $b => $c) { $line .= sprintf("  %02d:%d", $b, $c); }
    echo wordwrap($line, 100) . "\n";
    $min = min($bucketAgg); $max = max($bucketAgg);
    echo "  桶分布 min={$min} max={$max}（均方差参考：范围 " . ($max - $min) . "）\n";

    // 2. 各版块帖子数量统计
    echo "\n===== 各版块帖子统计（main_index.topic_index）=====\n";
    $cats = $biz->query('SELECT id, name FROM categories ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    $byCat = [];
    foreach ($mi->query('SELECT category_id, COUNT(*) c FROM topic_index WHERE deleted_at IS NULL GROUP BY category_id') as $r) {
        $byCat[(int)$r['category_id']] = (int)$r['c'];
    }
    foreach ($cats as $c) {
        $cnt = $byCat[(int)$c['id']] ?? 0;
        printf("  [%d] %s: %d 帖\n", $c['id'], $c['name'], $cnt);
    }
    echo '  博客: ' . (int)$biz->query('SELECT COUNT(*) FROM blogs')->fetchColumn() . " 篇\n";

    // 3. 全站占用空间
    echo "\n===== 全站占用空间 =====\n";
    $sizeBytes = 0; $fileCount = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dataPath, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { $sizeBytes += $f->getSize(); $fileCount++; }
    printf("  data/ 总大小: %.2f MB（%d 个文件）\n", $sizeBytes / 1048576, $fileCount);

    // 4. 其它计数
    echo "\n===== 总量统计 =====\n";
    echo '  users: ' . (int)$biz->query('SELECT COUNT(*) FROM users')->fetchColumn() . "\n";
    echo '  topic_index: ' . (int)$mi->query('SELECT COUNT(*) FROM topic_index')->fetchColumn() . "\n";
    echo '  reply_index: ' . (int)$mi->query('SELECT COUNT(*) FROM reply_index')->fetchColumn() . "\n";
    echo '  blogs: ' . (int)$biz->query('SELECT COUNT(*) FROM blogs')->fetchColumn() . "\n";
    echo '  search_index: ' . (int)$biz->query('SELECT COUNT(*) FROM search_index')->fetchColumn() . "\n";
    $gid = Schema::globalIdDb();
    echo '  global_id: topic=' . (int)$gid->query("SELECT last_id FROM id_generator WHERE table_name='topic'")->fetchColumn()
        . ' reply=' . (int)$gid->query("SELECT last_id FROM id_generator WHERE table_name='reply'")->fetchColumn() . "\n";

    // 5. 搜索抽样验证
    echo "\n===== 搜索抽样 =====\n";
    foreach (['PHP', 'SQLite', 'SplitDB', '插件', '周末'] as $kw) {
        $tokens = Segmenter::tokenizeQuery($kw);
        if (empty($tokens)) { echo "  [{$kw}] 无分词\n"; continue; }
        $ph = [];
        foreach ($tokens as $i => $t) { $ph[] = ':t' . $i; }
        $params = [];
        foreach ($tokens as $i => $t) { $params[':t' . $i] = $t; }
        $sql = 'SELECT COUNT(DISTINCT target_id) c FROM search_index WHERE token IN (' . implode(',', $ph) . ')';
        $stmt = $biz->prepare($sql);
        $stmt->execute($params);
        $c = (int)$stmt->fetchColumn();
        echo "  [{$kw}] 命中 {$c} 个目标\n";
    }
    echo "\nreport: 完成\n";
}

// ==================== 调度 ====================

switch ($phase) {
    case 'users':    phaseUsers($limit); break;
    case 'threads':  phaseThreads($limit); break;
    case 'replies':  phaseReplies($limit); break;
    case 'blogs':    phaseBlogs($limit); break;
    case 'search':   phaseSearch($limit); break;
    case 'finalize': phaseFinalize(); break;
    case 'report':   phaseReport(); break;
}
