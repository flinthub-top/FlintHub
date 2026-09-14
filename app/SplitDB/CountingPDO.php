<?php
/**
 * FlintHub 1.0 (SplitDB) — 全库 SQL 计数 PDO 包装
 * business / main_index / 分片桶 / session 统一经 DBFactory 创建本类实例，
 * 每次真实数据语句（SELECT/INSERT/UPDATE/DELETE/REPLACE/EXPLAIN）上报
 * Database::recordSql()。
 * 口径说明：页脚展示的是 Database::getQueryCount()（业务库查询数，i18n 键
 * footer.query_count），并非本类累计的全库总数；全库总数 getTotalSqlCount()
 * 仅供 cli 验证脚本诊断使用（不存在"页脚总 SQL"展示）。
 * 连接初始化 PRAGMA / 建表 DDL（CREATE/ALTER/BEGIN/COMMIT 等）由 recordSql
 * 的语句过滤自动排除，口径与旧"业务库查询数"可比。
 *
 * @file app/SplitDB/CountingPDO.php
 * @package app\SplitDB
 */

namespace app\SplitDB;

use PDO;
use PDOStatement;

class CountingPDO extends PDO
{
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        \app\Core\Database::recordSql($query);
        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        \app\Core\Database::recordSql($statement);
        return parent::exec($statement);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        \app\Core\Database::recordSql($query);
        return parent::prepare($query, $options);
    }
}
