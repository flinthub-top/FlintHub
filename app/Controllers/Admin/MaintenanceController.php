<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 后台维护工具控制器 — 缓存清理、数据重建、搜索索引
 * @file app/Controllers/Admin/MaintenanceController.php
 * @package app\Controllers\Admin
 */

namespace app\Controllers\Admin;

use app\Helpers\Csrf;
use app\Helpers\Settings;
use app\Helpers\Search;
use app\Helpers\Tag;

class MaintenanceController extends BaseController
{
    public function index()
    {
        $message = '';
        $error = '';

        // ========== POST 操作（CSRF Token 不暴露在 URL 中） ==========
        $action = $_POST['action'] ?? '';

        if ($action === 'rebuild_tags') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            try {
                $message = Tag::rebuild();
            } catch (\Exception $e) {
                $error = \app\Helpers\I18n::get('admin.mt_rebuild_failed', ['err' => $e->getMessage()]);
            }
        }

        if ($action === 'clear_cache') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            try {
                Settings::buildCache();
                if (\function_exists('opcache_reset')) {
                    @\opcache_reset();
                }
                // 联动清空游客页面静态缓存（data/runtime/pages）与版块统计快照：
                // 发帖/回帖会自动失效，但纯设置/模板改动后前台可能仍显示旧缓存，需手动一键清除
                \app\Helpers\PageCache::invalidate();
                \app\Models\Category::invalidateCategoryStats();
                $cacheFile = __DIR__ . '/../../../protected/settings_cache.php';
                $size = \file_exists($cacheFile) ? \filesize($cacheFile) : 0;
                $message = \app\Helpers\I18n::get('admin.mt_cache_updated', ['size' => \round($size / 1024, 2)]);
            } catch (\Exception $e) {
                $error = \app\Helpers\I18n::get('admin.mt_cache_failed', ['err' => $e->getMessage()]);
            }
        }

        // 重建运行时计数器（两步模式：先写标记，重定向后执行）
        $protectedDir = realpath(__DIR__ . '/../../../protected');
        $pendingFile = $protectedDir . '/.rebuild_pending';

        if ($action === 'runtime_rebuild') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            file_put_contents($pendingFile, time());
            $this->redirect('/admin/maintenance?rebuild_pending=1');
            return;
        }

        if (isset($_GET['rebuild_pending']) && file_exists($pendingFile)) {
            // 移除 CSRF 校验：此分支由 runtime_rebuild POST 重定向触发，CSRF 已在原始请求中校验
            try {
                $keys = ['total_users', 'total_threads', 'total_posts', 'total_blogs'];
                foreach ($keys as $key) {
                    Settings::runtimeBuildSingle($key);
                }
                Settings::buildCache();
                @unlink($pendingFile);
                $message = \app\Helpers\I18n::get('admin.mt_stats_rebuilt');
            } catch (\Exception $e) {
                @unlink($pendingFile);
                $error = \app\Helpers\I18n::get('admin.mt_rebuild_failed', ['err' => $e->getMessage()]);
            }
        }

        // 清理孤儿附件
        if ($action === 'cleanup_attachments') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            try {
                [$deleted, $freed] = Settings::cleanupOrphanAttachments();
                $freedKb = \round($freed / 1024, 1);
                $message = \app\Helpers\I18n::get('admin.mt_cleanup_done', ['deleted' => $deleted, 'freed' => $freedKb]);
            } catch (\Exception $e) {
                $error = \app\Helpers\I18n::get('admin.mt_cleanup_failed', ['err' => $e->getMessage()]);
            }
        }

        $this->view('admin/maintenance', [
            'message' => $message, 'error' => $error,
            '__nav_active' => 'maintenance',
        ]);
    }
}
