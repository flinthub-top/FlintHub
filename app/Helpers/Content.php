<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 内容处理 — Base64 编解码、HTML 净化、帖子内容格式化
 * @file app/Helpers/Content.php
 * @package app\Helpers
 */

namespace app\Helpers;

class Content
{
    // ====== 安全 HTML 白名单过滤 ======

    public static function sanitizeHtml(string $html): string
    {
        if ($html === '') return '';
        $html = \mb_substr($html, 0, 500000); // 500KB 上限，防 DOMDocument OOM

        \libxml_use_internal_errors(true);
        $dom = new \DOMDocument();

        // 1. 编码高位字符
        $html = \mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, ~0], 'UTF-8');

        // 2. 保护花括号 {}，防 DOMDocument 误认为自定义 HTML 标签（如 {yield}）
        $html = \str_replace(['{', '}'], ['___CURLY_OPEN___', '___CURLY_CLOSE___'], $html);

        // 3. 保护文档结构标签，防用户代码示例中的 <html><body> 破坏 DOM 树
        $html = \str_ireplace(
            ['<html', '</html>', '<head', '</head>', '<body', '</body>'],
            ['___LT___html', '___LT___/html___GT___', '___LT___head', '___LT___/head___GT___', '___LT___body', '___LT___/body___GT___'],
            $html
        );

        // 4. 保护 PHP 代码开始标记
        $html = \str_replace(['<?php', '<?='], ['___PHP_OPEN_TAG___', '___PHP_SHORT_TAG___'], $html);

        // 5. 加载到 DOMDocument
        $dom->loadHTML(
            '<!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $allowedTags = [
            'div', 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'del',
            'h2', 'h3', 'h4', 'ul', 'ol', 'li',
            'a', 'img', 'blockquote', 'pre', 'code', 'hr', 'sub', 'sup',
            'table', 'thead', 'tbody', 'tr', 'th', 'td',
            // span：编辑器颜色/字号等行内样式经 <span style="..."> 承载，必须放行
            'span',
        ];

        $allowedAttrs = ['href', 'src', 'alt', 'title', 'target', 'rel', 'class', 'width', 'height', 'style'];
        $xpath = new \DOMXPath($dom);

        foreach ($xpath->query('//*') as $node) {
            $tag = strtolower($node->nodeName);
            if ($tag === 'html' || $tag === 'body' || $tag === '#text' || $tag === '#comment') continue;

            if (!in_array($tag, $allowedTags, true)) {
                $fragment = $dom->createDocumentFragment();
                while ($node->childNodes->length > 0) {
                    $fragment->appendChild($node->childNodes->item(0));
                }
                $node->parentNode->replaceChild($fragment, $node);
                continue;
            }

            if ($node->hasAttributes()) {
                $removeAttrs = [];
                foreach ($node->attributes as $attr) {
                    $name = strtolower($attr->nodeName);
                    $value = trim(\html_entity_decode($attr->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                    if (!in_array($name, $allowedAttrs, true)) { $removeAttrs[] = $name; continue; }
                    if (strpos($name, 'on') === 0) { $removeAttrs[] = $name; continue; }

                    // style 属性白名单过滤：仅保留安全 CSS 属性，拦截 url()/expression/@import 等危险值（防 CSS 注入）
                    if ($name === 'style') {
                        $safeStyle = self::sanitizeCssStyle($value);
                        if ($safeStyle === '') {
                            $removeAttrs[] = $name;
                        } else {
                            $node->setAttribute('style', $safeStyle);
                        }
                        continue;
                    }

                    if ($name === 'href' || $name === 'src') {
                        $value = \preg_replace('/[\x00-\x20\x7F]+/u', '', $value); // 含空格(0x20)，防 java script: 绕过
                        // 白名单协议模式，仅允许 http/https/mailto/tel
                        if (!\preg_match('/^(https?:\/\/|mailto:|tel:|\/|#)/i', $value)) { $removeAttrs[] = $name; continue; }
                        if (\preg_match('/\.svg(?:\?|#|$)/i', $value)) { $removeAttrs[] = $name; continue; }
                    }
                }
                foreach ($removeAttrs as $attrName) $node->removeAttribute($attrName);
            }

            if ($tag === 'a') {
                $node->setAttribute('rel', 'noopener noreferrer nofollow');
                $node->setAttribute('target', '_blank');
            }

            if ($tag === 'img') {
                $node->removeAttribute('srcset');
                $node->removeAttribute('sizes');
                $node->setAttribute('loading', 'lazy');
                $node->setAttribute('decoding', 'async');
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) return '';

        $clean = '';
        foreach ($body->childNodes as $child) $clean .= $dom->saveHTML($child);

        // 6. 还原所有被保护的占位符 — 使用 HTML 实体防浏览器重新解析为 PHP 代码
        $clean = \str_replace(
            [
                '___PHP_OPEN_TAG___', '___PHP_SHORT_TAG___', 
                '___LT___', '___GT___',
                '___CURLY_OPEN___', '___CURLY_CLOSE___'
            ],
            [
                '&lt;?php', '&lt;?=', 
                '&lt;', '&gt;',
                '{', '}'
            ],
            $clean
        );

        \libxml_clear_errors();
        return $clean;
    }

    // ====== CSS style 属性安全过滤 ======

    /**
     * style 属性白名单过滤：仅保留安全 CSS 属性并拦截危险值
     *
     * 允许属性：color / font-size / text-align / background-color / border-radius
     * 危险拦截：url()、expression()、@import、@charset、behavior、-moz-binding、javascript: 等
     *
     * @param string $style 原始 style 值
     * @return string 过滤后的安全 style（无合法声明时返回 ''）
     */
    public static function sanitizeCssStyle(string $style): string
    {
        $style = \trim($style);
        if ($style === '') return '';

        // 白名单 CSS 属性
        $allowedProps = ['color', 'font-size', 'text-align', 'background-color', 'border-radius'];

        // 危险片段（CSS 注入向量）
        // 注：position 属性已被上方白名单（$allowedProps 不含 position）在属性名阶段拦截，
        // 且值字符集不含冒号，dangerPatterns 中 position\s*: 永不命中 → 已清理（冗余死代码）
        $dangerPatterns = [
            'url\s*\(', 'expression\s*\(', '@import', '@charset',
            'behavior\s*:', '-moz-binding', 'javascript\s*:',
        ];

        $safeParts = [];
        // 按分号切分声明（兼容无分号结尾）
        foreach (\explode(';', $style) as $decl) {
            $decl = \trim($decl);
            if ($decl === '') continue;
            // 仅接受 "属性: 值" 形式
            if (!\preg_match('/^([a-zA-Z-]+)\s*:\s*(.+)$/', $decl, $m)) continue;

            $prop = \strtolower(\trim($m[1]));
            $value = \trim($m[2]);

            // 属性白名单
            if (!\in_array($prop, $allowedProps, true)) continue;

            // 值安全校验：值仅允许合法 CSS 字符（防引号逃逸）。
            // 含括号的值仅放行 rgb()/rgba() 颜色函数——编辑器面板设色经浏览器 CSSOM 序列化
            // 后即为 rgb() 格式（hex 被规范化），网页复制粘贴同理；其余函数形式
            // （url()/calc()/expression() 等）一律拦截，dangerPatterns 的 url(/expression(
            // 规则继续兜底
            if (strpos($value, '(') !== false) {
                if (!\preg_match('/^(?:rgb\(\s*(?:\d{1,3}%?)(?:\s*,\s*(?:\d{1,3}%?)){2}\s*\)|rgba\(\s*(?:\d{1,3}%?)(?:\s*,\s*(?:\d{1,3}%?|\d(?:\.\d+)?)){3}\s*\))$/i', $value)) continue;
            } elseif (!\preg_match('/^[a-zA-Z0-9#%.,\-_\/\s]+$/', $value)) {
                continue;
            }

            // 危险模式拦截（防 url/expression/@import 等注入）
            $lower = \strtolower($value);
            $danger = false;
            foreach ($dangerPatterns as $dp) {
                if (\preg_match('/' . $dp . '/', $lower)) { $danger = true; break; }
            }
            if ($danger) continue;

            $safeParts[] = $prop . ': ' . $value;
        }

        return \implode('; ', $safeParts);
    }

    // ====== 格式化帖子内容 ======

    /**
     * 安全 HTML 摘要：按纯文本长度判定是否超限，超限时按标签结构安全截断
     *
     * @param string|null $content 原始正文（decode 后的 HTML，未净化也可）
     * @param int         $limit   摘要纯文本字数上限
     * @return array{html:string, truncated:bool} html=已净化摘要片段；truncated=是否被截断
     */
    public static function excerpt(?string $content, int $limit = 500): array
    {
        if (!is_string($content) || $content === '') {
            return ['html' => '', 'truncated' => false];
        }

        // 0. 与 formatPostContent HTML 分支同序：先解析站内表情，再净化（保证摘要内表情与全文一致）
        $content = Emoji::parse($content);

        // 1. 先净化，确保截断输入为白名单内标签（防未净化原文里的危险属性）
        $safe = self::sanitizeHtml($content);

        // 2. 纯文本长度度量（防 strip_tags 误删 PHP 代码标记，与 formatPostContent 一致）
        //    度量前先解码实体：&amp;/&nbsp; 按 1 字符计，防止字面实体（如 &nbsp; 占 6 字符）导致截断失真
        $safeForCheck = \str_replace(['<?', "\x3f\x3e"], ['__PHP_OPEN__', '__PHP_CLOSE__'], $safe);
        $plainLen = \mb_strlen(\trim(\strip_tags(\html_entity_decode($safeForCheck, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        if ($plainLen <= $limit) {
            return ['html' => $safe, 'truncated' => false];
        }

        // 3. DOM 安全截断：按文档顺序累计可见文本，达到上限后截断并移除后续全部节点
        \libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8"?><div>' . $safe . '</div>', \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD);
        \libxml_clear_errors();
        if (!$loaded) {
            return ['html' => $safe, 'truncated' => true];
        }

        $root = $dom->getElementsByTagName('div')->item(0);
        if (!$root) {
            return ['html' => $safe, 'truncated' => true];
        }

        $count = 0;
        $cutNode = null;
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//text()') as $tn) {
            $len = \mb_strlen($tn->nodeValue);
            if ($count + $len > $limit) {
                $keep = $limit - $count;
                $tn->nodeValue = ($keep > 0 ? \mb_substr($tn->nodeValue, 0, $keep) : '') . '…';
                $cutNode = $tn;
                break;
            }
            $count += $len;
        }

        if ($cutNode) {
            // 向上遍历父链，删除每一层的后续兄弟（保证标签闭合、不留半截子树）
            $node = $cutNode;
            while ($node && $node !== $root) {
                $sib = $node->nextSibling;
                while ($sib) {
                    $next = $sib->nextSibling;
                    $sib->parentNode->removeChild($sib);
                    $sib = $next;
                }
                $node = $node->parentNode;
            }
            $inner = '';
            foreach ($root->childNodes as $child) {
                $inner .= $dom->saveHTML($child);
            }
            return ['html' => $inner, 'truncated' => true];
        }

        return ['html' => $safe, 'truncated' => true];
    }

    public static function formatPostContent(?string $content): string
    {
        if (!is_string($content) || $content === '') return '';

        // 防 strip_tags 误删 PHP 代码标记（常见于代码示例）
        $safeForCheck = \str_replace(['<?', "\x3f\x3e"], ['__PHP_OPEN__', '__PHP_CLOSE__'], $content);

        // 纯文本 → 自动链接 + nl2br
        if ($safeForCheck === \strip_tags($safeForCheck)) {
            $content = \htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
            $content = \preg_replace_callback(
                '~(https?://[^\s<]+)~i',
                function ($m) {
                    $safe = \htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                    return '<a href="' . $safe . '" target="_blank" rel="noopener noreferrer nofollow">' . $safe . '</a>';
                },
                $content
            );
            $content = Emoji::parse($content);
            return \nl2br($content);
        }

        $content = Emoji::parse($content);

        return self::sanitizeHtml($content);
    }

    // ====== 解码编辑器内容（base64 转为原始文本） ======

    public static function decode(?string $data): string
    {
        if (!is_string($data) || $data === '') return '';

        $data = \trim($data);

        // 1. 严格过滤：Base64 长度必为 4 的倍数（最小合法长度 4，如 "aGk="），且只包含合法字符；
        //    后续 UTF-8 校验与控制字符检查兜底（纯文本解码后非 UTF-8 会原样返回）
        if (
            \strlen($data) >= 4
            && \strlen($data) % 4 === 0
            && \preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $data)
        ) {
            $decoded = \base64_decode($data, true);
            
            if ($decoded !== false && $decoded !== '') {
                // 2. 检查 base64_decode 出来的原始二进制是否含控制字符或非文本特征（非法 UTF-8 说明误判）
                if (!\mb_check_encoding($decoded, 'UTF-8')) {
                    return $data;
                }

                // 3. 反解 percent-encoding 还原为 UTF-8 原文
                $decoded = \rawurldecode($decoded);

                // 4. 再次严检：解密 url 编码后，必须仍是合法的 UTF-8 文本
                if ($decoded !== $data && \mb_check_encoding($decoded, 'UTF-8')) {
                    // 额外确保不是一堆乱码不可见字符
                    if (\preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $decoded) === 0) {
                        return $decoded;
                    }
                }
            }
        }

        // 非 base64 编码，或校验失败，直接返回原文
        return $data;
    }
}
