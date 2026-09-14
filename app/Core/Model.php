<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 基础 ORM 模型 — CRUD 封装、参数化查询、安全排序
 * @file app/Core/Model.php
 * @package app\Core
 */

namespace app\Core;

class Model
{
    protected $db;
    protected $table;
    protected $primaryKey = 'id';
    /** 可更新列白名单：子类必须声明；update() 对白名单外键忽略并记日志（fail-closed） */
    protected $fillable = [];

    public function __construct()
    {
        $this->db = \app\Core\Database::getInstance();
    }

    public function find($id): ?array
    {
        $stmt = $this->db->fetchOne(
            "SELECT * FROM \"{$this->table}\" WHERE \"{$this->primaryKey}\" = :id",
            [':id' => $id]
        );
        return $stmt;
    }

    public function all($orderBy = '', $limit = 0): array
    {
        $sql = "SELECT * FROM \"{$this->table}\"";
        if ($orderBy) {
            $orderBy = self::sanitizeOrderBy($orderBy);
            $sql .= " ORDER BY {$orderBy}";
        }
        if ($limit > 0) {
            $sql .= " LIMIT " . (int)$limit;
        }
        return $this->db->fetchAll($sql);
    }

    public function findWhere($conditions, $params = [])
    {
        $where = $this->buildWhere($conditions);
        $sql = "SELECT * FROM \"{$this->table}\" WHERE {$where} LIMIT 1";
        return $this->db->fetchOne($sql, $params);
    }

    public function findAllWhere($conditions, $params = [], $orderBy = '', $limit = 0)
    {
        $where = $this->buildWhere($conditions);
        $sql = "SELECT * FROM \"{$this->table}\" WHERE {$where}";
        if ($orderBy) {
            $orderBy = self::sanitizeOrderBy($orderBy);
            $sql .= " ORDER BY {$orderBy}";
        }
        if ($limit > 0) {
            $sql .= " LIMIT " . (int)$limit;
        }
        return $this->db->fetchAll($sql, $params);
    }

    public function insert($data)
    {
        // 列名白名单校验：防止非预期字段拼入 SQL
        foreach ($data as $key => $value) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string)$key)) {
                unset($data[$key]);
            }
        }
        if (empty($data)) {
            throw new \RuntimeException('插入数据为空或包含非法列名');
        }
        // 列名加双引号标识符引用（SQLite 标准），防保留字（如 key、order）报错
        $columns = '"' . implode('", "', array_keys($data)) . '"';
        $placeholders = ':' . implode(', :', array_keys($data));

        $sql = "INSERT INTO \"{$this->table}\" ({$columns}) VALUES ({$placeholders})";
        $this->db->query($sql, $data);

        return $this->db->lastInsertId();
    }

    /**
     * 更新记录（$fillable 列白名单，fail-closed 防批量赋值面）
     *
     * 子类必须声明 $fillable 可更新列；白名单外键忽略并记日志，绝不允许直接传入
     * 请求数组修改 role/group_id/status/points 等敏感字段（原实现仅做键名字符校验）。
     * $fillable 为空（未声明）时抛异常拒绝更新（宁可报错也不放开赋值面）。
     * 空 $data / 全部键被白名单过滤时返回 0（不拼出 `SET  WHERE` 非法 SQL）。
     */
    public function update($id, $data)
    {
        if (empty($this->fillable)) {
            throw new \RuntimeException("Model::update 拒绝：{$this->table} 未声明 \$fillable 列白名单（防批量赋值）");
        }
        $filtered = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $this->fillable, true)) {
                $filtered[$key] = $value;
            } else {
                \error_log("Model::update 忽略白名单外列 {$this->table}.{$key}（不在 \$fillable）");
            }
        }
        if (empty($filtered)) {
            return 0; // 无任何可更新列，不拼非法 SQL
        }
        $sets = '';
        foreach ($filtered as $key => $value) {
            // 安全清洗键名，防止字段名注入（同 insert() 的防护级别）
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                continue;
            }
            $sets .= "\"{$key}\" = :{$key}, ";
        }
        $sets = rtrim($sets, ', ');
        if ($sets === '') {
            return 0; // 全部键被过滤 → 无 SET 子句
        }

        $filtered[$this->primaryKey] = $id;
        $sql = "UPDATE \"{$this->table}\" SET {$sets} WHERE \"{$this->primaryKey}\" = :{$this->primaryKey}";
        return $this->db->query($sql, $filtered);
    }

    public function delete($id)
    {
        $sql = "DELETE FROM \"{$this->table}\" WHERE \"{$this->primaryKey}\" = :id";
        return $this->db->query($sql, [':id' => $id]);
    }

    /**
     * 统计（非字符串条件显式走 buildWhere，其余类型抛错，杜绝 `WHERE Array`）
     */
    public function count($conditions = '', $params = [])
    {
        if (\is_string($conditions)) {
            $where = $conditions === '' ? '' : 'WHERE ' . $this->buildWhere($conditions);
        } elseif (\is_array($conditions)) {
            // 数组条件：buildWhere 生成 `:key` 占位符，键值合并进参数供绑定
            $where = 'WHERE ' . $this->buildWhere($conditions);
            foreach ($conditions as $key => $value) {
                if (\preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string)$key)) {
                    $params[':' . $key] = $value;
                }
            }
        } else {
            throw new \InvalidArgumentException('Model::count 条件仅支持字符串或数组');
        }
        $sql = "SELECT COUNT(*) as count FROM \"{$this->table}\" {$where}";
        $result = $this->db->fetchOne($sql, $params);
        return (int)($result['count'] ?? 0);
    }

    public function query($sql, $params = [])
    {
        return $this->db->fetchAll($sql, $params);
    }

    public function queryOne($sql, $params = [])
    {
        return $this->db->fetchOne($sql, $params);
    }

    public function execute($sql, $params = [])
    {
        return $this->db->query($sql, $params);
    }

    protected function buildWhere($conditions)
    {
        if (is_string($conditions)) {
            // 白名单解析器（替代原"字符集过滤"——原正则放行 OR/子查询/字面量，形同虚设）：
            // 仅允许「[表前缀.]标识符 比较运算符 :占位符」以 AND 连接的固定格式，
            // 标识符可为裸名或反引号/双引号包裹（保留字列如 settings.key 必须加引号）。
            // 拒绝 OR、IN、子查询、字面量值、函数调用等一切其他形态 → 抛异常（fail-closed）。
            $trimmed = trim($conditions);
            if ($trimmed === '') {
                throw new \InvalidArgumentException('buildWhere: 字符串条件为空');
            }
            $parts = preg_split('/\s+AND\s+/i', $trimmed);
            $valid = '/^([a-zA-Z_][a-zA-Z0-9_]*\.)?(?:`[a-zA-Z0-9_]+`|"[a-zA-Z0-9_]+"|[a-zA-Z_][a-zA-Z0-9_]*)\s*(?:=|!=|<>|<=|>=|<|>)\s*:[a-zA-Z_][a-zA-Z0-9_]*$/';
            foreach ($parts as $part) {
                if (preg_match($valid, trim($part)) !== 1) {
                    throw new \InvalidArgumentException('buildWhere: 字符串条件仅允许「列名 比较运算符 :占位符」AND 连接，如 "key" = :key / t.id = :id');
                }
            }
            return $trimmed;
        }
        if (is_array($conditions)) {
            $parts = [];
            foreach ($conditions as $key => $value) {
                // 防数组 key 注入（如 "id or 1=1--"）；占位符须以字母/下划线开头（PDO 命名参数规范）
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string)$key)) {
                    continue;
                }
                $parts[] = "{$key} = :{$key}";
            }
            if (empty($parts)) {
                // 空数组/全非法键 → fail-closed，与字符串分支一致，绝不回退 WHERE 1 全表匹配
                throw new \InvalidArgumentException('buildWhere: 数组条件为空或全为非法键，拒绝全表匹配');
            }
            return implode(' AND ', $parts);
        }
        // 其余类型（null/数字/布尔）一律拒绝，防止隐式全表匹配
        throw new \InvalidArgumentException('buildWhere: 条件仅支持字符串或数组');
    }

    /**
     * 安全过滤 ORDER BY 子句
     * 只允许字母、数字、下划线、点、逗号、空格、ASC/DESC
     */
    protected static function sanitizeOrderBy($orderBy)
    {
        return \preg_replace('/[^a-zA-Z0-9_.,\s]/', '', $orderBy);
    }
}
