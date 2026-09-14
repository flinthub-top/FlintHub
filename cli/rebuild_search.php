<?php
/**
 * FlintHub 1.0 (SplitDB) — 全量重建搜索索引（CLI）
 *
 * 用法：
 *   php cli/rebuild_search.php            # 重建帖子 + 博客倒排索引
 *   php cli/rebuild_search.php --threads  # 仅重建帖子
 *   php cli/rebuild_search.php --blogs    # 仅重建博客
 *
 * 原理（索引库支持从分片真相源一键重建）：
 *   遍历 main_index.topic_index（帖子）与 business.blogs（博客）的 ID，
 *   逐个调用 Search::indexThread / indexBlog —— 标题/正文经 Thread 模型读取
 *   （正文剥离 SQLite 存 extern/），重新分词写入季度分文件
 *   （data/meta/search/search_{YYYYQn}.sqlite）。
 *
 * 本文件同时被 cli/migrate.php（B4 收口）require 调用 runRebuildSearch()，
 * 直接运行时入口有守卫，被 require 时不自动执行。
 *
 * @package app\cli
 */

require_once __DIR__ . '/_guard.php'; // Web 直访守卫（CLI-only）
require_once __DIR__ . '/../app/Core/Autoloader.php';

use app\Core\Database;
use app\Helpers\Search;
use app\SplitDB\Schema;

/**
 * 全量重建搜索索引（可被 migrate.php 复用）
 *
 * @param bool $threads 是否重建帖子索引（type=1）
 * @param bool $blogs   是否重建博客索引（type=2）
 * @return array{thread:int, blog:int, total:int} 重建统计
 */
function runRebuildSearch(bool $threads = true, bool $blogs = true): array
{
    $db = Database::getInstance();
    $mi = Schema::mainIndexDb();
    $started = microtime(true);

    // 1. 清空倒排索引（幂等重建，先删后建；清空全部季度分文件）
    \app\SplitDB\SearchIndexStore::clearAll();
    echo "已清空搜索索引（季度分文件）\n";

    // 2. 重建帖子索引（type=1）
    $threadCount = 0;
    if ($threads) {
        $ids = $mi->query('SELECT id FROM topic_index ORDER BY id ASC')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ids as $tid) {
            Search::indexThread((int)$tid);
            $threadCount++;
            if ($threadCount % 100 === 0) {
                echo "  帖子 {$threadCount}/" . count($ids) . "\n";
            }
        }
        echo "帖子索引重建完成：{$threadCount} 篇\n";
    }

    // 3. 重建博客索引（type=2）
    $blogCount = 0;
    if ($blogs) {
        $bids = $db->query('SELECT id FROM blogs ORDER BY id ASC')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($bids as $bid) {
            Search::indexBlog((int)$bid);
            $blogCount++;
        }
        echo "博客索引重建完成：{$blogCount} 篇\n";
    }

    $elapsed = round(microtime(true) - $started, 2);
    $total = \app\SplitDB\SearchIndexStore::countAll();
    echo "重建完成：搜索索引（季度分文件）共 {$total} 条，耗时 {$elapsed}s\n";

    return ['thread' => $threadCount, 'blog' => $blogCount, 'total' => (int)$total];
}

// ============================================================
// 直接运行入口（被 require 时跳过，供 migrate.php 复用）
// ============================================================
$isDirectRun = (PHP_SAPI === 'cli')
    && (isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === __FILE__);

if ($isDirectRun) {
    $threads = true;
    $blogs = true;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--threads') { $blogs = false; }
        elseif ($arg === '--blogs') { $threads = false; }
    }
    runRebuildSearch($threads, $blogs);
    exit(0);
}
