<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 认证控制器 — 登录、注册、登出、密码重置、邮箱验证
 * @file app/Controllers/AuthController.php
 * @package app\Controllers
 */

namespace app\Controllers;

use app\Core\Controller;
use app\Core\Database;
use app\Models\User;
use app\Helpers\Csrf;
use app\Helpers\Captcha;
use app\Helpers\RememberMe;
use app\Helpers\RateLimiter;
use app\Helpers\Settings;
use app\Helpers\Mailer;

class AuthController extends Controller
{
    public function login()
    {
        if ($this->isLoggedIn()) {
            header('Location: ' . (\defined('BASE_PATH') ? \BASE_PATH : '') . '/');
            exit;
        }

        $error = '';
        $locked = false;
        $now = time();
        $db = \app\Core\Database::getInstance();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // IP 级限流：每个 IP 15 分钟内最多 20 次登录尝试（防密码喷洒/登录 DoS，与用户名级锁定互补；阈值后台可配）
            [$loginMax, $loginWin] = RateLimiter::config('login', 20, 900);
            $ipAllowed = RateLimiter::check('login', $loginMax, $loginWin);
            if (!$ipAllowed) {
                $locked = true;
                $lockRemaining = $loginWin;
                $error = \app\Helpers\I18n::get('auth.lock_ip');
            }

            $username = trim($_POST['username'] ?? '');

            // 从数据库读取登录锁定状态（基于用户名，非 Session，更换 Session ID 无法绕过）
            $lockKey = '_login_lock_' . \md5(\strtolower($username));
            $lockRow = $db->fetchOne('SELECT value FROM settings WHERE "key" = :k', [':k' => $lockKey]);
            $lockData = $lockRow ? @\json_decode($lockRow['value'], true) : null;
            $attempts = (int)($lockData['attempts'] ?? 0);
            $lockUntil = (int)($lockData['lock_until'] ?? 0);

            if ($lockUntil > $now) {
                $locked = true;
                $lockRemaining = $lockUntil - $now;
                $error = \app\Helpers\I18n::get('auth.lock_retry', ['seconds' => $lockRemaining]);
            } elseif (!$ipAllowed) {
                // IP 已超限：跳过凭据校验（防密码喷洒），直接展示限流提示
            } else {
                if (!Csrf::verify($_POST['csrf'] ?? '')) {
                    $error = \app\Helpers\I18n::get('auth.request_expired');
                } else {
                    $password = (string)($_POST['password'] ?? '');
                    $remember = isset($_POST['remember']);

                    if (strlen($username) > 64) $username = substr($username, 0, 64);

                    if ($username !== '' && $password !== '') {

                        // 自适应防线：PoW 通过即视为正常用户直接放行；仅 PoW 失败（脚本/无 JS）才需图片验证码。
                        // IP 防爆破由上方 RateLimiter::check('login', 20, 900) 硬限流单独兜底
                        $needCaptcha = !\app\Helpers\PoW::validate($_POST['pow_nonce'] ?? null);

                        if ($needCaptcha && !Captcha::verify($_POST['captcha'] ?? '')) {
                            $error = \app\Helpers\I18n::get('auth.captcha_wrong');
                        } else {
                            $userModel = new User();
                            $user = $userModel->findByUsername($username);

                            static $dummyHash = null;
                            if ($dummyHash === null) {
                                $dummyHash = password_hash('dummy_password', PASSWORD_DEFAULT);
                            }
                            $hashToCheck = $user['password'] ?? $dummyHash;

                            if ($user && password_verify($password, $hashToCheck)) {
                                // 封禁拦截钩子：密码正确后、写 Session 前触发
                                \app\Helpers\Plugin::hook('auth_login_check', ['user' => $user]);
                                if (!empty($GLOBALS['mod_system_login_blocked'])) {
                                    // 封禁用户拒绝登录，避免因锁定重复计次
                                    $db->query('DELETE FROM settings WHERE "key" = :k', [':k' => $lockKey]);
                                    $error = \app\Helpers\I18n::get('plugin.mod_system.banned_tip');
                                } else {
                                // 检查邮箱是否已验证（仅邮箱验证开关开启时拦截，默认开启）
                                $emailVerifyEnabled = Settings::get('email_verify_enabled', '1') === '1';
                                if ($emailVerifyEnabled && empty($user['email_verified']) && $user['email_verify_token'] !== null) {
                                    $error = \app\Helpers\I18n::get('auth.verify_email_first');
                                } else {
                                // 登录成功：清除 DB 锁定记录
                                $db->query('DELETE FROM settings WHERE "key" = :k', [':k' => $lockKey]);

                                session_regenerate_id(true);
                                $_SESSION['user_id'] = (int)$user['id'];
                                Csrf::rotate(); // 登录后轮换 CSRF Token

                                $userModel->updateLoginTime((int)$user['id'], date('Y-m-d H:i:s'));

                                if ($remember) {
                                    $this->setRememberCookie((int)$user['id']);
                                }

                                \app\Helpers\Plugin::hook('auth_login_after', [
                                    'user_id' => (int)$user['id'],
                                    'username' => $user['username'],
                                ]);

                                \app\Helpers\AuditLog::setSessionUsername($user['username']);
                                \app\Helpers\AuditLog::log('login', 'user', (int)$user['id'], '登录成功');

                                // 登录成功后回跳来源页（无来源则回首页；一次性消费，防二次登录重复回跳）
                                $redirectAfter = \app\Helpers\Auth::consumeRedirectAfter();
                                $this->redirect($redirectAfter !== '' ? $redirectAfter : '/');
                                }
                                }
                            } else {
                                // 登录失败：记录到 DB，不依赖 Session
                                $attempts++;
                                $newLockUntil = 0;
                                if ($attempts >= 5) {
                                    $newLockUntil = $now + 900;  // 锁定 15 分钟
                                    $attempts = 0;
                                }
                                $db->query(
                                    'INSERT INTO settings ("key", value) VALUES (:k, :v) ON CONFLICT("key") DO UPDATE SET value = :v2',
                                    [':k' => $lockKey, ':v' => \json_encode(['attempts' => $attempts, 'lock_until' => $newLockUntil]),
                                     ':v2' => \json_encode(['attempts' => $attempts, 'lock_until' => $newLockUntil])]
                                );
                                \usleep(250000);
                                $error = \app\Helpers\I18n::get('auth.invalid_credentials');
                            }
                        }
                    } else {
                        $error = \app\Helpers\I18n::get('auth.fill_all_fields');
                    }
                }
            }
        }

        $this->view('auth/login', [
            'error' => $error,
            'locked' => $locked,
            'lockRemaining' => $lockRemaining ?? 0,
            'hasCsrf' => true,
        ]);
    }

    public function register()
    {
        if ($this->isLoggedIn()) {
            $this->redirect('/');
        }

        // 后台关闭注册时，不再渲染注册表单，直接提示（同时防 POST 直接提交注册）
        if (Settings::get('allow_registration', '1') !== '1') {
            $this->view('auth/message', [
                'title'   => \app\Helpers\I18n::get('auth.registration_closed'),
                'message' => \app\Helpers\I18n::get('admin.system_allow_registration_hint'),
            ]);
            return;
        }

        // 邮箱验证可能涉及的表结构延迟到发邮件前确保（见下方），此处不提前调用

        $error = '';
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            RateLimiter::hitConfig('register', 3, 3600); // 每小时最多注册 3 个账号（阈值后台可配）

            // 自适应防线：PoW 通过即视为正常用户直接放行；仅 PoW 失败才需图片验证码（与登录一致）
            $needCaptcha = !\app\Helpers\PoW::validate($_POST['pow_nonce'] ?? null);

            if ($needCaptcha && !Captcha::verify($_POST['captcha'] ?? '')) {
                $error = \app\Helpers\I18n::get('auth.captcha_wrong');
            } elseif (!Csrf::verify($_POST['csrf'] ?? '')) {
                $error = \app\Helpers\I18n::get('auth.request_expired');
            } else {
                $username = trim($_POST['username'] ?? '');
                $password = (string)($_POST['password'] ?? '');
                $confirmPassword = (string)($_POST['confirm_password'] ?? '');
                $email = trim($_POST['email'] ?? '');

                if (strlen($username) > 64) $username = substr($username, 0, 64);
                if (strlen($email) > 255) $email = substr($email, 0, 255);

                if (!$username || !$password || !$email) {
                    $error = \app\Helpers\I18n::get('auth.fill_all_fields');
                } elseif ($password !== $confirmPassword) {
                    $error = \app\Helpers\I18n::get('auth.password_mismatch');
                } elseif (strlen($password) < 6) {
                    $error = \app\Helpers\I18n::get('auth.password_too_short', ['min' => 6]);
                } elseif (!preg_match('/[a-zA-Z]/', $password)) {
                    $error = \app\Helpers\I18n::get('auth.password_need_letter');
                } elseif (!preg_match('/\d/', $password)) {
                    $error = \app\Helpers\I18n::get('auth.password_need_number');
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = \app\Helpers\I18n::get('auth.invalid_email');
                } else {
                    $userModel = new User();
                    $existing = $userModel->findByUsernameOrEmail($username, $email);
                    if ($existing) {
                        $error = \app\Helpers\I18n::get('auth.taken');
                    } else {
                        // 插件钩子：注册前校验（邀请码等）
                        \app\Helpers\Plugin::hook('auth_register_before');
                        $hookError = $GLOBALS['auth_register_error'] ?? '';
                        unset($GLOBALS['auth_register_error']);
                        if ($hookError) {
                            $error = $hookError;
                        } else {
                            $newUserId = $userModel->create($username, $password, $email);
                            // 新用户默认头像：确定性 SVG（复用 seed 风格，User::defaultAvatarPath）
                            $userModel->updateAvatar($newUserId, \app\Models\User::defaultAvatarPath($newUserId, $username));
                            Settings::runtimeIncr('total_users');
                            \app\Helpers\AuditLog::log('register', 'user', $newUserId, '新用户注册: ' . $username);
                            \app\Helpers\Plugin::hook('auth_register_after', [
                                'user_id' => $newUserId,
                                'username' => $username,
                                'email' => $email,
                            ]);

                            // 通知内置化：注册成功欢迎通知
                            \app\Helpers\Notification::notifyWelcome((int)$newUserId);

                            // 发送邮箱验证邮件（开关开启时，默认开启）
                            if (Settings::get('email_verify_enabled', '1') === '1') {
                                self::ensureEmailVerifyColumns();
                                $token = \bin2hex(\random_bytes(32));
                                $userModel->setVerifyToken($newUserId, $token);
                                $verifyLink = rtrim(SITE_URL, '/') . '/verify-email/' . $token;
                                $siteName = defined('DEFAULT_SITE_NAME') ? DEFAULT_SITE_NAME : 'FlintHub';
                                $subject = "=?UTF-8?B?" . \base64_encode(\app\Helpers\I18n::get('mail.verify_subject')) . "?=";
                                $body = \app\Helpers\I18n::get('mail.verify_greeting', ['username' => $username]) . "\n\n"
                                      . \app\Helpers\I18n::get('mail.register_thanks', ['site' => $siteName]) . "\n\n"
                                      . \app\Helpers\I18n::get('mail.verify_body') . "\n"
                                      . "{$verifyLink}\n\n"
                                      . \app\Helpers\I18n::get('mail.copy_hint') . "\n\n"
                                      . \app\Helpers\I18n::get('mail.ignore_hint', ['site' => $siteName]) . "\n\n"
                                      . "—— {$siteName} " . \app\Helpers\I18n::get('mail.team');
                                $mailResult = \app\Helpers\Mailer::send($email, $subject, $body);
                                if ($mailResult['success'] ?? false) {
                                    $success = \app\Helpers\I18n::get('auth.register_success_mail');
                                } else {
                                    $error = \app\Helpers\I18n::get('auth.register_success_mail_fail', ['error' => ($mailResult['error'] ?? \app\Helpers\I18n::get('mail.smtp_error'))]);
                                    \error_log('Registration email failed for user ' . $newUserId . ': ' . ($mailResult['error'] ?? 'unknown'));
                                }
                            } else {
                                $success = \app\Helpers\I18n::get('auth.register_success');
                            }
                        }
                    }
                }
            }
        }

        $this->view('auth/register', ['error' => $error, 'success' => $success]);
    }

    public function logout()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        // 审计日志：登出（在销毁 Session 前记录）
        $logoutUserId = (int)($_SESSION['user_id'] ?? 0);
        \app\Helpers\AuditLog::log('logout', 'user', $logoutUserId, '用户登出');
        Csrf::rotate(); // 登出后轮换 CSRF Token，使旧 Token 失效
        // 移除当前设备的记住登录 token（只吊销本端，不影响其他端）
        if ($logoutUserId > 0 && !empty($_COOKIE['remember_token'])) {
            RememberMe::removeToken($logoutUserId, (string)$_COOKIE['remember_token']);
        }
        RememberMe::clearCookies();
        // 清除 Session Cookie（彻底销毁会话）
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        $_SESSION = [];
        session_destroy();
        $this->redirect('/');
    }

    // ===== 找回密码 =====

    public function forgotPassword()
    {
        if ($this->isLoggedIn()) {
            $this->redirect('/');
        }

        $error = '';
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');
            RateLimiter::hitConfig('forgot_password', 3, 3600); // 阈值后台可配

            $email = trim($_POST['email'] ?? '');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = \app\Helpers\I18n::get('auth.email_invalid');
            } else {
                $userModel = new User();
                $user = $userModel->findByEmail($email);

                // 不管邮箱是否存在都提示"已发送"，防止枚举用户
                if ($user) {
                    self::ensurePasswordResetsTable();

                    $db = Database::getInstance();

                    $db->query("UPDATE password_resets SET used = 1 WHERE user_id = :uid AND used = 0", [':uid' => (int)$user['id']]);

                    $token = bin2hex(random_bytes(32));
                    $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1小时有效

                    $db->query(
                        "INSERT INTO password_resets (user_id, token, expires_at, created_at, used) VALUES (:uid, :token, :expires, :created, 0)",
                        [':uid' => (int)$user['id'], ':token' => hash('sha256', $token), ':expires' => $expiresAt, ':created' => date('Y-m-d H:i:s')]
                    );

                    // 发邮件（使用 SITE_URL 防 Host 头注入）
                    $resetLink = rtrim(SITE_URL, '/') . '/reset-password/' . $token;
                    $siteName = defined('DEFAULT_SITE_NAME') ? DEFAULT_SITE_NAME : 'FlintHub';
                    $subject = "=?UTF-8?B?" . base64_encode(\app\Helpers\I18n::get('mail.reset_subject')) . "?=";

                    $body = \app\Helpers\I18n::get('mail.reset_greeting') . "\n\n"
                          . \app\Helpers\I18n::get('mail.reset_requested', ['site' => $siteName]) . "\n\n"
                          . \app\Helpers\I18n::get('mail.reset_body') . "\n"
                          . "{$resetLink}\n\n"
                          . \app\Helpers\I18n::get('mail.copy_hint') . "\n\n"
                          . \app\Helpers\I18n::get('mail.reset_ignore', ['site' => $siteName]) . "\n\n"
                          . "—— {$siteName} " . \app\Helpers\I18n::get('mail.team');

                    Mailer::send($email, $subject, $body);
                }

                // 无论是否成功都显示统一提示
                $success = \app\Helpers\I18n::get('auth.reset_sent_hint');
            }
        }

        $this->view('auth/forgot_password', [
            'error' => $error,
            'success' => $success,
        ]);
    }

    // ===== 重置密码 =====

    public function resetPassword(string $token)
    {
        if ($this->isLoggedIn()) {
            $this->redirect('/');
        }

        $error = '';
        $success = '';

        self::ensurePasswordResetsTable();
        $db = Database::getInstance();
        $hashedToken = hash('sha256', $token);

        // 查找有效 token（时间比较用 PHP time 参数，不依赖 NOW()）
        $row = $db->fetchOne(
            "SELECT pr.*, u.username FROM password_resets pr JOIN users u ON pr.user_id = u.id
             WHERE pr.token = :token AND pr.used = 0 AND pr.expires_at > :now",
            [':token' => $hashedToken, ':now' => date('Y-m-d H:i:s')]
        );

        if (!$row) {
            $error = \app\Helpers\I18n::get('auth.reset_reapply');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');

            $password = (string)($_POST['password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if (strlen($password) < 6) {
                $error = \app\Helpers\I18n::get('auth.password_too_short', ['min' => 6]);
            } elseif (!preg_match('/[a-zA-Z]/', $password)) {
                $error = \app\Helpers\I18n::get('auth.password_need_letter');
            } elseif (!preg_match('/\d/', $password)) {
                $error = \app\Helpers\I18n::get('auth.password_need_number');
            } elseif ($password !== $confirmPassword) {
                $error = \app\Helpers\I18n::get('auth.password_mismatch');
            } else {
                $userModel = new User();
                $userModel->updatePassword((int)$row['user_id'], $password);

                $db->query("UPDATE password_resets SET used = 1 WHERE id = :id", [':id' => (int)$row['id']]);
                // 清除已过期/已使用的 token（每次重置时顺便清理）
                $db->query("DELETE FROM password_resets WHERE expires_at < :now OR used = 1", [':now' => date('Y-m-d H:i:s')]);

                // 吊销该用户全部记住登录 token（安全考虑：重置密码 = 全端登出）
                RememberMe::clearTokens((int)$row['user_id']);

                $success = \app\Helpers\I18n::get('auth.reset_success');
            }
        }

        $this->view('auth/reset_password', [
            'error' => $error,
            'success' => $success,
            'tokenValid' => !$error || $success,
            'token' => $token,
        ]);
    }

    /**
     * 确保 password_resets 表存在
     * 表由 Schema::bootstrap 统一创建（business.sqlite），此处幂等探测
     */
    private static function ensurePasswordResetsTable(): void
    {
        try {
            $db = Database::getInstance();
            $db->fetchOne('SELECT COUNT(*) as cnt FROM password_resets');
        } catch (\Throwable $e) {
            \error_log('password_resets 表探测失败（Schema 应已建表）: ' . $e->getMessage());
        }
    }

    // ===== 邮箱验证 =====

    public function verifyEmail(string $token)
    {
        $db = Database::getInstance();
        self::ensureEmailVerifyColumns();

        $hashedToken = hash('sha256', $token);
        // 时间过期校验：验证 Token 创建时间在 24 小时内（PHP 计算截止时间）
        $cutoff = date('Y-m-d H:i:s', time() - 86400);
        $row = $db->fetchOne(
            "SELECT u.id, u.created_at FROM users u WHERE u.email_verify_token = :token AND u.email_verified = 0
             AND u.email_verify_token_sent_at IS NOT NULL
             AND u.email_verify_token_sent_at > :cutoff",
            [':token' => $hashedToken, ':cutoff' => $cutoff]
        );

        if (!$row) {
            $this->view('auth/message', [
                'title' => \app\Helpers\I18n::get('auth.verify_invalid_title'),
                'message' => \app\Helpers\I18n::get('auth.verify_invalid_msg'),
            ]);
            return;
        }

        $userModel = new User();
        $userModel->markEmailVerified((int)$row['id']);
        $this->redirect('/login?verified=1');
    }

    // ===== 重新发送验证邮件 =====

    public function resendVerification()
    {
        // 只接受 POST，添加 CSRF 校验 + 频率限制
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/login');
        }
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        RateLimiter::hitConfig('resend_verify', 3, 3600); // 每小时最多3次（阈值后台可配）

        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->redirect('/login');
        }

        $userModel = new User();
        $user = $userModel->findByEmail($email);

        if ($user && empty($user['email_verified'])) {
            self::ensureEmailVerifyColumns();
            $token = \bin2hex(\random_bytes(32));
            $userModel->setVerifyToken((int)$user['id'], $token);
            // 使用 SITE_URL 防 Host 头注入
            $verifyLink = rtrim(SITE_URL, '/') . '/verify-email/' . $token;
            $siteName = defined('DEFAULT_SITE_NAME') ? DEFAULT_SITE_NAME : 'FlintHub';
            $subject = "=?UTF-8?B?" . \base64_encode(\app\Helpers\I18n::get('mail.verify_subject')) . "?=";
            $body = \app\Helpers\I18n::get('mail.verify_greeting', ['username' => $user['username']]) . "\n\n"
                  . \app\Helpers\I18n::get('mail.register_thanks', ['site' => $siteName]) . "\n\n"
                  . \app\Helpers\I18n::get('mail.verify_body') . "\n"
                  . "{$verifyLink}\n\n"
                  . \app\Helpers\I18n::get('mail.copy_hint') . "\n\n"
                  . \app\Helpers\I18n::get('mail.ignore_hint', ['site' => $siteName]) . "\n\n"
                  . "—— {$siteName} " . \app\Helpers\I18n::get('mail.team');
            \app\Helpers\Mailer::send($email, $subject, $body);
        }

        $this->view('auth/message', [
            'title' => \app\Helpers\I18n::get('auth.verify_sent_title'),
            'message' => \app\Helpers\I18n::get('auth.verify_sent_msg'),
        ]);
    }

    /**
     * 确保 users 表有 email_verified 和 email_verify_token 列
     */
    private static function ensureEmailVerifyColumns(): void
    {
        try {
            $db = Database::getInstance();
            $db->query("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) DEFAULT 0");
        } catch (\Throwable $e) {
            // 列已存在时忽略
        }
        try {
            $db = Database::getInstance();
            $db->query("ALTER TABLE users ADD COLUMN email_verify_token VARCHAR(64) DEFAULT NULL");
        } catch (\Throwable $e) {
            // 列已存在时忽略
        }
        try {
            $db = Database::getInstance();
            $db->query("ALTER TABLE users ADD COLUMN email_verify_token_sent_at DATETIME DEFAULT NULL");
        } catch (\Throwable $e) {
            // 列已存在时忽略
        }
    }

    private function setRememberCookie($userId)
    {
        $token = bin2hex(random_bytes(32));
        try {
            // 追加 token 到该用户的多端 token 数组（不覆盖其他端）
            RememberMe::appendToken((int)$userId, $token);
            $cookieLifetime = 86400 * 30;
            $cookieExpire = time() + $cookieLifetime;
            $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
            setcookie('remember_token', $token, ['expires' => $cookieExpire, 'path' => '/', 'httponly' => true, 'secure' => $secure, 'samesite' => 'Lax']);
            setcookie('remember_user_id', $userId, ['expires' => $cookieExpire, 'path' => '/', 'httponly' => true, 'secure' => $secure, 'samesite' => 'Lax']);
        } catch (\Exception $e) {
            \error_log('Remember me error: ' . $e->getMessage());
        }
    }
}
