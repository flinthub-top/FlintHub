<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 附件控制器 — 附件下载（权限校验 + 插件钩子预留）
 * @file app/Controllers/AttachmentController.php
 * @package app\Controllers
 */

namespace app\Controllers;

use app\Core\Controller;
use app\Core\Database;
use app\Helpers\Permission;
use app\Helpers\Auth;

class AttachmentController extends Controller
{
    /**
     * GET /attachment/{id} — 下载/查看附件
     * 权限跟随版块 can_view：能浏览该帖所属版块才能下载其附件
     * 预留 attachment_download_before 钩子（积分下载等插件扩展点）
     */
    public function download($id)
    {
        $attachmentId = (int)$id;
        if ($attachmentId <= 0) {
            http_response_code(404);
            exit;
        }

        $db = Database::getInstance();
        $att = $db->fetchOne(
            'SELECT a.id, a.thread_id, a.post_id, a.filename, a.original_name, a.file_size, a.mime_type
             FROM attachments a
             WHERE a.id = :id',
            [':id' => $attachmentId]
        );
        if (!$att) {
            http_response_code(404);
            exit;
        }

        $user = Auth::getCurrentUser();
        $userId = $user ? (int)$user['id'] : null;

        // 版块归属改读 main_index.topic_index（分片真相源）：
        // business.sqlite 的 threads 表已退役（不再写入），LEFT JOIN 旧表对新帖恒为 NULL，
        // 会导致 category_id=0 而跳过权限校验（越权下载）。附件行按 thread_id 关联主题，
        // 从 topic_index 取该主题的 category_id 做版块级权限判定。
        $categoryId = 0;
        if ((int)$att['thread_id'] > 0) {
            $mi = \app\SplitDB\Schema::mainIndexDb();
            $stmt = $mi->prepare('SELECT category_id FROM topic_index WHERE id = :tid');
            $stmt->execute([':tid' => (int)$att['thread_id']]);
            $categoryId = (int)($stmt->fetchColumn() ?: 0);
        }

        // 权限校验：能浏览该版块（can_view）且该组允许附件（can_attach）才能下载（未登录按游客组判定）。
        // 附件无有效版块归属（thread_id 为空或 topic_index 无对应行）时按拒绝处理，禁止默认放行。
        if ($categoryId <= 0
            || !Permission::canView($categoryId, $userId)
            || !Permission::canAttach($categoryId, $userId)) {
            http_response_code(403);
            echo '<h1>403 - ' . \app\Helpers\I18n::get('error.no_perm_download') . '</h1>';
            exit;
        }

        // 插件钩子：附件下载前拦截点（积分下载等扩展）
        // 约定：插件在 $GLOBALS['attachment_download_error'] 置非空字符串即拦截下载
        try {
            \app\Helpers\Plugin::hook('attachment_download_before', [
                'attachment' => $att,
                'user_id' => $userId,
            ]);
        } catch (\Throwable $e) {
            \error_log('Plugin hook error (attachment_download_before): ' . $e->getMessage());
        }
        if (!empty($GLOBALS['attachment_download_error'])) {
            http_response_code(403);
            echo '<h1>403 - ' . htmlspecialchars((string)$GLOBALS['attachment_download_error'], ENT_QUOTES, 'UTF-8') . '</h1>';
            exit;
        }

        // 文件路径安全：basename 防目录穿越 + realpath 校验
        $filePath = rtrim(\UPLOAD_PATH, '/\\') . \DIRECTORY_SEPARATOR . basename($att['filename']);
        $realPath = realpath($filePath);
        if ($realPath === false || !is_file($realPath)) {
            http_response_code(404);
            exit;
        }

        $mime = $att['mime_type'] ?: 'application/octet-stream';
        $isImage = strpos($mime, 'image/') === 0;
        $originalName = $att['original_name'] ?: basename($realPath);

        // ★ [2026-09-04 修复] 二进制直出必须绕开全局 Gzip：
        //   enable_gzip=1 时 init.php 用 ob_start('ob_gzhandler') 压缩输出，而这里
        //   Content-Length 声明的是原始字节数，压缩后实际字节不符 →
        //   浏览器 ERR_CONTENT_DECODING_FAILED → 附件图片/下载失败（编辑器静态图不受影响）。
        //   修复：清空全部输出缓冲（含 ob_gzhandler）+ 禁用 PHP 压缩 +
        //   显式声明 Content-Encoding: identity（同时阻止 IIS 动态压缩二次叠加）。
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        @\ini_set('zlib.output_compression', '0');
        @\ini_set('zlib.output_handler', '');
        \header('Content-Encoding: identity');
        \header('Content-Type: ' . $mime);
        \header('Content-Length: ' . filesize($realPath));
        \header('X-Content-Type-Options: nosniff');
        if (!$isImage) {
            // 非图片附件强制下载；图片内联展示（<img> 可直接引用）
            \header('Content-Disposition: attachment; filename="' . rawurlencode($originalName) . '"');
        }
        \readfile($realPath);
        exit;
    }
}
