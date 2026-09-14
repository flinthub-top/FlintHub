<?php
/**
 * FlintHub 1.0 — 轻量级 PHP 社区系统（SplitDB 纯 SQLite 分片引擎）
 * 前端控制器 — 路由分发入口
 * @file index.php
 * @package FlintHub
 */

// 服务器端渲染计时起点：从 index.php 接收请求开始计，
// 页面底部"页面渲染耗时" = microtime(true) - APP_START_TIME（不含 Nginx 传输与浏览器解析）
define('APP_START_TIME', microtime(true));
if (!file_exists(__DIR__ . '/config.php') && PHP_SAPI !== 'cli') {
    header('Location: /install.php');
    exit;
}

// 前置静态缓存：纯游客（无任何 Cookie）的 GET 列表请求命中 pages/ 缓存 → 直接输出并退出，
// 完全跳过 init.php（Session/DB/插件/在线状态）等框架引导 —— 命中约 3~10ms。
// 永久缓存：仅在发帖/回帖时由 PageCache::invalidate() 主动失效；
// 仅拦截 GET 列表页（首页/论坛/版块）；带 Cookie（会话/登录态）一律走完整管线，无用户态泄露。
// 前置缓存可命中条件：纯游客（无任何 Cookie），或「有会话 Cookie 但未登录」的游客
$canFrontServe = false;
if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if (empty($_COOKIE)) {
        $canFrontServe = true; // 纯游客：无会话/记住我 Cookie
    } elseif (empty($_COOKIE['remember_token']) && empty($_COOKIE['remember_user_id'])) {
        // 有会话 Cookie 但未登录（且无记住我）→ 直读 sessions.sqlite 判定，避免走完整引导
        $sid = $_COOKIE[session_name()] ?? '';
        // $sid 格式校验：PHP 合法会话 ID 字符集为 [a-zA-Z0-9,-]（长度 20~128），
        // 伪造/畸形 $sid 直接跳过会话查询（按游客命中前置缓存），防攻击者借伪造 Cookie 绕过缓存制造穿透（CC）
        if ($sid !== '' && preg_match('/^[a-zA-Z0-9,-]{20,128}$/', $sid)) {
            require_once __DIR__ . '/config.php';
            $sessionsFile = (rtrim((string)SPLITDB_DATA_PATH, '/\\')) . '/meta/sessions.sqlite';
            if (is_file($sessionsFile)) {
                try {
                    $pdo = new \PDO('sqlite:' . $sessionsFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                    $stmt = $pdo->prepare('SELECT data FROM sessions WHERE id = :id AND expires > :now');
                    $stmt->execute([':id' => $sid, ':now' => time()]);
                    $sdata = $stmt->fetchColumn();
                    // PHP 序列化会话中已登录的标志是 "user_id|"（如 user_id|i:5;）
                    // 用 preg_match 键边界匹配（会话条目格式 键|类型:值），避免会话"值"内包含 user_id| 字符串时误判已登录；
                    // 边界含 }：登录时 RateLimiter 会在会话写入 _rate_limit_login_*|a:1:{...} 数组值（以 } 结尾），
                    // 紧随其后的 user_id|i:N 前面是 } 而非 ; → 旧正则漏判已登录 → 误放行游客缓存
                    $canFrontServe = ($sdata === false || !preg_match('/(?:^|[;}])user_id\|i:/', (string)$sdata));
                } catch (\Throwable $e) {
                    $canFrontServe = false; // 判定失败则退回完整管线（安全兜底）
                }
            }
        }
    }
}
if ($canFrontServe) {
    $frontCacheHit = (static function (): bool {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // 二级目录支持：前置缓存路径判断前先剥离 BASE_PATH，
        // 否则 /flinthub/ 等二级目录下永远不命中缓存 key（功能安全但前置缓存失效）
        $base = \defined('BASE_PATH') ? \BASE_PATH : '';
        if ($base !== '' && \strpos($path, $base) === 0) {
            $path = \substr($path, \strlen($base));
            if ($path === '') $path = '/';
        }
        $page = max(1, (int)($_GET['page'] ?? 1));
        $key = null;
        if ($path === '' || $path === '/' || $path === '/index.php') {
            $key = 'home_' . $page;
        } elseif ($path === '/forum') {
            $key = 'forum_0_' . $page . ((($_GET['type'] ?? 'latest') === 'highlighted') ? '_h' : '');
        } elseif (preg_match('#^/forum/category/(\d+)$#', $path, $m)) {
            $key = 'forum_' . (int)$m[1] . '_' . $page;
        }
        if ($key === null) {
            return false;
        }
        require_once __DIR__ . '/config.php';
        require_once __DIR__ . '/app/Core/Autoloader.php';
        // [维护模式] 站点关闭期间禁用前置缓存：让游客走完整管线，由 init.php 维护检查拦截；
        //   判定失败时同样不命中缓存（安全兜底，宁走完整管线）
        try {
            if (\app\Helpers\Settings::get('site_closed') === '1') {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }
        return \app\Helpers\PageCache::serve($key);
    })();
    if ($frontCacheHit) {
        exit;
    }
}

require_once __DIR__ . '/app/init.php';

use app\Core\Router;
use app\Controllers\{
    HomeController, ForumController, ThreadController, PostController,
    AuthController, ProfileController, MessageController, BlogController,
    SearchController, TagsController, ThemeController, ApiController,
    AttachmentController, NotificationController
};
use app\Controllers\Admin\{
    DashboardController,
    UserController as AdminUserController,
    ThreadController as AdminThreadController,
    CategoryController as AdminCategoryController,
    BlogController as AdminBlogController,
    TagController as AdminTagController,
    LevelController as AdminLevelController,
    SettingsController as AdminSettingsController,
    DatabaseController as AdminDatabaseController,
    VerifyController as AdminVerifyController,
    MaintenanceController as AdminMaintenanceController,
    TrashedController as AdminTrashedController,
    ThemeController as AdminThemeController,
    GroupController as AdminGroupController
};

$router = new Router();

// ========== 前台路由 ==========
$router->get('/', [HomeController::class, 'index']);
$router->get('/index.php', [HomeController::class, 'index']);

$router->get('/forum', [ForumController::class, 'index']);
$router->get('/forum/category/{id}', [ForumController::class, 'category']);

$router->get('/thread/{id}', [ThreadController::class, 'show']);
$router->post('/thread/{id}', [ThreadController::class, 'show']);
$router->post('/thread/{id}/delete', [ThreadController::class, 'delete']);
$router->get('/thread/{id}/edit', [ThreadController::class, 'edit']);
$router->post('/thread/{id}/edit', [ThreadController::class, 'edit']);

$router->get('/post/new', [PostController::class, 'create']);
$router->post('/post/new', [PostController::class, 'create']);
$router->get('/post/{id}/edit', [PostController::class, 'edit']);
$router->post('/post/{id}/edit', [PostController::class, 'edit']);

// 附件下载（权限跟随版块 can_view，预留 attachment_download_before 钩子）
$router->get('/attachment/{id}', [AttachmentController::class, 'download']);

$router->get('/login', [AuthController::class, 'login']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/register', [AuthController::class, 'register']);
$router->post('/register', [AuthController::class, 'register']);
$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/forgot-password', [AuthController::class, 'forgotPassword']);
$router->post('/forgot-password', [AuthController::class, 'forgotPassword']);
$router->get('/reset-password/{token}', [AuthController::class, 'resetPassword']);
$router->post('/reset-password/{token}', [AuthController::class, 'resetPassword']);

$router->get('/verify-email/{token}', [AuthController::class, 'verifyEmail']);

$router->get('/resend-verification', [AuthController::class, 'resendVerification']);

$router->get('/notifications', [NotificationController::class, 'index']);
$router->post('/notifications', [NotificationController::class, 'index']);
$router->get('/profile', [ProfileController::class, 'index']);
$router->post('/profile', [ProfileController::class, 'index']);

$router->get('/message', [MessageController::class, 'index']);
$router->get('/message/inbox', [MessageController::class, 'inbox']);
$router->post('/message/inbox', [MessageController::class, 'inbox']);
$router->get('/message/sent', [MessageController::class, 'sent']);
$router->post('/message/sent', [MessageController::class, 'sent']);
$router->get('/message/compose', [MessageController::class, 'compose']);
$router->post('/message/compose', [MessageController::class, 'compose']);
$router->get('/message/view/{id}', [MessageController::class, 'detail']);

$router->get('/blog', [BlogController::class, 'index']);
$router->get('/blog/category/{id}', [BlogController::class, 'index']);
$router->get('/blog/new', [BlogController::class, 'create']);
$router->post('/blog/new', [BlogController::class, 'create']);
$router->get('/blog/{id}/edit', [BlogController::class, 'edit']);
$router->post('/blog/{id}/edit', [BlogController::class, 'edit']);
$router->post('/blog/{id}/delete', [BlogController::class, 'delete']);
$router->any('/blog/{id}', [BlogController::class, 'show']);

$router->get('/search', [SearchController::class, 'index']);
$router->post('/search', [SearchController::class, 'index']);

$router->get('/tags', [TagsController::class, 'index']);
$router->get('/tag/{id}', [TagsController::class, 'show']);

$router->get('/theme-settings', [ThemeController::class, 'settings']);
$router->post('/theme-settings', [ThemeController::class, 'settings']);
// 布局即时开关（导航“布局”按钮）：GET 翻转列表摘要显示态后 PRG 回原页
$router->get('/layout-toggle', [ThemeController::class, 'toggleLayout']);

// ========== 后台路由 ==========
$router->group('/admin', function (Router $r) {
    $r->get('/', [DashboardController::class, 'index']);
    $r->get('/database', [AdminDatabaseController::class, 'index']);
    $r->post('/database', [AdminDatabaseController::class, 'index']);
    $r->get('/database/convert', [AdminDatabaseController::class, 'convert']);
    $r->post('/database/convert', [AdminDatabaseController::class, 'convert']);
    $r->get('/database/engine', [AdminDatabaseController::class, 'engine']);
    $r->post('/database/engine', [AdminDatabaseController::class, 'engine']);
    $r->get('/database/overview', [AdminDatabaseController::class, 'overview']);
    $r->get('/database/settings', [AdminDatabaseController::class, 'settings']);
    $r->post('/database/settings', [AdminDatabaseController::class, 'settings']);
    $r->get('/settings', [AdminSettingsController::class, 'basic']);
    $r->post('/settings', [AdminSettingsController::class, 'basic']);
    $r->get('/settings/basic', [AdminSettingsController::class, 'basic']);
    $r->post('/settings/basic', [AdminSettingsController::class, 'basic']);
    $r->get('/settings/system', [AdminSettingsController::class, 'system']);
    $r->post('/settings/system', [AdminSettingsController::class, 'system']);
    $r->get('/settings/mode', [AdminSettingsController::class, 'siteMode']);
    $r->post('/settings/mode', [AdminSettingsController::class, 'siteMode']);
    $r->get('/settings/display', [AdminSettingsController::class, 'display']);
    $r->post('/settings/display', [AdminSettingsController::class, 'display']);
    $r->get('/settings/mail', [AdminSettingsController::class, 'mailSettings']);
    $r->post('/settings/mail', [AdminSettingsController::class, 'mailSettings']);
    $r->get('/settings/search', [AdminSettingsController::class, 'searchSettings']);
    $r->post('/settings/search', [AdminSettingsController::class, 'searchSettings']);
    $r->get('/settings/queue', [AdminSettingsController::class, 'queueSettings']);
    $r->post('/settings/queue', [AdminSettingsController::class, 'queueSettings']);
    $r->get('/users', [AdminUserController::class, 'index']);
    $r->post('/users', [AdminUserController::class, 'index']);
    $r->get('/threads', [AdminThreadController::class, 'index']);
    $r->post('/threads', [AdminThreadController::class, 'index']);
    $r->get('/categories', [AdminCategoryController::class, 'index']);
    $r->post('/categories', [AdminCategoryController::class, 'index']);
    $r->get('/blog-categories', [AdminBlogController::class, 'categories']);
    $r->post('/blog-categories', [AdminBlogController::class, 'categories']);
    $r->get('/blog-manage', [AdminBlogController::class, 'manage']);
    $r->post('/blog-manage', [AdminBlogController::class, 'manage']);
    $r->get('/tags', [AdminTagController::class, 'index']);
    $r->post('/tags', [AdminTagController::class, 'index']);
    $r->get('/trashed', [AdminTrashedController::class, 'index']);
    $r->post('/trashed', [AdminTrashedController::class, 'index']);
    $r->get('/levels', [AdminLevelController::class, 'index']);
    $r->post('/levels', [AdminLevelController::class, 'index']);
    $r->get('/verify', [AdminVerifyController::class, 'index']);
    $r->post('/verify', [AdminVerifyController::class, 'index']);
    $r->get('/maintenance', [AdminMaintenanceController::class, 'index']);
    $r->post('/maintenance', [AdminMaintenanceController::class, 'index']);
    $r->get('/groups', [AdminGroupController::class, 'index']);
    $r->post('/groups', [AdminGroupController::class, 'index']);
    $r->get('/permissions', [AdminGroupController::class, 'permissions']);
    $r->post('/permissions', [AdminGroupController::class, 'permissions']);
    $r->get('/logs/audit', [\app\Controllers\Admin\LogController::class, 'audit']);
    $r->get('/themes', [AdminThemeController::class, 'index']);
    $r->post('/themes/default', [AdminThemeController::class, 'setDefault']);
    $r->post('/themes/css', [AdminThemeController::class, 'saveCss']);
    $r->post('/themes/options', [AdminThemeController::class, 'saveOptions']);
    $r->post('/themes/upload', [AdminThemeController::class, 'upload']);
    $r->post('/themes/delete', [AdminThemeController::class, 'delete']);
    $r->get('/plugins', [\app\Controllers\Admin\PluginController::class, 'index']);
    $r->get('/plugins/edit/{name}', [\app\Controllers\Admin\PluginController::class, 'edit']);
    $r->post('/plugins/edit/{name}', [\app\Controllers\Admin\PluginController::class, 'edit']);
    $r->post('/plugins/activate', [\app\Controllers\Admin\PluginController::class, 'activate']);
    $r->post('/plugins/deactivate', [\app\Controllers\Admin\PluginController::class, 'deactivate']);
    $r->post('/plugins/uninstall', [\app\Controllers\Admin\PluginController::class, 'uninstall']);
    $r->post('/plugins/permission-enforce', [\app\Controllers\Admin\PluginController::class, 'togglePermissionEnforce']);

    \app\Helpers\Plugin::hook('admin_route_register', ['router' => $r]);
});

// ========== API 路由 ==========
$router->group('/api', function (Router $r) {
    $r->post('/vote', [ApiController::class, 'vote']);
    $r->post('/upload', [ApiController::class, 'upload']);
    $r->get('/fetch-image', [ApiController::class, 'fetchImage']);
    $r->get('/captcha', [ApiController::class, 'captcha']);
    $r->get('/pow', [ApiController::class, 'pow']);
    // 博客封面实时预览（只生成不落盘，编辑页下拉选择时所见即所得）
    $r->get('/cover-preview', [ApiController::class, 'coverPreview']);
    // PC 用户下拉「我的收藏」收藏数（htmx 懒加载，登录校验 + Session 30s 缓存）
    $r->get('/favorites-count', [ApiController::class, 'favoritesCount']);
    // 详情页摘要展开：htmx 点击「阅读全文」拉取完整正文（HTML 片段）
    $r->get('/post/{id}/body', [ApiController::class, 'postBody']);
});

// 插件钩子
\app\Helpers\Plugin::hook('route_register', ['router' => $router]);
\app\Helpers\Plugin::hook('route_before_dispatch', ['router' => $router]);

// 注册请求关闭时的处理：批量写入待处理的计数器 + 浏览量落库
register_shutdown_function(function () {
    \app\Helpers\Settings::flushCounters();
    // 浏览量 APCu 内存计数批量落库（ViewCounter::inc 在 APCu 下仅 apcu_inc，
    // 若无此 flush，topic_index.view_count 永不更新，论坛/分类页浏览数恒为 0）
    try {
        \app\SplitDB\ViewCounter::flush();
    } catch (\Throwable $e) {
        \error_log('ViewCounter::flush error: ' . $e->getMessage());
    }
});

// 处理请求
try {
    $router->dispatch();
} catch (\Throwable $e) {
    http_response_code(500);
    // 记录详细错误到日志，生产环境只显示通用提示
    \error_log('500 Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo '<h2>500 Internal Server Error</h2>';
    echo '<p>服务器内部错误，请稍后再试。错误详情已记录到服务器日志。</p>';
}

\app\Helpers\Plugin::hook('route_after_dispatch');
