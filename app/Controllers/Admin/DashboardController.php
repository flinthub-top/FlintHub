<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 后台仪表盘控制器 — 站点统计数据展示
 * @file app/Controllers/Admin/DashboardController.php
 * @package app\Controllers\Admin
 */

namespace app\Controllers\Admin;

use app\Core\Database;
use app\Helpers\Settings;
use app\Models\User;

class DashboardController extends BaseController
{
    public function index()
    {
        $stats = [
            'users' => Settings::getTotalUsers(),
            'threads' => Settings::getTotalThreads(),
            'posts' => Settings::getTotalPosts(),
            'blogs' => Settings::getTotalBlogs(),
            'blogComments' => Settings::getBlogCommentsCount(),
            'pluginsInstalled' => count(\app\Helpers\Plugin::getPlugins()),
            'pluginsActive' => self::countActivePlugins(),
            'onlineUsers' => Settings::getOnlineData()['count'] ?? 0,
            'dbSize' => Settings::getDbSize(),
        ];

        $latestUsers = (new User())->query('SELECT id, username, email, role, created_at FROM users ORDER BY created_at DESC LIMIT 5');

        // 最新帖子改读 main_index.topic_index（分片真相源）：
        // business.threads 表已退役（不再写入），直查该表对分片后新帖恒为空。
        // 与 Admin/ThreadController::index 口径一致；按原「最新创建」语义排序（created_at DESC）。
        $latestThreads = [];
        $mi = \app\SplitDB\Schema::mainIndexDb();
        $stmt = $mi->query(
            'SELECT id, uid, title, category_id, reply_count, view_count, create_time
             FROM topic_index
             WHERE status = 0 AND deleted_at IS NULL
             ORDER BY create_time DESC, id DESC
             LIMIT 10'
        );
        $indexRows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        if (!empty($indexRows)) {
            // 批量补用户/分类（business 库一次 IN 查询，避免 N+1）
            $bdb = Database::getInstance();
            $uids = array_values(array_unique(array_map(fn($r) => (int)$r['uid'], $indexRows)));
            $cids = array_values(array_unique(array_map(fn($r) => (int)$r['category_id'], $indexRows)));
            $userNames = [];
            if (!empty($uids)) {
                $marks = implode(',', array_fill(0, count($uids), '?'));
                foreach ($bdb->fetchAll('SELECT id, username FROM users WHERE id IN (' . $marks . ')', $uids) as $u) {
                    $userNames[(int)$u['id']] = (string)$u['username'];
                }
            }
            $catNames = [];
            if (!empty($cids)) {
                $marks = implode(',', array_fill(0, count($cids), '?'));
                foreach ($bdb->fetchAll('SELECT id, name FROM categories WHERE id IN (' . $marks . ')', $cids) as $c) {
                    $catNames[(int)$c['id']] = (string)$c['name'];
                }
            }
            foreach ($indexRows as $r) {
                $latestThreads[] = [
                    'id'            => (int)$r['id'],
                    'title'         => (string)$r['title'],
                    'reply_count'   => (int)$r['reply_count'],
                    'view_count'    => (int)$r['view_count'],
                    'username'      => $userNames[(int)$r['uid']] ?? '',
                    'category_name' => $catNames[(int)$r['category_id']] ?? '',
                    'created_at'    => date('Y-m-d H:i:s', (int)$r['create_time']),
                ];
            }
        }

        $this->view('admin/index', [
            'stats' => $stats,
            'latestUsers' => $latestUsers,
            'latestThreads' => $latestThreads,
            'env' => $this->collectEnvInfo(),
            '__nav_active' => 'dashboard',
        ]);
    }

    /**
     * 统计已启用插件数（一次 getPlugins() 遍历；degrades 到 0 不阻塞）
     */
    private static function countActivePlugins(): int
    {
        try {
            $plugins = \app\Helpers\Plugin::getPlugins();
            return (int)\count(\array_filter($plugins, static function ($p) {
                return !empty($p['activated']);
            }));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 收集站点环境信息（控制台"站点环境信息"区块）
     */
    private function collectEnvInfo(): array
    {
        $unknown = \app\Helpers\I18n::get('admin.unknown');
        $items = [
            ['label' => \app\Helpers\I18n::get('admin.env_version'), 'value' => FLINTHUB_VERSION],
            ['label' => \app\Helpers\I18n::get('admin.env_site_name'), 'value' => Settings::get('site_name', DEFAULT_SITE_NAME)],
            ['label' => \app\Helpers\I18n::get('admin.env_site_url'), 'value' => SITE_URL],
            ['label' => \app\Helpers\I18n::get('admin.env_web_server'), 'value' => $_SERVER['SERVER_SOFTWARE'] ?? $unknown],
            ['label' => \app\Helpers\I18n::get('admin.env_os'), 'value' => PHP_OS . ' ' . php_uname('r')],
            ['label' => \app\Helpers\I18n::get('admin.env_php_version'), 'value' => PHP_VERSION],
            ['label' => \app\Helpers\I18n::get('admin.env_php_sapi'), 'value' => php_sapi_name()],
            ['label' => \app\Helpers\I18n::get('admin.env_memory_limit'), 'value' => ini_get('memory_limit') ?: $unknown],
            ['label' => \app\Helpers\I18n::get('admin.env_upload_limit'), 'value' => (ini_get('upload_max_filesize') ?: $unknown) . \app\Helpers\I18n::get('admin.env_upload_limit_upper', ['max' => MAX_FILE_SIZE / 1048576])],
            ['label' => \app\Helpers\I18n::get('admin.env_database'), 'value' => \app\Helpers\I18n::get('admin.env_db_value')],
            ['label' => \app\Helpers\I18n::get('admin.env_data_dir'), 'value' => \app\SplitDB\ShardRouter::dataPath()],
            ['label' => \app\Helpers\I18n::get('admin.env_db_version'), 'value' => $this->getDbVersion()],
            ['label' => \app\Helpers\I18n::get('admin.env_timezone'), 'value' => date_default_timezone_get()],
            ['label' => \app\Helpers\I18n::get('admin.env_current_theme'), 'value' => DEFAULT_THEME],
        ];
        return $items;
    }

    /**
     * 查询 SQLite 版本（失败时返回空串，不阻塞页面）
     */
    private function getDbVersion(): string
    {
        try {
            $row = Database::getInstance()->fetchOne('SELECT sqlite_version() AS v');
            return $row['v'] ?? '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
