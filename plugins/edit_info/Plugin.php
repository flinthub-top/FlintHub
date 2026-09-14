<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 编辑信息插件主类 — 激活/停用、数据表创建、编辑信息查询
 * @file plugins/edit_info/Plugin.php
 * @package Plugin\EditInfo
 * @version 1.0.0
 */

namespace Plugin\EditInfo;

class Plugin
{
    /** [SplitDB] 插件独立库连接（plugins/edit_info/data/edit_info.sqlite） */
    public static function db(): \PDO
    {
        return \app\Helpers\Plugin::db('edit_info');
    }

    /**
     * 激活插件：创建数据表（[SplitDB] SQLite DDL，独立库）
     */
    public static function activate(): bool
    {
        $ddl = "CREATE TABLE IF NOT EXISTS edit_history (
            id INTEGER PRIMARY KEY,
            target_type TEXT NOT NULL,
            target_id INTEGER NOT NULL,
            edited_by INTEGER NOT NULL,
            edited_at TEXT NOT NULL
        )";
        \app\Helpers\Plugin::ensureSchema('edit_info', $ddl);
        self::db()->exec('CREATE INDEX IF NOT EXISTS idx_edit_history_target ON edit_history (target_type, target_id)');
        return true;
    }

    /**
     * 禁用插件（保留数据）
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
        self::db()->exec('DROP TABLE IF EXISTS edit_history');
    }

    /**
     * 获取编辑信息（[SplitDB] edit_history 在独立库，users 在核心库 → 分两次查询合并）
     */
    public static function getEditInfo(string $targetType, int $targetId): ?array
    {
        try {
            $stmt = self::db()->prepare(
                'SELECT * FROM edit_history
                 WHERE target_type = :type AND target_id = :id
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([':type' => $targetType, ':id' => $targetId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) return null;

            // 补用户名（M-1/P2：经 User::batchGetNames 批量封装读核心库 users）
            $names = \app\Models\User::batchGetNames([(int)$row['edited_by']]);
            $row['username'] = $names[(int)$row['edited_by']] ?? '';
            return $row;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 批量获取编辑信息（[SplitDB] 独立库查询 + 核心库批量补用户名）
     */
    public static function getEditInfoBatch(string $targetType, array $targetIds): array
    {
        if (empty($targetIds)) return [];

        try {
            $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
            $params = array_merge([$targetType], array_map('intval', $targetIds));

            $stmt = self::db()->prepare(
                "SELECT * FROM edit_history
                 WHERE target_type = ? AND target_id IN ($placeholders)
                 ORDER BY id DESC"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // 批量补用户名（M-1/P2：经 User::batchGetNames 批量封装读核心库 users）
            $uids = array_unique(array_map(fn($r) => (int)$r['edited_by'], $rows));
            $users = \app\Models\User::batchGetNames($uids);
            foreach ($rows as &$row) {
                $row['username'] = $users[(int)$row['edited_by']] ?? '';
            }
            unset($row);

            $result = [];
            foreach ($rows as $row) {
                $key = $row['target_type'] . '_' . $row['target_id'];
                if (!isset($result[$key])) {
                    $result[$key] = $row;
                }
            }
            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
