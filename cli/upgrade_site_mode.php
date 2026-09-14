<?php
/**
 * FlintHub 1.0 — 站点模式升级脚本（CLI，用后建议删除或移出 Web 根）
 *
 * 为存量站点增加「站点模式」设置键。
 *   - 新增后台页「站点模式」（/admin/settings/mode），三种模式：门户(portal)/论坛(forum)/博客(blog)
 *   - 本脚本仅做一件事：在 business.sqlite 的 settings 表中初始化 site_mode = portal（默认，与旧站点行为完全一致，零影响）
 *   - 门户默认 = 论坛+博客双开，与升级前完全相同，因此无需手工指定其它值。
 *
 * 用法（上传服务器后 CLI 运行，两种放置位置均可，自动探测站点根）：
 *   # ① 放站点根目录：
 *   php upgrade_site_mode.php                # 初始化/确认 site_mode=portal（默认）
 *   # ② 放 cli/ 目录：
 *   php cli/upgrade_site_mode.php            # 同上
 *   php upgrade_site_mode.php forum          # 强制覆盖为论坛模式
 *   php upgrade_site_mode.php blog           # 强制覆盖为博客模式
 *
 * ★ 已存在 site_mode 键且未传参时保留原值（不覆盖）。
 * ★ 自动读取 config.php 的 SPLITDB_DATA_PATH（支持 __DIR__ . '/data' 拼接写法），无需任何配置。
 * ★ 本脚本不加载完整框架，仅解析 config.php 中数据目录后直接操作 SQLite，
 *   避免 require config.php 触发 session/error_log 等 Web 副作用，可独立在服务器运行。
 *
 * @package cli
 */

// ===== 0. CLI 守卫（Web 直访拒绝）=====
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

// ===== 0b. 自动探测站点根目录（兼容两种放置位置）=====
//   ① 脚本放站点根目录：        config.php 与脚本同目录
//   ② 脚本放 cli/ 目录：        config.php 在脚本上级目录
$configFile = null;
foreach ([__DIR__, __DIR__ . '/..', __DIR__ . '/../..'] as $dir) {
    $candidate = realpath($dir . '/config.php');
    if ($candidate !== false && is_file($candidate)) {
        $configFile = $candidate;
        break;
    }
}

// ===== 1. 从 config.php 提取 SPLITDB_DATA_PATH（正则解析，不 require，避免副作用）=====
if ($configFile === null) {
    fwrite(STDERR, "[错误] 未找到 config.php，请确认脚本位于站点根目录或站点根目录的 cli/ 下运行。\n");
    exit(1);
}
$cfgSrc = file_get_contents($configFile);
if ($cfgSrc === false) {
    fwrite(STDERR, "[错误] 读取 config.php 失败。\n");
    exit(1);
}

$dataPath = null;
// 形式 A：define('SPLITDB_DATA_PATH', '/绝对路径/data');
if (preg_match("/define\(\s*'SPLITDB_DATA_PATH'\s*,\s*'([^']+)'\s*\)/", $cfgSrc, $m)) {
    $dataPath = $m[1];
}
// 形式 B：define('SPLITDB_DATA_PATH', __DIR__ . '/data');
elseif (preg_match("/define\(\s*'SPLITDB_DATA_PATH'\s*,\s*__DIR__\s*\.\s*'([^']+)'\s*\)/", $cfgSrc, $m)) {
    $dataPath = dirname($configFile) . $m[1];
}

if ($dataPath === null) {
    fwrite(STDERR, "[错误] 未在 config.php 中找到 SPLITDB_DATA_PATH。\n");
    exit(1);
}

// 相对路径兜底（相对站点根解析）
if (!preg_match('#^(/|[A-Za-z]:[\\\\/])#', $dataPath)) {
    $dataPath = dirname($configFile) . '/' . $dataPath;
}

if (!is_dir($dataPath)) {
    fwrite(STDERR, "[错误] SPLITDB_DATA_PATH 目录不存在：{$dataPath}\n");
    exit(1);
}

$businessFile = rtrim($dataPath, '/\\') . '/meta/business.sqlite';
if (!is_file($businessFile)) {
    fwrite(STDERR, "[错误] 未找到业务库：{$businessFile}\n");
    fwrite(STDERR, "      请确认数据目录正确，且站点已完成初始化（安装）。\n");
    exit(1);
}

// ===== 2. 解析 CLI 参数（可选强制模式）=====
$requestedMode = null;
foreach (array_slice($argv, 1) as $arg) {
    if (in_array($arg, ['portal', 'forum', 'blog'], true)) {
        $requestedMode = $arg;
    }
}

// ===== 3. 连接 business.sqlite，幂等初始化 site_mode 键 =====
$dsn = 'sqlite:' . $businessFile;
$pdo = null;
// 重试等待（最多 ~10s），兼容长任务/备份期间锁占用
for ($i = 0; $i < 10; $i++) {
    try {
        $pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        break;
    } catch (\Throwable $e) {
        if ($i === 9) {
            fwrite(STDERR, '[错误] 无法连接业务库：' . $e->getMessage() . "\n");
            exit(1);
        }
        usleep(1000000);
    }
}
$pdo->exec('PRAGMA busy_timeout = 5000;');

// 读取现有 site_mode
$stmt = $pdo->prepare('SELECT value FROM settings WHERE "key" = :k');
$stmt->execute([':k' => 'site_mode']);
$existing = $stmt->fetchColumn();

if (($existing !== false && $existing !== null && $existing !== '') && $requestedMode === null) {
    echo "[跳过] site_mode 已存在 = {$existing}，保持不变（如需覆盖请显式传参 portal/forum/blog）\n";
    exit(0);
}

// 目标值：显式参数优先，其次已有值，最后默认 portal
$target = $requestedMode ?: ($existing ?: 'portal');
if (!in_array($target, ['portal', 'forum', 'blog'], true)) {
    $target = 'portal';
}

if ($existing === false || $existing === null || $existing === '') {
    $ins = $pdo->prepare('INSERT INTO settings ("key", "value") VALUES (:k, :v)');
    $ins->execute([':k' => 'site_mode', ':v' => $target]);
    echo "[完成] 已初始化 site_mode = {$target}\n";
} else {
    $upd = $pdo->prepare('UPDATE settings SET value = :v WHERE "key" = :k');
    $upd->execute([':k' => 'site_mode', ':v' => $target]);
    echo "[完成] 已更新 site_mode：{$existing} → {$target}\n";
}

echo '数据文件：' . $businessFile . "\n";
echo "部署提示：覆盖 update/20 全部文件后，登录后台「设置 → 站点模式」可随时切换模式（切换会自动刷新前台缓存）。\n";
exit(0);
