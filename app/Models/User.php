<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 用户模型 — 用户注册/登录、资料管理、用户组/权限
 * @file app/Models/User.php
 * @package app\Models
 */

namespace app\Models;

use app\Core\Model;

class User extends Model
{
    protected $table = 'users';
    /** 可更新列白名单：仅 updateLoginTime 走基类 update() */
    protected $fillable = ['last_login'];

    public function findByUsername($username)
    {
        return $this->findWhere('username = :username', [':username' => $username]);
    }

    public function findByEmail($email)
    {
        return $this->findWhere('email = :email', [':email' => $email]);
    }

    public function updateLoginTime($id, $time)
    {
        return $this->update($id, ['last_login' => $time]);
    }

    public function search($keyword, $limit = 20)
    {
        // 转义 LIKE 通配符（\ % _ → \\ \% \_），与 buildAdminSearchWhere 同款；
        // 绑定转义后的 $kw 并配合 ESCAPE '\'，防用户输入 %/_ 导致全表/模糊过量匹配
        $kw = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string)$keyword) . '%';
        // 不返回 email/role/status/group_id 等敏感或权限字段（防信息泄露）
        return $this->query(
            "SELECT id, username, avatar, signature, created_at, level, points FROM {$this->table} WHERE username LIKE :kw ESCAPE '\\' LIMIT " . (int)$limit,
            [':kw' => $kw]
        );
    }

    /**
     * 统计用户名匹配数（独立 COUNT，不受 search() 的 LIMIT 截断影响）
     * 供搜索页"用户"Tab 做真实总计数（此前用 count(search(…,50)) 会把 >50 的匹配截断成错误总数）
     */
    public function countSearch(string $keyword): int
    {
        $kw = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $keyword) . '%';
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM {$this->table} WHERE username LIKE :kw ESCAPE '\\'",
            [':kw' => $kw]
        );
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * 后台用户查询（列表）—— 动态 WHERE + 白名单校验 + 参数绑定
     *
     * $filters 支持：q(关键词)、field(username/email/all)、role(user/admin)、status(active/banned)
     * 返回后台列表所需完整字段（含 email/role/status 等敏感字段，仅限后台使用）
     */
    public function adminSearch(array $filters, int $limit, int $offset): array
    {
        list($where, $params) = $this->buildAdminSearchWhere($filters);
        $sql = "SELECT id, username, email, role, status, group_id, post_count, points, level, created_at, last_login
                FROM {$this->table}";
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        return $this->query($sql, $params);
    }

    /**
     * 后台用户查询（总数）—— 与 adminSearch 复用同一 WHERE 子句
     */
    public function countAdminSearch(array $filters): int
    {
        list($where, $params) = $this->buildAdminSearchWhere($filters);
        $sql = "SELECT COUNT(*) as cnt FROM {$this->table}";
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $result = $this->queryOne($sql, $params);
        return (int)($result['cnt'] ?? 0);
    }

    /**
     * 后台用户查询 WHERE 拼接（私有，参数绑定 + 白名单）
     *
     * @return array [$whereSql, $params]
     */
    protected function buildAdminSearchWhere(array $filters): array
    {
        $where = [];
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        $field = (string)($filters['field'] ?? 'all');
        $role = (string)($filters['role'] ?? '');
        $status = (string)($filters['status'] ?? '');

        if ($q !== '') {
            // LIKE 通配符转义（\ % _ → \\ \% \_），配合 ESCAPE '\' 防用户输入 % 导致全表匹配
            $kw = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
            $params[':kw1'] = $kw;
            if ($field === 'username') {
                $where[] = "username LIKE :kw1 ESCAPE '\\'";
            } elseif ($field === 'email') {
                $where[] = "email LIKE :kw1 ESCAPE '\\'";
            } else { // all：用户名或邮箱
                $where[] = "(username LIKE :kw1 ESCAPE '\\' OR email LIKE :kw2 ESCAPE '\\')";
                $params[':kw2'] = $kw;
            }
        }

        // 白名单校验：非白名单值一律忽略（不拼入 SQL）
        if (in_array($role, ['user', 'admin'], true)) {
            $where[] = 'role = :role';
            $params[':role'] = $role;
        }
        if (in_array($status, ['active', 'banned'], true)) {
            $where[] = 'status = :status';
            $params[':status'] = $status;
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * 更新用户资料
     */
    public function updateProfile(int $id, string $email, string $signature): void
    {
        $this->execute(
            'UPDATE users SET email = :email, signature = :sig WHERE id = :id',
            [':email' => $email, ':sig' => $signature, ':id' => $id]
        );
    }

    /**
     * 递增用户发帖数
     */
    public function incrementPostCount(int $id): void
    {
        $this->execute('UPDATE users SET post_count = post_count + 1 WHERE id = :id', [':id' => $id]);
    }

    /**
     * 递减用户发帖数（软删主题时同步，防负保护）
     */
    public function decrementPostCount(int $id): void
    {
        $this->execute('UPDATE users SET post_count = MAX(post_count - 1, 0) WHERE id = :id', [':id' => $id]);
    }

    /**
     * 更新用户密码
     */
    public function updatePassword(int $id, string $password): void
    {
        $this->execute(
            'UPDATE users SET password = :pwd WHERE id = :id',
            [':pwd' => password_hash($password, PASSWORD_DEFAULT), ':id' => $id]
        );
    }

    /**
     * 更新用户头像
     */
    public function updateAvatar(int $id, string $avatar): void
    {
        $this->execute(
            'UPDATE users SET avatar = :avatar WHERE id = :id',
            [':avatar' => $avatar, ':id' => $id]
        );
    }

    /**
     * 生成确定性默认 SVG 头像，返回相对路径（assets/uploads/seed_avatars/{id}.svg）
     *
     * 复用 cli/seed_content.php::writeAvatar() 的生成公式与种子（SEED=20260814 + id + 12345），
     * 保证与既有 seed 头像同风格；确定性：同一 id 永远同一头像；已存在文件直接复用不重写。
     *
     * 安全：仅纯几何 + <text> 首字，首字经 htmlspecialchars 转义，不渲染任何用户输入 HTML。
     */
    public static function defaultAvatarPath(int $id, string $username): string
    {
        // 与 cli/seed_content.php rng($id + 12345) 同源：mt_srand(SEED + id + 12345)
        mt_srand(20260814 + $id + 12345);
        $h1 = mt_rand(0, 360);
        $h2 = ($h1 + mt_rand(40, 120)) % 360;
        $c1 = sprintf('hsl(%d,%d%%,%d%%)', $h1, mt_rand(45, 75), mt_rand(40, 60));
        $c2 = sprintf('hsl(%d,%d%%,%d%%)', $h2, mt_rand(45, 75), mt_rand(55, 75));
        $letter = mb_substr($username, 0, 1, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96">'
            . '<rect width="96" height="96" rx="14" fill="' . $c1 . '"/>'
            . '<circle cx="' . mt_rand(20, 76) . '" cy="' . mt_rand(16, 60) . '" r="' . mt_rand(8, 26) . '" fill="' . $c2 . '" opacity="0.55"/>'
            . '<text x="48" y="62" font-size="40" font-family="sans-serif" font-weight="bold" text-anchor="middle" fill="#ffffff">'
            . htmlspecialchars($letter, ENT_QUOTES, 'UTF-8') . '</text></svg>';

        $dir = rtrim(\UPLOAD_PATH, '/\\') . '/seed_avatars';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $file = $dir . '/' . $id . '.svg';
        if (!is_file($file)) {
            @file_put_contents($file, $svg, LOCK_EX);
        }
        return 'seed_avatars/' . $id . '.svg';
    }

    /**
     * 获取用户统计信息（帖子/回复/浏览量改读 main_index + reply_index）
     */
    public function getStats(int $userId): array
    {
        $mi = \app\SplitDB\Schema::mainIndexDb();
        $stmt = $mi->prepare(
            'SELECT COUNT(*) as total_threads,
                    COALESCE(SUM(view_count), 0) as total_views
             FROM topic_index WHERE uid = :uid AND status = 0'
        );
        $stmt->execute([':uid' => (int)$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $stmt = $mi->prepare('SELECT COUNT(*) as total_replies FROM reply_index WHERE uid = :uid AND status = 0');
        $stmt->execute([':uid' => (int)$userId]);
        $replies = (int)$stmt->fetchColumn();

        $blogs = (int)($this->queryOne('SELECT COUNT(*) as cnt FROM blogs WHERE user_id = :uid', [':uid' => (int)$userId])['cnt'] ?? 0);

        return [
            'total_threads' => (int)($row['total_threads'] ?? 0),
            'total_replies' => $replies,
            'total_views'   => (int)($row['total_views'] ?? 0),
            'total_blogs'   => $blogs,
        ];
    }

    /**
     * 获取用户的帖子列表（读 main_index + 分类名）
     */
    public function getThreads(int $userId, int $limit = 10): array
    {
        $mi = \app\SplitDB\Schema::mainIndexDb();
        $stmt = $mi->prepare(
            'SELECT * FROM topic_index WHERE uid = :uid AND status = 0 AND deleted_at IS NULL
             ORDER BY create_time DESC, id DESC LIMIT ' . (int)$limit
        );
        $stmt->execute([':uid' => (int)$userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // 批量补分类名
        $cids = array_values(array_unique(array_map(fn($r) => (int)$r['category_id'], $rows)));
        $cats = [];
        if ($cids) {
            $marks = implode(',', array_fill(0, count($cids), '?'));
            foreach ($this->db->fetchAll("SELECT id, name FROM categories WHERE id IN ({$marks})", $cids) as $c) {
                $cats[(int)$c['id']] = $c['name'];
            }
        }

        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'id'            => (int)$r['id'],
                'category_id'   => (int)$r['category_id'],
                'category_name' => $cats[(int)$r['category_id']] ?? '',
                'title'         => $r['title'],
                'view_count'    => (int)$r['view_count'],
                'reply_count'   => (int)$r['reply_count'],
                'is_pinned'     => (int)$r['is_pinned'],
                'is_highlighted'=> (int)$r['is_highlighted'],
                'color'         => $r['color'] ?? '',
                'reply_to_view' => (int)($r['reply_to_view'] ?? 0),
                'created_at'    => $r['create_time'] ? date('Y-m-d H:i:s', (int)$r['create_time']) : null,
                'last_reply_at' => $r['last_reply_time'] ? date('Y-m-d H:i:s', (int)$r['last_reply_time']) : null,
            ];
        }
        return $result;
    }

    /**
     * 获取用户的博客列表
     */
    public function getBlogs(int $userId, int $limit = 10): array
    {
        return $this->query(
            'SELECT b.*, c.name as category_name FROM blogs b
             LEFT JOIN blog_categories c ON b.category_id = c.id
             WHERE b.user_id = :uid
             ORDER BY b.created_at DESC LIMIT ' . (int)$limit,
            [':uid' => $userId]
        );
    }

    /**
     * 按用户名或邮箱查找用户
     */
    public function findByUsernameOrEmail(string $username, string $email): ?array
    {
        return $this->queryOne(
            'SELECT id, email_verified FROM users WHERE username = :u OR email = :e',
            [':u' => $username, ':e' => $email]
        );
    }

    /**
     * 设置邮箱验证 Token
     */
    public function setVerifyToken(int $userId, string $token): void
    {
        $this->execute(
            'UPDATE users SET email_verify_token = :token, email_verify_token_sent_at = :now WHERE id = :id',
            [':token' => hash('sha256', $token), ':id' => $userId, ':now' => date('Y-m-d H:i:s')]
        );
    }

    /**
     * 批量取用户名：插件侧名称补全统一走 Model，不直查核心库 users 表。
     * 请求级静态缓存，同请求内重复查询不落库；返回 [id => username]。
     * @param int[] $ids
     * @return array<int,string>
     */
    public static function batchGetNames(array $ids): array
    {
        $users = self::batchGetUsers($ids);
        $out = [];
        foreach ($users as $id => $u) {
            $out[$id] = (string)$u['username'];
        }
        return $out;
    }

    /**
     * 批量取用户展示信息（用户名+头像+等级）：请求级缓存，返回 [id => ['username','avatar','level']]。
     * 供 dice/qa/quiz_duel/user_profile 等需要头像/等级的插件批量补全使用。
     * @param int[] $ids
     * @return array<int,array{username:string,avatar:string,level:int}>
     */
    public static function batchGetUsers(array $ids): array
    {
        static $cache = [];
        $ids = \array_values(\array_unique(\array_filter(\array_map('intval', $ids), fn($v) => $v > 0)));
        if (empty($ids)) return [];

        $miss = [];
        foreach ($ids as $id) {
            if (!\array_key_exists($id, $cache)) $miss[] = $id;
        }
        if (!empty($miss)) {
            try {
                $marks = \implode(',', \array_fill(0, \count($miss), '?'));
                $rows = \app\Core\Database::getInstance()->fetchAll(
                    'SELECT id, username, avatar, level FROM users WHERE id IN (' . $marks . ')',
                    $miss
                );
                foreach ($rows as $r) {
                    $cache[(int)$r['id']] = [
                        'username' => (string)($r['username'] ?? ''),
                        'avatar'   => (string)($r['avatar'] ?? ''),
                        'level'    => (int)($r['level'] ?? 0),
                    ];
                }
            } catch (\Throwable $e) {
                \error_log('User::batchGetUsers error: ' . $e->getMessage());
            }
        }

        $out = [];
        foreach ($ids as $id) {
            if (isset($cache[$id])) $out[$id] = $cache[$id];
        }
        return $out;
    }

    /**
     * 批量按用户名查用户 ID：插件 @提及 等反向批量查询统一走 Model，不直查核心库。
     * 请求级静态缓存，同请求内重复查询不落库；返回 [username => id]（仅命中已存在用户）。
     * @param string[] $names
     * @return array<string,int>
     */
    public static function batchGetIdsByNames(array $names): array
    {
        static $cache = [];
        $names = \array_values(\array_unique(\array_filter(\array_map('strval', $names), fn($v) => $v !== '')));
        if (empty($names)) return [];

        $miss = [];
        foreach ($names as $n) {
            if (!\array_key_exists($n, $cache)) $miss[] = $n;
        }
        if (!empty($miss)) {
            try {
                $marks = \implode(',', \array_fill(0, \count($miss), '?'));
                $rows = \app\Core\Database::getInstance()->fetchAll(
                    'SELECT id, username FROM users WHERE username IN (' . $marks . ')',
                    $miss
                );
                foreach ($rows as $r) {
                    $cache[(string)$r['username']] = (int)$r['id'];
                }
            } catch (\Throwable $e) {
                \error_log('User::batchGetIdsByNames error: ' . $e->getMessage());
            }
        }

        $out = [];
        foreach ($names as $n) {
            if (isset($cache[$n])) $out[$n] = $cache[$n];
        }
        return $out;
    }

    /**
     * 标记邮箱已验证
     */
    public function markEmailVerified(int $userId): void
    {
        $this->execute(
            'UPDATE users SET email_verified = 1, email_verify_token = NULL WHERE id = :id',
            [':id' => $userId]
        );
    }

    /**
     * 创建用户
     */
    public function create(string $username, string $password, string $email): int
    {
        $db = \app\Core\Database::getInstance();
        $defaultGroup = $db->fetchOne("SELECT id FROM user_groups WHERE is_default = 1");
        $groupId = (int)($defaultGroup['id'] ?? 1);

        $this->insert([
            'username' => $username,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'email' => $email,
            'group_id' => $groupId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * 获取管理员总数
     */
    public static function getAdminCount(): int
    {
        $db = \app\Core\Database::getInstance();
        $r = $db->fetchOne("SELECT COUNT(*) as cnt FROM users WHERE role = 'admin'");
        return (int)($r['cnt'] ?? 0);
    }

    /**
     * 管理员更新用户资料（含密码可选）
     */
    public function updateByAdmin(int $id, array $data, string $password = ''): void
    {
        $sql = 'UPDATE users SET role = :r, email = :e, status = :s, points = :p, level = :l, group_id = :gid';
        $params = [
            ':r' => $data['role'], ':e' => $data['email'], ':s' => $data['status'],
            ':p' => $data['points'], ':l' => $data['level'], ':gid' => $data['group_id'] ?? 1, ':id' => $id,
        ];
        if ($password !== '') {
            $sql .= ', password = :pw';
            $params[':pw'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id = :id';
        $this->execute($sql, $params);
    }

    /**
     * 仅更新用户组：封禁/解封等插件侧组迁移统一走 Model，不绕模型直写 users 表。
     * 保持 users 表写入入口收敛在 Model 层，便于后续加缓存/审计而不遗漏调用点。
     */
    public function updateGroupId(int $id, int $groupId): void
    {
        $this->execute(
            'UPDATE users SET group_id = :g WHERE id = :u',
            [':g' => (int)$groupId, ':u' => (int)$id]
        );
    }

    /**
     * 删除用户及其所有关联数据（级联清理，分片感知）
     *
     * 跨库非原子：业务库/分片桶/main_index/extern 文件分属不同 SQLite，
     * 无法单事务。改为「分阶段幂等收敛」——
     *   阶段 A：收集待清理清单（threads/replies/blogs/attachments）；
     *   阶段 B：先清理分片桶 + main_index + extern 文件（Thread::hardDelete 级联 + 兜底回复清理）；
     *   阶段 C：最后在业务库单事务内清理业务表并删除用户行。
     * 任一步异常：清单幂等，重跑收敛；异常路径把未完成清单写入 error_log + 审计表，
     * 抛出"可重跑"提示（避免跨库不一致后无人知晓、无法恢复）。
     *
     * @return int 删除的附件文件数
     */
    public function deleteWithCascade(int $uid): int
    {
        $db = $this->db;
        $mi = \app\SplitDB\Schema::mainIndexDb();

        $fileCount = 0;
        $threadIds = [];
        $replyIds = [];

        try {
            // ============ 阶段 A：收集待清理清单 ============
            // 该用户的主题 ID（分片索引）与博客 ID
            $stmt = $mi->prepare('SELECT id FROM topic_index WHERE uid = :id');
            $stmt->execute([':id' => (int)$uid]);
            $threadIds = array_map('intval', array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'id'));
            $blogIds = array_map('intval', array_column($db->fetchAll('SELECT id FROM blogs WHERE user_id = :id', [':id' => $uid]), 'id'));

            $threadPlaceholder = !empty($threadIds) ? implode(',', array_fill(0, count($threadIds), '?')) : '0';
            $blogPlaceholder = !empty($blogIds) ? implode(',', array_fill(0, count($blogIds), '?')) : '0';

            // 该用户的回复 ID（自己发表 + 其主题下的全部回复）
            $stmt = $mi->prepare('SELECT id FROM reply_index WHERE uid = :uid');
            $stmt->execute([':uid' => (int)$uid]);
            $replyIds = array_merge($replyIds, array_map('intval', array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'id')));
            if ($threadPlaceholder !== '0') {
                $stmt = $mi->prepare("SELECT id FROM reply_index WHERE pid IN ({$threadPlaceholder})");
                $stmt->execute($threadIds);
                $replyIds = array_merge($replyIds, array_map('intval', array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'id')));
            }
            $replyIds = array_values(array_unique($replyIds));
            $replyPlaceholder = !empty($replyIds) ? implode(',', array_fill(0, count($replyIds), '?')) : '0';

            // ============ 阶段 B：分片清理（幂等，先桶/索引/文件，最后才删用户行） ============
            // 1) 用户自建主题：hardDelete 级联清理（附件/回复/extern/桶行/索引行/倒排索引/统计）
            $thread = new Thread();
            foreach ($threadIds as $tid) {
                $thread->hardDelete((int)$tid);
            }

            // 2) 兜底：该用户在「他人主题」下发表的回复（用户自建主题的回复已由
            //    Thread::hardDelete 级联删除；此处清理其余 reply_index 中 uid=被删用户的残留行，
            //    含桶 reply 行 + extern 正文文件 + reply_index 行，并同步递减全站回复计数）
            $stmt = $mi->prepare('SELECT id, bucket_path FROM reply_index WHERE uid = :uid');
            $stmt->execute([':uid' => (int)$uid]);
            $leftReplyRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $orphanTotal = 0;
            $byBucket = [];
            foreach ($leftReplyRows as $rr) {
                $byBucket[(string)$rr['bucket_path']][] = (int)$rr['id'];
            }
            $dataPath = \app\SplitDB\ShardRouter::dataPath();
            foreach ($byBucket as $bucketPath => $ids) {
                $bdb = \app\SplitDB\Schema::bucketFromPath($bucketPath);
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $repRows = $bdb->prepare("SELECT id, extern_path FROM reply WHERE id IN ({$marks})");
                $repRows->execute($ids);
                foreach ($repRows->fetchAll(\PDO::FETCH_ASSOC) as $rep) {
                    if (!empty($rep['extern_path'])) {
                        \app\SplitDB\ExternStorage::delete($dataPath, (string)$rep['extern_path'], (int)$rep['id'], 'reply');
                    }
                }
                $bdb->prepare("DELETE FROM reply WHERE id IN ({$marks})")->execute($ids);
                $mi->prepare("DELETE FROM reply_index WHERE id IN ({$marks})")->execute($ids);
                $orphanTotal += count($ids);
            }
            if ($orphanTotal > 0) {
                \app\Helpers\Settings::runtimeDecr('total_posts', $orphanTotal);
            }

            // ============ 阶段 C：业务库单事务（幂等；最后删除用户行） ============
            $db->begin();
            try {
                // 1. 附件记录 + 文件（已由 hardDelete 清理过主题附件的文件，此处兜底其余归属）
                $attachments = $db->fetchAll(
                    "SELECT filename FROM attachments WHERE user_id = ? OR thread_id IN ({$threadPlaceholder})",
                    array_merge([$uid], $threadIds)
                );
                foreach ($attachments as $att) {
                    // 与 Thread::hardDelete 同款文件名白名单（防 path 穿越），
                    // attachments.filename 仅来自 bin2hex(random_bytes).ext，但防御纵深保持一致
                    $fn = (string)($att['filename'] ?? '');
                    if (\preg_match('/^[a-zA-Z0-9_.\-]+$/', $fn) !== 1) {
                        \error_log('User::deleteWithCascade 跳过非法附件文件名: ' . $fn);
                        continue;
                    }
                    $f = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . $fn;
                    if (file_exists($f)) { @unlink($f); $fileCount++; }
                }
                $db->query("DELETE FROM attachments WHERE user_id = ? OR thread_id IN ({$threadPlaceholder})",
                    array_merge([$uid], $threadIds));

                // 2. 投票 / 标签 / 私信 / 博客评论 / 博客 / 在线 / 查看记录
                $db->query("DELETE FROM thread_votes WHERE thread_id IN ({$threadPlaceholder})", $threadIds);
                $db->query("DELETE FROM post_votes WHERE post_id IN ({$replyPlaceholder})", $replyIds);
                $db->query("DELETE FROM thread_tags WHERE thread_id IN ({$threadPlaceholder})", $threadIds);
                $db->query('DELETE FROM messages WHERE sender_id = :id OR receiver_id = :id', [':id' => $uid]);
                $db->query("DELETE FROM blog_comments WHERE blog_id IN ({$blogPlaceholder})", $blogIds);
                $db->query('DELETE FROM blogs WHERE user_id = :id', [':id' => $uid]);
                $db->query('DELETE FROM online_users WHERE user_id = :id', [':id' => $uid]);
                $db->query('DELETE FROM viewed_replies WHERE user_id = :id', [':id' => $uid]);

                // 3. 最后删除用户本身
                $db->query('DELETE FROM users WHERE id = :id', [':id' => $uid]);

                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();
                throw $e;
            }

            return $fileCount;
        } catch (\Throwable $e) {
            // 跨库非原子补偿：未完成清单写入 error_log + 审计表，抛"可重跑"提示。
            // 各阶段均幂等，重跑 deleteWithCascade 会收敛到最终一致。
            $detail = '用户删除未完全完成（可重跑）uid=' . (int)$uid
                . ' threads=' . count($threadIds) . ' replies=' . count($replyIds)
                . ' 原因=' . $e->getMessage();
            \error_log('[User::deleteWithCascade] ' . $detail);
            try {
                \app\Helpers\AuditLog::log('delete', 'user', (int)$uid, \mb_substr($detail, 0, 500));
            } catch (\Throwable $ae) {
                // 审计写入失败不覆盖原异常
            }
            throw new \RuntimeException('用户删除未完全完成，未清理清单已记录，可重试删除', 0, $e);
        }
    }
}
