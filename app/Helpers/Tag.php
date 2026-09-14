<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 标签管理 — 标签 CRUD、帖子标签关联、热门标签
 * @file app/Helpers/Tag.php
 * @package app\Helpers
 */

namespace app\Helpers;

class Tag
{
    /** @var array|null 运行时缓存，同请求内避免重复查库 */
    private static $popularCache = null;

    /**
     * 获取热门标签（含计数，运行时缓存）
     */
    public static function getPopular(int $limit = 20): array
    {
        if (self::$popularCache !== null) {
            return self::$popularCache;
        }
        $db = \app\Core\Database::getInstance();
        self::$popularCache = $db->fetchAll(
            'SELECT t.id, t.name, COUNT(tt.thread_id) as count FROM tags t
             LEFT JOIN thread_tags tt ON t.id = tt.tag_id
             GROUP BY t.id ORDER BY count DESC LIMIT ' . (int)$limit
        ) ?: [];
        return self::$popularCache;
    }

    /**
     * 获取所有标签（含计数）
     */
    public static function getAll(): array
    {
        $db = \app\Core\Database::getInstance();
        $rows = $db->fetchAll(
            'SELECT t.*, COUNT(tt.thread_id) as thread_count
             FROM tags t LEFT JOIN thread_tags tt ON t.id = tt.tag_id
             GROUP BY t.id ORDER BY thread_count DESC, t.name ASC'
        );
        return $rows ?: [];
    }

    /**
     * 获取帖子的标签
     */
    public static function getByThread(int $threadId): array
    {
        $db = \app\Core\Database::getInstance();
        $rows = $db->fetchAll(
            'SELECT t.* FROM tags t INNER JOIN thread_tags tt ON t.id = tt.tag_id WHERE tt.thread_id = :thread_id ORDER BY t.name',
            [':thread_id' => $threadId]
        );
        return $rows ?: [];
    }

    /**
     * 保存帖子标签（先删后加）
     * 注意：调用方需自行管理事务（PostController::create 等已在外层开启事务）
     */
    public static function saveForThread(int $threadId, $tagNames): void
    {
        $db = \app\Core\Database::getInstance();
        $db->query('DELETE FROM thread_tags WHERE thread_id = :thread_id', [':thread_id' => $threadId]);

        if (empty($tagNames)) return;

        if (is_string($tagNames)) $tagNames = \array_map('trim', \explode(',', $tagNames));

        $now = date('Y-m-d H:i:s');

        foreach ($tagNames as $name) {
            $name = \trim($name);
            // 标签名最大长度（后台「显示设置→长度限制」可配，下限固定 1）
            $maxTagLen = (int)\app\Helpers\Settings::get('limit_tag_name', '30');
            if (\mb_strlen($name) < 1 || \mb_strlen($name) > $maxTagLen) continue;

            $existing = $db->fetchOne('SELECT id FROM tags WHERE name = :name', [':name' => $name]);
            if ($existing) {
                $tagId = $existing['id'];
            } else {
                $db->query('INSERT INTO tags (name, created_at) VALUES (:name, :created_at)', [
                    ':name' => $name, ':created_at' => $now,
                ]);
                $tagId = $db->lastInsertId();
            }

            // SQLite 语法：INSERT OR IGNORE（兼容 3.33）
            $db->query('INSERT OR IGNORE INTO thread_tags (thread_id, tag_id) VALUES (:thread_id, :tag_id)', [
                ':thread_id' => $threadId, ':tag_id' => $tagId,
            ]);
        }
    }

    /**
     * 获取标签下的帖子（标签关联走 business，帖子详情经 Thread 模型读分片）
     */
    public static function getThreads(string $tagName, int $page = 1, int $perPage = 20): array
    {
        $db = \app\Core\Database::getInstance();
        $offset = ($page - 1) * $perPage;

        $tag = $db->fetchOne('SELECT * FROM tags WHERE name = :name', [':name' => $tagName]);
        if (!$tag) return ['threads' => [], 'tag' => null, 'total' => 0];

        $total = $db->fetchOne('SELECT COUNT(*) as cnt FROM thread_tags WHERE tag_id = :tag_id', [':tag_id' => $tag['id']]);

        // 取该标签下的主题 ID（分页）
        $ids = $db->fetchAll(
            'SELECT thread_id FROM thread_tags WHERE tag_id = :tag_id
             ORDER BY thread_id DESC
             LIMIT :limit OFFSET :offset',
            [':tag_id' => $tag['id'], ':limit' => $perPage, ':offset' => $offset]
        );
        $threadIds = array_map(function ($r) { return (int)$r['thread_id']; }, $ids);

        // 经 Thread 模型批量读分片索引（含用户/分类装饰，避免逐帖 find() 的 N+1）；按置顶/最新回复排序
        $thread = new \app\Models\Thread();
        $threads = $thread->getByIds($threadIds);
        usort($threads, function ($a, $b) {
            if ((int)$a['is_pinned'] !== (int)$b['is_pinned']) {
                return (int)$b['is_pinned'] - (int)$a['is_pinned'];
            }
            return strcmp((string)$b['last_reply_at'], (string)$a['last_reply_at']);
        });

        return ['threads' => $threads ?: [], 'tag' => $tag, 'total' => (int)($total['cnt'] ?? 0)];
    }

    /**
     * 渲染标签徽章
     */
    public static function renderBadges(array $tags, bool $linkable = true): string
    {
        if (empty($tags)) return '';

        $html = '<div class="tag-badges">';
        foreach ($tags as $tag) {
            $name = htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8');
            if ($linkable) {
                $html .= '<a href="/tag/' . (int)$tag['id'] . '" class="tag-badge">' . $name . '</a>';
            } else {
                $html .= '<span class="tag-badge">' . $name . '</span>';
            }
        }
        return $html . '</div>';
    }

    /**
     * 重建标签数据：清理孤儿关联、合并重复标签
     * 帖子已迁至 main_index（topic_index），business.sqlite 的 threads 表已退役（0 行），
     * 孤儿清理必须用 topic_index 校验帖子有效性，否则会误删全部标签关联。
     */
    public static function rebuild(): string
    {
        $db = \app\Core\Database::getInstance();

        // 有效帖子 ID 来自 main_index 主索引库（跨库无法 JOIN，先取 ID 列表再参数化删除）
        $validIds = \app\SplitDB\Schema::mainIndexDb()
            ->query('SELECT id FROM topic_index')
            ->fetchAll(\PDO::FETCH_COLUMN);
        $validIds = array_values(array_unique(array_map('intval', $validIds)));

        if (!empty($validIds)) {
            // 分批清理孤儿关联：
            //   ① 语义必须是「thread_id 不在有效集」的行才删——若直接对全集 NOT IN 分批，
            //      每批只含部分 ID，会让其它批之外的行全部被误删；
            //   ② SQLite 变量上限 32766，故先算出孤儿 ID 列表（现存 - 有效），再按 900/批 IN 删除。
            $existingIds = $db->query('SELECT DISTINCT thread_id FROM thread_tags')->fetchAll(\PDO::FETCH_COLUMN);
            $orphans = array_values(array_diff(
                array_map('intval', $existingIds),
                array_map('intval', $validIds)
            ));
            foreach (array_chunk($orphans, 900) as $chunk) {
                $marks = implode(',', array_fill(0, count($chunk), '?'));
                $db->query("DELETE FROM thread_tags WHERE thread_id IN ($marks)", $chunk);
            }
            // 删除无任何帖子引用的标签
            $db->query("DELETE FROM tags WHERE id NOT IN (SELECT DISTINCT tag_id FROM thread_tags)");
        }
        // 索引库为空（全新安装）时跳过清理，避免误删全部标签

        $duplicates = $db->fetchAll("SELECT name, MIN(id) as keep_id FROM tags GROUP BY name HAVING COUNT(*) > 1");
        foreach ($duplicates as $dup) {
            $db->query("UPDATE thread_tags SET tag_id = :keep WHERE tag_id IN (SELECT id FROM tags WHERE name = :name AND id != :keep)", [
                ':keep' => $dup['keep_id'], ':name' => $dup['name'],
            ]);
            $db->query("DELETE FROM tags WHERE name = :name AND id != :keep", [':name' => $dup['name']]);
        }
        $cleanCount = $db->fetchOne('SELECT COUNT(*) as cnt FROM thread_tags');
        $tagCount = $db->fetchOne('SELECT COUNT(*) as cnt FROM tags');
        return '标签重建完成！当前 ' . ($tagCount['cnt'] ?? 0) . ' 个标签、' . ($cleanCount['cnt'] ?? 0) . ' 条关联。';
    }

    /**
     * 后台标签管理操作（删除、重命名、新增）
     */
    public static function adminHandlePost(array $post): void
    {
        $db = \app\Core\Database::getInstance();
        $action = $post['action'] ?? '';
        if ($action === 'delete' && !empty($post['id'])) {
            $tagId = (int)$post['id'];
            $db->query('DELETE FROM thread_tags WHERE tag_id = :tid', [':tid' => $tagId]);
            $db->query('DELETE FROM tags WHERE id = :id', [':id' => $tagId]);
        } elseif ($action === 'rename' && !empty($post['tag_id'])) {
            $tagId = (int)$post['tag_id'];
            $newName = trim($post['new_name'] ?? '');
            $maxTagLen = (int)\app\Helpers\Settings::get('limit_tag_name', '30');
            if ($tagId > 0 && mb_strlen($newName) >= 1 && mb_strlen($newName) <= $maxTagLen) {
                try { $db->query('UPDATE tags SET name = :n WHERE id = :id', [':n' => $newName, ':id' => $tagId]); } catch (\Exception $e) { error_log('Tag rename error: ' . $e->getMessage()); }
            }
        } else {
            $name = trim($post['name'] ?? '');
            if ($name) {
                try { $db->query('INSERT INTO tags (name, created_at) VALUES (:n, :now)', [':n' => $name, ':now' => date('Y-m-d H:i:s')]); } catch (\Exception $e) { error_log('Tag create error: ' . $e->getMessage()); }
            }
        }
    }
}
