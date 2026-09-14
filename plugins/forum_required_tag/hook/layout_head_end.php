<?php
/**
 * FlintHub — 版块强制 Tag 插件 head 尾部钩子
 * 发帖/编辑帖页面：
 *   ① 输出 <meta name="frt-base"> 供前端拼接口 URL（head 内合法，不渲染）
 *   ② 服务端能解析版块 ID 且配置了强制项时，直出 #frt-config 数据容器（首屏即有提示）
 *   ③ 输出插件 assets 引用
 * 注意：不再在 head 输出任何 div 占位（div 放 head 会被渲染到页面顶部）；提示条/选择器
 *       全部由 assets/script.js 动态创建并插入到对应输入框旁（不修改核心视图）。
 * @file plugins/forum_required_tag/hook/layout_head_end.php
 * @package Plugin\ForumRequiredTag
 * @version 1.0.0
 */

// 模板短路：仅发帖/编辑帖页面处理，其余页面 0 查询
$tpl = $template ?? '';
if (!\in_array($tpl, ['post/create', 'thread/edit'], true)) return;

$bp = \defined('BASE_PATH') ? BASE_PATH : '';
$base = $bp;

// 解析当前版块 ID：发帖页取 GET category_id；编辑页从路径取 thread_id 回退 Thread 模型
$forumId = (int)($_GET['category_id'] ?? 0);
if ($forumId <= 0) {
    $path = \parse_url($_SERVER['REQUEST_URI'] ?? '', \PHP_URL_PATH) ?: '';
    if ($base !== '' && \str_starts_with($path, $base)) {
        $path = \substr($path, \strlen($base));
    }
    if (\preg_match('#^/thread/(\d+)/edit$#', $path, $m)) {
        try {
            $thread = (new \app\Models\Thread())->getById((int)$m[1]); // 实例方法调用（禁止静态调用）
            if ($thread) $forumId = (int)$thread['category_id'];
        } catch (\Throwable $e) {
            \error_log('forum_required_tag layout_head_end thread lookup error: ' . $e->getMessage());
        }
    }
}

// ① BASE_PATH 元数据（head 内合法；script.js 读它拼接口 URL）
echo '<meta name="frt-base" content="' . \htmlspecialchars($bp, \ENT_QUOTES, 'UTF-8') . '">';

// ② 服务端可解析版块且配置了强制项 → 直出数据容器（首屏渲染，免一次 fetch）
if (\Plugin\ForumRequiredTag\Plugin::isForumConstrained($forumId)) {
    $tags = \Plugin\ForumRequiredTag\Plugin::getForumTags($forumId);
    $prefixes = \Plugin\ForumRequiredTag\Plugin::getForumPrefixes($forumId);
    $dataJson = \json_encode([
        'base' => $bp,
        'forum_id' => $forumId,
        'tags' => \array_column($tags, 'tag_name'),
        'prefixes' => \array_column($prefixes, 'prefix'),
        'required_tag' => !empty($tags),
        'required_prefix' => !empty($prefixes),
        'i18n' => [
            'hint_tags' => \app\Helpers\I18n::get('plugin.forum_required_tag.hint_tags'),
            'hint_prefix' => \app\Helpers\I18n::get('plugin.forum_required_tag.hint_prefix'),
        ],
    ], \JSON_UNESCAPED_UNICODE);
    // hidden div + data-config 属性（htmlspecialchars 防注入；hidden 由浏览器默认 display:none，无 CSS 覆盖）
    echo '<div id="frt-config" hidden data-config="' . \htmlspecialchars($dataJson, \ENT_QUOTES, 'UTF-8') . '"></div>';
}

// ③ 资源引用（filemtime 版本号）
$cssVer = @\filemtime(__DIR__ . '/../assets/style.css') ?: 1;
$jsVer = @\filemtime(__DIR__ . '/../assets/script.js') ?: 1;
echo '<link rel="stylesheet" href="' . $bp . '/plugins/forum_required_tag/assets/style.css?v=' . $cssVer . '">';
echo '<script src="' . $bp . '/plugins/forum_required_tag/assets/script.js?v=' . $jsVer . '" defer></script>';
