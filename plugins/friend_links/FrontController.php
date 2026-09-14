<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 友情链接前台控制器 — 友情链接申请
 * @file plugins/friend_links/FrontController.php
 * @package Plugin\FriendLinks
 * @version 1.1.0
 */

namespace Plugin\FriendLinks;

use app\Core\Controller;
use app\Helpers\Auth;
use app\Helpers\Csrf;

class FrontController extends Controller
{
    public function apply()
    {
        // 检查申请功能是否开启
        if (!\Plugin\FriendLinks\Plugin::isApplyEnabled()) {
            $this->redirect('/');
        }

        $this->requireLogin();
        $currentUser = $this->currentUser();
        $error = '';
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $url = trim($_POST['url'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $logo = trim($_POST['logo'] ?? '');

            if (empty($name) || empty($url)) {
                $error = \app\Helpers\I18n::get('plugin.friend_links.err_apply_empty');
            } elseif (!preg_match('#^https?://#i', $url)) {
                $error = \app\Helpers\I18n::get('plugin.friend_links.err_url');
            } elseif ($logo !== '' && !preg_match('#^https?://#i', $logo)) {
                $error = \app\Helpers\I18n::get('plugin.friend_links.err_logo');
            } else {
                \Plugin\FriendLinks\Plugin::apply($name, $url, $description, $logo, (int)$currentUser['id']);
                $success = \app\Helpers\I18n::get('plugin.friend_links.msg_applied');
            }
        }

        $this->view('plugins/friend_links/apply', [
            'error' => $error,
            'success' => $success,
        ]);
    }
}
