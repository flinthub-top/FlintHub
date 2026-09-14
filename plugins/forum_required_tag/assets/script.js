/* ===== 版块强制 Tag 插件前端脚本（零内联：后台 Tab 切换 + 前台提示条/前缀选择器）=====
 * 前台提示条/选择器全部动态创建并插入到对应输入框旁（不依赖 head 占位、不修改核心视图）。
 * 数据来源两种：
 *   ① 服务端直出 #frt-config（编辑页 / 带 category_id 的发帖页，首屏即有）；
 *   ② 无直出时监听 select[name="category_id"] 变化 → fetch /api/forum-required-tag/config 懒加载。
 */
(function () {
    'use strict';

    var BASE = ''; // BASE_PATH（<meta name="frt-base"> 提供，供 fetch 拼 URL）

    /* ---------- 后台管理页：Tab 切换 ---------- */
    function initAdminTabs() {
        var tabs = document.querySelectorAll('.frt-tabs .admin-tab-btn');
        var panes = document.querySelectorAll('.admin-tab-pane');
        if (!tabs.length || !panes.length) return;

        // 支持 ?tab=records 深链
        var wanted = (location.search.match(/[?&]tab=([^&]+)/) || [])[1] || '';

        function activate(tabId) {
            panes.forEach(function (pane) {
                pane.classList.toggle('active', pane.id === tabId);
            });
            tabs.forEach(function (btn) {
                btn.classList.toggle('active', btn.getAttribute('data-tab') === tabId);
            });
        }

        tabs.forEach(function (btn) {
            btn.addEventListener('click', function () {
                activate(btn.getAttribute('data-tab'));
            });
        });

        if (wanted && document.getElementById('tab-' + wanted)) {
            activate('tab-' + wanted);
        }
    }

    /* ---------- 后台管理页：版块下拉跳转 + 删除确认（零内联，数据走 data-*） ---------- */
    function initAdminActions() {
        document.querySelectorAll('select[data-frt-redirect]').forEach(function (sel) {
            sel.addEventListener('change', function () {
                var base = sel.getAttribute('data-frt-redirect') || '';
                if (base) window.location = base + sel.value;
            });
        });
        document.querySelectorAll('.frt-del').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                var msg = btn.getAttribute('data-confirm') || '';
                if (msg && !window.confirm(msg)) e.preventDefault();
            });
        });
    }

    /* ---------- 前台工具 ---------- */
    // 把元素插入到目标输入框之后（同父容器）
    function insertAfterInput(input, el) {
        if (!input || !el) return;
        var parent = input.parentNode;
        if (!parent) return;
        if (input.nextSibling) {
            parent.insertBefore(el, input.nextSibling);
        } else {
            parent.appendChild(el);
        }
    }

    // 移除并返回已创建的提示元素（幂等）
    function removeHints() {
        var hint = document.querySelector('.frt-hint-tags');
        if (hint && hint.parentNode) hint.parentNode.removeChild(hint);
        var picker = document.querySelector('.frt-prefix-picker');
        if (picker && picker.parentNode) picker.parentNode.removeChild(picker);
    }

    /* ---------- 前台：应用配置（动态创建提示条/前缀选择器；cfg 无强制项则清空） ---------- */
    function applyConfig(cfg) {
        removeHints(); // 每次应用先清旧（防切换分类时残留）
        if (!cfg || typeof cfg !== 'object') return;
        var requiredTag = !!cfg.required_tag;
        var requiredPrefix = !!cfg.required_prefix;
        if (!requiredTag && !requiredPrefix) return;

        var i18n = cfg.i18n || {};
        var tags = cfg.tags || [];
        var prefixes = cfg.prefixes || [];

        /* ---- 提示条：Tag 可点击，支持多选，点击填入标签输入框（name="tags"），再点取消 ---- */
        if (requiredTag) {
            var tagInput = document.querySelector('input[name="tags"]');
            if (tagInput) {
                var hint = document.createElement('div');
                hint.className = 'frt-hint frt-hint-tags';
                hint.innerHTML =
                    '<span class="frt-hint-top"><span class="frt-hint-icon"><i class="fa fa-tags"></i></span>' +
                    '<span class="frt-hint-text" data-frt="hint-tags-text"></span></span>' +
                    '<span class="frt-tag-btns" data-frt="hint-tag-btns"></span>';
                hint.querySelector('[data-frt="hint-tags-text"]').textContent =
                    (i18n.hint_tags || '本版块需选择 Tag：');
                var btnsBox = hint.querySelector('[data-frt="hint-tag-btns"]');
                // 编辑页预填：以逗号分隔解析已有 Tag（支持多个）
                var currentList = (tagInput.value || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
                tags.forEach(function (t) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'frt-tag-btn';
                    btn.textContent = '[' + t + ']';
                    // 编辑页预填已有 Tag 时高亮
                    if (currentList.indexOf(t) !== -1) btn.classList.add('active');
                    btn.addEventListener('click', function () {
                        var curList = (tagInput.value || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
                        var idx = curList.indexOf(t);
                        if (idx !== -1) {
                            // 已选：从列表移除（取消选择）
                            curList.splice(idx, 1);
                            btn.classList.remove('active');
                        } else {
                            // 未选：追加到列表（逗号分隔，支持多选）
                            curList.push(t);
                            btn.classList.add('active');
                        }
                        tagInput.value = curList.join(', ');
                    });
                    btnsBox.appendChild(btn);
                });
                insertAfterInput(tagInput, hint);
            }
        }

        /* ---- 前缀选择器：插入到标题输入框（name="title"）下方，点选后拼入标题 ---- */
        if (requiredPrefix && prefixes.length) {
            var titleInput = document.querySelector('input[name="title"]');
            if (titleInput) {
                var picker = document.createElement('div');
                picker.className = 'frt-prefix-picker';
                picker.innerHTML =
                    '<span class="frt-prefix-label" data-frt="prefix-label"></span>' +
                    '<span class="frt-prefix-btns" data-frt="prefix-btns"></span>';
                picker.querySelector('[data-frt="prefix-label"]').textContent =
                    i18n.hint_prefix || '标题前缀：';
                var btnsBox = picker.querySelector('[data-frt="prefix-btns"]');
                prefixes.forEach(function (p) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'frt-prefix-btn';
                    btn.textContent = '[' + p + ']';
                    btn.addEventListener('click', function () {
                        // 已选：移除前缀；未选：拼入标题头部
                        var bracket = '[' + p + ']';
                        var cur = titleInput.value.trim();
                        if (cur.indexOf(bracket) === 0) {
                            titleInput.value = cur.substring(bracket.length).replace(/^\s+/, '');
                            btn.classList.remove('active');
                        } else {
                            // 清除已存在的其他前缀（防止多个前缀叠加）
                            var cleaned = cur.replace(/^\[[^\]]*\]\s*/, '');
                            titleInput.value = bracket + ' ' + cleaned;
                            btnsBox.querySelectorAll('.frt-prefix-btn').forEach(function (b) {
                                b.classList.remove('active');
                            });
                            btn.classList.add('active');
                        }
                    });
                    btnsBox.appendChild(btn);
                });
                insertAfterInput(titleInput, picker);
            }
        }
    }

    /* ---------- 前台：从服务端直出的 #frt-config 数据容器解析并应用 ---------- */
    function applyFromContainer() {
        var cfgEl = document.getElementById('frt-config');
        if (!cfgEl) return false;
        var cfg;
        try {
            cfg = JSON.parse(cfgEl.getAttribute('data-config') || '{}');
        } catch (e) {
            return false;
        }
        applyConfig(cfg);
        return true;
    }

    /* ---------- 前台：懒加载 —— 未预选分类进入发帖页时，监听分类下拉变化后拉取配置 ---------- */
    function fetchConfig(forumId, cb) {
        var url = BASE + '/api/forum-required-tag/config?forum_id=' + encodeURIComponent(forumId);
        fetch(url)
            .then(function (r) {
                if (!r.ok) throw new Error('bad status ' + r.status);
                return r.json();
            })
            .then(function (data) { cb(data); })
            .catch(function () { cb(null); });
    }

    function initFrontHint() {
        // BASE_PATH：<meta name="frt-base">（layout_head_end 输出，head 内合法）
        var meta = document.querySelector('meta[name="frt-base"]');
        if (!meta) return; // 非发帖/编辑页，或插件资源未加载
        BASE = meta.getAttribute('content') || '';

        // 服务端直出优先（编辑页 / 带 category_id 的发帖页）：首屏即有提示
        applyFromContainer();

        // 无论是否有直出，都绑定分类下拉 change：
        //   - 未预选分类时 → 选中后懒加载该版块配置；
        //   - 已直出时 → 切换分类同样刷新为新版块配置（服务端校验按 $_POST['category_id'] 一致）
        var catSelect = document.querySelector('select[name="category_id"]');
        if (!catSelect) return;
        catSelect.addEventListener('change', function () {
            var fid = parseInt(catSelect.value, 10);
            if (!fid || fid <= 0) { removeHints(); return; }
            fetchConfig(fid, applyConfig);
        });
    }

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(function () {
        initAdminTabs();
        initAdminActions();
        initFrontHint();
    });
})();
