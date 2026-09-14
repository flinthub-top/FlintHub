<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * SEO 前台控制器 — sitemap.xml / robots.txt
 * @file plugins/seo/FrontController.php
 * @package Plugin\Seo
 * @version 1.0.0
 */

namespace Plugin\Seo;

use app\Core\Database;

class FrontController
{
    /**
     * 输出 XML 站点地图
     */
    public function sitemap()
    {
        if (Plugin::getSetting('enable_sitemap', '1') !== '1') {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Sitemap disabled.';
            return;
        }

        $siteUrl = Plugin::getSetting('site_url');
        if (empty($siteUrl)) {
            $siteUrl = rtrim(SITE_URL, '/');
        }
        $siteUrl = rtrim($siteUrl, '/');

        $db = Database::getInstance();

        // 获取最近 1000 个帖子（[SplitDB] 经 Thread Model 读 main_index 分片，不再直查退役表 threads）
        $threads = (new \app\Models\Thread())->getLatest(1000);

        // 获取最近 500 篇博客（经 Blog Model）
        $blogs = (new \app\Models\Blog())->getLatest(500);

        // 获取版块
        $categories = $db->fetchAll(
            "SELECT id, name FROM categories ORDER BY id ASC"
        );

        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // 首页
        echo "  <url>\n    <loc>{$siteUrl}/</loc>\n    <priority>1.0</priority>\n    <changefreq>daily</changefreq>\n  </url>\n";

        // 论坛
        echo "  <url>\n    <loc>{$siteUrl}/forum</loc>\n    <priority>0.8</priority>\n    <changefreq>hourly</changefreq>\n  </url>\n";

        // 博客
        echo "  <url>\n    <loc>{$siteUrl}/blog</loc>\n    <priority>0.8</priority>\n    <changefreq>daily</changefreq>\n  </url>\n";

        // 版块
        foreach ($categories as $cat) {
            $loc = $siteUrl . '/forum/category/' . (int)$cat['id'];
            echo "  <url>\n    <loc>{$loc}</loc>\n    <priority>0.6</priority>\n    <changefreq>hourly</changefreq>\n  </url>\n";
        }

        // 帖子
        foreach ($threads as $t) {
            $loc = $siteUrl . '/thread/' . (int)$t['id'];
            $date = $this->formatDate($t['created_at'] ?? '');
            echo "  <url>\n    <loc>{$loc}</loc>\n";
            if ($date) echo "    <lastmod>{$date}</lastmod>\n";
            echo "    <priority>0.6</priority>\n    <changefreq>weekly</changefreq>\n  </url>\n";
        }

        // 博客
        foreach ($blogs as $b) {
            $loc = $siteUrl . '/blog/' . (int)$b['id'];
            $date = $this->formatDate($b['created_at'] ?? '');
            echo "  <url>\n    <loc>{$loc}</loc>\n";
            if ($date) echo "    <lastmod>{$date}</lastmod>\n";
            echo "    <priority>0.7</priority>\n    <changefreq>monthly</changefreq>\n  </url>\n";
        }

        echo '</urlset>';
    }

    /**
     * 输出 robots.txt
     */
    public function robots()
    {
        $siteUrl = Plugin::getSetting('site_url');
        if (empty($siteUrl)) {
            $siteUrl = rtrim(SITE_URL, '/');
        }
        $siteUrl = rtrim($siteUrl, '/');

        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\n";
        echo "Disallow: /admin/\n";
        echo "Disallow: /api/\n";
        echo "Disallow: /login\n";
        echo "Disallow: /register\n";
        echo "Disallow: /logout\n";
        echo "Disallow: /forgot-password\n";
        echo "Disallow: /reset-password/\n";
        echo "Disallow: /verify-email/\n";
        echo "Disallow: /message/\n";
        echo "Disallow: /profile\n";
        echo "Disallow: /theme-settings\n";
        echo "Sitemap: {$siteUrl}/sitemap.xml\n";
    }

    /**
     * 格式化日期为 W3C 格式
     */
    private function formatDate(?string $date): string
    {
        if (empty($date)) return '';
        $ts = strtotime($date);
        return $ts ? date('Y-m-d\TH:i:sP', $ts) : '';
    }
}
