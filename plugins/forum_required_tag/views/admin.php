<?php
/**
 * 版块强制 Tag 插件 — 后台管理视图（3 Tab：强制 Tag / 标题前缀 / 使用记录）
 * @file plugins/forum_required_tag/views/admin.php
 */
$categories = $this->getData('categories', []);
$forumId = (int)$this->getData('forumId');
$tags = $this->getData('tags', []);
$prefixes = $this->getData('prefixes', []);
$records = $this->getData('records', []);
$recordTotal = (int)$this->getData('recordTotal');
$recordForum = (int)$this->getData('recordForum');
$page = (int)$this->getData('page', 1);
$totalPages = (int)$this->getData('totalPages', 1);
$msg = $this->getData('msg', '');
$csrfToken = $this->e($this->getData('csrfToken'));
$bp = \defined('BASE_PATH') ? BASE_PATH : '';
$cssVer = @filemtime(__DIR__ . '/../assets/style.css') ?: 1;
$jsVer = @filemtime(__DIR__ . '/../assets/script.js') ?: 1;
?>
<?php $this->section('title'); ?><?php echo $this->t('plugin.forum_required_tag.admin_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('css'); ?>
<link rel="stylesheet" href="<?php echo $bp; ?>/plugins/forum_required_tag/assets/style.css?v=<?php echo $cssVer; ?>">
<?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'forum-required-tag']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('tags', 16); ?> <?php echo $this->t('plugin.forum_required_tag.admin_title'); ?></h1>
        </div>
        <?php if ($msg): ?><div class="alert alert-success"><?php echo $this->e($msg); ?></div><?php endif; ?>

        <div class="admin-section admin-form-lg">
            <div class="admin-tabs frt-tabs" role="tablist">
                <button type="button" class="admin-tab-btn active" data-tab="tab-tags" role="tab"><?php echo $this->t('plugin.forum_required_tag.tab_tags'); ?></button>
                <button type="button" class="admin-tab-btn" data-tab="tab-prefixes" role="tab"><?php echo $this->t('plugin.forum_required_tag.tab_prefixes'); ?></button>
                <button type="button" class="admin-tab-btn" data-tab="tab-records" role="tab"><?php echo $this->t('plugin.forum_required_tag.tab_records'); ?></button>
            </div>

            <!-- ===== Tab 1：强制 Tag 管理 ===== -->
            <div class="admin-tab-pane active" id="tab-tags" role="tabpanel">
                <p class="tab-pane-desc"><?php echo $this->t('plugin.forum_required_tag.tab_tags_desc'); ?></p>

                <div class="form-group frt-forum-select">
                    <label><?php echo $this->t('plugin.forum_required_tag.select_forum'); ?></label>
                    <select id="frt-forum-tags" class="form-input" data-frt-redirect="<?php echo $bp; ?>/admin/forum-required-tag?tab=tags&forum_id=">
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo (int)$cat['id'] === $forumId ? 'selected' : ''; ?>><?php echo $this->e($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <form method="POST" action="<?php echo $bp; ?>/admin/forum-required-tag/add-tag" class="frt-inline-form">
                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="forum_id" value="<?php echo $forumId; ?>">
                    <input type="text" name="tag_name" required maxlength="30" placeholder="<?php echo $this->t('plugin.forum_required_tag.tag_placeholder'); ?>" class="form-input frt-input">
                    <button type="submit" class="btn btn-primary"><?php echo $this->t('plugin.forum_required_tag.add_btn'); ?></button>
                </form>

                <table class="frt-table">
                    <thead>
                        <tr>
                            <th><?php echo $this->t('plugin.forum_required_tag.th_name'); ?></th>
                            <th><?php echo $this->t('plugin.forum_required_tag.th_status'); ?></th>
                            <th><?php echo $this->t('plugin.forum_required_tag.th_actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tags)): ?>
                        <tr><td colspan="3" class="frt-empty"><?php echo $this->t('plugin.forum_required_tag.empty_tags'); ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($tags as $t): ?>
                        <tr>
                            <td><?php echo $this->e($t['tag_name']); ?></td>
                            <td>
                                <form method="POST" action="<?php echo $bp; ?>/admin/forum-required-tag/toggle" class="inline-block">
                                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="table" value="frt_forum_tags">
                                    <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                                    <input type="hidden" name="forum_id" value="<?php echo $forumId; ?>">
                                    <button type="submit" class="frt-toggle <?php echo (int)$t['enabled'] === 1 ? 'on' : 'off'; ?>">
                                        <?php echo $this->icon((int)$t['enabled'] === 1 ? 'eye' : 'eye-slash', 13); ?>
                                        <?php echo (int)$t['enabled'] === 1 ? $this->t('plugin.forum_required_tag.on') : $this->t('plugin.forum_required_tag.off'); ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <form method="POST" action="<?php echo $bp; ?>/admin/forum-required-tag/delete" class="inline-block">
                                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="table" value="frt_forum_tags">
                                    <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                                    <input type="hidden" name="forum_id" value="<?php echo $forumId; ?>">
                                    <button type="submit" class="btn-delete frt-del" data-confirm="<?php echo $this->e($this->t('plugin.forum_required_tag.delete_confirm')); ?>"><?php echo $this->icon('trash', 13); ?></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- 一键复制配置（从版块 → 到版块，均可选） -->
                <form method="POST" action="<?php echo $bp; ?>/admin/forum-required-tag/copy" class="frt-copy-form">
                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                    <label><?php echo $this->t('plugin.forum_required_tag.copy_from_label'); ?></label>
                    <select name="from_forum" class="form-input frt-input">
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo (int)$cat['id'] === $forumId ? 'selected' : ''; ?>><?php echo $this->e($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label><?php echo $this->t('plugin.forum_required_tag.copy_to_label'); ?></label>
                    <select name="to_forum" class="form-input frt-input">
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo (int)$cat['id'] !== $forumId && !isset($copyToSet) ? 'selected' : ''; ?><?php $copyToSet = true; ?>><?php echo $this->e($cat['name']); ?></option>
                        <?php endforeach; ?>
                        <?php unset($copyToSet); ?>
                    </select>
                    <button type="submit" class="btn btn-secondary"><?php echo $this->icon('copy', 13); ?> <?php echo $this->t('plugin.forum_required_tag.copy_btn'); ?></button>
                </form>
            </div>

            <!-- ===== Tab 2：标题前缀管理 ===== -->
            <div class="admin-tab-pane" id="tab-prefixes" role="tabpanel">
                <p class="tab-pane-desc"><?php echo $this->t('plugin.forum_required_tag.tab_prefixes_desc'); ?></p>

                <div class="form-group frt-forum-select">
                    <label><?php echo $this->t('plugin.forum_required_tag.select_forum'); ?></label>
                    <select id="frt-forum-prefixes" class="form-input" data-frt-redirect="<?php echo $bp; ?>/admin/forum-required-tag?tab=prefixes&forum_id=">
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo (int)$cat['id'] === $forumId ? 'selected' : ''; ?>><?php echo $this->e($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <form method="POST" action="<?php echo $bp; ?>/admin/forum-required-tag/add-prefix" class="frt-inline-form">
                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="forum_id" value="<?php echo $forumId; ?>">
                    <input type="text" name="prefix" required maxlength="20" placeholder="<?php echo $this->t('plugin.forum_required_tag.prefix_placeholder'); ?>" class="form-input frt-input">
                    <button type="submit" class="btn btn-primary"><?php echo $this->t('plugin.forum_required_tag.add_btn'); ?></button>
                </form>

                <table class="frt-table">
                    <thead>
                        <tr>
                            <th><?php echo $this->t('plugin.forum_required_tag.th_prefix'); ?></th>
                            <th><?php echo $this->t('plugin.forum_required_tag.th_status'); ?></th>
                            <th><?php echo $this->t('plugin.forum_required_tag.th_actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($prefixes)): ?>
                        <tr><td colspan="3" class="frt-empty"><?php echo $this->t('plugin.forum_required_tag.empty_prefixes'); ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($prefixes as $p): ?>
                        <tr>
                            <td>[<?php echo $this->e($p['prefix']); ?>]</td>
                            <td>
                                <form method="POST" action="<?php echo $bp; ?>/admin/forum-required-tag/toggle" class="inline-block">
                                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="table" value="frt_forum_prefixes">
                                    <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                    <input type="hidden" name="forum_id" value="<?php echo $forumId; ?>">
                                    <button type="submit" class="frt-toggle <?php echo (int)$p['enabled'] === 1 ? 'on' : 'off'; ?>">
                                        <?php echo $this->icon((int)$p['enabled'] === 1 ? 'eye' : 'eye-slash', 13); ?>
                                        <?php echo (int)$p['enabled'] === 1 ? $this->t('plugin.forum_required_tag.on') : $this->t('plugin.forum_required_tag.off'); ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <form method="POST" action="<?php echo $bp; ?>/admin/forum-required-tag/delete" class="inline-block">
                                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="table" value="frt_forum_prefixes">
                                    <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                    <input type="hidden" name="forum_id" value="<?php echo $forumId; ?>">
                                    <button type="submit" class="btn-delete frt-del" data-confirm="<?php echo $this->e($this->t('plugin.forum_required_tag.delete_confirm')); ?>"><?php echo $this->icon('trash', 13); ?></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- 一键复制配置（从版块 → 到版块，均可选） -->
                <form method="POST" action="<?php echo $bp; ?>/admin/forum-required-tag/copy" class="frt-copy-form">
                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                    <label><?php echo $this->t('plugin.forum_required_tag.copy_from_label'); ?></label>
                    <select name="from_forum" class="form-input frt-input">
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo (int)$cat['id'] === $forumId ? 'selected' : ''; ?>><?php echo $this->e($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label><?php echo $this->t('plugin.forum_required_tag.copy_to_label'); ?></label>
                    <select name="to_forum" class="form-input frt-input">
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo (int)$cat['id'] !== $forumId && !isset($copyToSet) ? 'selected' : ''; ?><?php $copyToSet = true; ?>><?php echo $this->e($cat['name']); ?></option>
                        <?php endforeach; ?>
                        <?php unset($copyToSet); ?>
                    </select>
                    <button type="submit" class="btn btn-secondary"><?php echo $this->icon('copy', 13); ?> <?php echo $this->t('plugin.forum_required_tag.copy_btn'); ?></button>
                </form>
            </div>

            <!-- ===== Tab 3：使用记录 ===== -->
            <div class="admin-tab-pane" id="tab-records" role="tabpanel">
                <p class="tab-pane-desc"><?php echo $this->t('plugin.forum_required_tag.tab_records_desc'); ?></p>

                <form method="GET" action="<?php echo $bp; ?>/admin/forum-required-tag" class="frt-inline-form">
                    <input type="hidden" name="tab" value="records">
                    <select name="record_forum" class="form-input frt-input">
                        <option value="0"><?php echo $this->t('plugin.forum_required_tag.all_forums'); ?></option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo (int)$cat['id'] === $recordForum ? 'selected' : ''; ?>><?php echo $this->e($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-secondary"><?php echo $this->t('plugin.forum_required_tag.filter_btn'); ?></button>
                </form>

                <table class="frt-table">
                    <thead>
                        <tr>
                            <th><?php echo $this->t('plugin.forum_required_tag.th_thread'); ?></th>
                            <th><?php echo $this->t('plugin.forum_required_tag.th_tag'); ?></th>
                            <th><?php echo $this->t('plugin.forum_required_tag.th_prefix'); ?></th>
                            <th><?php echo $this->t('plugin.forum_required_tag.th_user'); ?></th>
                            <th><?php echo $this->t('plugin.forum_required_tag.th_time'); ?></th>
                            <th><?php echo $this->t('plugin.forum_required_tag.th_actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($records)): ?>
                        <tr><td colspan="6" class="frt-empty"><?php echo $this->t('plugin.forum_required_tag.empty_records'); ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($records as $r): ?>
                        <tr>
                            <td><a href="<?php echo $bp; ?>/thread/<?php echo (int)$r['thread_id']; ?>">#<?php echo (int)$r['thread_id']; ?></a></td>
                            <td><?php echo $this->e($r['tag_name'] !== '' ? $r['tag_name'] : '-'); ?></td>
                            <td><?php echo $r['prefix'] !== '' ? '[' . $this->e($r['prefix']) . ']' : '-'; ?></td>
                            <td><?php echo $this->e($r['username']); ?></td>
                            <td><?php echo $this->e($r['created_at']); ?></td>
                            <td>
                                <form method="POST" action="<?php echo $bp; ?>/admin/forum-required-tag/delete-record" class="inline-block">
                                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                    <input type="hidden" name="record_forum" value="<?php echo $recordForum; ?>">
                                    <button type="submit" class="btn-delete frt-del" data-confirm="<?php echo $this->e($this->t('plugin.forum_required_tag.delete_confirm')); ?>"><?php echo $this->icon('trash', 13); ?></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <p class="mn-fs-12 c-999"><?php echo $this->t('plugin.forum_required_tag.record_count', ['total' => $recordTotal]); ?></p>
                <?php if ($totalPages > 1): ?>
                <div class="frt-pagination">
                    <?php if ($page > 1): ?>
                    <a class="btn btn-secondary btn-sm" href="<?php echo $bp; ?>/admin/forum-required-tag?tab=records&record_forum=<?php echo $recordForum; ?>&page=<?php echo $page - 1; ?>">&laquo;</a>
                    <?php endif; ?>
                    <span class="mn-fs-13 c-666"><?php echo $page; ?> / <?php echo $totalPages; ?></span>
                    <?php if ($page < $totalPages): ?>
                    <a class="btn btn-secondary btn-sm" href="<?php echo $bp; ?>/admin/forum-required-tag?tab=records&record_forum=<?php echo $recordForum; ?>&page=<?php echo $page + 1; ?>">&raquo;</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<script src="<?php echo $bp; ?>/plugins/forum_required_tag/assets/script.js?v=<?php echo $jsVer; ?>"></script>
<?php $this->endSection(); ?>
