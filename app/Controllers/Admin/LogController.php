<?php
/**
 * FlintHub 1.0 — 后台日志控制器
 * 操作日志查看页：/admin/logs/audit（分页 + 按日期/操作类型筛选）
 * 权限：继承 BaseController 构造器（Auth::isAdmin + 30 分钟二次验证，等价 need_admin）
 * @file app/Controllers/Admin/LogController.php
 * @package app\Controllers\Admin
 */

namespace app\Controllers\Admin;

use app\Helpers\AuditLog;
use app\SplitDB\ShardRouter;

class LogController extends BaseController
{
    /** 每页条数 */
    private const PER_PAGE = 50;

    /** DISTINCT action 缓存 TTL（秒） */
    private const ACTION_CACHE_TTL = 60;

    /**
     * 操作日志列表页
     */
    public function audit()
    {
        // ---- 筛选参数清洗（白名单 + intval） ----
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $action = (string)($_GET['action'] ?? '');
        $date   = (string)($_GET['date'] ?? '');

        // action 白名单：仅小写字母 + 下划线（非法置空）
        if ($action !== '' && !preg_match('/^[a-z_]{1,32}$/', $action)) {
            $action = '';
        }
        // date 白名单：YYYY-MM-DD（非法置空）
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = '';
        }

        // ---- 查询 ----
        $audit = AuditLog::query($action, 0, $page, self::PER_PAGE, $date);
        $total = (int)$audit['total'];
        $totalPages = max(1, (int)ceil($total / self::PER_PAGE));
        if ($page > $totalPages) {
            $page = $totalPages; // 越界回落最后一页
            $audit = AuditLog::query($action, 0, $page, self::PER_PAGE, $date);
        }

        $this->view('admin/logs/audit', [
            'items'      => $audit['items'],
            'total'      => $total,
            'page'       => $page,
            'totalPages' => $totalPages,
            'action'     => $action,
            'date'       => $date,
            'actions'    => $this->distinctActions(),
            '__nav_active' => 'logs_audit',
        ]);
    }

    /**
     * 操作类型下拉数据源：audit_logs 表 DISTINCT action（60s 文件缓存）
     * 免维护：新操作类型自动出现；走 COVERING INDEX idx_audit_logs_action
     */
    private function distinctActions(): array
    {
        $cacheFile = rtrim(ShardRouter::dataPath(), '/\\') . '/runtime/cache_audit_actions.json';
        if (is_file($cacheFile)) {
            $cached = @json_decode(@file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['ts']) && (time() - (int)$cached['ts']) < self::ACTION_CACHE_TTL) {
                return $cached['actions'] ?? [];
            }
        }

        $actions = [];
        try {
            $db = \app\Core\Database::getInstance();
            $rows = $db->fetchAll('SELECT DISTINCT action FROM audit_logs WHERE action != \'\' ORDER BY action ASC');
            foreach ($rows as $r) {
                $actions[] = (string)$r['action'];
            }
        } catch (\Throwable $e) {
            \error_log('LogController distinctActions failed: ' . $e->getMessage());
        }

        $dir = dirname($cacheFile);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents($cacheFile, json_encode(['ts' => time(), 'actions' => $actions], JSON_UNESCAPED_UNICODE), LOCK_EX);

        return $actions;
    }
}
