<?php
/**
 * 楼中楼插件 楼层锚点注入 — 每个楼层输出楼中楼容器锚点
 * 锚点由 assets/script.js 移动到楼层内容末尾，点击展开才经 htmx 拉取列表。
 * @file plugins/floor_reply/hook/post_operation_after.php
 * @package Plugin\FloorReply
 * @version 1.0.0
 */

$post = $params['post'] ?? null;
if (!$post || empty($post['id'])) return;

$pid = (int)$post['id'];
if ($pid <= 0) return;

// count 由 controller_view_before 一次性批量查询后经 $GLOBALS 传递（避免每楼一次查询）
$counts = $GLOBALS['__floor_reply_counts'] ?? [];
$count = (int)($counts[$pid] ?? 0);
$threadId = (int)($post['thread_id'] ?? 0);

$bp = \defined('BASE_PATH') ? BASE_PATH : '';
$replyLabel = \app\Helpers\I18n::get('plugin.floor_reply.reply');
?>
<button type="button" class="fr-reply-btn" data-fr-pid="<?php echo $pid; ?>" hx-push-url="false"
        title="<?php echo htmlspecialchars($replyLabel, ENT_QUOTES, 'UTF-8'); ?>">
    <i class="fa">&#xf112;</i><span class="fr-reply-text mn-desktop-only"><?php echo htmlspecialchars($replyLabel, ENT_QUOTES, 'UTF-8'); ?></span>
    <span class="fr-reply-count">(<?php echo (int)$count; ?>)</span><span class="fr-caret">&#9662;</span>
</button>
<div class="fr-anchor" id="fr-anchor-<?php echo $pid; ?>"
     data-fr-post="<?php echo $pid; ?>" data-fr-thread="<?php echo $threadId; ?>"
     data-fr-count="<?php echo $count; ?>"></div>
