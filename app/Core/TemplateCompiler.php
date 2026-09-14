<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 模板编译器 — 布局继承、区块输出、图标映射
 * @file app/Core/TemplateCompiler.php
 * @package app\Core
 */

namespace app\Core;

class TemplateCompiler
{
    protected $viewPath;
    protected $layoutPath;
    protected $themePath;
    protected $cachePath;
    protected $sections = [];
    protected $currentSection;
    protected $data = [];
    protected $isCompiledView = false;

    public function __construct()
    {
        $this->viewPath = __DIR__ . '/../Views/';
        $this->layoutPath = $this->viewPath . 'layouts/';
        $this->cachePath = __DIR__ . '/../../protected/cache/templates/';

        // 自动创建缓存目录（0750：默认用户/组可读写，收紧 group/other 可读，防共享主机缓存泄露）
        if (!is_dir($this->cachePath)) {
            @mkdir($this->cachePath, 0750, true);
        }

        // 主题覆盖层：当前主题在 assets/themes/ 下有同名目录时，
        // 前台视图优先从该目录加载，缺失自动回退 app/Views（Xenforo 等目录主题通用）
        try {
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            $isAdminRequest = strpos($requestUri, '/admin') === 0;
            if (!$isAdminRequest) {
                $current = \app\Helpers\Theme::getCurrent();
                if ($current !== '') {
                    $themeDir = \dirname(__DIR__, 2) . '/assets/themes/' . $current;
                    if (is_dir($themeDir)) {
                        $this->themePath = rtrim($themeDir, '/\\') . '/';
                    }
                }
            }
        } catch (\Throwable $e) {
            // 主题探测异常时保持默认单模板体系
        }
    }

    protected function resolveTemplate($template)
    {
        // 尝试 .php（主题）
        if ($this->themePath) {
            $f = $this->themePath . $template . '.php';
            if (file_exists($f)) return ['path' => $f, 'type' => 'php'];
        }
        // 尝试 .html（主题）
        if ($this->themePath) {
            $f = $this->themePath . $template . '.html';
            if (file_exists($f)) return ['path' => $f, 'type' => 'html'];
        }
        // 插件目录优先：view('plugins/xxx/yyy') 先查 站点根/plugins/xxx/views/yyy.php
        if (strpos($template, 'plugins/') === 0) {
            $slash = strpos($template, '/', strlen('plugins/'));
            if ($slash !== false) {
                $pluginId = substr($template, strlen('plugins/'), $slash - strlen('plugins/'));
                $rest = substr($template, $slash + 1);
                if ($pluginId !== '' && $rest !== '') {
                    $pluginBase = \dirname(__DIR__, 2) . '/plugins/' . $pluginId . '/views/';
                    $pf = $pluginBase . $rest . '.php';
                    if (file_exists($pf)) return ['path' => $pf, 'type' => 'php'];
                    $pf = $pluginBase . $rest . '.html';
                    if (file_exists($pf)) return ['path' => $pf, 'type' => 'html'];
                }
            }
        }
        // 尝试 .php（默认视图）
        $f = $this->viewPath . $template . '.php';
        if (file_exists($f)) return ['path' => $f, 'type' => 'php'];
        // 尝试 .html（默认视图）
        $f = $this->viewPath . $template . '.html';
        if (file_exists($f)) return ['path' => $f, 'type' => 'html'];

        return null;
    }

    protected function compile($html)
    {
        // 1. 注释 {* ... *}
        $html = preg_replace('/\{\*.*?\*\}/s', '', $html);

        // 2. 变量输出 {$var}
        $html = preg_replace_callback('/\{(\$[a-zA-Z_][a-zA-Z0-9_\[\]\'\"\->]*?)\}/', [$this, 'cbVar'], $html);

        // 3. 原始输出 {$var:raw}
        $html = preg_replace_callback('/\{(\$[a-zA-Z_][a-zA-Z0-9_\[\]\'\"\->]*?):raw\}/', [$this, 'cbRaw'], $html);

        // 3b. 图标 {$icon:name}
        $html = preg_replace_callback('/\{\\$icon:([a-zA-Z_][a-zA-Z0-9_-]*?)\}/', [$this, 'cbIcon'], $html);

        // 3c. 多语言文案 {$t:key}（key 含点号如 forum.latest）
        $html = preg_replace_callback('/\{\$t:([a-zA-Z0-9_.\-]+?)\}/', [$this, 'cbT'], $html);

        // 4. {if} / {elseif} / {else} / {/if}
        $html = preg_replace_callback('/\{if\s+(.+?)\}/', [$this, 'cbIf'], $html);
        $html = preg_replace_callback('/\{elseif\s+(.+?)\}/', [$this, 'cbElseif'], $html);
        $html = preg_replace('/\{else\}/', '<?php else: ?>', $html);
        $html = preg_replace('/\{\/if\}/', '<?php endif; ?>', $html);

        // 5. {loop}
        // 安全：数组变量捕获组收紧为「标识符 + [数组键] + ' 引号 -> 属性」字符集（不含括号/空白），
        // 禁止 {loop $fn() $x} 这类函数调用表达式（等价于已移除的 {php} RCE 向量）。
        $loopVarPat = '[a-zA-Z_][a-zA-Z0-9_\[\]\'"\->]*';
        $html = preg_replace_callback('/\{loop\s+\$(' . $loopVarPat . ')\s+\$([a-zA-Z_][a-zA-Z0-9_]*?)\s+\$([a-zA-Z_][a-zA-Z0-9_]*?)\}/', [$this, 'cbLoopKV'], $html);
        $html = preg_replace_callback('/\{loop\s+\$(' . $loopVarPat . ')\s+\$([a-zA-Z_][a-zA-Z0-9_]*?)\}/', [$this, 'cbLoopV'], $html);
        $html = preg_replace('/\{\/loop\}/', '<?php endforeach; ?>', $html);

        // 6. {include}
        $html = preg_replace_callback('/\{include\s+([a-zA-Z0-9_\/]+?)\}/', [$this, 'cbInclude'], $html);

        // 7. {extend}
        $html = preg_replace_callback('/\{extend\s+([a-zA-Z0-9_]+?)\}/', [$this, 'cbExtend'], $html);

        // 8. {section} / {/section}
        $html = preg_replace_callback('/\{section\s+([a-zA-Z0-9_]+?)\}/', [$this, 'cbSection'], $html);
        $html = preg_replace('/\{\/section\}/', '<?php $this->endSection(); ?>', $html);

        // 9. {yield}
        $html = preg_replace_callback('/\{yield\s+([a-zA-Z0-9_]+?)\}/', [$this, 'cbYield'], $html);
        $html = preg_replace_callback('/\{yield\s+([a-zA-Z0-9_]+?)\s+(.+?)\}/', [$this, 'cbYieldDefault'], $html);

        // 10. 原始 PHP 代码（已移除——{php} 标签在主题上传场景下存在 RCE 风险）
        //     如需复杂视图逻辑，应在 Controller 中计算完成后再注入模板

        return $html;
    }

    protected function fixArrayKeys($var) {
        return preg_replace('/\[([a-zA-Z_][a-zA-Z0-9_]*)\]/', "['$1']", $var);
    }

    protected function cbVar($m) { return '<?php echo htmlspecialchars(' . $this->fixArrayKeys($m[1]) . ' ?? \'\', ENT_QUOTES, \'UTF-8\'); ?>'; }
    protected function cbRaw($m) { return '<?php echo ' . $this->fixArrayKeys($m[1]) . ' ?? \'\'; ?>'; }
    protected function cbIcon($m) { return '<?php echo $this->icon(\'' . $m[1] . '\'); ?>'; }
    protected function cbT($m) { return '<?php echo $this->t(\'' . $m[1] . '\'); ?>'; }
    protected function cbIf($m) { return '<?php if (' . $this->safeExpr($this->fixArrayKeys($m[1])) . '): ?>'; }
    protected function cbElseif($m) { return '<?php elseif (' . $this->safeExpr($this->fixArrayKeys($m[1])) . '): ?>'; }

    /**
     * 安全校验模板表达式：只允许变量/数组/比较运算符/逻辑运算符/括号，防 PHP 注入
     */
    protected function safeExpr(string $expr): string
    {
        // 只允许：字母数字下划线 $ ' " [ ] ( ) isset ! empty 空格 比较运算符
        if (preg_match('/^[\$\w\s\'\"\[\]\(\)\!\?\:\|\&\=\<\>\.\,]+$/', $expr)) {
            // 拒绝函数调用模式（如 system("id") / $fn()），仅放行 isset / empty
            if (preg_match('/(?:[a-zA-Z_][a-zA-Z0-9_]*|\$\w+)\s*\(/', $expr, $m)) {
                $call = strtolower(\ltrim($m[0]));
                if (strpos($call, 'isset') !== 0 && strpos($call, 'empty') !== 0) {
                    return 'false';
                }
            }
            return $expr;
        }
        return 'false';
    }
    protected function cbLoopV($m) { return '<?php foreach ($' . $this->fixArrayKeys($m[1]) . ' as $' . $m[2] . '): ?>'; }
    protected function cbLoopKV($m) { return '<?php foreach ($' . $this->fixArrayKeys($m[1]) . ' as $' . $m[2] . ' => $' . $m[3] . '): ?>'; }
    protected function cbInclude($m) { return '<?php $this->include(\'' . $m[1] . '\'); ?>'; }
    protected function cbExtend($m) { return '<?php $this->extend(\'' . $m[1] . '\'); ?>'; }
    protected function cbSection($m) { return '<?php $this->section(\'' . $m[1] . '\'); ?>'; }
    protected function cbYield($m) { return '<?php $this->yield(\'' . $m[1] . '\'); ?>'; }
    protected function cbYieldDefault($m)
    {
        // 安全：默认值不再原样拼入 PHP 代码（防 {yield x system('id')} 表达式注入，与 {php} 同风险）。
        // 规则：引号包裹且闭合 → 去外层引号按纯文本处理；未加引号 → 同样按纯文本处理。
        // 统一转义后以 PHP 单引号字符串字面量输出，保证只作为默认文案、绝不执行。
        $name = $m[1];
        $raw = \trim($m[2]);
        $content = $raw;
        if (\strlen($raw) >= 2 && ($raw[0] === "'" || $raw[0] === '"') && \substr($raw, -1) === $raw[0]) {
            $content = \substr($raw, 1, -1);
        }
        $esc = \str_replace(['\\', "'"], ['\\\\', "\\'"], $content);
        return '<?php $this->yield(\'' . $name . '\', \'' . $esc . '\'); ?>';
    }

    public function display($template, $data = [])
    {
        $this->data = array_merge($this->data, $data);
        $extracted = $this->data;
        extract($extracted, EXTR_SKIP);

        $resolveResult = $this->resolveTemplate($template);
        if (!$resolveResult) {
            // 静默失败：缺失模板不向页面输出任何路径/注释（防泄露内部模板结构），仅记服务端日志
            \error_log('Template not found: ' . $template . ' (' . ($_SERVER['REQUEST_URI'] ?? 'cli') . ')');
            return;
        }

        if ($resolveResult['type'] === 'php') {
            require $resolveResult['path'];
        } else {
            $this->renderOne($resolveResult['path'], $extracted);
        }

        // 布局
        $layout = $this->sections['__layout'] ?? null;
        if ($layout) {
            unset($this->sections['__layout']);
            $layoutResult = $this->resolveTemplate('layouts/' . $layout);
            if ($layoutResult) {
                if ($layoutResult['type'] === 'php') {
                    extract($extracted, EXTR_SKIP);
                    require $layoutResult['path'];
                } else {
                    $this->renderOne($layoutResult['path'], $extracted);
                }
            } else {
                $default = $this->layoutPath . $layout . '.php';
                if (file_exists($default)) {
                    extract($extracted, EXTR_SKIP);
                    require $default;
                }
            }
        }
    }

    protected function renderOne($htmlFile, $data)
    {
        $cacheKey = 'tpl_' . md5($htmlFile) . '.php';
        $cacheFile = $this->cachePath . $cacheKey;

        if (!file_exists($cacheFile) || filemtime($cacheFile) < filemtime($htmlFile)) {
            $html = file_get_contents($htmlFile);
            $php = $this->compile($html);
            // 原子写入：先写临时文件再 rename，防并发读到部分写入的缓存
            $tmpFile = $this->cachePath . 'tmp_' . bin2hex(random_bytes(8)) . '.php';
            if (file_put_contents($tmpFile, $php) !== false) {
                rename($tmpFile, $cacheFile);
            }
        }

        extract($data, EXTR_SKIP);
        require $cacheFile;
    }

    // ==== 区块方法（模板中调用） ====

    public function section($name)
    {
        $this->currentSection = $name;
        ob_start();
    }

    public function endSection()
    {
        $content = ob_get_clean();
        if ($this->currentSection) {
            $this->sections[$this->currentSection] = $content;
            $this->currentSection = null;
        }
    }

    public function yield($name, $default = '')
    {
        echo $this->sections[$name] ?? $default;
    }

    public function extend($layout)
    {
        $this->sections['__layout'] = $layout;
    }

    public function include($template, $extra = [])
    {
        $data = array_merge($this->data, $extra);

        $r = $this->resolveTemplate($template);
        if (!$r) return;
        if ($r['type'] === 'php') {
            extract($data, EXTR_SKIP);
            require $r['path'];
        } else {
            $this->renderOne($r['path'], $data);
        }
    }

    public function e($value)
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }

    /**
     * 多语言文案：取当前语言包 key（缺 key 回退中文 → key 本身，永不白屏）
     *
     * @param string $key    语义化 key（如 forum.latest）
     * @param array  $params 占位符替换（{name} => 值）
     */
    public function t($key, array $params = [])
    {
        return \app\Helpers\I18n::get((string)$key, $params);
    }

    public function getData($key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function timeAgo($datetime)
    {
        if (empty($datetime)) return '';
        $timestamp = strtotime($datetime);
        if ($timestamp === false) return $datetime;
        $diff = time() - $timestamp;
        $t = static fn(string $key, array $params = []) => \app\Helpers\I18n::get($key, $params);
        if ($diff < 60) return $t('time.just_now');
        if ($diff < 3600) return $t('time.minutes_ago', ['count' => intval($diff / 60)]);
        if ($diff < 86400) return $t('time.hours_ago', ['count' => intval($diff / 3600)]);
        if ($diff < 2592000) return $t('time.days_ago', ['count' => intval($diff / 86400)]);
        if ($diff < 31536000) return $t('time.months_ago', ['count' => intval($diff / 2592000)]);
        return $t('time.years_ago', ['count' => intval($diff / 31536000)]);
    }

    public function decodeContent($data)
    {
        return \app\Helpers\Content::decode($data);
    }

    public function formatPostContent($content)
    {
        return \app\Helpers\Content::formatPostContent($content);
    }

    public function highlightSearchTerms($text, $query)
    {
        return \app\Helpers\Search::highlightTerms($text, $query);
    }

    /**
     * 静态资源 URL（自动加文件修改时间作为版本号，防浏览器缓存）
     */
    public function asset($path)
    {
        $base = \dirname(__DIR__, 2);
        $fullPath = $base . '/assets/' . \ltrim($path, '/');
        $ver = \file_exists($fullPath) ? \filemtime($fullPath) : \time();
        $bp = \defined('BASE_PATH') ? \BASE_PATH : '';
        return $bp . '/assets/' . \ltrim($path, '/') . '?v=' . $ver;
    }

    /**
     * 生成带 BASE_PATH 的 URL（供视图模板使用）
     * @param string $path 以 / 开头的路径，如 /forum
     * @return string 带 BASE_PATH 前缀的完整 URL
     */
    public function url($path)
    {
        $bp = \defined('BASE_PATH') ? \BASE_PATH : '';
        if ($bp === '') return $path;
        return $bp . $path;
    }

    /**
     * 统一分页条渲染（前台 mn-* 体系 / 后台 .pagination 体系）
     *
     * @param int    $page       当前页码
     * @param int    $totalPages 总页数（<=1 时不渲染分页条）
     * @param string $url        URL 模板，页码用 {page} 占位符替换
     *                           如 '/forum?page={page}'、'/blog/5?comment_page={page}#comments'
     * @param array  $opts       可选：style=mn(默认，前台)/admin(后台)；window=窗口大小(默认2)；
     *                           prevnext=是否显示上一页/下一页(前台默认true，后台默认false)；
     *                           class=容器附加 CSS 类（如 'thread-pagination'）
     * @return string
     */
    public function pagination($page, $totalPages, $url, $opts = [])
    {
        $page = max(1, (int)$page);
        $totalPages = (int)$totalPages;
        if ($totalPages <= 1) return '';

        $window = max(1, (int)($opts['window'] ?? 2));
        // 前后台统一分页样式（mn-pagination/mn-page-link）；prevnext 默认显示，旧 style=admin 参数兼容忽略
        $showPrevNext = $opts['prevnext'] ?? true;
        // keyset 游标分页：可传入自定义上一页/下一页 URL（携带 after/before 游标），未提供时保持 ?page=N±1
        $prevUrl = $opts['prevUrl'] ?? null;
        $nextUrl = $opts['nextUrl'] ?? null;

        $container = 'mn-pagination';
        if (!empty($opts['class'])) $container .= ' ' . $opts['class'];
        $linkClass = 'mn-page-link';
        $activeClass = 'mn-active';
        $dotsClass = 'mn-page-dots';

        $href = function ($i) use ($url) {
            $replaced = str_replace('{page}', (string)$i, $url);
            // 以 / 开头的完整路径拼 BASE_PATH；以 ?/# 开头的相对查询串原样输出（如搜索页 ?keyword=..&page=）
            if (strpos($url, '/') === 0) {
                return $this->url($replaced);
            }
            return $replaced;
        };
        $link = function ($i, $active = false) use ($href, $linkClass, $activeClass) {
            $cls = $linkClass;
            if ($active) $cls = trim($cls . ' ' . $activeClass);
            return '<a href="' . $href($i) . '"' . ($cls !== '' ? ' class="' . $cls . '"' : '') . '>' . $i . '</a>';
        };

        $html = '<div class="' . $container . '">';

        // 游标模式（$page 恒为 1）下只要提供了 prevUrl/nextUrl 也渲染翻页按钮
        if ($showPrevNext && ($page > 1 || $prevUrl !== null)) {
            $prevHref = $prevUrl !== null ? $prevUrl : $href($page - 1);
            $html .= '<a href="' . $prevHref . '"' . ($linkClass !== '' ? ' class="' . $linkClass . '"' : '') . '>← ' . \app\Helpers\I18n::get('pagination.prev') . '</a>';
        }

        $start = max(1, $page - $window);
        $end = min($totalPages, $page + $window);

        if ($start > 1) {
            $html .= $link(1);
            if ($start > 2) $html .= '<span class="' . $dotsClass . '">...</span>';
        }

        for ($i = $start; $i <= $end; $i++) {
            $html .= $link($i, $i === $page);
        }

        if ($end < $totalPages) {
            if ($end < $totalPages - 1) $html .= '<span class="' . $dotsClass . '">...</span>';
            $html .= $link($totalPages);
        }

        if ($showPrevNext && $page < $totalPages) {
            $nextHref = $nextUrl !== null ? $nextUrl : $href($page + 1);
            $html .= '<a href="' . $nextHref . '"' . ($linkClass !== '' ? ' class="' . $linkClass . '"' : '') . '>' . \app\Helpers\I18n::get('pagination.next') . ' →</a>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * 输出 FontAwesome 图标
     *
     * @param string $name 图标名称
     * @param int $size 尺寸（像素）
     * @param string $extraClass 额外 CSS 类
     * @return string HTML
     */
    public function icon($name, $size = 16, $extraClass = '')
    {
        static $map = [
            'home'          => '&#xf015;',   // 首页
            'forum'         => '&#xf086;',   // 论坛/版块（comments）
            'blog'          => '&#xf15c;',   // 博客（file-alt）
            'search'        => '&#xf002;',   // 搜索
            'tags'          => '&#xf02c;',   // 标签管理
            'theme'         => '&#xf53f;',   // 主题/外观（调色板）
            'profile'       => '&#xf007;',   // 个人中心
            'message'       => '&#xf0e0;',   // 消息/私信
            'logout'        => '&#xf08b;',   // 退出登录
            'login'         => '&#xf090;',   // 登录
            'register'      => '&#xf234;',   // 注册
            'admin'         => '&#xf013;',   // 后台管理/设置
            'back'          => '&#xf060;',   // 返回
            'new-post'      => '&#xf055;',   // 新建帖子
            'write'         => '&#xf040;',   // 撰写/编辑
            'edit'          => '&#xf044;',   // 编辑
            'delete'        => '&#xf1f8;',   // 删除
            'quote'         => '&#xf10d;',   // 引用
            'attachment'    => '&#xf0c6;',   // 附件
            'save'          => '&#xf0c7;',   // 保存
            'upload'        => '&#xf093;',   // 上传
            'add'           => '&#xf067;',   // 添加/新增
            'dashboard'     => '&#xf0e4;',   // 仪表盘/概览
            'settings'      => '&#xf013;',   // 设置
            'categories'    => '&#xf009;',   // 分类管理
            'threads'       => '&#xf15c;',   // 帖子列表（file-alt）
            'trash'         => '&#xf2ed;',   // 回收站/删除（trash-can）
            'tag'           => '&#xf02b;',   // 标签
            'users'         => '&#xf0c0;',   // 用户管理
            'user'          => '&#xf007;',   // 用户
            'levels'        => '&#xf0ae;',   // 等级（stairs）
            'database'      => '&#xf1c0;',   // 数据库
            'maintenance'   => '&#xf0ad;',   // 维护/工具
            'blog-cat'      => '&#xf07c;',   // 博客分类
            'blog-manage'   => '&#xf0f6;',   // 博客管理
            'nav-group'     => '&#xf07c;',   // 导航分组
            'blog-write'    => '&#xf040;',   // 写博客
            'write-blog'    => '&#xf040;',   // 写博客（别名）
            'pinned'        => '&#xf08d;',   // 置顶
            'highlight'     => '&#xf005;',   // 精华/高亮
            'reply'         => '&#xf4ad;',   // 回复（气泡省略号）
            'views'         => '&#xf06e;',   // 浏览量
            'eye'           => '&#xf06e;',   // 眼睛/查看（views 别名）
            'calendar'      => '&#xf073;',   // 日历/日期
            'like'          => '&#xf087;',   // 赞/喜欢
            'dislike'       => '&#xf088;',   // 踩/不喜欢
            'like-small'    => '&#xf087;',   // 赞（小尺寸）
            'dislike-small' => '&#xf088;',   // 踩（小尺寸）
            'announcement'  => '&#xf0a1;',   // 公告
            'lock'          => '&#xf023;',   // 锁定/加密
            'reply-lock'    => '&#xf023;',   // 回复锁定（lock 别名）
            'image'         => '&#xf03e;',   // 图片
            'verify'        => '&#xf058;',   // 验证/认证
            'info'          => '&#xf05a;',   // 信息/提示
            'new-thread'    => '&#xf055;',   // 新建主题（new-post 别名）
            'menu'          => '&#xf0c9;',   // 菜单/导航
            'check-all'     => '&#xf046;',   // 全选/多选
            'chevron-down'  => '&#xf078;',   // 下箭头
            'plugin'        => '&#xf12e;',   // 插件/应用
            'star'          => '&#xf006;',   // 星标/收藏（空心星，区别于 highlight 实心星）
            'heart'         => '&#xf004;',   // 喜欢/关注
            'clock'         => '&#xf017;',   // 时间/历史
            'time'          => '&#xf017;',   // clock 别名
            'history'       => '&#xf017;',   // clock 别名
            'download'      => '&#xf019;',   // 下载
            'refresh'       => '&#xf021;',   // 刷新/同步
            'sync'          => '&#xf021;',   // refresh 别名
            'filter'        => '&#xf0b0;',   // 过滤
            'sort'          => '&#xf0dc;',   // 排序
            'share'         => '&#xf064;',   // 分享
            'flag'          => '&#xf024;',   // 举报/标记
            'report'        => '&#xf024;',   // flag 别名
            'crown'         => '&#xf521;',   // 皇冠/VIP
            'gift'          => '&#xf06b;',   // 礼物/奖励
            'award'         => '&#xf559;',   // 奖项
            'trophy'        => '&#xf091;',   // 奖杯/排行
            'chat'          => '&#xf075;',   // 对话/评论
            'comment'       => '&#xf075;',   // chat 别名
            'code'          => '&#xf121;',   // 代码
            'copy'          => '&#xf0c5;',   // 复制
            'link'          => '&#xf0c1;',   // 链接
            'external-link' => '&#xf08e;',   // 外链
            'warning'       => '&#xf071;',   // 警告
            'alert'         => '&#xf071;',   // warning 别名
            'check'         => '&#xf00c;',   // 勾选/确认
            'close'         => '&#xf00d;',   // 关闭
            'x'             => '&#xf00d;',   // close 别名
            'list'          => '&#xf03a;',   // 列表视图
            'grid'          => '&#xf00a;',   // 网格视图
            'map-marker'    => '&#xf041;',   // 定位
            'location'      => '&#xf041;',   // map-marker 别名
            'mail'          => '&#xf0e0;',   // 邮件(message 别名)
            'mobile'        => '&#xf10b;',   // 移动端
            'phone'         => '&#xf095;',   // 电话
            // ===== 第三方平台登录图标（均取自已确认存在于系统 woff2 的字形）=====
            'github'        => '&#xf121;',   // GitHub 登录 -> 用「代码」图标
            'qq'            => '&#xf075;',   // QQ 登录     -> 用「单聊气泡」图标
            'wechat'        => '&#xf086;',   // 微信登录   -> 用「双对话气泡」图标(provider名wechat)
            'weixin'        => '&#xf086;',   // 微信登录   -> wechat 别名（兼容"weixin"叫法）
            'sms'           => '&#xf10b;',   // 短信登录   -> 用「手机」图标
            'rss'           => '&#xf09e;',   // RSS
            'print'         => '&#xf02f;',   // 打印
            'play'          => '&#xf04b;',   // 播放
            'pause'         => '&#xf04c;',   // 暂停
            'forward'       => '&#xf04e;',   // 快进
            'backward'      => '&#xf04a;',   // 快退
            'undo'          => '&#xf0e2;',   // 撤销
            'redo'          => '&#xf01e;',   // 重做
            'bookmark'      => '&#xf02e;',   // 书签
            // ===== FA 6 新增图标 =====
            'dice'          => '&#xf522;',   // 🎲 骰子
            'dice-d6'       => '&#xf6d1;',   // 骰子六面
            'coins'         => '&#xf53a;',   // 🪙 硬币/积分（money-bill-wave）
            'swords'        => '&#xf05b;',   // ⚔️ 对决/战斗（crosshairs）
            'money'         => '&#xf0d6;',   // 💰 金额（money-bill）
            'sack'          => '&#xf81d;',   // 钱袋/彩池（sack-dollar）
            'fight'         => '&#xf6de;',   // 拳头/对决（hand-fist）
            'target'        => '&#xf05b;',   // 准星/挑战（crosshairs）
            'question'      => '&#xf059;',   // 问号/帮助（question-circle）
            'info-circle'   => '&#xf05a;',   // 信息（info-circle，info 的别名）
            'check-circle'  => '&#xf058;',   // 勾选圆圈（verify 的别名）
            'times-circle'  => '&#xf057;',   // 叉号圆圈
            'plus-circle'   => '&#xf055;',   // 加号圆圈（new-post/new-thread 的别名）
            'minus-circle'  => '&#xf056;',   // 减号圆圈
            'circle'        => '&#xf111;',   // 圆圈
            'dot-circle'    => '&#xf192;',   // 实心圆点
            'hand'          => '&#xf256;',   // 手/操作（hand-paper）
            'thumbs-up'     => '&#xf164;',   // 赞（like 的别名）
            'thumbs-down'   => '&#xf165;',   // 踩（dislike 的别名）
            'angle-up'      => '&#xf106;',   // 上尖角
            'angle-down'    => '&#xf107;',   // 下尖角
            'angle-left'    => '&#xf104;',   // 左尖角
            'angle-right'   => '&#xf105;',   // 右尖角
            'caret-up'      => '&#xf0d8;',   // 上三角
            'caret-down'    => '&#xf0d7;',   // 下三角
            'star-half'     => '&#xf089;',   // 半星
            'star-fill'     => '&#xf005;',   // 实心星（highlight 的别名）
            'bell'          => '&#xf0f3;',   // 铃铛/通知
            'bell-slash'    => '&#xf1f6;',   // 静音
            'envelope'      => '&#xf0e0;',   // 信封（message 的别名）
            'envelope-open' => '&#xf2b6;',   // 打开的信封
            'paper-plane'   => '&#xf1d8;',   // 纸飞机/发送
            'send'          => '&#xf1d8;',   // 发送（paper-plane 的别名）
            'rocket'        => '&#xf135;',   // 火箭/加速
            'plane'         => '&#xf072;',   // 飞机
            'truck'         => '&#xf0d1;',   // 卡车/运输
            'shield'        => '&#xf132;',   // 盾牌/安全
            'shield-alt'    => '&#xf3ed;',   // 盾牌变体
            'bug'           => '&#xf188;',   // 虫子/调试
            'ban'           => '&#xf05e;',   // 禁止/停止
            'slash'         => '&#xf715;',   // 斜杠/禁用
            'eye-slash'     => '&#xf070;',   // 不可见
            'unlock'        => '&#xf09c;',   // 解锁
            'unlock-alt'    => '&#xf13e;',   // 解锁变体
            'key'           => '&#xf084;',   // 钥匙
            'wrench'        => '&#xf0ad;',   // 扳手（maintenance 的别名）
            'hammer'        => '&#xf6e3;',   // 锤子
            'screwdriver'   => '&#xf54a;',   // 螺丝刀
            'toolbox'       => '&#xf552;',   // 工具箱
            'magic'         => '&#xf0d0;',   // 魔棒
            'wand'          => '&#xf0d0;',   // 魔棒（magic 的别名）
            'palette'       => '&#xf53f;',   // 调色板/主题
            'paint-brush'   => '&#xf1fc;',   // 画笔（theme 的别名）
            'paint-roller'  => '&#xf5aa;',   // 滚筒
            'pen'           => '&#xf304;',   // 钢笔
            'pen-alt'       => '&#xf305;',   // 钢笔变体
            'pen-fancy'     => '&#xf5ac;',   // 花式钢笔
            'pen-nib'       => '&#xf5ad;',   // 笔尖
            'marker'        => '&#xf5a1;',   // 标记笔
            'eraser'        => '&#xf12d;',   // 橡皮擦
            'clipboard'     => '&#xf328;',   // 剪贴板
            'clipboard-list'=> '&#xf46d;',   // 清单
            'board'         => '&#xf009;',   // 面板/分类（categories 的别名）
            'chart-bar'     => '&#xf080;',   // 柱状图
            'chart-line'    => '&#xf201;',   // 折线图
            'chart-pie'     => '&#xf200;',   // 饼图
            'chart-area'    => '&#xf1fe;',   // 面积图
            'signal'        => '&#xf012;',   // 信号
            'wifi'          => '&#xf1eb;',   // WiFi
            'battery-full'  => '&#xf240;',   // 满电
            'battery-empty' => '&#xf244;',   // 空电
            'power-off'     => '&#xf011;',   // 关机
            'volume-up'     => '&#xf028;',   // 音量+
            'volume-down'   => '&#xf027;',   // 音量-
            'volume-off'    => '&#xf026;',   // 静音
            'mute'          => '&#xf026;',   // 静音（volume-off 的别名）
            'music'         => '&#xf001;',   // 音乐
            'video'         => '&#xf03d;',   // 视频
            'film'          => '&#xf008;',   // 胶片
            'camera'        => '&#xf030;',   // 相机
            'camera-retro'  => '&#xf083;',   // 复古相机
            'microphone'    => '&#xf130;',   // 麦克风
            'headphones'    => '&#xf025;',   // 耳机
            'tv'            => '&#xf26c;',   // 电视
            'laptop'        => '&#xf109;',   // 笔记本
            'tablet'        => '&#xf10a;',   // 平板
            'desktop'       => '&#xf108;',   // 台式机
            'server'        => '&#xf233;',   // 服务器
            'hdd'           => '&#xf0a0;',   // 硬盘
            'database'      => '&#xf1c0;',   // 数据库
            'cloud'         => '&#xf0c2;',   // 云
            'cloud-upload'  => '&#xf0ee;',   // 云上传
            'cloud-download'=> '&#xf0ed;',   // 云下载
            'globe'         => '&#xf0ac;',   // 地球/网络
            'globe-asia'    => '&#xf57e;',   // 亚洲地图
            'compass'       => '&#xf14e;',   // 指南针
            'map'           => '&#xf279;',   // 地图
            'map-pin'       => '&#xf276;',   // 地图标记
            'route'         => '&#xf4d7;',   // 路线
            'flag-checkered'=> '&#xf11e;',   // 格子旗
            'flag-alt'      => '&#xf024;',   // 旗帜（flag 的别名）
            'book'          => '&#xf02d;',   // 书
            'book-open'     => '&#uf518;',   // 打开的书
            'newspaper'     => '&#xf1ea;',   // 报纸
            'file'          => '&#xf15b;',   // 文件
            'file-alt'      => '&#xf15c;',   // 文件变体
            'file-archive'  => '&#xf1c6;',   // 压缩文件
            'file-pdf'      => '&#xf1c1;',   // PDF
            'file-word'     => '&#xf1c2;',   // Word
            'file-excel'    => '&#xf1c3;',   // Excel
            'file-image'    => '&#xf1c5;',   // 图片文件
            'file-code'     => '&#xf1c9;',   // 代码文件
            'folder'        => '&#xf07b;',   // 文件夹
            'folder-open'   => '&#xf07c;',   // 打开的文件夹
            'folder-plus'   => '&#xf65e;',   // 添加文件夹
            'folder-minus'  => '&#xf65d;',   // 删除文件夹
            'terminal'      => '&#xf120;',   // 终端
            'console'       => '&#xf120;',   // 控制台（terminal 的别名）
            'command'       => '&#xf142;',   // 命令
            'cube'          => '&#uf1b2;',   // 立方体
            'cubes'         => '&#uf1b3;',   // 多个立方体
            'puzzle-piece'  => '&#xf12e;',   // 拼图/插件（plugin 的别名）
            'puzzle'        => '&#xf12e;',   // 拼图（plugin 的别名）
            'grip'          => '&#xf58d;',   // 网格/应用
            'grip-vertical' => '&#xf58e;',   // 垂直网格
            'columns'       => '&#xf0db;',   // 列
            'rows'          => '&#xf0ce;',   // 行
            'table'         => '&#xf0ce;',   // 表格（rows 的别名）
            'merge'         => '&#xf526;',   // 合并
            'split'         => '&#xf527;',   // 拆分
            'random'        => '&#xf074;',   // 随机
            'retweet'       => '&#xf079;',   // 转发
            'exchange'      => '&#xf0ec;',   // 交换
            'repeat'        => '&#xf01e;',   // 重复（redo 的别名）
            'recycle'       => '&#xf1b8;',   // 回收
            'broom'         => '&#xf51a;',   // 扫帚
            'trash-restore' => '&#xf829;',   // 恢复删除
            'trash-alt'     => '&#xf2ed;',   // 删除（trash 的 FA 6 版）
            'trash-alt-alt' => '&#xf1f8;',   // 删除变体（delete 的别名）
            // ===== FA 6 扩展图标 =====
            'dog'           => '&#xf6d3;',   // 狗
            'cat'           => '&#xf6be;',   // 猫
            'paw'           => '&#xf1b0;',   // 爪子
            'horse'         => '&#xf6f0;',   // 马
            'fish'          => '&#xf578;',   // 鱼
            'apple-alt'     => '&#xf5d1;',   // 苹果
            'carrot'        => '&#xf787;',   // 胡萝卜
            'lemon'         => '&#xf094;',   // 柠檬
            'cheese'        => '&#xf7ef;',   // 奶酪
            'bread'         => '&#xf7ec;',   // 面包
            'pizza'         => '&#xf818;',   // 披萨
            'cake'          => '&#xf1fd;',   // 蛋糕
            'cookie'        => '&#xf563;',   // 饼干
            'coffee'        => '&#xf0f4;',   // 咖啡
            'beer'          => '&#xf0fc;',   // 啤酒
            'wine'          => '&#xf4e3;',   // 酒杯
            'utensils'      => '&#xf2e7;',   // 餐具
            'sun'           => '&#xf185;',   // 太阳
            'moon'          => '&#xf186;',   // 月亮
            'snowflake'     => '&#xf2dc;',   // 雪花
            'rainbow'       => '&#xf75b;',   // 彩虹
            'wind'          => '&#xf72e;',   // 风
            'building'      => '&#xf1ad;',   // 建筑
            'hospital'      => '&#xf0f8;',   // 医院
            'school'        => '&#xf549;',   // 学校
            'car'           => '&#xf1b9;',   // 汽车
            'bus'           => '&#xf207;',   // 公交
            'train'         => '&#xf238;',   // 火车
            'ship'          => '&#xf21a;',   // 船
            'plane'         => '&#xf072;',   // 飞机
            'helicopter'    => '&#xf533;',   // 直升机
            'rocket'        => '&#xf135;',   // 火箭
            'bicycle'       => '&#xf206;',   // 自行车
            'anchor'        => '&#xf13d;',   // 锚
            'football'      => '&#xf44e;',   // 足球
            'basketball'    => '&#xf434;',   // 篮球
            'baseball'      => '&#xf433;',   // 棒球
            'medal'         => '&#xf5a2;',   // 奖牌
            'dumbbell'      => '&#xf44b;',   // 哑铃
            'running'       => '&#xf70c;',   // 跑步
            'swimmer'       => '&#xf5c4;',   // 游泳
            'heartbeat'     => '&#xf21e;',   // 心跳
            'stethoscope'   => '&#xf0f1;',   // 听诊器
            'syringe'       => '&#xf48e;',   // 注射器
            'pills'         => '&#xf484;',   // 药丸
            'ambulance'     => '&#xf0f9;',   // 救护车
            'microchip'     => '&#xf2db;',   // 芯片
            'cpu'           => '&#xf2db;',   // CPU（microchip）
            'keyboard'      => '&#xf11c;',   // 键盘
            'robot'         => '&#xf544;',   // 机器人
            'qrcode'        => '&#xf029;',   // 二维码
            'barcode'       => '&#xf02a;',   // 条形码
            'wallet'        => '&#xf555;',   // 钱包
            'credit-card'   => '&#xf09d;',   // 信用卡
            'piggy-bank'    => '&#xf4d3;',   // 存钱罐
            'calculator'    => '&#xf1ec;',   // 计算器
            'percent'       => '&#xf295;',   // 百分比
            'balance-scale' => '&#xf24e;',   // 天平
            'smile'         => '&#xf118;',   // 微笑
            'frown'         => '&#xf119;',   // 皱眉
            'meh'           => '&#xf11a;',   // 面无表情
            'angry'         => '&#xf556;',   // 生气
            'kiss'          => '&#xf596;',   // 亲吻
            'laugh'         => '&#xf599;',   // 大笑
            'sad-cry'       => '&#xf5b3;',   // 哭
            'surprise'      => '&#xf5c2;',   // 惊讶
            'dizzy'         => '&#xf567;',   // 晕
            'handshake'     => '&#xf2b5;',   // 握手
            'hand-peace'    => '&#xf25b;',   // 和平
            'thumbs-up'     => '&#xf164;',   // 赞
            'thumbs-down'   => '&#xf165;',   // 踩
            'ghost'         => '&#xf6e2;',   // 幽灵
            'skull'         => '&#xf54c;',   // 骷髅
            'bomb'          => '&#xf1e2;',   // 炸弹
            'jack-o-lantern'=> '&#xf6e2;',   // 南瓜灯（ghost）
            'snowman'       => '&#xf7d0;',   // 雪人
            'gift'          => '&#xf06b;',   // 礼物
            'birthday'      => '&#xf1fd;',   // 生日蛋糕
            // ===== 备用图标补充（社区站常用） =====
            // 箭头/导航
            'chevron-left'  => '&#xf053;',   // 左箭头
            'chevron-right' => '&#xf054;',   // 右箭头
            'chevron-up'    => '&#xf077;',   // 上箭头
            'arrow-up'      => '&#xf062;',   // 上箭头
            'arrow-down'    => '&#xf063;',   // 下箭头
            'arrow-right'   => '&#xf061;',   // 右箭头
            'arrow-up-long' => '&#xf176;',   // 长上箭头
            'arrow-right-long' => '&#xf178;', // 长右箭头
            'arrow-turn-up' => '&#xf148;',   // 上转箭头
            'arrow-turn-down' => '&#xf149;', // 下转箭头
            'arrows-up-down' => '&#xf07d;',  // 上下双向
            'arrows-left-right' => '&#xf07e;', // 左右双向
            'arrow-trend-up' => '&#xe098;',  // 上升趋势
            'arrow-trend-down' => '&#xe097;', // 下降趋势
            'circle-chevron-left' => '&#xf137;', // 圆形左箭头
            'circle-chevron-right' => '&#xf138;', // 圆形右箭头
            'circle-chevron-up' => '&#xf139;', // 圆形上箭头
            'circle-chevron-down' => '&#xf13a;', // 圆形下箭头
            'circle-arrow-up' => '&#xf0aa;',  // 圆形上箭头
            'circle-arrow-down' => '&#xf0ab;', // 圆形下箭头
            // 状态/操作
            'circle-check'  => '&#xf05d;',   // 圆圈对勾
            'circle-xmark'  => '&#xf05c;',   // 圆圈叉
            'circle-question' => '&#xf29c;', // 圆圈问号
            'circle-exclamation' => '&#xf06a;', // 圆圈叹号
            'square-check'  => '&#xf14a;',   // 方块对勾
            'square-plus'   => '&#xf196;',   // 方块加号
            'square-minus'  => '&#xf147;',   // 方块减号
            'square-xmark'  => '&#xf2d3;',   // 方块叉
            'square-arrow-up-right' => '&#xf14c;', // 方块外链
            'rectangle-xmark' => '&#xf410;', // 矩形叉
            'check-double'  => '&#xf560;',   // 双对勾
            'check-to-slot' => '&#xf772;',   // 投票箱
            // 分享/社交
            'share-from-square' => '&#xf14d;', // 分享（方块）
            'share-nodes'   => '&#xf1e0;',   // 分享节点
            'square-share-nodes' => '&#xf1e1;', // 分享（方块节点）
            'quote-right'   => '&#xf10e;',   // 右引号
            'envelope-open' => '&#xf2b7;',   // 打开的信封
            'envelope-open-text' => '&#xf658;', // 带文字信封
            'paper-plane'   => '&#xf1d9;',   // 纸飞机
            'comment-slash' => '&#xf4b3;',   // 评论禁用
            'reply-all'     => '&#xf122;',   // 回复全部
            'heart-crack'   => '&#xf7a9;',   // 心碎
            // 用户/身份
            'circle-user'   => '&#xf2be;',   // 圆形用户
            'user-group'    => '&#xf500;',   // 用户组
            'user-check'    => '&#xf4fc;',   // 用户验证
            'user-xmark'    => '&#xf235;',   // 用户禁用
            'user-slash'    => '&#xf506;',   // 用户隐藏
            'user-lock'     => '&#xf502;',   // 用户锁定
            'user-gear'     => '&#xf4fe;',   // 用户设置
            'user-pen'      => '&#xf4ff;',   // 用户编辑
            'user-secret'   => '&#xf21b;',   // 秘密用户
            'user-tag'      => '&#xf507;',   // 用户标签
            'id-badge'      => '&#xf2c1;',   // 身份徽章
            'id-card'       => '&#xf2c3;',   // 身份卡
            'id-card-clip'  => '&#xf47f;',   // 证件卡
            'user-graduate' => '&#xf501;',   // 毕业生
            'user-tie'      => '&#xf508;',   // 西装用户
            'ranking-star'  => '&#xe561;',   // 星级排行
            // 文件/内容
            'file-arrow-down' => '&#xf56d;', // 文件下载
            'file-arrow-up' => '&#xf574;',   // 文件上传
            'file-export'   => '&#xf56e;',   // 文件导出
            'file-import'   => '&#xf56f;',   // 文件导入
            'file-csv'      => '&#xf6dd;',   // CSV 文件
            'file-audio'    => '&#xf1c7;',   // 音频文件
            'file-video'    => '&#xf1c8;',   // 视频文件
            'file-pen'      => '&#xf31c;',   // 文件编辑
            'book-open'     => '&#xf518;',   // 打开的书
            'folder-tree'   => '&#xf802;',   // 目录树
            'folder-closed' => '&#xe185;',   // 关闭的文件夹
            'images'        => '&#xf302;',   // 多图
            'photo-film'    => '&#xf87c;',   // 照片胶片
            'image-portrait' => '&#xf3e0;',  // 人像图
            'clipboard-check' => '&#xf46c;', // 剪贴板对勾
            'clipboard-user' => '&#xf7f3;',  // 剪贴板用户
            'pencil'        => '&#xf303;',   // 铅笔
            'signature'     => '&#xf5b7;',   // 签名
            'fingerprint'   => '&#xf577;',   // 指纹
            // 工具/系统
            'gears'         => '&#xf085;',   // 齿轮组
            'sliders'       => '&#xf1de;',   // 滑块
            'screwdriver-wrench' => '&#xf7d9;', // 螺丝刀扳手
            'spinner'       => '&#xf110;',   // 旋转加载
            'circle-notch'  => '&#xf1ce;',   // 环形加载
            'rotate'        => '&#xf2f1;',   // 旋转
            'rotate-left'   => '&#xf2ea;',   // 左旋转
            'rotate-right'  => '&#xf2f9;',   // 右旋转
            'clock-rotate-left' => '&#xf1da;', // 历史时钟
            'stopwatch'     => '&#xf2f2;',   // 秒表
            'hourglass'     => '&#xf254;',   // 沙漏
            'lightbulb'     => '&#xf0eb;',   // 灯泡
            'bullseye'      => '&#xf140;',   // 靶心
            'plug'          => '&#xf1e6;',   // 插头
            'eye-dropper'   => '&#xf1fb;',   // 吸管
            'wand-sparkles' => '&#xf72b;',   // 魔杖星光
            'code-branch'   => '&#xf126;',   // 代码分支
            'code-fork'     => '&#xe13b;',   // 代码分叉
            'code-pull-request' => '&#xe13c;', // 拉取请求
            'laptop-code'   => '&#xf5fc;',   // 笔记本代码
            'desktop'       => '&#xf390;',   // 台式机
            'mobile'        => '&#xf3ce;',   // 手机
            'tablet'        => '&#xf3fb;',   // 平板
            // 交易/奖励
            'store'         => '&#xf54e;',   // 商店
            'cart-shopping' => '&#xf07a;',   // 购物车
            'cart-plus'     => '&#xf217;',   // 购物车加
            'bag-shopping'  => '&#xf290;',   // 购物袋
            'credit-card-alt' => '&#xf283;', // 信用卡（FA6）
            'ticket'        => '&#xf145;',   // 票券
            'gem'           => '&#xf3a5;',   // 宝石
            'diamond'       => '&#xf219;',   // 钻石
            'coins-alt'     => '&#xf51e;',   // 硬币（FA5）
            'money-bill-1'  => '&#xf3d1;',   // 纸币
            'money-check'   => '&#xf53c;',   // 支票
            'lock-open'     => '&#xf3c1;',   // 打开锁
            // 通知/日历/状态
            'bell-slash'    => '&#xf1f7;',   // 铃铛禁用
            'bell-concierge' => '&#xf562;',  // 服务铃
            'calendar-day'  => '&#xf783;',   // 日视图
            'calendar-week' => '&#xf784;',   // 周视图
            'calendar-plus' => '&#xf271;',   // 日历加
            'calendar-check' => '&#xf274;',  // 日历对勾
            'calendar-xmark' => '&#xf273;',  // 日历叉
            'leaf'          => '&#xf06c;',   // 叶子
            'seedling'      => '&#xf4d8;',   // 幼苗
            'fire-flame-simple' => '&#xf46a;', // 火焰
            'fire-flame-curved' => '&#xf7e4;', // 弯曲火焰
            'cloud-arrow-up' => '&#xf382;',  // 云上传
            'cloud-arrow-down' => '&#xf381;', // 云下载
            'map-location-dot' => '&#xf5a0;', // 地图定位
            'location-dot'  => '&#xf3c5;',   // 定位点
            'location-crosshairs' => '&#xf601;', // 十字定位
            'language'      => '&#xf1ab;',   // 语言
            // 列表/编辑/多媒体
            'list-ul'       => '&#xf0ca;',   // 无序列表
            'list-ol'       => '&#xf0cb;',   // 有序列表
            'table-list'    => '&#xf00b;',   // 表格列表
            'square'        => '&#xf0c8;',   // 方块
            'circle'        => '&#xf1db;',   // 圆圈
            'circle-half-stroke' => '&#xf042;', // 半圆
            'scissors'      => '&#xf0c4;',   // 剪刀
            'crop'          => '&#xf125;',   // 裁剪
            'crop-simple'   => '&#xf565;',   // 简单裁剪
            'spell-check'   => '&#xf891;',   // 拼写检查
            'phone-flip'    => '&#xf879;',   // 翻盖手机
            'phone-volume'  => '&#xf2a0;',   // 音量电话
            'video-slash'   => '&#xf4e2;',   // 视频禁用
            'microphone-slash' => '&#xf131;', // 麦克风禁用
            'microphone-lines' => '&#xf3c9;', // 麦克风声波
            'headphones-simple' => '&#xf58f;', // 耳机
            'circle-play'   => '&#xf144;',   // 圆形播放
            'circle-pause'  => '&#xf28c;',   // 圆形暂停
            'circle-stop'   => '&#xf28e;',   // 圆形停止
            'volume-xmark'  => '&#xf6a9;',   // 音量关闭
            'door-open'     => '&#xf52b;',   // 开门
            'door-closed'   => '&#xf52a;',   // 关门
            'trash-can-arrow-up' => '&#xf82a;', // 回收恢复
            'hand-holding-heart' => '&#xf4be;', // 捧心
            'square-envelope' => '&#xf199;', // 信封方块
            'sitemap'       => '&#xf0e8;',   // 站点地图
            'square-rss'    => '&#xf143;',   // RSS 方块
            'square-phone'  => '&#xf098;',   // 电话方块
            'window-restore' => '&#xf2d2;',  // 还原窗口
            'star-half'     => '&#xf123;',   // 半星
            'star-half-stroke' => '&#xf5c0;', // 半星描边
            'grip-lines'    => '&#xf7a4;',   // 横线 grip
            'grip-lines-vertical' => '&#xf7a5;', // 竖线 grip
            'magnifying-glass-plus' => '&#xf00e;', // 放大镜加
            'magnifying-glass-minus' => '&#xf010;', // 放大镜减
        ];
        $class = 'fa' . ($extraClass ? ' ' . $extraClass : '');
        $char = isset($map[$name]) ? $map[$name] : '&#xf128;';
        // extraClass 进入 class 属性：HTML 转义防属性注入（调用方多为模板常量，防御纵深）
        return '<i class="' . \htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" style="font-size:' . (int)$size . 'px">' . $char . '</i>';
    }
}
