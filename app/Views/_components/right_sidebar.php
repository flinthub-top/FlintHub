<?php
/**
 * Modern 主题 — 右栏组件
 * 卡片式面板：用户、统计、标签、友链
 */
$isLoggedIn = $this->getData('isLoggedIn');
$currentUser = $this->getData('currentUser', []);
$totalUsers = (int)$this->getData('totalUsers', 0);
$totalThreads = (int)$this->getData('totalThreads', 0);
$totalPosts = (int)$this->getData('totalPosts', 0);   // 回复数（HomeController 传入）
$totalBlogs = (int)$this->getData('totalBlogs', 0);
$blogComments = (int)$this->getData('blogComments', 0); // 博客评论数（HomeController 传入）
$onlineCount = (int)$this->getData('onlineCount', 0);
$tags = $this->getData('tags', []);
$currentUri = $_SERVER['REQUEST_URI'] ?? '/';
$isHome = $currentUri === '/' || preg_match('#^\?preview_theme#', $currentUri) || preg_match('#^/\?preview_theme#', $currentUri);
// 页面类型判断：论坛/分类/博客页右侧栏也显示站点统计
$isForumPage = $currentUri === '/forum';
$isForumCategory = strpos($currentUri, '/forum/category/') === 0;
$isBlogPage = strpos($currentUri, '/blog') === 0;
// 站点统计显示范围：首页 / 论坛列表 / 分类页 / 博客页
$showSiteStats = $isHome || $isForumPage || $isForumCategory || $isBlogPage;
// 友情链接显示范围：首页 / 论坛列表 / 博客页（分类页不显示）
$showFriendLinks = $isHome || $isForumPage || $isBlogPage;
// /tags 列表页本身即标签云，不再重复显示热门标签；/tag/{id} 详情页正常展示
$isTagsListPage = $currentUri === '/tags';
// 实时统计主题数（与资料页 getStats 同口径：COUNT threads WHERE deleted_at IS NULL）
$userThreadCount = null;
$userBlogCount = null;
if ($isLoggedIn && !empty($currentUser['id'])) {
    $stats = (new \app\Models\User())->getStats((int)$currentUser['id']);
    $userThreadCount = (int)($stats['total_threads'] ?? 0);
    $userBlogCount = (int)($stats['total_blogs'] ?? 0);
}
// 帖子/博客详情页数据（存在则渲染发帖人信息栏 + 主题卡片，替代默认用户面板）
$thread = $this->getData('thread');
$blog = $this->getData('blog');
$detailItem = $thread ?: $blog;              // 帖子或博客详情数据
$isThreadDetail = !empty($thread);           // 区分 /thread/ 与 /blog/ 链接
?>
<?php if (!empty($detailItem)): ?>
<!-- 发帖人信息栏（帖子/博客详情页） -->
<div class="mn-panel">
    <h3 class="mn-panel-title"><?php echo $this->icon('user', 12); ?> <?php echo $this->t('sidebar.author'); ?></h3>
    <?php
    $authorId = (int)($detailItem['user_id'] ?? 0);
    $authorName = $detailItem['username'] ?? '';
    $authorAvatar = $detailItem['avatar'] ?? '';
    $authorLevel = (int)($detailItem['level'] ?? 1);
    $authorCfg = \app\Helpers\Points::getLevelConfig($authorLevel);
    // 发帖/回复/粉丝/关注统计（依赖 user_profile 插件，未激活时归零）
    $authorStats = \app\Helpers\Plugin::isActivated('user_profile')
        ? \Plugin\UserProfile\Plugin::getStats($authorId)
        : ['total_threads' => 0, 'total_replies' => 0];
    $authorFollows = \app\Helpers\Plugin::isActivated('user_profile')
        ? \Plugin\UserProfile\Plugin::getFollowCounts($authorId)
        : ['followers' => 0, 'following' => 0];
    ?>
    <div class="mn-user-card">
        <div class="mn-flex-center mn-gap-10">
            <?php if (!empty($authorAvatar)): ?>
                <img src="<?php echo $this->e(\UPLOAD_URL . $authorAvatar); ?>" alt="" class="mn-user-avatar">
            <?php else: ?>
                <div class="mn-user-avatar-placeholder"><?php echo $this->e(mb_substr($authorName, 0, 1)); ?></div>
            <?php endif; ?>
            <div>
                <div class="mn-user-name">
                    <a href="<?php echo $this->url('/u/' . $authorId); ?>" class="mn-link-inherit"><?php echo $this->e($authorName); ?></a>
                    <?php $wearingMedals = $this->getData('wearingMedals', []); echo $wearingMedals[$authorId] ?? ''; ?>
                </div>
                <div class="mn-user-level">
                    <span class="mn-level-badge" style="background:<?php echo $this->e($authorCfg['color'] ?? '#999'); ?>;"><?php echo $this->e($authorCfg['icon'] ?? ''); ?> Lv.<?php echo $authorLevel; ?> <?php echo $this->e($authorCfg['title'] ?? $this->t('profile.level_newbie')); ?></span>
                </div>
            </div>
        </div>
        <div class="mn-user-stats">
            <div class="mn-user-stat">
                <div class="mn-user-stat-value"><?php echo (int)($authorStats['total_threads'] ?? 0); ?></div>
                <div class="mn-user-stat-label"><?php echo $this->t('profile.stat_threads'); ?></div>
            </div>
            <div class="mn-user-stat">
                <div class="mn-user-stat-value"><?php echo (int)($authorStats['total_replies'] ?? 0); ?></div>
                <div class="mn-user-stat-label"><?php echo $this->t('profile.stat_replies'); ?></div>
            </div>
            <div class="mn-user-stat">
                <div class="mn-user-stat-value"><?php echo (int)($authorFollows['followers'] ?? 0); ?></div>
                <div class="mn-user-stat-label"><?php echo $this->t('profile.stat_followers'); ?></div>
            </div>
            <div class="mn-user-stat">
                <div class="mn-user-stat-value"><?php echo (int)($authorFollows['following'] ?? 0); ?></div>
                <div class="mn-user-stat-label"><?php echo $this->t('profile.stat_following'); ?></div>
            </div>
        </div>
        <div class="mn-user-actions">
            <a href="<?php echo $this->url('/u/' . $authorId); ?>" class="mn-btn mn-btn-sm mn-flex-1 mn-text-center"><?php echo $this->icon('user', 12); ?> <?php echo $this->t('profile.homepage'); ?></a>
            <a href="<?php echo $this->url('/post/new'); ?>" class="mn-btn mn-btn-sm mn-btn-primary mn-flex-1 mn-text-center"><?php echo $this->icon('new-post', 12); ?> <?php echo $this->t('forum.new_thread'); ?></a>
        </div>
    </div>
</div>

<!-- 主题卡片（帖子/博客详情页） -->
<?php
$detailId = (int)($detailItem['id'] ?? 0);
$detailTitle = $detailItem['title'] ?? '';
$detailUrl = rtrim(SITE_URL, '/') . $this->url(($isThreadDetail ? '/thread/' : '/blog/') . $detailId);
?>
<div class="mn-panel">
    <h3 class="mn-panel-title"><?php echo $this->icon('link', 12); ?> <?php echo $this->t('sidebar.topic'); ?></h3>
    <div class="mn-thread-card">
        <div class="mn-thread-card-head">
            <span class="mn-thread-card-title" title="<?php echo $this->e($detailTitle); ?>"><?php echo $this->e($detailTitle); ?></span>
            <button type="button" class="mn-thread-card-copy" onclick="copyThreadUrl(this)" title="<?php echo $this->t('sidebar.copy_link'); ?>"><?php echo $this->icon('copy', 13); ?></button>
        </div>
        <div class="mn-thread-card-url"><?php echo $this->e($detailUrl); ?></div>
        <div class="mn-thread-card-body">
            <div id="threadQrcode" class="mn-thread-card-qr"></div>
        </div>
    </div>
</div>
<script src="<?php echo $this->asset('lib/qrcode.min.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var qrEl = document.getElementById('threadQrcode');
    if (qrEl && typeof QRCode !== 'undefined') {
        new QRCode(qrEl, { text: <?php echo json_encode($detailUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>, width: 132, height: 132 });
    }
});
function copyThreadUrl(btn) {
    var url = <?php echo json_encode($detailUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function() {
            btn.classList.add('mn-copied');
            setTimeout(function() { btn.classList.remove('mn-copied'); }, 1200);
        }).catch(function() { fallbackCopyUrl(url, btn); });
    } else {
        fallbackCopyUrl(url, btn);
    }
}
function fallbackCopyUrl(url, btn) {
    var ta = document.createElement('textarea');
    ta.value = url;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); btn.classList.add('mn-copied'); setTimeout(function() { btn.classList.remove('mn-copied'); }, 1200); } catch (e) {}
    document.body.removeChild(ta);
}
</script>
<?php else: ?>
<!-- 用户面板（其他页面） -->
<div class="mn-panel">
    <h3 class="mn-panel-title"><?php echo $this->icon('user', 12); ?> <?php echo $this->t('sidebar.user'); ?></h3>
    <?php if ($isLoggedIn && $currentUser): ?>
    <div class="mn-user-card">
        <div class="mn-flex-center mn-gap-10">
            <?php if (!empty($currentUser['avatar'])): ?>
                <img src="<?php echo $this->e(\UPLOAD_URL . $currentUser['avatar']); ?>" alt="" class="mn-user-avatar">
            <?php else: ?>
                <div class="mn-user-avatar-placeholder"><?php echo $this->e(mb_substr($currentUser['username'] ?? '', 0, 1)); ?></div>
            <?php endif; ?>
            <div>
                <div class="mn-user-name"><?php echo $this->e($currentUser['username'] ?? ''); ?></div>
                <div class="mn-user-level"><?php echo $this->icon('calendar', 11); ?> <?php echo $this->t('profile.joined'); ?> <?php echo !empty($currentUser['created_at']) ? date('Y-m-d', strtotime($currentUser['created_at'])) : ''; ?></div>
            </div>
        </div>
        <div class="mn-user-stats">
            <div class="mn-user-stat">
                <div class="mn-user-stat-value"><?php echo (int)($currentUser['points'] ?? 0); ?></div>
                <div class="mn-user-stat-label"><?php echo $this->t('profile.points'); ?></div>
            </div>
            <div class="mn-user-stat">
                <div class="mn-user-stat-value"><?php echo $isBlogPage ? (int)$userBlogCount : (int)($userThreadCount ?? $currentUser['post_count'] ?? 0); ?></div>
                <div class="mn-user-stat-label"><?php echo $this->t($isBlogPage ? 'profile.stat_blogs' : 'profile.stat_threads'); ?></div>
            </div>
        </div>
        <div class="mn-user-actions">
            <a href="<?php echo $this->url('/profile'); ?>" class="mn-btn mn-btn-sm mn-flex-1 mn-text-center"><?php echo $this->icon('user', 12); ?> <?php echo $this->t('profile.title'); ?></a>
            <?php if ($isBlogPage): ?>
            <a href="<?php echo $this->url('/blog/new'); ?>" class="mn-btn mn-btn-sm mn-btn-primary mn-flex-1 mn-text-center"><?php echo $this->icon('write', 12); ?> <?php echo $this->t('blog.new'); ?></a>
            <?php else: ?>
            <?php $this->include('plugins/daily_checkin/_sidebar'); ?>
            <?php endif; ?>
        </div>
        <?php if (!$isBlogPage): ?>
        <!-- 签到提示：与用户信息同卡，位于签到按钮下方 -->
        <div id="checkinHint" class="mn-border-t mn-mt-12 mn-pt-10 mn-fs-12 mn-text-muted mn-text-center">
            <?php if ($this->getData('checkin_today')): ?>
            <?php echo \app\Helpers\I18n::get('plugin.daily_checkin.msg_success_persist', ['points' => (int)$this->getData('checkin_today_points'), 'consecutive' => (int)$this->getData('checkin_consecutive')]); ?>
            <?php else: ?>
            <?php echo \app\Helpers\I18n::get('plugin.daily_checkin.title'); ?> · <?php echo \app\Helpers\I18n::get('plugin.daily_checkin.subtitle'); ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="mn-text-center mn-py-6">
        <p class="mn-text-muted mn-fs-13 mn-mb-12"><?php echo $this->t('sidebar.login_hint'); ?></p>
        <div class="mn-flex mn-gap-8">
            <a href="<?php echo $this->url('/login'); ?>" class="mn-btn mn-btn-primary mn-flex-1 mn-text-center"><?php echo $this->icon('login', 12); ?> <?php echo $this->t('auth.login'); ?></a>
            <a href="<?php echo $this->url('/register'); ?>" class="mn-btn mn-flex-1 mn-text-center"><?php echo $this->icon('register', 12); ?> <?php echo $this->t('auth.register'); ?></a>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
// 右栏通用钩子：用户信息面板之后，供插件注入侧栏面板
\app\Helpers\Plugin::hook('right_sidebar_after');
?>

<?php if ($showSiteStats): ?>
<!-- 站点统计 -->
<div class="mn-panel">
    <h3 class="mn-panel-title"><?php echo $this->icon('dashboard', 12); ?> <?php echo $this->t('sidebar.site_stats'); ?></h3>
    <div class="mn-stat-list">
        <div class="mn-stat-item">
            <span class="mn-stat-label"><?php echo $this->icon('users', 12); ?> <?php echo $this->t('sidebar.stat_users'); ?></span>
            <span class="mn-stat-value"><?php echo $totalUsers; ?></span>
        </div>
        <?php if ($isHome || $isForumPage || $isForumCategory): ?>
        <div class="mn-stat-item">
            <span class="mn-stat-label"><?php echo $this->icon('forum', 12); ?> <?php echo $this->t('sidebar.stat_threads_posts'); ?></span>
            <span class="mn-stat-value"><?php echo $totalThreads; ?> / <?php echo $totalPosts; ?></span>
        </div>
        <?php endif; ?>
        <?php if ($isHome || $isBlogPage): ?>
        <div class="mn-stat-item">
            <span class="mn-stat-label"><?php echo $this->icon('blog', 12); ?> <?php echo $this->t('sidebar.stat_blog_comments'); ?></span>
            <span class="mn-stat-value"><?php echo $totalBlogs; ?> / <?php echo $blogComments; ?></span>
        </div>
        <?php endif; ?>
        <div class="mn-stat-item">
            <span class="mn-stat-label"><?php echo $this->icon('user', 12); ?> <?php echo $this->t('sidebar.stat_online'); ?></span>
            <span class="mn-stat-value"><?php echo $onlineCount; ?></span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 热门标签 -->
<?php if (!empty($tags) && !$isTagsListPage): ?>
<div class="mn-panel">
    <h3 class="mn-panel-title"><a href="<?php echo $this->url('/tags'); ?>" class="mn-link-inherit"><?php echo $this->icon('tags', 12); ?> <?php echo $this->t('sidebar.hot_tags'); ?></a></h3>
    <div class="mn-tag-cloud">
        <?php
        $tagMax = 1;
        foreach ($tags as $t) { $c = (int)($t['count'] ?? 0); if ($c > $tagMax) $tagMax = $c; }
        foreach ($tags as $tag):
            $count = (int)($tag['count'] ?? 0);
            $size = 0.8 + ($count / max($tagMax, 1)) * 0.7;
        ?>
        <a href="<?php echo $this->url('/tag/' . (int)($tag['id'] ?? 0)); ?>" style="font-size:<?php echo $size; ?>em;"><?php echo $this->e($tag['name'] ?? ''); ?> <small style="font-size:0.7em;opacity:0.6;">(<?php echo $count; ?>)</small></a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($showFriendLinks): ?>
<!-- 友情链接 -->
<div class="mn-panel">
    <h3 class="mn-panel-title"><?php echo $this->icon('link', 12); ?> <?php echo $this->t('sidebar.friend_links'); ?></h3>
    <div class="mn-friend-links">
<?php
\app\Helpers\Plugin::hook('home_sidebar_bottom');
?>
    </div>
</div>
<?php endif; ?>