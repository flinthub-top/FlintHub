<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 博客封面生成器 — 6 套纯原创 SVG 模板（1200×750，8:5）+ 文字转义 + 落盘
 * @file app/Helpers/CoverTemplate.php
 * @package app\Helpers
 */

namespace app\Helpers;

class CoverTemplate
{
    /** 封面画幅 1200×750（8:5）：与桌面列表 160×100 同比例，矢量无损缩放 */
    public const WIDTH  = 1200;
    public const HEIGHT = 750;

    /** 模板白名单（未知模板名静默回退 crystal，绝不抛异常） */
    public const TEMPLATES = ['crystal', 'minimal', 'green', 'pink', 'dusk', 'sunset'];

    /**
     * 字体栈：系统字体，零外部资源（无版权风险）
     */
    private const FONT_STACK = 'system-ui,-apple-system,\'Segoe UI\',\'PingFang SC\',\'Microsoft YaHei\',sans-serif';

    /** 安全区：移动端 background-size:cover 只保留中部带，文字必须落在该区域内 */
    private const SAFE_X1 = 240;
    private const SAFE_X2 = 960;

    /** 渐变 id 唯一后缀计数器（同一页面多次生成避免 SVG id 冲突） */
    private static $uid = 0;

    /**
     * 模板列表（name => i18n key），供视图下拉使用
     */
    public static function templates(): array
    {
        return [
            'crystal' => 'blog.cover.crystal',
            'minimal' => 'blog.cover.minimal',
            'green'   => 'blog.cover.green',
            'pink'    => 'blog.cover.pink',
            'dusk'    => 'blog.cover.dusk',
            'sunset'  => 'blog.cover.sunset',
        ];
    }

    /**
     * 按模板拼接生成封面 SVG 字符串（所有注入文字 htmlspecialchars 转义）
     *
     * @param string $title        博客标题（最多 2 行，超长自动截断加省略号）
     * @param string $author       作者名
     * @param string $date         日期字符串
     * @param string $templateName 模板名，不在白名单内回退 crystal
     * @return string 完整 SVG 文档字符串
     */
    public static function generateCover(string $title, string $author, string $date, string $templateName): string
    {
        $name = \in_array($templateName, self::TEMPLATES, true) ? $templateName : 'crystal';
        $bg   = \call_user_func([self::class, 'bg' . \ucfirst($name)]);

        $lines = self::wrapTitle($title, 2, 10); // 单行预算 10 个中文字符单位（720px 安全宽 / 65px 字号）
        // 文字块按行数动态压缩，落在移动端 cover 可见带内（竖屏仅显示画布中部 y≈263~487 横带）：
        //   1 行标题 65 / 2 行标题 60 / 日期 40；横线 y=412、日期 y=470（1 行/2 行一致）
        $titleHtml = '';
        $dividerY  = 412;
        $metaY     = 470;
        $metaSize  = 40;
        if (\count($lines) === 1) {
            $titleHtml = self::textLine($lines[0], 600, 330, 65, 'title', $name);
        } else {
            $titleHtml = self::textLine($lines[0] ?? '', 600, 312, 60, 'title', $name)
                       . self::textLine($lines[1] ?? '', 600, 380, 60, 'title', $name);
        }

        $divider = '<rect x="550" y="' . $dividerY . '" width="100" height="4" rx="2" fill="' . self::color('meta', $name) . '" opacity="0.85"/>';
        $meta    = self::textLine($author . ' · ' . $date, 600, $metaY, $metaSize, 'meta', $name);

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="' . self::WIDTH . '" height="' . self::HEIGHT . '"'
            . ' viewBox="0 0 ' . self::WIDTH . ' ' . self::HEIGHT . '">'
            . $bg . $titleHtml . $divider . $meta
            . '</svg>';
    }

    /**
     * 将 SVG 落盘到 assets/uploads/covers/，返回相对路径（covers/xxx.svg）。
     * 失败返回空串（调用方回退为不设置封面）。
     *
     * @param string $svg generateCover() 产出的完整 SVG 字符串
     * @return string 相对 UPLOAD_PATH 的路径（与现有 UPLOAD_URL . $cover 拼链兼容）
     */
    public static function saveCover(string $svg): string
    {
        $dir = \rtrim(\UPLOAD_PATH, '/\\') . \DIRECTORY_SEPARATOR . 'covers';
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        $filename = \bin2hex(\random_bytes(16)) . '.svg';
        $path     = $dir . \DIRECTORY_SEPARATOR . $filename;
        if (\file_put_contents($path, $svg) === false) {
            return '';
        }
        @\chmod($path, 0644);
        return 'covers/' . $filename;
    }

    /* ==================== 私有工具 ==================== */

    /**
     * 标题换行：按字符宽度估算（中文/全角=1 单位，ASCII≈0.5 单位），
     * 超出 maxLines 的剩余字符以省略号收尾。
     */
    private static function wrapTitle(string $title, int $maxLines, int $budgetPerLine): array
    {
        $title = \trim($title);
        if ($title === '') return [''];

        $chars = \preg_split('//u', $title, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) $chars = \str_split($title);

        $lines = [];
        $current = '';
        $units = 0;
        $ellipsis = false;

        foreach ($chars as $ch) {
            if (\count($lines) >= $maxLines) {
                $ellipsis = true;
                break;
            }
            $u = self::charUnits($ch);
            if ($units + $u > $budgetPerLine && $current !== '') {
                $lines[] = $current;
                $current = $ch;
                $units   = $u;
            } else {
                $current .= $ch;
                $units   += $u;
            }
        }
        // 超出 maxLines 时：残缺的下一行不渲染，省略号加到最后一行；
        // 未超限（自然结束）才把当前行收尾
        if (!$ellipsis && $current !== '') {
            $lines[] = $current;
        }
        if ($ellipsis && !empty($lines)) {
            $lines[\count($lines) - 1] .= '…';
        }
        return $lines;
    }

    /** 字符宽度估算：ASCII 0.5 单位（宽字符 0.9），其余（中文/全角）1 单位 */
    private static function charUnits(string $ch): float
    {
        $o = \ord($ch);
        if ($o < 0x80) {
            return (\preg_match('/[MWmw@#%&]/', $ch) ? 0.9 : 0.5);
        }
        return 1.0;
    }

    /** 生成一行 <text>（内部统一转义，杜绝 SVG 注入） */
    private static function textLine(string $rawText, int $x, int $y, int $fontSize, string $role, string $template): string
    {
        $esc   = self::esc($rawText);
        $color = self::color($role, $template);
        $weight = $role === 'title' ? 700 : 400;
        $spacing = $role === 'meta' ? ' letter-spacing="1"' : '';
        return '<text x="' . $x . '" y="' . $y . '" text-anchor="middle"'
            . ' font-family="' . self::FONT_STACK . '"'
            . ' font-size="' . $fontSize . '" font-weight="' . $weight . '"'
            . ' fill="' . $color . '"' . $spacing . '>' . $esc . '</text>';
    }

    /** 模板文字配色（title=标题，meta=作者/日期） */
    private static function color(string $role, string $template): string
    {
        $palette = [
            'crystal' => ['title' => '#ffffff', 'meta' => '#bae6fd'],
            'minimal' => ['title' => '#1e293b', 'meta' => '#64748b'],
            'green'   => ['title' => '#ffffff', 'meta' => '#d1fae5'],
            'pink'    => ['title' => '#ffffff', 'meta' => '#fdf2f8'],
            'dusk'    => ['title' => '#ffffff', 'meta' => '#ddd6fe'],
            'sunset'  => ['title' => '#ffffff', 'meta' => '#ffedd5'],
        ];
        return $palette[$template][$role] ?? '#ffffff';
    }

    /** 统一转义：ENT_QUOTES + UTF-8（< > " ' & 全部转义，防 SVG 注入/XSS） */
    private static function esc(string $s): string
    {
        return \htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /** 生成唯一渐变 id（同一页面多次调用不冲突） */
    private static function uid(string $key): string
    {
        self::$uid++;
        return 'cv-' . $key . '-' . self::$uid;
    }

    /* ==================== 6 套模板背景（纯原创几何设计） ==================== */

    /** 深蓝晶体：深蓝渐变 + 半透明多边形晶体切割 */
    private static function bgCrystal(): string
    {
        $g1 = self::uid('g');
        $g2 = self::uid('g');
        return '<defs>'
            . '<linearGradient id="' . $g1 . '" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="#0b1e4b"/><stop offset="0.55" stop-color="#123a8c"/><stop offset="1" stop-color="#0ea5e9"/>'
            . '</linearGradient>'
            . '<linearGradient id="' . $g2 . '" x1="0" y1="0" x2="1" y2="0">'
            . '<stop offset="0" stop-color="#ffffff" stop-opacity="0.18"/><stop offset="1" stop-color="#ffffff" stop-opacity="0.02"/>'
            . '</linearGradient>'
            . '</defs>'
            . '<rect width="1200" height="750" fill="url(#' . $g1 . ')"/>'
            . '<polygon points="980,60 1180,180 1060,420 860,300" fill="url(#' . $g2 . ')"/>'
            . '<polygon points="120,620 340,470 480,640 260,760" fill="url(#' . $g2 . ')"/>'
            . '<polygon points="520,120 700,60 780,240 600,300" fill="url(#' . $g2 . ')" opacity="0.7"/>'
            . '<polygon points="60,80 200,20 260,160 120,220" fill="url(#' . $g2 . ')" opacity="0.5"/>'
            . '<polygon points="1080,520 1200,440 1200,600 1100,680" fill="url(#' . $g2 . ')" opacity="0.6"/>'
            . '<line x1="600" y1="560" x2="600" y2="670" stroke="#7dd3fc" stroke-width="1.5" opacity="0.5"/>'
            // 贝塞尔波浪 + 弧线（顶部/底部/左下角落，不侵入文字安全区）
            . '<path d="M0,140 Q100,50 200,140 T400,140 T600,140 T800,140 T1000,140 T1200,140" stroke="#7dd3fc" stroke-width="2" fill="none" opacity="0.35"/>'
            . '<path d="M0,630 Q150,700 300,630 T600,630 T900,630 T1200,630" stroke="#bae6fd" stroke-width="1.5" fill="none" opacity="0.3"/>'
            . '<path d="M30,480 A180,180 0 0 1 210,300" stroke="#7dd3fc" stroke-width="2" fill="none" opacity="0.4"/>';
    }

    /** 浅灰极简：浅灰渐变 + 细线/圆环留白几何 */
    private static function bgMinimal(): string
    {
        $g1 = self::uid('g');
        return '<defs>'
            . '<linearGradient id="' . $g1 . '" x1="0" y1="0" x2="0" y2="1">'
            . '<stop offset="0" stop-color="#f8fafc"/><stop offset="1" stop-color="#e2e8f0"/>'
            . '</linearGradient>'
            . '</defs>'
            . '<rect width="1200" height="750" fill="url(#' . $g1 . ')"/>'
            . '<circle cx="1050" cy="150" r="90" fill="none" stroke="#cbd5e1" stroke-width="2"/>'
            . '<circle cx="1080" cy="180" r="88" fill="none" stroke="#e2e8f0" stroke-width="1.5"/>'
            . '<line x1="90" y1="140" x2="340" y2="140" stroke="#94a3b8" stroke-width="2"/>'
            . '<line x1="90" y1="152" x2="280" y2="152" stroke="#cbd5e1" stroke-width="1"/>'
            . '<circle cx="120" cy="600" r="4" fill="#94a3b8"/>'
            . '<rect x="860" y="600" width="250" height="3" rx="1.5" fill="#cbd5e1"/>'
            // 贝塞尔弧线/波浪（顶部/底部/右侧，不侵入文字安全区）
            . '<path d="M80,120 C180,60 260,60 360,130" stroke="#94a3b8" stroke-width="1.5" fill="none"/>'
            . '<path d="M0,600 Q150,660 300,600 T600,600 T900,600 T1200,600" stroke="#cbd5e1" stroke-width="1.5" fill="none"/>'
            . '<path d="M1050,420 C1150,420 1200,480 1180,560" stroke="#94a3b8" stroke-width="1.5" fill="none" opacity="0.8"/>';
    }

    /** 清新绿意：绿渐变 + 柔光 + 叶形椭圆 */
    private static function bgGreen(): string
    {
        $g1 = self::uid('g');
        $g2 = self::uid('g');
        return '<defs>'
            . '<linearGradient id="' . $g1 . '" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="#064e3b"/><stop offset="0.6" stop-color="#059669"/><stop offset="1" stop-color="#34d399"/>'
            . '</linearGradient>'
            . '<radialGradient id="' . $g2 . '" cx="0.5" cy="0.5" r="0.5">'
            . '<stop offset="0" stop-color="#a7f3d0" stop-opacity="0.35"/><stop offset="1" stop-color="#a7f3d0" stop-opacity="0"/>'
            . '</radialGradient>'
            . '</defs>'
            . '<rect width="1200" height="750" fill="url(#' . $g1 . ')"/>'
            . '<circle cx="200" cy="160" r="140" fill="url(#' . $g2 . ')"/>'
            . '<circle cx="1000" cy="600" r="180" fill="url(#' . $g2 . ')"/>'
            . '<ellipse cx="1050" cy="180" rx="70" ry="26" fill="#6ee7b7" opacity="0.5" transform="rotate(-30 1050 180)"/>'
            . '<ellipse cx="1120" cy="240" rx="70" ry="26" fill="#6ee7b7" opacity="0.35" transform="rotate(20 1120 240)"/>'
            . '<ellipse cx="120" cy="560" rx="80" ry="30" fill="#6ee7b7" opacity="0.4" transform="rotate(25 120 560)"/>'
            . '<ellipse cx="60" cy="640" rx="80" ry="30" fill="#6ee7b7" opacity="0.3" transform="rotate(-15 60 640)"/>'
            // 贝塞尔波浪 + 弧线（顶部/底部/左下角落，不侵入文字安全区）
            . '<path d="M0,130 Q100,40 200,130 T400,130 T600,130 T800,130 T1000,130 T1200,130" stroke="#a7f3d0" stroke-width="2" fill="none" opacity="0.35"/>'
            . '<path d="M0,640 Q150,710 300,640 T600,640 T900,640 T1200,640" stroke="#6ee7b7" stroke-width="1.5" fill="none" opacity="0.4"/>'
            . '<path d="M20,420 A160,160 0 0 1 180,260" stroke="#a7f3d0" stroke-width="2" fill="none" opacity="0.35"/>';
    }

    /** 粉色渐变：粉紫渐变 + 柔光圆点 */
    private static function bgPink(): string
    {
        $g1 = self::uid('g');
        $g2 = self::uid('g');
        return '<defs>'
            . '<linearGradient id="' . $g1 . '" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="#fbcfe8"/><stop offset="0.5" stop-color="#f472b6"/><stop offset="1" stop-color="#a855f7"/>'
            . '</linearGradient>'
            . '<radialGradient id="' . $g2 . '" cx="0.5" cy="0.5" r="0.5">'
            . '<stop offset="0" stop-color="#ffffff" stop-opacity="0.5"/><stop offset="1" stop-color="#ffffff" stop-opacity="0"/>'
            . '</radialGradient>'
            . '</defs>'
            . '<rect width="1200" height="750" fill="url(#' . $g1 . ')"/>'
            . '<circle cx="180" cy="200" r="130" fill="url(#' . $g2 . ')"/>'
            . '<circle cx="1020" cy="560" r="160" fill="url(#' . $g2 . ')"/>'
            . '<circle cx="1000" cy="150" r="60" fill="#ffffff" opacity="0.25"/>'
            . '<circle cx="90" cy="620" r="40" fill="#ffffff" opacity="0.2"/>'
            . '<circle cx="1120" cy="400" r="24" fill="#ffffff" opacity="0.35"/>'
            // 贝塞尔波浪 + 弧线（顶部/底部/右侧，不侵入文字安全区）
            . '<path d="M0,120 Q100,30 200,120 T400,120 T600,120 T800,120 T1000,120 T1200,120" stroke="#ffffff" stroke-width="2" fill="none" opacity="0.3"/>'
            . '<path d="M0,650 Q150,720 300,650 T600,650 T900,650 T1200,650" stroke="#ffffff" stroke-width="1.5" fill="none" opacity="0.25"/>'
            . '<path d="M1080,320 A160,160 0 0 1 1200,480" stroke="#ffffff" stroke-width="2" fill="none" opacity="0.3"/>';
    }

    /** 暮光紫：深紫渐变 + 星点 + 光晕 */
    private static function bgDusk(): string
    {
        $g1 = self::uid('g');
        $g2 = self::uid('g');
        return '<defs>'
            . '<linearGradient id="' . $g1 . '" x1="0" y1="0" x2="0" y2="1">'
            . '<stop offset="0" stop-color="#1e1b4b"/><stop offset="0.6" stop-color="#4c1d95"/><stop offset="1" stop-color="#a855f7"/>'
            . '</linearGradient>'
            . '<radialGradient id="' . $g2 . '" cx="0.5" cy="0.5" r="0.5">'
            . '<stop offset="0" stop-color="#c4b5fd" stop-opacity="0.4"/><stop offset="1" stop-color="#c4b5fd" stop-opacity="0"/>'
            . '</radialGradient>'
            . '</defs>'
            . '<rect width="1200" height="750" fill="url(#' . $g1 . ')"/>'
            . '<circle cx="950" cy="180" r="150" fill="url(#' . $g2 . ')"/>'
            . '<circle cx="160" cy="560" r="120" fill="url(#' . $g2 . ')"/>'
            . '<circle cx="150" cy="120" r="2.5" fill="#e0e7ff"/>'
            . '<circle cx="280" cy="80" r="1.8" fill="#e0e7ff"/>'
            . '<circle cx="420" cy="150" r="2" fill="#e0e7ff"/>'
            . '<circle cx="1080" cy="100" r="2.2" fill="#e0e7ff"/>'
            . '<circle cx="1150" cy="300" r="1.6" fill="#e0e7ff"/>'
            . '<circle cx="80" cy="350" r="1.8" fill="#e0e7ff"/>'
            . '<circle cx="880" cy="640" r="2" fill="#e0e7ff"/>'
            . '<circle cx="700" cy="680" r="1.6" fill="#e0e7ff"/>'
            // 贝塞尔波浪 + 弧线（顶部/底部/右侧，不侵入文字安全区）
            . '<path d="M0,110 Q100,20 200,110 T400,110 T600,110 T800,110 T1000,110 T1200,110" stroke="#a78bfa" stroke-width="2" fill="none" opacity="0.35"/>'
            . '<path d="M0,660 Q150,730 300,660 T600,660 T900,660 T1200,660" stroke="#c4b5fd" stroke-width="1.5" fill="none" opacity="0.3"/>'
            . '<path d="M980,560 A150,150 0 0 1 1150,720" stroke="#a78bfa" stroke-width="2" fill="none" opacity="0.35"/>';
    }

    /** 暖阳橙：橙红渐变 + 半圆日轮 + 山形层叠 + 飞鸟 */
    private static function bgSunset(): string
    {
        $g1 = self::uid('g');
        return '<defs>'
            . '<linearGradient id="' . $g1 . '" x1="0" y1="0" x2="0" y2="1">'
            . '<stop offset="0" stop-color="#7c2d12"/><stop offset="0.55" stop-color="#ea580c"/><stop offset="1" stop-color="#fbbf24"/>'
            . '</linearGradient>'
            . '</defs>'
            . '<rect width="1200" height="750" fill="url(#' . $g1 . ')"/>'
            . '<circle cx="980" cy="420" r="110" fill="#fcd34d" opacity="0.65"/>'
            . '<polygon points="0,560 200,380 420,560" fill="#9a3412" opacity="0.55"/>'
            . '<polygon points="260,560 520,330 780,560" fill="#7c2d12" opacity="0.6"/>'
            . '<polygon points="620,560 880,300 1200,560" fill="#431407" opacity="0.55"/>'
            . '<path d="M180,200 q12,-10 24,0 q12,-10 24,0" stroke="#ffedd5" stroke-width="2.5" fill="none" opacity="0.8"/>'
            . '<path d="M260,240 q9,-8 18,0 q9,-8 18,0" stroke="#ffedd5" stroke-width="2" fill="none" opacity="0.6"/>'
            // 贝塞尔弧线 + 波浪（顶部/底部，不侵入文字安全区）
            . '<path d="M60,90 C180,20 300,20 420,100" stroke="#ffedd5" stroke-width="2" fill="none" opacity="0.7"/>'
            . '<path d="M0,670 Q150,740 300,670 T600,670 T900,670 T1200,670" stroke="#fdba74" stroke-width="1.5" fill="none" opacity="0.6"/>'
            . '<path d="M30,420 A160,160 0 0 1 190,260" stroke="#ffedd5" stroke-width="2" fill="none" opacity="0.5"/>';
    }
}
