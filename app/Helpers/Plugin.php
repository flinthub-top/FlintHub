<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 插件管理系统 — 钩子注册、激活/禁用、缓存扫描
 * @file app/Helpers/Plugin.php
 * @package app\Helpers
 */

namespace app\Helpers;

class Plugin
{
    private static array $plugins = [];
    private static bool $initialized = false;
    private static string $pluginDir = '';
    private static string $cacheFile = '';

    /**
     * 初始化插件系统
     */
    public static function init(): void
    {
        if (self::$initialized) return;
        self::$initialized = true;

        self::$pluginDir = __DIR__ . '/../../plugins';
        self::$cacheFile = self::$pluginDir . '/plugins_cache.json';

        if (!is_dir(self::$pluginDir)) {
            @mkdir(self::$pluginDir, 0755, true);
        }

        self::loadPlugins();
    }

    /**
     * 获取插件独立数据库连接（每个插件一个 SQLite 文件，位于插件目录 data/ 下）
     * 复用 DBFactory：统一 PRAGMA（WAL/busy_timeout 等）+ LRU 池 + 路径归一化，零新增连接管理
     * 路径约定：plugins/{插件名}/data/{插件名}.sqlite（卸载时删 data/ 目录即可清库）
     *
     * @param string $name 插件名（目录名）
     * @return \PDO
     */
    public static function db(string $name): \PDO
    {
        self::init();
        $dir = self::$pluginDir . '/' . $name . '/data';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        // 插件 data/ 下为插件独立 SQLite 库（敏感数据），自动补写 Apache/IIS 防护文件防 Web 直接下载
        // （Nginx 侧由 nginx-server.conf 全局正则覆盖）；幂等：文件已存在则跳过
        $guard = $dir . '/.htaccess';
        if (!file_exists($guard)) {
            @file_put_contents($guard,
                "# FlintHub — 禁止直接访问插件数据目录（Apache）\n" .
                "# 本目录含插件 SQLite 独立库（敏感数据），禁止 HTTP 下载。\n" .
                "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n" .
                "<FilesMatch \".*\">\n    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n" .
                "    <IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all\n    </IfModule>\n</FilesMatch>\n",
                LOCK_EX);
        }
        $iisGuard = $dir . '/web.config';
        if (!file_exists($iisGuard)) {
            @file_put_contents($iisGuard,
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
                "<configuration>\n  <system.webServer>\n    <security>\n      <requestFiltering>\n" .
                "        <hiddenSegments>\n          <add segment=\"data\" />\n        </hiddenSegments>\n" .
                "      </requestFiltering>\n    </security>\n  </system.webServer>\n</configuration>\n",
                LOCK_EX);
        }
        return \app\SplitDB\DBFactory::getConnection($dir . '/' . $name . '.sqlite');
    }

    /**
     * 幂等执行插件建表（SQLite DDL，CREATE TABLE IF NOT EXISTS）
     * 供插件 activate()/钩子建表时调用，避免直接操作全局 business.sqlite
     *
     * @param string $name 插件名
     * @param string $ddl  单条 SQLite DDL 语句
     */
    public static function ensureSchema(string $name, string $ddl): void
    {
        self::db($name)->exec($ddl);
    }

    // ========================================================================
    //  权限声明与强制（极简版）：plugin.json permissions 字段消费
    //  - 权限键：net:fetch（外部网络请求）/ route:admin（后台路由注册）/ system:settings（写全局设置）
    //  - 全局开关 permission_enforce（后台设置，默认 1=强制模式）：未声明权限的操作被拒绝；
    //    仅当显式置 0=警告模式时才记录日志并放行（兼容开发调试）
    // ========================================================================

    /**
     * 检查插件是否声明了指定权限
     */
    public static function granted(string $name, string $perm): bool
    {
        self::init();
        $perms = self::$plugins[$name]['permissions'] ?? [];
        return is_array($perms) && in_array($perm, $perms, true);
    }

    /**
     * 权限强制检查：声明了 → 放行；未声明 → 按全局开关记录警告（放行）或拒绝
     *
     * @param string $name 插件名（目录名）
     * @param string $perm 权限键（net:fetch / route:admin / system:settings）
     * @return bool true=放行；false=拒绝（仅 permission_enforce=1 时未声明才拒绝）
     */
    public static function requirePermission(string $name, string $perm): bool
    {
        if (self::granted($name, $perm)) return true;

        $enforce = \app\Helpers\Settings::get('permission_enforce', '1') === '1';
        $action = $enforce ? 'DENY' : 'warn';
        \error_log("[plugin-permission] {$action} [{$name}] missing permission '{$perm}'");
        \app\Helpers\AuditLog::log('plugin_permission', 'plugin', 0, "{$action} [{$name}] missing permission '{$perm}'");
        return !$enforce;
    }

    /**
     * 插件统一网络外呼入口：强制校验 net:fetch 权限 + 防 SSRF（禁内网/保留 IP）+ 超时
     * 插件应通过本方法发起外部 HTTP(S) 请求，替代裸 file_get_contents / curl_init
     *
     * @param string $name 插件名（目录名）
     * @param string $url  目标 URL（仅 http/https）
     * @param array  $opts ['method'=>'GET|POST','headers'=>[],'body'=>string,'timeout'=>int]
     * @return array{ok:bool,body:string,error:string}
     */
    public static function http(string $name, string $url, array $opts = []): array
    {
        if (!self::requirePermission($name, 'net:fetch')) {
            return ['ok' => false, 'body' => '', 'error' => 'permission_denied: net:fetch not declared'];
        }
        if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'body' => '', 'error' => 'invalid_url'];
        }

        // 防 SSRF：域名解析后校验真实 IP，拒绝内网/保留地址
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === false || $host === null || $host === '') {
            return ['ok' => false, 'body' => '', 'error' => 'invalid_host'];
        }
        $ips = @gethostbynamel($host);
        if ($ips === false || empty($ips)) {
            return ['ok' => false, 'body' => '', 'error' => 'dns_failed'];
        }
        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                return ['ok' => false, 'body' => '', 'error' => 'blocked_ip'];
            }
        }

        $method = strtoupper((string)($opts['method'] ?? 'GET'));
        $headers = (array)($opts['headers'] ?? []);
        $body = (string)($opts['body'] ?? '');
        $timeout = max(3, (int)($opts['timeout'] ?? 10));
        // ssl_verify=false 仅用于 phpstudy 等缺 CA 包环境的证书降级重试（默认严格校验）
        $sslVerify = (bool)($opts['ssl_verify'] ?? true);
        // 浏览器 UA（newsnow 等源要求带 UA 否则 403）
        $headers[] = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

        $response = null;
        if (ini_get('allow_url_fopen')) {
            $httpCtx = [
                'method' => $method,
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => ($method === 'POST' && $body !== '') ? $body : null,
                'timeout' => $timeout,
                'ignore_errors' => true,
                // SSRF 防绕过：初始 URL 已过 isBlockedIp 校验，禁跟随重定向——
                // 否则攻击者用外部域名 301 指向 127.0.0.1/169.254.169.254 可绕过内网拦截
                'follow_location' => 0,
                'max_redirects' => 0,
            ];
            $ctx = stream_context_create([
                'http' => $httpCtx,
                'ssl'  => ['verify_peer' => $sslVerify, 'verify_peer_name' => $sslVerify],
            ]);
            $response = @file_get_contents($url, false, $ctx);
        }

        if ($response === false || $response === null) {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_SSL_VERIFYPEER => $sslVerify,
                    // SSRF 防绕过：显式禁跟随重定向（cURL 默认 false，显式置零防版本/未来默认值变化）
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_MAXREDIRS => 0,
                ]);
                if ($method === 'POST') {
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }
                $response = curl_exec($ch);
                $curlErr = curl_error($ch);
                curl_close($ch);
                if ($response === false) {
                    return ['ok' => false, 'body' => '', 'error' => 'curl: ' . $curlErr];
                }
            } else {
                return ['ok' => false, 'body' => '', 'error' => 'http_disabled'];
            }
        }

        return ['ok' => true, 'body' => (string)$response, 'error' => ''];
    }

    /**
     * 内网/保留 IP 检测（SSRF 防线；覆盖常见保留网段）
     */
    private static function isBlockedIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return true;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        return false;
    }

    /**
     * 扫描并加载所有插件
     */
    private static function loadPlugins(): void
    {
        // 优先从缓存加载（JSON 格式，直接 json_decode，消除 RCE 风险）
        if (file_exists(self::$cacheFile)) {
            $content = @file_get_contents(self::$cacheFile);
            if ($content !== false) {
                $cached = @json_decode($content, true);
                // 缓存新鲜度校验：任一 plugin.json 比缓存文件新 → 判定缓存过期，走全量扫描重建。
                // 否则「改 plugin.json 的 hooks 却不生效」会静默发生（升级覆盖插件目录后尤其危险），
                // 此前只能靠后台禁用/启用或删缓存文件手动触发重建。
                if (is_array($cached) && self::cacheFresh($cached)) {
                    self::$plugins = $cached;
                    // 重新计算路径，避免缓存中的绝对路径在新服务器上失效
                    foreach (self::$plugins as $name => &$plugin) {
                        $plugin['path'] = self::$pluginDir . '/' . $name;
                        // 旧缓存可能没有 category 字段：补默认值，避免"未分类"以外的空值
                        $plugin['category'] = $plugin['category'] ?? 'default';
                    }
                    unset($plugin);
                    return;
                }
            }
        }

        $dirs = glob(self::$pluginDir . '/*', GLOB_ONLYDIR);
        self::$plugins = [];

        if ($dirs) {
            foreach ($dirs as $dir) {
                $pluginName = basename($dir);
                $configFile = $dir . '/plugin.json';
                if (!file_exists($configFile)) continue;

                $config = @json_decode(file_get_contents($configFile), true);
                if (empty($config) || empty($config['name'])) continue;

                $config['dir'] = $pluginName;
                $config['path'] = $dir;
                $config['activated'] = $config['activated'] ?? false;
                // 分类：后台插件分类（system/community/content/entertainment/default），缺失归入 default
                $config['category'] = $config['category'] ?? 'default';
                // 许可声明：插件声明的权限白名单，空数组表示无特殊权限
                $config['permissions'] = $config['permissions'] ?? [];
                self::$plugins[$pluginName] = $config;
            }
        }

        self::saveCache();
    }

    /**
     * 缓存新鲜度校验：缓存文件必须比所有 plugin.json 都新，且插件目录集合与缓存键集合一致。
     *
     * 判定为「过期」的情况（任一命中即重建缓存）：
     *  1. 存在某个已注册插件的 plugin.json mtime > 缓存文件 mtime（典型：插件升级覆盖文件、手工编辑 hooks）
     *  2. plugins/ 下出现了缓存里没有的插件目录（新增插件未激活过）
     *  3. 缓存里有某个键，但对应 plugin.json 已消失（插件目录被删除）
     *
     * 成本：一次 glob + 每个 plugin.json 一次 stat（均走系统 stat 缓存，无 IO 读取、不解析 JSON）。
     * 失败时保守返回 true（沿用旧缓存），避免只读目录 / stat 被禁用时反复全量扫描。
     *
     * @param array $cached 已解码的缓存数组
     */
    private static function cacheFresh(array $cached): bool
    {
        if (empty($cached)) return true; // 空缓存交给全量扫描处理

        $dirs = @glob(self::$pluginDir . '/*', GLOB_ONLYDIR);
        if (!is_array($dirs)) return true;

        $cacheMtime = @filemtime(self::$cacheFile);
        if ($cacheMtime === false) return false;

        $liveNames = [];
        foreach ($dirs as $dir) {
            $configFile = $dir . '/plugin.json';
            if (!@file_exists($configFile)) continue;
            $name = basename($dir);
            $liveNames[$name] = true;
            // 情况 1：plugin.json 比缓存新
            $jsonMtime = @filemtime($configFile);
            if ($jsonMtime !== false && $jsonMtime > $cacheMtime) return false;
        }

        // 情况 2：新增插件目录未进缓存
        foreach ($liveNames as $name => $_) {
            if (!isset($cached[$name])) return false;
        }
        // 情况 3：缓存键对应的插件已不存在
        foreach ($cached as $name => $_) {
            if (!isset($liveNames[$name])) return false;
        }

        return true;
    }

    /**
     * 重建缓存
     */
    private static function saveCache(): void
    {
        // 写入前剔除 path 字段：绝对路径不应进缓存（loadPlugins 读取时会按当前目录重新计算）
        $data = [];
        foreach (self::$plugins as $name => $plugin) {
            unset($plugin['path']);
            $data[$name] = $plugin;
        }
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($content === false) return; // 编码失败兜底：不写缓存（下次扫描重建）

        // 临时文件 + rename 原子写入，防并发写入数据丢失
        $tmpFile = self::$cacheFile . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmpFile, $content, LOCK_EX) !== false) {
            if (@rename($tmpFile, self::$cacheFile)) {
                // 清除 OPcache
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate(self::$cacheFile, true);
                }
                // 清理历史遗留的 .tmp.* 残留文件（rename 成功后临时文件已不存在，仅清理旧残留）
                foreach (glob(self::$cacheFile . '.tmp.*') as $stale) {
                    if (is_file($stale)) @unlink($stale);
                }
            } else {
                // rename 失败（目标被占用/锁冲突）：删除本次临时文件，避免残留
                @unlink($tmpFile);
            }
        } else {
            // 写入失败：删除可能产生的残缺临时文件
            @unlink($tmpFile);
        }
    }

    /**
     * 严格读取 JSON 配置文件：文件必须存在且为有效 JSON 对象，否则返回 null
     */
    private static function readJson(string $file): ?array
    {
        if (!is_file($file)) return null;
        $content = @file_get_contents($file);
        if ($content === false) return null;
        $decoded = @json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 原子写入 JSON 配置文件（plugin.json 等）
     * 临时文件 + rename 防半截 JSON；返回 false 表示写入失败（磁盘满/目录不可写等）
     */
    private static function writeJsonAtomic(string $file, array $data): bool
    {
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($content === false) return false;

        $tmpFile = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmpFile, $content, LOCK_EX) === false) {
            @unlink($tmpFile);
            return false;
        }
        if (!@rename($tmpFile, $file)) {
            @unlink($tmpFile);
            return false;
        }
        // 清理历史遗留的 .tmp.* 残留（rename 成功后临时文件已不存在）
        foreach (glob($file . '.tmp.*') as $stale) {
            if (is_file($stale)) @unlink($stale);
        }
        return true;
    }

    /**
     * 执行钩子 — 调用所有启用的插件中注册的钩子文件
     * 钩子文件在调用点被 include，可访问当前作用域的所有变量
     *
     * @param string $hookName 钩子名称
     * @param array $params 传递给钩子的参数
     */
    public static function hook(string $hookName, array $params = []): void
    {
        self::init();

        foreach (self::$plugins as $name => $config) {
            if (empty($config['activated'])) continue;
            if (empty($config['hooks'][$hookName])) continue;

            // 权限特判：注册后台路由的钩子必须声明 route:admin 权限
            // （警告模式=记录并放行；permission_enforce=1 强制模式=拒绝分发该钩子）
            if ($hookName === 'admin_route_register' && !self::requirePermission($name, 'route:admin')) {
                continue;
            }

            // 用相对路径拼接，避免缓存中的绝对路径在新服务器上找不到
            $hookFile = self::$pluginDir . '/' . $name . '/' . ltrim($config['hooks'][$hookName], '/');

            // 安全校验：确保钩子文件路径在插件目录内，防路径穿越
            $realHook = realpath($hookFile);
            $realPluginDir = realpath(self::$pluginDir . '/' . $name);
            // 追加目录分隔符，防兄弟目录绕过（如 foo 匹配 foobar）
            $realPluginDirSafe = $realPluginDir !== false ? rtrim($realPluginDir, '/\\') . DIRECTORY_SEPARATOR : '';
            if ($realHook === false || $realPluginDirSafe === '' || strpos($realHook, $realPluginDirSafe) !== 0) {
                error_log("Plugin hook path security violation [{$name}:{$hookName}]: {$hookFile}");
                continue;
            }

            if (file_exists($hookFile)) {
                // 提取变量到当前作用域供钩子文件使用
                extract($params, EXTR_SKIP | EXTR_REFS);
                try {
                    include $hookFile;
                } catch (\Throwable $e) {
                    // 单个钩子出错不影响其他钩子和页面继续运行
                    error_log("Plugin hook error [{$name}:{$hookName}]: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * 获取所有插件列表
     */
    public static function getPlugins(): array
    {
        self::init();
        return self::$plugins;
    }

    /**
     * 启用插件
     * 依赖核心 Autoloader 加载插件类文件（Plugin\ 命名空间已注册），无需显式 require
     */
    public static function activate(string $name): bool
    {
        self::init();
        if (!isset(self::$plugins[$name])) return false;

        $plugin = &self::$plugins[$name];
        if ($plugin['activated']) return true;

        $plugin['activated'] = true;

        // 写入 plugin.json（先严格验证文件存在且有效；原子写失败则回滚内存状态并中止）
        $configFile = $plugin['path'] . '/plugin.json';
        $config = self::readJson($configFile);
        if ($config === null) {
            $plugin['activated'] = false;
            return false;
        }
        $config['activated'] = true;
        if (!self::writeJsonAtomic($configFile, $config)) {
            $plugin['activated'] = false;
            return false;
        }

        // 调用激活方法（如果有 Plugin.php）
        $pluginClassFile = $plugin['path'] . '/Plugin.php';
        if (file_exists($pluginClassFile)) {
            $className = "\\Plugin\\{$name}\\Plugin";
            if (class_exists($className) && method_exists($className, 'activate')) {
                try {
                    $result = $className::activate();
                    if ($result === false) {
                        throw new \RuntimeException('Plugin activate returned false');
                    }
                } catch (\Throwable $e) {
                    // 激活失败回滚（文件与内存）
                    $plugin['activated'] = false;
                    $config['activated'] = false;
                    self::writeJsonAtomic($configFile, $config);
                    self::saveCache();
                    return false;
                }
            }
        }

        self::saveCache();
        return true;
    }

    /**
     * 禁用插件
     */
    public static function deactivate(string $name): bool
    {
        self::init();
        if (!isset(self::$plugins[$name])) return false;

        $plugin = &self::$plugins[$name];
        if (!$plugin['activated']) return true;

        $plugin['activated'] = false;

        // 写入 plugin.json（先严格验证文件存在且有效；原子写失败则回滚内存状态并中止）
        $configFile = $plugin['path'] . '/plugin.json';
        $config = self::readJson($configFile);
        if ($config === null) {
            $plugin['activated'] = true;
            return false;
        }
        $config['activated'] = false;
        if (!self::writeJsonAtomic($configFile, $config)) {
            $plugin['activated'] = true;
            return false;
        }

        // 调用禁用方法
        $pluginClassFile = $plugin['path'] . '/Plugin.php';
        if (file_exists($pluginClassFile)) {
            $className = "\\Plugin\\{$name}\\Plugin";
            if (class_exists($className) && method_exists($className, 'deactivate')) {
                try {
                    $className::deactivate();
                } catch (\Throwable $e) {
                    // 忽略
                }
            }
        }

        self::saveCache();
        return true;
    }

    /**
     * 检查插件是否启用
     */
    public static function isActivated(string $name): bool
    {
        self::init();
        return isset(self::$plugins[$name]) && self::$plugins[$name]['activated'];
    }

    /**
     * 获取单个插件信息
     */
    public static function getPlugin(string $name): ?array
    {
        self::init();
        return self::$plugins[$name] ?? null;
    }

    /**
     * 更新插件 plugin.json 配置
     */
    public static function updateConfig(string $name, array $data): bool
    {
        self::init();
        if (!isset(self::$plugins[$name])) return false;

        $plugin = self::$plugins[$name];
        $configFile = $plugin['path'] . '/plugin.json';

        // 严格读取并验证 plugin.json 存在且有效
        $config = self::readJson($configFile);
        if ($config === null) return false;

        // 只修改允许编辑的字段，保留其余字段（不重建白名单数组）
        // 存原始值（不转义）：转义属于输出侧职责，输出时用 htmlspecialchars 兜底，避免重复转义
        foreach (['name', 'author', 'description', 'category'] as $field) {
            if (array_key_exists($field, $data)) {
                $config[$field] = (string)$data[$field];
            }
        }

        // 原子写入 plugin.json；失败返回 false（保持内存/文件一致）
        if (!self::writeJsonAtomic($configFile, $config)) return false;

        self::refresh();
        return true;
    }

    /**
     * 刷新插件缓存
     */
    public static function refresh(): void
    {
        self::$initialized = false;
        // refresh() 可能为插件系统首个调用：先行补齐路径，保证重建缓存逻辑在任意调用顺序下正确
        self::$pluginDir = __DIR__ . '/../../plugins';
        self::$cacheFile = self::$pluginDir . '/plugins_cache.json';
        if (file_exists(self::$cacheFile)) {
            @unlink(self::$cacheFile);
        }
        self::init();
    }

    /**
     * 清理插件数据并禁用（保留文件）：调用插件自身的 uninstall() 清理数据，
     * 将 plugin.json 标记为禁用，并从系统中移除注册状态、刷新缓存。
     * 注：本操作保留插件目录及文件，便于后续重新启用或手动删除。
     */
    public static function uninstall(string $name): bool
    {
        self::init();
        if (!isset(self::$plugins[$name])) return false;

        $plugin = &self::$plugins[$name];

        // 先调插件自身的 uninstall() 方法（由插件自己写 DROP TABLE 等清理逻辑）
        $pluginClassFile = $plugin['path'] . '/Plugin.php';
        if (file_exists($pluginClassFile)) {
            $className = "\\Plugin\\{$name}\\Plugin";
            if (class_exists($className) && method_exists($className, 'uninstall')) {
                try {
                    $className::uninstall();
                } catch (\Throwable $e) {
                    \error_log("Plugin uninstall error [{$name}]: " . $e->getMessage());
                }
            }
        }

        // 标记为禁用并写入 plugin.json（原子写；失败则中止，保留注册状态）
        $configFile = $plugin['path'] . '/plugin.json';
        $config = self::readJson($configFile);
        if ($config !== null) {
            $config['activated'] = false;
            if (!self::writeJsonAtomic($configFile, $config)) {
                return false;
            }
        }

        // 从内存移除注册状态并刷新缓存（保留文件，方便重新启用）
        unset(self::$plugins[$name]);
        self::saveCache();
        return true;
    }
}
