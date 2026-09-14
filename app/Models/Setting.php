<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 系统设置模型 — KV 键值对读写、缓存设置
 * @file app/Models/Setting.php
 * @package app\Models
 */

namespace app\Models;

use app\Core\Model;

class Setting extends Model
{
    protected $table = 'settings';

    public function getValue($key, $default = '')
    {
        $result = $this->findWhere('"key" = :key', [':key' => $key]);
        return $result['value'] ?? $default;
    }

    public function setValue($key, $value)
    {
        // UPSERT：单条原子语句替代「先 SELECT 后 INSERT/UPDATE」两步（settings.key 为主键），
        // 消除并发写入时的竞态（先查后插在并发下会因主键冲突抛异常）。
        $db = \app\Core\Database::getInstance();
        return $db->query(
            "INSERT INTO {$this->table} (\"key\", \"value\") VALUES (:key, :val)
             ON CONFLICT(\"key\") DO UPDATE SET value = excluded.value",
            [':key' => $key, ':val' => $value]
        );
    }

    public function getAll()
    {
        $rows = $this->all();
        $result = [];
        foreach ($rows as $row) {
            $result[$row['key']] = $row['value'];
        }
        return $result;
    }
}
