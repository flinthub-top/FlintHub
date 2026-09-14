<?php
/**
 * FlintHub — 版块强制 Tag 插件 核心校验钩子
 * 发帖/编辑帖提交前强制 Tag + 标题前缀校验（替代草案不存在的 _before 钩子）
 *
 * 三条短路保证"未配置版块零影响"：
 *   ① 非 POST 直接返回（读操作零开销）；
 *   ② 路径未命中发帖/编辑帖直接返回（全站其余请求 0 查询）；
 *   ③ 目标版块未配置任何强制项直接返回（0 影响）。
 *
 * 参照 plugins/mod_system/hook/route_before_dispatch.php 拦截先例（mod_system 封禁拦截）。
 * @file plugins/forum_required_tag/hook/route_before_dispatch.php
 * @package Plugin\ForumRequiredTag
 * @version 1.0.0
 */

// ① 非 POST 直接放行
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;

$path = \parse_url($_SERVER['REQUEST_URI'] ?? '', \PHP_URL_PATH) ?: '';
$base = \defined('BASE_PATH') ? \BASE_PATH : '';
if ($base !== '' && \str_starts_with($path, $base)) {
    $path = \substr($path, \strlen($base));
}

// ② 仅命中发帖 / 编辑帖两条路径
$forumId = 0;
$editThreadId = 0;
if ($path === '/post/new') {
    // 发帖：POST 里带版块
    $forumId = (int)($_POST['category_id'] ?? 0);
} elseif (\preg_match('#^/thread/(\d+)/edit$#', $path, $m)) {
    // 编辑帖：优先用 POST 提交的新版块（用户可能改版块，校验按新版块规则）；未携带时回退路径帖子的旧版块
    $editThreadId = (int)$m[1];
    $forumId = (int)($_POST['category_id'] ?? 0);
    if ($forumId <= 0) {
        $thread = (new \app\Models\Thread())->getById($editThreadId); // 核心 Model 必须实例方法调用（禁止静态调用）
        if (!$thread) return;
        $forumId = (int)$thread['category_id'];
    }
    // 编辑帖：tags 为空但帖子原本有 Tag → 用帖子原有 Tag 参与校验
    // （编辑页不打算改动标签时，用原 Tag 放行；格式与发帖一致：取第一个）
    if (\trim((string)($_POST['tags'] ?? '')) === '') {
        $existingTags = \app\Helpers\Tag::getByThread($editThreadId);
        if (!empty($existingTags)) {
            $_POST['tags'] = (string)$existingTags[0]['name'];
        }
    }
} else {
    return; // 非发帖/编辑路径，放行
}

// ③ 该版块未配置任何强制项 → 放行（0 影响）
if (!\Plugin\ForumRequiredTag\Plugin::isForumConstrained($forumId)) return;

$error = \Plugin\ForumRequiredTag\Plugin::validateSubmit(
    $forumId,
    (string)($_POST['tags'] ?? ''),
    (string)($_POST['title'] ?? '')
);

if ($error !== null) {
    // 拦截：422 + 独立错误页（已决策：不重定向回发帖页，不回填；回填列入后续优化）
    // 渲染钩子禁令不适用——route_before_dispatch 是路由分发前拦截，mod_system 同款先例
    \http_response_code(422);
    $view = new \app\Core\TemplateCompiler();
    $view->extend('main');
    $view->display('plugins/forum_required_tag/error', [
        'error' => $error,
        'back' => $path,
    ]);
    exit;
}
