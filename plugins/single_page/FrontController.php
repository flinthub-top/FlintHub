<?php
/**
 * FlintHub — 单页/链接管理插件 前台控制器
 * 站内单页渲染：GET /page/{slug}
 * @file plugins/single_page/FrontController.php
 * @package Plugin\SinglePage
 * @version 1.0.0
 */

namespace Plugin\SinglePage;

use app\Core\Controller;

class FrontController extends Controller
{
    public function show(string $slug)
    {
        $page = \Plugin\SinglePage\Plugin::getBySlug($slug);

        // 未公开 / 不存在 / 非站内页类型 → 一律 404，不泄露存在性
        if (
            $page === null
            || (int)($page['is_public'] ?? 0) !== 1
            || (string)($page['type'] ?? '') !== 'page'
        ) {
            $this->notFound();
            return;
        }

        $this->view('plugins/single_page/page', [
            'page' => $page,
            '__nav_active' => 'single-page-' . $slug,
        ]);
    }

    /**
     * 404 兜底：输出 404 状态 + 前台 404 视图（若存在），否则重定向首页
     */
    private function notFound(): void
    {
        \http_response_code(404);
        try {
            $this->view('errors/404');
        } catch (\Throwable $e) {
            $this->redirect('/');
        }
    }
}
