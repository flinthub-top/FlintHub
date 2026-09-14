<?php
/**
 * Modern 主题 — 主布局：三栏骨架 + 卡片面板 + 精简导航
 */
$isAdminPage = isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/admin') === 0;
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$navActive = $this->getData('__nav_active', '');
// 单模式下隐藏顶部论坛/博客入口（portal 双开显示；发帖按钮仅论坛关闭时隐藏）
$siteMode = \app\Helpers\Settings::get('site_mode', 'portal');
$showForumNav = ($siteMode === 'portal');
$showBlogNav = ($siteMode === 'portal');
$showPostBtn = ($siteMode !== 'blog');
// 单模式下 PC 导航搜索/主题/语言补文字
$isSingleMode = ($siteMode === 'forum' || $siteMode === 'blog');
// 通栏门户首页：portal 模式且为首页时隐藏左右侧栏，内容横跨整页（页眉/页脚/移动导航仍由本布局共享）
$isPortalHome = ($navActive === 'home' && $siteMode === 'portal');

// 布局开关（PC/移动导航“布局”按钮共用，2026-09-05）：当前摘要态 + PRG 回跳 URL
$layoutExcerptOn = \app\Helpers\Theme::layoutExcerptNavState();
$layoutToggleUrl = $this->url('/layout-toggle') . '?next=' . rawurlencode((string)$uri);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php $this->yield('title', $this->getData('siteName', 'SoSite')); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo $this->url('/favicon.svg'); ?>">
    <!-- [PWA] manifest + 主题色（theme_color 与 manifest.webmanifest 保持一致） -->
    <link rel="manifest" href="<?php echo $this->url('/assets/pwa/manifest.webmanifest'); ?>">
    <meta name="theme-color" content="#3b82f6">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?php echo $this->e($this->getData('siteName', 'FlintHub')); ?>">
    <link rel="apple-touch-icon" href="<?php echo $this->url('/assets/pwa/apple-touch-icon.png'); ?>">
<?php
\app\Helpers\Plugin::hook('layout_head_end', ['template' => $this->getData('__current_template', '')]);
?>

<?php if ($isAdminPage): ?>
    <!-- 后台页面：仅加载必需 CSS（基础 + 后台核心）；modern.css 统一在下方加载 -->
    <link rel="stylesheet" href="<?php echo $this->asset('css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo $this->asset('css/admin.css'); ?>">
<?php else: ?>
    <!-- 前台页面：页面级 CSS 由各视图按需声明，modern.css 基座统一加载 -->
<?php endif; ?>
<?php $this->yield('css'); ?>
    <link rel="stylesheet" href="<?php echo $this->asset('css/modern.css'); ?>">
<?php
// 加载 modern 配色变体（modern-xxx 系列）
$themeDetected = \app\Helpers\Theme::getCurrent();
if ($themeDetected !== '' && strpos($themeDetected, 'modern-') === 0) {
    $variantFile = 'css/themes/' . $themeDetected . '.css';
    if (file_exists(__DIR__ . '/../../../assets/' . $variantFile)) {
        echo '<link rel="stylesheet" href="' . $this->asset($variantFile) . '">';
    }
}
?>

    <link rel="stylesheet" href="<?php echo $this->asset('css/fontawesome.css'); ?>">
<?php if ($this->getData('needsEditor', false)): ?>
    <link rel="stylesheet" href="<?php echo $this->asset('css/editor.css'); ?>">
    <script src="<?php echo $this->asset('js/editor.js'); ?>"></script>
<?php endif; ?>
    <script>window.CSRF_TOKEN='<?php echo $this->e($this->getData('csrfToken', '')); ?>';window.BASE_PATH=<?php echo json_encode(defined('BASE_PATH') ? BASE_PATH : '', JSON_UNESCAPED_SLASHES); ?>;</script>

    <!-- [i18n] JS 端文案注入：全局 __t() + Alpine.store('i18n') -->
    <script>
    window.FlintHubI18n = <?php echo json_encode(\app\Helpers\I18n::jsSubset(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    window.__t = function(key, params) {
        var s = (window.FlintHubI18n && window.FlintHubI18n[key] !== undefined) ? window.FlintHubI18n[key] : key;
        if (params) {
            for (var k in params) { if (Object.prototype.hasOwnProperty.call(params, k)) { s = s.split('{' + k + '}').join(params[k]); } }
        }
        return s;
    };
    document.addEventListener('alpine:init', function() {
        if (window.Alpine) {
            window.Alpine.store('i18n', { t: window.__t, dict: window.FlintHubI18n });
        }
    });
    </script>

    <!-- FhState (Alpine.js) 状态管理 -->
    <script defer src="<?php echo $this->asset('lib/fhstate.js'); ?>"></script>

    <!-- FhAjax (htmx) AJAX 请求 -->
    <script src="<?php echo $this->asset('lib/fhajax.js'); ?>"></script>
</head>
<body>

<?php if (!$isAdminPage): ?>
<?php
// 移动端抽屉：按站点模式取分类（不依赖页面视图数据）
$drawerHasForum = ($siteMode !== 'blog');
$drawerHasBlog  = ($siteMode !== 'forum');
$drawerForumCats = [];
if ($drawerHasForum) {
    // 优先复用控制器已传入视图的 categories（详情页/论坛页/首页均已传，免二次全表查库）；
    // 未传入的页面（如独立插件页）兜底查询，行为不变
    $drawerForumCats = $this->getData('categories', []);
    if (empty($drawerForumCats)) {
        $drawerForumCats = (new \app\Models\Category())->allOrdered();
    }
    $dUser = $this->getData('currentUser');
    $dUid = $dUser ? (int)($dUser['id'] ?? 0) : 0;
    $dAllowed = \app\Helpers\Permission::getAuthorizedCategoryIds($dUid ?: null);
    if (!empty($dAllowed)) {
        $drawerForumCats = array_values(array_filter($drawerForumCats, function ($c) use ($dAllowed) {
            return in_array((int)($c['id'] ?? 0), $dAllowed, true);
        }));
    }
}
$drawerBlogCats = [];
if ($drawerHasBlog) {
    // 优先复用控制器已传入的 blogCategories（博客页/首页均已传），未传入的页面兜底查询
    $drawerBlogCats = $this->getData('blogCategories', []);
    if (empty($drawerBlogCats)) {
        $drawerBlogCats = (new \app\Models\BlogCategory())->allOrdered();
    }
}
$drawerBlogArchives = [];
if ($drawerHasBlog) {
    // 博客归档走 30s 短 TTL 缓存（Blog::getArchivesCached），控制器已传 archives 时直接复用，避免每请求裸 GROUP BY
    $drawerBlogArchives = $this->getData('archives', []);
    if (empty($drawerBlogArchives)) {
        $drawerBlogArchives = (new \app\Models\Blog())->getArchivesCached();
    }
}
// 抽屉当前激活项：命中「具体分类」优先，其次命中「模块大项」
$dUri = $_SERVER['REQUEST_URI'] ?? '/';
$dForumCat = 0;
if (preg_match('#^/forum/category/(\d+)#', $dUri, $m3)) { $dForumCat = (int)$m3[1]; }
$dBlogCat = 0;
if (preg_match('#^/blog/category/(\d+)#', $dUri, $m4)) { $dBlogCat = (int)$m4[1]; }
$dArchive = $_GET['archive'] ?? '';
$dHomeActive = $navActive === 'home' || strpos($dUri, '/?') === 0;
$dForumActive = $dForumCat === 0 && ($navActive === 'forum' || strpos($dUri, '/thread') === 0 || strpos($dUri, '/post') === 0 || preg_match('#^/forum(/|\?|$)#', $dUri));
$dBlogActive = $dBlogCat === 0 && ($navActive === 'blog' || strpos($dUri, '/blog') === 0);
?>
<!-- ====== 导航栏 ====== -->
<nav class="mn-nav" x-data="{ drawerOpen: false }" x-on:keydown.escape.window="drawerOpen = false">
    <div class="mn-nav-inner">
        <!-- 移动端左侧汉堡按钮（仅 ≤768px 显示） -->
        <button type="button" class="mn-drawer-toggle" aria-label="<?php echo $this->t('nav.home'); ?>" x-on:click="drawerOpen = true"><?php echo $this->icon('menu', 18); ?></button>
        <a href="<?php echo $this->url('/'); ?>" class="mn-logo mn-logo-stacked">
            <span class="mn-logo-line"><span class="mn-drawer-logo-icon"><img src="<?php echo $this->url('/logo.svg'); ?>" alt="" width="18" height="18" style="display:block;border-radius:4px;"></span><?php echo $this->e($this->getData('siteName')); ?></span>
            <span class="mn-logo-desc"><?php echo $this->e($this->getData('siteDescription')); ?></span>
        </a>

        <div class="mn-nav-links" id="mnNavLinks">
            <a href="<?php echo $this->url('/'); ?>" class="<?php echo $navActive === 'home' ? 'mn-active' : ''; ?>"><?php echo $this->icon('home', 14); ?> <?php echo $this->t('nav.home'); ?></a>
            <?php if ($showForumNav): ?><a href="<?php echo $this->url('/forum'); ?>" class="<?php echo $navActive === 'forum' ? 'mn-active' : ''; ?>"><?php echo $this->icon('forum', 14); ?> <?php echo $this->t('nav.forum'); ?></a><?php endif; ?>
            <?php if ($showBlogNav): ?><a href="<?php echo $this->url('/blog'); ?>" class="<?php echo $navActive === 'blog' ? 'mn-active' : ''; ?>"><?php echo $this->icon('blog', 14); ?> <?php echo $this->t('nav.blog'); ?></a><?php endif; ?>
<?php
// 插件主导航钩子（nav_main_links，位于搜索链接前）
\app\Helpers\Plugin::hook('nav_main_links');
?>
            <a href="<?php echo $this->url('/search'); ?>" title="<?php echo $this->t('nav.search'); ?>" class="<?php echo $navActive === 'search' || strpos($uri, '/search') === 0 ? 'mn-active' : ''; ?>"><?php echo $this->icon('search', 14); ?><?php if ($isSingleMode): ?> <?php echo $this->t('nav.search'); ?><?php endif; ?></a>
            <a href="<?php echo $this->url('/theme-settings'); ?>" title="<?php echo $this->t('nav.theme'); ?>" class="<?php echo $navActive === 'theme' || strpos($uri, '/theme-settings') === 0 ? 'mn-active' : ''; ?>"><?php echo $this->icon('theme', 14); ?><?php if ($isSingleMode): ?> <?php echo $this->t('nav.theme'); ?><?php endif; ?></a>
            <!-- 布局开关（PC）：点击切换列表摘要显示（PRG 回原页），图标随状态 columns/list -->
            <a href="<?php echo $this->e($layoutToggleUrl); ?>" title="<?php echo $this->t($layoutExcerptOn ? 'nav.layout_click_hide' : 'nav.layout_click_show'); ?>"><?php echo $this->icon($layoutExcerptOn ? 'columns' : 'list', 14); ?><?php if ($isSingleMode): ?> <?php echo $this->t('nav.layout'); ?><?php endif; ?></a>
            <!-- [i18n] 语言切换（语言 ≥2 时显示；?lang= 切换，PRG 回原页） -->
            <?php $i18nLangs = \app\Helpers\I18n::available(); ?>
            <?php if (count($i18nLangs) >= 2): ?>
            <?php $i18nCurrent = \app\Helpers\I18n::current(); ?>
            <?php
            $i18nUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
            $i18nBase = (string)preg_replace('/([?&])lang=[^&]*&?/', '$1', $i18nUri);
            $i18nBase = rtrim($i18nBase, '?&');
            $i18nSep = (strpos($i18nBase, '?') === false) ? '?' : '&';
            ?>
            <div class="mn-nav-lang mn-dropdown-wrap" style="margin-left:6px;" x-data="{ langOpen: false }" x-on:click.outside="langOpen = false">
                <button type="button" class="mn-btn mn-btn-sm mn-border-none mn-bg-none mn-cursor-pointer mn-nav-lang-btn" title="<?php echo $this->t('nav.language'); ?>" style="display:inline-flex;align-items:center;padding:6px 14px;border-radius:6px;" x-on:click="langOpen = !langOpen">
                    <?php echo $this->icon('globe-asia', 14); ?><?php if ($isSingleMode): ?> <?php echo $this->t('nav.language'); ?><?php endif; ?>
                </button>
                <div class="mn-dropdown-panel mn-dropdown-panel-left" style="display:none;" x-show="langOpen">
                    <?php foreach ($i18nLangs as $lang): ?>
                    <?php $isCur = ($lang === $i18nCurrent); ?>
                    <a href="<?php echo $this->e($this->url($i18nBase . $i18nSep . 'lang=' . $lang)); ?>" class="mn-dropdown-item<?php echo $isCur ? ' mn-dropdown-item-active' : ''; ?>"><?php echo $this->t('lang.' . $lang); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="mn-nav-right"
             x-data="{ appOpen: false, langOpen: false }"
             x-on:click.outside="appOpen = false; langOpen = false"
             x-on:mn-close-top.window="appOpen = false; langOpen = false"
             :class="{ 'mn-apps-open': appOpen }">
            <!-- 移动端图标（仅移动端可见）：搜索 → 主题 → 语言切换 → 应用（搜索图标仅登录后显示，游客端顶部过挤且 /search 需登录） -->
            <?php if ($this->getData('isLoggedIn')): ?><a href="<?php echo $this->url('/search'); ?>" class="mn-mobile-icon" title="<?php echo $this->t('nav.search'); ?>"><?php echo $this->icon('search', 16); ?></a><?php endif; ?>
            <a href="<?php echo $this->url('/theme-settings'); ?>" class="mn-mobile-icon" title="<?php echo $this->t('nav.theme'); ?>"><?php echo $this->icon('theme', 16); ?></a>
            <!-- [i18n] 移动端语言切换（仅移动端可见） -->
            <?php if (count($i18nLangs ?? []) >= 2): ?>
            <div class="mn-mobile-icon mn-nav-lang" title="<?php echo $this->t('lang.' . $i18nCurrent); ?>" :class="{ 'mn-lang-open': langOpen }">
                <a href="#" x-on:click.prevent="appOpen = false; langOpen = !langOpen; $dispatch('mn-close-mine')" class="mn-mobile-icon-link"><?php echo $this->icon('globe-asia', 16); ?></a>
                <div class="mn-mobile-lang-dropdown">
                    <?php foreach ($i18nLangs as $lang): ?>
                    <?php $isCur = ($lang === $i18nCurrent); ?>
                    <a href="<?php echo $this->e($this->url($i18nBase . $i18nSep . 'lang=' . $lang)); ?>" style="<?php echo $isCur ? 'color:var(--mn-primary,#667eea);font-weight:600;' : ''; ?>"><?php echo $this->t('lang.' . $lang); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="mn-mobile-icon mn-mobile-icon-apps" title="<?php echo $this->t('sidebar.apps'); ?>">
                <a href="#" x-on:click.prevent="langOpen = false; appOpen = !appOpen; $dispatch('mn-close-mine')" class="mn-mobile-icon-link"><?php echo $this->icon('grid', 16); ?></a>
                <div class="mn-apps-dropdown"><!-- CSS 通过 .mn-nav-right.mn-apps-open 控制显隐 -->
                    <?php
\app\Helpers\Plugin::hook('nav_plugin_links');
?>
                </div>
            </div>
            <!-- 布局开关（移动端，位于应用后）：点击切换列表摘要显示（PRG 回原页） -->
            <a href="<?php echo $this->e($layoutToggleUrl); ?>" class="mn-mobile-icon" title="<?php echo $this->t($layoutExcerptOn ? 'nav.layout_click_hide' : 'nav.layout_click_show'); ?>"><?php echo $this->icon($layoutExcerptOn ? 'columns' : 'list', 16); ?></a>

<?php if ($this->getData('isLoggedIn')): ?>
            <!-- PC 用户下拉：点击用户名弹出菜单（Alpine 显隐；收藏数 htmx 懒加载） -->
            <div class="mn-desktop-only mn-dropdown-wrap" x-data="{ userOpen: false }" x-on:click.outside="userOpen = false">
                <button type="button" class="mn-cursor-pointer mn-user-btn" x-on:click="userOpen = !userOpen" title="<?php echo $this->t('nav.profile'); ?>">
                    <?php echo $this->icon('user', 14); ?><span><?php echo $this->e($this->getData('currentUsername')); ?></span>
                </button>
                <div class="mn-dropdown-panel mn-dropdown-panel-right mn-dropdown-panel-lg" style="display:none;" x-show="userOpen">
                    <a href="<?php echo $this->url('/profile'); ?>" class="mn-dropdown-item"><?php echo $this->icon('user', 14); ?><?php echo $this->t('nav.profile'); ?></a>
                    <?php if (\app\Helpers\Settings::get('site_mode', 'portal') !== 'blog'): ?>
                    <a href="<?php echo $this->url('/favorites'); ?>" class="mn-dropdown-item"><?php echo $this->icon('star', 14); ?><?php echo $this->t('profile.my_collections'); ?><span class="fav-count-badge" hx-get="<?php echo $this->url('/api/favorites-count'); ?>" hx-trigger="revealed" hx-swap="innerHTML"></span></a>
                    <?php endif; ?>
                    <?php if (\app\Helpers\Settings::get('message_enabled') === '1'): ?>
                    <a href="<?php echo $this->url('/message'); ?>" class="mn-dropdown-item"><?php echo $this->icon('message', 14); ?><?php echo $this->t('nav.messages'); ?><?php $uc = (int)$this->getData('unreadCount', 0); if ($uc > 0): ?><small class="mn-text-error mn-fw-700"><?php echo $uc; ?></small><?php endif; ?></a>
                    <?php endif; ?>
                    <?php if ($this->getData('isAdmin')): ?>
                    <a href="<?php echo $this->url('/admin'); ?>" class="mn-dropdown-item"><?php echo $this->icon('admin', 14); ?><?php echo $this->t('nav.admin'); ?></a>
                    <?php endif; ?>
                    <div class="mn-dropdown-hookitems"><?php \app\Helpers\Plugin::hook('nav_user_menu_items', ['placement' => 'pc']); ?></div>
                    <form method="POST" action="<?php echo $this->url('/logout'); ?>">
                        <input type="hidden" name="csrf" value="<?php echo $this->e($this->getData('csrfToken')); ?>">
                        <button type="submit" class="mn-bg-none mn-border-none mn-cursor-pointer mn-dropdown-item mn-dropdown-item-logout"><?php echo $this->icon('logout', 14); ?><?php echo $this->t('nav.logout'); ?></button>
                    </form>
                </div>
            </div>
            <?php $this->include('_components/notification_bell', ['placement' => 'pc']); ?>
<?php else: ?>
            <a href="<?php echo $this->url('/login'); ?>" class="mn-desktop-only"><?php echo $this->icon('login', 14); ?> <?php echo $this->t('nav.login'); ?></a>
            <a href="<?php echo $this->url('/register'); ?>" class="mn-desktop-only"><?php echo $this->icon('register', 14); ?> <?php echo $this->t('nav.register'); ?></a>
            <!-- 移动端未登录 -->
            <a href="<?php echo $this->url('/login'); ?>" class="mn-mobile-icon"><?php echo $this->icon('login', 16); ?></a>
<?php endif; ?>
        </div>
    </div>
<!-- 移动端左侧抽屉：仅 ≤768px 显示（Alpine 控制显隐，点击遮罩 / Esc 关闭） -->
    <div class="mn-drawer-overlay" :class="{ 'mn-drawer-open': drawerOpen }" x-on:click="drawerOpen = false"></div>
    <aside class="mn-drawer" :class="{ 'mn-drawer-open': drawerOpen }" aria-label="<?php echo $this->t('nav.home'); ?>">
        <div class="mn-drawer-head">
            <span class="mn-drawer-title mn-drawer-logo"><img src="<?php echo $this->url('/logo.svg'); ?>" alt="" width="18" height="18"><?php echo $this->e($this->getData('siteName')); ?></span>
            <button type="button" class="mn-drawer-close" x-on:click="drawerOpen = false" aria-label="close">&times;</button>
        </div>
        <div class="mn-drawer-body">
            <a href="<?php echo $this->url('/'); ?>" class="mn-drawer-group-title<?php echo $dHomeActive ? ' mn-drawer-active' : ''; ?>"><?php echo $this->icon('home', 15); ?> <?php echo $this->t('nav.home'); ?></a>
            <?php if ($drawerHasForum): ?>
            <div class="mn-drawer-group-title<?php echo $dForumActive ? ' mn-drawer-active' : ''; ?>"><?php echo $this->icon('forum', 15); ?> <?php echo $this->t('nav.forum'); ?></div>
            <ul class="mn-drawer-sublist">
                <?php foreach ($drawerForumCats as $dCat): ?>
                <li><a href="<?php echo $this->url('/forum/category/' . (int)($dCat['id'] ?? 0)); ?>" class="<?php echo $dForumCat === (int)($dCat['id'] ?? 0) ? 'mn-drawer-active' : ''; ?>"><?php if (!empty($dCat['show_icon'])): ?><?php echo $this->icon($dCat['icon'] ?? 'forum', 13); ?> <?php endif; ?><?php echo $this->e($dCat['name'] ?? ''); ?></a></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <?php if ($drawerHasForum && $drawerHasBlog): ?>
            <div class="mn-drawer-divider"></div>
            <?php endif; ?>
            <?php if ($drawerHasBlog): ?>
            <div class="mn-drawer-group-title<?php echo $dBlogActive ? ' mn-drawer-active' : ''; ?>"><?php echo $this->icon('blog', 15); ?> <?php echo $this->t('nav.blog'); ?></div>
            <ul class="mn-drawer-sublist">
                <?php foreach ($drawerBlogCats as $dBcat): ?>
                <li><a href="<?php echo $this->url('/blog/category/' . (int)($dBcat['id'] ?? 0)); ?>" class="<?php echo $dBlogCat === (int)($dBcat['id'] ?? 0) ? 'mn-drawer-active' : ''; ?>"><?php echo $this->e($dBcat['name'] ?? ''); ?></a></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <?php \app\Helpers\Plugin::hook('nav_drawer_links'); ?>
        </div>
    </aside>
</nav>

<!-- ====== 三栏主体 ====== -->
<div class="mn-body">
    <!-- 左栏 -->
    <aside class="mn-left">
        <?php $this->include('_components/left_sidebar'); ?>
    </aside>

    <!-- 中栏 -->
    <main class="mn-center">
        <?php $this->yield('content'); ?>
        <!-- 移动端侧栏（仅 ≤768px 显示：热门标签 + 友链） -->
        <?php $this->include('_components/mobile_sidebar'); ?>
    </main>

    <!-- 右栏（站点/用户设置隐藏时省略；中栏 flex:1 自动占满） -->
    <?php if (!\app\Helpers\Theme::hideRightSidebar()): ?>
    <aside class="mn-right">
        <?php $this->include('_components/right_sidebar'); ?>
    </aside>
    <?php endif; ?>
    </div>

<!-- ====== 移动端底部导航 ====== -->
<nav class="mn-mobile-nav">
    <a href="<?php echo $this->url('/'); ?>" class="mn-mobile-nav-item<?php echo $uri === '/' || strpos($uri, '/?') === 0 ? ' mn-active' : ''; ?>">
        <?php echo $this->icon('home', 18); ?>
        <span><?php echo $this->t('nav.home'); ?></span>
    </a>
    <?php if ($showForumNav): ?>
    <a href="<?php echo $this->url('/forum'); ?>" class="mn-mobile-nav-item<?php echo strpos($uri, '/forum') === 0 || strpos($uri, '/thread') === 0 || strpos($uri, '/post') === 0 ? ' mn-active' : ''; ?>">
        <?php echo $this->icon('forum', 18); ?>
        <span><?php echo $this->t('nav.forum'); ?></span>
    </a>
    <?php endif; ?>
    <?php if ($showPostBtn): ?>
    <a href="<?php echo $this->url('/post/new'); ?>" class="mn-mobile-nav-item mn-mobile-nav-plus">
        <?php echo $this->icon('add', 22); ?>
    </a>
    <?php endif; ?>
    <?php if ($showBlogNav): ?>
    <a href="<?php echo $this->url('/blog'); ?>" class="mn-mobile-nav-item<?php echo strpos($uri, '/blog') === 0 ? ' mn-active' : ''; ?>">
        <?php echo $this->icon('blog', 18); ?>
        <span><?php echo $this->t('nav.blog'); ?></span>
    </a>
    <?php endif; ?>
    <?php if ($this->getData('isLoggedIn')): ?>
    <!-- 我的：弹出菜单（仅移动端可见） -->
    <div class="mn-mobile-nav-item mn-mobile-nav-mine"
         x-data="{ mineOpen: false }"
         x-on:click.outside="mineOpen = false"
         x-on:mn-close-mine.window="mineOpen = false"
         :class="{ 'mn-open': mineOpen }">
        <button type="button" class="mn-mobile-nav-mine-trigger" x-on:click="mineOpen = !mineOpen; $dispatch('mn-close-top')" aria-label="<?php echo $this->t('nav.profile'); ?>">
            <?php echo $this->icon('user', 18); ?>
        </button>
        <span><?php echo $this->t('nav.profile'); ?></span>
        <div class="mn-mobile-nav-mine-menu">
            <a href="<?php echo $this->url('/profile'); ?>"><?php echo $this->icon('user', 14); ?> <?php echo $this->t('nav.profile'); ?></a>
            <?php $this->include('_components/notification_bell', ['placement' => 'mobile']); ?>
            <?php if (\app\Helpers\Settings::get('message_enabled') === '1'): ?>
            <a href="<?php echo $this->url('/message'); ?>"><?php echo $this->icon('message', 14); ?> <?php echo $this->t('nav.messages'); ?><?php $uc = (int)$this->getData('unreadCount', 0); if ($uc > 0): ?> <small class="mn-text-error">(<?php echo $uc; ?>)</small><?php endif; ?></a>
            <?php endif; ?>
            <?php if ($this->getData('isAdmin')): ?>
            <a href="<?php echo $this->url('/admin'); ?>"><?php echo $this->icon('admin', 14); ?> <?php echo $this->t('nav.admin'); ?></a>
            <?php endif; ?>
            <?php \app\Helpers\Plugin::hook('nav_user_menu_items', ['placement' => 'mobile']); ?>
            <div class="mn-mobile-avatar-divider"></div>
            <form method="POST" action="<?php echo $this->url('/logout'); ?>">
                <input type="hidden" name="csrf" value="<?php echo $this->e($this->getData('csrfToken')); ?>">
                <button type="submit" class="mn-bg-none mn-border-none mn-cursor-pointer mn-flex mn-flex-center mn-gap-6 mn-p-10-14 mn-w-full mn-fs-14 mn-text-error"><?php echo $this->icon('logout', 14); ?> <?php echo $this->t('nav.logout'); ?></button>
            </form>
        </div>
    </div>
    <?php else: ?>
    <a href="<?php echo $this->url('/login'); ?>" class="mn-mobile-nav-item<?php echo strpos($uri, '/login') === 0 ? ' mn-active' : ''; ?>">
        <?php echo $this->icon('user', 18); ?>
        <span><?php echo $this->t('nav.profile'); ?></span>
    </a>
    <?php endif; ?>
</nav>

<!-- ====== 页脚 ====== -->
<footer class="mn-footer">
    <!-- 前台页脚两行：上行动态链接 + 下行版权/统计 -->
    <div class="mn-container">
        <div class="mn-flex-between mn-flex-wrap" style="row-gap:4px;">
            <div class="mn-footer-left"><?php $badgeVer = @\filemtime(__DIR__ . '/../../../flinthub-badge.svg') ?: 1; ?><img src="<?php echo $this->url('/flinthub-badge.svg?v=' . $badgeVer); ?>" alt="" class="mn-footer-badge" style="height:20px;width:138px;"></div>
            <div class="mn-footer-right mn-flex mn-flex-wrap mn-fw-600"><?php \app\Helpers\Plugin::hook('footer_links'); ?></div>
        </div>
        <div class="mn-flex-between mn-flex-wrap" style="row-gap:4px;margin-top:4px;">
            <div class="mn-footer-left"><?php echo $this->t('footer.powered_by', ['version' => FLINTHUB_VERSION]); ?></div>
            <div class="mn-footer-right mn-flex mn-gap-16 mn-flex-wrap">
                <?php
                // 服务器端渲染耗时（请求 → 视图输出前）
                $renderTime = (float)$this->getData('renderTime', 0);
                $queryCount = \app\Core\Database::getQueryCount();
                // OPcache 运行状态（服务器读取）
                $opcacheOn = false;
                if (function_exists('opcache_get_status')) {
                    $opcSt = @opcache_get_status(false);
                    $opcacheOn = is_array($opcSt) && !empty($opcSt['opcache_enabled']);
                }
                ?>
                <span><?php echo $this->t('footer.render_time'); ?> <?php echo number_format($renderTime, 4); ?>s</span>
                <span class="mn-text-muted">·</span>
                <span><?php echo $this->t('footer.query_count'); ?> <?php echo (int)$queryCount; ?> <?php echo $this->t('footer.times'); ?></span>
                <span class="mn-text-muted">·</span>
                <span><?php echo $this->t('footer.opcache'); ?>: <?php echo $opcacheOn ? 'On' : 'Off'; ?></span>
            </div>
        </div>
    </div>
</footer>

<?php else: ?>
<!-- ====== 后台页面 ====== -->
<nav class="mn-nav">
    <div class="mn-nav-inner">
        <a href="<?php echo $this->url('/'); ?>" class="mn-logo"><img src="<?php echo $this->url('/logo.svg'); ?>" alt="" width="18" height="18" style="display:block;border-radius:4px;flex-shrink:0;"><?php echo $this->e($this->getData('siteName')); ?></a>
        <div class="mn-nav-links">
            <a href="<?php echo $this->url('/admin'); ?>" class="mn-active"><?php echo $this->icon('admin', 14); ?> <?php echo $this->t('nav.admin_panel'); ?></a>
            <a href="<?php echo $this->url('/'); ?>"><?php echo $this->icon('back', 14); ?> <?php echo $this->t('nav.back_to_site'); ?></a>
        </div>
        <div class="mn-nav-right">
            <?php
            // [i18n] 后台语言切换（?lang= 切换，PRG 回原页）
            $i18nLangs = \app\Helpers\I18n::available();
            ?>
            <?php if (count($i18nLangs) >= 2): ?>
            <?php $i18nCurrent = \app\Helpers\I18n::current(); ?>
            <?php
            $i18nUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
            $i18nBase = (string)preg_replace('/([?&])lang=[^&]*&?/', '$1', $i18nUri);
            $i18nBase = rtrim($i18nBase, '?&');
            $i18nSep = (strpos($i18nBase, '?') === false) ? '?' : '&';
            ?>
            <div class="mn-nav-lang mn-dropdown-wrap" style="margin-right:8px;" x-data="{ langOpen: false }" x-on:click.outside="langOpen = false">
                <button type="button" class="mn-btn mn-btn-sm mn-border-none mn-bg-none mn-cursor-pointer mn-nav-lang-btn" style="display:inline-flex;align-items:center;padding:6px 10px;border-radius:6px;" x-on:click="langOpen = !langOpen">
                    <?php echo $this->icon('globe-asia', 14); ?>
                </button>
                <div class="mn-dropdown-panel mn-dropdown-panel-right" style="display:none;" x-show="langOpen">
                    <?php foreach ($i18nLangs as $lang): ?>
                    <?php $isCur = ($lang === $i18nCurrent); ?>
                    <a href="<?php echo $this->e($this->url($i18nBase . $i18nSep . 'lang=' . $lang)); ?>" class="mn-dropdown-item<?php echo $isCur ? ' mn-dropdown-item-active' : ''; ?>"><?php echo $this->t('lang.' . $lang); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($this->getData('isLoggedIn')): ?>
            <span class="mn-fs-13 mn-text-nav mn-px-8"><?php echo $this->e($this->getData('currentUsername')); ?></span>
            <form method="POST" action="<?php echo $this->url('/logout'); ?>" class="mn-inline">
                <input type="hidden" name="csrf" value="<?php echo $this->e($this->getData('csrfToken')); ?>">
                <button type="submit" class="mn-btn mn-btn-sm mn-border-none mn-bg-none mn-cursor-pointer mn-text-secondary mn-p-6-10"><?php echo $this->icon('logout', 14); ?> <?php echo $this->t('nav.logout'); ?></button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</nav>
<?php
// 后台警示横幅：长任务模式（验证超时 > 1800s）开启时提醒切回标准模式
if ($this->getData('isAdmin') && (int)\app\Helpers\Settings::get('admin_verify_timeout', '1800') > 1800):
?>
<div style="position:sticky;top:54px;z-index:99;background:#fef3c7;border-bottom:1px solid #fde68a;color:#92400e;padding:10px 24px;text-align:center;font-size:13px;line-height:1.6;">
    <?php echo $this->icon('warning', 14); ?> <strong><?php echo $this->t('verify_banner_title'); ?></strong>
    <?php echo $this->t('verify_banner_text'); ?>
    <a href="<?php echo $this->url('/admin/settings/system'); ?>" style="margin-left:10px;font-weight:600;color:#92400e;text-decoration:underline;"><?php echo $this->t('verify_banner_action'); ?> →</a>
</div>
<?php endif; ?>
<div class="mn-container mn-admin-body">
    <?php $this->yield('content'); ?>
</div>
<footer class="mn-footer">
    <div class="mn-container mn-flex-between mn-gap-16 mn-flex-wrap">
        <div class="mn-footer-left"><?php echo $this->t('footer.powered_by', ['version' => FLINTHUB_VERSION]); ?></div>
        <div class="mn-footer-right mn-flex mn-gap-16 mn-flex-wrap">
            <?php
            // 服务器端渲染耗时（请求 → 视图输出前）
            $renderTime = (float)$this->getData('renderTime', 0);
            $queryCount = \app\Core\Database::getQueryCount();
            // OPcache 运行状态（服务器读取）
            $opcacheOn = false;
            if (function_exists('opcache_get_status')) {
                $opcSt = @opcache_get_status(false);
                $opcacheOn = is_array($opcSt) && !empty($opcSt['opcache_enabled']);
            }
            ?>
            <span><?php echo $this->t('footer.render_time'); ?> <?php echo number_format($renderTime, 4); ?>s</span>
            <span class="mn-text-muted">·</span>
            <span><?php echo $this->t('footer.query_count'); ?> <?php echo (int)$queryCount; ?> <?php echo $this->t('footer.times'); ?></span>
            <span class="mn-text-muted">·</span>
            <span><?php echo $this->t('footer.opcache'); ?>: <?php echo $opcacheOn ? 'On' : 'Off'; ?></span>
        </div>
    </div>
</footer>
<?php endif; ?>

<script src="<?php echo $this->asset('js/auth.js'); ?>"></script>
<script src="<?php echo $this->asset('js/pow.js'); ?>"></script>
<!-- [PWA] 注册 Service Worker：静态资源离线缓存 -->
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register((window.BASE_PATH || '') + '/sw.js').catch(function (err) {
            console.warn('SW register failed:', err);
        });
    });
}
</script>
</body>
</html>