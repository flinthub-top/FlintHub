/**
 * FlintHub — 本地草稿保护系统（插件 draft_saver）
 * 版本：V3.3（2026-09-01，插件化修正版）
 * 架构：纯前端、零后端、零数据库依赖，只使用浏览器 localStorage
 *
 * 与 V3.2 方案的关键修正：
 * 1. 富文本读写：content 字段由 editor.js 接管（原 textarea 被隐藏、name 被移除），
 *    必须读 window.ACTIVE_EDITORS[field].editor.innerHTML，写 el.value 恒为空；
 * 2. 提交清理：本项目表单为普通 POST 整页提交（无 AJAX 回调），
 *    改为 submit 打标 + pagehide 清键：提交跳转才清，校验失败不跳转则草稿保留；
 * 3. 恢复逻辑：完整支持 富文本 / radio / checkbox 组 / select 多选 / 普通 input；
 * 4. 表单定位：post/create、blog/edit 的表单无 id，按 URL 自举直选 document.querySelector('form')；
 * 5. 有效性判断：minChars 只统计非富文本字段的纯文本长度，避免富文本隐藏框空值误删；
 * 6. defer 加载：保证 editor.js 的 DOMContentLoaded autoInit 先于本脚本执行。
 *
 * 核心原则（沿用方案）：数据主权、四重隔离键、防抖+内存副本、用户可控、提交成功才清理、异常降级。
 */
(function () {
    'use strict';

    // ========== 全局配置 ==========
    var DRAFT_CONFIG = {
        debounceMs: 800,          // 防抖间隔
        expireDays: 7,            // 草稿过期天数
        minChars: 5,              // 无效草稿最小纯文本长度
        prefix: 'flinthub_draft_' // 键名前缀（容量清理/卸载识别用）
    };

    // ========== 公共工具函数 ==========

    /** 去除HTML标签、空白字符，用于判断无效草稿 */
    function stripHtml(str) {
        if (!str) return '';
        return String(str).replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
    }

    /** 预检测是否处于隐私模式（无痕/隐身） */
    function isPrivateMode() {
        try {
            var key = 'test_private_mode';
            localStorage.setItem(key, key);
            localStorage.removeItem(key);
            return false;
        } catch (e) {
            return true;
        }
    }

    /** 判断草稿是否过期 */
    function isExpired(timestamp) {
        return Date.now() - timestamp > DRAFT_CONFIG.expireDays * 24 * 60 * 60 * 1000;
    }

    /** 剥离 BASE_PATH 后的 pathname（二级目录部署下键与实体提取一致） */
    function cleanPath() {
        var p = window.location.pathname || '/';
        var base = window.BASE_PATH || '';
        if (base && p.indexOf(base) === 0) {
            p = p.slice(base.length);
        }
        return p;
    }

    /** pathname 分段（如 /post/new → ['post','new']） */
    function pathSegments() {
        return cleanPath().replace(/\/+$/, '').split('/').filter(Boolean);
    }

    // ========== 键名构建（四重隔离：用户 + 表单 + 实体 + 路径） ==========
    function buildDraftKey(userId, formId, entityId) {
        return DRAFT_CONFIG.prefix + userId + '_' + formId + '_' + entityId + '_' + cleanPath();
    }

    // ========== 字段收集（兼容富文本 / Radio / 多选 Checkbox / Select 多选） ==========
    function collectFields(form, fields) {
        var data = {};

        for (var i = 0; i < fields.length; i++) {
            var field = fields[i];

            // 支持回调函数，兼容外部注入字段
            if (typeof field === 'function') {
                var extra = field();
                if (extra) {
                    for (var k in extra) {
                        if (Object.prototype.hasOwnProperty.call(extra, k)) data[k] = extra[k];
                    }
                }
                continue;
            }

            // 富文本字段：编辑器已接管该 textarea（原 textarea 隐藏且 name 被移除）
            if (window.ACTIVE_EDITORS && window.ACTIVE_EDITORS[field]) {
                var ed = window.ACTIVE_EDITORS[field];
                // Markdown 模式：存 MD 源码（切可视化时编辑器自会同步）
                if (ed.mdWrap && ed.mdWrap.style.display !== 'none' && ed.mdTextarea) {
                    data[field] = ed.mdTextarea.value;
                } else {
                    data[field] = ed.editor.innerHTML;
                }
                continue;
            }

            // 单选框：取 checked 的那一个
            var radio = form.querySelector('input[name="' + field + '"]:checked');
            if (radio && radio.type === 'radio') {
                data[field] = radio.value;
                continue;
            }

            var els = form.querySelectorAll('[name="' + field + '"]');
            if (!els.length) continue;

            // 多选 Checkbox 组
            var checkboxGroup = [];
            for (var j = 0; j < els.length; j++) {
                if (els[j].type === 'checkbox') checkboxGroup.push(els[j]);
            }
            if (checkboxGroup.length > 1) {
                var picked = [];
                for (var c = 0; c < checkboxGroup.length; c++) {
                    if (checkboxGroup[c].checked) picked.push(checkboxGroup[c].value);
                }
                data[field] = picked.join(',');
                continue;
            }

            var el = els[0];
            if (el.type === 'checkbox') {
                data[field] = el.checked ? '1' : '';
            } else if (el.multiple) {
                var sel = [];
                for (var s = 0; s < el.selectedOptions.length; s++) {
                    sel.push(el.selectedOptions[s].value);
                }
                data[field] = sel.join(',');
            } else {
                data[field] = el.value;
            }
        }

        return data;
    }

    // ========== 写盘逻辑（容量超限自动降级） ==========
    function saveDraft(userId, formId, entityId, data) {
        var key = buildDraftKey(userId, formId, entityId);
        var payload = {
            timestamp: Date.now(),
            user_id: userId,
            entity_id: entityId,
            fields: data
        };

        try {
            localStorage.setItem(key, JSON.stringify(payload));
        } catch (e) {
            if (e && (e.name === 'QuotaExceededError' || e.code === 22)) {
                // 先清理所有过期草稿，腾出空间后重试一次
                cleanExpiredDrafts();
                try {
                    localStorage.setItem(key, JSON.stringify(payload));
                } catch (err2) {
                    showDraftNotice('本地存储已满，无法保存草稿，请手动清理。');
                }
            } else {
                // 隐私模式或浏览器受限
                showDraftNotice('当前为隐私模式或浏览器受限，草稿仅在本次会话有效，无法持久保存。');
            }
        }
    }

    /** 清理所有已过期的草稿键 */
    function cleanExpiredDrafts() {
        var keys = [];
        for (var i = 0; i < localStorage.length; i++) {
            var key = localStorage.key(i);
            if (key && key.indexOf(DRAFT_CONFIG.prefix) === 0) keys.push(key);
        }
        for (var j = 0; j < keys.length; j++) {
            try {
                var raw = localStorage.getItem(keys[j]);
                var data = JSON.parse(raw);
                if (isExpired(data.timestamp)) localStorage.removeItem(keys[j]);
            } catch (e) {
                // 忽略解析失败的脏数据
            }
        }
    }

    // ========== 表单绑定（防抖 + 内存副本兜底 + submit 打标） ==========
    function bindAutoSave(form, formId, userId, entityId, fields) {
        if (!form || !userId) return;

        var draftTimer = null;
        var latestDraftData = null;
        var submitted = false;

        // 立即收集到内存副本（防抖窗口内刷新/关闭时 beforeunload 兜底不丢最新输入）
        var collectMemory = function () {
            latestDraftData = collectFields(form, fields);
        };

        var collectAndSave = function () {
            saveDraft(userId, formId, entityId, latestDraftData);
        };

        var debounceSave = function () {
            collectMemory(); // 先同步内存副本，防抖只负责延迟写盘
            clearTimeout(draftTimer);
            draftTimer = setTimeout(collectAndSave, DRAFT_CONFIG.debounceMs);
        };

        form.addEventListener('input', debounceSave);
        form.addEventListener('change', debounceSave);

        // ---- 触发兜底：保证“只写内容/纯粘贴/工具栏操作”也能启动防抖 ----
        // 内容字段是富文本编辑器，保存触发不能只依赖 input 冒泡：
        //  - 可视化模式打字依赖 contenteditable 的 input，中文 IME 组合期/部分浏览器内核不发或延迟发；
        //  - 工具栏命令、粘贴（被 preventDefault 后手动插入）、撤销/重做、模式切换都是直接改
        //    innerHTML，根本不会产生任何 input 事件；
        //  - 标题是原生 <input>，input 事件必然触发，因此用户体感“写上标题才触发”。
        // 这里并入 keyup / compositionend / click 三类必然事件（共用同一防抖，不额外写盘），
        // 再对编辑器本体直接绑定（不依赖冒泡），保证任意内容编辑路径都会启动一次保存计时。
        form.addEventListener('keyup', debounceSave);
        form.addEventListener('compositionend', debounceSave);
        form.addEventListener('click', debounceSave);

        // 编辑器本体直绑：ACTIVE_EDITORS 在 DOMContentLoaded（editor.js autoInit）后才就绪，
        // 而本脚本为 defer、先于其执行，故“现在 + DOMContentLoaded + 首次进入表单”各尝试一次；
        // 用 container 的 dataset 标记防重复绑定，focusin 兜底编辑器懒初始化的场景。
        var bindEditors = function () {
            if (!window.ACTIVE_EDITORS) return;
            for (var bi = 0; bi < fields.length; bi++) {
                var fname = fields[bi];
                var ed = window.ACTIVE_EDITORS[fname];
                if (!ed || !ed.container) continue;
                if (ed.container.getAttribute('data-draft-bound')) continue;
                ed.container.setAttribute('data-draft-bound', '1');
                var tgts = [];
                if (ed.editor) tgts.push(ed.editor);
                if (ed.mdTextarea) tgts.push(ed.mdTextarea);
                for (var tj = 0; tj < tgts.length; tj++) {
                    tgts[tj].addEventListener('input', debounceSave);
                    tgts[tj].addEventListener('keyup', debounceSave);
                    tgts[tj].addEventListener('compositionend', debounceSave);
                }
            }
        };
        bindEditors();
        document.addEventListener('DOMContentLoaded', bindEditors);
        form.addEventListener('focusin', bindEditors);

        // 提交打标：submitted 仅用于 beforeunload 判断（提交后刷新不再回写草稿）；
        // sessionStorage 提交标记供"成功详情页确认"（cleanDraftOnSuccess）判断本次跳转是否
        // 源于表单提交（防纯浏览详情页误删草稿）；422 拦截等失败时 URL 不变，不会执行清理
        form.addEventListener('submit', function () {
            submitted = true;
            try {
                sessionStorage.setItem(DRAFT_CONFIG.prefix + 'submitted_' + userId + '_' + formId, String(Date.now()));
            } catch (e) { /* 隐私模式/禁用存储时忽略：无标记则详情页不清理，草稿保留 */ }
        });

        // 关闭页面前，用内存副本快速落盘（不重新爬 DOM）；已提交则不回写
        window.addEventListener('beforeunload', function () {
            if (latestDraftData && !submitted) {
                saveDraft(userId, formId, entityId, latestDraftData);
            }
        });
    }

    // ========== 加载检测（越权 + 过期 + 有效性校验） ==========
    function checkDraft(form, formId, userId, entityId, fields) {
        if (!form || !userId) return;

        var key = buildDraftKey(userId, formId, entityId);
        var raw = localStorage.getItem(key);
        if (!raw) return;

        var data;
        try {
            data = JSON.parse(raw);
        } catch (e) {
            localStorage.removeItem(key);
            return;
        }
        if (!data || typeof data !== 'object') return;

        // 安全：不读其他用户草稿
        if (data.user_id !== userId) return;

        // 有效性校验：标题非空即视为有效草稿（只填标题也可恢复）；
        // 无标题时纯文本长度 < minChars 视为无效草稿，不打扰
        var dataFields = data.fields || {};
        var hasTitle = !!String(dataFields.title || '').trim();
        var plainLen = 0;
        for (var f in dataFields) {
            if (!Object.prototype.hasOwnProperty.call(dataFields, f)) continue;
            var v = dataFields[f];
            if (v === undefined || v === null || v === '') continue;
            plainLen += stripHtml(String(v)).length;
        }
        if (!hasTitle && plainLen < DRAFT_CONFIG.minChars) {
            localStorage.removeItem(key);
            return;
        }

        // 过期处理：不静默删除，给用户选择
        if (isExpired(data.timestamp)) {
            showExpiredNotice(key);
            return;
        }

        showDraftRestoreBar(form, data, key);
    }

    // ========== 恢复字段（富文本 / radio / checkbox 组 / select 多选 / 普通） ==========
    function restoreFields(form, data) {
        var dataFields = data.fields || {};
        for (var fieldName in dataFields) {
            if (!Object.prototype.hasOwnProperty.call(dataFields, fieldName)) continue;
            var val = dataFields[fieldName];
            if (val === undefined || val === null) continue;

            // 富文本：写入编辑器并触发 input（刷新字数统计/历史）
            if (window.ACTIVE_EDITORS && window.ACTIVE_EDITORS[fieldName]) {
                var ed = window.ACTIVE_EDITORS[fieldName];
                ed.editor.innerHTML = sanitizeDraftHtml(String(val));
                try {
                    ed.editor.dispatchEvent(new Event('input', { bubbles: true }));
                } catch (e2) { /* 忽略 */ }
                continue;
            }

            var els = form.querySelectorAll('[name="' + fieldName + '"]');
            if (!els.length) continue;

            // checkbox 组：按逗号串回勾
            var checkboxGroup = [];
            for (var j = 0; j < els.length; j++) {
                if (els[j].type === 'checkbox') checkboxGroup.push(els[j]);
            }
            if (checkboxGroup.length > 1) {
                var selected = String(val).split(',');
                for (var c = 0; c < checkboxGroup.length; c++) {
                    checkboxGroup[c].checked = selected.indexOf(String(checkboxGroup[c].value)) >= 0;
                }
                continue;
            }

            var el = els[0];
            if (el.type === 'checkbox') {
                el.checked = (String(val) === '1' || val === true);
            } else if (el.type === 'radio') {
                var radio = form.querySelector('input[name="' + fieldName + '"][value="' + val + '"]');
                if (radio) radio.checked = true;
            } else {
                el.value = val;
            }
        }
    }

    /** 轻量净化恢复的富文本草稿（自写自读风险低，但拦截 script/事件属性/危险 URL） */
    function sanitizeDraftHtml(html) {
        if (!html) return '';
        var doc = document.implementation.createHTMLDocument('');
        var div = doc.createElement('div');
        div.innerHTML = String(html);
        var bad = div.querySelectorAll('script, iframe, object, embed, link, meta, style, form');
        for (var b = 0; b < bad.length; b++) {
            if (bad[b].parentNode) bad[b].parentNode.removeChild(bad[b]);
        }
        var all = div.querySelectorAll('*');
        for (var a = 0; a < all.length; a++) {
            var attrs = all[a].attributes;
            for (var x = attrs.length - 1; x >= 0; x--) {
                var name = attrs[x].name.toLowerCase();
                if (name.indexOf('on') === 0) {
                    all[a].removeAttribute(attrs[x].name);
                } else if (name === 'href' || name === 'src') {
                    var v = (attrs[x].value || '').trim().toLowerCase();
                    if (v.indexOf('javascript:') === 0 || v.indexOf('data:') === 0) {
                        all[a].removeAttribute(attrs[x].name);
                    }
                }
            }
        }
        return div.innerHTML;
    }

    // ========== UI 交互（提示条，样式收敛于插件 assets/draft.css） ==========

    /** 防重复弹窗：同页仅一个提示条 */
    function hasBar() {
        return !!document.getElementById('draft-restore-bar');
    }

    /** 展示恢复提示条 */
    function showDraftRestoreBar(form, data, key) {
        if (hasBar()) return;

        var bar = document.createElement('div');
        bar.id = 'draft-restore-bar';
        bar.className = 'draft-restore-bar';
        bar.innerHTML =
            '<span class="draft-restore-text">检测到上次未提交的内容，是否恢复？</span>' +
            '<button type="button" class="mn-btn mn-btn-sm mn-btn-primary" id="draft-restore-btn">恢复</button>' +
            '<button type="button" class="mn-btn mn-btn-sm" id="draft-dismiss-btn">忽略</button>';
        document.body.appendChild(bar);

        document.getElementById('draft-restore-btn').addEventListener('click', function () {
            restoreFields(form, data);
            bar.remove();
        });
        document.getElementById('draft-dismiss-btn').addEventListener('click', function () {
            // 忽略 = 明确放弃这份草稿：删除草稿键，避免刷新后反复弹出
            localStorage.removeItem(key);
            bar.remove();
        });
    }

    /** 展示草稿过期提示 */
    function showExpiredNotice(key) {
        if (hasBar()) return;

        var bar = document.createElement('div');
        bar.id = 'draft-restore-bar';
        bar.className = 'draft-restore-bar';
        bar.innerHTML =
            '<span class="draft-restore-text">草稿已过期，是否删除？</span>' +
            '<button type="button" class="mn-btn mn-btn-sm mn-btn-primary" id="draft-delete-btn">删除</button>' +
            '<button type="button" class="mn-btn mn-btn-sm" id="draft-keep-btn">保留</button>';
        document.body.appendChild(bar);

        document.getElementById('draft-delete-btn').addEventListener('click', function () {
            localStorage.removeItem(key);
            bar.remove();
        });
        document.getElementById('draft-keep-btn').addEventListener('click', function () {
            bar.remove();
        });
    }

    /** 展示草稿通知（存储已满 / 隐私模式），5 秒自动消失 */
    function showDraftNotice(msg) {
        if (hasBar()) return;

        var bar = document.createElement('div');
        bar.id = 'draft-restore-bar';
        bar.className = 'draft-restore-bar draft-restore-notice';
        bar.innerHTML = '<span class="draft-restore-text">' + msg + '</span>';
        document.body.appendChild(bar);
        setTimeout(function () {
            bar.remove();
        }, 5000);
    }

    // ========== 提交成功后清理（外部手动调用，兼容原方案 API） ==========
    function handleSubmitSuccess(userId, formId, entityId) {
        localStorage.removeItem(buildDraftKey(userId, formId, entityId));
    }

    /**
     * 定位页面主表单。
     * 不能盲选 document.querySelector('form')：布局导航（body 前部）含 logout 等表单，
     * 会被误选导致草稿绑定到错误表单。发帖/博客表单必有 [name="title"] 字段，据此定位。
     */
    function findMainForm() {
        var forms = document.querySelectorAll('form');
        for (var i = 0; i < forms.length; i++) {
            if (forms[i].querySelector('[name="title"]')) {
                return forms[i];
            }
        }
        return null;
    }

    // ========== 页面自举绑定（按 URL 识别 4 类表单页） ==========
    function autoBind() {
        var userId = window.FlintDraftUserId || 0;
        if (!userId) return; // 未登录不绑定（回复表单未登录时也不存在）

        var segs = pathSegments();
        var form = null;
        var formId = '';
        var entityId = '';
        var fields = [];

        // 新建主题帖 /post/new（表单无 id，按 [name="title"] 特征定位）
        if (segs[0] === 'post' && segs[1] === 'new') {
            form = findMainForm();
            formId = 'post_new';
            entityId = 'new';
            fields = ['title', 'tags', 'category_id', 'reply_to_view', 'content'];
        }
        // 发表回复 /thread/{id}（详情页内嵌 #replyForm，仅登录且有权限时存在）
        else if (segs[0] === 'thread' && segs[1] && !segs[2]) {
            form = document.getElementById('replyForm');
            formId = 'post_reply';
            entityId = 'thread_' + segs[1];
            fields = ['content'];
        }
        // 博客新建 /blog/new 与编辑 /blog/{id}/edit（共用 blog/edit.php 视图，表单无 id）
        else if (segs[0] === 'blog') {
            if (segs[1] === 'new' && !segs[2]) {
                form = findMainForm();
                formId = 'blog_new';
                entityId = 'new';
                fields = ['title', 'category_id', 'content'];
            } else if (segs[1] && segs[2] === 'edit') {
                form = findMainForm();
                formId = 'blog_edit';
                entityId = 'blog_' + segs[1];
                fields = ['title', 'category_id', 'content'];
            }
        }

        if (!form) return;

        bindAutoSave(form, formId, userId, entityId, fields);
        checkDraft(form, formId, userId, entityId, fields);
    }

    // ========== 方案 B：成功详情页确认后清理（替代原 pagehide 盲删） ==========
    // 发帖/博客/回帖均为整页 POST：422/500 等失败响应会导航到错误页，旧方案在 pagehide
    // 里仅凭 submitted 打标就删除草稿，导致非成功提交时草稿被误删。
    // 现改为：仅在"成功目标页"（帖子详情 /thread/{id}、博客详情 /blog/{id}）上按 URL 推断
    // 表单类型，且本次导航确系表单提交（sessionStorage 提交标记，短 TTL）才前缀扫描删除
    // 对应草稿；未跳转详情页（被拦/失败）、标记缺失/过期（纯浏览详情页）则草稿保留可恢复。
    var SUBMIT_TTL_MS = 30 * 1000; // 提交标记有效期：正常提交跳转瞬时完成，30s 足够

    function removeSubmitMark(markKey) {
        try { sessionStorage.removeItem(markKey); } catch (e) { /* 隐私模式/禁用存储时忽略 */ }
    }

    // 失败跳转（回帖被限流/权限拦截等走 redirect ?error= 或 ?msg=archived_*）时作废全部提交标记（草稿保留）
    function removeSubmitMarks(userId) {
        var keys = [];
        try {
            for (var i = 0; i < sessionStorage.length; i++) {
                var key = sessionStorage.key(i);
                if (key && key.indexOf(DRAFT_CONFIG.prefix + 'submitted_' + userId + '_') === 0) keys.push(key);
            }
        } catch (e) { /* 忽略 */ }
        for (var j = 0; j < keys.length; j++) removeSubmitMark(keys[j]);
    }

    function cleanDraftOnSuccess() {
        var userId = window.FlintDraftUserId || 0;
        if (!userId) return;

        // 失败跳转不清理，并作废本次提交标记（草稿保留，下次提交重新打标）
        var qs = (window.location.search || '').replace(/^\?/, '');
        if (/(^|&)error=/i.test(qs) || /(^|&)msg=archived/i.test(qs)) {
            removeSubmitMarks(userId);
            return;
        }

        var segs = pathSegments();
        var formIds = [];
        if (segs[0] === 'thread' && segs[1] && !segs[2]) {
            // 帖子详情页：发新帖 / 回帖 / 编辑帖成功均跳转至此
            formIds = ['post_new', 'post_reply', 'post_edit'];
        } else if (segs[0] === 'blog' && segs[1] && !segs[2]) {
            // 博客详情页：博客新建 / 编辑成功均跳转至此
            formIds = ['blog_new', 'blog_edit'];
        }
        if (!formIds.length) return;

        // 仅清理"确有提交标记且在有效期"的表单草稿（防纯浏览详情页误删他人场景下的残留草稿）
        var now = Date.now();
        for (var fi = 0; fi < formIds.length; fi++) {
            var markKey = DRAFT_CONFIG.prefix + 'submitted_' + userId + '_' + formIds[fi];
            var ts = 0;
            try { ts = parseInt(sessionStorage.getItem(markKey) || '0', 10) || 0; } catch (e) { ts = 0; }
            if (ts <= 0 || now - ts > SUBMIT_TTL_MS) continue; // 无标记 / 已过期：保留草稿
            removeSubmitMark(markKey);

            // 草稿键含路径组件（buildDraftKey 末段 = 表单页路径），详情页路径与之不同，
            // 不能直接 buildDraftKey()；按 前缀+用户+表单 前缀扫描，兼容任意实体/路径。
            var head = DRAFT_CONFIG.prefix + userId + '_' + formIds[fi] + '_';
            var keys = [];
            for (var i = 0; i < localStorage.length; i++) {
                var key = localStorage.key(i);
                if (key && key.indexOf(head) === 0) keys.push(key);
            }
            for (var k = 0; k < keys.length; k++) localStorage.removeItem(keys[k]);
        }
    }

    // ========== 对外暴露的接口 ==========
    window.FlintDraft = {
        bindAutoSave: bindAutoSave,
        checkDraft: checkDraft,
        restoreFields: restoreFields,
        handleSubmitSuccess: handleSubmitSuccess,
        cleanExpiredDrafts: cleanExpiredDrafts,
        isPrivateMode: isPrivateMode,
        buildDraftKey: buildDraftKey
    };

    // defer 加载：脚本在 DOM 解析后、DOMContentLoaded 前执行；
    // 此时 editor.js（head 同步脚本）已注册 DOMContentLoaded 监听，先于本脚本触发，ACTIVE_EDITORS 就绪。
    // 自举顺序：先按"成功详情页"清理草稿（thread/show 详情页也有回复表单，清理须先于表单绑定执行），
    // 再执行表单自动绑定（详情页无对应表单时 autoBind 自然跳过）。
    function boot() {
        cleanDraftOnSuccess();
        autoBind();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
