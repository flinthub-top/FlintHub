<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 博客前台控制器 — 博客列表展示、文章详情、评论处理
 * @file app/Controllers/BlogController.php
 * @package app\Controllers
 */

namespace app\Controllers;
use app\Core\Controller;
use app\Models\{Blog, BlogCategory, BlogComment};
use app\Helpers\Settings;
use app\Helpers\Csrf;
use app\Helpers\Upload;
use app\Helpers\Search;
use app\Helpers\Content;
use app\Helpers\RateLimiter;
use app\Helpers\CoverTemplate;

class BlogController extends Controller
{
    /**
     * 论坛模式(forum)下博客被关闭：直连 /blog* 重定向首页
     */
    public function __construct()
    {
        if (\app\Helpers\Settings::get('site_mode', 'portal') === 'forum') {
            $this->redirect('/');
        }
    }

    public function index(int $id = 0, bool $isHomeDelegate = false)
    {
        $blogModel = new Blog();
        $catModel = new BlogCategory();

        $page = max(1, (int)($_GET['page'] ?? 1)); // 页码在统计 totalPages 后钳制
        $perPage = max(5, (int)Settings::get('blogs_per_page', 10));
        // 分类 ID 仅从路由参数取，不兼容旧 ?category_id= 查询参数
        $categoryId = $id;
        // 旧链接 ?category_id=X 自动 301 跳转到 /blog/category/X
        if ($categoryId === 0 && !empty($_GET['category_id'])) {
            $oldId = (int)$_GET['category_id'];
            $query = $_GET;
            unset($query['category_id']);
            $qs = $query ? '?' . http_build_query($query) : '';
            \header('Location: ' . (\defined('BASE_PATH') ? \BASE_PATH : '') . '/blog/category/' . $oldId . $qs, true, 301);
            exit;
        }
        $archive = $_GET['archive'] ?? '';

        $where = [];
        $params = [];
        $db = \app\Core\Database::getInstance();
        if ($categoryId > 0) {
            $where[] = 'b.category_id = :cid';
            $params[':cid'] = $categoryId;
        }
        if ($archive !== '') {
            $where[] = "strftime('%Y-%m', b.created_at) = :archive";
            $params[':archive'] = $archive;
        }
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countRow = $blogModel->queryOne(
            "SELECT COUNT(*) as cnt FROM blogs b {$whereClause}",
            $params
        );
        $total = (int)($countRow['cnt'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));

        // 页码钳制到真实 totalPages（与前台一致，解除 1000 页上限）；非法页码回落到最后一页
        $page = max(1, min($totalPages, $page));
        $offset = ($page - 1) * $perPage;

        $blogs = $blogModel->query(
            "SELECT b.*, u.username, u.avatar, c.name as category_name
             FROM blogs b
             JOIN users u ON b.user_id = u.id
             LEFT JOIN blog_categories c ON b.category_id = c.id
             {$whereClause}
             ORDER BY b.created_at DESC
             LIMIT " . (int)$perPage . " OFFSET " . (int)$offset,
            $params
        );
        $blogs = $blogModel->decodeRowsPublic($blogs);

        $categories = $catModel->allOrdered();
        $totalBlogsCount = Settings::getTotalBlogs();
        $totalCategories = count($categories);
        // 右侧栏统计（博客页显示：用户/博客/在线，论坛相关由模板按页面类型隐藏）
        $totalUsers = Settings::getTotalUsers();
        $totalBlogs = Settings::getTotalBlogs();
        $blogComments = Settings::getBlogCommentsCount();
        $onlineCount = Settings::getOnlineCount();

        // 归档：按年月分组统计（30s 短 TTL 缓存，列表页/详情页共用）
        $archives = $blogModel->getArchivesCached();

        $this->view('blog/index', [
            'blogs' => $blogs, 'blogCategories' => $categories, 'page' => $page,
            'totalPages' => $totalPages, 'categoryId' => $categoryId,
            'totalBlogsCount' => $totalBlogsCount, 'totalCategories' => $totalCategories,
            // 右侧栏统计
            'totalUsers' => $totalUsers, 'totalBlogs' => $totalBlogs,
            'blogComments' => $blogComments, 'onlineCount' => $onlineCount,
            'archives' => $archives, 'archive' => $archive,
            // 首页委托渲染标志：单模式下首页标题输出纯净站点名
            'is_home_delegate' => $isHomeDelegate,
            '__nav_active' => 'blog',
        ]);
    }

    public function show($id)
    {
        $blogModel = new Blog();
        $blog = $blogModel->getWithUser((int)$id);
        if (!$blog) { $this->redirect('/blog'); }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requireLogin();
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            RateLimiter::hitConfig('blog_comment', 10, 60); // 每分钟最多 10 条评论（阈值后台可配）
            $content = trim($_POST['content'] ?? '');
            // 博客评论也需要 Content::decode 解码 base64 编码内容
            $content = \app\Helpers\Content::decode($content);
            if ($content !== '') {
                $blogModel->execute(
                    'INSERT INTO blog_comments (blog_id, user_id, content, created_at) VALUES (:bid, :uid, :content, :now)',
                    [':bid' => (int)$id, ':uid' => (int)$this->currentUser()['id'], ':content' => $content, ':now' => date('Y-m-d H:i:s')]
                );
                $blogModel->execute('UPDATE blogs SET comment_count = comment_count + 1 WHERE id = :id', [':id' => (int)$id]);

                $commentModel = new BlogComment();
                $totalC = $commentModel->countByBlog((int)$id);
                $perPageC = max(5, (int)Settings::get('blog_comments_per_page', 20));
                $lastPage = (int)ceil(max(1, $totalC) / $perPageC);
                $this->redirect('/blog/' . (int)$id . '?comment_page=' . $lastPage . '#comments');
            }
            $this->redirect('/blog/' . (int)$id . '#comments');
        }

        // 浏览量仅在 GET 请求时递增，防评论提交刷浏览量
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $blogModel->execute("UPDATE blogs SET view_count = view_count + 1 WHERE id = :id", [':id' => (int)$id]);
        }

        $commentModel = new BlogComment();
        $commentsPerPage = max(5, (int)Settings::get('blog_comments_per_page', 20));
        $totalComments = $commentModel->countByBlog((int)$id);
        $totalCommentPages = max(1, (int)ceil($totalComments / $commentsPerPage));

        // 评论页码钳制到真实 totalCommentPages（与前台一致，解除 1000 页上限）
        $commentPage = max(1, min($totalCommentPages, (int)($_GET['comment_page'] ?? 1)));
        $commentOffset = ($commentPage - 1) * $commentsPerPage;
        $comments = $commentModel->getByBlogPaginated((int)$id, $commentsPerPage, $commentOffset);

        $catModel = new BlogCategory();
        $categories = $catModel->allOrdered();
        $totalBlogsCount = Settings::getTotalBlogs();
        $totalCategories = count($categories);

        // 归档：按年月分组统计（30s 短 TTL 缓存，列表页/详情页共用）
        $archives = $blogModel->getArchivesCached();

        $this->view('blog/detail', [
            'blog' => $blog,
            'comments' => $comments,
            'blogCategories' => $categories,
            'totalBlogsCount' => $totalBlogsCount,
            'totalCategories' => $totalCategories,
            'totalComments' => $totalComments,
            'commentPage' => $commentPage,
            'totalCommentPages' => $totalCommentPages,
            'commentPerPage' => $commentsPerPage,
            'archives' => $archives,
        ]);
    }

    public function create()
    {
        $this->requireLogin();
        if (!$this->isAdmin()) { $this->redirect('/blog'); }
        $catModel = new BlogCategory();
        $categories = $catModel->allOrdered();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            // 前台 admin 操作：创建博客为 admin-only，需 30 分钟二次验证
            $this->requireAdminVerified();
            $currentUser = $this->currentUser();
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $content = Content::decode($content);
            $categoryId = (int)($_POST['category_id'] ?? 0);

            if ($title && $content) {
                $coverImage = '';
                $coverTemplate = \trim((string)($_POST['cover_template'] ?? ''));
                // 模板封面优先（与文件上传互斥）：选中模板则生成 SVG 封面；
                // 生成失败（落盘异常 saveCover 返回 ''）才回退文件上传
                if ($coverTemplate !== '') {
                    $svg = CoverTemplate::generateCover($title, (string)$currentUser['username'], \date('Y-m-d'), $coverTemplate);
                    $coverImage = CoverTemplate::saveCover($svg);
                }
                if ($coverImage === '' && !empty($_FILES['cover_image']['name'])) {
                    $result = Upload::file($_FILES['cover_image']);
                    if ($result['success']) { $coverImage = $result['filename']; }
                }

                $blogModel = new Blog();
                $newId = $blogModel->insert([
                    'category_id' => $categoryId ?: null, 'user_id' => (int)$currentUser['id'],
                    'title' => $title, 'content' => $content, 'cover_image' => $coverImage,
                    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
                Settings::runtimeIncr('total_blogs');
                Search::indexBlog($newId);
                $this->redirect('/blog/' . $newId);
            }

            $this->view('blog/edit', ['blogCategories' => $categories, 'blog' => null, 'error' => \app\Helpers\I18n::get('post.title_content_required'), 'isNew' => true]);
            return;
        }

        $this->view('blog/edit', ['blogCategories' => $categories, 'blog' => null, 'error' => null, 'isNew' => true, 'needsEditor' => true]);
}

public function edit($id)
    {
        $this->requireLogin();
        $blogModel = new Blog();
        $blog = $blogModel->getWithUser((int)$id);
        if (!$blog) { $this->redirect('/blog'); }

        $currentUser = $this->currentUser();
        if (!$this->isAdmin() && (int)$blog['user_id'] !== (int)$currentUser['id']) { $this->redirect('/blog'); }

        $catModel = new BlogCategory();
        $categories = $catModel->allOrdered();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            // 前台 admin 操作：管理员编辑他人博客需 30 分钟二次验证（本人编辑不受限）
            if ($this->isAdmin() && (int)$blog['user_id'] !== (int)$currentUser['id']) {
                $this->requireAdminVerified();
            }
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $content = Content::decode($content);
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $coverImage = (string)($blog['cover_image'] ?? '');

            if ($title && $content) {
                // 模板封面优先（与文件上传互斥）：选中模板则生成 SVG 封面；
                // 生成失败（落盘异常 saveCover 返回 ''）才回退文件上传
                $coverTemplate = \trim((string)($_POST['cover_template'] ?? ''));
                $templateCover = '';
                if ($coverTemplate !== '') {
                    $svg = CoverTemplate::generateCover($title, (string)$currentUser['username'], \date('Y-m-d'), $coverTemplate);
                    $templateCover = CoverTemplate::saveCover($svg);
                }
                if ($templateCover !== '') {
                    // 模板封面落盘成功后才清理旧封面（替换语义，避免新文件丢失）
                    if ($coverImage && is_file(\UPLOAD_PATH . $coverImage)) { @unlink(\UPLOAD_PATH . $coverImage); }
                    $coverImage = $templateCover;
                } elseif (!empty($_FILES['cover_image']['name'])) {
                    $result = Upload::file($_FILES['cover_image']);
                    if ($result['success']) {
                        if ($coverImage && is_file(\UPLOAD_PATH . $coverImage)) { @unlink(\UPLOAD_PATH . $coverImage); }
                        $coverImage = $result['filename'];
                    }
                }
                if (isset($_POST['remove_cover']) && $_POST['remove_cover'] == '1') {
                    if ($coverImage && is_file(\UPLOAD_PATH . $coverImage)) { @unlink(\UPLOAD_PATH . $coverImage); }
                    $coverImage = '';
                }

                $blogModel->update((int)$id, [
                    'category_id' => $categoryId ?: null, 'title' => $title, 'content' => $content,
                    'cover_image' => $coverImage, 'updated_at' => date('Y-m-d H:i:s'),
                ]);
                \app\Helpers\Search::indexBlog((int)$id);
                $this->redirect('/blog/' . $id . '?msg=blog_updated');
            }

            $this->view('blog/edit', ['blogCategories' => $categories, 'blog' => $blog, 'error' => \app\Helpers\I18n::get('post.title_content_required'), 'isNew' => false]);
            return;
        }

        $this->view('blog/edit', ['blogCategories' => $categories, 'blog' => $blog, 'error' => null, 'isNew' => false, 'needsEditor' => true]);
    }

    public function delete($id)
    {
        $this->requireLogin();
        $blogModel = new Blog();
        $blog = $blogModel->find((int)$id);
        if (!$blog) { $this->redirect('/blog'); }

        $currentUser = $this->currentUser();
        if (!$this->isAdmin() && (int)$blog['user_id'] !== (int)$currentUser['id']) { $this->redirect('/blog'); }

        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        // 前台 admin 操作：管理员删除他人博客需 30 分钟二次验证（本人删除不受限）
        if ($this->isAdmin() && (int)$blog['user_id'] !== (int)$currentUser['id']) {
            $this->requireAdminVerified();
        }

        if ($blog['cover_image'] && file_exists(UPLOAD_PATH . basename($blog['cover_image']))) { @unlink(UPLOAD_PATH . basename($blog['cover_image'])); }
        $blogModel->execute('DELETE FROM blog_comments WHERE blog_id = :bid', [':bid' => (int)$id]);
        $blogModel->delete((int)$id);
        \app\Helpers\Search::removeFromIndex('blog', (int)$id);
        Settings::runtimeDecr('total_blogs');

        $this->redirect($this->isAdmin() ? '/admin/blog-manage' : '/blog');
    }
}
