<?php
/**
 * 社区治理插件 — 「审核封禁」面板（admin_index 内嵌片段，含手动封禁 + 封禁名单 + 举报列表）
 * @file plugins/mod_system/views/_reports_panel.php
 * @package Plugin\ModSystem
 */
use app\Helpers\I18n as _T;

$I      = function (string $k, array $p = []) { return _T::get($k, $p); };
$items      = $this->getData('items', []);
$csrfToken  = $this->e($this->getData('csrfToken'));
$status     = (int)$this->getData('status', -1);
$page       = (int)$this->getData('page', 1);
$totalPages = (int)$this->getData('total_pages', 1);
$success    = (string)$this->getData('success', '');
$banItems   = $this->getData('ban_items', []);
$defaultBanDays = (int)$this->getData('default_ban_days', 7);

$targetUrl = function (string $type, int $id): string {
    $b = \defined('BASE_PATH') ? \BASE_PATH : '';
    switch ($type) {
        case 'thread':        return $b . '/thread/' . $id;
        case 'post':          return $b . '/forum';
        case 'blog':          return $b . '/blog/' . $id;
        case 'blog_comment':  return $b . '/blog/' . $id;
        case 'user':          return $b . '/profile/' . $id;
    }
    return $b . '/forum';
};

$filterTabs = [
    -1 => 'filter_all',
    0  => 'filter_pending',
    1  => 'filter_handled',
    2  => 'filter_dismissed',
];
?>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?php echo $this->e($success); ?></div>
<?php endif; ?>

<!-- 小黑屋：手动封禁 -->
<div class="card mn-p-15 mt-10">
    <div class="mb-10 mn-fs-14 mn-fw-500"><?php echo $I('plugin.mod_system.add_ban'); ?> <span class="mn-fs-12 fw-400 c-999"><?php echo $I('plugin.mod_system.manual_ban_tip'); ?></span></div>
    <form method="POST" action="<?php echo $this->url('/admin/mod-system/bans/create'); ?>" class="mn-flex mn-gap-10 items-center mn-flex-wrap">
        <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
        <input type="text" name="username" class="form-input w-180" required placeholder="<?php echo $I('plugin.mod_system.ban_username_placeholder'); ?>">
        <input type="text" name="reason" class="form-input w-220" placeholder="<?php echo $I('plugin.mod_system.ban_reason_placeholder'); ?>">
        <input type="number" name="ban_days" class="form-input w-110" min="0" value="<?php echo $defaultBanDays; ?>" title="<?php echo $I('plugin.mod_system.ban_days_placeholder'); ?>">
        <button type="submit" class="btn-delete"><?php echo $I('plugin.mod_system.add_ban'); ?></button>
    </form>
</div>

<!-- 小黑屋：封禁名单 -->
<div class="card mn-p-15 mt-10">
    <div class="mb-10 mn-fs-14 mn-fw-500"><?php echo $I('plugin.mod_system.admin_bans'); ?></div>
        <table class="admin-table">
            <thead>
                <tr>
                    <th class="admin-w-100"><?php echo $I('plugin.mod_system.ban_user'); ?></th>
                    <th><?php echo $I('plugin.mod_system.ban_reason'); ?></th>
                    <th class="admin-w-100"><?php echo $I('plugin.mod_system.ban_by'); ?></th>
                    <th class="admin-w-150"><?php echo $I('plugin.mod_system.ban_time'); ?></th>
                    <th class="admin-w-80"><?php echo $I('plugin.mod_system.ban_status'); ?></th>
                    <th class="admin-w-100"><?php echo $I('plugin.mod_system.operate'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($banItems)): ?>
                <tr><td colspan="6" class="c-999 mn-text-center"><?php echo $I('plugin.mod_system.empty'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($banItems as $b): ?>
                <tr>
                    <td class="text-nowrap"><?php echo $this->e($b['username']); ?></td>
                    <td><?php echo $this->e($b['reason'] !== '' ? $b['reason'] : '-'); ?></td>
                    <td class="text-nowrap"><?php echo $this->e($b['banned_by']); ?></td>
                    <td class="mn-fs-12 c-999 text-nowrap"><?php echo $this->e(date('Y-m-d H:i', strtotime($b['banned_at']))); ?></td>
                    <td class="text-nowrap">
                        <?php if ($b['is_active']): ?>
                            <span class="tag tag-danger tag-xs"><?php echo $I('plugin.mod_system.ban_active'); ?></span>
                        <?php else: ?>
                            <span class="tag tag-xs"><?php echo $I('plugin.mod_system.ban_inactive'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                        <?php if ($b['is_active']): ?>
                            <form method="POST" action="<?php echo $this->url('/admin/mod-system/bans/' . (int)$b['id'] . '/unban'); ?>" class="inline-block"
                                  x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode($I('plugin.mod_system.confirm_handle')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                                <button type="submit" class="btn-edit"><?php echo $I('plugin.mod_system.action_unban'); ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
</div>

<!-- 状态筛选 -->
<div class="mb-10 mn-flex mn-gap-10 items-center mn-flex-wrap">
    <select class="form-input w-160" x-data
            x-on:change="location.href = '<?php echo $this->url('/admin/mod-system/reports'); ?>' + (this.value >= 0 ? '?status=' + this.value : '')">
        <?php foreach ($filterTabs as $val => $key): ?>
            <option value="<?php echo (int)$val; ?>" <?php echo $status === $val ? 'selected' : ''; ?>><?php echo $I('plugin.mod_system.' . $key); ?></option>
        <?php endforeach; ?>
    </select>
</div>

<!-- 批量处理工具条 -->
<div class="mb-10 mn-flex mn-gap-10 items-center mn-flex-wrap">
    <label class="mn-flex items-center mn-gap-4 mn-fs-13 mn-cursor-pointer">
        <input type="checkbox" id="select-all">
        <?php echo $I('plugin.mod_system.select_all'); ?>
    </label>
    <select id="batch-action" class="form-input w-160">
        <option value="dismiss"><?php echo $I('plugin.mod_system.action_dismiss'); ?></option>
        <option value="delete"><?php echo $I('plugin.mod_system.action_delete'); ?></option>
        <option value="ban"><?php echo $I('plugin.mod_system.action_ban'); ?></option>
        <option value="delete_ban"><?php echo $I('plugin.mod_system.action_delete_ban'); ?></option>
    </select>
    <input type="number" id="batch-ban-days" class="form-input w-100" min="0" value="0"
           title="<?php echo $I('plugin.mod_system.ban_days_label'); ?>">
    <input type="text" id="batch-reason" class="form-input w-200"
           placeholder="<?php echo $I('plugin.mod_system.ban_reason_placeholder'); ?>">
    <button type="button" class="btn-save" id="batch-handle-btn" disabled><?php echo $I('plugin.mod_system.batch_handle'); ?></button>
</div>

<table class="admin-table admin-table-mobile-hide-id" id="modAdminReports"
       data-csrf="<?php echo htmlspecialchars((string)$this->getData('csrfToken'), ENT_QUOTES, 'UTF-8'); ?>"
       data-batch-url="<?php echo $this->url('/admin/mod-system/reports/batch'); ?>"
       data-handle-prefix="<?php echo $this->url('/admin/mod-system/reports/'); ?>"
       data-need-select="<?php echo htmlspecialchars($I('plugin.mod_system.need_select'), ENT_QUOTES, 'UTF-8'); ?>"
       data-confirm="<?php echo htmlspecialchars($I('plugin.mod_system.confirm_handle'), ENT_QUOTES, 'UTF-8'); ?>">
    <thead>
        <tr>
            <th class="admin-w-40"><input type="checkbox" disabled></th>
            <th class="admin-w-50">ID</th>
            <th><?php echo $I('plugin.mod_system.col_target'); ?></th>
            <th class="admin-w-100"><?php echo $I('plugin.mod_system.col_category'); ?></th>
            <th class="admin-w-120"><?php echo $I('plugin.mod_system.col_reporter'); ?></th>
            <th class="admin-w-150"><?php echo $I('plugin.mod_system.col_time'); ?></th>
            <th class="admin-w-120"><?php echo $I('plugin.mod_system.col_status'); ?></th>
            <th class="admin-w-160"><?php echo $I('plugin.mod_system.operate'); ?></th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($items)): ?>
        <tr><td colspan="8" class="c-999 mn-text-center"><?php echo $I('plugin.mod_system.empty'); ?></td></tr>
    <?php endif; ?>
    <?php foreach ($items as $it): ?>
        <tr>
            <td><input type="checkbox" value="<?php echo (int)$it['id']; ?>"
                       <?php echo $it['status'] === 0 ? '' : 'disabled'; ?>
                       class="report-checkbox"></td>
            <td><?php echo (int)$it['id']; ?></td>
            <td class="mobile-col-title">
                <div class="mn-flex-center mn-gap-6 mn-flex-wrap">
                    <?php if ($it['alert'] && $it['status'] === 0): ?>
                        <span class="tag tag-danger tag-xs"><?php echo $I('plugin.mod_system.auto_alert'); ?></span>
                    <?php endif; ?>
                    <a href="<?php echo $this->e($targetUrl($it['type'], (int)$it['target_id'])); ?>"
                       class="mn-text-decoration-none mn-fw-500" target="_blank"><?php echo $this->e($it['target_title']); ?></a>
                </div>
                <?php if (!empty($it['note'])): ?>
                    <div class="mn-fs-12 c-999 mn-mt-2"><?php echo $this->e(mb_substr($it['note'], 0, 60)); ?></div>
                <?php endif; ?>
            </td>
            <td class="text-nowrap mobile-col-meta"><?php echo $this->e($it['category_text']); ?></td>
            <td class="text-nowrap mobile-col-meta">
                <?php echo $this->e($it['reporter']); ?>
                <?php if ($it['is_anonymous']): ?><span class="tag tag-xs"><?php echo $I('plugin.mod_system.anonymous'); ?></span><?php endif; ?>
            </td>
            <td class="mn-fs-12 c-999 text-nowrap mobile-col-meta"><?php echo $this->e(date('Y-m-d H:i', strtotime($it['created_at']))); ?></td>
            <td class="text-nowrap mobile-col-meta">
                <?php
                $stKey = $it['status'] === 0 ? 'status_pending' : ($it['status'] === 1 ? 'status_handled' : 'status_dismissed');
                $stCls = $it['status'] === 0 ? 'tag-danger' : ($it['status'] === 1 ? 'tag-hot' : 'tag');
                ?>
                <span class="tag tag-xs <?php echo $stCls; ?>"><?php echo $I('plugin.mod_system.' . $stKey); ?></span>
            </td>
            <td class="text-nowrap">
                <?php if ($it['status'] === 0): ?>
                    <form method="POST" action="<?php echo $this->url('/admin/mod-system/reports/' . (int)$it['id'] . '/handle'); ?>" class="inline-block"
                          x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode($I('plugin.mod_system.confirm_handle')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                        <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="dismiss">
                        <button type="submit" class="btn-edit"><?php echo $I('plugin.mod_system.action_dismiss'); ?></button>
                    </form>
                    <form method="POST" action="<?php echo $this->url('/admin/mod-system/reports/' . (int)$it['id'] . '/handle'); ?>" class="inline-block"
                          x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode($I('plugin.mod_system.confirm_handle')), ENT_QUOTES, 'UTF-8'); ?>)) return; $el.submit()">
                        <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" class="btn-delete"><?php echo $I('plugin.mod_system.action_delete'); ?></button>
                    </form>
                    <button type="button" class="btn-delete report-ban-btn"
                            data-id="<?php echo (int)$it['id']; ?>"
                            data-default-days="<?php echo (int)$defaultBanDays; ?>"
                            data-days-label="<?php echo htmlspecialchars($I('plugin.mod_system.ban_days_label'), ENT_QUOTES, 'UTF-8'); ?>"
                            data-reason-label="<?php echo htmlspecialchars($I('plugin.mod_system.ban_reason_label'), ENT_QUOTES, 'UTF-8'); ?>"
                            data-reason-ph="<?php echo htmlspecialchars($I('plugin.mod_system.ban_reason_placeholder'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $I('plugin.mod_system.action_ban'); ?></button>
                <?php elseif ($it['handle_action'] !== ''): ?>
                    <span class="mn-fs-12 c-999"><?php echo $this->e($it['handle_action']); ?></span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php $jsVer = @filemtime(__DIR__ . '/../assets/script.js') ?: 1; ?>
<script src="<?php echo $this->url('/plugins/mod_system/assets/script.js'); ?>?v=<?php echo $jsVer; ?>"></script>

<?php
$qs = $status >= 0 ? 'status=' . $status . '&amp;' : '';
echo $this->pagination($page, $totalPages, '/admin/mod-system/reports?' . $qs . 'page={page}', ['style' => 'admin', 'class' => 'mt-20']);
?>