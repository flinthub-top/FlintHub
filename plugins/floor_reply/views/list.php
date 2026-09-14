<?php
/**
 * 楼中楼列表片段视图 — htmx 局部渲染（列表 + 分页 + 回复表单 + @高亮）
 * 由 FrontController::list/reply/delete 以 viewRaw 无布局渲染，整块替换 #fr-anchor-{postId}。
 * 每页 5 条；超过 5 条时显示分页器（htmx 按页切换）。
 * @file plugins/floor_reply/views/list.php
 * @package Plugin\FloorReply
 */
$postId = (int)$this->getData('postId', 0);
$threadId = (int)$this->getData('threadId', 0);
$threadOwnerId = (int)$this->getData('threadOwnerId', 0);
$replies = $this->getData('replies', []);
$error = (string)$this->getData('error', '');
$page = max(1, (int)$this->getData('page', 1));
$total = (int)$this->getData('total', 0);
$totalPages = (int)$this->getData('totalPages', 0);
$hasPage = (bool)$this->getData('hasPage', false);
$isLoggedIn = (bool)$this->getData('isLoggedIn', false);
$currentUser = $this->getData('currentUser', []);
$csrfToken = $this->e($this->getData('csrfToken', ''));
$I = function (string $k, array $p = []) { return \app\Helpers\I18n::get($k, $p); };
$uid = (int)($currentUser['id'] ?? 0);
$canModerate = $isLoggedIn && ($uid === $threadOwnerId);
$listUrl = $this->url('/floor-reply/list/' . $postId);
?>
<div class="fr-box" id="fr-anchor-<?php echo $postId; ?>" data-fr-post="<?php echo $postId; ?>" data-fr-thread="<?php echo $threadId; ?>" hx-push-url="false">
    <?php if ($error !== ''): ?>
    <div class="fr-error" role="alert"><?php echo $this->e($error); ?></div>
    <?php endif; ?>

    <?php if (empty($replies)): ?>
        <?php if ($error === ''): ?>
        <p class="fr-empty"><?php echo $I('plugin.floor_reply.empty'); ?></p>
        <?php endif; ?>
    <?php else: ?>
    <ul class="fr-list">
        <?php foreach ($replies as $r): $rid = (int)$r['id']; $rUid = (int)$r['user_id']; ?>
        <li class="fr-item" data-fr-id="<?php echo $rid; ?>">
            <div class="fr-item-head">
                <span class="fr-avatar">
                    <?php if (!empty($r['avatar'])): ?>
                        <img src="<?php echo $this->e(\UPLOAD_URL . $r['avatar']); ?>" alt="" class="fr-avatar-img">
                    <?php else: ?>
                        <span class="fr-avatar-ph"><?php echo $this->e(mb_substr($r['username'] ?? '', 0, 1)); ?></span>
                    <?php endif; ?>
                </span>
                <span class="fr-meta">
                    <a href="<?php echo $this->url('/u/' . $rUid); ?>" class="fr-username"><?php echo $this->e($r['username'] ?? '#' . $rUid); ?></a>
                    <?php if (!empty($r['mention_username'])): ?>
                    <span class="fr-at"><?php echo $I('plugin.floor_reply.at_target', ['name' => $this->e($r['mention_username'])]); ?></span>
                    <?php endif; ?>
                    <span class="fr-time"><?php echo $this->e(date('Y-m-d H:i', strtotime($r['created_at'] ?? 'now'))); ?></span>
                </span>
                <?php if ($isLoggedIn && ($rUid === $uid || $this->getData('isAdmin') || $canModerate)): ?>
                <form method="post" action="<?php echo $this->url('/floor-reply/delete'); ?>" class="fr-del-form"
                      hx-post="<?php echo $this->url('/floor-reply/delete'); ?>" hx-target="#fr-anchor-<?php echo $postId; ?>" hx-swap="outerHTML">
                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="id" value="<?php echo $rid; ?>">
                    <input type="hidden" name="post_id" value="<?php echo $postId; ?>">
                    <input type="hidden" name="thread_id" value="<?php echo $threadId; ?>">
                    <input type="hidden" name="page" value="<?php echo $page; ?>">
                    <button type="submit" class="fr-del-btn" title="<?php echo $I('plugin.floor_reply.delete'); ?>"><?php echo $this->icon('delete', 12); ?></button>
                </form>
                <?php endif; ?>
            </div>
            <div class="fr-content"><?php echo $r['content_html'] ?? $this->e($r['content'] ?? ''); ?></div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
    <div class="fr-pagination">
        <?php if ($page > 1): ?>
        <a class="fr-page-btn" href="<?php echo $listUrl; ?>?page=<?php echo $page - 1; ?>"
           hx-get="<?php echo $listUrl; ?>?page=<?php echo $page - 1; ?>" hx-target="#fr-anchor-<?php echo $postId; ?>" hx-swap="outerHTML">&laquo; <?php echo $I('plugin.floor_reply.prev'); ?></a>
        <?php endif; ?>
        <?php for ($n = 1; $n <= $totalPages; $n++): ?>
        <a class="fr-page-btn<?php echo ($hasPage && $n === $page) ? ' fr-page-active' : ''; ?>"
           href="<?php echo $listUrl; ?>?page=<?php echo $n; ?>"
           hx-get="<?php echo $listUrl; ?>?page=<?php echo $n; ?>" hx-target="#fr-anchor-<?php echo $postId; ?>" hx-swap="outerHTML"><?php echo $n; ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
        <a class="fr-page-btn" href="<?php echo $listUrl; ?>?page=<?php echo $page + 1; ?>"
           hx-get="<?php echo $listUrl; ?>?page=<?php echo $page + 1; ?>" hx-target="#fr-anchor-<?php echo $postId; ?>" hx-swap="outerHTML"><?php echo $I('plugin.floor_reply.next'); ?> &raquo;</a>
        <?php endif; ?>
        <span class="fr-page-total"><?php echo $I('plugin.floor_reply.total', ['count' => $total]); ?></span>
    </div>
    <?php endif; ?>

    <?php if ($isLoggedIn): ?>
    <form method="post" action="<?php echo $this->url('/floor-reply/reply'); ?>" class="fr-form"
          hx-post="<?php echo $this->url('/floor-reply/reply'); ?>" hx-target="#fr-anchor-<?php echo $postId; ?>" hx-swap="outerHTML">
        <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
        <input type="hidden" name="post_id" value="<?php echo $postId; ?>">
        <input type="hidden" name="thread_id" value="<?php echo $threadId; ?>">
        <input type="hidden" name="page" value="<?php echo $page; ?>">
        <div class="fr-form-row">
            <input type="text" name="content" class="fr-input" maxlength="500" required
                   placeholder="<?php echo $I('plugin.floor_reply.placeholder'); ?>">
            <button type="submit" class="fr-submit"><?php echo $I('plugin.floor_reply.reply'); ?></button>
        </div>
        <p class="fr-hint"><?php echo $I('plugin.floor_reply.mention_hint'); ?></p>
    </form>
    <?php else: ?>
    <p class="fr-login-hint"><a href="<?php echo $this->url('/login'); ?>" class="mn-text-primary mn-fw-600"><?php echo $I('plugin.floor_reply.login_to_reply'); ?></a></p>
    <?php endif; ?>
</div>
