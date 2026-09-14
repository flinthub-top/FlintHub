<?php
/**
 * FlintHub — 版块强制 Tag 插件 后台控制器
 * Tag / 前缀管理（增删 + 启用开关 + 一键复制）+ 使用记录（筛选/删除）
 * @file plugins/forum_required_tag/AdminController.php
 * @package Plugin\ForumRequiredTag
 * @version 1.0.0
 */

namespace Plugin\ForumRequiredTag;

use app\Controllers\Admin\BaseController;
use app\Helpers\Csrf;
use app\Helpers\I18n;

class AdminController extends BaseController
{
    /** 后台表单写操作白名单（表名） */
    private const TABLES = ['frt_forum_tags', 'frt_forum_prefixes'];

    public function index()
    {
        // 建表幂等（后台入口兜底，与 activate/init_after 一致）
        \Plugin\ForumRequiredTag\Plugin::ensureSchema();

        $allCategories = $this->allCategories();

        $forumId = (int)($_GET['forum_id'] ?? 0);
        if ($forumId <= 0 && !empty($allCategories)) {
            $forumId = (int)$allCategories[0]['id']; // 默认选中第一个版块
        }

        $tags = $forumId > 0 ? \Plugin\ForumRequiredTag\Plugin::listForumTags($forumId) : [];
        $prefixes = $forumId > 0 ? \Plugin\ForumRequiredTag\Plugin::listForumPrefixes($forumId) : [];

        // 使用记录（分页）
        $recordForum = (int)($_GET['record_forum'] ?? 0);
        $page = \max(1, (int)($_GET['page'] ?? 1));
        $records = \Plugin\ForumRequiredTag\Plugin::listRecords($recordForum, $page, 20);
        $totalPages = \max(1, (int)\ceil($records['total'] / 20));
        // 记录补充用户名（M-1/P2：经 User::batchGetNames 批量封装读核心库 users）
        $uids = \array_unique(\array_map(fn($r) => (int)$r['user_id'], $records['rows']));
        $userMap = \app\Models\User::batchGetNames(\array_values($uids));
        foreach ($records['rows'] as &$r) {
            $r['username'] = $userMap[(int)$r['user_id']] ?? ('#' . (int)$r['user_id']);
        }
        unset($r);

        $msg = $_SESSION['frt_msg'] ?? '';
        unset($_SESSION['frt_msg']);

        $this->view('plugins/forum_required_tag/admin', [
            'categories' => $allCategories,
            'forumId' => $forumId,
            'tags' => $tags,
            'prefixes' => $prefixes,
            'records' => $records['rows'],
            'recordTotal' => $records['total'],
            'recordForum' => $recordForum,
            'page' => $page,
            'totalPages' => $totalPages,
            'msg' => $msg,
            '__nav_active' => 'forum-required-tag',
        ]);
    }

    /** 新增 Tag */
    public function addTag()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $forumId = (int)($_POST['forum_id'] ?? 0);
        $name = \trim((string)($_POST['tag_name'] ?? ''));
        if (\mb_strlen($name) > 30) $name = \mb_substr($name, 0, 30);
        $ok = \Plugin\ForumRequiredTag\Plugin::addTag($forumId, $name);
        $_SESSION['frt_msg'] = $ok
            ? I18n::get('plugin.forum_required_tag.msg_add_ok')
            : I18n::get('plugin.forum_required_tag.msg_add_dup');
        $this->redirect('/admin/forum-required-tag?forum_id=' . $forumId);
    }

    /** 新增前缀 */
    public function addPrefix()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $forumId = (int)($_POST['forum_id'] ?? 0);
        $name = \trim((string)($_POST['prefix'] ?? ''));
        if (\mb_strlen($name) > 20) $name = \mb_substr($name, 0, 20);
        $ok = \Plugin\ForumRequiredTag\Plugin::addPrefix($forumId, $name);
        $_SESSION['frt_msg'] = $ok
            ? I18n::get('plugin.forum_required_tag.msg_add_ok')
            : I18n::get('plugin.forum_required_tag.msg_add_dup');
        $this->redirect('/admin/forum-required-tag?forum_id=' . $forumId);
    }

    /** 删除 Tag / 前缀 */
    public function delete()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $table = (string)($_POST['table'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $forumId = (int)($_POST['forum_id'] ?? 0);
        \Plugin\ForumRequiredTag\Plugin::deleteItem($table, $id);
        $this->redirect('/admin/forum-required-tag?forum_id=' . $forumId);
    }

    /** 切换启用/禁用 */
    public function toggle()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $table = (string)($_POST['table'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $forumId = (int)($_POST['forum_id'] ?? 0);
        \Plugin\ForumRequiredTag\Plugin::toggleItem($table, $id);
        $this->redirect('/admin/forum-required-tag?forum_id=' . $forumId);
    }

    /** 一键复制配置（把 $from 版块配置复制到 $to 版块） */
    public function copy()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $from = (int)($_POST['from_forum'] ?? 0);
        $to = (int)($_POST['to_forum'] ?? 0);
        $result = \Plugin\ForumRequiredTag\Plugin::copyConfig($from, $to);
        $_SESSION['frt_msg'] = I18n::get('plugin.forum_required_tag.msg_copy_ok', [
            'tags' => $result['tags'],
            'prefixes' => $result['prefixes'],
        ]);
        $this->redirect('/admin/forum-required-tag?forum_id=' . $to);
    }

    /** 删除使用记录 */
    public function deleteRecord()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $recordForum = (int)($_POST['record_forum'] ?? 0);
        \Plugin\ForumRequiredTag\Plugin::deleteRecord($id);
        $this->redirect('/admin/forum-required-tag?tab=records&record_forum=' . $recordForum);
    }

    /** 全部版块（经核心 Model 读取，禁止直查核心表） */
    private function allCategories(): array
    {
        try {
            return (new \app\Models\Category())->all() ?: [];
        } catch (\Throwable $e) {
            \error_log('forum_required_tag categories error: ' . $e->getMessage());
            return [];
        }
    }
}
