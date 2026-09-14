<?php
/**
 * 后台仪表盘视图 — 站点统计概览、操作快捷入口
 * @file app/Views/admin/index.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.nav_dashboard'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar'); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('dashboard', 16); ?> <?php echo $this->t('admin.nav_dashboard'); ?></h1>
        </div>
        <?php $stats = $this->getData('stats'); ?>
        <div class="admin-stats">
            <div class="stat-card">
                <h3><?php echo $this->t('admin.stat_total_users'); ?></h3>
                <div class="value"><?php echo (int)($stats['users'] ?? 0); ?></div>
                <div class="label"><?php echo $this->t('admin.stat_total_users_label'); ?></div>
            </div>
            <div class="stat-card success">
                <h3><?php echo $this->t('admin.stat_online'); ?></h3>
                <div class="value"><?php echo (int)($stats['onlineUsers'] ?? 0); ?></div>
                <div class="label"><?php echo $this->t('admin.stat_online_label'); ?></div>
            </div>
            <div class="stat-card warning">
                <h3><?php echo $this->t('admin.stat_threads'); ?></h3>
                <div class="value"><?php echo (int)($stats['threads'] ?? 0); ?></div>
                <div class="label"><?php echo $this->t('admin.stat_threads_label'); ?></div>
            </div>
            <div class="stat-card danger">
                <h3><?php echo $this->t('admin.stat_posts'); ?></h3>
                <div class="value"><?php echo (int)($stats['posts'] ?? 0); ?></div>
                <div class="label"><?php echo $this->t('admin.stat_posts_label'); ?></div>
            </div>
            <div class="stat-card info">
                <h3><?php echo $this->t('admin.stat_blogs'); ?></h3>
                <div class="value"><?php echo (int)($stats['blogs'] ?? 0); ?></div>
                <div class="label"><?php echo $this->t('admin.stat_blogs_label'); ?></div>
            </div>
            <div class="stat-card violet">
                <h3><?php echo $this->t('admin.stat_blog_comments'); ?></h3>
                <div class="value"><?php echo (int)($stats['blogComments'] ?? 0); ?></div>
                <div class="label"><?php echo $this->t('admin.stat_blog_comments_label'); ?></div>
            </div>
            <div class="stat-card success">
                <h3><?php echo $this->t('admin.stat_plugins_installed'); ?></h3>
                <div class="value"><?php echo (int)($stats['pluginsInstalled'] ?? 0); ?></div>
                <div class="label"><?php echo $this->t('admin.stat_plugins_installed_label'); ?></div>
            </div>
            <div class="stat-card warning">
                <h3><?php echo $this->t('admin.stat_plugins_active'); ?></h3>
                <div class="value"><?php echo (int)($stats['pluginsActive'] ?? 0); ?></div>
                <div class="label"><?php echo $this->t('admin.stat_plugins_active_label'); ?></div>
            </div>
        </div>
        <?php $env = $this->getData('env', []); ?>
        <?php if (!empty($env)): ?>
        <div class="admin-section">
            <h3 class="admin-section-title"><?php echo $this->t('admin.env_info'); ?></h3>
            <div class="admin-grid-2 env-list">
                <?php foreach ($env as $item): ?>
                <div class="env-item">
                    <span class="env-label"><?php echo $this->e($item['label']); ?></span>
                    <span class="env-value"><?php echo $this->e($item['value']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php $latestUsers = $this->getData('latestUsers', []); ?>
        <?php if (!empty($latestUsers)): ?>
        <div class="admin-section">
            <h3 class="admin-section-title"><?php echo $this->t('admin.latest_users'); ?></h3>
            <!-- 桌面：表格 -->
            <table class="admin-table admin-table-desktop">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th><?php echo $this->t('auth.username'); ?></th>
                        <th><?php echo $this->t('auth.email'); ?></th>
                        <th><?php echo $this->t('admin.role'); ?></th>
                        <th><?php echo $this->t('admin.reg_time'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($latestUsers as $u): ?>
                    <tr>
                        <td><?php echo (int)$u['id']; ?></td>
                        <td><?php echo $this->e($u['username']); ?></td>
                        <td><?php echo $this->e($u['email']); ?></td>
                        <td><?php echo $this->e($u['role'] ?? 'user'); ?></td>
                        <td><?php echo $this->e($u['created_at'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <!-- 移动端：小卡片 -->
            <div class="admin-cards admin-cards-mobile">
                <?php foreach ($latestUsers as $u): ?>
                <div class="mini-card">
                    <div class="mini-card-title"><?php echo $this->e($u['username']); ?></div>
                    <div class="mini-card-meta">
                        <span>ID <?php echo (int)$u['id']; ?></span>
                        <span><?php echo $this->e($u['role'] ?? 'user'); ?></span>
                        <span><?php echo $this->e($u['created_at'] ?? ''); ?></span>
                    </div>
                    <div class="mini-card-sub"><?php echo $this->e($u['email']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php $latestThreads = $this->getData('latestThreads', []); ?>
        <?php if (!empty($latestThreads)): ?>
        <div class="admin-section">
            <h3 class="admin-section-title"><?php echo $this->t('admin.latest_threads'); ?></h3>
            <!-- 桌面：表格 -->
            <table class="admin-table admin-table-desktop">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th><?php echo $this->t('common.title'); ?></th>
                        <th><?php echo $this->t('admin.author'); ?></th>
                        <th><?php echo $this->t('common.category'); ?></th>
                        <th><?php echo $this->t('admin.reply_short'); ?></th>
                        <th><?php echo $this->t('admin.view_short'); ?></th>
                        <th><?php echo $this->t('admin.time'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($latestThreads as $t): ?>
                    <tr>
                        <td><?php echo (int)$t['id']; ?></td>
                        <td><a href="<?php echo $this->url('/thread/' . (int)$t['id']); ?>"><?php echo $this->e(mb_substr($t['title'] ?? '', 0, 40)); ?></a></td>
                        <td><?php echo $this->e($t['username'] ?? ''); ?></td>
                        <td><?php echo $this->e($t['category_name'] ?? ''); ?></td>
                        <td><?php echo (int)($t['reply_count'] ?? 0); ?></td>
                        <td><?php echo (int)($t['view_count'] ?? 0); ?></td>
                        <td><?php echo $this->e($t['created_at'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <!-- 移动端：小卡片 -->
            <div class="admin-cards admin-cards-mobile">
                <?php foreach ($latestThreads as $t): ?>
                <div class="mini-card">
                    <div class="mini-card-title"><a href="<?php echo $this->url('/thread/' . (int)$t['id']); ?>"><?php echo $this->e($t['title'] ?? ''); ?></a></div>
                    <div class="mini-card-meta">
                        <span><?php echo $this->e($t['username'] ?? ''); ?></span>
                        <span><?php echo $this->e($t['category_name'] ?? ''); ?></span>
                    </div>
                    <div class="mini-card-sub">
                        <span><?php echo $this->t('admin.reply_short'); ?> <?php echo (int)($t['reply_count'] ?? 0); ?></span>
                        <span><?php echo $this->t('admin.view_short'); ?> <?php echo (int)($t['view_count'] ?? 0); ?></span>
                        <span><?php echo $this->e($t['created_at'] ?? ''); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <div class="c-999 mn-fs-12 mn-mt-10">
            <?php echo $this->t('admin.database_info'); ?>: SplitDB（SQLite 分片）
            <?php if (isset($stats['dbSize'])): ?> | <?php echo $this->t('admin.db_size'); ?>: <?php echo round($stats['dbSize'] / 1024, 1); ?>KB<?php endif; ?>
        </div>
    </main>
</div>
<?php $this->endSection(); ?>