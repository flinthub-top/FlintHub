<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 后台控制器基类 — 管理员权限验证、公共方法
 * @file app/Controllers/Admin/BaseController.php
 * @package app\Controllers\Admin
 */

namespace app\Controllers\Admin;
use app\Core\Controller;
use app\Helpers\Auth;
use app\Core\Database;

class BaseController extends Controller
{
    public function __construct()
    {
        if (!Auth::isAdmin()) {
            // 记录登录来源（管理员登录成功后回跳原页面）
            \app\Helpers\Auth::rememberRedirectAfter();
            \header('Location: ' . (\defined('BASE_PATH') ? \BASE_PATH : '') . '/login');
            exit;
        }

        // 管理员二次验证超时由后台设置 admin_verify_timeout 动态控制（标准 1800s / 长任务 2592000s），限幅 60s ~ 7 天
        $verifyTimeout = (int)\app\Helpers\Settings::get('admin_verify_timeout', '1800');
        $verifyTimeout = \max(60, \min($verifyTimeout, 604800));

        // 获取当前路由路径（不含查询参数），用于判断是否需要二次验证
        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $uriPath = rtrim($uriPath, '/');

        // 除了 /admin/verify 和 /admin/verify/ 之外，都需要二次密码验证
        if ($uriPath !== '/admin/verify' && $uriPath !== '/admin/verify/' ) {
            $verifiedAt = $_SESSION['admin_verified_at'] ?? 0;
            if ($verifiedAt < time() - $verifyTimeout) {
                $_SESSION['admin_redirect_after_verify'] = $_SERVER['REQUEST_URI'];
                session_write_close();
                \header('Location: ' . (\defined('BASE_PATH') ? \BASE_PATH : '') . '/admin/verify');
                exit;
            }
        }
    }

    /**
     * 批量移动记录到目标分类（公共方法，消除 ThreadController / BlogController 重复代码）
     * @param string $table 表名（threads / blogs）
     * @param string $idField POST 提交的 ID 数组字段名（thread_ids / blog_ids）
     * @param string $redirectUrl 重定向地址
     * @param string $msgPrefix 消息前缀（moved_）
     * @param callable|null $afterMove 移动后的回调（可选，用于同步搜索索引等）
     * @param string $extraQuery 追加到重定向 URL 的查询串（如 category_id=3，不含 & 开头）
     */
    protected function batchMoveIds(string $table, string $idField, string $redirectUrl, string $msgPrefix = 'moved_', ?callable $afterMove = null, string $extraQuery = ''): void
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $ids = $_POST[$idField] ?? [];
        $targetCategoryId = (int)($_POST['target_category_id'] ?? 0);
        if (is_array($ids) && !empty($ids) && $targetCategoryId > 0) {
            $ids = array_slice($ids, 0, 500); // 上限 500 条，防超长 SQL
            // 全部转 int，杜绝非数字值混入 SQL 参数
            $ids = array_map('intval', array_values($ids));

            if ($table === 'threads') {
                // threads 已分片（数据在 bucket/*.sqlite + main_index.topic_index），
                // 单条 UPDATE 打在 business.sqlite 的退役 threads 表上会静默失效；
                // 改为：按 main_index.bucket_path 分桶分组 → 逐桶 UPDATE topic + main_index.topic_index 同步
                $this->batchMoveThreads($ids, $targetCategoryId);
            } else {
                // 非分片表（如 blogs）维持单条 UPDATE
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $db = Database::getInstance();
                $db->query(
                    "UPDATE {$table} SET category_id = ? WHERE id IN ({$placeholders})",
                    array_merge([$targetCategoryId], $ids)
                );
            }

            if ($afterMove) {
                foreach ($ids as $id) {
                    $afterMove($id);
                }
            }
            // msg 统一为 ?msg=moved_N 格式（与视图 $_GET['msg'] 解析一致）；
            // $extraQuery（如 category_id=X）拼在 msg 之前，保证操作后仍停留在筛选分类
            $qs = 'msg=' . $msgPrefix . count($ids);
            if ($extraQuery !== '') $qs = $extraQuery . '&' . $qs;
            $this->redirect($redirectUrl . '?' . $qs);
        }
        $this->redirect($redirectUrl);
    }

    /**
     * 批量移动帖子分类：按 main_index.bucket_path 分桶分组，
     * 逐桶 UPDATE topic + main_index.topic_index 同步，并联动失效列表缓存
     *
     * @param array $ids 帖子 ID 列表（已 int 化）
     * @param int   $targetCategoryId 目标分类 ID
     */
    private function batchMoveThreads(array $ids, int $targetCategoryId): void
    {
        $mi = \app\SplitDB\Schema::mainIndexDb();
        if (empty($ids)) return;

        $ph = implode(',', array_fill(0, count($ids), '?'));

        // 1. 从 main_index 读取 bucket_path（读取路由唯一依据，白皮书 5.3），按桶分组
        $stmt = $mi->prepare("SELECT id, bucket_path FROM topic_index WHERE id IN ({$ph})");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $groups = []; // bucket_path => int ids
        foreach ($rows as $r) {
            $groups[(string)$r['bucket_path']][] = (int)$r['id'];
        }

        // 2. 逐桶 UPDATE topic（单桶事务，失败回滚该桶并记日志，不中断其他桶）
        foreach ($groups as $bucketPath => $bucketIds) {
            $bph = implode(',', array_fill(0, count($bucketIds), '?'));
            try {
                $bdb = \app\SplitDB\Schema::bucketFromPath($bucketPath);
                $bdb->beginTransaction();
                $bdb->prepare("UPDATE topic SET category_id = ? WHERE id IN ({$bph})")
                    ->execute(array_merge([$targetCategoryId], $bucketIds));
                $bdb->commit();
            } catch (\Throwable $e) {
                \error_log('[batchMoveIds] bucket update failed (' . $bucketPath . '): ' . $e->getMessage());
            }
        }

        // 3. main_index.topic_index 同步（单库，一条 UPDATE 覆盖全部 ID）
        $stmt = $mi->prepare("UPDATE topic_index SET category_id = ? WHERE id IN ({$ph})");
        $stmt->execute(array_merge([$targetCategoryId], $ids));

        // 4. 联动：列表页静态缓存 / 版块统计失效（与 Thread::update 一致）
        try {
            \app\Helpers\PageCache::invalidate();
            \app\Models\Category::invalidateCategoryStats();
        } catch (\Throwable $e) {
            // 缓存失效失败不影响移动主流程
        }
    }
}
