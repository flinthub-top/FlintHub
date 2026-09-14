<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 标签前台控制器 — 标签列表展示、标签关联帖子
 * @file app/Controllers/TagsController.php
 * @package app\Controllers
 */

namespace app\Controllers;

use app\Core\Controller;
use app\Models\Tag;
use app\Helpers\Permission;

class TagsController extends Controller
{
    public function index()
    {
        $tagModel = new Tag();
        $tags = $tagModel->getAllWithCount();
        $this->view('tags/index', ['tags' => $tags, '__nav_active' => 'tags']);
    }

    public function show($id)
    {
        $tagId = (int)$id;
        $tag = (new \app\Models\Tag())->find($tagId);
        if (!$tag) {
            $this->redirect('/tags');
        }

        $perPage = 20;
        // 先按请求页码查询（返回含 total），随后按真实 totalPages 钳制页码；
        // 越界页码回落到最后一页并重查（与前台一致，解除 1000 页上限）
        $rawPage = max(1, (int)($_GET['page'] ?? 1));
        $page = $rawPage;
        $threads = \app\Helpers\Tag::getThreads($tag['name'], $page, $perPage);
        $totalPages = $threads['total'] > 0 ? max(1, (int)ceil($threads['total'] / $perPage)) : 1;
        $page = max(1, min($totalPages, $page));
        if ($rawPage !== $page) {
            // 越界请求：回落到最后一页并重查数据
            $threads = \app\Helpers\Tag::getThreads($tag['name'], $page, $perPage);
        }

        // SQL 层权限过滤：剔除无权查看的帖子（在分页查询前过滤，保证分页准确）
        $currentUser = $this->currentUser();
        $userId = $currentUser ? (int)$currentUser['id'] : null;
        $allowedIds = Permission::getAuthorizedCategoryIds($userId);
        if (!empty($allowedIds)) {
            $threads['threads'] = array_values(array_filter($threads['threads'], function ($t) use ($allowedIds) {
                return in_array((int)$t['category_id'], $allowedIds, true);
            }));
            $threads['total'] = count($threads['threads']);
        } elseif (!empty($threads['threads'])) {
            $threads['threads'] = [];
            $threads['total'] = 0;
        }

        // 重新计算总页数（基于过滤后的实际条数）
        $totalPages = $threads['total'] > 0 ? max(1, (int)ceil($threads['total'] / $perPage)) : 1;
        // 权限过滤可能减少总数，再次钳制页码（高亮与实际页一致）
        $page = max(1, min($totalPages, $page));

        $this->view('tags/show', [
            'tag' => $tag,
            'threads' => $threads['threads'] ?? [],
            'total' => $threads['total'] ?? 0,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
            'tags' => \app\Helpers\Tag::getPopular(20),
            'pageTitle' => \app\Helpers\I18n::get('tags.page_title', ['name' => htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8')]),
        ]);
    }
}
