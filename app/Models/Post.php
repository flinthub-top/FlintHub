<?php
/**
 * FlintHub 1.0 (SplitDB) — 回复模型（分片路由读写）
 * 白皮书 8.x：回复写入 = global_id 取号 → 写入【父帖所在桶】的 reply 表 + 正文 extern
 *             → main_index.reply_index 记录桶路径（回复按 ID 读取 O(1)）
 * 设计决策：回复存储于父帖所在桶（而非按回复 ID 重新哈希）——
 * 保证 getByThread 单桶读取、回复级联清理与详情页「单桶」语义一致（白皮书 8.2）。
 * @file app/Models/Post.php
 * @package app\Models
 */

namespace app\Models;

use app\Core\Model;
use app\SplitDB\ExternStorage;
use app\SplitDB\IDGenerator;
use app\SplitDB\Schema;
use app\SplitDB\ShardRouter;

class Post extends Model
{
    /** 兼容保留：business.sqlite 的 posts 表（历史过渡遗留，现已不再写入） */
    protected $table = 'posts';

    /** 回复状态：0 正常 / 1 已删除 */
    private const STATUS_NORMAL = 0;
    private const STATUS_DELETED = 1;

    // ==================== 分片写入 ====================

    /**
     * 分片写入回复（覆写 Model::insert）
     *
     * @param array $data 含 thread_id / user_id / content / created_at
     * @return int 回复全局 ID
     */
    public function insert($data)
    {
        $threadId = (int)($data['thread_id'] ?? 0);
        if ($threadId <= 0) {
            throw new \InvalidArgumentException('Post::insert 缺少 thread_id');
        }

        // 1. 查父帖桶路径（main_index.topic_index）
        $indexRow = $this->fetchThreadIndex($threadId);
        if (!$indexRow) {
            throw new \RuntimeException("Post::insert 父帖不存在: {$threadId}");
        }

        // 归档/删除守卫：父帖已归档（is_archived 标记或物理归档桶）或已软删 → 拒绝插入回复。
        // Web 层 ThreadController 已拦；此处兜底 AI worker/队列等直写通道绕过控制器的情况。
        $parentArchived = (int)($indexRow['is_archived'] ?? 0) === 1
            || strpos((string)($indexRow['bucket_path'] ?? ''), 'bucket/archive/') === 0;
        if ($parentArchived || !empty($indexRow['deleted_at'])) {
            throw new \RuntimeException('归档帖不可回复');
        }

        // 回复写入前桶负载检查（在写 extern/桶/索引之前；仅统计 topic 行数）
        $bdb = Schema::bucketFromPath($indexRow['bucket_path']);
        $this->guardBucketCapacity($indexRow['bucket_path'], $bdb);

        // 2. 回复全局 ID 取号
        $id = IDGenerator::nextId('reply', Schema::globalIdDb());

        $now = time();

        // 支持回填发布时间（AI 自动回复按队列预期的 due_at 落时间戳）；未提供 created_at 则用当前时间
        $ct = isset($data['created_at']) && is_string($data['created_at']) && ($t = strtotime($data['created_at'])) > 0
            ? $t : $now;

        // 3. 正文 extern 原子写入（桶号从父帖 bucket_path 提取）
        $content = (string)($data['content'] ?? '');
        $bucketNum = ShardRouter::bucketFromPath($indexRow['bucket_path']);
        $externPath = ExternStorage::write(ShardRouter::dataPath(), $id, $bucketNum, 'reply', $content);

        // 4. 写父帖所在桶 reply 表（真相源）——$bdb 已在步骤 1 打开
        $bdb->prepare(
            'INSERT INTO reply (id, pid, uid, create_time, update_time, status, extern_path)
             VALUES (:id, :pid, :uid, :ct, :ut, :status, :extern)'
        )->execute([
            ':id'     => $id,
            ':pid'    => $threadId,
            ':uid'    => (int)($data['user_id'] ?? 0),
            ':ct'     => $ct,
            ':ut'     => $now,
            ':status' => self::STATUS_NORMAL,
            ':extern' => $externPath,
        ]);

        // 5. 写 reply_index（O(1) 定位）
        Schema::mainIndexDb()->prepare(
            'INSERT INTO reply_index (id, pid, uid, create_time, update_time, status, bucket_path)
             VALUES (:id, :pid, :uid, :ct, :ut, :status, :bpath)'
        )->execute([
            ':id' => $id, ':pid' => $threadId, ':uid' => (int)($data['user_id'] ?? 0),
            ':ct' => $ct, ':ut' => $now, ':status' => self::STATUS_NORMAL,
            ':bpath' => $indexRow['bucket_path'],
        ]);

        // 6. 按队列调度模式处理统计刷新（queue_mode: sync=同步直写 / cron|cli=入队异步）
        $mode = \app\Helpers\Settings::get('queue_mode', 'sync');
        if ($mode === 'sync') {
            // 模式 A：同步直写（实时刷新全站统计 total_posts）
            \app\Helpers\Settings::runtimeBuild();
        } else {
            // 模式 B/C：入队异步刷新统计（worker/cron 消费后 Settings::runtimeBuild）
            \app\SplitDB\Queue::push('stats', ['type' => 'reply', 'thread_id' => $threadId, 'user_id' => (int)($data['user_id'] ?? 0)]);
        }

        // 7. 主动失效版块统计缓存（回帖后版块统计立即可见）
        \app\Models\Category::invalidateCategoryStats();

        // 7.5. 刷新父帖版块的最新帖快照（回帖改变版块"最后发表"）
        \app\Models\Category::refreshLatestSnapshot((int)($indexRow['category_id'] ?? 0));

        // 8. 主动失效列表页静态缓存（回帖后列表排序/计数立即刷新）
        \app\Helpers\PageCache::invalidate();

        return $id;
    }

    /**
     * 回复写入前桶负载守卫（统计 topic + reply 双计数）
     *
     * 规则：
     *   load ≥ 90% × bucket_safe_capacity → 触发紧急扩容（尽力而为，失败不阻塞回复）
     *   load ≥ 100% × bucket_safe_capacity → 抛 RuntimeException 拒绝写入（最终防线）
     *
     * 桶内 topic 与 reply 同库存储，原实现仅统计 topic 行数会严重低估桶文件实际负载
     * （回复量常为主题的数十倍）→ 改为 topic + reply 双计数后与容量评估口径一致。
     *
     * 性能：进程内静态 60s 缓存（key = bucket_path），避免高并发回复时每次 COUNT 打桶。
     * 安全：计数/扩容失败全部 try/catch 放行写入（不因检查故障误伤正常回复），仅记录日志。
     *
     * @param string $bucketPath 父帖 bucket_path
     * @param \PDO   $bdb        桶连接（步骤 1 已打开，读 topic/reply 计数）
     */
    private function guardBucketCapacity(string $bucketPath, \PDO $bdb): void
    {
        static $loadCache = [];
        $now = time();
        $load = -1;
        if (isset($loadCache[$bucketPath]) && $loadCache[$bucketPath]['ts'] + 60 >= $now) {
            $load = $loadCache[$bucketPath]['load'];
        } else {
            try {
                // topic + reply 双计数（reply 表与 topic 同桶文件，单计 topic 会低估桶负载）
                $load = (int)$bdb->query('SELECT COUNT(*) FROM topic')->fetchColumn()
                      + (int)$bdb->query('SELECT COUNT(*) FROM reply')->fetchColumn();
            } catch (\Throwable $e) {
                \error_log('Post::guardBucketCapacity 计数失败（放行写入）: ' . $e->getMessage());
                return;
            }
            $loadCache[$bucketPath] = ['ts' => $now, 'load' => $load];
        }

        $capacity = (int)\app\Helpers\Settings::get('bucket_safe_capacity', '50000');
        if ($capacity <= 0 || $load < 0) {
            return; // 未配置容量或计数异常 → 放行
        }

        if ($load >= $capacity) {
            // 100%：拒绝写入（最终防线）
            // 文案修正——原「请联系管理员扩容」误导（扩容只把新帖路由进新桶，
            // 旧桶回复仍跟随父帖桶持续增长，扩容救不了当前旧桶）；如实说明状态。
            throw new \RuntimeException('该帖所在数据桶已满载，暂无法写入回复（扩容仅对新帖生效，需管理员迁移旧桶或调高容量后重试）', 1001);
        }
        if ($load >= (int)($capacity * 0.9)) {
            // 90%：紧急扩容（不受 auto_expand_enabled 开关限制；24h 守卫/flock 在
            // BucketAutoScaler::emergencyExpand 内）——Model 不再直接依赖 Controller 层
            try {
                \app\SplitDB\BucketAutoScaler::emergencyExpand($load, $capacity);
            } catch (\Throwable $e) {
                \error_log('Post::guardBucketCapacity 紧急扩容失败（不阻塞回复）: ' . $e->getMessage());
            }
        }
    }

    // ==================== 分片读取 ====================

    /**
     * 按主键读取回复（reply_index → 桶 → extern）
     */
    public function find($id): ?array
    {
        $id = (int)$id;
        $indexRow = Schema::mainIndexDb()->prepare('SELECT * FROM reply_index WHERE id = :id');
        $indexRow->execute([':id' => $id]);
        $idx = $indexRow->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$idx || (int)$idx['status'] === self::STATUS_DELETED) {
            return null;
        }

        $bdb = Schema::bucketFromPath($idx['bucket_path']);
        $stmt = $bdb->prepare('SELECT * FROM reply WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }

        $content = ExternStorage::read(ShardRouter::dataPath(), $row['extern_path'], $id, 'reply');
        return $this->decorate($row, $idx['bucket_path'], $content);
    }

    /**
     * 楼层列表（单桶读取父帖 reply 表）
     */
    public function getByThread($threadId, $offset = 0, $limit = 20)
    {
        $threadId = (int)$threadId;
        $indexRow = $this->fetchThreadIndex($threadId);
        if (!$indexRow) {
            return [];
        }

        $bdb = Schema::bucketFromPath($indexRow['bucket_path']);
        $rows = $bdb->query(
            'SELECT * FROM reply WHERE pid = ' . $threadId . '
             AND status = ' . self::STATUS_NORMAL . '
             ORDER BY create_time ASC, id ASC
             LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset
        )->fetchAll(\PDO::FETCH_ASSOC);

        return $this->decorateList($rows, $indexRow['bucket_path']);
    }

    /**
     * 回复总数（单桶 COUNT）
     */
    public function countByThread($threadId)
    {
        $threadId = (int)$threadId;
        $indexRow = $this->fetchThreadIndex($threadId);
        if (!$indexRow) {
            return 0;
        }
        $bdb = Schema::bucketFromPath($indexRow['bucket_path']);
        // 只统计未删除回复（status=0），楼层数不含软删
        return (int)$bdb->query(
            'SELECT COUNT(*) FROM reply WHERE pid = ' . $threadId . ' AND status = 0'
        )->fetchColumn();
    }

    // ==================== 修改 / 软删除 ====================

    /**
     * 更新回复内容（重写 extern + 桶 update_time + reply_index update_time）
     */
    public function update($id, $data)
    {
        $id = (int)$id;
        $indexRow = Schema::mainIndexDb()->prepare('SELECT * FROM reply_index WHERE id = :id');
        $indexRow->execute([':id' => $id]);
        $idx = $indexRow->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$idx) {
            return null;
        }

        $bdb = Schema::bucketFromPath($idx['bucket_path']);
        $stmt = $bdb->prepare('SELECT extern_path FROM reply WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $bucketRow = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$bucketRow) {
            return null;
        }

        // 旧正文文件清理：extern 路径按 (id, 季度, 桶) 确定——同季度编辑路径相同、写入即原子覆盖，
        // 无需删除；跨季度/归档后编辑会生成新路径，此时旧 .txt 成孤儿文件，需在成功后删除。
        $oldExtern = (string)($bucketRow['extern_path'] ?? '');
        if (isset($data['content'])) {
            $bucketNum = ShardRouter::bucketFromPath($idx['bucket_path']);
            // 传入旧 extern_path：跨季度/归档编辑时归档 .idx 位于旧路径所在季度目录，
            // write() 按旧路径目录作废 .idx（新路径目录下无该 .idx，否则旧归档条目永不失效）
            $externPath = ExternStorage::write(ShardRouter::dataPath(), $id, $bucketNum, 'reply', (string)$data['content'], null, $oldExtern !== '' ? $oldExtern : null);
            $bdb->prepare('UPDATE reply SET extern_path = :extern, update_time = :ut WHERE id = :id')
                ->execute([':extern' => $externPath, ':ut' => time(), ':id' => $id]);
            if ($oldExtern !== '' && $oldExtern !== $externPath) {
                ExternStorage::delete(ShardRouter::dataPath(), $oldExtern, $id, 'reply');
            }
        } else {
            $bdb->prepare('UPDATE reply SET update_time = :ut WHERE id = :id')
                ->execute([':ut' => time(), ':id' => $id]);
        }

        Schema::mainIndexDb()->prepare('UPDATE reply_index SET update_time = :ut WHERE id = :id')
            ->execute([':ut' => time(), ':id' => $id]);

        return true;
    }

    /**
     * 软删除（桶 + reply_index 双写）
     */
    public function softDelete($id)
    {
        $id = (int)$id;
        $indexRow = Schema::mainIndexDb()->prepare('SELECT bucket_path, status FROM reply_index WHERE id = :id');
        $indexRow->execute([':id' => $id]);
        $idx = $indexRow->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$idx) {
            return null;
        }
        // 状态守卫：已删除的回复不重复软删，避免每次重复递减全站计数（幂等）
        if ((int)($idx['status'] ?? 0) === self::STATUS_DELETED) {
            return true;
        }

        $bdb = Schema::bucketFromPath($idx['bucket_path']);
        $bdb->prepare('UPDATE reply SET status = :st, update_time = :now WHERE id = :id')
            ->execute([':st' => self::STATUS_DELETED, ':now' => time(), ':id' => $id]);

        Schema::mainIndexDb()->prepare('UPDATE reply_index SET status = :st, update_time = :now WHERE id = :id')
            ->execute([':st' => self::STATUS_DELETED, ':now' => time(), ':id' => $id]);

        // 同步递减全站回复计数（发帖时 runtimeBuild 重建，删除若不减会漂移多 1）
        \app\Helpers\Settings::runtimeDecr('total_posts');

        return true;
    }

    /**
     * 恢复
     * @deprecated 无调用方（回收站恢复走 Thread::restore，此为非帖子的旧方法），待确认后删除
     */
    public function restore($id)
    {
        $id = (int)$id;
        $indexRow = Schema::mainIndexDb()->prepare('SELECT bucket_path, status FROM reply_index WHERE id = :id');
        $indexRow->execute([':id' => $id]);
        $idx = $indexRow->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$idx) {
            return null;
        }
        // 状态守卫：非删除态的回复不重复恢复，避免每次重复递增全站计数（幂等）
        if ((int)($idx['status'] ?? 0) !== self::STATUS_DELETED) {
            return true;
        }

        Schema::bucketFromPath($idx['bucket_path'])
            ->prepare('UPDATE reply SET status = :st, update_time = :now WHERE id = :id')
            ->execute([':st' => self::STATUS_NORMAL, ':now' => time(), ':id' => $id]);
        Schema::mainIndexDb()->prepare('UPDATE reply_index SET status = :st, update_time = :now WHERE id = :id')
            ->execute([':st' => self::STATUS_NORMAL, ':now' => time(), ':id' => $id]);

        // 同步递增全站回复计数（与 softDelete 的递减对称）
        \app\Helpers\Settings::runtimeIncr('total_posts');

        return true;
    }

    // ==================== 内部工具 ====================

    /** @var array<int, array|null> 请求级缓存：thread_id => topic_index 行（countByThread/getByThread 共用一次查询） */
    private static array $threadIndexCache = [];
    /** 缓存上限：常驻 worker（CLI/AI）下无上限会持续内存膨胀；超限按 FIFO 淘汰最旧 */
    private const THREAD_INDEX_CACHE_MAX = 500;

    /**
     * 查询父帖索引行（topic_index）
     * 请求级缓存：详情页 countByThread + getByThread 各调一次，合并为同一次 main_index 查询
     */
    private function fetchThreadIndex(int $threadId): ?array
    {
        if (array_key_exists($threadId, self::$threadIndexCache)) {
            return self::$threadIndexCache[$threadId];
        }
        $stmt = Schema::mainIndexDb()->prepare('SELECT * FROM topic_index WHERE id = :id');
        $stmt->execute([':id' => $threadId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (count(self::$threadIndexCache) >= self::THREAD_INDEX_CACHE_MAX) {
            array_shift(self::$threadIndexCache); // FIFO 淘汰最旧条目
        }
        return self::$threadIndexCache[$threadId] = $row;
    }

    /**
     * 单条回复装饰：桶行 + extern 正文 + 用户信息
     */
    private function decorate(array $bucketRow, string $bucketPath, string $content): array
    {
        $row = $bucketRow;
        $row['thread_id']  = (int)($row['pid'] ?? 0);
        $row['user_id']    = (int)($row['uid'] ?? 0);
        $row['content']    = $content;
        $row['created_at'] = date('Y-m-d H:i:s', (int)$row['create_time']);
        $row['bucket_path'] = $bucketPath;
        unset($row['pid'], $row['uid'], $row['create_time']);

        $user = $this->db->fetchOne(
            'SELECT id, username, avatar, signature, role, points, level, post_count FROM users WHERE id = :id',
            [':id' => (int)$row['user_id']]
        );
        if ($user) {
            // 剔除用户 id，防止覆盖回复 id
            $row = array_merge($row, array_diff_key($user, ['id' => 1]));
        }
        return $row;
    }

    /**
     * 楼层列表装饰：批量补用户信息（避免 N+1）
     */
    private function decorateList(array $rows, string $bucketPath): array
    {
        if (empty($rows)) return [];

        $uids = array_values(array_unique(array_map(fn($r) => (int)$r['uid'], $rows)));
        $users = [];
        if (!empty($uids)) {
            $marks = implode(',', array_fill(0, count($uids), '?'));
            $userRows = $this->db->fetchAll(
                "SELECT id, username, avatar, signature, role, points, level, post_count FROM users WHERE id IN ({$marks})",
                $uids
            );
            foreach ($userRows as $u) {
                $users[(int)$u['id']] = $u;
            }
        }

        $result = [];
        foreach ($rows as $r) {
            $uid = (int)$r['uid'];
            $result[] = [
                'id'         => (int)$r['id'],
                'thread_id'  => (int)$r['pid'],
                'user_id'    => $uid,
                'content'    => ExternStorage::read(ShardRouter::dataPath(), $r['extern_path'], (int)$r['id'], 'reply'),
                'created_at' => date('Y-m-d H:i:s', (int)$r['create_time']),
                'bucket_path'=> $bucketPath,
                'username'   => $users[$uid]['username'] ?? '',
                'avatar'     => $users[$uid]['avatar'] ?? '',
                'signature'  => $users[$uid]['signature'] ?? '',
                'role'       => $users[$uid]['role'] ?? '',
                'points'     => $users[$uid]['points'] ?? 0,
                'level'      => $users[$uid]['level'] ?? 0,
                'post_count' => $users[$uid]['post_count'] ?? 0,
            ];
        }
        return $result;
    }
}
