<?php
/**
 * 红包插件钩子 — 帖子详情页内嵌红包卡片（thread/show.php 签名后、统计栏前锚点）
 * 查询该帖关联红包，渲染状态卡片（可抢/已抢/已抢完/已过期/自己的红包），AJAX 抢红包不跳页。
 * 无红包 / 插件停用 → 零输出。
 * @file plugins/red_packet/hook/thread_redpacket_area.php
 * @package Plugin\RedPacket
 * @version 1.1.0
 */

$rpThread = $params['thread'] ?? null;
if (empty($rpThread) || empty($rpThread['id'])) return;
$rpTid = (int)$rpThread['id'];
if ($rpTid <= 0) return;

try {
    $pdb = \Plugin\RedPacket\Plugin::db();
    $rpNow = date('Y-m-d H:i:s');
    // 优先取「未领完且未过期」的可抢红包；若无，取该帖最新红包展示最终状态（已抢完/已过期），
    // 保证帖子详情页红包卡片不因领完/过期而消失。
    $stmt = $pdb->prepare(
        'SELECT * FROM red_packets
         WHERE thread_id = :tid AND remaining_count > 0 AND expires_at > :now
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([':tid' => $rpTid, ':now' => $rpNow]);
    $rp = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$rp) {
        $stmt = $pdb->prepare('SELECT * FROM red_packets WHERE thread_id = :tid ORDER BY id DESC LIMIT 1');
        $stmt->execute([':tid' => $rpTid]);
        $rp = $stmt->fetch(\PDO::FETCH_ASSOC);
    }
    if (!$rp) return; // 该帖从未发过红包，零输出

    $rpIsExpired = $rpNow > $rp['expires_at'];
    $rpIsEmpty = (int)$rp['remaining_count'] <= 0;
    $rpIsLoggedIn = \app\Helpers\Auth::isLoggedIn();
    $rpUserId = (int)($_SESSION['user_id'] ?? 0);

    // 当前用户是否已抢
    $rpClaimed = null;
    if ($rpIsLoggedIn) {
        $cstmt = $pdb->prepare('SELECT points FROM red_packet_claims WHERE packet_id = :pid AND user_id = :uid');
        $cstmt->execute([':pid' => (int)$rp['id'], ':uid' => $rpUserId]);
        $rpClaimed = $cstmt->fetch(\PDO::FETCH_ASSOC);
    }

    $bp = \defined('BASE_PATH') ? BASE_PATH : '';
    $cssVer = @filemtime(__DIR__ . '/../assets/style.css') ?: 1;
    $jsVer = @filemtime(__DIR__ . '/../assets/script.js') ?: 1;
    $csrf = \app\Helpers\Csrf::token();
    $I = function (string $k, array $p = []) { return \app\Helpers\I18n::get($k, $p); };
    ?>
    <div class="rp-thread-card" data-rp-card data-rp-id="<?php echo (int)$rp['id']; ?>">
        <div class="rp-thread-inner">
            <div class="rp-thread-head">
                <span class="rp-thread-head-icon"><?php echo \app\Helpers\I18n::get('plugin.red_packet.thread_card_title'); ?></span>
            </div>
            <div class="rp-thread-body">
                <div class="rp-thread-info">
                    <div class="rp-thread-amount"><span class="rp-thread-plus">+</span><?php echo (int)$rp['total_points']; ?> <span class="rp-thread-unit"><?php echo $I('plugin.red_packet.points_unit'); ?></span></div>
                    <div class="rp-thread-meta"><?php echo $I('plugin.red_packet.thread_remaining', ['count' => (int)$rp['remaining_count'], 'total' => (int)$rp['total_count']]); ?></div>
                </div>
                <div class="rp-thread-action">
                    <?php if ($rpIsExpired): ?>
                        <span class="rp-thread-status rp-thread-status-off"><?php echo $I('plugin.red_packet.expired'); ?></span>
                    <?php elseif ($rpIsEmpty): ?>
                        <span class="rp-thread-status rp-thread-status-off"><?php echo $I('plugin.red_packet.finished'); ?></span>
                    <?php elseif ($rpClaimed): ?>
                        <span class="rp-thread-status rp-thread-status-ok"><?php echo $I('plugin.red_packet.thread_claimed', ['points' => (int)$rpClaimed['points']]); ?></span>
                    <?php elseif (!$rpIsLoggedIn): ?>
                        <a href="<?php echo $bp; ?>/login" class="mn-btn mn-btn-sm rp-thread-login-btn"><?php echo $I('plugin.red_packet.thread_login'); ?></a>
                    <?php else: ?>
                        <button type="button" class="rp-thread-grab-btn" data-rp-grab data-rp-id="<?php echo (int)$rp['id']; ?>" data-rp-csrf="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $I('plugin.red_packet.grab'); ?></button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="rp-thread-msg" data-rp-msg></div>
        </div>
    </div>
    <link rel="stylesheet" href="<?php echo $bp; ?>/plugins/red_packet/assets/style.css?v=<?php echo $cssVer; ?>">
    <script src="<?php echo $bp; ?>/plugins/red_packet/assets/script.js?v=<?php echo $jsVer; ?>"></script>
    <?php
} catch (\Throwable $e) {
    \error_log('red_packet thread_redpacket_area: ' . $e->getMessage());
}
