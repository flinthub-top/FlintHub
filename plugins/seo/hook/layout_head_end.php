<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * SEO 钩子 — 在 </head> 前注入 Meta / OG / JSON-LD / 统计代码
 * @file plugins/seo/hook/layout_head_end.php
 * @package Plugin\Seo
 * @version 1.0.0
 */

// ============================================================
// 1. 读取 SEO 设置
// ============================================================
$seoKeywords   = \app\Helpers\Settings::get('seo_meta_keywords', '');
$seoDesc       = \app\Helpers\Settings::get('seo_meta_description', '');
$seoCustomHead = \app\Helpers\Settings::get('seo_custom_head', '');
$siteName      = \app\Helpers\Settings::get('site_name', \DEFAULT_SITE_NAME);
$siteDesc      = \app\Helpers\Settings::get('site_description', '一个简洁现代的PHP论坛');

// ============================================================
// 2. 计算站点基础 URL（从 SITE_URL 常量取，不依赖 HTTP_HOST）
// ============================================================
$baseUrl  = rtrim(SITE_URL, '/');
// 当前请求 URI（不含查询参数）
$uriPath  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uriPath  = rtrim($uriPath, '/') ?: '/';
$canonical = $baseUrl . $uriPath;

// ============================================================
// 3. 页面类型检测
// ============================================================
$pageDesc  = '';

// 跳过后台页面
if (strpos($uriPath, '/admin') === 0) {
    return;
}

// -- 首页 --
if ($uriPath === '/' || $uriPath === '/index.php') {
    $pageDesc  = $seoDesc ?: $siteDesc;

// -- 帖子详情 --
} elseif (preg_match('#^/thread/(\d+)$#', $uriPath, $m)) {
    $threadId = (int)$m[1];
    try {
        // [SplitDB] 帖子已迁至 main_index（topic_index），经 Thread 模型读分片（含正文 extern + 用户名）
        $thread = (new \app\Models\Thread())->find($threadId);
        if ($thread) {
            $plain = trim(strip_tags(\app\Helpers\Content::decode($thread['content'] ?? '')));
            $pageDesc = mb_substr($plain, 0, 150);
            if (mb_strlen($plain) > 150) $pageDesc .= '…';
        }
    } catch (\Throwable $e) {
        // 静默
    }

// -- 博客详情 --
} elseif (preg_match('#^/blog/(\d+)$#', $uriPath, $m)) {
    $blogId = (int)$m[1];
    try {
        // [SplitDB] 博客经 Blog Model（content 已 decode），不再联表直查 blogs/users
        $blog = (new \app\Models\Blog())->getWithUser($blogId);
        if ($blog) {
            $plain = trim(strip_tags((string)($blog['content'] ?? '')));
            $pageDesc = mb_substr($plain, 0, 150);
            if (mb_strlen($plain) > 150) $pageDesc .= '…';
        }
    } catch (\Throwable $e) {
        // 静默
    }

// -- 版块详情 --
} elseif (preg_match('#^/forum/category/(\d+)$#', $uriPath, $m)) {
    $catId = (int)$m[1];
    try {
        $db = \app\Core\Database::getInstance();
        $cat = $db->fetchOne("SELECT name, description FROM categories WHERE id = :id", [':id' => $catId]);
        if ($cat) {
            $pageDesc  = !empty($cat['description']) ? $cat['description'] : ($seoDesc ?: $siteDesc);
        }
    } catch (\Throwable $e) {
        // 静默
    }

// -- 论坛列表 --
} elseif ($uriPath === '/forum') {
    $pageDesc  = $seoDesc ?: $siteDesc;

// -- 博客列表 --
} elseif ($uriPath === '/blog') {
    $pageDesc  = $seoDesc ?: $siteDesc;

// -- 搜索 --
} elseif (strpos($uriPath, '/search') === 0) {
    $pageDesc  = $seoDesc ?: $siteDesc;

// -- 标签 --
} elseif (strpos($uriPath, '/tags') === 0 || strpos($uriPath, '/tag/') === 0) {
    $pageDesc  = $seoDesc ?: $siteDesc;

// -- 默认 --
} else {
    $pageDesc  = $seoDesc ?: $siteDesc;
}

// 确保有值
if (empty($pageDesc)) $pageDesc = $seoDesc ?: $siteDesc;
$pageDesc   = htmlspecialchars($pageDesc, ENT_QUOTES, 'UTF-8');
$seoKeywords = htmlspecialchars($seoKeywords, ENT_QUOTES, 'UTF-8');
$canonical  = htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8');

// ============================================================
// 4. 输出搜索引擎 Meta 标签
// ============================================================
?>
<meta name="description" content="<?php echo $pageDesc; ?>">
<?php if (!empty($seoKeywords)): ?>
<meta name="keywords" content="<?php echo $seoKeywords; ?>">
<?php endif; ?>
<?php if (!empty($seoCustomHead)): ?>
<?php echo \app\Helpers\Content::decode($seoCustomHead) . "\n"; ?>
<?php endif; ?>
<link rel="canonical" href="<?php echo $canonical; ?>">
