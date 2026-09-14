<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * API 控制器 — 投票、上传、验证码、JSON 接口
 * @file app/Controllers/ApiController.php
 * @package app\Controllers
 */

namespace app\Controllers;

use app\Core\Controller;
use app\Helpers\Auth;
use app\Helpers\Csrf;
use app\Helpers\Vote;
use app\Helpers\Points;
use app\Helpers\Settings;
use app\Helpers\Upload;
use app\Helpers\RateLimiter;

class ApiController extends Controller
{
    /**
     * POST /api/vote — 投票（纯点赞：赞/取消）
     */
    public function vote()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonError(\app\Helpers\I18n::get('api.post_only'), 405);
        }
        if (!Auth::isLoggedIn()) {
            // 记录登录来源（htmx 场景取 HX-Current-URL 当前页面，登录成功后回跳）
            \app\Helpers\Auth::rememberRedirectAfter();
            // htmx 请求：返回 HX-Redirect 头让整页跳登录（非 htmx 保持原 401 JSON 语义）
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
                header('HX-Redirect: ' . (\defined('BASE_PATH') ? BASE_PATH : '') . '/login');
            }
            $this->jsonError(\app\Helpers\I18n::get('api.login_required'), 401);
        }
        if (!Csrf::verify($_POST['csrf'] ?? '')) {
            $this->jsonError(\app\Helpers\I18n::get('api.csrf_failed'), 403);
        }
        RateLimiter::hitConfig('vote', 30, 60, true); // 每分钟最多 30 次投票（API 恒 JSON，阈值后台可配）

        $type = trim($_POST['type'] ?? '');
        if (!in_array($type, ['thread', 'post'], true)) {
            $this->jsonError(\app\Helpers\I18n::get('api.invalid_type'), 400);
        }

        $idRaw = $_POST['id'] ?? '';
        if (!ctype_digit((string)$idRaw)) {
            $this->jsonError(\app\Helpers\I18n::get('api.invalid_id'), 400);
        }
        $id = (int)$idRaw;

        $voteRaw = $_POST['vote'] ?? null;
        if (!is_numeric($voteRaw) || !in_array((int)$voteRaw, [0, 1], true)) {
            $this->jsonError(\app\Helpers\I18n::get('api.invalid_vote'), 400);
        }
        $vote = (int)$voteRaw;

        try {
            if ($type === 'thread') {
                $target = (new \app\Models\Thread())->find($id);
            } else {
                $target = (new \app\Models\Post())->find($id);
            }
            if (!$target) {
                $this->jsonError(\app\Helpers\I18n::get('api.not_found'), 404);
            }

            // 权限校验：校验目标所属版块可浏览性，防对隐藏/不可见版块内容投票形成存在性预言机。
            // 帖子类型需回查所属主题的版块；未命中版块时不阻止既有逻辑。
            $catId = (int)($target['category_id'] ?? 0);
            if ($catId <= 0 && $type === 'post') {
                $parentThread = (new \app\Models\Thread())->find((int)($target['thread_id'] ?? 0));
                $catId = (int)($parentThread['category_id'] ?? 0);
            }
            if ($catId > 0 && !\app\Helpers\Permission::canView($catId, (int)($_SESSION['user_id'] ?? 0))) {
                $this->jsonError(\app\Helpers\I18n::get('api.not_found'), 404);
            }

            $result = Vote::toggle($type, $id, $vote);
            if (!is_array($result)) {
                throw new \RuntimeException('Vote::toggle返回格式错误');
            }

            // 获赞积分奖励：仅在「由非赞态首次变为赞态」时发放（Vote::toggle 返回 award_like），
            // 重复点亮同一目标不再重复发分（防刷分）
            if ($vote == 1 && !empty($result['award_like'])) {
                if ($owner = $target['user_id'] ?? 0) {
                    if ((int)$owner !== (int)($_SESSION['user_id'] ?? 0)) {
                        Points::award((int)$owner, (int)Settings::get('points_vote_received', '1'), ($type === 'thread' ? \app\Helpers\I18n::get('points.thread_liked') : \app\Helpers\I18n::get('points.post_liked')), $id, $type);
                    }
                }
            }

            // 通知钩子：投票/点赞后（供通知插件发"被赞"通知；自己赞自己由插件侧排除）
            \app\Helpers\Plugin::hook('vote_after', [
                'type'     => $type,
                'id'       => $id,
                'vote'     => $vote,
                'user_id'  => (int)($_SESSION['user_id'] ?? 0),
                'owner_id' => (int)($target['user_id'] ?? 0),
            ]);

            // 通知内置化：帖子/回复被赞时通知作者（仅"赞"，取消赞/点踩不打扰）
            \app\Helpers\Notification::notifyVote($type, (int)$id, (int)$vote, (int)($_SESSION['user_id'] ?? 0), (int)($target['user_id'] ?? 0));

            // 附带新的 CSRF Token，供前端刷新
            $result['csrf_token'] = Csrf::token();

            // htmx 请求：返回 HTML 片段（纯点赞，仅渲染「赞」按钮）
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
                $likeActive = ($result['user_vote'] === 1) ? ' active' : '';
                $csrfToken = $result['csrf_token'];
                $html = '<span class="vote-buttons" data-type="' . $type . '" data-id="' . $id . '">'
                    . '<button class="vote-btn vote-up' . $likeActive . '" hx-post="/api/vote" hx-target="closest .vote-buttons" hx-swap="outerHTML" hx-vals=\'{"type":"' . $type . '","id":"' . $id . '","vote":"1","csrf":"' . $csrfToken . '"}\' title="' . \app\Helpers\I18n::get('thread.like') . '"><i class="fa mn-fs-14">&#xf087;</i> <span class="vote-count">' . (int)$result['likes'] . '</span></button>'
                    . '</span>'
                    // CSRF 写入 JS 上下文改用 json_encode 生成安全字符串字面量，避免原始拼接导致脚本注入
                    . '<script>if(window.CSRF_TOKEN) window.CSRF_TOKEN=' . \json_encode($csrfToken) . ';</script>';
                echo $html;
                exit;
            }

            $this->json($result);
        } catch (\Throwable $e) {
            \error_log('[api/vote] ' . $e->getMessage());
            $this->jsonError(\app\Helpers\I18n::get('api.internal_error'), 500);
        }
    }

    /**
     * POST /api/upload — AJAX 文件上传
     */
    public function upload()
    {
        $ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
        $response = ['success' => false, 'error' => ''];

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $response['error'] = 'Invalid request method';
        } elseif (!isset($_SESSION['user_id'])) {
            $response['error'] = 'Please log in first';
        } elseif (!Csrf::verify($_POST['csrf'] ?? '')) {
            // 添加 CSRF 校验，与 editor.js 上传时附带 csrf token 配合
            $response['error'] = \app\Helpers\I18n::get('error.csrf');
        } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $response['error'] = 'Upload failed';
        } elseif (isset($_POST['category_id']) && \ctype_digit((string)$_POST['category_id'])
                  && (int)$_POST['category_id'] > 0
                  && !\app\Helpers\Permission::canAttach((int)$_POST['category_id'], (int)($_SESSION['user_id'] ?? 0))) {
            // 指定目标版块时校验其 can_attach 权限，防止借编辑器直传 URL 绕过按版块上传/磁盘策略
            $response['error'] = '你没有权限在该版块上传附件';
        } else {
            RateLimiter::hitConfig('upload', 5, 60, true); // 每分钟最多 5 次上传（API 恒 JSON，阈值后台可配）
            $result = Upload::file($_FILES['file']);
            if ($result['success']) {
                $response['success'] = true;
                $response['url'] = $result['url'];
            } else {
                $response['error'] = $result['error'];
            }
        }

        if ($ajax) {
            $this->json($response);
        }

        if ($response['success']) {
            $referer = self::safeReferer();
            header('Location: ' . $referer);
        } else {
            $_SESSION['upload_error'] = $response['error'];
            $referer = self::safeReferer();
            header('Location: ' . $referer);
        }
        exit;
    }

    public function fetchImage()
    {
        header('Content-Type: application/json');

        if (!isset($_SESSION['user_id'])) {
            $this->jsonError(\app\Helpers\I18n::get('api.login_required'));
        }
        RateLimiter::hitConfig('fetch_image', 20, 60, true); // 每分钟最多 20 次远程拉图（API 恒 JSON，阈值后台可配）
        if ($_SERVER['REQUEST_METHOD'] !== 'GET' || empty($_GET['url'])) {
            $this->jsonError('Invalid request');
        }

        $url = trim($_GET['url']);
        if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $this->jsonError('Invalid URL');
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            $this->jsonError('Invalid host');
        }

        // 从 URL 解析的 host 检测是否为内网地址（含云元数据 169.254.0.0/16、CGNAT 100.64.0.0/10）
        if (self::isBlockedHost($host)) {
            $this->jsonError('Local URLs not allowed');
        }

        // DNS 解析后校验真实 IP，防域名指向内网（SSRF 防护）
        $resolvedIps = @gethostbynamel($host);
        if ($resolvedIps === false || empty($resolvedIps)) {
            // 解析失败直接拒绝，防 DNS Rebinding TOCTOU
            $this->jsonError('Remote image source not allowed');
        }

        $targetIp = null;
        foreach ($resolvedIps as $ip) {
            if (self::isBlockedIp($ip)) {
                $this->jsonError('Remote image source not allowed');
            }
            $targetIp = $ip; // 取最后一个非内网 IP
        }

        // SSRF 加固：用解析出的 IP 替换 host 发起请求，防 DNS Rebinding；保留原始 scheme（https→https）
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'http';
        if ($targetIp) {
            $url = preg_replace('#^https?://[^/]+#', $scheme . '://' . $targetIp, $url);
        }

        // SSRF 加固：逐跳请求，重定向每跳均做 host/IP 二次校验（防黑名单绕过）
        $imageData = self::fetchHopByHop($url, $host);
        if ($imageData === false) {
            $this->jsonError('Failed to download image');
        }

        $size = strlen($imageData);
        if ($size > 5 * 1024 * 1024) {
            $this->jsonError('Image too large (>5MB)');
        }

        // 1. 检查 fileinfo 扩展是否可用，不可用则先给个默认值
        if (class_exists('\finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($imageData);
        } else {
            $mime = 'application/octet-stream';
        }

        // 2. WebP 兼容补丁：如果识别为二进制流，根据文件头魔数手动纠正
        if ($mime === 'application/octet-stream') {
            $header = substr($imageData, 0, 12);
            if (str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP') {
                $mime = 'image/webp';
            }
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowedMimes)) {
            $this->jsonError('Invalid image type: ' . $mime);
        }

        $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $ext = $extMap[$mime] ?? 'jpg';
        $filename = bin2hex(random_bytes(8)) . '.' . $ext;
        $filepath = \UPLOAD_PATH . $filename;

        if (!is_dir(\UPLOAD_PATH)) {
            @mkdir(\UPLOAD_PATH, 0755, true);
        }

        if (file_put_contents($filepath, $imageData) === false) {
            $this->jsonError('Failed to save image');
        }

        $this->json(['success' => true, 'url' => \UPLOAD_URL . $filename, 'size' => $size, 'type' => $mime]);
    }

    /**
     * 判断 host 是否为被禁止的本地/内网地址
     * 封禁段：localhost/127.0.0.1/::1、0.x、10.x、127.x、169.254.0.0/16（云元数据）、
     * 172.16-31.x、192.168.x、100.64.0.0/10（CGNAT 内网段）
     */
    private static function isBlockedHost(string $host): bool
    {
        return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1'
            || \preg_match('#^(0\.|10\.|127\.|169\.254\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.|100\.(6[4-9]|[7-9][0-9]|1[0-1][0-9]|12[0-7])\.)#', $host);
    }

    /**
     * 判断解析后的 IP 是否为被禁止的本地/内网地址（同上封禁段）
     */
    private static function isBlockedIp(string $ip): bool
    {
        return $ip === '127.0.0.1' || $ip === '::1' || $ip === '0.0.0.0'
            || \preg_match('#^(0\.|10\.|127\.|169\.254\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.|100\.(6[4-9]|[7-9][0-9]|1[0-1][0-9]|12[0-7])\.)#', $ip);
    }

    /**
     * 逐跳抓取远程内容（SSRF 防护：重定向每跳均做 host/IP 二次校验，最多 3 跳）
     * 优先 file_get_contents，失败时降级 cURL；两者均关闭自动跟随，改由本方法人工跟随
     *
     * @param string $url 当前请求 URL（host 已替换为解析出的 IP）
     * @param string $originalHost 原始 Host 头（保持不变）
     * @return string|false 内容字节流，失败返回 false
     */
    private static function fetchHopByHop(string $url, string $originalHost)
    {
        $currentUrl = $url;
        $currentHost = $originalHost;
        $hops = 0;

        while (true) {
            // 构建当前跳的请求（不自动跟随重定向）
            $ctx = \stream_context_create([
                'http' => [
                    'timeout' => 15, 'user_agent' => 'Mozilla/5.0 (compatible; FlintHub)',
                    'follow_location' => 0,   // 关闭自动跟随，逐跳人工校验
                    'max_redirects' => 0,
                    'header' => "Accept: image/webp,image/*,*/*;q=0.8\n"
                        . "Host: {$currentHost}\n", // 保持原始 Host 头
                ],
                'ssl' => [
                    'verify_peer' => false,      // 连接 IP 而非域名，证书无法匹配，故跳过验证
                    'verify_peer_name' => false,
                    'timeout' => 15,
                ],
            ]);

            $fp = @\fopen($currentUrl, 'rb', false, $ctx);
            if ($fp !== false) {
                $meta = \stream_get_meta_data($fp);
                $headers = $meta['wrapper_data'] ?? [];
                $data = \stream_get_contents($fp);
                \fclose($fp);
                if ($data === false) return false;

                $statusCode = 0;
                $location = '';
                foreach ($headers as $h) {
                    if (\preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $statusCode = (int)$m[1]; }
                    if (\stripos($h, 'Location:') === 0) { $location = \trim(\substr($h, 9)); }
                }

                if ($statusCode >= 300 && $statusCode < 400 && $location !== '') {
                    if ($hops >= 3) return false; // 最多 3 跳
                    if (!self::nextHop($currentUrl, $location, $nextUrl, $currentHost)) return false;
                    $currentUrl = $nextUrl;
                    $hops++;
                    continue;
                }
                return $data; // 非 3xx（4xx/5xx 由后续 MIME/大小检查拦截）
            }

            // cURL 降级分支（allow_url_fopen=Off 或 fopen 失败）
            if (\function_exists('curl_init')) {
                $ch = \curl_init($currentUrl);
                \curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_FOLLOWLOCATION => false,   // 关闭自动跟随
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; FlintHub)',
                    CURLOPT_HTTPHEADER => ["Accept: image/webp,image/*,*/*;q=0.8", "Host: {$currentHost}"],
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                ]);
                $data = \curl_exec($ch);
                $httpCode = (int)\curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $location = (string)\curl_getinfo($ch, CURLINFO_REDIRECT_URL);
                \curl_close($ch);
                if ($data === false) return false;

                if ($httpCode >= 300 && $httpCode < 400 && $location !== '') {
                    if ($hops >= 3) return false;
                    if (!self::nextHop($currentUrl, $location, $nextUrl, $currentHost)) return false;
                    $currentUrl = $nextUrl;
                    $hops++;
                    continue;
                }
                if ($httpCode >= 400) return false;
                return $data;
            }

            return false;
        }
    }

    /**
     * 解析并校验重定向目标（host + DNS 真实 IP 双重检查，IP 替换 host 防 DNS Rebinding）
     *
     * @param string $baseUrl 当前请求 URL
     * @param string $location Location 头（可为绝对/协议相对/根相对/相对路径）
     * @param string|null $nextUrl 输出：下一跳请求 URL（host 已替换为 IP）
     * @param string $nextHost 输出：下一跳原始 host（用于 Host 头）
     * @return bool 校验通过返回 true
     */
    private static function nextHop(string $baseUrl, string $location, ?string &$nextUrl, string &$nextHost): bool
    {
        // 解析 Location 为绝对 URL（支持协议相对 //、根相对 /、相对路径）
        if (\preg_match('#^https?://#i', $location)) {
            $abs = $location;
        } elseif (\strpos($location, '//') === 0) {
            $scheme = \parse_url($baseUrl, PHP_URL_SCHEME) ?: 'http';
            $abs = $scheme . ':' . $location;
        } else {
            $parts = \parse_url($baseUrl);
            $scheme = $parts['scheme'] ?? 'http';
            $host = $parts['host'] ?? '';
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';
            if ($host === '') return false;
            if (\strpos($location, '/') === 0) {
                $abs = $scheme . '://' . $host . $port . $location;
            } else {
                $path = $parts['path'] ?? '/';
                $dir = \substr($path, 0, \strrpos($path, '/') + 1) ?: '/';
                $abs = $scheme . '://' . $host . $port . $dir . $location;
            }
        }

        if (!\preg_match('#^https?://#i', $abs) || !\filter_var($abs, FILTER_VALIDATE_URL)) return false;

        $host = \parse_url($abs, PHP_URL_HOST);
        if (!$host || self::isBlockedHost($host)) return false;

        // DNS 解析后校验真实 IP，防域名指向内网
        $ips = @\gethostbynamel($host);
        if ($ips === false || empty($ips)) return false;
        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) return false;
        }

        $scheme = \parse_url($abs, PHP_URL_SCHEME) ?: 'http';
        $nextUrl = \preg_replace('#^https?://[^/]+#', $scheme . '://' . $ips[0], $abs);
        $nextHost = $host;
        return true;
    }

    public function captcha()
    {
        header('Content-Type: image/png');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        $width = 120;
        $height = 40;
        $length = 4;
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        // 动态盐值，优先使用 settings 表中的配置，与 Captcha::verify 保持一致
        $salt = \app\Helpers\Captcha::getSalt();

        $img = imagecreatetruecolor($width, $height);
        if (!$img) { http_response_code(500); exit; }

        $bg = imagecolorallocate($img, 245, 245, 245);
        $colors = [
            imagecolorallocate($img, 50, 80, 180), imagecolorallocate($img, 180, 50, 50),
            imagecolorallocate($img, 50, 150, 50), imagecolorallocate($img, 150, 80, 180),
        ];
        $lineColor = imagecolorallocate($img, 200, 200, 200);
        $dotColor = imagecolorallocate($img, 180, 180, 180);

        imagefill($img, 0, 0, $bg);
        // 干扰线
        for ($i = 0; $i < 6; $i++) {
            imageline($img, random_int(0, 30), random_int(0, $height), random_int(70, $width), random_int(0, $height), $lineColor);
        }
        // 干扰弧线（增加 OCR 难度）
        for ($i = 0; $i < 2; $i++) {
            $cx = random_int(20, $width - 20);
            $cy = random_int(10, $height - 10);
            imagearc($img, $cx, $cy, random_int(40, 80), random_int(20, 40), random_int(0, 180), random_int(180, 360), $lineColor);
        }
        // 噪点
        for ($i = 0; $i < 200; $i++) {
            imagesetpixel($img, random_int(0, $width), random_int(0, $height), $dotColor);
        }

        $code = '';
        $clen = strlen($chars);
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, $clen - 1)];
        }

        $_SESSION['captcha_hash'] = hash('sha256', strtolower($code) . $salt);
        $_SESSION['captcha_time'] = time();

        // 尽早释放 Session 锁，使后续的 AJAX 请求不需排队等待
        session_write_close();

        $font = 5;
        for ($i = 0; $i < $length; $i++) {
            $x = $i * 28 + random_int(3, 10);
            $y = random_int(12, 24);
            $color = $colors[$i % count($colors)];
            imagestring($img, $font, $x, $y, $code[$i], $color);
        }

        imagepng($img);
        imagedestroy($img);
        exit;
    }

    /**
     * GET /api/pow — 签发工作量证明挑战（自适应验证码防线第一道）
     * 返回 challenge/difficulty 供前端计算 nonce，及是否需要图片验证码(mode)
     */
    public function pow()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        $data = \app\Helpers\PoW::issue();
        echo \json_encode($data);
        exit;
    }

    /**
     * GET /api/post/{id}/body — 详情页「阅读全文」：返回帖子完整正文（HTML 片段，htmx 直接替换）
     *
     * 权限与详情页 ThreadController::show 一致：
     *   ① canView 版块浏览权限（游客 null 或登录用户）；
     *   ② reply_to_view 回复可见帖：未回复且非作者 → 403；
     * 响应：Content-Type: text/html（净化后正文片段），权限不足返回 403/404。
     */
    public function postBody($id)
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        $idRaw = (string)$id;
        if (!ctype_digit($idRaw) || (int)$idRaw <= 0) {
            http_response_code(404);
            exit;
        }
        $threadId = (int)$idRaw;

        // 防脚本批量抓取正文（超限自动 429 退出；阈值后台可配）
        RateLimiter::hitConfig('post_body', 30, 60, true);

        $thread = (new \app\Models\Thread())->getById($threadId);
        if (!$thread) {
            http_response_code(404);
            exit;
        }

        // 当前用户（游客为 null）
        $currentUser = $this->currentUser();
        $userId = $currentUser ? (int)$currentUser['id'] : 0;

        // ① 版块浏览权限（与详情页一致）
        if (!\app\Helpers\Permission::canView((int)$thread['category_id'], $userId ?: null)) {
            http_response_code(403);
            exit;
        }

        // ② 回复可见帖：未回复且非作者 → 拒绝（复用 Points::canViewContent，与 ThreadController 同判定）
        if (!empty($thread['reply_to_view'])) {
            $hasReplied = ($userId > 0 && $userId === (int)$thread['user_id'])
                || \app\Helpers\Points::canViewContent($threadId, $userId);
            if (!$hasReplied) {
                http_response_code(403);
                exit;
            }
        }

        // 完整正文（净化后渲染，与详情页 $renderedContent 完全一致）
        echo \app\Helpers\Content::formatPostContent(\app\Helpers\Content::decode($thread['content'] ?? ''));
        exit;
    }

    /**
     * GET /api/cover-preview — 博客封面实时预览（只生成不落盘）
     *
     * 参数白名单清洗：template / title / author / date 均直接传给
     * CoverTemplate::generateCover()（内部统一 htmlspecialchars 转义，防 SVG 注入）；
     * template 不在白名单内由 generateCover 静默回退 crystal，绝不抛异常。
     * 响应：Content-Type: image/svg+xml（浏览器 <img> 直接显示）。
     */
    public function coverPreview()
    {
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        $title    = (string)($_GET['title'] ?? '');
        $author   = (string)($_GET['author'] ?? '');
        $date     = (string)($_GET['date'] ?? '');
        $template = (string)($_GET['template'] ?? '');

        // 标题过长截断（预览与落盘语义一致：generateCover 内部已按 2 行截断，
        // 这里仅做请求级长度保护，防恶意超长参数撑爆响应）
        if (\mb_strlen($title) > 500) {
            $title = \mb_substr($title, 0, 500);
        }
        if (\mb_strlen($author) > 100) {
            $author = \mb_substr($author, 0, 100);
        }
        if (\mb_strlen($date) > 50) {
            $date = \mb_substr($date, 0, 50);
        }

        echo \app\Helpers\CoverTemplate::generateCover($title, $author, $date, $template);
        exit;
    }

    /**
     * GET /api/favorites-count — 用户收藏总数（htmx 懒加载）
     *
     * 供 PC 用户下拉「我的收藏」徽标使用：hx-trigger="revealed" 下拉展开时才请求，
     * 页面渲染零查询。登录校验 + Session 30 秒缓存 + 博客模式隐藏；返回纯数字文本
     * （htmx hx-swap="innerHTML" 直接填充徽标）。
     */
    public function favoritesCount()
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        // 博客模式（blog）下论坛关闭，收藏无意义 → 返回空（徽标不显示）
        if (\app\Helpers\Settings::get('site_mode', 'portal') === 'blog') {
            echo '';
            exit;
        }
        if (!Auth::isLoggedIn()) {
            http_response_code(401);
            echo '';
            exit;
        }
        $user = Auth::getCurrentUser();
        $userId = (int)($user['id'] ?? 0);
        if (!$userId) {
            http_response_code(401);
            echo '';
            exit;
        }

        // Session 缓存（30 秒过期），避免每页请求都查 COUNT（与插件 nav_user_menu_items 一致）
        $cacheKey = '_fav_count_' . $userId;
        $now = time();
        if (isset($_SESSION[$cacheKey]) && ($now - $_SESSION[$cacheKey]['time']) < 30) {
            $count = $_SESSION[$cacheKey]['count'];
        } else {
            try {
                $count = \Plugin\PostFavorite\Plugin::getFavoriteCount($userId);
                $_SESSION[$cacheKey] = ['count' => $count, 'time' => $now];
            } catch (\Throwable $e) {
                $count = 0;
            }
        }

        echo (int)$count;
        exit;
    }

    protected function json($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * 安全的 Referer 回跳 — 仅允许本站域名，避免开放重定向
     */
    private static function safeReferer(): string
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if ($referer === '') return '/';
        $host = parse_url($referer, PHP_URL_HOST);
        // 从 SITE_URL 常量中提取站点域名，不依赖 HTTP_HOST（防 Host 头注入）
        $siteHost = parse_url(SITE_URL, PHP_URL_HOST) ?: '';
        if ($host === null || $host === '' || $host === $siteHost || $host === 'localhost' || $host === '127.0.0.1') {
            return $referer;
        }
        return '/';
    }

    protected function jsonError($msg, $status = 400)
    {
        $this->json(['success' => false, 'error' => $msg], $status);
    }
}
