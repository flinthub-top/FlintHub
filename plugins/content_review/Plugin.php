<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 内容审核插件主类 — 激活/停用、数据表创建
 * @file plugins/content_review/Plugin.php
 * @package Plugin\ContentReview
 * @version 1.0.0
 */

namespace Plugin\ContentReview;

class Plugin
{
    /** [SplitDB] 插件独立库连接（plugins/content_review/data/content_review.sqlite） */
    public static function db(): \PDO
    {
        return \app\Helpers\Plugin::db('content_review');
    }

    /**
     * 激活插件：创建数据表（[SplitDB] SQLite DDL，独立库）
     */
    public static function activate(): bool
    {
        $db = self::db();

        // 审核记录表
        $db->exec("CREATE TABLE IF NOT EXISTS content_reviews (
            id INTEGER PRIMARY KEY,
            target_type TEXT NOT NULL,
            target_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            reason TEXT,
            reviewed_by INTEGER DEFAULT 0,
            points_held INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            reviewed_at TEXT
        )");
        // 幂等加列：老库补 points_held（审核暂扣积分值，0=未暂扣）
        try {
            $cols = [];
            foreach ($db->query('PRAGMA table_info(content_reviews)')->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                $cols[] = $c['name'];
            }
            if (!in_array('points_held', $cols, true)) {
                $db->exec('ALTER TABLE content_reviews ADD COLUMN points_held INTEGER NOT NULL DEFAULT 0');
            }
        } catch (\Throwable $e) {
            \error_log('content_review points_held column error: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * 禁用插件
     */
    public static function deactivate(): bool
    {
        return true;
    }

    /**
     * 卸载插件：删除数据表
     */
    public static function uninstall(): void
    {
        self::db()->exec('DROP TABLE IF EXISTS content_reviews');
    }

    /**
     * 获取待审核数量
     */
    public static function getPendingCount(): int
    {
        try {
            $r = self::db()->query("SELECT COUNT(*) as cnt FROM content_reviews WHERE status = 'pending'")->fetch(\PDO::FETCH_ASSOC);
            return (int)($r['cnt'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 创建审核记录
     */
    public static function createReview(string $targetType, int $targetId, int $userId, int $pointsHeld = 0): int
    {
        $db = self::db();
        $db->prepare(
            "INSERT INTO content_reviews (target_type, target_id, user_id, status, points_held, created_at)
             VALUES (:type, :tid, :uid, 'pending', :held, :now)"
        )->execute([':type' => $targetType, ':tid' => $targetId, ':uid' => $userId, ':held' => $pointsHeld, ':now' => date('Y-m-d H:i:s')]);
        return (int)$db->lastInsertId();
    }

    /**
     * 通过审核
     */
    public static function approve(int $id, int $reviewedBy): void
    {
        $pdb = self::db(); // [SplitDB] 插件独立库
        $db = \app\Core\Database::getInstance(); // 核心库（threads/posts/users）
        $stmt = $pdb->prepare('SELECT * FROM content_reviews WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $review = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$review) return;

        $targetType = $review['target_type'];
        $targetId = (int)$review['target_id'];
        $userId = (int)$review['user_id'];

        // 更新审核状态（独立库）
        $pdb->prepare(
            "UPDATE content_reviews SET status = 'approved', reviewed_by = :by, reviewed_at = :now WHERE id = :id"
        )->execute([':id' => $id, ':by' => $reviewedBy, ':now' => date('Y-m-d H:i:s')]);

        switch ($targetType) {
            case 'thread':
                // [SplitDB] 帖子已迁 main_index 分片：恢复必须经 Thread Model（实例方法，写 bucket + index + 刷新快照）
                (new \app\Models\Thread())->restore($targetId);
                break;
            case 'post':
                (new \app\Models\Post())->restore($targetId);
                break;
            case 'avatar':
                // 从审核记录的 reason 字段取出暂存路径
                $pendingPath = $review['reason'] ?? '';
                if ($pendingPath) {
                    // 获取当前（旧）头像路径，用于清理旧文件
                    $user = $db->fetchOne("SELECT avatar FROM users WHERE id = :id", [':id' => $userId]);
                    $oldFile = $user['avatar'] ?? '';
                    // 更新为新头像（经 User Model，不再直写核心表）
                    (new \app\Models\User())->updateAvatar((int)$userId, (string)$pendingPath);
                    // 删除旧头像文件
                    if ($oldFile) {
                        $oldPath = rtrim(\UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . basename($oldFile);
                        if (is_file($oldPath)) { @unlink($oldPath); }
                    }
                }
                break;
        }

        // 审核通过后补发暂扣积分（发帖/回帖进入审核时 Points::deduct 暂扣，这里归还）
        $pointsHeld = (int)($review['points_held'] ?? 0);
        if ($pointsHeld > 0) {
            try {
                \app\Helpers\Points::award(
                    $userId,
                    $pointsHeld,
                    ($targetType === 'thread' ? '主题审核通过' : '回复审核通过'),
                    $targetId,
                    $targetType
                );
            } catch (\Throwable $e) {
                \error_log('content_review points refund error: ' . $e->getMessage());
            }
        }

        // 通知内置化：审核通过通知（写入核心 notifications 表）
        $typeLabel = ['thread' => '帖子', 'post' => '回复', 'avatar' => '头像'];
        $label = $typeLabel[$targetType] ?? $targetType;
        $contentTitle = self::getReviewTargetTitle($targetType, $targetId);
        $notifyTitle = $contentTitle
            ? "你的{$label}「{$contentTitle}」已审核通过"
            : "你的{$label}已审核通过";
        $link = self::getReviewLink($targetType, $targetId);
        \app\Helpers\Notification::notifyReview($userId, $id, 'approved', $notifyTitle, $link, $targetType);
    }

    /**
     * 驳回审核
     */
    public static function reject(int $id, int $reviewedBy, string $reason = ''): void
    {
        $pdb = self::db(); // [SplitDB] 插件独立库
        $db = \app\Core\Database::getInstance(); // 核心库（threads/posts）
        $stmt = $pdb->prepare('SELECT * FROM content_reviews WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $review = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$review) return;

        $targetType = $review['target_type'];
        $targetId = (int)$review['target_id'];
        $userId = (int)$review['user_id'];

        // 更新审核状态（独立库）
        $pdb->prepare(
            "UPDATE content_reviews SET status = 'rejected', reason = :reason, reviewed_by = :by, reviewed_at = :now WHERE id = :id"
        )->execute([':id' => $id, ':reason' => $reason, ':by' => $reviewedBy, ':now' => date('Y-m-d H:i:s')]);

        switch ($targetType) {
            case 'thread':
                // [SplitDB] 软删除必须经 Thread Model（写 bucket + index + 刷新快照/缓存）
                (new \app\Models\Thread())->softDelete($targetId);
                break;
            case 'post':
                (new \app\Models\Post())->softDelete($targetId);
                break;
            case 'avatar':
                // 驳回：删除上传的新头像文件（DB 已回退到旧头像）
                $newFile = $review['reason'] ?? '';
                if ($newFile) {
                    $newPath = rtrim(\UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . basename($newFile);
                    if (is_file($newPath)) { @unlink($newPath); }
                }
                break;
        }

        // 通知内置化：审核驳回通知（写入核心 notifications 表）
        $typeLabel = ['thread' => '帖子', 'post' => '回复', 'avatar' => '头像'];
        $label = $typeLabel[$targetType] ?? $targetType;
        $contentTitle = self::getReviewTargetTitle($targetType, $targetId);
        $baseMsg = $contentTitle ? "你的{$label}「{$contentTitle}」未通过审核" : "你的{$label}未通过审核";
        $title = $reason ? "{$baseMsg}，原因：{$reason}" : $baseMsg;
        $link = self::getReviewLink($targetType, $targetId);
        \app\Helpers\Notification::notifyReview($userId, $id, 'rejected', $title, $link, $targetType);
    }

    /**
     * 获取待审核列表（独立库查 + 核心库批量补用户名）
     */
    public static function getPendingList(): array
    {
        try {
            $rows = self::db()->query(
                "SELECT * FROM content_reviews WHERE status = 'pending' ORDER BY created_at ASC"
            )->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($rows)) return [];

            $uids = array_values(array_unique(array_map(fn($r) => (int)$r['user_id'], $rows)));
            $users = \app\Models\User::batchGetNames($uids);
            foreach ($rows as &$row) {
                $row['username'] = $users[(int)$row['user_id']] ?? '';
            }
            unset($row);
            return $rows;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 获取已审核列表（[SplitDB] 独立库查 + 核心库批量补用户名/审核人）
     */
    public static function getReviewedList(int $page = 1, int $perPage = 20): array
    {
        try {
            $offset = ($page - 1) * $perPage;
            $rows = self::db()->query(
                "SELECT * FROM content_reviews
                 WHERE status IN ('approved', 'rejected')
                 ORDER BY reviewed_at DESC
                 LIMIT " . (int)$perPage . " OFFSET " . (int)$offset
            )->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($rows)) return [];

            $uids = [];
            foreach ($rows as $r) {
                $uids[] = (int)$r['user_id'];
                $uids[] = (int)$r['reviewed_by'];
            }
            $uids = array_values(array_unique($uids));
            $users = \app\Models\User::batchGetNames($uids);
            foreach ($rows as &$row) {
                $row['username'] = $users[(int)$row['user_id']] ?? '';
                $row['reviewer_name'] = $users[(int)$row['reviewed_by']] ?? '';
            }
            unset($row);
            return $rows;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 获取审核记录总数（已审）
     */
    public static function getReviewedTotal(): int
    {
        try {
            $r = self::db()->query("SELECT COUNT(*) as cnt FROM content_reviews WHERE status IN ('approved', 'rejected')")->fetch(\PDO::FETCH_ASSOC);
            return (int)($r['cnt'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 获取审核配置（含免审用户组）
     */
    public static function getConfig(): array
    {
        $trusted = \app\Helpers\Settings::get('cr_trusted_groups', '');
        $trustedAvatar = \app\Helpers\Settings::get('cr_trusted_groups_avatar', '');
        $trustedRegister = \app\Helpers\Settings::get('cr_trusted_groups_register', '');
        $cats = \app\Helpers\Settings::get('cr_review_categories', '');
        return [
            'review_thread' => \app\Helpers\Settings::get('cr_review_thread', '0'),
            'review_post' => \app\Helpers\Settings::get('cr_review_post', '1'),
            'review_avatar' => \app\Helpers\Settings::get('cr_review_avatar', '0'),
            'review_register' => \app\Helpers\Settings::get('cr_review_register', '0'),
            'trusted_groups' => $trusted !== '' ? explode(',', $trusted) : [],
            'trusted_groups_avatar' => $trustedAvatar !== '' ? explode(',', $trustedAvatar) : [],
            'trusted_groups_register' => $trustedRegister !== '' ? explode(',', $trustedRegister) : [],
            'review_categories' => $cats !== '' ? explode(',', $cats) : [],
        ];
    }

    /**
     * 保存审核配置（含免审用户组、分版块）
     */
    public static function saveConfig(array $config): void
    {
        $settingModel = new \app\Models\Setting();
        $settingModel->setValue('cr_review_thread', !empty($config['review_thread']) ? '1' : '0');
        $settingModel->setValue('cr_review_post', !empty($config['review_post']) ? '1' : '0');
        $settingModel->setValue('cr_review_avatar', !empty($config['review_avatar']) ? '1' : '0');
        $settingModel->setValue('cr_review_register', !empty($config['review_register']) ? '1' : '0');

        // 免审用户组（帖子和回复）
        $trusted = isset($config['trusted_groups']) && is_array($config['trusted_groups'])
            ? implode(',', array_map('intval', $config['trusted_groups']))
            : '';
        $settingModel->setValue('cr_trusted_groups', $trusted);

        // 免审用户组（头像）
        $ta = isset($config['trusted_groups_avatar']) && is_array($config['trusted_groups_avatar'])
            ? implode(',', array_map('intval', $config['trusted_groups_avatar']))
            : '';
        $settingModel->setValue('cr_trusted_groups_avatar', $ta);

        // 免审用户组（注册）
        $tr = isset($config['trusted_groups_register']) && is_array($config['trusted_groups_register'])
            ? implode(',', array_map('intval', $config['trusted_groups_register']))
            : '';
        $settingModel->setValue('cr_trusted_groups_register', $tr);

        // 分版块审核
        $cats = isset($config['review_categories']) && is_array($config['review_categories'])
            ? implode(',', array_map('intval', $config['review_categories']))
            : '';
        $settingModel->setValue('cr_review_categories', $cats);

        \app\Helpers\Settings::buildCache();
    }

    /**
     * 检查帖子是否需要在当前版块审核
     * 分版块设置优先于全局开关
     */
    public static function isThreadReviewRequired(int $categoryId): bool
    {
        $config = self::getConfig();
        $cats = $config['review_categories'] ?? [];
        if (!empty($cats)) {
            // 有分版块设置 → 仅选中的版块需要审核（explode 返回字符串，转为 int 再比较）
            $catInts = array_map('intval', $cats);
            return in_array($categoryId, $catInts, true);
        }
        // 无分版块设置 → 按全局开关
        return !empty($config['review_thread']);
    }

    /**
     * 检查用户组是否免审
     * @param int $groupId 用户组 ID
     * @param string $type 审核类型: thread(默认) / avatar / register
     * @return bool true=跳过审核, false=需要审核
     */
    public static function isGroupExempt(int $groupId, string $type = 'thread'): bool
    {
        // 管理员组（id=4）永远免审
        if ($groupId === 4) return true;

        $config = self::getConfig();
        // 根据类型选择对应的免审组配置
        $key = $type === 'avatar' ? 'trusted_groups_avatar'
             : ($type === 'register' ? 'trusted_groups_register' : 'trusted_groups');
        $trusted = $config[$key] ?? [];
        $trustedInts = array_map('intval', $trusted);
        return in_array($groupId, $trustedInts, true);
    }

    /**
     * 获取所有用户组（含免审标记）
     */
    public static function getGroupsWithExempt(string $type = 'thread'): array
    {
        $groups = \app\Helpers\Permission::getGroups();
        $config = self::getConfig();
        $key = $type === 'avatar' ? 'trusted_groups_avatar'
             : ($type === 'register' ? 'trusted_groups_register' : 'trusted_groups');
        $trusted = $config[$key] ?? [];
        $trustedInts = array_map('intval', $trusted);
        foreach ($groups as &$g) {
            $g['is_exempt'] = in_array((int)$g['id'], $trustedInts, true) || (int)$g['id'] === 4;
        }
        unset($g);
        return $groups;
    }

    /**
     * 检查用户是否免审（查对应用户组）
     * @param int $userId 用户 ID
     * @param string $type 审核类型: thread(默认) / avatar / register
     * @return bool true=跳过审核, false=需要审核
     */
    public static function isUserExempt(int $userId, string $type = 'thread'): bool
    {
        if ($userId <= 0) return false;
        try {
            $db = \app\Core\Database::getInstance();
            $row = $db->fetchOne("SELECT group_id FROM users WHERE id = :id", [':id' => $userId]);
            $groupId = (int)($row['group_id'] ?? 0);
            return self::isGroupExempt($groupId, $type);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 删除单条审核记录（独立库）
     */
    public static function deleteReview(int $id): void
    {
        try {
            self::db()->prepare('DELETE FROM content_reviews WHERE id = :id')->execute([':id' => $id]);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 批量删除审核记录（独立库）
     * @param int[] $ids
     */
    public static function batchDeleteReviews(array $ids): void
    {
        if (empty($ids)) return;
        try {
            $db = self::db(); // [SplitDB] 独立库
            $ids = array_values(array_map('intval', $ids));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("DELETE FROM content_reviews WHERE id IN ({$in})");
            $stmt->execute($ids);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 获取审核内容标题（通知用）
     */
    private static function getReviewTargetTitle(string $targetType, int $targetId): string
    {
        try {
            if ($targetType === 'thread') {
                $t = (new \app\Models\Thread())->find($targetId);
                return $t ? \htmlspecialchars(mb_substr((string)($t['title'] ?? ''), 0, 50), ENT_QUOTES, 'UTF-8') : '';
            } elseif ($targetType === 'post') {
                $p = (new \app\Models\Post())->find($targetId);
                if ($p) {
                    $plain = strip_tags(\app\Helpers\Content::decode((string)($p['content'] ?? '')));
                    return \htmlspecialchars(mb_substr($plain, 0, 30), ENT_QUOTES, 'UTF-8');
                }
                return '';
            }
            return '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 获取审核内容跳转链接
     */
    public static function getReviewLink(string $targetType, int $targetId): string
    {
        try {
            if ($targetType === 'thread') {
                return '/thread/' . $targetId;
            } elseif ($targetType === 'post') {
                return self::getPostThreadUrl($targetId);
            }
            return '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 获取内容标题/摘要（后台展示用）
     */
    public static function getContentPreview(string $targetType, int $targetId): string
    {
        try {
            if ($targetType === 'thread') {
                $t = (new \app\Models\Thread())->find($targetId);
                return $t ? \htmlspecialchars(mb_substr((string)($t['title'] ?? ''), 0, 60), ENT_QUOTES, 'UTF-8') : '(已删除)';
            } elseif ($targetType === 'post') {
                $p = (new \app\Models\Post())->find($targetId);
                if ($p) {
                    $plain = strip_tags(\app\Helpers\Content::decode((string)($p['content'] ?? '')));
                    return \htmlspecialchars(mb_substr($plain, 0, 60), ENT_QUOTES, 'UTF-8');
                }
                return '(已删除)';
            } elseif ($targetType === 'avatar') {
                return '头像更换申请';
            }
            return '-';
        } catch (\Throwable $e) {
            return '-';
        }
    }

    /**
     * 获取回复所属的帖子链接（后台审核用）
     */
    public static function getPostThreadUrl(int $postId): string
    {
        try {
            $p = (new \app\Models\Post())->find($postId);
            return $p ? '/thread/' . (int)($p['thread_id'] ?? 0) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
