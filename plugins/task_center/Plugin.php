<?php
/**
 * FlintHub — 任务中心插件 主类
 * 建库/种子、事件进度累计（taskProgress）、惰性统计（签到/资料/邀请）、领取奖励（claim）
 * @file plugins/task_center/Plugin.php
 * @package Plugin\TaskCenter
 * @version 1.0.0
 */

namespace Plugin\TaskCenter;

class Plugin
{
    /** 事件类型白名单（与 AdminController::TYPES 保持一致） */
    public const TYPES = ['register', 'post', 'reply', 'sign_in', 'profile', 'invite', 'vote', 'login'];

    /** 惰性统计依赖的跨插件类型（读不到依赖库时前台标记"依赖未启用"） */
    public const LAZY_TYPES = ['sign_in', 'profile', 'invite'];

    /** 图标白名单（必须在 TemplateCompiler $map 中存在，否则回退问号） */
    public const ICONS = [
        'clipboard-list', 'star', 'gift', 'trophy', 'medal', 'heart', 'check',
        'calendar-check', 'user-check', 'user-group', 'reply-all', 'new-thread',
        'circle-user', 'clock', 'coins-alt',
    ];

    /** 单次请求内跨插件统计缓存（user_id => 统计数组/null） */
    private static $statsCache = [];

    /** 单次请求内任务列表缓存 */
    private static $tasksCache = null;

    /** [SplitDB] 插件独立库连接（plugins/task_center/data/task_center.sqlite） */
    public static function db(): \PDO
    {
        return \app\Helpers\Plugin::db('task_center');
    }

    public static function ddl(): string
    {
        return "CREATE TABLE IF NOT EXISTS tc_tasks (
            id INTEGER PRIMARY KEY,
            type TEXT NOT NULL,
            title TEXT NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            target INTEGER NOT NULL DEFAULT 1,
            reward_points INTEGER NOT NULL DEFAULT 0,
            sign_in_mode TEXT NOT NULL DEFAULT 'total',
            profile_mode TEXT NOT NULL DEFAULT 'all',
            daily_reset INTEGER NOT NULL DEFAULT 0,
            icon TEXT NOT NULL DEFAULT 'clipboard-list',
            sort_order INTEGER NOT NULL DEFAULT 0,
            enabled INTEGER NOT NULL DEFAULT 1,
            created_at TEXT
        );
        CREATE TABLE IF NOT EXISTS tc_progress (
            id INTEGER PRIMARY KEY,
            task_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            progress INTEGER NOT NULL DEFAULT 0,
            claimed INTEGER NOT NULL DEFAULT 0,
            claim_date TEXT,
            last_event_date TEXT,
            completed_at TEXT,
            snapshot TEXT,
            created_at TEXT,
            UNIQUE(task_id, user_id)
        );
        CREATE TABLE IF NOT EXISTS tc_log (
            id INTEGER PRIMARY KEY,
            task_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            delta INTEGER NOT NULL DEFAULT 0,
            event_type TEXT NOT NULL DEFAULT '',
            event_id INTEGER NOT NULL DEFAULT 0,
            created_at TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_tc_progress_uid ON tc_progress (user_id);
        CREATE INDEX IF NOT EXISTS idx_tc_log_uid ON tc_log (user_id);";
    }

    /**
     * 激活：建表 + 幂等写入种子任务
     */
    public static function activate(): bool
    {
        \app\Helpers\Plugin::ensureSchema('task_center', self::ddl());
        self::seedDefaults();
        return true;
    }

    public static function deactivate(): bool
    {
        return true;
    }

    /**
     * 卸载插件：删除本插件数据表（[SplitDB] 独立库）
     */
    public static function uninstall(): void
    {
        $db = self::db();
        $db->exec('DROP TABLE IF EXISTS tc_log');
        $db->exec('DROP TABLE IF EXISTS tc_progress');
        $db->exec('DROP TABLE IF EXISTS tc_tasks');
    }

    public static function clearCache(): void
    {
        self::$tasksCache = null;
        self::$statsCache = [];
    }

    /**
     * 种子任务：仅当 tc_tasks 为空时写入（幂等）。标题存语言键（plugin.task_center.seed.*），
     * 视图经 I18n::get 解析；管理员可改成纯文本覆盖。
     */
    public static function seedDefaults(): void
    {
        try {
            $db = self::db();
            $cnt = (int)$db->query('SELECT COUNT(*) FROM tc_tasks')->fetchColumn();
            if ($cnt > 0) return;

            $rows = [
                // [type, titleKey, descKey, target, reward, sign_in_mode, profile_mode, daily, icon, sort]
                ['register', 'plugin.task_center.seed.register', 'plugin.task_center.seed.desc.register', 1, 10, 'total', 'all', 0, 'user-check', 10],
                ['post',     'plugin.task_center.seed.post1',     'plugin.task_center.seed.desc.post1',     1, 5,  'total', 'all', 0, 'new-thread', 20],
                ['post',     'plugin.task_center.seed.post5',     'plugin.task_center.seed.desc.post5',     5, 20, 'total', 'all', 0, 'new-thread', 30],
                ['reply',    'plugin.task_center.seed.reply3',    'plugin.task_center.seed.desc.reply3',    3, 10, 'total', 'all', 0, 'reply-all', 40],
                ['sign_in',  'plugin.task_center.seed.sign_daily','plugin.task_center.seed.desc.sign_daily',1, 2,  'total', 'all', 1, 'calendar-check', 50],
                ['sign_in',  'plugin.task_center.seed.sign7',     'plugin.task_center.seed.desc.sign7',     7, 30, 'consecutive', 'all', 0, 'calendar-check', 60],
                ['profile',  'plugin.task_center.seed.profile',   'plugin.task_center.seed.desc.profile',   3, 15, 'total', 'all', 0, 'circle-user', 70],
                ['invite',   'plugin.task_center.seed.invite1',   'plugin.task_center.seed.desc.invite1',   1, 20, 'total', 'all', 0, 'user-group', 80],
                ['login',    'plugin.task_center.seed.login_daily','plugin.task_center.seed.desc.login_daily',1, 1, 'total', 'all', 1, 'clock', 90],
                ['vote',     'plugin.task_center.seed.vote5',     'plugin.task_center.seed.desc.vote5',     5, 10, 'total', 'all', 0, 'heart', 100],
            ];

            $stmt = $db->prepare(
                "INSERT INTO tc_tasks (type, title, description, target, reward_points, sign_in_mode, profile_mode, daily_reset, icon, sort_order, enabled, created_at)
                 VALUES (:type, :title, :desc, :target, :reward, :sigmode, :promode, :daily, :icon, :sort, 1, :now)"
            );
            $now = date('Y-m-d H:i:s');
            foreach ($rows as $r) {
                $stmt->execute([
                    ':type' => $r[0], ':title' => $r[1], ':desc' => $r[2],
                    ':target' => (int)$r[3], ':reward' => (int)$r[4],
                    ':sigmode' => $r[5], ':promode' => $r[6],
                    ':daily' => (int)$r[7], ':icon' => $r[8], ':sort' => (int)$r[9],
                    ':now' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            \error_log('task_center seedDefaults error: ' . $e->getMessage());
        }
    }

    /**
     * 任务列表（按 sort_order 升序）
     */
    public static function getTasks(bool $enabledOnly = false): array
    {
        if (self::$tasksCache === null) {
            try {
                $rows = self::db()->query('SELECT * FROM tc_tasks ORDER BY sort_order ASC, id ASC')->fetchAll(\PDO::FETCH_ASSOC);
                self::$tasksCache = $rows ?: [];
            } catch (\Throwable $e) {
                self::$tasksCache = [];
            }
        }
        if (!$enabledOnly) return self::$tasksCache;
        return \array_values(\array_filter(self::$tasksCache, fn($t) => (int)$t['enabled'] === 1));
    }

    public static function getTask(int $id): ?array
    {
        foreach (self::getTasks() as $t) {
            if ((int)$t['id'] === $id) return $t;
        }
        return null;
    }

    /**
     * 用户全部进度行（task_id => row）
     */
    public static function getProgressMap(int $userId): array
    {
        if ($userId <= 0) return [];
        try {
            $stmt = self::db()->prepare('SELECT * FROM tc_progress WHERE user_id = :uid');
            $stmt->execute([':uid' => $userId]);
            $map = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $map[(int)$r['task_id']] = $r;
            }
            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** 确保进度行存在（INSERT OR IGNORE 幂等；completed_at 统一空串，避免 null/'' 混用） */
    private static function ensureRow(\PDO $db, int $taskId, int $userId): void
    {
        $stmt = $db->prepare('INSERT OR IGNORE INTO tc_progress (task_id, user_id, progress, claimed, completed_at, created_at) VALUES (:tid, :uid, 0, 0, \'\', :now)');
        $stmt->execute([':tid' => $taskId, ':uid' => $userId, ':now' => date('Y-m-d H:i:s')]);
    }

    /**
     * 事件驱动进度累计（钩子调用入口）
     * 每日任务：跨天（last_event_date != 今天）先清零重计；一次性任务：进度达 target 后不再累计。
     *
     * @param string $type    事件类型（register/post/reply/vote/login/...）
     * @param int    $userId  触发用户
     * @param int    $eventId 关联业务 ID（写入流水，可选）
     */
    public static function taskProgress(string $type, int $userId, int $eventId = 0): void
    {
        if ($userId <= 0) return;
        $tasks = \array_values(\array_filter(self::getTasks(true), fn($t) => $t['type'] === $type));
        if (!$tasks) return;

        $db = self::db();
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        try {
            $db->beginTransaction();
            foreach ($tasks as $task) {
                $taskId = (int)$task['id'];
                $target = (int)$task['target'];
                $daily = (int)$task['daily_reset'] === 1;
                self::ensureRow($db, $taskId, $userId);

                $stmt = $db->prepare('SELECT progress, claimed, claim_date, last_event_date, completed_at FROM tc_progress WHERE task_id = :tid AND user_id = :uid');
                $stmt->execute([':tid' => $taskId, ':uid' => $userId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                $progress = (int)$row['progress'];
                $newProgress = $progress;
                $newCompleted = (string)$row['completed_at'];
                $newClaimed = (int)$row['claimed'];
                $newClaimDate = (string)$row['claim_date'];
                $newLastEvent = (string)$row['last_event_date'];

                if ($daily) {
                    // 每日任务：跨天重置（进度/领取状态/完成时间全部清零）
                    if ($newLastEvent !== '' && $newLastEvent !== $today) {
                        $newProgress = 0;
                        $newCompleted = '';
                        $newClaimed = 0;
                        $newClaimDate = '';
                    }
                    $newProgress++;
                    if ($newProgress >= $target) {
                        $newCompleted = $now;
                    }
                } else {
                    // 一次性任务：达 target 后不再累计（仅跳过该任务，不影响同类型其他任务）
                    if ($progress >= $target) {
                        continue;
                    }
                    $newProgress++;
                    if ($newProgress >= $target) {
                        $newCompleted = $now;
                    }
                }
                $newLastEvent = $today;

                $db->prepare(
                    'UPDATE tc_progress SET progress = :p, claimed = :c, claim_date = :cd, last_event_date = :le, completed_at = :ca WHERE task_id = :tid AND user_id = :uid'
                )->execute([
                    ':p' => $newProgress, ':c' => $newClaimed, ':cd' => $newClaimDate,
                    ':le' => $newLastEvent, ':ca' => $newCompleted, ':tid' => $taskId, ':uid' => $userId,
                ]);
                $db->prepare(
                    'INSERT INTO tc_log (task_id, user_id, delta, event_type, event_id, created_at) VALUES (:tid, :uid, 1, :et, :eid, :now)'
                )->execute([
                    ':tid' => $taskId, ':uid' => $userId, ':et' => $type, ':eid' => $eventId, ':now' => $now,
                ]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            try { $db->rollBack(); } catch (\Throwable $ignored) {}
            \error_log('task_center taskProgress error: ' . $e->getMessage());
        }
    }

    /**
     * 惰性统计刷新（前台打开任务中心时调用）：重算 sign_in/profile/invite 类任务进度并写回。
     * 返回"依赖未启用"的任务 map（task_id => 原因），供视图灰态展示。
     */
    public static function refreshLazyProgress(int $userId): array
    {
        $depOff = [];
        if ($userId <= 0) return $depOff;
        $tasks = \array_values(\array_filter(self::getTasks(true), fn($t) => \in_array($t['type'], self::LAZY_TYPES, true)));
        if (!$tasks) return $depOff;

        $db = self::db();
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');

        // 签到统计：全类型共用一次（含快照守卫）
        $hasSign = false;
        foreach ($tasks as $t) { if ($t['type'] === 'sign_in') { $hasSign = true; break; } }
        $signStats = null;
        if ($hasSign) {
            $signRows = [];
            foreach ($tasks as $t) {
                if ($t['type'] !== 'sign_in') continue;
                $tid = (int)$t['id'];
                self::ensureRow($db, $tid, $userId);
                $stmt = $db->prepare('SELECT task_id, snapshot, last_event_date FROM tc_progress WHERE task_id = :tid AND user_id = :uid');
                $stmt->execute([':tid' => $tid, ':uid' => $userId]);
                $signRows[$tid] = $stmt->fetch(\PDO::FETCH_ASSOC);
            }
            $signStats = self::checkinStats($userId, $signRows);
        }
        $inviteCnt = null;
        foreach ($tasks as $t) { if ($t['type'] === 'invite') { $inviteCnt = self::inviteCount($userId); break; } }

        try {
            $db->beginTransaction();
            foreach ($tasks as $task) {
                $taskId = (int)$task['id'];
                $target = (int)$task['target'];
                $daily = (int)$task['daily_reset'] === 1;
                self::ensureRow($db, $taskId, $userId);

                $value = null;
                $offReason = '';
                if ($task['type'] === 'sign_in') {
                    if ($signStats === null) {
                        $offReason = 'sign_in';
                    } else {
                        if ($daily) {
                            // 每日签到："今日已签到" 语义
                            $value = ($signStats['last_date'] === $today) ? 1 : 0;
                        } else {
                            $value = $task['sign_in_mode'] === 'consecutive' ? (int)$signStats['max_streak'] : (int)$signStats['total'];
                        }
                    }
                } elseif ($task['type'] === 'invite') {
                    if ($inviteCnt === null) {
                        $offReason = 'invite';
                    } else {
                        $value = $inviteCnt;
                    }
                } else { // profile
                    $value = self::profileProgress($userId, (string)$task['profile_mode']);
                }

                if ($offReason !== '') {
                    $depOff[$taskId] = $offReason;
                    continue; // 依赖缺失：不动进度行
                }

                $stmt = $db->prepare('SELECT progress, claimed, claim_date, last_event_date, completed_at, snapshot FROM tc_progress WHERE task_id = :tid AND user_id = :uid');
                $stmt->execute([':tid' => $taskId, ':uid' => $userId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                $claimed = (int)$row['claimed'];
                $claimDate = (string)$row['claim_date'];
                $lastEvent = (string)$row['last_event_date'];
                $completed = (string)$row['completed_at'];

                if ($daily && $lastEvent !== '' && $lastEvent !== $today) {
                    // 跨天：清空昨日状态后写入今日值
                    $claimed = 0;
                    $claimDate = '';
                    $completed = '';
                }
                $progress = \max(0, (int)$value);
                if ($progress >= $target && $completed === '') {
                    $completed = $now;
                } elseif ($progress < $target) {
                    $completed = '';
                }

                $db->prepare(
                    'UPDATE tc_progress SET progress = :p, claimed = :c, claim_date = :cd, last_event_date = :le, completed_at = :ca, snapshot = :sn WHERE task_id = :tid AND user_id = :uid'
                )->execute([
                    ':p' => $progress, ':c' => $claimed, ':cd' => $claimDate,
                    ':le' => $today, ':ca' => $completed,
                    ':sn' => $task['type'] === 'sign_in' && $signStats !== null ? \json_encode($signStats, JSON_UNESCAPED_UNICODE) : (string)$row['snapshot'],
                    ':tid' => $taskId, ':uid' => $userId,
                ]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            try { $db->rollBack(); } catch (\Throwable $ignored) {}
            \error_log('task_center refreshLazyProgress error: ' . $e->getMessage());
        }
        return $depOff;
    }

    /**
     * 签到统计（跨插件只读 daily_checkin 库）+ 快照守卫：
     * 仅当有新签到记录（MAX(checkin_date) 变化）时全量重算，否则直接复用快照。
     * 依赖缺失/异常时返回 null（不抛异常，由调用方灰态降级）。
     */
    public static function checkinStats(int $userId, array $signRows = []): ?array
    {
        if (\array_key_exists($userId, self::$statsCache)) return self::$statsCache[$userId];
        $result = null;
        try {
            if (!\app\Helpers\Plugin::isActivated('daily_checkin')) {
                return self::$statsCache[$userId] = null;
            }
            $db = \app\Helpers\Plugin::db('daily_checkin');
            if (!self::tableExists($db, 'daily_checkin')) {
                return self::$statsCache[$userId] = null;
            }
            $last = (string)$db->query('SELECT MAX(checkin_date) FROM daily_checkin WHERE user_id = ' . (int)$userId)->fetchColumn();

            // 快照守卫：任一行快照的 last_date 与当前 MAX 一致且非空 → 直接复用
            if ($last !== '') {
                foreach ($signRows as $r) {
                    if (!empty($r['snapshot'])) {
                        $snap = \json_decode((string)$r['snapshot'], true);
                        if (\is_array($snap) && ($snap['last_date'] ?? '') === $last) {
                            return self::$statsCache[$userId] = $snap;
                        }
                    }
                }
            }

            // 全量重算：总次数 + 最大连续段
            $all = $db->query(
                'SELECT checkin_date FROM daily_checkin WHERE user_id = ' . (int)$userId . ' ORDER BY checkin_date ASC'
            )->fetchAll(\PDO::FETCH_ASSOC);
            $maxStreak = 0;
            $cur = 0;
            $prev = null;
            foreach ($all as $r) {
                if ($prev !== null && $r['checkin_date'] === date('Y-m-d', \strtotime($prev . ' +1 day'))) {
                    $cur++;
                } else {
                    $cur = 1;
                }
                if ($cur > $maxStreak) $maxStreak = $cur;
                $prev = $r['checkin_date'];
            }
            $result = ['total' => \count($all), 'max_streak' => $maxStreak, 'last_date' => $last];
        } catch (\Throwable $e) {
            \error_log('task_center checkinStats error: ' . $e->getMessage());
            $result = null;
        }
        return self::$statsCache[$userId] = $result;
    }

    /**
     * 邀请成功数（跨插件只读 invite 库）；invite 插件未激活/无表/异常 → null
     */
    public static function inviteCount(int $userId): ?int
    {
        if (\array_key_exists($userId, self::$statsCache)) {
            return \is_int(self::$statsCache[$userId]) ? self::$statsCache[$userId] : null;
        }
        $key = 'invite_' . $userId;
        if (\array_key_exists($key, self::$statsCache)) return self::$statsCache[$key];
        if (!\app\Helpers\Plugin::isActivated('invite')) return self::$statsCache[$key] = null;
        try {
            $db = \app\Helpers\Plugin::db('invite');
            if (!self::tableExists($db, 'invites')) return self::$statsCache[$key] = null;
            $stmt = $db->prepare('SELECT COUNT(*) FROM invites WHERE creator_id = :uid AND used_by_user_id IS NOT NULL');
            $stmt->execute([':uid' => $userId]);
            return self::$statsCache[$key] = (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            \error_log('task_center inviteCount error: ' . $e->getMessage());
        }
        return self::$statsCache[$key] = null;
    }

    /**
     * 资料完善度（核心库 users 只读）：
     * avatar=已上传自定义头像（非 seed 默认）/ signature=签名非空 / email=邮箱已验证
     * mode 取 avatar|signature|email 时返回 0/1；all 返回满足条件数（0-3）。
     */
    public static function profileProgress(int $userId, string $mode): int
    {
        $key = 'profile_' . $userId;
        if (!isset(self::$statsCache[$key])) {
            $ok = ['avatar' => 0, 'signature' => 0, 'email' => 0];
            try {
                $u = \app\Core\Database::getInstance()->fetchOne(
                    'SELECT avatar, signature, email_verified FROM users WHERE id = :id', [':id' => $userId]
                );
                if ($u) {
                    $av = (string)($u['avatar'] ?? '');
                    $ok['avatar'] = ($av !== '' && \strpos($av, 'seed_avatars/') !== 0) ? 1 : 0;
                    $ok['signature'] = \trim((string)($u['signature'] ?? '')) !== '' ? 1 : 0;
                    $ok['email'] = (int)($u['email_verified'] ?? 0) === 1 ? 1 : 0;
                }
            } catch (\Throwable $e) {
                \error_log('task_center profileProgress error: ' . $e->getMessage());
            }
            self::$statsCache[$key] = $ok;
        }
        $ok = self::$statsCache[$key];
        if ($mode === 'avatar' || $mode === 'signature' || $mode === 'email') return (int)$ok[$mode];
        return (int)$ok['avatar'] + (int)$ok['signature'] + (int)$ok['email'];
    }

    /** 探测 SQLite 表是否存在（跨插件只读前的降级检查） */
    private static function tableExists(\PDO $db, string $table): bool
    {
        try {
            $stmt = $db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = " . $db->quote($table));
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 领取奖励（前台 POST 入口）：条件 UPDATE 原子领取 + Points::award 发积分。
     * 每日任务仅限当天完成当天领（作废策略）；一次性任务终身一次。
     *
     * @return array [ok(bool), errorKey(string)]
     */
    public static function claim(int $userId, int $taskId): array
    {
        if ($userId <= 0) return [false, 'plugin.task_center.err_param'];
        $task = self::getTask($taskId);
        if ($task === null || (int)$task['enabled'] !== 1) {
            return [false, 'plugin.task_center.err_unavailable'];
        }
        if ((int)$task['reward_points'] <= 0) {
            return [false, 'plugin.task_center.err_no_reward'];
        }

        $db = self::db();
        $today = date('Y-m-d');
        $daily = (int)$task['daily_reset'] === 1;
        try {
            $db->beginTransaction();
            $stmt = $db->prepare('SELECT progress, claimed, completed_at FROM tc_progress WHERE task_id = :tid AND user_id = :uid');
            $stmt->execute([':tid' => $taskId, ':uid' => $userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                $db->rollBack();
                return [false, 'plugin.task_center.err_not_done'];
            }
            if ($daily && \substr((string)$row['completed_at'], 0, 10) !== $today) {
                // 每日任务跨天未领即作废
                $db->rollBack();
                return [false, 'plugin.task_center.err_expired'];
            }
            if ((int)$row['claimed'] === 1) {
                $db->rollBack();
                return [false, 'plugin.task_center.err_claimed'];
            }
            if ((int)$row['progress'] < (int)$task['target']) {
                $db->rollBack();
                return [false, 'plugin.task_center.err_not_done'];
            }

            // 原子领取：条件 UPDATE 防并发双领
            $upd = $db->prepare(
                'UPDATE tc_progress SET claimed = 1, claim_date = :cd WHERE task_id = :tid AND user_id = :uid AND claimed = 0'
            );
            $upd->execute([':cd' => $today, ':tid' => $taskId, ':uid' => $userId]);
            if ($upd->rowCount() === 0) {
                $db->rollBack();
                return [false, 'plugin.task_center.err_claimed'];
            }

            \app\Helpers\Points::award(
                $userId,
                (int)$task['reward_points'],
                'task_center:' . $taskId,
                $taskId,
                'task_center'
            );
            $db->commit();
            return [true, ''];
        } catch (\Throwable $e) {
            try { $db->rollBack(); } catch (\Throwable $ignored) {}
            \error_log('task_center claim error: ' . $e->getMessage());
            return [false, 'plugin.task_center.err_system'];
        }
    }

    /**
     * 后台统计：各任务领取人数（task_id => count）
     */
    public static function claimCounts(): array
    {
        try {
            $rows = self::db()->query('SELECT task_id, COUNT(*) AS cnt FROM tc_progress WHERE claimed = 1 GROUP BY task_id')->fetchAll(\PDO::FETCH_ASSOC);
            $map = [];
            foreach ($rows as $r) $map[(int)$r['task_id']] = (int)$r['cnt'];
            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
