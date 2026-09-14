<?php
/**
 * FlintHub — 单页/链接管理插件 后台控制器
 * 站内页面（type=page）/ 外部链接（type=link）增删改查
 * @file plugins/single_page/AdminController.php
 * @package Plugin\SinglePage
 * @version 1.0.0
 */

namespace Plugin\SinglePage;

use app\Controllers\Admin\BaseController;
use app\Helpers\Csrf;

class AdminController extends BaseController
{
    /** [SplitDB] 插件独立库连接 */
    public static function db(): \PDO
    {
        return \app\Helpers\Plugin::db('single_page');
    }

    public function index()
    {
        $db = self::db();

        // [SplitDB] 建表幂等（独立库 + SQLite DDL）
        \app\Helpers\Plugin::ensureSchema('single_page', \Plugin\SinglePage\Plugin::ddl());

        $items = $db->query(
            "SELECT * FROM single_pages ORDER BY sort_order ASC, id ASC"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $error = $_SESSION['sp_error'] ?? '';
        $success = $_SESSION['sp_success'] ?? '';
        unset($_SESSION['sp_error'], $_SESSION['sp_success']);

        // 编辑回显：GET ?edit=ID 加载记录，刷新即清除（不落 session）
        $editing = null;
        $editId = (int)($_GET['edit'] ?? 0);
        if ($editId > 0) {
            $stmt = $db->prepare("SELECT * FROM single_pages WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $editId]);
            $editing = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        $this->view('plugins/single_page/admin', [
            'items' => $items,
            'editing' => $editing,
            'error' => $error,
            'success' => $success,
            '__nav_active' => 'single-page',
            'needsEditor' => true,
        ]);
    }

    /**
     * 校验并规范化表单输入；失败时返回错误信息（成功返回 null）
     */
    private function validate(array $input, int $excludeId = 0): ?string
    {
        $title = trim((string)($input['title'] ?? ''));
        $type = (string)($input['type'] ?? 'page');
        $I = function (string $k) { return \app\Helpers\I18n::get($k); };

        if ($title === '') {
            return $I('plugin.single_page.err_title');
        }
        if (!in_array($type, ['page', 'link'], true)) {
            return $I('plugin.single_page.err_type');
        }

        if ($type === 'link') {
            $url = trim((string)($input['url'] ?? ''));
            // 协议白名单：仅 http/https
            if ($url === '' || !\preg_match('#^https?://#i', $url)) {
                return $I('plugin.single_page.err_url');
            }
        } else {
            $slug = trim((string)($input['slug'] ?? ''));
            if ($slug === '') {
                return $I('plugin.single_page.err_slug');
            }
            // slug 白名单：小写字母/数字/连字符，长度 1-64，防路径穿越与 URL 注入
            if (!\preg_match('/^[a-z0-9-]{1,64}$/', $slug)) {
                return $I('plugin.single_page.err_slug');
            }
            if (\Plugin\SinglePage\Plugin::slugExists($slug, $excludeId)) {
                return $I('plugin.single_page.err_slug_exists');
            }
        }

        return null;
    }

    public function add()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $type = (string)($_POST['type'] ?? 'page');

        $error = $this->validate($_POST);
        if ($error !== null) {
            $_SESSION['sp_error'] = $error;
            $this->redirect('/admin/single-page');
        }

        $db = self::db();
        $db->prepare(
            "INSERT INTO single_pages (type, title, content, url, slug, sort_order, is_public, created_at, updated_at)
             VALUES (:type, :title, :content, :url, :slug, :sort, :vis, :now, :now)"
        )->execute([
            ':type' => $type,
            ':title' => trim((string)($_POST['title'] ?? '')),
            ':content' => $type === 'page' ? trim((string)($_POST['content'] ?? '')) : '',
            ':url' => $type === 'link' ? trim((string)($_POST['url'] ?? '')) : '',
            ':slug' => $type === 'page' ? trim((string)($_POST['slug'] ?? '')) : '',
            ':sort' => (int)($_POST['sort_order'] ?? 0),
            ':vis' => isset($_POST['is_public']) ? 1 : 0,
            ':now' => date('Y-m-d H:i:s'),
        ]);
        \Plugin\SinglePage\Plugin::clearCache();
        $_SESSION['sp_success'] = \app\Helpers\I18n::get('plugin.single_page.msg_added');
        $this->redirect('/admin/single-page');
    }

    public function edit()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $type = (string)($_POST['type'] ?? 'page');

        if ($id <= 0) {
            $_SESSION['sp_error'] = \app\Helpers\I18n::get('plugin.single_page.err_param');
            $this->redirect('/admin/single-page');
        }

        $error = $this->validate($_POST, $id);
        if ($error !== null) {
            // 编辑失败：跳回编辑态保留表单输入
            $_SESSION['sp_error'] = $error;
            $this->redirect('/admin/single-page?edit=' . $id);
        }

        $db = self::db();
        $db->prepare(
            "UPDATE single_pages
                SET type = :type, title = :title, content = :content, url = :url, slug = :slug,
                    sort_order = :sort, is_public = :vis, updated_at = :now
              WHERE id = :id"
        )->execute([
            ':type' => $type,
            ':title' => trim((string)($_POST['title'] ?? '')),
            ':content' => $type === 'page' ? trim((string)($_POST['content'] ?? '')) : '',
            ':url' => $type === 'link' ? trim((string)($_POST['url'] ?? '')) : '',
            ':slug' => $type === 'page' ? trim((string)($_POST['slug'] ?? '')) : '',
            ':sort' => (int)($_POST['sort_order'] ?? 0),
            ':vis' => isset($_POST['is_public']) ? 1 : 0,
            ':now' => date('Y-m-d H:i:s'),
            ':id' => $id,
        ]);
        \Plugin\SinglePage\Plugin::clearCache();
        $_SESSION['sp_success'] = \app\Helpers\I18n::get('plugin.single_page.msg_updated');
        $this->redirect('/admin/single-page');
    }

    public function delete()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            self::db()->prepare("DELETE FROM single_pages WHERE id = :id")->execute([':id' => $id]);
            \Plugin\SinglePage\Plugin::clearCache();
            $_SESSION['sp_success'] = \app\Helpers\I18n::get('plugin.single_page.msg_deleted');
        }
        $this->redirect('/admin/single-page');
    }
}
