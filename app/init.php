<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 应用初始化 — 自动加载、配置、Session、插件、维护模式
 * @file app/init.php
 * @package app
 */

// 1. 自动加载
require_once __DIR__ . '/Core/Autoloader.php';

// 2. 加载配置（含 session 路径设置）
require_once __DIR__ . '/../config.php';

// 3. Session 存储方式：优先使用 MySQL（sessions 表），表不存在时回退到文件存储
if (PHP_SAPI !== 'cli') {
    // ★ Gzip 输出压缩（后台"基本信息"页开关，默认关闭；表未初始化时静默跳过）
    try {
        if (\app\Helpers\Settings::get('enable_gzip') === '1') {
            ob_start('ob_gzhandler');
        }
    } catch (\Throwable $e) {
        // Settings 尚未初始化时静默忽略
    }
    try {
        $db = \app\Core\Database::getInstance();
        // [SplitDB] Session 表由 Schema::bootstrap 在 sessions.sqlite 幂等创建，无需 SHOW TABLES 探测
        // ★ 原 MySQL 探测逻辑（恢复时参考）：
        // $sessionTableCache = __DIR__ . '/../protected/.sessions_table_cache';
        // if (file_exists($sessionTableCache) && file_get_contents($sessionTableCache) === '1') {
        //     $tableCheck = true;
        // } elseif ($db->fetchOne("SHOW TABLES LIKE 'sessions'")) {
        //     file_put_contents($sessionTableCache, '1', LOCK_EX);
        //     $tableCheck = true;
        // } else {
        //     $tableCheck = false;
        // }
        $tableCheck = true;
        if ($tableCheck) {
            // ★ Session 使用独立 PDO 连接，与业务连接物理隔离，避免 2014 游标冲突
            session_set_save_handler(
                // open
                function () { return true; },
                // close
                function () { return true; },
                // read
                function ($id) {
                    try {
                        $conn = \app\Core\Database::getSessionConnection();
                        // [SplitDB] SQLite：expires 存秒级时间戳，与 PHP time() 比较
                        $stmt = $conn->prepare("SELECT data FROM sessions WHERE id = :id AND expires > :now");
                        $stmt->execute([':id' => $id, ':now' => time()]);
                        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
                        $stmt->closeCursor();
                        return $r ? $r['data'] : '';
                    } catch (\Throwable $e) {
                        return '';
                    }
                },
                // write
                function ($id, $data) {
                    try {
                        $conn = \app\Core\Database::getSessionConnection();
                        // [SplitDB] SQLite UPSERT（ON CONFLICT 兼容 3.33）；expires = time() + 1800（30 分钟）
                        $stmt = $conn->prepare(
                            "INSERT INTO sessions (id, data, expires) VALUES (:id, :data, :expires)
                             ON CONFLICT(id) DO UPDATE SET data = :data2, expires = :expires2"
                        );
                        $stmt->execute([
                            ':id' => $id, ':data' => $data, ':expires' => time() + 1800,
                            ':data2' => $data, ':expires2' => time() + 1800,
                        ]);
                        $stmt->closeCursor();
                        return true;
                    } catch (\Throwable $e) {
                        \error_log('Session write error: ' . $e->getMessage());
                        return false;
                    }
                },
                // destroy
                function ($id) {
                    try {
                        $conn = \app\Core\Database::getSessionConnection();
                        $stmt = $conn->prepare("DELETE FROM sessions WHERE id = :id");
                        $stmt->execute([':id' => $id]);
                        $stmt->closeCursor();
                        return true;
                    } catch (\Throwable $e) {
                        return false;
                    }
                },
                // gc
                function ($maxLifetime) {
                    try {
                        $conn = \app\Core\Database::getSessionConnection();
                        // [SplitDB] SQLite：expires 秒级时间戳与 PHP time() 比较
                        $stmt = $conn->prepare("DELETE FROM sessions WHERE expires < :now");
                        $stmt->execute([':now' => time()]);
                        $count = $stmt->rowCount();
                        $stmt->closeCursor();
                        return $count;
                    } catch (\Throwable $e) {
                        return 0;
                    }
                }
            );
        }
    } catch (\Throwable $e) {
        // DB 不可用时回退到文件 Session，无需处理
    }
}

// 4. 启动 Session
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_start();
}

// 4. [i18n] 语言初始化：?lang= 切换（PRG 去参回跳，防刷新丢参/重复切换）→ 探测并预热当前语言
if (PHP_SAPI !== 'cli') {
    try {
        $langParam = isset($_GET['lang']) ? (string)$_GET['lang'] : '';
        if ($langParam !== ''
            && preg_match('/^[a-z][a-z0-9_-]*$/', $langParam)
            && in_array($langParam, \app\Helpers\I18n::available(), true)) {
            \app\Helpers\I18n::set($langParam);
            // PRG：去掉 lang 参数回原页（保其他查询参数）
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            $clean = (string)preg_replace('/([?&])lang=[^&]*&?/', '$1', $uri);
            $clean = rtrim($clean, '?&');
            // 防开放重定向：回跳仅放行「单个 / 开头的同站相对路径」，拒绝 // 协议相对与反斜杠
            if ($clean === '' || !\str_starts_with($clean, '/') || \str_starts_with($clean, '//') || \strpos($clean, '\\') !== false) {
                $clean = '/';
            }
            \header('Location: ' . ($clean !== '' ? $clean : '/'));
            exit;
        }
        \app\Helpers\I18n::current(); // 预热探测（Cookie/Session/后台设置/浏览器）
    } catch (\Throwable $e) {
        // 语言初始化失败不阻塞框架引导
    }
}

// 4. 请求级初始化：自动登录恢复 + 在线状态更新（确保 AJAX/API 等非视图请求也能触发）
if (PHP_SAPI !== 'cli') {
    \app\Helpers\RememberMe::check();
    try {
        \app\Helpers\Settings::updateOnlineStatus();
    } catch (\Throwable $e) {
        // Settings 尚未初始化时静默忽略
    }
}

// 4. 创建模板引擎实例并注入到全局
$GLOBALS['__view'] = new \app\Core\TemplateCompiler();

// 5. 初始化插件系统
\app\Helpers\Plugin::init();

// 6. 执行应用初始化钩子
\app\Helpers\Plugin::hook('init_after');

// 6.5. 从 settings 表覆盖 BASE_PATH（后台配置优先于 config.php）
if (PHP_SAPI !== 'cli' && !\defined('BASE_PATH_SET')) {
    try {
        $bp = \app\Helpers\Settings::get('base_path', '');
        if ($bp !== '') {
            \define('BASE_PATH', $bp);
            \define('BASE_PATH_SET', true);
        }
    } catch (\Throwable $e) {
        // 表尚未初始化时静默忽略
    }
}

// 7. 站点维护模式检查（非 CLI、非管理员、非登录/后台路径时拦截）
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    try {
        $closed = \app\Helpers\Settings::get('site_closed');
        if ($closed === '1') {
            $uri  = $_SERVER['REQUEST_URI'] ?? '';
            $path = parse_url($uri, PHP_URL_PATH) ?: '';
            // ★ [2026-08-21] 二级目录支持：维护模式白名单判断前先剥离 BASE_PATH，
            //   否则 /flinthub/admin 等路径无法命中 /admin 白名单（维护模式会误拦截后台/登录）
            $base = \defined('BASE_PATH') ? \BASE_PATH : '';
            if ($base !== '' && \strpos($path, $base) === 0) {
                $path = \substr($path, \strlen($base));
                if ($path === '') $path = '/';
            }

            // 允许通过的路径：后台、登录、注册、忘记密码、API
            // ★ [审计修复 2026-08-20] 拆分"精确匹配"与"前缀匹配"：
            //   /admin_test、/api_v2 等畸形路径不再被 strncmp 前缀误放行
            $exactAllowed = ['/admin', '/login', '/register', '/logout'];
            $prefixAllowed = ['/admin/', '/api/'];
            $pass = in_array($path, $exactAllowed, true);
            if (!$pass) {
                foreach ($prefixAllowed as $prefix) {
                    if (strncmp($path, $prefix, strlen($prefix)) === 0) { $pass = true; break; }
                }
            }

            // 管理员已登录 → 跳过维护模式，前台后台都能正常访问
            if (\app\Helpers\Auth::isAdmin()) {
                // pass
            } elseif (!$pass) {
                http_response_code(503);
                $siteName = \app\Helpers\Settings::get('site_name', \DEFAULT_SITE_NAME);
                $basePath = defined('BASE_PATH') ? BASE_PATH : '';
                // 不要依赖模板引擎，用纯 HTML（维护页需在模板引擎故障时仍可用）
                echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . ' - 维护中</title>
<link rel="stylesheet" href="' . htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') . '/assets/css/fontawesome.css"><style>
:root{--brand-1:#3b82f6;--brand-2:#0ea5e9;--ink-1:#1e293b;--ink-2:#475569;--ink-3:#94a3b8;--glass:rgba(255,255,255,.72);--line:rgba(59,130,246,.16)}
*{margin:0;padding:0;box-sizing:border-box}
html,body{height:100%}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue","PingFang SC","Microsoft YaHei",sans-serif;color:var(--ink-1);background:linear-gradient(135deg,#eff6ff,#dbeafe);display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px;position:relative;overflow:hidden;-webkit-font-smoothing:antialiased}
body::before,body::after{content:"";position:fixed;border-radius:50%;filter:blur(90px);z-index:0;pointer-events:none}
body::before{width:480px;height:480px;top:-160px;left:-120px;background:rgba(59,130,246,.2)}
body::after{width:420px;height:420px;bottom:-140px;right:-100px;background:rgba(14,165,233,.16)}
.wrap{position:relative;z-index:1;width:100%;max-width:480px}
.card{background:var(--glass);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border:1px solid var(--line);border-radius:28px;padding:52px 44px 40px;text-align:center;box-shadow:0 24px 64px rgba(59,130,246,.16),inset 0 1px 0 rgba(255,255,255,.9);animation:rise .8s cubic-bezier(.22,.68,.32,1.2) both}
.icon-badge{width:104px;height:104px;margin:0 auto 26px;border-radius:34px;display:flex;align-items:center;justify-content:center;font-size:44px;color:#fff;background:linear-gradient(135deg,var(--brand-1),var(--brand-2));box-shadow:0 14px 36px rgba(59,130,246,.35),inset 0 1px 0 rgba(255,255,255,.3);animation:breathe 3s ease-in-out infinite}
.status-pill{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:999px;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.3);color:#3b82f6;font-size:12px;font-weight:600;letter-spacing:.6px}
.status-dot{width:7px;height:7px;border-radius:50%;background:#22c55e;animation:pulse 1.8s ease-in-out infinite}
h1{font-size:25px;font-weight:700;margin:22px 0 10px;color:var(--ink-1);letter-spacing:-.3px}
p{font-size:14.5px;line-height:1.9;color:var(--ink-2)}
.btn{display:inline-flex;align-items:center;gap:9px;margin-top:30px;padding:12px 30px;border-radius:13px;background:linear-gradient(135deg,var(--brand-1),var(--brand-2));color:#fff;text-decoration:none;font-size:14.5px;font-weight:600;box-shadow:0 10px 28px rgba(59,130,246,.3);transition:transform .2s ease,box-shadow .2s ease}
.btn:hover{transform:translateY(-2px);box-shadow:0 14px 36px rgba(59,130,246,.42)}
.footer{position:fixed;bottom:22px;left:0;right:0;text-align:center;font-size:12px;color:var(--ink-3);z-index:1}
.footer .fa{margin-right:4px}
@keyframes rise{from{opacity:0;transform:translateY(22px)}to{opacity:1;transform:none}}
@keyframes breathe{0%,100%{transform:scale(1)}50%{transform:scale(1.05)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
@media (max-width:520px){.card{padding:44px 24px 36px;border-radius:22px}.icon-badge{width:88px;height:88px;font-size:36px;border-radius:28px}h1{font-size:21px}p{font-size:13.5px}}
</style></head><body>
<div class="wrap"><div class="card">
  <div class="icon-badge"><i class="fa">&#xf0ad;</i></div>
  <span class="status-pill"><span class="status-dot"></span>系统维护中</span>
  <h1>我们正在努力升级中</h1>
  <p>' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . ' 正在进行维护升级<br>短暂离开，是为了更好的相遇。<br>请稍后再来访问，感谢您的理解与支持。</p>
  <a class="btn" href="' . htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') . '/login"><i class="fa">&#xf090;</i> 管理员登录</a>
</div></div>
<div class="footer"><i class="fa">&#xf015;</i>' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</div>
</body></html>';
                exit;
            }
        }
    } catch (\Throwable $e) {
        // Settings 尚未初始化（如首次安装），忽略
    }
}
