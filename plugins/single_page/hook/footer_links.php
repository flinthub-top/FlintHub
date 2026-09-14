<?php
/**
 * FlintHub — 单页/链接管理插件 页脚链接区钩子
 * 读取全部公开记录：站内页 → /page/{slug}；外链 → 原样输出并强制 noopener
 * @file plugins/single_page/hook/footer_links.php
 * @package Plugin\SinglePage
 * @version 1.0.0
 */

$items = \Plugin\SinglePage\Plugin::getPublic();
if (empty($items)) return;

$base = \defined('BASE_PATH') ? \BASE_PATH : '';
$links = [];

foreach ($items as $item) {
    $title = \htmlspecialchars((string)($item['title'] ?? ''), ENT_QUOTES, 'UTF-8');
    if ($title === '') continue;

    $type = (string)($item['type'] ?? 'page');
    if ($type === 'link') {
        $url = \htmlspecialchars((string)($item['url'] ?? ''), ENT_QUOTES, 'UTF-8');
        if ($url === '' || !\preg_match('#^https?://#i', $url)) continue;
        $links[] = '<a href="' . $url . '" target="_blank" rel="noopener">' . $title . '</a>';
    } else {
        $slug = \htmlspecialchars((string)($item['slug'] ?? ''), ENT_QUOTES, 'UTF-8');
        if ($slug === '') continue;
        $links[] = '<a href="' . $base . '/page/' . $slug . '" class="mn-text-decoration-none">' . $title . '</a>';
    }
}

// 分隔符由布局层 CSS 统一处理（.mn-footer-right a + a::before 自动插入 ·），插件只输出 <a>
if (!empty($links)) {
    echo implode('', $links);
}
