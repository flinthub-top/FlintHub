<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 后台系统设置控制器 — 基本信息、显示设置、邮件、搜索设置
 * @file app/Controllers/Admin/SettingsController.php
 * @package app\Controllers\Admin
 */

namespace app\Controllers\Admin;

use app\Helpers\Csrf;
use app\Helpers\Settings;

class SettingsController extends BaseController
{
    // ===== 基本信息 =====
    public function basic()
    {
        $settingModel = new \app\Models\Setting();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            // 站点状态/功能开关/GZIP 已迁至 system()，此处仅保留站点基础信息
            $keys = ['site_name', 'site_description', 'site_lang'];
            foreach ($keys as $key) {
                $settingModel->setValue($key, $_POST[$key] ?? '');
            }
            // base_path 白名单校验：仅允许「空（根目录）」或「/ 开头 + 字母数字下划线连字符斜杠」，
            // 拒绝 'javascript:'、'//evil.com'、'../'、反斜杠等注入全站 URL / JS 上下文（P0）
            $basePath = (string)($_POST['base_path'] ?? '');
            if ($basePath !== '' && !\preg_match('#^/[A-Za-z0-9_\-\/]*$#', $basePath)) {
                $basePath = '';
            }
            $settingModel->setValue('base_path', $basePath);
            Settings::buildCache();
            \app\Helpers\AuditLog::log('admin_action', 'setting', 0, '保存基本信息');
            $this->redirect('/admin/settings/basic?success=1');
        }

        $msg = isset($_GET['success']) ? \app\Helpers\I18n::get('admin.settings_saved') : '';
        $allSettings = $settingModel->getAll();
        // 建议扩容提示数据（渲染于站点设置页顶部；maxBucketLoad ≥ 80% × bucket_safe_capacity 命中）
        $suggestExpand = [];
        try {
            $suggestExpand = \app\Controllers\Admin\DatabaseController::suggestExpandData();
        } catch (\Throwable $e) {
            \error_log('suggestExpandData error: ' . $e->getMessage());
        }
        $this->view('admin/settings_basic', [
            'settings' => $allSettings, 'msg' => $msg, '__nav_active' => 'settings_basic',
            'suggestExpand' => $suggestExpand,
        ]);
    }

    // ===== 系统设置（站点状态/功能开关/GZIP/验证超时） =====
    public function system()
    {
        $settingModel = new \app\Models\Setting();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');

            // ===== 常规：站点状态 / 功能开关 / 性能（checkbox 未勾选时浏览器不提交字段，显式存 '0' 保证关闭语义明确）=====
            $keys = ['site_closed', 'enable_gzip', 'allow_registration', 'allow_attachments', 'message_enabled'];
            foreach ($keys as $key) {
                $settingModel->setValue($key, $_POST[$key] ?? '');
            }
            $settingModel->setValue('email_verify_enabled', isset($_POST['email_verify_enabled']) ? '1' : '0');

            // ===== 积分设置（points_*，非负整数，0 = 关闭该奖励）=====
            $pointsDef = ['points_thread_create' => 5, 'points_post_create' => 2, 'points_vote_received' => 1];
            foreach ($pointsDef as $key => $def) {
                $val = (int)($_POST[$key] ?? $def);
                $settingModel->setValue($key, (string)max(0, min(10000, $val)));
            }

            // ===== 限流设置（rate_*：13 组 max/window，最小 1）=====
            $rateDef = [
                'reply' => [3, 30], 'create_thread' => [5, 600], 'search' => [8, 30],
                'message_send' => [10, 60], 'blog_comment' => [10, 60], 'vote' => [30, 60],
                'upload' => [5, 60], 'fetch_image' => [20, 60], 'post_body' => [30, 60],
                'login' => [20, 900], 'register' => [3, 3600], 'forgot_password' => [3, 3600],
                'resend_verify' => [3, 3600],
            ];
            foreach ($rateDef as $action => [$defMax, $defWin]) {
                $max = (int)($_POST["rate_{$action}_max"] ?? $defMax);
                $win = (int)($_POST["rate_{$action}_window"] ?? $defWin);
                $settingModel->setValue("rate_{$action}_max", (string)max(1, min(100000, $max)));
                $settingModel->setValue("rate_{$action}_window", (string)max(1, min(86400, $win)));
            }

            // ===== 附件设置（attachment_*）=====
            $maxPerPost = (int)($_POST['attachment_max_per_post'] ?? 10);
            $settingModel->setValue('attachment_max_per_post', (string)max(1, min(100, $maxPerPost)));
            $maxSize = (int)($_POST['attachment_max_size'] ?? MAX_FILE_SIZE);
            $settingModel->setValue('attachment_max_size', (string)max(1024, min(MAX_FILE_SIZE * 10, $maxSize)));
            // 扩展名白名单：逗号分隔 → 拆分/去空格/去点/小写/校验/去重，全部非法时回退常量
            $exts = [];
            foreach (\explode(',', (string)($_POST['attachment_allowed_ext'] ?? '')) as $e) {
                $e = \strtolower(\ltrim(\trim($e), '.'));
                if ($e !== '' && \preg_match('/^[a-z0-9]{1,10}$/', $e)) {
                    $exts[$e] = true;
                }
            }
            $exts = \array_keys($exts);
            $settingModel->setValue('attachment_allowed_ext', \implode(',', $exts ?: ALLOWED_EXTENSIONS));

            // ===== 常规：管理员二次验证超时（标准模式 1800s / 长任务模式 2592000s）=====
            // 白名单校验：只允许 1800 / 2592000 两个值，拒绝其它（防配置写坏导致验证失效）
            $timeout = (int)($_POST['admin_verify_timeout'] ?? 0);
            if (!in_array($timeout, [1800, 2592000], true)) {
                $timeout = 1800; // 非法值回退标准模式
            }
            $settingModel->setValue('admin_verify_timeout', (string)$timeout);
            Settings::buildCache();
            \app\Helpers\AuditLog::log('admin_action', 'setting', 0, '保存系统设置（常规/积分/限流/附件）');
            \app\Helpers\AuditLog::log('admin_action', 'setting', 0,
                $timeout === 2592000 ? '开启长任务模式（验证超时30天）' : '切换标准模式（验证超时30分钟）');
            $this->redirect('/admin/settings/system?msg=verify_mode_' . $timeout);
        }

        $msg = isset($_GET['success']) ? \app\Helpers\I18n::get('admin.settings_saved') : '';
        if (isset($_GET['msg']) && strpos($_GET['msg'], 'verify_mode_') === 0) {
            // 验证模式切换提示
            $modeVal = (int)substr($_GET['msg'], 12);
            $msg = \app\Helpers\I18n::get($modeVal === 2592000 ? 'admin.verify_mode_long' : 'admin.verify_mode_standard');
        }
        $allSettings = $settingModel->getAll();
        // 新配置组默认值（与核心硬编码值一致；供视图回显与「恢复默认值」按钮使用）
        $defaults = [
            // 积分
            'points_thread_create' => 5, 'points_post_create' => 2, 'points_vote_received' => 1,
            // 限流（13 组 max/window）
            'rate_reply_max' => 3, 'rate_reply_window' => 30,
            'rate_create_thread_max' => 5, 'rate_create_thread_window' => 600,
            'rate_search_max' => 8, 'rate_search_window' => 30,
            'rate_message_send_max' => 10, 'rate_message_send_window' => 60,
            'rate_blog_comment_max' => 10, 'rate_blog_comment_window' => 60,
            'rate_vote_max' => 30, 'rate_vote_window' => 60,
            'rate_upload_max' => 5, 'rate_upload_window' => 60,
            'rate_fetch_image_max' => 20, 'rate_fetch_image_window' => 60,
            'rate_post_body_max' => 30, 'rate_post_body_window' => 60,
            'rate_login_max' => 20, 'rate_login_window' => 900,
            'rate_register_max' => 3, 'rate_register_window' => 3600,
            'rate_forgot_password_max' => 3, 'rate_forgot_password_window' => 3600,
            'rate_resend_verify_max' => 3, 'rate_resend_verify_window' => 3600,
            // 附件
            'attachment_max_per_post' => 10,
            'attachment_max_size' => (string)MAX_FILE_SIZE,
            'attachment_allowed_ext' => \implode(',', ALLOWED_EXTENSIONS),
        ];
        $this->view('admin/settings_system', [
            'settings' => $allSettings, 'defaults' => $defaults, 'msg' => $msg, '__nav_active' => 'settings_system',
        ]);
    }

    // ===== 站点模式(portal 门户 / forum 论坛 / blog 博客) =====
    public function siteMode()
    {
        $settingModel = new \app\Models\Setting();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            // 白名单校验:只允许 portal/forum/blog,拒绝其它值
            $mode = (string)($_POST['site_mode'] ?? '');
            if (!in_array($mode, ['portal', 'forum', 'blog'], true)) {
                $mode = 'portal';
            }
            $settingModel->setValue('site_mode', $mode);
            Settings::buildCache();
            // 模式切换会改变首页/论坛渲染内容，清空全部游客静态缓存防旧版面
            \app\Helpers\PageCache::invalidate();
            $modeName = ['portal' => '门户模式(Portal)', 'forum' => '论坛模式', 'blog' => '博客模式'][$mode] ?? $mode;
            \app\Helpers\AuditLog::log('admin_action', 'setting', 0, "切换站点模式: {$modeName}");
            $this->redirect('/admin/settings/mode?success=1');
        }

        $msg = isset($_GET['success']) ? \app\Helpers\I18n::get('admin.settings_saved') : '';
        $allSettings = $settingModel->getAll();
        $this->view('admin/settings_mode', ['settings' => $allSettings, 'msg' => $msg, '__nav_active' => 'settings_mode']);
    }

    // ===== 显示设置 =====
    public function display()
    {
        $settingModel = new \app\Models\Setting();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');

            // ===== 分页设置（原有）=====
            $keys = ['threads_per_page', 'posts_per_page', 'blogs_per_page', 'blog_comments_per_page',
                     'home_threads_count', 'home_blogs_count'];
            foreach ($keys as $key) {
                $val = (int)($_POST[$key] ?? 0);
                $settingModel->setValue($key, (string)max(1, min(100, $val)));
            }

            // ===== 长度限制（limit_*，1~10000 钳制）=====
            $limitDef = ['limit_thread_title' => 200, 'limit_user_signature' => 500,
                         'limit_search_query' => 100, 'limit_tag_name' => 30];
            foreach ($limitDef as $key => $def) {
                $val = (int)($_POST[$key] ?? $def);
                $settingModel->setValue($key, (string)max(1, min(10000, $val)));
            }

            Settings::buildCache();
            \app\Helpers\AuditLog::log('admin_action', 'setting', 0, '保存显示设置（分页/长度限制）');
            $this->redirect('/admin/settings/display?success=1');
        }

        $msg = isset($_GET['success']) ? \app\Helpers\I18n::get('admin.settings_saved') : '';
        $allSettings = $settingModel->getAll();
        // 长度限制默认值（与核心硬编码值一致；供视图回显与「恢复默认值」按钮使用）
        $defaults = [
            'limit_thread_title' => 200, 'limit_user_signature' => 500,
            'limit_search_query' => 100, 'limit_tag_name' => 30,
        ];
        $this->view('admin/settings_display', [
            'settings' => $allSettings, 'defaults' => $defaults, 'msg' => $msg, '__nav_active' => 'settings_display',
        ]);
    }

    // ===== 邮件设置 =====
    public function mailSettings()
    {
        $settingModel = new \app\Models\Setting();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $keys = ['mail_driver', 'mail_host', 'mail_port', 'mail_user', 'mail_encryption',
                     'mail_from_addr', 'mail_from_name'];
            foreach ($keys as $key) {
                $settingModel->setValue($key, $_POST[$key] ?? '');
            }
            $mailPass = $_POST['mail_pass'] ?? '';
            if ($mailPass !== '') {
                $settingModel->setValue('mail_pass', \app\Helpers\Mailer::encryptSmtpPass($mailPass));
            }
            Settings::buildCache();
            \app\Helpers\AuditLog::log('admin_action', 'setting', 0, '保存邮件设置');
            $this->redirect('/admin/settings/mail?success=1');
        }

        $msg = isset($_GET['success']) ? \app\Helpers\I18n::get('admin.mail_settings_saved') : '';
        $allSettings = $settingModel->getAll();
        $this->view('admin/mail_settings', ['settings' => $allSettings, 'msg' => $msg, '__nav_active' => 'mail_settings']);
    }

    // ===== 搜索设置 =====
    public function searchSettings()
    {
        $settingModel = new \app\Models\Setting();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');

            $action = $_POST['action'] ?? '';

            if ($action === 'save_switch') {
                $settingModel->setValue('search_index_content', $_POST['search_index_content'] ?? '0');
                Settings::buildCache();
                \app\Helpers\AuditLog::log('admin_action', 'setting', 0,
                    ($_POST['search_index_content'] ?? '0') === '1' ? '开启全文索引' : '关闭全文索引');
                $this->redirect('/admin/settings/search?success=1');
            }

            // 安全验证模式已迁至 system()，此处不再处理 save_verify_mode

            // 清理正文索引（weight=1）
            if ($action === 'clean_content') {
                try {
                    // search_index 已剥离 business → 季度分文件（weight=1 为正文词条）
                    $count = \app\SplitDB\SearchIndexStore::cleanWeightOne();
                    \app\Helpers\AuditLog::log('admin_action', 'search_index', 0, "清理正文索引 {$count} 条");
                    $this->redirect('/admin/settings/search?msg=cleaned_' . $count);
                } catch (\Exception $e) {
                    $this->redirect('/admin/settings/search?error=' . urlencode($e->getMessage()));
                }
                return;
            }

            // ===== 搜索索引重建：3 个动作（重建 / 续建 / 停止） =====

            // ① 重建全部索引（rebuild）= 强制从头：清断点 → clearAll → 第一批
            if ($action === 'rebuild') {
                set_time_limit(120);  // 每批最多 2 分钟，防长时间占用 worker
                $perPage = max(1, min(100, (int)($_POST['per_page'] ?? 50))); // 默认 50（keyset 游标下提高单批吞吐）
                // 生成重建令牌，防 GET CSRF 伪造重建请求
                $_SESSION['_rebuild_token'] = \bin2hex(\random_bytes(16));
                // 强制从头：清除断点文件，processRebuild 内部将走 clearAll + 从头
                \app\Helpers\SearchRebuild::clear();
                $this->processRebuild(1, $perPage);
                return;
            }

            // ② 续建全部索引（resume）= 从断点继续：保留断点，processRebuild 依据断点决定
            if ($action === 'resume') {
                set_time_limit(120);
                $perPage = max(1, min(100, (int)($_POST['per_page'] ?? 50)));
                $_SESSION['_rebuild_token'] = \bin2hex(\random_bytes(16));
                // 不调用 SearchRebuild::clear()：有有效断点 → 续建；无断点/损坏/开关变更 → 才从头
                $this->processRebuild(1, $perPage);
                return;
            }

            // ③ 停止（stop）= 中断流程：清除重建令牌（meta refresh 链断裂即停），保留断点文件供续建
            if ($action === 'stop') {
                unset($_SESSION['_rebuild_token']);
                $this->redirect('/admin/settings/search?msg=rebuild_stopped');
                return;
            }
        }

        // GET 方式继续重建进度（meta refresh 不能 POST），需验证重建令牌
        $rebuildPage = (int)($_GET['rebuild_page'] ?? 0);
        $rebuildPerPage = (int)($_GET['rebuild_per_page'] ?? 50); // 与 POST 默认一致（50）
        $rebuildToken = $_GET['_rt'] ?? '';
        if ($rebuildPage > 0) {
            if ($rebuildToken === '' || !isset($_SESSION['_rebuild_token']) || !\hash_equals($_SESSION['_rebuild_token'], $rebuildToken)) {
                $this->redirect('/admin/settings/search?error=' . \urlencode(\app\Helpers\I18n::get('admin.invalid_rebuild_request')));
            }
            set_time_limit(120);  // 每批最多 2 分钟
            $this->processRebuild($rebuildPage, $rebuildPerPage);
            return;
        }

        $msg = isset($_GET['success']) ? \app\Helpers\I18n::get('admin.settings_saved') : '';
        if (isset($_GET['msg'])) {
            if ($_GET['msg'] === 'rebuild_done') $msg = \app\Helpers\I18n::get('admin.index_rebuild_done');
            elseif ($_GET['msg'] === 'rebuild_stopped') $msg = \app\Helpers\I18n::get('admin.rebuild_stopped');
            elseif (strpos($_GET['msg'], 'cleaned_') === 0) $msg = \app\Helpers\I18n::get('admin.index_cleaned', ['count' => (int)substr($_GET['msg'], 8)]);
        }
        $error = $_GET['error'] ?? '';
        $allSettings = $settingModel->getAll();

        // 重建进度信息
        $rebuildProgress = '';
        $rebuildNext = '';
        $perPage = 10;
        $totalPages = 1;

        $this->view('admin/search_settings', [
            'settings' => $allSettings,
            'msg' => $msg,
            'error' => $error,
            'rebuild_progress' => $rebuildProgress,
            'rebuild_next' => $rebuildNext,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            '__nav_active' => 'settings_search',
        ]);
    }

    /**
     * 重建索引处理（支持分页续跑）
     * @param int $page    当前页码（从1开始）
     * @param int $perPage 每批条数
     */
    private function processRebuild(int $page, int $perPage): void
    {
        $db = \app\Core\Database::getInstance();
        $settingModel = new \app\Models\Setting();
        $includeContent = Settings::get('search_index_content', '0') === '1' ? '1' : '0';

        // 读取断点（含指纹校验：全文索引开关变更 → 断点失效、强制从头）
        // 断点文件：data/runtime/search_rebuild_checkpoint.json
        // {"phase":"threads","threads_last":N,"blogs_last":N,"include_content":"0/1",
        //  "processed_threads":N,"processed_blogs":N,"ts":...}
        // 双阶段独立 id 空间：帖子 topic_index.id 与 博客 blogs.id 互不相干，各维护独立游标。
        $cp = \app\Helpers\SearchRebuild::validated($includeContent);
        if ($cp === null) {
            // 无断点 / 断点损坏 / 开关变更 → 清空全部季度分文件，从头开始
            try {
                \app\SplitDB\SearchIndexStore::clearAll();
            } catch (\Exception $e) {
                $this->redirect('/admin/settings/search?error=' . urlencode($e->getMessage()));
                return;
            }
            $cp = \app\Helpers\SearchRebuild::initial($includeContent);
        }

        // 帖子计数接 main_index（正文经 Thread 模型，含 extern）
        $mi = \app\SplitDB\Schema::mainIndexDb();
        $totalThreads = (int)$mi->query('SELECT COUNT(*) FROM topic_index WHERE status = 0 AND deleted_at IS NULL')->fetchColumn();
        $totalBlogs = (int)$db->fetchOne("SELECT COUNT(*) as cnt FROM blogs")['cnt'];

        // ==================== 阶段一：帖子（topic_index，独立 id 空间） ====================
        if ($cp['phase'] === 'threads') {
            // keyset 游标：WHERE id > threads_last ORDER BY id LIMIT perPage（替代 OFFSET 深翻页）
            $ids = $mi->query(
                'SELECT id FROM topic_index WHERE status = 0 AND deleted_at IS NULL AND id > ' . (int)$cp['threads_last'] . ' ORDER BY id ASC LIMIT ' . (int)$perPage
            )->fetchAll(\PDO::FETCH_COLUMN);

            if (count($ids) > 0) {
                $threadModel = new \app\Models\Thread();
                foreach ($ids as $tid) {
                    $t = $threadModel->find((int)$tid);
                    if (!$t) continue;
                    $tokens = $includeContent === '1'
                        ? \app\Helpers\Segmenter::tokenize($t['title'], $t['content'] ?? '')
                        : \app\Helpers\Segmenter::tokenize($t['title']);
                    // 写入季度分文件（按 created_at 路由，删旧 + 插入单事务）
                    \app\SplitDB\SearchIndexStore::writeIndex(1, (int)$t['id'], (string)($t['created_at'] ?? ''), $tokens);
                }
                // 本批完成 → 更新断点（threads_last = 本批最大 id）
                $cp['threads_last'] = (int)end($ids);
                $cp['processed_threads'] += count($ids);
                \app\Helpers\SearchRebuild::write($cp);
                $this->renderRebuildProgress($settingModel, $perPage, $cp, $totalThreads, $totalBlogs);
                return;
            }

            // 帖子批取空（含"最后一批恰好 =LIMIT 后多取一次为空"）→ 切换到博客阶段
            // 双阶段独立 id 空间：切阶段时 blogs_last 重置 0
            $cp['phase'] = 'blogs';
            $cp['blogs_last'] = 0;
            \app\Helpers\SearchRebuild::write($cp);
        }

        // ==================== 阶段二：博客（blogs，独立 id 空间） ====================
        $blogs = $db->fetchAll(
            "SELECT id, title, content, created_at FROM blogs WHERE id > :last ORDER BY id ASC LIMIT :limit",
            [':last' => (int)$cp['blogs_last'], ':limit' => $perPage]
        );

        if (count($blogs) > 0) {
            foreach ($blogs as $b) {
                $tokens = $includeContent === '1'
                    ? \app\Helpers\Segmenter::tokenize($b['title'], $b['content'] ?? '')
                    : \app\Helpers\Segmenter::tokenize($b['title']);
                // 写入季度分文件（按 created_at 路由，删旧 + 插入单事务）
                \app\SplitDB\SearchIndexStore::writeIndex(2, (int)$b['id'], (string)($b['created_at'] ?? ''), $tokens);
            }
            $cp['blogs_last'] = (int)$blogs[count($blogs) - 1]['id'];
            $cp['processed_blogs'] += count($blogs);
            \app\Helpers\SearchRebuild::write($cp);
            $this->renderRebuildProgress($settingModel, $perPage, $cp, $totalThreads, $totalBlogs);
            return;
        }

        // ==================== 整体结束（帖子空批 + 博客空批 = 连续两次取空） ====================
        \app\Helpers\SearchRebuild::clear(); // 完成 → 删除断点文件
        \app\Helpers\AuditLog::log('admin_action', 'search_index', 0, '重建全部索引完成');
        $this->redirect('/admin/settings/search?msg=rebuild_done');
    }

    /**
     * 渲染重建进度页（keyset 断点模式下批次推进，进度 = 已处理总数 / 总目标数）
     * 视图字段兼容原实现（current_page/total_pages 仅用于百分比展示）；
     * rebuild_page 参数仅作为 GET 续跑的"继续"信号，真实游标由断点文件驱动。
     */
    private function renderRebuildProgress($settingModel, int $perPage, array $cp, int $totalThreads, int $totalBlogs): void
    {
        // ① 分母按阶段取（threads 阶段显示帖子进度，blogs 阶段显示博客进度），不混入另一阶段总数
        // ② current_page 传「批次号」而非「已处理条数」——视图用 current_page/total_pages 算百分比，
        //    条数÷批次数会量纲错位导致 >100%
        $stage = ($cp['phase'] === 'threads') ? 'threads' : 'blogs';
        if ($stage === 'threads') {
            $stageDone   = $cp['processed_threads'];
            $stageTotal  = $totalThreads;
            $progressKey = 'admin.rebuild_progress_threads';
        } else {
            $stageDone   = $cp['processed_blogs'];
            $stageTotal  = $totalBlogs;
            $progressKey = 'admin.rebuild_progress_blogs';
        }
        $currentBatch = max(1, (int)ceil($stageDone / max(1, $perPage)));
        $totalBatches = max(1, (int)ceil($stageTotal / max(1, $perPage)));
        $nextBatch = $currentBatch + 1;
        $this->view('admin/search_settings', [
            'settings' => $settingModel->getAll(),
            'rebuild_progress' => \app\Helpers\I18n::get($progressKey, ['done' => $stageDone, 'total' => $stageTotal]),
            'rebuild_next' => "/admin/settings/search?rebuild_page={$nextBatch}&rebuild_per_page={$perPage}&_rt=" . urlencode($_SESSION['_rebuild_token'] ?? ''),
            'per_page' => $perPage,
            'current_page' => $currentBatch,
            'total_pages' => $totalBatches,
            '__nav_active' => 'settings_search',
        ]);
    }

    // ===== 任务队列（独立模块） =====

    /**
     * 任务队列设置页：三种调度模式选择 + 队列状态面板 + 手动立即处理
     */
    public function queueSettings()
    {
        $settingModel = new \app\Models\Setting();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $action = $_POST['action'] ?? '';

            if ($action === 'save_mode') {
                $mode = $_POST['queue_mode'] ?? 'sync';
                if (!in_array($mode, ['sync', 'cron', 'cli'], true)) $mode = 'sync';
                $settingModel->setValue('queue_mode', $mode);
                Settings::buildCache();
                \app\Helpers\AuditLog::log('admin_action', 'setting', 0, "设置队列调度模式: {$mode}");
                $this->redirect('/admin/settings/queue?success=1');
            }

            // 立即处理（手动触发，复用 worker 逻辑，带文件锁防并发）
            if ($action === 'flush') {
                $processed = $this->flushQueueNow();
                $this->redirect('/admin/settings/queue?msg=flushed_' . $processed);
            }
        }

        $msg = '';
        if (isset($_GET['success'])) $msg = \app\Helpers\I18n::get('admin.settings_saved');
        if (strpos($_GET['msg'] ?? '', 'flushed_') === 0) {
            $msg = \app\Helpers\I18n::get('admin.queue_flushed', ['count' => (int)substr($_GET['msg'], 8)]);
        }
        $error = $_GET['error'] ?? '';
        $allSettings = $settingModel->getAll();

        $this->view('admin/queue_settings', [
            'settings' => $allSettings,
            'queue_stats' => $this->queueStatsAggregate(),
            'msg' => $msg,
            'error' => $error,
            '__nav_active' => 'settings_queue',
        ]);
    }

    /**
     * 聚合 3 个队列文件的任务统计（pending+processing / done / failed）
     */
    private function queueStatsAggregate(): array
    {
        $pending = 0;
        $done = 0;
        $failed = 0;
        for ($i = 0; $i < \app\SplitDB\Queue::QUEUE_COUNT; $i++) {
            $map = \app\SplitDB\Queue::countByStatus($i);
            $pending += (int)($map['pending'] ?? 0) + (int)($map['processing'] ?? 0);
            $done   += (int)($map['done'] ?? 0);
            $failed += (int)($map['failed'] ?? 0);
        }
        return ['pending' => $pending, 'done' => $done, 'failed' => $failed];
    }

    /**
     * 手动立即处理：复用 worker 同构 handler，对 3 个队列各 drain(0) 一次（有界）
     * 文件锁 lock/manual_flush.lock 防并发重入（与 cron_trigger.php 同一策略）
     * @return int 处理的任务数
     */
    private function flushQueueNow(): int
    {
        $lockFile = \app\SplitDB\ShardRouter::dataPath() . '/lock/manual_flush.lock';
        $lockDir = dirname($lockFile);
        if (!is_dir($lockDir)) @mkdir($lockDir, 0755, true);
        $lock = @fopen($lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) @fclose($lock);
            return 0; // 已有任务在处理，本次跳过
        }

        $handler = function (string $type, $data, int $taskId): void {
            $payload = is_array($data) ? $data : [];
            switch ($type) {
                case 'rebuild_search':
                    if (!empty($payload['topic_id'])) {
                        \app\Helpers\Search::indexThread((int)$payload['topic_id']);
                    }
                    break;
                case 'stats':
                    \app\Helpers\Settings::runtimeBuild();
                    break;
                default:
                    \error_log("SplitDB manual_flush: 未知任务类型 {$type} (task #{$taskId})");
            }
        };

        $total = 0;
        try {
            for ($i = 0; $i < \app\SplitDB\Queue::QUEUE_COUNT; $i++) {
                $db = \app\SplitDB\Queue::getQueueDb($i);
                $total += (new \app\SplitDB\QueueConsumer($db, $handler, 300, 3))->drain(0);
            }
        } finally {
            flock($lock, LOCK_UN);
            @fclose($lock);
        }

        \app\Helpers\AuditLog::log('admin_action', 'queue', 0, "手动处理队列任务 {$total} 个");
        return $total;
    }
}
