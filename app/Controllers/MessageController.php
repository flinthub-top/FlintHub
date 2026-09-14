<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 私信前台控制器 — 收件箱/发件箱、发送私信、已读标记
 * @file app/Controllers/MessageController.php
 * @package app\Controllers
 */

namespace app\Controllers;
use app\Core\Controller;
use app\Models\Message;
use app\Helpers\Csrf;
use app\Helpers\RateLimiter;

class MessageController extends Controller
{
    protected function checkMessageEnabled(): void
    {
        if (\app\Helpers\Settings::get('message_enabled') !== '1') {
            $this->redirect('/');
        }
    }

    public function index() { $this->redirect('/message/inbox'); }

    public function inbox()
    {
        $this->checkMessageEnabled();
        $this->requireLogin();
        $currentUser = $this->currentUser();
        $messageModel = new Message();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $msgId = (int)($_POST['message_id'] ?? 0);
            if ($msgId) {
                $messageModel->deleteOwned($msgId, (int)$currentUser['id']);
            }
            $this->redirect('/message/inbox');
        }

        // 未读数走 Settings 轻量 COUNT（请求级缓存：与布局顶部未读数共用一次查询），
        // 不再全量拉取消息列表数未读（原实现先 getInbox() 拉一页只为数未读，随后又分页查询一次）
        $unreadCount = \app\Helpers\Settings::getUnreadMessageCount();

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 10;
        $offset = ($page - 1) * $perPage;
        $total = $messageModel->countInbox((int)$currentUser['id']);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $messages = $messageModel->getInbox((int)$currentUser['id'], $perPage, $offset);

        $this->view('message/inbox', [
            'messages' => $messages, 'unreadCount' => $unreadCount,
            'page' => $page, 'totalPages' => $totalPages, 'total' => $total,
            '_action' => 'inbox',
        ]);
    }

    public function sent()
    {
        $this->checkMessageEnabled();
        $this->requireLogin();
        $currentUser = $this->currentUser();
        $messageModel = new Message();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $msgId = (int)($_POST['message_id'] ?? 0);
            if ($msgId) {
                $messageModel->deleteOwned($msgId, (int)$currentUser['id']);
            }
            $this->redirect('/message/sent');
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 10;
        $offset = ($page - 1) * $perPage;
        $total = $messageModel->countSent((int)$currentUser['id']);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $messages = $messageModel->getSent((int)$currentUser['id'], $perPage, $offset);

        $this->view('message/sent', [
            'messages' => $messages,
            'page' => $page, 'totalPages' => $totalPages, 'total' => $total,
            '_action' => 'sent',
        ]);
    }

    public function compose()
    {
        $this->checkMessageEnabled();
        $this->requireLogin();
        $currentUser = $this->currentUser();
        $receiverName = '';
        $replyTo = (int)($_GET['reply_to'] ?? 0);

        if ($replyTo > 0) {
            $replyUser = (new \app\Models\User())->find($replyTo);
            if ($replyUser) { $receiverName = $replyUser['username']; }
        }

        $error = '';
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            RateLimiter::hitConfig('message_send', 10, 60); // 频率限制：每分钟最多 10 条（阈值后台可配）
            $receiver = trim($_POST['receiver'] ?? '');
            $subject = trim($_POST['subject'] ?? '');
            $rawContent = trim($_POST['content'] ?? '');
            $content = \app\Helpers\Content::decode($rawContent);

            if (!$receiver || !$subject || !$content) {
                $error = \app\Helpers\I18n::get('message.fill_all');
            } else {
                $receiverUser = (new \app\Models\User())->findByUsername($receiver);
                if (!$receiverUser) { $error = \app\Helpers\I18n::get('message.receiver_not_found'); }
                elseif ((int)$receiverUser['id'] === (int)$currentUser['id']) { $error = \app\Helpers\I18n::get('message.cannot_self'); }
                else {
                    (new Message())->insert([
                        'sender_id' => (int)$currentUser['id'], 'receiver_id' => (int)$receiverUser['id'],
                        'subject' => $subject, 'content' => $content, 'is_read' => 0,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $success = \app\Helpers\I18n::get('message.sent_success');
                }
            }
        }

        $this->view('message/compose', ['error' => $error, 'success' => $success, 'replyTo' => $replyTo, 'receiverName' => $receiverName, '_action' => 'compose', 'needsEditor' => true]);
    }

    public function detail($id)
    {
        $this->checkMessageEnabled();
        $this->requireLogin();
        $currentUser = $this->currentUser();
        $messageModel = new Message();
        $message = $messageModel->find((int)$id);

        if (!$message || ((int)$message['receiver_id'] !== (int)$currentUser['id'] && (int)$message['sender_id'] !== (int)$currentUser['id'])) {
            $this->redirect('/message/inbox');
        }

        if ((int)$message['receiver_id'] === (int)$currentUser['id']) {
            $messageModel->markAsRead((int)$id, (int)$currentUser['id']);
            $message['is_read'] = 1;
            // 已读状态变更：清除请求级未读缓存，防本请求布局顶部未读数读到旧值
            \app\Helpers\Settings::clearUnreadMessageCache();
        }

        $this->view('message/view', ['message' => $message, '_action' => 'view']);
    }
}
