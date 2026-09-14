<?php
/**
 * FlintHub 1.0 (SplitDB) — 桶扩容服务
 * 独立于控制器层：Model（Post::guardBucketCapacity）与后台控制器共同调用，
 * 消除「Model → Controller」反向依赖（原逻辑在 Admin/DatabaseController 静态方法内）。
 *
 * 职责：
 *   - currentBucketSize()   读 config.php 当前桶数（文件为准）
 *   - updateBucketSize()    安全写 config.php 桶数（备份→替换→写回→opcache 失效 + 防重入锁）
 *   - emergencyExpand()     紧急扩容（桶负载 ≥90% 触发，不受自动扩容开关限制）
 *
 * @file app/SplitDB/BucketAutoScaler.php
 * @package app\SplitDB
 */

namespace app\SplitDB;

class BucketAutoScaler
{
    /**
     * 项目根目录（本文件位于 app/SplitDB/，上溯两级即根；与 config.php 同级）
     */
    private static function rootPath(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * 读取 config.php 中当前配置桶数（文件为准；文件缺失/无法解析时回退常量，再回退 32）
     */
    public static function currentBucketSize(): int
    {
        $file = self::rootPath() . '/config.php';
        if (is_file($file)) {
            $content = @file_get_contents($file);
            if ($content !== false && preg_match("/define\(\s*'SPLITDB_BUCKET_SIZE'\s*,\s*(\d+)\s*\);/", $content, $m)) {
                return (int)$m[1];
            }
            if ($content !== false) {
                // config.php 存在但正则匹配失败 → 发声（否则误以为读到了配置、静默回落常量无迹可查）
                \error_log('SplitDB 扩容: config.php 存在但无法匹配 SPLITDB_BUCKET_SIZE，回落常量');
            }
        }
        return defined('SPLITDB_BUCKET_SIZE') ? (int)SPLITDB_BUCKET_SIZE : 32;
    }

    /**
     * 安全更新 config.php 的 SPLITDB_BUCKET_SIZE（供手动调整与自动/紧急扩容共用）
     * 四步：备份 → 正则替换 → 写回（失败不覆盖）→ opcache 失效（绝对路径）
     * 前置：auto_expand.lock 防重入（获取失败立即放弃，防并发写坏/跳级扩容）
     *
     * @return array ['ok' => bool, 'msg' => string]
     */
    public static function updateBucketSize(int $newSize): array
    {
        // 防御性兜底校验（正常入口 handleSaveBuckets / maybeAutoExpand / emergencyExpand 已校验）
        if (!in_array($newSize, [32, 64, 128, 256], true)) {
            return ['ok' => false, 'msg' => \app\Helpers\I18n::get('admin.db_bucket_invalid')];
        }
        $file = self::rootPath() . '/config.php';
        if (!is_file($file)) return ['ok' => false, 'msg' => \app\Helpers\I18n::get('admin.db_config_missing')];
        if (!is_writable($file)) return ['ok' => false, 'msg' => \app\Helpers\I18n::get('admin.db_config_not_writable_msg')];
        $oldSize = self::currentBucketSize();
        if ($newSize < $oldSize) {
            return ['ok' => false, 'msg' => \app\Helpers\I18n::get('admin.db_bucket_no_shrink')];
        }

        // 防重入锁（手动调整与自动/紧急扩容共用 auto_expand.lock）
        $lockDir = rtrim(ShardRouter::dataPath(), '/\\') . '/lock';
        // mkdir 失败必须发声，否则 fopen 会静默失败导致「以为拿到锁、实际没锁」并发写坏
        if (!is_dir($lockDir) && !@mkdir($lockDir, 0755, true)) {
            \error_log('SplitDB 扩容: 创建 lock 目录失败: ' . $lockDir);
        }
        $lock = @fopen($lockDir . '/auto_expand.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) @fclose($lock);
            return ['ok' => false, 'msg' => \app\Helpers\I18n::get('admin.db_expand_busy')];
        }

        try {
            // ① 备份
            $bakDir = self::rootPath() . '/protected/backups';
            // mkdir 失败必须发声，否则备份 @copy 静默失败且后续无备份可用
            if (!is_dir($bakDir) && !@mkdir($bakDir, 0755, true)) {
                \error_log('SplitDB 扩容: 创建备份目录失败: ' . $bakDir);
            }
            $bak = $bakDir . '/config.php.bak.' . date('Ymd_His') . '.' . bin2hex(random_bytes(4));
            @copy($file, $bak);

            // ② 正则替换（保留行内注释；限 1 次，防误伤）
            $content = @file_get_contents($file);
            if ($content === false) {
                return ['ok' => false, 'msg' => \app\Helpers\I18n::get('admin.db_config_read_failed')];
            }
            $pattern = "/define\(\s*'SPLITDB_BUCKET_SIZE'\s*,\s*\d+\s*\);/";
            $replaced = 0;
            $newContent = preg_replace($pattern, "define('SPLITDB_BUCKET_SIZE', {$newSize});", $content, 1, $replaced);
            if ($replaced !== 1) {
                return ['ok' => false, 'msg' => \app\Helpers\I18n::get('admin.db_config_constant_missing')];
            }

            // 扩容时间戳 + 扩容前桶数（智能扩容路由 bucketForWrite 依据）：
            // 存在则替换、缺失则追加在 SPLITDB_BUCKET_SIZE 行之后（同一文件一次写回，备份已先行）
            $newContent = self::upsertDefine($newContent, 'SPLITDB_LAST_EXPANSION_AT', (string)time(), 'SPLITDB_BUCKET_SIZE');
            $newContent = self::upsertDefine($newContent, 'SPLITDB_PREV_BUCKET_SIZE', (string)$oldSize, 'SPLITDB_BUCKET_SIZE');

            // ③ 写回（LOCK_EX；失败则原文件未动，备份保留）
            if (@file_put_contents($file, $newContent, LOCK_EX) === false) {
                return ['ok' => false, 'msg' => \app\Helpers\I18n::get('admin.db_config_write_failed', ['bak' => basename($bak)])];
            }

            // ④ opcache 失效（必须传绝对物理路径，防相对路径缓存键不匹配）
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate(realpath($file), true);
            }

            return ['ok' => true, 'msg' => \app\Helpers\I18n::get('admin.db_bucket_updated')];
        } finally {
            flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /**
     * 紧急扩容（旧帖爆火安全余量：load ≥ 90% 触发）
     * 不受 auto_expand_enabled 开关限制（紧急必须扩）；保留 24h 守卫 + flock 防重入 + 翻倍 ≤256；
     * 供 Post::insert guardBucketCapacity 调用（尽力而为，失败不阻塞回复）。
     *
     * @param int $load     当前桶负载（topic 行数）
     * @param int $capacity 单桶容量（bucket_safe_capacity）
     * @return string 执行结果消息（未满足条件时返回 ''）
     */
    public static function emergencyExpand(int $load = 0, int $capacity = 0): string
    {
        try {
            // 24 小时间隔守卫（与常规自动扩容共用 auto_expand_last_at，防回复洪峰反复触发）
            $lastAt = (int)\app\Helpers\Settings::get('auto_expand_last_at', '0');
            if ($lastAt > 0 && (time() - $lastAt) < 86400) return '';

            $current = self::currentBucketSize();
            $newSize = $current * 2;
            if ($newSize > 256) return \app\Helpers\I18n::get('admin.db_emergency_maxed');

            $r = self::updateBucketSize($newSize);
            if (!$r['ok']) {
                \error_log('SplitDB 紧急扩容失败: ' . $r['msg']);
                return $r['msg'];
            }
            $setting = new \app\Models\Setting();
            $setting->setValue('auto_expand_last_at', (string)time());
            \app\Helpers\Settings::buildCache();
            \app\Helpers\AuditLog::log('emergency_expand', 'database', 0, "紧急扩容: 桶数 {$current} → {$newSize}（桶负载 {$load} / 容量 {$capacity} ≥ 90%）");
            return \app\Helpers\I18n::get('admin.db_emergency_done', ['from' => $current, 'to' => $newSize]);
        } catch (\Throwable $e) {
            \error_log('BucketAutoScaler::emergencyExpand error: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 幂等写入 config.php 的 define 常量行：存在则替换、缺失则追加在锚点行之后
     * （供 updateBucketSize 写 SPLITDB_LAST_EXPANSION_AT / SPLITDB_PREV_BUCKET_SIZE 使用）
     *
     * @param string $content 当前 config 全文
     * @param string $name    常量名（不带引号）
     * @param string $value   常量值（原样拼接，数字）
     * @param string $anchor  锚点常量名（缺失时追加在其 define 行之后）
     */
    private static function upsertDefine(string $content, string $name, string $value, string $anchor): string
    {
        // $value 只允许纯数字（常量值拼进 config.php 前必须白名单校验，
        // 防止意外注入污染配置文件；非纯数字直接返回原内容不落盘）
        if (!preg_match('/^\d+$/', (string)$value)) {
            \error_log("SplitDB 扩容: upsertDefine 拒绝非数字值 {$name}=" . var_export($value, true));
            return $content;
        }

        $pattern = "/define\(\s*'{$name}'\s*,\s*[^;]+\);/";
        if (preg_match($pattern, $content)) {
            return (string)preg_replace($pattern, "define('{$name}', {$value});", $content, 1);
        }
        $anchorPattern = "/define\(\s*'{$anchor}'\s*,\s*[^;]+\);/";
        if (preg_match($anchorPattern, $content, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr($content, 0, $pos) . "\n" . "define('{$name}', {$value});" . substr($content, $pos);
        }
        return $content . "\n" . "define('{$name}', {$value});";
    }
}
