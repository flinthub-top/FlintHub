<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 全文搜索 — 倒排索引构建/查询、FTS 同步
 * @file app/Helpers/Search.php
 * @package app\Helpers
 */

namespace app\Helpers;

class Search
{
    /**
     * 统一搜索入口
     * @param array|null $allowedCategoryIds 可选，指定可查看的版块 ID 列表，在 SQL 层做权限过滤
     * @param string $timeRange 时间范围：all / 30d（近一月）/ 180d（近半年）/ 365d（近一年）
     */
    public static function search(string $query, string $type = 'all', int $page = 1, int $perPage = 20, ?array $allowedCategoryIds = null, string $timeRange = 'all'): array
    {
        $db = \app\Core\Database::getInstance();
        $results = ['threads' => [], 'blogs' => [], 'total' => 0, 'thread_count' => 0, 'blog_count' => 0];

        $query = \trim($query);
        if ($query === '') return $results;
        // 搜索关键词截断长度（后台「显示设置→长度限制」可配）
        $maxQueryLen = (int)Settings::get('limit_search_query', '100');
        if (\mb_strlen($query) > $maxQueryLen) $query = \mb_substr($query, 0, $maxQueryLen);

        // 优先使用倒排索引搜索（中文友好），搜索库文件缺失/损坏时降级到 LIKE
        try {
            return self::searchByIndex($query, $type, $page, $perPage, $allowedCategoryIds, $timeRange);
        } catch (\Throwable $e) {
            // search_index 表不存在 / 索引损坏 → 降级到 LIKE 搜索
            \error_log('Search index failed, fallback to LIKE: ' . $e->getMessage());
            return self::searchLIKE($db, $query, $type, $page, $perPage, $allowedCategoryIds, $timeRange);
        }
    }

    // ====== 倒排索引搜索（中文分词支持） ======

    /**
     * 时间范围 → created_at 下限（空串表示不过滤）
     */
    private static function timeRangeSince(string $timeRange): string
    {
        switch ($timeRange) {
            case '30d':  return date('Y-m-d H:i:s', time() - 30 * 86400);
            case '180d': return date('Y-m-d H:i:s', time() - 180 * 86400);
            case '365d': return date('Y-m-d H:i:s', time() - 365 * 86400);
            default:     return '';
        }
    }

    /**
     * 通过倒排索引搜索（多季度文件归并 + 中文 + 版块权限过滤 + 时间范围过滤）
     *
     * 多文件检索：时间范围 → quartersForSince() 算覆盖季度集合（滑动窗口跨季度），
     * 每文件各取前 (offset+limit) 名候选 → 归并 → score 降序 → 页内切片；计数跨文件 SUM；
     * 版块权限过滤在取数后统一做
     */
    private static function searchByIndex(string $query, string $type, int $page, int $perPage, ?array $allowedCategoryIds = null, string $timeRange = 'all'): array
    {
        $results = ['threads' => [], 'blogs' => [], 'total' => 0, 'thread_count' => 0, 'blog_count' => 0];
        $offset = ($page - 1) * $perPage;

        $queryTokens = Segmenter::tokenizeQuery($query);
        if (empty($queryTokens)) return $results;

        // 时间范围 → 覆盖季度文件集合（空串 = 全部季度文件）
        $since = self::timeRangeSince($timeRange);
        $quarters = \app\SplitDB\SearchIndexStore::quartersForSince($since);
        if (empty($quarters)) return $results;

        // 展示数量：all 模式 thread/blog 各 10；thread/blog 模式 = perPage
        $threadLimit = ($type === 'thread') ? $perPage : (($type === 'all') ? 10 : 0);
        $blogLimit   = ($type === 'blog')   ? $perPage : (($type === 'all') ? 10 : 0);

        // 全局计数：thread/blog 各统计一次（与当前 tab 无关），
        // 供 tab 计数（全部/帖子/博客）始终显示该关键词的全局匹配数，切 tab 后数字不漂移
        $threadCount = 0;
        $blogCount = 0;
        foreach ($quarters as $quarter) {
            $pdo = \app\SplitDB\SearchIndexStore::db($quarter);
            $threadCount += \app\SplitDB\SearchIndexStore::countTargets($pdo, 1, $queryTokens, $since);
            $blogCount += \app\SplitDB\SearchIndexStore::countTargets($pdo, 2, $queryTokens, $since);
        }
        $results['thread_count'] = $threadCount;
        $results['blog_count'] = $blogCount;

        $threadCands = []; // target_id => score（跨季度归并）
        $blogCands = [];
        $threadModel = new \app\Models\Thread();

        // ============================================================
        // 候选收集（逐 token 查询归并，强制走 token 索引）
        // 候选放大 3 倍（take = offset + limit*3），为权限过滤留出余量；
        // 不足展示数时扩大 take 重查补位（最多 4 轮，take 上限 500 防过度查询）
        // ============================================================
        $threadTake = $offset + max($threadLimit * 3, 1);
        $blogTake   = $offset + max($blogLimit * 3, 1);
        $MAX_TAKE   = 500;

        // —— 帖子候选 + 计数 ——
        if ($threadLimit > 0) {
            $threadPicked = []; // tid => score（已过权限过滤，保持 score 降序）
            $rounds = 0;
            while ($rounds < 4 && count($threadPicked) < $threadLimit) {
                $rounds++;
                if ($rounds > 1) {
                    // 补位：扩大 take 重查（重新归并候选池）
                    $threadTake = min($threadTake * 2, $MAX_TAKE);
                    $threadCands = [];
                }
                foreach ($quarters as $quarter) {
                    $pdo = \app\SplitDB\SearchIndexStore::db($quarter);
                    foreach (\app\SplitDB\SearchIndexStore::searchByTokens($pdo, 1, $queryTokens, $threadTake, $since) as $tid => $score) {
                        $threadCands[$tid] = ($threadCands[$tid] ?? 0) + $score;
                    }
                }
                arsort($threadCands);
                $orderedTids = array_keys($threadCands);
                // 从分页 offset 位置开始扫描（跳过已展示页）：直接遍历已切片 $scanSlice，
                // 不再用 $orderedTids 全量下标判断 $i < $offset——原 break 会在第 2 页起
                // 因 $i=0 < $offset 立即退出循环，导致第 2 页起永远无数据（分页缺陷已修复）
                $scannedThisRound = 0;
                // 本轮候选一次批量取基本行（替代逐帖 find() 的 N+1）：
                // getByIds 仅读 main_index + 批量补用户/分类，不读 extern 正文（扫描阶段用不到 content）
                $candRows = [];
                $scanSlice = array_slice($orderedTids, $offset);
                if (!empty($scanSlice)) {
                    foreach ($threadModel->getByIds($scanSlice) as $row) {
                        $candRows[(int)$row['id']] = $row;
                    }
                }
                foreach ($scanSlice as $tid) {
                    if (count($threadPicked) >= $threadLimit) break;
                    $tid = (int)$tid;
                    if (isset($threadPicked[$tid])) continue;
                    $scannedThisRound++;
                    $t = $candRows[$tid] ?? null;
                    // 软删/不存在：getByIds 已按 status + deleted_at 过滤，不在映射即跳过
                    if (!$t) continue;
                    // 版块权限过滤（SQL 层无 catFilter 的路径在此 PHP 层过滤）
                    if (!empty($allowedCategoryIds) && !in_array((int)$t['category_id'], array_map('intval', $allowedCategoryIds), true)) {
                        continue;
                    }
                    $threadPicked[$tid] = $threadCands[$tid] ?? 0;
                }
                // 候选池尽判定：本 take 取回的候选数 < take（匹配总量小于单轮取数上限）
                //   → 已覆盖全部匹配，无需扩大重查；或本轮无新增扫描 → 终止补位
                if (count($threadCands) < $threadTake || $scannedThisRound === 0) {
                    break;
                }
            }
            // 最终输出：一次批量取选中帖（getByIds）+ 按桶批量回填 extern 正文，替代第二轮逐帖 find()
            $pickedRows = [];
            $pickedTids = array_keys($threadPicked);
            if (!empty($pickedTids)) {
                $pickedRows = $threadModel->fillExternContent($threadModel->getByIds($pickedTids));
            }
            $pickedMap = [];
            foreach ($pickedRows as $row) {
                $pickedMap[(int)$row['id']] = $row;
            }
            // 保持 score 降序输出 + _score 透出（供合并排序使用）
            foreach ($threadPicked as $tid => $score) {
                $t = $pickedMap[$tid] ?? null;
                if (!$t) continue;
                $t['_score'] = $score;
                $t['highlighted_title'] = self::highlightTerms($t['title'] ?? '', $query);
                $t['content_snippet'] = \mb_substr(\strip_tags($t['content'] ?? ''), 0, 200);
                $results['threads'][] = $t;
            }
        }

        // —— 博客候选（计数已在上方全局块完成；博客无版块权限概念，直接按 score 取 top） ——
        if ($blogLimit > 0) {
            $db = \app\Core\Database::getInstance();
            foreach ($quarters as $quarter) {
                $pdo = \app\SplitDB\SearchIndexStore::db($quarter);
                foreach (\app\SplitDB\SearchIndexStore::searchByTokens($pdo, 2, $queryTokens, $blogTake, $since) as $bid => $score) {
                    $blogCands[$bid] = ($blogCands[$bid] ?? 0) + $score;
                }
            }
            arsort($blogCands);
            $blogBids = array_slice(array_keys($blogCands), $offset, $blogLimit);
            if (!empty($blogBids)) {
                $blogs = $db->fetchAll(
                    "SELECT b.*, u.username, bc.name as category_name
                     FROM blogs b
                     LEFT JOIN users u ON b.user_id = u.id
                     LEFT JOIN blog_categories bc ON b.category_id = bc.id
                     WHERE b.id IN (" . implode(',', array_map('intval', $blogBids)) . ")",
                    []
                );
                $blogMap = [];
                foreach ($blogs as $b) { $blogMap[(int)$b['id']] = $b; }
                foreach ($blogBids as $bid) {
                    if (!isset($blogMap[$bid])) continue;
                    $b = $blogMap[$bid];
                    $b['_score'] = $blogCands[$bid] ?? 0;
                    $b['highlighted_title'] = self::highlightTerms($b['title'] ?? '', $query);
                    $b['content_snippet'] = \mb_substr(\strip_tags($b['content'] ?? ''), 0, 200);
                    $results['blogs'][] = $b;
                }
            }
        }

        if ($type === 'all') $results['total'] = $threadCount + $blogCount;
        elseif ($type === 'thread') $results['total'] = $threadCount;
        else $results['total'] = $blogCount;

        return $results;
    }

    // ====== 倒排索引维护 ======

    /**
     * 索引一篇帖子（发帖/编辑时调用）
     * 正文外置分片：标题/正文经 Thread 模型读取
     * 写入季度分文件（按 created_at 路由；删旧 + 批量 INSERT 单事务，见 SearchIndexStore）
     */
    public static function indexThread(int $threadId): void
    {
        set_time_limit(0); // 长帖分词可能耗时较长
        $thread = (new \app\Models\Thread())->find((int)$threadId);
        if (!$thread) return;

        $tokens = Segmenter::tokenize($thread['title']); // 至少索引标题
        // 根据后台设置决定是否索引正文（合并时标题词条优先保留，正文不覆盖）
        if (\app\Helpers\Settings::get('search_index_content', '0') === '1') {
            $tokenMap = [];
            foreach ($tokens as $t) { $tokenMap[$t['token']] = $t['weight']; }
            foreach (Segmenter::tokenize('', $thread['content'] ?? '') as $bt) {
                if (!isset($tokenMap[$bt['token']])) {
                    $tokenMap[$bt['token']] = $bt['weight'];
                }
            }
            $tokens = [];
            foreach ($tokenMap as $tok => $w) { $tokens[] = ['token' => $tok, 'weight' => $w]; }
        }

        \app\SplitDB\SearchIndexStore::writeIndex(1, (int)$threadId, (string)($thread['created_at'] ?? ''), $tokens);
    }

    /**
     * 索引一篇博客（发博/编辑时调用）
     * 写入季度分文件（按 created_at 路由；删旧 + 批量 INSERT 单事务，见 SearchIndexStore）
     */
    public static function indexBlog(int $blogId): void
    {
        set_time_limit(0); // 长文分词可能耗时较长
        $db = \app\Core\Database::getInstance();
        $blog = $db->fetchOne("SELECT title, content, created_at FROM blogs WHERE id = :id", [':id' => $blogId]);
        if (!$blog) return;

        $tokens = Segmenter::tokenize($blog['title']); // 至少索引标题
        // 根据后台设置决定是否索引正文（合并时标题词条优先保留，正文不覆盖）
        if (\app\Helpers\Settings::get('search_index_content', '0') === '1') {
            $tokenMap = [];
            foreach ($tokens as $t) { $tokenMap[$t['token']] = $t['weight']; }
            foreach (Segmenter::tokenize('', $blog['content'] ?? '') as $bt) {
                if (!isset($tokenMap[$bt['token']])) {
                    $tokenMap[$bt['token']] = $bt['weight'];
                }
            }
            $tokens = [];
            foreach ($tokenMap as $tok => $w) { $tokens[] = ['token' => $tok, 'weight' => $w]; }
        }

        \app\SplitDB\SearchIndexStore::writeIndex(2, (int)$blogId, (string)($blog['created_at'] ?? ''), $tokens);
    }

    /**
     * 从倒排索引中移除（删帖/删博客时调用）
     * 无时间信息，扫描全部季度文件删除（SearchIndexStore::deleteIndex）
     */
    public static function removeFromIndex(string $type, int $targetId): void
    {
        $typeNum = ($type === 'thread') ? 1 : (($type === 'blog') ? 2 : 0);
        if ($typeNum === 0) return;
        \app\SplitDB\SearchIndexStore::deleteIndex($typeNum, $targetId);
    }

    // ====== LIKE search（通用降级方案） ======

    private static function searchLIKE($db, string $query, string $type, int $page, int $perPage, ?array $allowedCategoryIds = null, string $timeRange = 'all'): array
    {
        $results = ['threads' => [], 'blogs' => [], 'total' => 0, 'thread_count' => 0, 'blog_count' => 0];
        $offset = ($page - 1) * $perPage;
        $sq = '%' . \str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';

        // 时间范围过滤（与倒排索引路径语义一致）。
        // 降级红线：仅当 $since !== '' 时才绑定 ':since'，否则在原生预处理
        // （EMULATE_PREPARES=false）下多余命名参数会抛 column index out of range。
        // 帖子时间过滤用 topic_index.create_time（秒级 INTEGER），博客用 blogs.created_at（字符串）。
        $since = self::timeRangeSince($timeRange);
        $sinceTs = $since !== '' ? (int)\strtotime($since) : 0;
        $timeFilterBlog = '';   // 博客（created_at 字符串）
        $timeFilterIdx  = '';   // 帖子（create_time 秒级）
        $blogTimeParams = [];
        $idxTimeParams  = [];
        if ($since !== '') {
            $timeFilterBlog = ' AND created_at >= :since';
            $blogTimeParams[':since'] = $since;
            $timeFilterIdx = ' AND create_time >= :sinceTs';
            $idxTimeParams[':sinceTs'] = $sinceTs;
        }

        // 版块权限过滤：帖子读 main_index.topic_index（category_id），博客无版块概念
        $catFilterIdx = '';
        $catParams = [];
        if (!empty($allowedCategoryIds)) {
            $placeholders = [];
            foreach ($allowedCategoryIds as $i => $cid) {
                $p = ':lcat' . $i;
                $placeholders[] = $p;
                $catParams[$p] = (int)$cid;
            }
            $catFilterIdx = ' AND category_id IN (' . implode(',', $placeholders) . ')';
        }

        // 全局计数：thread 读 main_index.topic_index（退役 threads 表已弃用）；
        // blog 读 business.blogs（未分片，保留）。内容匹配：降级仅覆盖标题全文
        // （topic_index 无 content 列；正文匹配明确声明为降级能力）。
        $mi = \app\SplitDB\Schema::mainIndexDb();
        $miCntStmt = $mi->prepare(
            'SELECT COUNT(*) AS count FROM topic_index'
            . ' WHERE title LIKE :q1 AND status = 0 AND deleted_at IS NULL'
            . $catFilterIdx . $timeFilterIdx
        );
        $miCntStmt->execute(\array_merge([':q1' => $sq], $catParams, $idxTimeParams));
        $results['thread_count'] = (int)($miCntStmt->fetch(\PDO::FETCH_ASSOC)['count'] ?? 0);

        $cnt = $db->fetchOne('SELECT COUNT(*) AS count FROM blogs WHERE title LIKE :q1 OR content LIKE :q2' . $timeFilterBlog, \array_merge([':q1' => $sq, ':q2' => $sq], $blogTimeParams));
        $results['blog_count'] = (int)($cnt['count'] ?? 0);

        // —— 帖子列表：读 topic_index 定位 → getByIds + fillExternContent 装饰（与索引路径同口径）——
        if ($type === 'all' || $type === 'thread') {
            $threadLimit = ($type === 'thread') ? $perPage : 10;
            $toff = ($type === 'thread') ? $offset : 0;
            $miListStmt = $mi->prepare(
                'SELECT id FROM topic_index'
                . ' WHERE title LIKE :q1 AND status = 0 AND deleted_at IS NULL'
                . $catFilterIdx . $timeFilterIdx
                . ' ORDER BY create_time DESC, id DESC'
                . ' LIMIT ' . (int)$threadLimit . ' OFFSET ' . (int)$toff
            );
            $miListStmt->execute(\array_merge([':q1' => $sq], $catParams, $idxTimeParams));
            $ids = [];
            foreach ($miListStmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $ids[] = (int)$r['id'];
            }
            $threadModel = new \app\Models\Thread();
            $rows = $threadModel->fillExternContent($threadModel->getByIds($ids));
            // 保持与旧降级路径一致的 created_at DESC 顺序（getByIds 自身按置顶/最后回复排序）
            \usort($rows, fn($a, $b) => (($b['created_at'] ?? '') <=> ($a['created_at'] ?? '')) ?: (($b['id'] ?? 0) <=> ($a['id'] ?? 0)));
            foreach ($rows as &$t) {
                $t['highlighted_title'] = self::highlightTerms($t['title'] ?? '', $query);
                $t['content_snippet'] = \mb_substr(\strip_tags($t['content'] ?? ''), 0, 200);
            }
            unset($t);
            $results['threads'] = $rows;
        }

        // —— 博客列表（business.blogs 未分片，保留 LIKE）——
        if ($type === 'all' || $type === 'blog') {
            $blogLimit = ($type === 'blog') ? $perPage : 10;
            $boff = ($type === 'blog') ? $offset : 0;
            $rows = $db->fetchAll(
                'SELECT b.*, u.username, bc.name AS category_name FROM blogs b'
                . ' LEFT JOIN users u ON b.user_id = u.id'
                . ' LEFT JOIN blog_categories bc ON b.category_id = bc.id'
                . ' WHERE b.title LIKE :q1 OR b.content LIKE :q2' . $timeFilterBlog
                . ' ORDER BY b.created_at DESC'
                . ' LIMIT ' . (int)$blogLimit . ' OFFSET ' . (int)$boff,
                \array_merge([':q1' => $sq, ':q2' => $sq], $blogTimeParams)
            );
            foreach ($rows as &$b) {
                $b['highlighted_title'] = self::highlightTerms($b['title'] ?? '', $query);
                $b['content_snippet'] = \mb_substr(\strip_tags($b['content'] ?? ''), 0, 200);
            }
            unset($b);
            $results['blogs'] = $rows;
        }

        if ($type === 'all') $results['total'] = $results['thread_count'] + $results['blog_count'];
        elseif ($type === 'thread') $results['total'] = $results['thread_count'];
        else $results['total'] = $results['blog_count'];

        return $results;
    }

    /**
     * 高亮搜索关键词
     */
    public static function highlightTerms(string $text, string $query): string
    {
        if ($text === '' || $query === '') return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $terms = \preg_split('/\\s+/', $query);
        foreach ($terms as $term) {
            $term = \trim($term);
            if ($term === '') continue;
            $termEscaped = htmlspecialchars($term, ENT_QUOTES, 'UTF-8');
            $escaped = @\preg_replace('/(' . \preg_quote($termEscaped, '/') . ')/iu', '<mark class="search-highlight">$1</mark>', $escaped);
        }
        return $escaped;
    }
}
