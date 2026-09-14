<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 用户主页前台控制器 — 查看用户资料、帖子、博客、关注
 * @file plugins/user_profile/FrontController.php
 * @package Plugin\UserProfile
 * @version 1.1.0
 */

namespace Plugin\UserProfile;

use app\Core\Controller;

class FrontController extends Controller
{
    public function show($id)
    {
        $userId = (int)$id;
        if ($userId <= 0) {
            $this->redirect('/');
        }

        $user = \Plugin\UserProfile\Plugin::getUser($userId);
        if (!$user) {
            $this->view('errors/404', ['message' => \app\Helpers\I18n::get('plugin.user_profile.user_not_found')]);
            return;
        }

        // 不暴露邮箱给未登录用户，以及不暴露给其他用户
        $isOwner = $this->isLoggedIn() && (int)$this->currentUser()['id'] === $userId;
        if (!$isOwner) {
            unset($user['email']);
            unset($user['email_verified']);
        }

        $threads = \Plugin\UserProfile\Plugin::getThreads($userId, 10);
        $blogs   = \Plugin\UserProfile\Plugin::getBlogs($userId, 10);
        $stats   = \Plugin\UserProfile\Plugin::getStats($userId);
        $followCounts = \Plugin\UserProfile\Plugin::getFollowCounts($userId);
        $isFollowing = $this->isLoggedIn() && !$isOwner
            ? \Plugin\UserProfile\Plugin::isFollowing((int)$this->currentUser()['id'], $userId) : false;

        $this->view('plugins/user_profile/front', [
            'profileUser'  => $user,
            'threads'      => $threads,
            'blogs'        => $blogs,
            'stats'        => $stats,
            'followCounts' => $followCounts,
            'isFollowing'  => $isFollowing,
            'isOwner'      => $isOwner,
            '__nav_active' => '',
        ]);
    }

    public function follow($id)
    {
        $this->handleCsrf();
        $followedId = (int)$id;
        if (!$this->isLoggedIn() || $followedId <= 0) {
            $this->redirect('/login');
        }
        $followerId = (int)$this->currentUser()['id'];
        \Plugin\UserProfile\Plugin::follow($followerId, $followedId);
        $this->redirect('/u/' . $followedId);
    }

    public function unfollow($id)
    {
        $this->handleCsrf();
        $followedId = (int)$id;
        if (!$this->isLoggedIn() || $followedId <= 0) {
            $this->redirect('/login');
        }
        $followerId = (int)$this->currentUser()['id'];
        \Plugin\UserProfile\Plugin::unfollow($followerId, $followedId);
        $this->redirect('/u/' . $followedId);
    }

    private function handleCsrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/');
        }
        \app\Helpers\Csrf::verifyOrDie($_POST['csrf'] ?? '');
    }
}