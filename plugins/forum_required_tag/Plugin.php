<?php
/**
 * FlintHub — 版块强制 Tag 插件主类
 * 建表（幂等）/ 版块 Tag 与标题前缀查询 / 提交校验 / 使用记录
 * @file plugins/forum_required_tag/Plugin.php
 * @package Plugin\ForumRequiredTag
 * @version 1.0.0
 */

namespace Plugin\ForumRequiredTag;

class Plugin
{
    /** 插件独立库连接（plugins/forum_required_tag/data/forum_required_tag.sqlite） */
    public static function db(): \PDO
    {
        return \app\Helpers\Plugin::db('forum_required_tag');
    }

    /** 表 1：版块 × 强制 Tag 绑定 DDL（幂等） */
    public static function ddlTags(): string
    {
        return "CREATE TABLE IF NOT EXISTS frt_forum_tags (
            id INTEGER PRIMARY KEY,
            forum_id INTEGER NOT NULL,
            tag_name TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            enabled INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            UNIQUE(forum_id, tag_name)
        )";
    }

    /** 表 2：版块 × 标题前缀绑定 DDL（幂等，prefix 存储不含方括号，展示/校验为 [xxx]） */
    public static function ddlPrefixes(): string
    {
        return "CREATE TABLE IF NOT EXISTS frt_forum_prefixes (
            id INTEGER PRIMARY KEY,
            forum_id INTEGER NOT NULL,
            prefix TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            enabled INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            UNIQUE(forum_id, prefix)
        )";
    }

    /** 表 3：发帖/编辑使用记录 DDL（幂等） */
    public static function ddlPostTags(): string
    {
        return "CREATE TABLE IF NOT EXISTS frt_post_tags (
            id INTEGER PRIMARY KEY,
            thread_id INTEGER NOT NULL,
            forum_id INTEGER NOT NULL,
            tag_name TEXT NOT NULL DEFAULT '',
            prefix TEXT NOT NULL DEFAULT '',
            user_id INTEGER NOT NULL,
            created_at TEXT NOT NULL
        )";
    }

    /** 建表 + 索引（幂等），供 activate() 与 init_after 钩子共用 */
    public static function ensureSchema(): void
    {
        \app\Helpers\Plugin::ensureSchema('forum_required_tag', self::ddlTags());
        \app\Helpers\Plugin::ensureSchema('forum_required_tag', self::ddlPrefixes());
        \app\Helpers\Plugin::ensureSchema('forum_required_tag', self::ddlPostTags());
        $db = self::db();
        $db->exec('CREATE INDEX IF NOT EXISTS idx_frt_forum_tags ON frt_forum_tags(forum_id, enabled)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_frt_forum_prefixes ON frt_forum_prefixes(forum_id, enabled)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_frt_post_tags ON frt_post_tags(thread_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_frt_post_tags_forum ON frt_post_tags(forum_id, created_at)');
    }

    /** 激活：建表 + 索引（幂等） */
    public static function activate(): bool
    {
        self::ensureSchema();
        return true;
    }

    /** 禁用：不清表（uninstall 才删） */
    public static function deactivate(): bool
    {
        return true;
    }

    /** 卸载：删 3 张表（先独立库表，缓存由系统重建） */
    public static function uninstall(): void
    {
        $db = self::db();
        $db->exec('DROP TABLE IF EXISTS frt_post_tags');
        $db->exec('DROP TABLE IF EXISTS frt_forum_prefixes');
        $db->exec('DROP TABLE IF EXISTS frt_forum_tags');
    }

    // ========================================================================
    //  查询方法（运行时静态缓存，同请求不重复查库）
    // ========================================================================

    /** @var array<int, array> 请求级缓存：forum_id → 启用 Tag 列表 */
    private static array $tagsCache = [];
    /** @var array<int, array> 请求级缓存：forum_id → 启用前缀列表 */
    private static array $prefixesCache = [];

    /** 获取版块启用的强制 Tag 列表 */
    public static function getForumTags(int $forumId): array
    {
        if ($forumId <= 0) return [];
        if (isset(self::$tagsCache[$forumId])) return self::$tagsCache[$forumId];
        $stmt = self::db()->prepare(
            'SELECT id, tag_name, sort_order FROM frt_forum_tags
             WHERE forum_id = :fid AND enabled = 1 ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([':fid' => $forumId]);
        return self::$tagsCache[$forumId] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** 获取版块启用的标题前缀列表（prefix 字段不含方括号） */
    public static function getForumPrefixes(int $forumId): array
    {
        if ($forumId <= 0) return [];
        if (isset(self::$prefixesCache[$forumId])) return self::$prefixesCache[$forumId];
        $stmt = self::db()->prepare(
            'SELECT id, prefix, sort_order FROM frt_forum_prefixes
             WHERE forum_id = :fid AND enabled = 1 ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([':fid' => $forumId]);
        return self::$prefixesCache[$forumId] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** 该版块是否配置了任何强制项（Tag 或前缀，任一生效即 true） */
    public static function isForumConstrained(int $forumId): bool
    {
        return !empty(self::getForumTags($forumId)) || !empty(self::getForumPrefixes($forumId));
    }

    /**
     * 提交校验：返回 null=通过，字符串=错误文案（已多语言化，前台用 *_act 键）
     *
     * @param int    $forumId  目标版块 ID
     * @param string $tagName  提交的 Tag（逗号分隔串，含任一预设 Tag 即通过，不要求放第一个）
     * @param string $title    提交的标题
     */
    public static function validateSubmit(int $forumId, string $tagName, string $title): ?string
    {
        $tags = self::getForumTags($forumId);
        $prefixes = self::getForumPrefixes($forumId);

        // 1. 强制 Tag：版块配置了列表则提交的 tags 中必须至少命中一个预设 Tag（任一位置均可）
        if (!empty($tags)) {
            $submitted = \array_values(\array_filter(\array_map('trim', \explode(',', $tagName))));
            $allowed = \array_column($tags, 'tag_name');
            if (empty($submitted)) {
                return \app\Helpers\I18n::get('plugin.forum_required_tag.err_tag_act'); // 请选择一个 Tag 后再发布
            }
            $hit = false;
            foreach ($submitted as $t) {
                if (\in_array($t, $allowed, true)) { $hit = true; break; }
            }
            if (!$hit) {
                return \app\Helpers\I18n::get('plugin.forum_required_tag.err_tag_invalid_act'); // 请选择预设 Tag
            }
        }

        // 2. 标题前缀：版块配置了列表则标题必须以某前缀开头
        if (!empty($prefixes)) {
            $title = \trim($title);
            $hit = false;
            foreach ($prefixes as $p) {
                if (\str_starts_with($title, '[' . $p['prefix'] . ']')) { $hit = true; break; }
            }
            if (!$hit) {
                return \app\Helpers\I18n::get('plugin.forum_required_tag.err_prefix_act'); // 标题需带预设前缀
            }
        }

        return null; // 通过
    }

    /** 记录使用（发帖/编辑成功后调用；thread_id 此时已存在） */
    public static function logPostTag(int $threadId, int $forumId, string $tagName, string $prefix, int $userId): void
    {
        $stmt = self::db()->prepare(
            'INSERT INTO frt_post_tags (thread_id, forum_id, tag_name, prefix, user_id, created_at)
             VALUES (:tid, :fid, :tag, :pfx, :uid, :now)'
        );
        $stmt->execute([
            ':tid' => $threadId, ':fid' => $forumId, ':tag' => $tagName,
            ':pfx' => $prefix, ':uid' => $userId, ':now' => \date('Y-m-d H:i:s'),
        ]);
    }

    /** 删除某帖的使用记录（编辑重记前清理旧记录） */
    public static function deletePostTag(int $threadId): void
    {
        $stmt = self::db()->prepare('DELETE FROM frt_post_tags WHERE thread_id = :tid');
        $stmt->execute([':tid' => $threadId]);
    }

    // ========================================================================
    //  后台管理方法（AdminController 使用）
    // ========================================================================

    /** 版块的全部 Tag（含禁用），后台列表用 */
    public static function listForumTags(int $forumId): array
    {
        $stmt = self::db()->prepare(
            'SELECT * FROM frt_forum_tags WHERE forum_id = :fid ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([':fid' => $forumId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** 版块的全部前缀（含禁用），后台列表用 */
    public static function listForumPrefixes(int $forumId): array
    {
        $stmt = self::db()->prepare(
            'SELECT * FROM frt_forum_prefixes WHERE forum_id = :fid ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([':fid' => $forumId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** 新增 Tag（重复返回 false） */
    public static function addTag(int $forumId, string $tagName): bool
    {
        $name = \trim($tagName);
        if ($forumId <= 0 || $name === '' || \mb_strlen($name) > 30) return false;
        $stmt = self::db()->prepare(
            'INSERT OR IGNORE INTO frt_forum_tags (forum_id, tag_name, sort_order, created_at)
             VALUES (:fid, :name, 0, :now)'
        );
        $stmt->execute([':fid' => $forumId, ':name' => $name, ':now' => \date('Y-m-d H:i:s')]);
        return $stmt->rowCount() > 0;
    }

    /** 新增前缀（重复返回 false；只存前缀名不含方括号） */
    public static function addPrefix(int $forumId, string $prefix): bool
    {
        $name = \trim($prefix);
        // 容忍用户输入 [求助] 形式：剥掉方括号再存
        if (\strlen($name) >= 2 && $name[0] === '[' && \substr($name, -1) === ']') {
            $name = \trim(\substr($name, 1, -1));
        }
        if ($forumId <= 0 || $name === '' || \mb_strlen($name) > 20) return false;
        $stmt = self::db()->prepare(
            'INSERT OR IGNORE INTO frt_forum_prefixes (forum_id, prefix, sort_order, created_at)
             VALUES (:fid, :name, 0, :now)'
        );
        $stmt->execute([':fid' => $forumId, ':name' => $name, ':now' => \date('Y-m-d H:i:s')]);
        return $stmt->rowCount() > 0;
    }

    /** 删除 Tag / 前缀（$table ∈ frt_forum_tags | frt_forum_prefixes，白名单限定） */
    public static function deleteItem(string $table, int $id): void
    {
        if (!\in_array($table, ['frt_forum_tags', 'frt_forum_prefixes'], true)) return;
        $stmt = self::db()->prepare("DELETE FROM {$table} WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    /** 切换启用状态（$table 白名单限定） */
    public static function toggleItem(string $table, int $id): void
    {
        if (!\in_array($table, ['frt_forum_tags', 'frt_forum_prefixes'], true)) return;
        $stmt = self::db()->prepare("UPDATE {$table} SET enabled = 1 - enabled WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    /** 复制配置：把 $fromForum 的启用配置复制到 $toForum（重复项跳过） */
    public static function copyConfig(int $fromForum, int $toForum): array
    {
        if ($fromForum <= 0 || $toForum <= 0 || $fromForum === $toForum) {
            return ['tags' => 0, 'prefixes' => 0];
        }
        $db = self::db();
        $copiedTags = 0;
        $copiedPrefixes = 0;
        $now = \date('Y-m-d H:i:s');
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT OR IGNORE INTO frt_forum_tags (forum_id, tag_name, sort_order, enabled, created_at)
                 SELECT :to, tag_name, sort_order, enabled, :now FROM frt_forum_tags
                 WHERE forum_id = :from'
            );
            $stmt->execute([':to' => $toForum, ':from' => $fromForum, ':now' => $now]);
            // SQLite 的 changes() 对 INSERT OR IGNORE 跳过的重复行不计入（rowCount 可能不准），
            // 且必须在同一条语句 execute 后、下一条语句执行前立即取
            $copiedTags = $db->changes();

            $stmt = $db->prepare(
                'INSERT OR IGNORE INTO frt_forum_prefixes (forum_id, prefix, sort_order, enabled, created_at)
                 SELECT :to, prefix, sort_order, enabled, :now FROM frt_forum_prefixes
                 WHERE forum_id = :from'
            );
            $stmt->execute([':to' => $toForum, ':from' => $fromForum, ':now' => $now]);
            $copiedPrefixes = $db->changes();

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            \error_log('forum_required_tag copyConfig error: ' . $e->getMessage());
        }
        return ['tags' => $copiedTags, 'prefixes' => $copiedPrefixes];
    }

    /** 使用记录（分页；$forumId>0 时按版块筛选） */
    public static function listRecords(int $forumId, int $page = 1, int $perPage = 20): array
    {
        $page = \max(1, $page);
        $perPage = \max(1, \min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $db = self::db();

        if ($forumId > 0) {
            $total = (int)$db->query(
                'SELECT COUNT(*) FROM frt_post_tags WHERE forum_id = ' . (int)$forumId
            )->fetchColumn();
            $stmt = $db->prepare(
                'SELECT * FROM frt_post_tags WHERE forum_id = :fid
                 ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset'
            );
            $stmt->bindValue(':fid', $forumId, \PDO::PARAM_INT);
        } else {
            $total = (int)$db->query('SELECT COUNT(*) FROM frt_post_tags')->fetchColumn();
            $stmt = $db->prepare(
                'SELECT * FROM frt_post_tags ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset'
            );
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return ['rows' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'total' => $total];
    }

    /** 删除使用记录 */
    public static function deleteRecord(int $id): void
    {
        $stmt = self::db()->prepare('DELETE FROM frt_post_tags WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
