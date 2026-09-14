<?php
/**
 * FlintHub 1.0 — 轻量级 PHP 社区系统（SplitDB 纯 SQLite 分片引擎）
 * Web 安装程序 — 环境检测 / SplitDB 初始化 / 管理员创建 分步向导
 * @file install.php
 * @package FlintHub
 */

// ============================================================
// 0. 基础防护
// ============================================================
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '1');

define('ROOT_DIR', __DIR__);
define('INSTALL_DONE_FILE', ROOT_DIR . '/protected/.install_done');

// 版本号统一由 version.php 定义（install 阶段 config.php 尚不存在，须直接引用 version.php）
require_once __DIR__ . '/version.php';

// 已安装则拒绝再次运行（防覆盖现有站点）
// 判定依据：config.php 已含 SplitDB 配置，或 data/meta/business.sqlite 已存在。
// （不依赖 .install_done 标记——标记缺失但库已建的情况同样拒绝，杜绝二次初始化覆盖数据）
$installDetected = false;
$cfgPath = ROOT_DIR . '/config.php';
if (is_file($cfgPath)) {
    $cfgContent = @file_get_contents($cfgPath);
    if ($cfgContent !== false && strpos($cfgContent, 'SPLITDB_DATA_PATH') !== false) {
        $installDetected = true;
    }
}
if (!$installDetected && is_file(ROOT_DIR . '/data/meta/business.sqlite')) {
    $installDetected = true;
}
if ($installDetected) {
    renderAlreadyInstalled();
    exit;
}

// 加固 Session 安全参数（与 config.php 同风格：HttpOnly / SameSite / Strict Mode）
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
$installSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
ini_set('session.cookie_secure', $installSecure ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');

session_start();
if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(16));
}

$csrf = $_SESSION['install_csrf'];

// ============================================================
// 1. 工具函数
// ============================================================

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/**
 * 安装页基础路径（二级目录部署支持，如 /flinthub；根目录部署返回 ''）
 * 基于 SCRIPT_NAME 推导：/flinthub/install.php → /flinthub
 */
function installBasePath(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return ($base === '/' || $base === '.') ? '' : $base;
}

/** 环境检测：返回 [name, ok, detail] 列表 */
function detectEnvironment(): array {
    $checks = [];
    $checks[] = ['PHP 版本 ≥ 8.0', version_compare(PHP_VERSION, '8.0.0', '>='),
        '当前 ' . PHP_VERSION . (version_compare(PHP_VERSION, '8.0.0', '>=') ? '（推荐 8.2+）' : '（过低，请升级）')];
    $checks[] = ['PDO 扩展', extension_loaded('pdo'), extension_loaded('pdo') ? '已加载' : '未加载'];
    $checks[] = ['PDO SQLite 驱动', extension_loaded('pdo_sqlite'), extension_loaded('pdo_sqlite') ? '已加载' : '未加载'];
    // SQLite 实际版本检测（兼容基线 3.33：ON CONFLICT/WAL/无 RETURNING 兼容均依赖版本）
    $sqliteVer = '';
    $sqliteOk = false;
    if (extension_loaded('pdo_sqlite')) {
        try {
            $sqlitePdo = new \PDO('sqlite::memory:');
            $sqliteVer = (string)$sqlitePdo->query('SELECT sqlite_version()')->fetchColumn();
            $sqliteOk = version_compare($sqliteVer, '3.33.0', '>=');
        } catch (\Throwable $e) {
            $sqliteVer = '';
        }
    }
    $checks[] = ['SQLite 版本 ≥ 3.33', $sqliteOk,
        $sqliteVer !== '' ? '当前 ' . $sqliteVer . ($sqliteOk ? '' : '（过低，请升级）') : '无法获取（PDO SQLite 不可用）'];
    $checks[] = ['mbstring（中文处理）', extension_loaded('mbstring'), extension_loaded('mbstring') ? '已加载' : '未加载'];
    $checks[] = ['fileinfo（上传 MIME 检测）', extension_loaded('fileinfo'), extension_loaded('fileinfo') ? '已加载' : '未加载'];
    $checks[] = ['curl（远程拉图）', extension_loaded('curl'), extension_loaded('curl') ? '已加载' : '未加载'];
    $checks[] = ['图像处理 gd 或 imagick', extension_loaded('gd') || extension_loaded('imagick'),
        extension_loaded('gd') ? 'gd 已加载' : (extension_loaded('imagick') ? 'imagick 已加载' : '未加载')];
    $checks[] = ['openssl（邮件加密）', extension_loaded('openssl'), extension_loaded('openssl') ? '已加载' : '未加载'];
    $checks[] = ['JSON', extension_loaded('json'), extension_loaded('json') ? '已加载' : '未加载'];

    $dirs = [
        'config.php（可写）' => ROOT_DIR . '/config.php',
        'data（SplitDB 数据目录）' => ROOT_DIR . '/data',
        'protected/sessions' => ROOT_DIR . '/protected/sessions',
        'protected/cache'    => ROOT_DIR . '/protected/cache',
        'protected/rate_cache' => ROOT_DIR . '/protected/rate_cache',
        'assets/uploads'     => ROOT_DIR . '/assets/uploads',
    ];
    foreach ($dirs as $label => $path) {
        // 目标目录/文件可能尚未创建（全新安装时），向上递归找到最近的已存在父目录检测可写性
        $target = $path;
        while (!file_exists($target) && $target !== dirname($target)) {
            $target = dirname($target);
        }
        $writable = is_writable($target);
        if ($target !== $path) {
            // 区分文件/目录两类提示：文件不存在时说明将检测父目录以支持创建
            $hint = is_dir($path) ? "（检测上级目录 {$target}）" : "（文件尚不存在，将检测父目录 {$target} 是否可写）";
        } else {
            $hint = '';
        }
        $checks[] = [$label, $writable, $writable ? '可写' . $hint : '不可写' . $hint . '（请检查目录权限）'];
    }
    return $checks;
}

function envAllOk(array $checks): bool {
    foreach ($checks as $c) { if (!$c[1]) return false; }
    return true;
}

/** 写入 config.php（SplitDB 模板；仅全新安装时创建，拒绝升级旧配置） */
function writeConfig(): array {
    $file = ROOT_DIR . '/config.php';

    // 不再支持"旧 MySQL 配置升级为 SplitDB"：config.php 已存在一律拒绝覆盖
    // （顶部安装判定已确认 config.php 不含 SplitDB 配置、business.sqlite 不存在，此处双保险）
    if (file_exists($file)) {
        return ['ok' => false, 'msg' => 'config.php 已存在（非 SplitDB 配置），已停止安装。请先备份并移除该文件后重试'];
    }
    if (!is_writable(dirname($file))) {
        return ['ok' => false, 'msg' => '根目录不可写，无法创建 config.php，请检查目录权限'];
    }
    // MAIL_PASS_KEY 不再内置固定值：写入前用随机 32 字节替换占位符（bin2hex → 64 字符）
    $template = str_replace('__FLINTHUB_MAIL_PASS_KEY__', bin2hex(random_bytes(32)), configTemplate());
    if (@file_put_contents($file, $template) === false) {
        return ['ok' => false, 'msg' => '创建 config.php 失败，请检查目录权限'];
    }
    // 立即失效 OPCache：防御共享缓存持有旧版本
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($file, true);
    }
    return ['ok' => true, 'msg' => 'config.php 已自动生成（SplitDB）'];
}

/** config.php 内置模板（SplitDB：纯 SQLite 分片，无任何 MySQL 配置项） */
function configTemplate(): string {
    return <<<'TPL'
<?php
/**
 * FlintHub 1.0 — 轻量级 PHP 社区系统（SplitDB 纯 SQLite 分片引擎）
 * 全局配置 — 数据目录、上传、邮件、主题、Session
 * @file config.php
 * @package FlintHub
 */
// config.php — FlintHub 1.0 (SplitDB) 配置
// 版本号统一由 version.php 定义（单一版本源，升版只改 version.php）
require_once __DIR__ . '/version.php';

// ========== SplitDB 数据引擎（纯 SQLite 分片，白皮书 v2.3） ==========
define('SPLITDB_DATA_PATH', __DIR__ . '/data');     // 数据根目录（meta/bucket/extern/...）
define('SPLITDB_BUCKET_SIZE', 32);                  // 每季度哈希桶数量；未扩容时写入路由 = ID % 桶数量（可平滑升级 64/128）
// 智能扩容记录：扩容时间戳（0=从未扩容）与扩容前桶数。
// 路由规则：扩容后新帖（创建时间 ≥ 扩容时间戳）全部进入「新增桶编号范围」（如 32→64 后进桶 32..63），
// 旧桶冻结不再接收新帖（回复仍进旧桶）；读取始终以 main_index.bucket_path 为准，旧帖不受影响。
define('SPLITDB_LAST_EXPANSION_AT', 0);
define('SPLITDB_PREV_BUCKET_SIZE', 32);

// ========== 代码仓库插件（code_repo） ==========
define('CODE_REPO_ROOT', __DIR__ . '/plugins/code_repo/data/repos');  // 仓库根目录（每个子目录 = 一个仓库；插件后台设置可覆盖）

define('UPLOAD_PATH', __DIR__ . '/assets/uploads/');
define('UPLOAD_URL', '/assets/uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024);
define('ALLOWED_EXTENSIONS', ['jpg','jpeg','png','gif','webp','pdf','zip','doc','docx']);
// ========== 邮件发送 (SMTP) ==========
define('MAIL_DRIVER', 'smtp');           // smtp / mail (PHP mail() 函数)
define('MAIL_HOST', 'smtp.qq.com');      // SMTP 服务器
define('MAIL_PORT', 465);                 // 465(SSL) / 587(TLS) / 25
define('MAIL_USER', '');                  // SMTP 用户名（邮箱地址）
define('MAIL_PASS', '');                  // SMTP 密码或授权码
define('MAIL_PASS_KEY', '__FLINTHUB_MAIL_PASS_KEY__'); // SMTP 密码加密密钥（AES-256-CBC，至少 32 字符）——安装时随机生成
define('MAIL_ENCRYPTION', 'ssl');         // ssl / tls / null
define('MAIL_FROM_ADDR', '');             // 发件人地址（通常同 MAIL_USER）
define('MAIL_FROM_NAME', 'FlintHub 论坛');  // 发件人名称

define('DEFAULT_SITE_NAME', 'FlintHub 精品论坛');
// 单模板体系：默认主题固定为 modern（配色变体 modern-* 仍可在后台切换）
define('DEFAULT_THEME', 'modern');
define('POSTS_PER_PAGE', 20);
define('THREADS_PER_PAGE', 20);
date_default_timezone_set('Asia/Shanghai');
if (!ini_get('default_charset')) ini_set('default_charset','UTF-8');
if (!is_dir(UPLOAD_PATH)) @mkdir(UPLOAD_PATH, 0755, true);
ini_set('session.use_only_cookies',1);
ini_set('session.cookie_httponly',1);
ini_set('session.use_strict_mode',1);  // 拒绝客户端未初始化的 Session ID（配合 session_regenerate_id 防 Session Fixation）
$sessionPath = __DIR__ . '/protected/sessions';
if (!is_dir($sessionPath)) @mkdir($sessionPath, 0755, true);
if (is_dir($sessionPath) && is_writable($sessionPath)) {
    ini_set('session.save_path', $sessionPath);
    // 会话 GC（文件 sess_* + SQLite sessions.sqlite 过期清理）已迁移至 cron_trigger.php 低频维护
    // 执行（300s 节流），卸载热路径：不再每次请求扫描过期会话/检查节流时间戳。
}
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)$_SERVER['SERVER_PORT'] === 443;
ini_set('session.cookie_secure', $isSecure ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');

// 站点域名（用于邮件链接等，优先 SERVER_NAME，回退到 HTTP_HOST）
// 部署在反向代理/CDN 之后时，SERVER_NAME/HTTP_HOST 可能被伪造 Host 头污染，
// 导致邮件中的验证/重置链接指向恶意域名（钓鱼）。两种覆盖方式（优先级从高到低）：
//   ① 环境变量 FLINTHUB_SITE_URL（Nginx/Apache 在 fastcgi 参数或环境注入，适合容器/面板）；
//   ② 在 config.php 中于本行前手工 define('SITE_URL_OVERRIDE', 'https://你的域名')。
// 未设置时保持原 SERVER_NAME 优先逻辑（默认部署下 Nginx server_name 受控，无风险）。
$siteUrlOverride = \getenv('FLINTHUB_SITE_URL');
if (!\is_string($siteUrlOverride) || $siteUrlOverride === '') {
    $siteUrlOverride = \defined('SITE_URL_OVERRIDE') ? (string)\SITE_URL_OVERRIDE : '';
}
if ($siteUrlOverride !== '') {
    define('SITE_URL', \rtrim($siteUrlOverride, '/'));
} else {
    define('SITE_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . ($_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost'));
}

// cron_trigger.php 触发密钥（可选）。留空 = 不启用密钥校验（兼容旧部署）；
// 设置后 cron_trigger.php 必须携带 ?key=CRON_KEY 才执行，防匿名反复触发队列消费。
// 建议虚拟主机 Cron 触发时设置为强随机串，如：define('CRON_KEY', bin2hex(random_bytes(16)));
define('CRON_KEY', '');

// 站点部署路径（二级目录支持，如 /flinthub；根目录留空）
define('BASE_PATH', '');

// 错误日志保存到文件（方便排查问题）
ini_set('error_log', __DIR__ . '/protected/error.log');
TPL;
}

/** 执行 SplitDB 初始化 + 创建管理员 */
function runInstall(array $cfg): array {
    // 安装锁：防并发双请求同时初始化（flock 非阻塞，拿不到锁立即拒绝）
    $lockFile = ROOT_DIR . '/protected/install.lock';
    $lockDir = dirname($lockFile);
    if (!is_dir($lockDir)) @mkdir($lockDir, 0755, true);
    $lockHandle = @fopen($lockFile, 'c');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        if ($lockHandle !== false) @fclose($lockHandle);
        return ['ok' => false, 'msg' => '另一个安装任务正在执行，请稍后重试'];
    }

    try {
        // 关闭安装会话：config.php 内含 session.* 的 ini_set（session.save_path / cookie 参数等），
        // 若会话仍处于活动状态会触发 "Session ini settings cannot be changed when a session is active" 警告
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        // 1) 写 config.php（SplitDB 模板）
        $r = writeConfig();
        if (!$r['ok']) return $r;

        // 2) 加载框架核心（配置 + 自动加载）
        require_once ROOT_DIR . '/config.php';
        require_once ROOT_DIR . '/app/Core/Autoloader.php';

        // 3) SplitDB 建库骨架（目录树 + 全部 meta 库 + 表，幂等）+ 默认数据种子
        try {
            \app\SplitDB\Schema::bootstrap();
            \app\SplitDB\Schema::seedDefaults();
        } catch (\Throwable $ex) {
            return ['ok' => false, 'msg' => 'SplitDB 初始化失败：' . $ex->getMessage()];
        }

        // 4) 创建管理员账号
        $db = \app\Core\Database::getInstance();
        $now = date('Y-m-d H:i:s');
        $username = trim($cfg['admin_user']);
        $email    = trim($cfg['admin_email']);
        $passHash = password_hash($cfg['admin_pass'], PASSWORD_DEFAULT);

        $existing = $db->fetchOne('SELECT id FROM users WHERE username = :u LIMIT 1', [':u' => $username]);
        if ($existing) {
            // 纯净安装不应存在同名用户：不再"更新密码/角色"静默覆盖，
            // 直接停止安装，提示数据目录可能并非纯净环境（防误装覆盖既有站点数据）
            return ['ok' => false, 'msg' => '检测到同名用户，当前数据目录可能并非纯净安装环境，已停止安装。'];
        }
        // level 取值须匹配 level_config 实际档位（1~11 级，11 = 社区元老，Points::calculateLevel 按积分回算）：
        // points=9999 对应最高档 level=11；旧写法写 99 超档位，导致 getLevelConfig(99) 查不到配置、升级判定永不触发
        $db->query(
            'INSERT INTO users (username, password, email, role, status, group_id, level, points, created_at, last_active_at)
             VALUES (:u, :p, :e, :role, :s, 4, 11, 9999, :created_at, :last_active_at)',
            [
                ':u' => $username, ':p' => $passHash, ':e' => $email,
                ':role' => 'admin', ':s' => 'active',
                ':created_at' => $now, ':last_active_at' => $now,
            ]
        );

        // 管理员默认头像：与注册流程（AuthController）一致，分配确定性 seed SVG 头像
        // （assets/uploads/seed_avatars/{id}.svg，User::defaultAvatarPath），避免新管理员缺少头像
        $adminId = (int)$db->lastInsertId();
        $adminAvatar = \app\Models\User::defaultAvatarPath($adminId, $username);
        $db->query('UPDATE users SET avatar = :avatar WHERE id = :id', [':avatar' => $adminAvatar, ':id' => $adminId]);

        // 5) 写入安装完成标记（原子写入 tmp+rename，并检查返回值）
        $doneDir = dirname(INSTALL_DONE_FILE);
        if (!is_dir($doneDir)) @mkdir($doneDir, 0755, true);
        $doneTmp = INSTALL_DONE_FILE . '.tmp.' . bin2hex(random_bytes(4));
        $doneOk = @file_put_contents($doneTmp, date('Y-m-d H:i:s'), LOCK_EX) !== false;
        if ($doneOk) {
            $doneOk = @rename($doneTmp, INSTALL_DONE_FILE);
        }
        if (!$doneOk) {
            @unlink($doneTmp);
            return ['ok' => false, 'msg' => '写入安装完成标记失败，请检查 protected/ 目录权限'];
        }

        return ['ok' => true, 'msg' => '安装完成'];
    } finally {
        // 无论成功失败均释放安装锁
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
}

function renderAlreadyInstalled() {
    // 已安装后本页返回 410 Gone（原 200 + 泄露 protected/.install_done 内部路径），
    // 不向匿名访问者暴露任何内部文件路径信息；页面保持极简。
    http_response_code(410);
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">
        <title>已安装 - FlintHub</title>
        <style>
            body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f7f8fa;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;color:#333}
            .box{max-width:420px;margin:20px;background:#fff;border-radius:14px;padding:40px 36px;text-align:center;box-shadow:0 2px 20px rgba(0,0,0,.08)}
            h1{font-size:20px;margin:0 0 12px}
            p{font-size:14px;color:#888;line-height:1.8}
            .btn{display:inline-block;margin-top:18px;padding:10px 30px;background:#667eea;color:#fff;border-radius:6px;text-decoration:none;font-size:14px}
        </style>
    </head>
    <body><div class="box"><h1>FlintHub 已安装</h1><p>系统已完成安装，安装向导已停用。</p><a class="btn" href="<?php echo e(installBasePath() . '/'); ?>">进入站点</a></div></body>
    </html>
    <?php
}

function renderPage(string $title, callable $body) {
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title><?php echo e($title); ?> - FlintHub 安装向导</title>
<style>
    :root{ --mn-primary:#667eea; --mn-success:#16a34a; --mn-text:#222; --mn-text-muted:#5b6778; --mn-bg-body:#f8f9fb; --mn-bg-card:#fff; --mn-border:#e8eaee; --mn-border-light:#f0f1f3; }
    *{box-sizing:border-box}
    body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"PingFang SC","Microsoft YaHei",sans-serif;background:var(--mn-bg-body);color:var(--mn-text);margin:0;min-height:100vh}
    .install-wrap{max-width:760px;margin:0 auto;padding:36px 20px 60px}
    .install-header{text-align:center;margin-bottom:10px}
    .install-logo{font-size:26px;font-weight:700;letter-spacing:.5px}
    .mn-text-muted{color:var(--mn-text-muted)} .mn-text-error{color:#dc2626} .mn-text-success{color:var(--mn-success)} .mn-text-primary{color:var(--mn-primary)}
    .mn-fs-12{font-size:12px}.mn-fs-13{font-size:13px}.mn-fs-14{font-size:14px}.mn-fs-15{font-size:15px}.mn-fs-16{font-size:16px}.mn-fs-18{font-size:18px}.mn-fs-20{font-size:20px}
    .mn-fw-600{font-weight:600}.mn-mt-4{margin-top:4px}.mn-mt-6{margin-top:6px}.mn-mb-6{margin-bottom:6px}.mn-mb-8{margin-bottom:8px}.mn-mb-12{margin-bottom:12px}.mn-mb-14{margin-bottom:14px}.mn-mb-16{margin-bottom:16px}.mn-mb-20{margin-bottom:20px}.mn-mt-12{margin-top:12px}.mn-mt-14{margin-top:14px}.mn-mt-16{margin-top:16px}
    .mn-btn{display:inline-flex;align-items:center;gap:6px;padding:10px 22px;border:1px solid var(--mn-border);border-radius:8px;background:#fff;color:var(--mn-text);font-size:14px;cursor:pointer;text-decoration:none;transition:all .15s}
    .mn-btn:hover{border-color:var(--mn-primary);color:var(--mn-primary)}
    .mn-btn-primary{background:var(--mn-primary);border-color:var(--mn-primary);color:#fff}
    .mn-btn-primary:hover{opacity:.9;color:#fff}
    .mn-btn-sm{padding:6px 14px;font-size:13px}
    .mn-input{width:100%;padding:10px 12px;border:1px solid var(--mn-border);border-radius:8px;font-size:14px;background:#fff}
    .mn-input:focus{outline:none;border-color:var(--mn-primary)}
    .mn-label{display:block;margin-bottom:6px;font-size:13px;color:var(--mn-text-muted)}
    .mn-alert{padding:12px 14px;border-radius:8px;font-size:14px;margin-bottom:12px}
    .mn-alert-success{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0}
    .mn-alert-error{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
    .mn-alert-warning{background:#fffbeb;color:#b45309;border:1px solid #fde68a;text-align:left}
    .mn-flex-center{display:flex;align-items:center;justify-content:center;gap:10px}
    .mn-text-center{text-align:center}
    [x-cloak]{display:none!important}
    .install-steps{display:flex;justify-content:center;gap:6px;margin:20px 0 26px;flex-wrap:wrap}
    .istep{display:flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;font-size:13px;background:var(--mn-bg-card,#fff);border:1px solid var(--mn-border-light);color:var(--mn-text-muted)}
    .istep.on{background:var(--mn-primary);color:#fff;border-color:var(--mn-primary);font-weight:600}
    .istep.done{color:var(--mn-success);border-color:var(--mn-success)}
    .icheck-ok{color:#16a34a;font-weight:700}
    .icheck-bad{color:#dc2626;font-weight:700}
    .env-row{display:flex;justify-content:space-between;align-items:center;padding:10px 4px;border-bottom:1px dashed var(--mn-border-light,#f0f1f3);font-size:14px}
    .install-actions{display:flex;justify-content:space-between;margin-top:26px}
    .install-card{background:var(--mn-bg-card,#fff);border:1px solid var(--mn-border,#e8eaee);border-radius:14px;padding:24px;box-shadow:0 2px 10px rgba(0,0,0,.04)}
    .feature-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px;margin-top:14px}
    .feat{display:flex;gap:8px;align-items:flex-start;font-size:13px;color:var(--mn-text-secondary,#5b6778);padding:8px 10px;background:var(--mn-bg-body,#f8f9fb);border-radius:8px}
    .feat b{color:var(--mn-text,#222)}
    .nginx-snippet{background:#f6f7f9;border:1px solid #e8eaee;border-radius:6px;padding:8px 12px;font-family:Consolas,Menlo,monospace;font-size:12px;color:#374151;margin:8px 0 4px;overflow-x:auto}
    .license-box{background:#f6f7f9;border:1px solid #e8eaee;border-radius:8px;padding:12px 14px;font-family:Consolas,Menlo,monospace;font-size:12px;line-height:1.7;color:#374151;height:280px;overflow:auto;white-space:pre-wrap;word-break:break-word;margin-bottom:12px}
    .mn-check{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--mn-text-muted);user-select:none}
    .mn-check input[type=checkbox]{width:16px;height:16px;accent-color:var(--mn-primary);cursor:pointer}
</style>
</head>
<body>
<div class="install-wrap">
    <div class="install-header">
        <div class="install-logo"><?php echo '✦'; ?> FlintHub 安装向导</div>
        <p class="mn-text-muted mn-fs-13 mn-mt-4">轻量级 PHP 社区系统 · SplitDB（SQLite 分片）· <?php echo FLINTHUB_VERSION; ?></p>
    </div>
    <?php $body(); ?>
</div>
</body>
</html>
    <?php
}

// ============================================================
// 2. 请求分发
// ============================================================

$action = $_GET['action'] ?? ($_POST['action'] ?? 'welcome');

// 提交安装
if ($action === 'install' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // hash_equals 前强制检查 csrf 为字符串（防非字符串输入导致 TypeError）
    $postCsrf = $_POST['csrf'] ?? '';
    if (!is_string($postCsrf) || !hash_equals($csrf, $postCsrf)) {
        renderPage('安装', function () { ?>
            <div class="install-card mn-text-center">
                <p class="mn-text-error mn-fs-15">CSRF 校验失败，请返回重试。</p>
            </div>
        <?php });
        exit;
    }

    $checks = detectEnvironment();
    if (!envAllOk($checks)) {
        renderPage('安装', function () use ($checks) { ?>
            <div class="install-card">
                <h2 class="mn-fs-16 mn-fw-600 mn-mb-12">环境未就绪</h2>
                <p class="mn-text-muted mn-fs-13 mn-mb-16">以下检测项未通过，请先修复环境后再安装：</p>
                <?php foreach ($checks as $c) if (!$c[1]): ?>
                <div class="env-row"><span><?php echo e($c[0]); ?></span><span class="icheck-bad">✗ <?php echo e($c[2]); ?></span></div>
                <?php endif; ?>
                <div class="install-actions"><a href="<?php echo e(installBasePath() . '/install.php'); ?>" class="mn-btn">返回检测</a></div>
            </div>
        <?php });
        exit;
    }

    // 强制同意许可协议：服务端再校验，防止绕过前端直接 POST 触发安装
    if (($_POST['agree_license'] ?? '') !== '1') {
        renderPage('安装', function () { ?>
            <div class="install-card mn-text-center">
                <p class="mn-text-error mn-fs-15">未同意《许可协议》，已停止安装。请返回重新阅读并勾选同意后继续。</p>
                <div class="install-actions mn-flex-center">
                    <a href="<?php echo e(installBasePath() . '/install.php'); ?>" class="mn-btn">返回协议</a>
                </div>
            </div>
        <?php });
        exit;
    }

    $cfg = [
        'admin_user'  => trim($_POST['admin_user'] ?? ''),
        'admin_email' => trim($_POST['admin_email'] ?? ''),
        'admin_pass'  => (string)($_POST['admin_pass'] ?? ''),
    ];

    $errors = [];
    if (strlen($cfg['admin_user']) < 2) $errors[] = '管理员用户名至少 2 个字符';
    if (!filter_var($cfg['admin_email'], FILTER_VALIDATE_EMAIL)) $errors[] = '管理员邮箱格式不正确';
    if (strlen($cfg['admin_pass']) < 6) $errors[] = '管理员密码至少 6 位';

    $r = ['ok' => false, 'msg' => ''];
    if (empty($errors)) {
        // 包裹安装核心逻辑于 try/catch：异常只输出简化错误，不向浏览器泄露内部细节
        try {
            $r = runInstall($cfg);
        } catch (\Throwable $ex) {
            \error_log('FlintHub install exception: ' . $ex->getMessage());
            $r = ['ok' => false, 'msg' => '安装过程中发生内部错误，请查看服务器错误日志后重试'];
        }
    } else {
        $r['msg'] = implode('；', $errors);
    }

    if ($r['ok']) {
        $isNginx = stripos($_SERVER['SERVER_SOFTWARE'] ?? '', 'nginx') !== false;
        renderPage('完成', function () use ($isNginx) { ?>
            <div class="install-card mn-text-center">
                <div class="mn-text-success mn-mb-12" style="font-size:52px;"><?php echo '✓'; ?></div>
                <h1 class="mn-fs-20 mn-fw-600 mn-mb-8">安装完成！</h1>
                <p class="mn-text-muted mn-fs-14 mn-mb-20">FlintHub 1.0（SplitDB）已成功安装。为安全起见，请立即删除本安装文件。</p>
                <?php if ($isNginx): ?>
                <div class="mn-alert mn-alert-warning mn-mb-16">
                    <b>Nginx 防护提醒：</b>为避免 config.php 备份、会话、缓存、数据分片等敏感文件被直接下载，请在站点 Nginx 配置中确认已包含随包附带的 <code>nginx-server.conf</code> <b>完整规则</b>（含 data/protected/cli/插件数据目录 deny 拦截与 /repo/ 路由），而非仅单条 location。
                </div>
                <?php endif; ?>
                <div class="mn-flex-center mn-gap-10">
                    <a href="<?php echo e(installBasePath() . '/'); ?>" class="mn-btn mn-btn-primary">进入站点</a>
                    <a href="<?php echo e(installBasePath() . '/admin'); ?>" class="mn-btn">后台管理</a>
                </div>
            </div>
        <?php });
        exit;
    }

    renderPage('安装', function () use ($r) { ?>
        <div class="install-card">
            <h2 class="mn-fs-16 mn-fw-600 mn-mb-12">安装未完成</h2>
            <div class="mn-alert mn-alert-error"><?php echo e($r['msg']); ?></div>
            <div class="install-actions"><a href="<?php echo e(installBasePath() . '/install.php'); ?>" class="mn-btn">返回重试</a></div>
        </div>
    <?php });
    exit;
}

// ============================================================
// 3. 分步向导页面（Alpine 控制步骤切换）
// ============================================================

$checks = detectEnvironment();
$envOk  = envAllOk($checks);

$defaults = [
    'admin_user'  => 'admin',
    'admin_email' => '',
    'admin_pass'  => '',
];

// 许可协议文本：优先读取包内 LICENSE 文件（单一来源），缺失时回退到内嵌 MIT 全文
$licenseText = @file_get_contents(ROOT_DIR . '/LICENSE');
if ($licenseText === false || trim($licenseText) === '') {
    $licenseText = "FlintHub 1.0 — MIT License\n\n"
        . "Copyright (c) 2026 FlintHub Contributors\n\n"
        . "Permission is hereby granted, free of charge, to any person obtaining a copy\n"
        . "of this software and associated documentation files (the \"Software\"), to deal\n"
        . "in the Software without restriction, including without limitation the rights\n"
        . "to use, copy, modify, merge, publish, distribute, sublicense, and/or sell\n"
        . "copies of the Software, and to permit persons to whom the Software is\n"
        . "furnished to do so, subject to the following conditions:\n\n"
        . "The above copyright notice and this permission notice shall be included in all\n"
        . "copies or substantial portions of the Software.\n\n"
        . "THE SOFTWARE IS PROVIDED \"AS IS\", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR\n"
        . "IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,\n"
        . "FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE\n"
        . "AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER\n"
        . "LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,\n"
        . "OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE\n"
        . "SOFTWARE.";
}

renderPage('欢迎', function () use ($checks, $envOk, $csrf, $defaults, $licenseText) { ?>
<div class="mn-section mn-p-0" x-data="installer()">
    <div class="install-steps">
        <span class="istep" :class="{ on: step === 1, done: step > 1 }">1 欢迎</span>
        <span class="istep" :class="{ on: step === 2, done: step > 2 }">2 许可协议</span>
        <span class="istep" :class="{ on: step === 3, done: step > 3 }">3 环境检测</span>
        <span class="istep" :class="{ on: step === 4, done: step > 4 }">4 管理员</span>
        <span class="istep" :class="{ on: step === 5 }">5 完成</span>
    </div>

    <!-- ===== 步骤 1：欢迎 ===== -->
    <div class="install-card" x-show="step === 1" x-cloak>
        <h1 class="mn-fs-18 mn-fw-600 mn-mb-6">欢迎使用 FlintHub 1.0（SplitDB）！</h1>
        <p class="mn-text-muted mn-fs-14 mn-mb-14">本向导将引导你完成系统安装：环境检测 → 创建管理员账号。数据引擎为纯 SQLite 分片，无需配置数据库。</p>
        <div class="feature-grid">
            <div class="feat"><span><?php echo '◆'; ?></span><span><b>论坛 + 博客</b>：版块、帖子、评论、点赞、附件，一站式社区</span></div>
            <div class="feat"><span><?php echo '◆'; ?></span><span><b>SplitDB 分片引擎</b>：季度分区 + 32 桶哈希，正文外置 extern/，读写解耦</span></div>
            <div class="feat"><span><?php echo '◆'; ?></span><span><b>自研搜索引擎</b>：中文 bigram 分词 + 倒排索引全文检索</span></div>
            <div class="feat"><span><?php echo '◆'; ?></span><span><b>自研富文本编辑器</b>：可视化 + Markdown 双模式</span></div>
            <div class="feat"><span><?php echo '◆'; ?></span><span><b>安全体系</b>：SQL 参数化、CSRF、限流、上传双白名单、SSRF 防护</span></div>
            <div class="feat"><span><?php echo '◆'; ?></span><span><b>零依赖</b>：无 Composer、无 MySQL，PHP 8.0+ 开箱即用</span></div>
        </div>
        <div class="install-actions">
            <span></span>
            <button type="button" class="mn-btn mn-btn-primary" x-on:click="step = 2">开始安装 →</button>
        </div>
    </div>

    <!-- ===== 步骤 2：许可协议 ===== -->
    <div class="install-card" x-show="step === 2" x-cloak>
        <h2 class="mn-fs-16 mn-fw-600 mn-mb-6">许可协议</h2>
        <p class="mn-text-muted mn-fs-13 mn-mb-12">请阅读以下协议全文后，勾选同意方可继续安装。</p>
        <div class="license-box"><?php echo e($licenseText); ?></div>
        <label class="mn-check">
            <input type="checkbox" x-model="agreed" x-on:change="agreed = $event.target.checked">
            <span>我已阅读并同意上述《许可协议》（MIT License）</span>
        </label>
        <div class="install-actions">
            <button type="button" class="mn-btn" x-on:click="step = 1">← 上一步</button>
            <button type="button" class="mn-btn mn-btn-primary" :disabled="!agreed" x-on:click="step = 3">下一步 →</button>
        </div>
    </div>

    <!-- ===== 步骤 3：环境检测 ===== -->
    <div class="install-card" x-show="step === 3" x-cloak>
        <h2 class="mn-fs-16 mn-fw-600 mn-mb-14">环境检测</h2>
        <?php foreach ($checks as $c): ?>
        <div class="env-row">
            <span><?php echo e($c[0]); ?></span>
            <span class="<?php echo $c[1] ? 'icheck-ok' : 'icheck-bad'; ?>"><?php echo $c[1] ? '✓' : '✗'; ?> <?php echo e($c[2]); ?></span>
        </div>
        <?php endforeach; ?>
        <div class="install-actions">
            <button type="button" class="mn-btn" x-on:click="step = 2">← 上一步</button>
            <?php if ($envOk): ?>
            <button type="button" class="mn-btn mn-btn-primary" x-on:click="step = 4">下一步 →</button>
            <?php else: ?>
            <span class="mn-fs-13 mn-text-error mn-flex-center">存在未通过项，请先修复环境</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== 步骤 4：管理员账号 + 提交 ===== -->
    <div class="install-card" x-show="step === 4" x-cloak>
        <h2 class="mn-fs-16 mn-fw-600 mn-mb-6">创建管理员账号</h2>
        <p class="mn-text-muted mn-fs-13 mn-mb-16">设置站点管理员信息，安装后使用该账号登录后台。安装将自动创建 data/ 目录树与全部 SplitDB 库。</p>
        <form method="POST" action="<?php echo e(installBasePath() . '/install.php'); ?>" x-on:submit="submitting = true; setTimeout(() => step = 5, 30)">
            <input type="hidden" name="action" value="install">
            <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="agree_license" value="1">
            <div class="mn-mb-12">
                <label class="mn-label">管理员用户名</label>
                <input type="text" name="admin_user" value="<?php echo e($defaults['admin_user']); ?>" class="mn-input" minlength="2" required>
            </div>
            <div class="mn-mb-12">
                <label class="mn-label">管理员邮箱</label>
                <input type="email" name="admin_email" value="<?php echo e($defaults['admin_email']); ?>" class="mn-input" required>
            </div>
            <div class="mn-mb-12">
                <label class="mn-label">管理员密码（至少 6 位）</label>
                <input type="password" name="admin_pass" class="mn-input" minlength="6" required autocomplete="new-password">
            </div>
            <div class="install-actions">
                <button type="button" class="mn-btn" x-on:click="step = 3" :disabled="submitting">← 上一步</button>
                <button type="submit" class="mn-btn mn-btn-primary" :disabled="submitting"><?php echo '✓'; ?> 开始安装</button>
            </div>
        </form>
    </div>

    <!-- ===== 步骤 5：安装中（提交表单后立即展示，避免误以为卡死） ===== -->
    <div class="install-card mn-text-center" x-show="step === 5" x-cloak>
        <div class="mn-text-primary mn-mb-8" style="font-size:40px;">⏳</div>
        <p class="mn-fs-16 mn-fw-600 mn-mb-6">正在安装，请稍候…</p>
        <p class="mn-text-muted mn-fs-13">正在创建 data/ 目录树、初始化 SplitDB 库与默认数据，请勿关闭页面</p>
        <div class="mn-flex-center mn-mt-16">
            <span class="mn-btn mn-btn-primary" style="pointer-events:none;opacity:.7;">安装中…</span>
        </div>
    </div>
</div>

<script defer src="<?php echo e(installBasePath()); ?>/assets/lib/fhstate.js"></script>
<script>
function installer() {
    return {
        step: 1,
        submitting: false,
        agreed: false,
        csrf: '<?php echo e($csrf); ?>'
    };
}
</script>
<?php });
