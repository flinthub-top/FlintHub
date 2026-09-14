/**
 * SoSite WYSIWYG Editor v3.0 — contenteditable-based
 * 使用原生 DOM API（Selection + Range）
 * 安全优化版：XSS 净化、内容转义
 * v3.0 - 新增字体颜色/背景色/对齐/分割线/全屏/字数统计/撤销重做
 * @license MIT
 */
(function() {
    "use strict";

    var EDITOR_VERSION = "3.0";
    var ACTIVE_EDITORS = {};

    // ===================================================================
    // 1. 历史记录（undo/redo，替代浏览器内置）
    // ===================================================================

    function createHistory() {
        var stack = [];
        var index = -1;
        var maxSteps = 50;
        var pending = false;

        function push(html) {
            // 与上一次相同则不记录
            if (index >= 0 && stack[index] === html) return;
            // 砍掉 redo 分支
            stack.length = index + 1;
            stack.push(html);
            if (stack.length > maxSteps) stack.shift();
            index = stack.length - 1;
        }

        return {
            push: push,
            undo: function() {
                if (index <= 0) return null;
                index--;
                return stack[index];
            },
            redo: function() {
                if (index >= stack.length - 1) return null;
                index++;
                return stack[index];
            },
            snapshot: function() {
                return stack[index];
            },
            mark: function(html) {
                if (!pending) {
                    pending = true;
                    push(html);
                    setTimeout(function() { pending = false; }, 800);
                }
            },
            reset: function(html) {
                stack = [html];
                index = 0;
            }
        };
    }

    // ===================================================================
    // 2. 选区工具 — 替代 execCommand 的核心
    // ===================================================================

    /**
     * 获取安全的选区 Range，选区不可用时返回 null
     */
    function getSelectionRange() {
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount || sel.isCollapsed) return null;
        return sel.getRangeAt(0);
    }

    /**
     * 获取光标所在位置的 Range（选区或光标，均可）
     */
    function getAnyRange() {
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount) return null;
        return sel.getRangeAt(0);
    }

    /**
     * 获取当前选区所在的最近块级元素（p / h1-h6 / li / blockquote / pre / div）
     */
    function getBlockNode() {
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount) return null;
        var range = sel.getRangeAt(0);
        var node = range.commonAncestorContainer;
        if (node.nodeType === 3) node = node.parentElement;
        while (node && !/^(P|H[1-6]|LI|BLOCKQUOTE|PRE|DIV)$/i.test(node.tagName)) {
            node = node.parentElement;
        }
        return node || document.body;
    }

    /**
     * 判断选区是否完全位于某个指定标签内
     */
    function isSelectionInTag(tagName) {
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount) return false;
        var range = sel.getRangeAt(0);
        var node = range.commonAncestorContainer;
        if (node.nodeType === 3) node = node.parentElement;
        // 空段落（<p><br></p>）时 node 可能是 <br> 的父级 <p>，直接返回 false
        if (!node || node === node.ownerDocument.body) return false;
        return !!node.closest(tagName.toLowerCase());
    }

    /**
     * 判断选中内容的父链是否有指定 style 值
     */
    function isSelectionStyled(prop, value) {
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount) return false;
        var range = sel.getRangeAt(0);
        var node = range.commonAncestorContainer;
        if (node.nodeType === 3) node = node.parentElement;
        while (node && node !== node.ownerDocument.body) {
            if (node.style && node.style[prop] === value) return true;
            node = node.parentElement;
        }
        return false;
    }

    /**
     * 用指定标签包裹选区内容
     * @param {string} tagName - 标签名（如 'strong', 'span'）
     * @param {object|null} attrs - 属性对象（如 {style: {color: 'red'}}）
     * @returns {boolean} 是否成功
     */
    function wrapSelection(tagName, attrs) {
        var range = getSelectionRange();
        if (!range) return false;

        // 分割文本节点边界，确保 surroundContents 正常工作
        if (range.startContainer.nodeType === 3 && range.startOffset > 0) {
            range.startContainer.splitText(range.startOffset);
        }
        if (range.endContainer.nodeType === 3 && range.endOffset < range.endContainer.length) {
            range.endContainer.splitText(range.endOffset);
        }

        var el = document.createElement(tagName);
        if (attrs) {
            for (var k in attrs) {
                if (attrs.hasOwnProperty(k)) {
                    if (k === 'style' && typeof attrs[k] === 'object') {
                        for (var sk in attrs[k]) {
                            if (attrs[k].hasOwnProperty(sk)) {
                                el.style[sk] = attrs[k][sk];
                            }
                        }
                    } else {
                        el.setAttribute(k, attrs[k]);
                    }
                }
            }
        }

        try {
            range.surroundContents(el);
        } catch(e) {
            // 跨节点复杂选区：拆出内容 → 包裹 → 插回
            var fragment = range.extractContents();
            el.appendChild(fragment);
            range.insertNode(el);
        }

        // 清除选中状态，光标放在包裹元素末尾
        var sel = window.getSelection();
        sel.removeAllRanges();
        var newRange = document.createRange();
        newRange.setStartAfter(el);
        newRange.collapse(true);
        sel.addRange(newRange);

        return true;
    }

    /**
     * 取消包裹：将选中内容从最近的指定标签中解出
     */
    function unwrapSelection(tagName) {
        var range = getSelectionRange();
        if (!range) return false;

        var node = range.commonAncestorContainer;
        if (node.nodeType === 3) node = node.parentElement;

        var wrapper = node.closest(tagName.toLowerCase());
        if (!wrapper) return false;

        // 记住位置用于恢复光标
        var parent = wrapper.parentNode;
        var nextSib = wrapper.nextSibling;

        // 把所有子节点上移
        while (wrapper.firstChild) {
            parent.insertBefore(wrapper.firstChild, wrapper);
        }
        parent.removeChild(wrapper);

        // 恢复光标到原位置之后
        var sel = window.getSelection();
        sel.removeAllRanges();
        var r = document.createRange();
        if (nextSib) {
            r.setStartBefore(nextSib);
        } else {
            // 没有下一个兄弟节点时，放到父节点末尾内部（而非父节点外面）
            r.setStart(parent, parent.childNodes.length);
        }
        r.collapse(true);
        sel.addRange(r);

        return true;
    }

    /**
     * 切换包裹（有则取消，无则包裹）—— 用于粗体/斜体等 toggle 操作
     */
    function toggleWrap(tagName, attrs) {
        if (isSelectionInTag(tagName)) {
            unwrapSelection(tagName);
        } else {
            wrapSelection(tagName, attrs);
        }
    }

    /**
     * 替换当前块级元素为新标签
     */
    function replaceBlock(newTag, extraClasses) {
        var block = getBlockNode();
        if (!block) return;
        if (block.tagName === newTag.toUpperCase()) return; // 已相同

        var el = document.createElement(newTag);
        if (extraClasses) el.className = extraClasses;
        // 用 DOM 节点迁移替代 innerHTML，保留事件绑定和 expando 属性
        while (block.firstChild) {
            el.appendChild(block.firstChild);
        }
        // 如果原块级元素是空的或只有 <br>，垫一个干净占位防旧浏览器格式错乱
        if (el.innerHTML === "" || el.innerHTML === "<br>") {
            el.innerHTML = "<br>";
        }

        // 复制 block 上的 style
        if (block.style.cssText) {
            el.style.cssText = block.style.cssText;
        }

        block.parentNode.replaceChild(el, block);

        // 光标放到内容末尾
        var sel = window.getSelection();
        sel.removeAllRanges();
        var range = document.createRange();
        range.selectNodeContents(el);
        range.collapse(false);
        sel.addRange(range);
    }

    /**
     * 块级命令（标题/段落）的选区拆分核心。
     * 若存在非折叠的文字选区，把选中内容拆成独立的新块（如 h2/p），
     * 选区前内容留在原位段落、选区后内容放入其后新段落；
     * 若仅光标无选区，则退化为整块替换（replaceBlock）。
     */
    function splitBlockOrWhole(newTag) {
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount) { replaceBlock(newTag); return; }
        var range = sel.getRangeAt(0);
        // 无选区（仅光标）→ 整块替换
        if (range.collapsed) { replaceBlock(newTag); return; }

        var block = getBlockNode();
        if (!block || block === document.body) { replaceBlock(newTag); return; }

        // 边界安全：选区必须落在编辑器内容区内且不跨出当前块
        var common = range.commonAncestorContainer;
        if (common.nodeType === 3) common = common.parentElement;
        if (!common || common.contains(range.startContainer) === false || common.contains(range.endContainer) === false) {
            replaceBlock(newTag);
            return;
        }

        // 1) 分割选区起点/终点的文本节点，让边界落在干净的文本节点上
        if (range.startContainer.nodeType === 3 && range.startOffset > 0) {
            range.startContainer.splitText(range.startOffset);
        }
        if (range.endContainer.nodeType === 3 && range.endOffset < range.endContainer.length) {
            range.endContainer.splitText(range.endOffset);
        }
        range = sel.getRangeAt(0);

        // 2) 计算“选区前”“选区后”两个片段（用克隆，不影响原 DOM）
        var parentNode = block.parentNode;
        if (!parentNode) { replaceBlock(newTag); return; }

        var beforeFrag = null;
        try {
            var beforeRange = document.createRange();
            beforeRange.setStart(block, 0);
            beforeRange.setEnd(range.startContainer, range.startOffset);
            beforeFrag = beforeRange.cloneContents();
        } catch (e) { beforeFrag = null; }

        var afterFrag = null;
        try {
            var afterRange = document.createRange();
            afterRange.setStart(range.endContainer, range.endOffset);
            afterRange.setEnd(block, block.childNodes.length);
            afterFrag = afterRange.cloneContents();
        } catch (e) { afterFrag = null; }

        // 3) 提取选中内容，迁移进新块
        var frag = range.extractContents();
        var heading = document.createElement(newTag);
        if (block.style && block.style.cssText) heading.style.cssText = block.style.cssText;
        while (frag.firstChild) heading.appendChild(frag.firstChild);
        if (!heading.firstChild) heading.appendChild(document.createElement('br'));

        // 4) 组装：before 段（若有内容）、新块、after 段（若有内容）
        function hasRealContent(f) {
            if (!f) return false;
            // 有非空白可见文本（忽略零宽字符）
            if ((f.textContent || '').replace(/[\s\u200b]/g, '') !== '') return true;
            // 有图片/媒体/占位等非文本元素
            if (f.querySelector && f.querySelector('img,iframe,embed,hr,br,input')) return true;
            return false;
        }

        var beforeHas = hasRealContent(beforeFrag);
        var afterHas = hasRealContent(afterFrag);

        var beforeEl = null;
        if (beforeHas) { beforeEl = document.createElement('p'); if (block.style && block.style.cssText) beforeEl.style.cssText = block.style.cssText; while (beforeFrag.firstChild) beforeEl.appendChild(beforeFrag.firstChild); }

        var afterEl = null;
        if (afterHas) { afterEl = document.createElement('p'); if (block.style && block.style.cssText) afterEl.style.cssText = block.style.cssText; while (afterFrag.firstChild) afterEl.appendChild(afterFrag.firstChild); }

        // 5) 替换原块的位置，插入 [before?, heading, after?]
        var seq = [];
        if (beforeEl) seq.push(beforeEl);
        seq.push(heading);
        if (afterEl) seq.push(afterEl);

        var ref = block;
        while (seq.length) {
            parentNode.insertBefore(seq.shift(), ref);
        }
        parentNode.removeChild(block);

        // 6) 光标放到新块之后
        var s2 = window.getSelection();
        s2.removeAllRanges();
        var r = document.createRange();
        var place = afterEl || heading;
        try {
            if (afterEl) {
                r.setStart(place, 0);
                r.collapse(true);
            } else {
                r.selectNodeContents(place);
                r.collapse(false);
            }
        } catch (e2) {
            try { r.selectNodeContents(place); r.collapse(false); } catch (e3) { return; }
        }
        s2.addRange(r);
    }

    /**
     * 在光标处插入 HTML
     */
    function insertAtCursor(html) {
        // 前置 XSS 清洗
        html = sanitizeHTML(html);
        var range = getAnyRange();
        if (!range) return;

        range.deleteContents();
        var temp = document.createElement('div');
        temp.innerHTML = html;
        var frag = document.createDocumentFragment();
        while (temp.firstChild) {
            frag.appendChild(temp.firstChild);
        }
        range.insertNode(frag);

        // 光标移到插入内容后
        var sel = window.getSelection();
        sel.removeAllRanges();
        var newRange = document.createRange();
        newRange.setStartAfter(frag.lastChild || frag);
        newRange.collapse(true);
        sel.addRange(newRange);
    }

    /**
     * 在光标处插入纯文本节点
     */
    function insertTextNode(text) {
        var range = getAnyRange();
        if (!range) return;
        range.deleteContents();
        var node = document.createTextNode(text);
        range.insertNode(node);

        var sel = window.getSelection();
        sel.removeAllRanges();
        var newRange = document.createRange();
        newRange.setStartAfter(node);
        newRange.collapse(true);
        sel.addRange(newRange);
    }

    /**
     * 清除选中内容的所有行内样式/标签，保留纯文本
     */
    function removeInlineFormatting() {
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount || sel.isCollapsed) return;

        var range = sel.getRangeAt(0);

        // 分割文本节点
        if (range.startContainer.nodeType === 3 && range.startOffset > 0) {
            range.startContainer.splitText(range.startOffset);
        }
        if (range.endContainer.nodeType === 3 && range.endOffset < range.endContainer.length) {
            range.endContainer.splitText(range.endOffset);
        }

        var fragment = range.extractContents();

        // 迭代栈替代递归，防深嵌套 DOM 栈溢出
        var stack = [fragment];
        var maxLoop = 10000;
        var loops = 0;
        while (stack.length > 0 && loops < maxLoop) {
            loops++;
            var current = stack.pop();
            if (!current || current.nodeType === 3) continue;

            var tag = current.nodeType === 1 ? current.tagName.toLowerCase() : '';
            if (/^(strong|b|em|i|u|s|strike|span|font|code|mark|sub|sup|small)$/i.test(tag)) {
                var parent = current.parentNode;
                while (current.firstChild) {
                    parent.insertBefore(current.firstChild, current);
                }
                parent.removeChild(current);
                // 继续处理当前父节点（可能合并了新子节点）
                stack.push(parent);
                continue;
            }

            // 非格式化标签，继续处理子节点
            var children = Array.prototype.slice.call(current.childNodes);
            for (var ci = children.length - 1; ci >= 0; ci--) {
                stack.push(children[ci]);
            }
        }

        range.insertNode(fragment);

        // 光标移到末尾
        sel.removeAllRanges();
        var newRange = document.createRange();
        newRange.setStartAfter(fragment.lastChild || fragment);
        newRange.collapse(true);
        sel.addRange(newRange);
    }

    // ===================================================================
    // 3. 工具函数（外部库加载、Markdown 转换、XSS 净化）
    // ===================================================================

    function loadScript(url) {
        return new Promise(function(resolve, reject) {
            var s = document.createElement('script');
            s.src = url;
            s.onload = resolve;
            s.onerror = reject;
            document.head.appendChild(s);
        });
    }

    var _markedLoaded = false;
    var _turndownLoaded = false;
    var _gfmLoaded = false; // turndown-plugin-gfm（表格→GFM 转换）
    var _mdLoading = false;
    var _mdQueue = [];

    function ensureMarkdownLibs(callback) {
        // 依赖库含 turndown-plugin-gfm（表格→GFM 语法转换）
        if (_markedLoaded && _turndownLoaded && _gfmLoaded) { callback(); return; }
        // 正在加载中，挂起等待
        if (_mdLoading) {
            _mdQueue.push(callback);
            return;
        }
        _mdLoading = true;
        Promise.all([
            loadScript('/assets/js/marked.min.js'),
            loadScript('/assets/js/turndown.js'),
            loadScript('/assets/js/turndown-plugin-gfm.js')
        ]).then(function() {
            _markedLoaded = true;
            _turndownLoaded = true;
            _gfmLoaded = true;
            _mdLoading = false;
            callback();
            while (_mdQueue.length) _mdQueue.shift()();
        }).catch(function() {
            _markedLoaded = true;
            _turndownLoaded = true;
            _gfmLoaded = true;
            _mdLoading = false;
            console.warn('Markdown 库加载失败，降级为纯文本模式');
            callback();
            while (_mdQueue.length) _mdQueue.shift()();
        });
    }

    function htmlToMarkdown(html) {
        if (!html) return '';
        try {
            var turndownService = new TurndownService({
                headingStyle: 'atx',
                codeBlockStyle: 'fenced',
                emDelimiter: '*'
            });
            // 注册 GFM 表格规则：<table> → | 分隔的 GFM 表格语法
            // （turndown-plugin-gfm 的 tables 插件；库未加载时静默跳过，不阻塞转换）
            if (typeof turndownPluginGfm !== 'undefined' && turndownPluginGfm.tables) {
                turndownService.use(turndownPluginGfm.tables);
            }
            return turndownService.turndown(html);
        } catch(e) {
            console.error('HTML→Markdown 转换失败:', e);
            return html;
        }
    }

    function markdownToHtml(md) {
        if (!md) return '';
        try {
            return marked.parse(md);
        } catch(e) {
            console.error('Markdown→HTML 转换失败:', e);
            return md;
        }
    }

    // 简单 HTML 净化函数（安全核心）
    // 使用 createHTMLDocument 沙箱，隔离脚本执行环境
    function sanitizeHTML(html) {
        if (!html) return "";
        var doc = document.implementation.createHTMLDocument('');
        var tempDiv = doc.createElement("div");
        tempDiv.innerHTML = html;

        function cleanNode(node) {
            if (node.nodeType === 1) {
                var tagName = node.tagName.toLowerCase();
                if (["script", "style", "iframe", "object", "embed", "form", "svg", "math", "template", "noscript"].indexOf(tagName) !== -1) {
                    node.parentNode.removeChild(node);
                    return;
                }
                var attrs = node.attributes;
                for (var i = attrs.length - 1; i >= 0; i--) {
                    var attrName = attrs[i].name.toLowerCase();
                    if (attrName.indexOf("on") === 0 ||
                        (attrName === "href" && attrs[i].value.toLowerCase().indexOf("javascript:") === 0) ||
                        (attrName === "src" && attrs[i].value.toLowerCase().indexOf("javascript:") === 0)) {
                        node.removeAttribute(attrs[i].name);
                    }
                }
                var children = Array.from(node.childNodes);
                for (var j = 0; j < children.length; j++) {
                    cleanNode(children[j]);
                }
            }
        }
        cleanNode(tempDiv);
        return tempDiv.innerHTML;
    }

    // 全局 HTML 转义（供链接插入、引用等功能使用）
    function escapeHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    // ===================================================================
    // 4. 格式命令（替代 execCommand）
    // ===================================================================

    var COMMANDS = {};

    // 4.1 行内格式化
    COMMANDS.bold = function() { toggleWrap('strong'); };
    COMMANDS.italic = function() { toggleWrap('em'); };
    COMMANDS.underline = function() { toggleWrap('u'); };
    COMMANDS.strikeThrough = function() { toggleWrap('s'); };
    COMMANDS.code = function() { toggleWrap('code'); };

    // 4.2 颜色（用 wrapSelection 而非 toggleWrap，避免与已有 span 冲突）
    COMMANDS.foreColor = function(color) {
        if (!color) return;
        wrapSelection('span', { style: { color: color } });
    };
    COMMANDS.backColor = function(color) {
        if (!color) return;
        wrapSelection('span', { style: { backgroundColor: color } });
    };
    COMMANDS.foreColor_clear = function() {
        var range = getSelectionRange();
        if (!range) return;
        var node = range.commonAncestorContainer;
        if (node.nodeType === 3) node = node.parentElement;
        var span = node.closest('span');
        if (span) {
            span.style.color = '';
            if (!span.style.cssText) {
                // 解包失败则手动清理
                if (!unwrapSelection('span')) {
                    var p = span.parentNode;
                    while (span.firstChild) p.insertBefore(span.firstChild, span);
                    p.removeChild(span);
                }
            }
        }
    };
    COMMANDS.backColor_clear = function() {
        var range = getSelectionRange();
        if (!range) return;
        var node = range.commonAncestorContainer;
        if (node.nodeType === 3) node = node.parentElement;
        var span = node.closest('span');
        if (span) {
            span.style.backgroundColor = '';
            if (!span.style.cssText) {
                if (!unwrapSelection('span')) {
                    var p = span.parentNode;
                    while (span.firstChild) p.insertBefore(span.firstChild, span);
                    p.removeChild(span);
                }
            }
        }
    };

    // 4.3 字号（用 wrapSelection，避免 toggleWrap 与已有 span 冲突）
    COMMANDS.fontSize = function(size) {
        if (!size) return;
        wrapSelection('span', { style: { fontSize: size + 'px' } });
    };

    // 4.4 标题
    COMMANDS.heading = function(level) {
        if (level < 1 || level > 6) return;
        splitBlockOrWhole('h' + level);
    };
    COMMANDS.paragraph = function() {
        splitBlockOrWhole('p');
    };

    // 4.5 对齐
    COMMANDS.align = function(align) {
        var block = getBlockNode();
        if (!block) return;
        block.style.textAlign = align;
    };

    // 4.6 列表
    COMMANDS.insertUnorderedList = function() {
        toggleList('ul');
    };
    COMMANDS.insertOrderedList = function() {
        toggleList('ol');
    };

    function toggleList(type) {
        var block = getBlockNode();
        if (!block) return;

        // 如果在列表中，取消列表
        var existingList = block.closest('ul, ol');
        if (existingList) {
            // 把列表项内容展开为段落
            var parent = existingList.parentNode;
            var items = Array.prototype.slice.call(existingList.children);
            var idx = 0;
            for (var i = 0; i < items.length; i++) {
                var p = document.createElement('p');
                while (items[i].firstChild) {
                    p.appendChild(items[i].firstChild);
                }
                parent.insertBefore(p, existingList);
                if (items[i] === block || items[i].contains(block)) {
                    idx = i;
                }
            }
            parent.removeChild(existingList);
            // 光标移到原来所在的段落末尾
            var paras = parent.querySelectorAll(':scope > p');
            var targetP = paras[idx] || paras[paras.length - 1];
            if (targetP) {
                var sel = window.getSelection();
                sel.removeAllRanges();
                var r = document.createRange();
                r.selectNodeContents(targetP);
                r.collapse(false);
                sel.addRange(r);
            }
            return;
        }

        var list = document.createElement(type);
        var li = document.createElement('li');
        // 用 DOM 节点迁移保留行内样式，而非 innerHTML 剥落样式
        while (block.firstChild) {
            li.appendChild(block.firstChild);
        }
        list.appendChild(li);
        block.parentNode.replaceChild(list, block);

        var sel = window.getSelection();
        sel.removeAllRanges();
        var r = document.createRange();
        r.selectNodeContents(li);
        r.collapse(false);
        sel.addRange(r);
    }

    // 4.7 链接
    COMMANDS.link = function(url) {
        if (!url) {
            url = prompt(window.__t('js.link_prompt'), 'https://');
            if (!url) return;
        }
        // 安全拦截：禁止 javascript: data: 等伪协议
        var lowUrl = url.toLowerCase().trim();
        if (lowUrl.indexOf('javascript:') === 0 || lowUrl.indexOf('data:') === 0) {
            alert(window.__t('js.link_protocol_block'));
            return;
        }
        var sel = window.getSelection();
        if (sel.isCollapsed) {
            insertAtCursor('<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener">' + escapeHtml(url) + '</a>');
        } else {
            var range = sel.getRangeAt(0);
            var a = document.createElement('a');
            a.href = url;
            a.target = '_blank';
            a.rel = 'noopener';
            try {
                range.surroundContents(a);
            } catch(e) {
                var fragment = range.extractContents();
                a.appendChild(fragment);
                range.insertNode(a);
            }
            sel.removeAllRanges();
            var newRange = document.createRange();
            newRange.setStartAfter(a);
            newRange.collapse(true);
            sel.addRange(newRange);
        }
    };

    // 4.8 分割线
    COMMANDS.hr = function() {
        var range = getAnyRange();
        if (!range) return;
        range.deleteContents();
        var hr = document.createElement('hr');
        range.insertNode(hr);
        // 在 hr 后面加个空行
        var br = document.createElement('br');
        hr.parentNode.insertBefore(br, hr.nextSibling);
        var sel = window.getSelection();
        sel.removeAllRanges();
        var newRange = document.createRange();
        newRange.setStartAfter(br);
        newRange.collapse(true);
        sel.addRange(newRange);
    };

    // 4.9 引用
    COMMANDS.blockquote = function() {
        var block = getBlockNode();
        if (!block) return;
        if (block.tagName === 'BLOCKQUOTE') {
            // 取消引用：拆出来
            var parent = block.parentNode;
            var p = document.createElement('p');
            p.innerHTML = block.innerHTML;
            parent.replaceChild(p, block);
            var sel = window.getSelection();
            sel.removeAllRanges();
            var r = document.createRange();
            r.setStart(p, 0);
            r.collapse(true);
            sel.addRange(r);
        } else {
            replaceBlock('blockquote');
        }
    };

    // 4.10 清除格式
    COMMANDS.removeFormat = function() {
        removeInlineFormatting();
    };

    // 4.11 撤销 / 重做
    COMMANDS.undo = function(editorId) {
        var editorData = ACTIVE_EDITORS[editorId];
        if (!editorData || !editorData.editor) return;
        var html = editorData.history.undo();
        if (html !== null) {
            editorData.editor.innerHTML = html;
            updateBtnStates(editorId);
        }
    };
    COMMANDS.redo = function(editorId) {
        var editorData = ACTIVE_EDITORS[editorId];
        if (!editorData || !editorData.editor) return;
        var html = editorData.history.redo();
        if (html !== null) {
            editorData.editor.innerHTML = html;
            updateBtnStates(editorId);
        }
    };

    // 4.12 全屏
    COMMANDS.fullscreen = function(editorId) {
        var editorData = ACTIVE_EDITORS[editorId];
        if (!editorData) return;
        var container = editorData.container;
        if (container.classList.contains('editor-fullscreen')) {
            container.classList.remove('editor-fullscreen');
            document.body.style.overflow = '';
            // 恢复行内固定高度，替换 CSS 中删掉的 !important 方案
            var opts = editorData._initOptions || {};
            var h = opts.height || defaultEditorHeight();
            editorData.editor.style.height = h + 'px';
        } else {
            container.classList.add('editor-fullscreen');
            document.body.style.overflow = 'hidden';
            // 全屏时移除行内固定高度，让 CSS flex:1 接管
            editorData.editor.style.height = '';
        }
    };

    // ===================================================================
    // 5. 新建编辑器实例
    // ===================================================================

    /**
     * 编辑器默认高度（响应式）：
     *   PC 端 300px；移动端 180px（配合软键盘，不遮挡输入区域）
     * 断点与 editor.css 移动端媒体查询一致（max-width: 768px）
     */
    function defaultEditorHeight() {
        var isMobile = window.matchMedia && window.matchMedia('(max-width: 768px)').matches;
        return isMobile ? 180 : 300;
    }

    function initEditor(textareaId, options) {
        var textarea = document.getElementById(textareaId);
        if (!textarea) return;

        var editorId = textareaId;

        options = options || {};
        var height = options.height || defaultEditorHeight();
        var placeholder = options.placeholder || "";

        var container = document.createElement("div");
        container.className = "editor-container";

        // === 模式切换选项卡 ===
        var tabBar = document.createElement("div");
        tabBar.className = "editor-tabs";
        tabBar.innerHTML = '<span class="editor-tab active" data-mode="visual">' + window.__t('js.editor.visual') + '</span>' +
                           '<span class="editor-tab" data-mode="markdown">Markdown</span>';
        container.appendChild(tabBar);

        // === 共用工具栏（放在两个模式外，始终可见） ===
        var toolbar = createToolbar(textareaId);
        container.appendChild(toolbar);

        // === 可视化编辑器 ===
        var editorWrap = document.createElement("div");
        editorWrap.className = "editor-visual-wrap";

        var editor = document.createElement("div");
        editor.className = "editor-editable";
        editor.contentEditable = "true";
        editor.setAttribute("data-placeholder", placeholder);
        editor.innerHTML = sanitizeHTML(textarea.value || "");
        editor.style.height = height + "px";
        editorWrap.appendChild(editor);
        container.appendChild(editorWrap);

        // === Markdown 源码编辑区（隐藏） ===
        // 注意：没有独立 mdToolbar，共用可视化工具栏
        var mdWrap = document.createElement("div");
        mdWrap.className = "editor-md-wrap";
        mdWrap.style.display = "none";
        var mdTextarea = document.createElement("textarea");
        mdTextarea.className = "editor-md-textarea";
        mdTextarea.style.height = height + "px";
        mdTextarea.style.width = "100%";
        mdTextarea.style.border = "none";
        mdTextarea.style.padding = "16px";
        mdTextarea.style.fontFamily = "Consolas, Monaco, 'Courier New', monospace";
        mdTextarea.style.fontSize = "15px";
        mdTextarea.style.lineHeight = "1.7";
        mdTextarea.style.resize = "vertical";
        mdTextarea.style.outline = "none";
        // 背景/文字色由 editor.css 变量控制（var(--bg-white)/var(--mn-content-text)），随主题自动适配
        mdTextarea.spellcheck = false;
        mdWrap.appendChild(mdTextarea);
        container.appendChild(mdWrap);

        // === 字数统计（放在所有编辑区之后，始终在底部） ===
        var wordCountBar = document.createElement("div");
        wordCountBar.className = "editor-footer-bar";
        var wcSpan = document.createElement("span");
        wcSpan.className = "editor-word-count";
        wcSpan.textContent = window.__t('js.word_count', {count: 0});
        wordCountBar.appendChild(wcSpan);
        container.appendChild(wordCountBar);

        var hiddenInput = document.createElement("textarea");
        hiddenInput.name = textarea.name;
        hiddenInput.style.display = "none";
        hiddenInput.id = textarea.id + "_hidden";
        container.appendChild(hiddenInput);

        textarea.parentNode.insertBefore(container, textarea);
        textarea.style.display = "none";
        textarea.removeAttribute("name");
        textarea.removeAttribute("required");
        textarea.id = textarea.id + "_backup";

        // 历史记录
        var history = createHistory();
        history.reset(editor.innerHTML);

        // 保存编辑状态
        ACTIVE_EDITORS[textareaId] = {
            textarea: textarea,
            editor: editor,
            hidden: hiddenInput,
            toolbar: toolbar,
            editorWrap: editorWrap,
            mdWrap: mdWrap,
            mdTextarea: mdTextarea,
            tabs: tabBar,
            container: container,
            history: history,
            wordCount: wcSpan,
            _initOptions: options
        };

        // === 事件绑定 ===

        // 表单提交
        var form = textarea.form;
        if (form) {
            form.addEventListener("submit", function() {
                var mdMode = mdWrap.style.display !== 'none';
                if (mdMode) {
                    var md = mdTextarea.value;
                    if (md.trim()) {
                        editor.innerHTML = sanitizeHTML(markdownToHtml(md));
                    }
                }
                var clean = sanitizeHTML(editor.innerHTML);
                // 安全包裹：防超大文本导致 btoa 崩溃丢数据
                try {
                    hiddenInput.value = btoa(unescape(encodeURIComponent(clean)));
                } catch(err) {
                    console.error('Base64 编码失败，内容过长', err);
                    // 截断后重新过沙箱净化，防止切断的残缺标签被浏览器修补引发 XSS
                    var safeCut = sanitizeHTML(clean.substring(0, 5000000));
                    hiddenInput.value = btoa(unescape(encodeURIComponent(safeCut)));
                    alert(window.__t('js.content_truncated'));
                }
            });
        }

        // 编辑器输入 → 自动保存历史 + 更新字数
        var inputTimer = null;
        editor.addEventListener("input", function(e) {
            updateWordCount(editorId);
            // 空格/回车立即压栈，普通打字防抖 1000ms 后再压
            if (e.inputType === "insertParagraph" || e.data === " ") {
                if (inputTimer) clearTimeout(inputTimer);
                ACTIVE_EDITORS[textareaId].history.push(editor.innerHTML);
                updateBtnStates(editorId);
            } else {
                if (inputTimer) clearTimeout(inputTimer);
                inputTimer = setTimeout(function() {
                    ACTIVE_EDITORS[textareaId].history.push(editor.innerHTML);
                    updateBtnStates(editorId);
                }, 1000);
            }
        });

        // 选区变化 → 更新按钮高亮状态，并保存编辑器内选区供命令恢复
        document.addEventListener("selectionchange", function() {
            updateBtnStates(editorId);
            saveEditorRange(ACTIVE_EDITORS[editorId]);
        });
        // 键盘/光标移动时同步保存（防 selectionchange 在纯键盘输入时不触发的机型）
        editor.addEventListener("keyup", function() {
            saveEditorRange(ACTIVE_EDITORS[editorId]);
        });
        editor.addEventListener("mouseup", function() {
            saveEditorRange(ACTIVE_EDITORS[editorId]);
        });

        // Paste 图片
        editor.addEventListener("paste", function(e) {
            var items = e.clipboardData && e.clipboardData.items;
            if (items) {
                for (var i = 0; i < items.length; i++) {
                    if (items[i].type.indexOf("image") === 0) {
                        var file = items[i].getAsFile();
                        if (file) {
                            e.preventDefault();
                            uploadPastedImage(textareaId, file);
                            return;
                        }
                    }
                }
            }
            // 文本/富文本粘贴：拦截默认行为，取剪贴板数据净化后经 insertAtCursor 插入，
            // 消除 setTimeout 200ms 窗口期恶意 HTML 已被渲染的风险
            var html = e.clipboardData.getData("text/html");
            var text = e.clipboardData.getData("text/plain");
            if (html || text) {
                e.preventDefault();
                if (html) {
                    insertAtCursor(sanitizeHTML(html));
                } else {
                    insertAtCursor(sanitizeHTML(escapeHtml(text)));
                }
                processExternalImages(editor);
            }
        });

        // 切换事件
        var tabs = tabBar.querySelectorAll('.editor-tab');
        for (var ti = 0; ti < tabs.length; ti++) {
            (function(tab) {
                tab.addEventListener('click', function() {
                    var mode = tab.getAttribute('data-mode');
                    switchEditorMode(textareaId, mode, tabs, editor, editorWrap, mdWrap, mdTextarea);
                });
            })(tabs[ti]);
        }

        // 编辑器模式记忆：初始化时读取 localStorage 上次使用的模式（visual/markdown），
        // 默认可视化；读取失败（隐私模式/禁用存储）时安全回退为默认模式
        var savedMode = 'visual';
        try { savedMode = localStorage.getItem('flinthub_editor_mode') || 'visual'; } catch(e) { savedMode = 'visual'; }
        if (savedMode === 'markdown') {
            // 初始化恢复模式时 skipFocus=true：不 focus，防止打开帖子详情页被自动滚动定位到回复框
            switchEditorMode(textareaId, 'markdown', tabs, editor, editorWrap, mdWrap, mdTextarea, true);
        }

        // 初始字数统计
        updateWordCount(textareaId);
    }

    // ===================================================================
    // 6. 工具栏创建
    // ===================================================================

    function createToolbar(editorId) {
        var tb = document.createElement("div");
        tb.className = "editor-toolbar";

        // === 行内格式化 ===
        addBtn(tb, 'bold', '<i class="fa fa-bold"></i>', window.__t('js.editor.bold'));
        addBtn(tb, 'italic', '<i class="fa fa-italic"></i>', window.__t('js.editor.italic'));
        addBtn(tb, 'underline', '<i class="fa fa-underline"></i>', window.__t('js.underline'));
        addBtn(tb, 'strikeThrough', '<i class="fa fa-strikethrough"></i>', window.__t('js.strike'));
        addSep(tb);

        // === 标题 ===
        addBtn(tb, 'heading', '<i class="fa fa-header"></i><sub>2</sub>', window.__t('js.heading2'), '2');
        addBtn(tb, 'heading', '<i class="fa fa-header"></i><sub>3</sub>', window.__t('js.heading3'), '3');
        addSep(tb);

        // === 列表 ===
        addBtn(tb, 'insertUnorderedList', '<i class="fa fa-list-ul"></i>', window.__t('js.ulist'));
        addBtn(tb, 'insertOrderedList', '<i class="fa fa-list-ol"></i>', window.__t('js.olist'));
        addSep(tb);

        // === 插入 ===
        addBtn(tb, 'link', '<i class="fa fa-link"></i>', window.__t('js.editor.link'));
        addBtn(tb, 'image', '<i class="fa fa-image"></i>', window.__t('js.editor.image'));
        addSep(tb);

        // === 表情 ===
        addBtn(tb, 'emoji', '<i class="fa fa-smile-o"></i>', window.__t('js.emoji'));
        addSep(tb);

        // ========== 新增 Phase 1 按钮 ==========

        // 字体颜色
        addColorBtn(tb, 'foreColor', '<i class="fa fa-font"></i>', window.__t('js.forecolor'));
        addSep(tb);

        // 对齐
        addBtn(tb, 'align', '<i class="fa fa-align-left"></i>', window.__t('js.align_left'), 'left');
        addBtn(tb, 'align', '<i class="fa fa-align-center"></i>', window.__t('js.align_center'), 'center');
        addBtn(tb, 'align', '<i class="fa fa-align-right"></i>', window.__t('js.align_right'), 'right');
        addSep(tb);

        // 分割线
        addBtn(tb, 'hr', '<i class="fa fa-minus"></i>', window.__t('js.hr'));
        addSep(tb);

        // 撤销 / 重做 / 清除格式
        addBtn(tb, 'undo', '<i class="fa fa-undo"></i>', window.__t('js.undo'));
        addBtn(tb, 'redo', '<i class="fa fa-repeat"></i>', window.__t('js.redo'));
        addSep(tb);

        addBtn(tb, 'removeFormat', '<i class="fa fa-eraser"></i>', window.__t('js.remove_format'));
        addSep(tb);

        // 全屏
        addBtn(tb, 'fullscreen', '<i class="fa fa-arrows-alt"></i>', window.__t('js.fullscreen'));

        // 隐藏的图片文件选择器
        var fileInput = document.createElement("input");
        fileInput.type = "file";
        fileInput.accept = "image/*";
        fileInput.style.display = "none";
        fileInput.id = "editor_img_" + editorId;
        fileInput.addEventListener("change", function(e) {
            var file = e.target.files[0];
            if (!file) return;
            uploadImage(editorId, file);
            this.value = "";
        });
        tb.appendChild(fileInput);

        return tb;
    }

    // === 辅助：桌面端点按工具栏按钮时不抢走编辑器焦点/选区 ===
    // 注意：这里只对 mousedown preventDefault（不阻止 click，但能避免按钮抢焦点）；
    // 不能对 touchstart preventDefault——移动端上这会掐断随后的 click，导致按钮彻底无响应。
    // 移动端的选区丢失改由「保存/恢复 Range」兜底（见 handleCommand 与 saveEditorRange）。
    function preventEditorBlur(el) {
        el.addEventListener("mousedown", function(e) { e.preventDefault(); });
    }

    // === 辅助：保存编辑器内当前选区 Range，供命令执行前恢复 ===
    function saveEditorRange(editorData) {
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount) return;
        var node = sel.getRangeAt(0).commonAncestorContainer;
        if (node.nodeType === 3) node = node.parentElement;
        if (!editorData || !editorData.editor || !editorData.editor.contains(node)) return;
        var r = sel.getRangeAt(0);
        // 已有一份"非折叠"文字选区时，不要让（点按按钮触发的）折叠光标把它覆盖掉，
        // 否则命令执行前无从恢复用户选中的文字。
        if (r.collapsed && editorData.savedRange && !editorData.savedRange.collapsed) return;
        editorData.savedRange = r.cloneRange();
    }

    // === 辅助：添加普通按钮 ===
    function addBtn(tb, cmd, html, title, val) {
        var el = document.createElement("button");
        el.type = "button";
        el.className = "editor-btn";
        el.title = title;
        el.innerHTML = html;
        el.setAttribute('data-cmd', cmd);
        if (val !== undefined) el.setAttribute('data-val', val);
        preventEditorBlur(el);

        el.addEventListener("click", function(e) {
            e.preventDefault();
            handleCommand(tb.getAttribute('data-editor') || getEditorIdByToolbar(tb), cmd, val || el.getAttribute('data-val') || null);
        });

        tb.appendChild(el);
        return el;
    }

    // === 辅助：添加分隔线 ===
    function addSep(tb) {
        var sep = document.createElement("span");
        sep.className = "editor-separator";
        tb.appendChild(sep);
    }

    // === 辅助：添加颜色选择按钮（下拉面板） ===
    function addColorBtn(tb, cmd, label, title) {
        var wrap = document.createElement("div");
        wrap.className = "editor-color-wrap";
        wrap.style.position = "relative";
        wrap.style.display = "inline-block";

        var btn = document.createElement("button");
        btn.type = "button";
        btn.className = "editor-btn editor-color-btn";
        btn.title = title;
        btn.innerHTML = label;
        btn.setAttribute('data-cmd', cmd);
        wrap.appendChild(btn);
        preventEditorBlur(btn);

        // 下拉面板
        var panel = document.createElement("div");
        panel.className = "editor-color-panel";
        panel.style.display = "none";
        panel.style.position = "absolute";
        panel.style.top = "100%";
        panel.style.left = "0";
        panel.style.zIndex = "1000";
        // 背景/边框/阴影由 editor.css .editor-color-panel 变量规则控制，随主题自动适配
        panel.style.borderRadius = "8px";
        panel.style.padding = "8px";
        panel.style.boxShadow = "0 4px 12px rgba(0,0,0,0.12)";
        panel.style.width = "180px";

        var colors = [
            '#000000','#434343','#666666','#999999','#b7b7b7','#cccccc','#d9d9d9','#efefef','#f3f3f3','#ffffff',
            '#980000','#ff0000','#ff9900','#ffff00','#00ff00','#00ffff','#4a86e8','#0000ff','#9900ff','#ff00ff',
            '#e6b8af','#f4cccc','#fce5cd','#fff2cc','#d9ead3','#d0e0e3','#c9daf8','#cfe2f3','#d9d2e9','#ead1dc',
            '#dd7e6b','#ea9999','#f9cb9c','#ffe599','#b6d7a8','#a2c4c9','#a4c2f4','#9fc5e8','#b4a7d6','#d5a6bd',
            '#cc4125','#e06666','#f6b26b','#ffd966','#93c47d','#76a5af','#6d9eeb','#6fa8dc','#8e7cc3','#c27ba0',
            '#a61c00','#cc0000','#e69138','#f1c232','#6aa84f','#45818e','#3c78d8','#3d85c6','#674ea7','#a64d79',
            '#85200c','#990000','#b45f06','#bf9000','#38761d','#134f5c','#1155cc','#0b5394','#351c75','#741b47',
            '#5b0f00','#660000','#783f04','#7f6000','#274e13','#0c343d','#1c4587','#073763','#20124d','#4c1130'
        ];

        var grid = document.createElement("div");
        grid.style.cssText = "display:grid;grid-template-columns:repeat(10,1fr);gap:2px;margin-bottom:6px;";

        for (var i = 0; i < colors.length; i++) {
            (function(c) {
                var swatch = document.createElement("div");
                swatch.style.cssText = "width:16px;height:16px;border-radius:3px;cursor:pointer;border:1px solid #e0e0e0;background:" + c + ";";
                preventEditorBlur(swatch);
                swatch.addEventListener("click", function(e) {
                    e.stopPropagation();
                    handleCommand(null, cmd, c);
                    panel.style.display = "none";
                });
                grid.appendChild(swatch);
            })(colors[i]);
        }
        panel.appendChild(grid);

        // 自定义颜色输入
        var customRow = document.createElement("div");
        customRow.style.cssText = "display:flex;gap:4px;align-items:center;";
        var customLabel = document.createElement("span");
        customLabel.textContent = window.__t('js.custom');
        customLabel.style.cssText = "font-size:11px;color:#666;";
        var customInput = document.createElement("input");
        customInput.type = "color";
        customInput.style.cssText = "width:30px;height:24px;border:none;padding:0;cursor:pointer;";
        customInput.addEventListener("change", function() {
            handleCommand(null, cmd, this.value);
            panel.style.display = "none";
        });
        customRow.appendChild(customLabel);
        customRow.appendChild(customInput);
        panel.appendChild(customRow);

        // 清除颜色按钮
        var clearBtn = document.createElement("div");
        clearBtn.textContent = window.__t('js.clear_color');
        clearBtn.style.cssText = "font-size:11px;color:#e03131;cursor:pointer;margin-top:6px;text-align:center;padding:3px;border-radius:4px;";
        preventEditorBlur(clearBtn);
        clearBtn.addEventListener("click", function(e) {
            e.stopPropagation();
            handleCommand(null, cmd + '_clear', '');
            panel.style.display = "none";
        });
        panel.appendChild(clearBtn);

        wrap.appendChild(panel);

        // 切换面板
        btn.addEventListener("click", function(e) {
            e.stopPropagation();
            var isVisible = panel.style.display !== "none";
            // 关闭所有颜色面板
            var allPanels = document.querySelectorAll('.editor-color-panel');
            for (var p = 0; p < allPanels.length; p++) {
                allPanels[p].style.display = "none";
            }
            panel.style.display = isVisible ? "none" : "";
        });

        tb.appendChild(wrap);
        return wrap;
    }

    // === 辅助：从 toolbar 找 editorId ===
    function getEditorIdByToolbar(tb) {
        for (var id in ACTIVE_EDITORS) {
            if (ACTIVE_EDITORS.hasOwnProperty(id) && ACTIVE_EDITORS[id].toolbar === tb) {
                return id;
            }
        }
        return null;
    }

    // ===================================================================
    // 7. 命令分发
    // ===================================================================

    function handleCommand(editorId, cmd, val) {
        // 如果是通过颜色面板触发的，没有 editorId，需要自动查找
        if (!editorId) {
            editorId = findActiveEditorId();
        }
        if (!editorId) return;
        var editorData = ACTIVE_EDITORS[editorId];
        if (!editorData || !editorData.editor) return;

        // ── Markdown 模式：路由到 MD 快捷插入 ──
        var isMdMode = editorData.mdWrap && editorData.mdWrap.style.display !== 'none';
        if (isMdMode) {
            handleMdModeCommand(editorId, cmd, val);
            return;
        }

        var sel = window.getSelection();
        // 移动端点按工具栏会把文本选区折叠成"编辑器内的光标"（sel.isCollapsed 为真，
        // anchorNode 仍在编辑器内）。此时命令拿不到文字选区 → 需恢复到此前保存的非折叠 Range。
        var hasLiveSel = sel && sel.rangeCount > 0 && !sel.isCollapsed
            && editorData.editor.contains((sel.anchorNode || sel.focusNode) || document.body);
        if (!hasLiveSel && editorData.savedRange && !editorData.savedRange.collapsed) {
            editorData.editor.focus();
            try {
                var rr = editorData.savedRange.cloneRange();
                var s2 = window.getSelection();
                s2.removeAllRanges();
                s2.addRange(rr);
            } catch (e) { /* 选区已失效时忽略，退化为光标插入 */ }
        }

        if (cmd === 'emoji') {
            toggleEmojiPicker(editorId);
            return;
        }
        if (cmd === 'image') {
            var fi = document.getElementById("editor_img_" + editorId);
            if (fi) fi.click();
            return;
        }
        if (cmd === 'link') {
            COMMANDS.link();
            afterEdit(editorId);
            return;
        }
        if (cmd === 'foreColor_clear' || cmd === 'backColor_clear') {
            // 清除颜色：unwrap span with color/bgcolor
            clearColorFormat(editorData.editor, cmd === 'foreColor_clear' ? 'color' : 'backgroundColor');
            afterEdit(editorId);
            return;
        }
        if (cmd === 'foreColor' || cmd === 'backColor') {
            if (val) {
                var styleProp = cmd === 'foreColor' ? 'color' : 'backgroundColor';
                var attrs = { style: {} };
                attrs.style[styleProp] = val;
                wrapSelection('span', attrs);
            }
            afterEdit(editorId);
            return;
        }
        if (cmd === 'heading') {
            COMMANDS.heading(val || 2);
            afterEdit(editorId);
            return;
        }
        if (cmd === 'align') {
            COMMANDS.align(val || 'left');
            afterEdit(editorId);
            return;
        }
        if (cmd === 'undo') {
            COMMANDS.undo(editorId);
            return;
        }
        if (cmd === 'redo') {
            COMMANDS.redo(editorId);
            return;
        }
        if (cmd === 'fullscreen') {
            COMMANDS.fullscreen(editorId);
            return;
        }
        if (cmd === 'hr') {
            COMMANDS.hr();
            afterEdit(editorId);
            return;
        }
        if (cmd === 'blockquote') {
            COMMANDS.blockquote();
            afterEdit(editorId);
            return;
        }
        if (cmd === 'removeFormat') {
            COMMANDS.removeFormat();
            afterEdit(editorId);
            return;
        }
        // 通用命令：在 COMMANDS 中有定义则调用
        if (typeof COMMANDS[cmd] === 'function') {
            COMMANDS[cmd](val);
            afterEdit(editorId);
        }
    }

    /**
     * Markdown 模式命令处理：将可视化命令映射为 MD 快捷插入标签
     */
    function handleMdModeCommand(editorId, cmd, val) {
        var editorData = ACTIVE_EDITORS[editorId];
        if (!editorData || !editorData.mdTextarea) return;

        // 可视化 cmd → MD tag 映射表
        var cmdToMdTag = {
            'bold': 'bold',
            'italic': 'italic',
            'code': 'code',
            'underline': null,
            'strikeThrough': null,
            'heading': 'heading',
            'insertUnorderedList': 'list',
            'insertOrderedList': 'list',
            'link': 'link',
            'image': 'image',
            'emoji': 'emoji',
            'blockquote': 'quote',
            'hr': 'hr',
            'codeblock': 'codeblock'
        };

        // 不支持的命令（颜色、对齐、清除格式等）静默忽略
        var tag = cmdToMdTag[cmd];
        if (cmd === 'heading' && val) {
            // heading 特殊处理：用对应级别
            tag = 'heading';
        }
        if (tag === 'emoji') {
            toggleEmojiPicker(editorId);
            return;
        }
        if (tag) {
            insertMDTag(editorData.mdTextarea, tag);
            updateWordCount(editorId);
        }
        // focus 保持
        editorData.mdTextarea.focus();
    }

    function afterEdit(editorId) {
        var editorData = ACTIVE_EDITORS[editorId];
        if (!editorData) return;
        editorData.history.push(editorData.editor.innerHTML);
        updateWordCount(editorId);
        updateBtnStates(editorId);
    }

    // 清除颜色格式化
    function clearColorFormat(editorEl, styleProp) {
        var spans = editorEl.querySelectorAll('span');
        for (var i = 0; i < spans.length; i++) {
            var sp = spans[i];
            if (sp.style[styleProp]) {
                sp.style[styleProp] = '';
                if (!sp.style.cssText || sp.style.cssText === '') {
                    // 无样式了，unwrap
                    var parent = sp.parentNode;
                    while (sp.firstChild) {
                        parent.insertBefore(sp.firstChild, sp);
                    }
                    parent.removeChild(sp);
                }
            }
        }
    }

    // ===================================================================
    // 8. 按钮状态更新（高亮当前格式）
    // ===================================================================

    function updateBtnStates(editorId) {
        var editorData = ACTIVE_EDITORS[editorId];
        if (!editorData || !editorData.toolbar) return;
        var tb = editorData.toolbar;
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount) return;

        var buttons = tb.querySelectorAll('.editor-btn[data-cmd]');
        for (var i = 0; i < buttons.length; i++) {
            var btn = buttons[i];
            var cmd = btn.getAttribute('data-cmd');
            var active = false;

            if (cmd === 'bold') active = isSelectionInTag('strong') || isSelectionInTag('b');
            else if (cmd === 'italic') active = isSelectionInTag('em') || isSelectionInTag('i');
            else if (cmd === 'underline') active = isSelectionInTag('u');
            else if (cmd === 'strikeThrough') active = isSelectionInTag('s') || isSelectionInTag('strike');
            else if (cmd === 'code') active = isSelectionInTag('code');
            else if (cmd === 'align') {
                var block = getBlockNode();
                if (block) {
                    active = block.style.textAlign === (btn.getAttribute('data-val') || 'left');
                }
            }

            btn.classList.toggle('editor-btn-active', active);
        }
    }

    // ===================================================================
    // 9. 字数统计
    // ===================================================================

    function updateWordCount(editorId) {
        var editorData = ACTIVE_EDITORS[editorId];
        if (!editorData || !editorData.wordCount) return;

        var isMdMode = editorData.mdWrap && editorData.mdWrap.style.display !== 'none';
        var text = '';
        if (isMdMode) {
            text = editorData.mdTextarea.value || '';
        } else {
            text = editorData.editor ? (editorData.editor.textContent || '') : '';
        }
        // 去除首尾空白，统计中文字符+英文单词
        var count = 0;
        text = text.trim();
        if (text) {
            count = text.length;
        }
        editorData.wordCount.textContent = window.__t('js.word_count', {count: count});
    }

    // ===================================================================
    // 10. 模式切换
    // ===================================================================

    // MD 模式下不可用的按钮命令
    var MD_HIDDEN_CMDS = ['underline', 'strikeThrough', 'undo', 'redo', 'removeFormat'];
    // MD 模式下需要隐藏整个容器的命令
    var MD_HIDDEN_WRAPPERS = ['foreColor', 'backColor', 'align'];

    function switchEditorMode(editorId, mode, tabs, editor, editorWrap, mdWrap, mdTextarea, skipFocus) {
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('active', tabs[i].getAttribute('data-mode') === mode);
        }
        // 模式记忆：每次切换时把当前模式写入 localStorage（供下次打开编辑器恢复）
        try { localStorage.setItem('flinthub_editor_mode', mode); } catch(e) { /* 隐私模式/禁用存储时忽略 */ }
        var isMd = mode === 'markdown';
        if (isMd) {
            ensureMarkdownLibs(function() {
                var html = editor.innerHTML;
                if (html === '<br>' || html === '') html = '';
                mdTextarea.value = htmlToMarkdown(html);
            });
            // 同步高度：切换到 MD 时，textarea 高度跟随可视化编辑器实际高度
            mdTextarea.style.height = editor.offsetHeight + 'px';
            editorWrap.style.display = 'none';
            mdWrap.style.display = '';
            // 初始化恢复模式（skipFocus=true）时不 focus，防止打开帖子详情页被自动滚动定位到回复框；
            // 用户手动点击 tab 切换（skipFocus 缺省/undefined）仍保留 focus 行为
            if (!skipFocus) mdTextarea.focus();
        } else {
            ensureMarkdownLibs(function() {
                var md = mdTextarea.value;
                if (md.trim()) {
                    editor.innerHTML = sanitizeHTML(markdownToHtml(md));
                    var ed = ACTIVE_EDITORS[editorId];
                    if (ed) ed.history.push(editor.innerHTML);
                }
            });
            // 固定高度：切回可视化时保持固定默认高度，长文由内部滚动承载（不再跟随 scrollHeight 增长）
            editor.style.height = defaultEditorHeight() + 'px';
            mdWrap.style.display = 'none';
            editorWrap.style.display = '';
        }

        // 隐藏/显示 MD 下不可用的工具栏按钮
        var edData = ACTIVE_EDITORS[editorId];
        if (edData && edData.toolbar) {
            var tb = edData.toolbar;
            // 单独按钮：通过 data-cmd 匹配
            var allBtns = tb.querySelectorAll('.editor-btn[data-cmd]');
            for (var b = 0; b < allBtns.length; b++) {
                var cmd = allBtns[b].getAttribute('data-cmd');
                if (MD_HIDDEN_CMDS.indexOf(cmd) !== -1) {
                    allBtns[b].style.display = isMd ? 'none' : '';
                }
            }
            // 容器类按钮（颜色选择器包裹层、对齐按钮行）
            var hiddenSepNext = false;
            var children = tb.childNodes;
            for (var c = children.length - 1; c >= 0; c--) {
                var child = children[c];
                if (child.nodeType !== 1) continue;
                // 颜色选择器包裹层
                if (child.classList && child.classList.contains('editor-color-wrap')) {
                    child.style.display = isMd ? 'none' : '';
                }
                // 对齐按钮用 data-val 识别（cmd=align 的按钮）
                if (child.nodeType === 1 && child.getAttribute) {
                    var dataCmd = child.getAttribute('data-cmd');
                    if (dataCmd && MD_HIDDEN_WRAPPERS.indexOf(dataCmd) !== -1) {
                        child.style.display = isMd ? 'none' : '';
                    }
                }
                // 连续隐藏按钮前面的分隔线也隐藏 → 用相邻兄弟优化
            }
            // 清理连续隐藏导致的孤立分隔线
            if (isMd) {
                cleanOrphanSeps(tb);
            }
        }

        updateWordCount(editorId);
    }

    // 移除孤立的 separator（两边都隐藏时）
    function cleanOrphanSeps(toolbar) {
        var children = toolbar.childNodes;
        for (var i = children.length - 1; i >= 0; i--) {
            if (children[i].className === 'editor-separator') {
                var prev = i > 0 ? children[i-1] : null;
                var next = i < children.length - 1 ? children[i+1] : null;
                var prevHidden = prev && prev.nodeType === 1 && prev.style.display === 'none';
                var nextHidden = next && next.nodeType === 1 && next.style.display === 'none';
                if (prevHidden || nextHidden) {
                    children[i].style.display = 'none';
                } else {
                    children[i].style.display = '';
                }
            }
        }
    }

    // ===================================================================
    // 11. Markdown 快捷插入
    // ===================================================================

    function insertMDTag(textarea, tag) {
        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;
        var text = textarea.value;
        var selText = text.substring(start, end);
        var before, after, insert;

        // 块级插入判断：如果光标不在行首且前一字符不是换行，才加 \n
        function ensureNewline(text, pos) {
            if (pos === 0) return '';
            var prevChar = text.charAt(pos - 1);
            return prevChar === '\n' ? '' : '\n';
        }

        switch (tag) {
            case 'bold':    before = '**'; after = '**'; break;
            case 'italic':  before = '*'; after = '*'; break;
            case 'code':    before = '`'; after = '`'; break;
            case 'codeblock':
                before = ensureNewline(text, start) + '```\n'; after = '\n```\n'; break;
            case 'link':
                if (selText) {
                    insert = '[' + selText + '](url)';
                    textarea.value = text.substring(0, start) + insert + text.substring(end);
                    textarea.selectionStart = textarea.selectionEnd = start + selText.length + 3;
                    textarea.focus();
                    return;
                }
                before = '['; after = '](url)'; break;
            case 'image':   before = '!['; after = '](url)'; break;
            case 'quote':   before = ensureNewline(text, start) + '> '; after = ''; break;
            case 'list':    before = ensureNewline(text, start) + '- '; after = ''; break;
            case 'hr':      before = ensureNewline(text, start) + '---\n'; after = ''; break;
            default:        before = ''; after = ''; break;
        }
        if (!insert) {
            insert = before + (selText || '') + after;
        }
        textarea.value = text.substring(0, start) + insert + text.substring(end);
        var cursor = start + insert.length;
        if (selText) {
            cursor = start + before.length + selText.length;
        }
        textarea.selectionStart = textarea.selectionEnd = cursor;
        textarea.focus();
    }

    // ===================================================================
    // 12. 图片上传
    // ===================================================================

    function uploadAndInsertImage(editorId, file) {
        if (file.size > 5 * 1024 * 1024) {
            alert(window.__t('js.image_too_large'));
            return;
        }

        var editorData = ACTIVE_EDITORS[editorId];
        if (!editorData) return;

        var placeholder = document.createTextNode(window.__t('js.uploading_placeholder'));
        editorData.editor.focus();

        var sel = window.getSelection();
        var range = sel.rangeCount > 0 ? sel.getRangeAt(0) : document.createRange();
        range.insertNode(placeholder);
        range.setStartAfter(placeholder);
        range.collapse(true);
        sel.removeAllRanges();
        sel.addRange(range);

        var formData = new FormData();
        formData.append("file", file);
        if (window.CSRF_TOKEN) formData.append("csrf", window.CSRF_TOKEN);

        var xhr = new XMLHttpRequest();
        xhr.open("POST", "/api/upload?ajax=1", true);
        xhr.onload = function() {
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp.success && resp.url) {
                    var img = document.createElement("img");
                    img.src = resp.url;
                    img.alt = "";
                    img.style.maxWidth = "100%";
                    if (placeholder.parentNode) {
                        placeholder.parentNode.replaceChild(img, placeholder);
                    }
                } else {
                    alert(resp.error || window.__t('js.upload_failed'));
                    if (placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);
                }
            } catch(e) {
                alert(window.__t('js.upload_failed'));
                if (placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);
            }
            afterEdit(editorId);
        };
        xhr.onerror = function() {
            alert(window.__t('js.network_error'));
            if (placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);
        };
        xhr.send(formData);
    }

    function uploadImage(editorId, file) {
        uploadAndInsertImage(editorId, file);
    }

    function uploadPastedImage(editorId, file) {
        uploadAndInsertImage(editorId, file);
    }

    // ===================================================================
    // 13. 外部图片处理
    // ===================================================================

    function normalizeContent(el, depth) {
        if (!el || !el.childNodes) return;
        depth = depth || 0;
        if (depth > 50) return; // 防无限递归
        for (var i = el.childNodes.length - 1; i >= 0; i--) {
            var node = el.childNodes[i];
            if (node.nodeType === 1) {
                var tag = node.tagName;
                if (tag === "DIV" && !node.innerHTML.trim()) {
                    el.removeChild(node);
                } else if ((tag === "FONT" || tag === "SPAN") && !node.attributes.length && !node.innerHTML.trim()) {
                    el.removeChild(node);
                } else {
                    normalizeContent(node, depth + 1);
                }
            }
        }
    }

    function processExternalImages(editor) {
        if (!editor) return;
        var imgs = editor.querySelectorAll("img");
        var processed = 0;
        for (var i = 0; i < imgs.length; i++) {
            if (processed >= 10) break;
            var img = imgs[i];
            var src = img.getAttribute("src") || "";
            if (!src || src.indexOf("data:") === 0 || src.indexOf((window.BASE_PATH || '') + "/assets/uploads/") >= 0) continue;
            if (src.indexOf("http://") === 0 || src.indexOf("https://") === 0) {
                processed++;
                (function(imgEl, srcUrl) {
                    var xhr = new XMLHttpRequest();
                    xhr.open("GET", (window.BASE_PATH || '') + "/api/fetch-image?url=" + encodeURIComponent(srcUrl), true);
                    xhr.onload = function() {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.success && resp.url) {
                                // 强校验：只允许 http/https 和相对路径，防后端返回 javascript: 伪协议
                                var lowUrl = resp.url.toLowerCase().trim();
                                if (lowUrl.indexOf('http://') === 0 || lowUrl.indexOf('https://') === 0 || lowUrl.indexOf('/') === 0) {
                                    imgEl.src = resp.url;
                                }
                            }
                        } catch(e) {}
                    };
                    xhr.send();
                })(img, src);
            }
        }
    }

    // ===================================================================
    // 14. 站内表情选择器
    // ===================================================================

    var EMOJI_LIST = [
        ['guzhang','鼓掌'],['nanguo','难过'],['suanle','酸了'],['guile','跪了'],
        ['tiaopi','调皮'],['lianhong','脸红'],['tuodandoge','脱单doge'],['shengli','胜利'],
        ['fanbaiyan','翻白眼'],['geixinxin','给心心'],['teng','疼'],['yihuo','疑惑'],
        ['shengbing','生病'],['shengqi','生气'],['dianzan','点赞'],['huaji','滑稽'],
        ['waizui','歪嘴'],['xingxingyan','星星眼'],['wuyu','无语'],['zhichi','支持'],
        ['piezui','撇嘴'],['wulian','捂脸'],['wuyan','捂眼'],['yongbao','拥抱'],
        ['baoquan','抱拳'],['koubi','抠鼻'],['zhuakuang','抓狂'],['dacall','打call'],
        ['jingya','惊讶'],['jingxi','惊喜'],['sikao','思考'],['weixiao','微笑'],
        ['ganbei','干杯'],['ganga','尴尬'],['haixiu','害羞'],['xianqi','嫌弃'],
        ['weiqu','委屈'],['miaoah','妙啊'],['fendou','奋斗'],['daxiao','大笑'],
        ['daku','大哭'],['mojing','墨镜'],['dudu','嘟嘟'],['xusheng','嘘声'],
        ['keguazi','嗑瓜子'],['xihuan','喜欢'],['xijierqi','喜极而泣'],['ohu','哦呼'],
        ['xiangzhi','响指'],['haqian','哈欠'],['ciya','呲牙'],['dai','呆'],
        ['chigua','吃瓜'],['jiayou','加油'],['zaijian','再见'],['aojiao','傲娇'],
        ['touxiao','偷笑'],['baoyou','保佑'],['ok','OK'],['doge','doge'],
    ];

    function getEmojiPicker() {
        var picker = document.getElementById('emoji-picker');
        if (picker) return picker;

        picker = document.createElement('div');
        picker.id = 'emoji-picker';
        picker.className = 'emoji-picker';
        picker.style.display = 'none';

        var grid = document.createElement('div');
        grid.className = 'emoji-grid';

        for (var i = 0; i < EMOJI_LIST.length; i++) {
            (function(code, name) {
                var item = document.createElement('div');
                item.className = 'emoji-item';
                item.title = name;
                item.innerHTML = '<img src="' + (window.BASE_PATH || '') + '/assets/img/emoji/default/' + code + '.png" alt=":' + code + ':" width="28" height="28">';
                item.addEventListener('click', function(e) {
                    e.stopPropagation();
                    insertEmoji(':' + code + ':');
                    picker.style.display = 'none';
                });
                grid.appendChild(item);
            })(EMOJI_LIST[i][0], EMOJI_LIST[i][1]);
        }

        picker.appendChild(grid);
        document.body.appendChild(picker);
        return picker;
    }

    function toggleEmojiPicker(editorId) {
        var picker = getEmojiPicker();
        if (picker.style.display !== 'none') {
            picker.style.display = 'none';
            return;
        }

        var editorData = ACTIVE_EDITORS[editorId];
        if (editorData) {
            // 共用同一工具栏，不再区分 mdToolbar
            var tb = editorData.toolbar;
            var btn = tb && tb.querySelector('.editor-btn[title="表情"]');
            if (btn) {
                var rect = btn.getBoundingClientRect();
                picker.style.left = Math.max(4, Math.min(rect.left, window.innerWidth - 370)) + 'px';
                picker.style.top = (rect.bottom + 4) + 'px';
            }
        }
        picker.style.display = '';
        picker.setAttribute('data-editor', editorId);
    }

    function insertEmoji(code) {
        var picker = document.getElementById('emoji-picker');
        var editorId = picker ? picker.getAttribute('data-editor') : null;
        if (!editorId) return;
        var editorData = ACTIVE_EDITORS[editorId];
        if (!editorData) return;

        var isMdMode = editorData.mdWrap && editorData.mdWrap.style.display !== 'none';

        if (isMdMode) {
            var ta = editorData.mdTextarea;
            var start = ta.selectionStart;
            var val = ta.value;
            ta.value = val.substring(0, start) + code + val.substring(ta.selectionEnd);
            ta.selectionStart = ta.selectionEnd = start + code.length;
            ta.focus();
        } else {
            var imgHtml = '<img src="' + (window.BASE_PATH || '') + '/assets/img/emoji/default/' + code.replace(/:/g,'') + '.png" alt="' + code.replace(/:/g,'') + '" class="emoji-emotion">';
            editorData.editor.focus();
            var sel = window.getSelection();
            if (sel && sel.rangeCount > 0) {
                var range = sel.getRangeAt(0);
                if (!editorData.editor.contains(range.commonAncestorContainer)) {
                    range.selectNodeContents(editorData.editor);
                    range.collapse(false);
                }
                range.deleteContents();
                var temp = document.createElement('div');
                temp.innerHTML = imgHtml;
                var frag = document.createDocumentFragment();
                while (temp.firstChild) {
                    frag.appendChild(temp.firstChild);
                }
                range.insertNode(frag);
                range.collapse(false);
                sel.removeAllRanges();
                sel.addRange(range);
            }
            afterEdit(editorId);
        }
    }

    // 全局点击关闭表情面板
    document.addEventListener('click', function(e) {
        var picker = document.getElementById('emoji-picker');
        if (picker && picker.style.display !== 'none') {
            if (!picker.contains(e.target) && !e.target.closest('.editor-btn[title="表情"]')) {
                picker.style.display = 'none';
            }
        }
        // 关闭颜色面板
        var allPanels = document.querySelectorAll('.editor-color-panel');
        for (var p = 0; p < allPanels.length; p++) {
            if (!allPanels[p].parentElement.contains(e.target)) {
                allPanels[p].style.display = 'none';
            }
        }
    });

    // ===================================================================
    // 15. 自动初始化 & 导出
    // ===================================================================

    function findActiveEditorId() {
        var sel = window.getSelection();
        if (sel && sel.rangeCount > 0) {
            var node = sel.getRangeAt(0).commonAncestorContainer;
            if (node.nodeType === 3) node = node.parentElement;
            for (var id in ACTIVE_EDITORS) {
                if (ACTIVE_EDITORS.hasOwnProperty(id)) {
                    var ed = ACTIVE_EDITORS[id];
                    if (ed.editor && ed.editor.contains(node)) return id;
                }
            }
        }
        // fallback: 返回第一个
        for (var id2 in ACTIVE_EDITORS) {
            if (ACTIVE_EDITORS.hasOwnProperty(id2)) return id2;
        }
        return null;
    }

    function autoInit() {
        var els = document.querySelectorAll("[data-editor]");
        for (var i = 0; i < els.length; i++) {
            var id = els[i].getAttribute("data-editor") || els[i].id || els[i].name || "editor_" + i;
            initEditor(id);
        }
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", autoInit);
    } else {
        autoInit();
    }

    window.initEditor = initEditor;
    window.ACTIVE_EDITORS = ACTIVE_EDITORS;

    /**
     * 帖子引用功能
     */
    window.quotePost = function(el) {
        var card = el.closest('[data-quote-user]');
        if (!card) return;

        var username = card.getAttribute('data-quote-user') || '';
        var content = card.getAttribute('data-quote-content') || '';
        var quoteUid = card.getAttribute('data-quote-uid') || '0';
        // 允许通过 data-editor-target 指定目标编辑器 ID，缺省回退 'content'
        var targetId = card.getAttribute('data-editor-target') || el.getAttribute('data-editor') || 'content';
        if (!content) return;

        // 被引用人 ID 追加到回复表单隐藏字段（逗号分隔去重），供服务端发引用通知
        if (quoteUid && quoteUid !== '0') {
            var quotedForm = document.getElementById('replyForm') || card.closest('form');
            var qHidden = quotedForm ? quotedForm.querySelector('#quoted_uids') : null;
            if (!qHidden) {
                qHidden = document.createElement('input');
                qHidden.type = 'hidden';
                qHidden.name = 'quoted_uids';
                qHidden.id = 'quoted_uids';
                (quotedForm || document.body).appendChild(qHidden);
            }
            var uids = qHidden.value ? qHidden.value.split(',') : [];
            if (uids.indexOf(quoteUid) === -1) {
                uids.push(quoteUid);
                qHidden.value = uids.join(',');
            }
        }

        var safeUser = escapeHtml(username);
        var safeContent = escapeHtml(content);

        var quoteHtml = '<blockquote><cite>' + safeUser + ' 发表于:</cite>' + safeContent + '</blockquote><p><br></p>';

        var editorData = window.ACTIVE_EDITORS && window.ACTIVE_EDITORS[targetId];
        if (!editorData) {
            var ta = document.getElementById(targetId);
            if (ta) {
                var quoteText = '> **' + username + ' 发表于:**\n> ' + content.replace(/\n/g, '\n> ') + '\n\n';
                ta.value += quoteText;
                ta.focus();
            }
            return;
        }

        var isMdMode = editorData.mdWrap && editorData.mdWrap.style.display !== 'none';
        if (isMdMode) {
            var mdText = '> **' + username + ' 发表于:**\n> ' + content.replace(/\n/g, '\n> ') + '\n\n';
            editorData.mdTextarea.value += mdText;
        } else {
            var editor = editorData.editor;
            if (editor) {
                var sel = window.getSelection();
                if (sel.rangeCount > 0 && editor.contains(sel.anchorNode)) {
                    var range = sel.getRangeAt(0);
                    range.deleteContents();
                    var temp = document.createElement('div');
                    temp.innerHTML = quoteHtml;
                    var frag = document.createDocumentFragment();
                    while (temp.firstChild) {
                        frag.appendChild(temp.firstChild);
                    }
                    range.insertNode(frag);
                    range.collapse(false);
                    sel.removeAllRanges();
                    sel.addRange(range);
                } else {
                    editor.innerHTML += quoteHtml;
                }
                afterEdit(targetId);
            }
        }

        var target = editorData.editor || editorData.mdTextarea;
        if (target) target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (editorData.mdTextarea) editorData.mdTextarea.focus();
    };

})();
