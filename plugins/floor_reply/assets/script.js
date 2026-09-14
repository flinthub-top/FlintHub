/* ===== 楼中楼插件脚本（零内联）=====
 * 职责：
 * 1) 把 post_operation_after 输出的回复按钮（.fr-reply-btn）移动到楼层投票操作栏
 *    「引用」链接（[data-action="quote"]）之后 —— 图标+回复数+箭头内联，不单独一行；
 *    点击按钮 = 展开/收缩楼中楼（首次展开经 htmx 拉列表，再次点击收缩）；
 * 2) 把锚点挂载点（.fr-anchor，带 id）移动到楼层容器末尾 = 楼层内容下方；
 * 3) 自动展开：楼层已有楼中楼（data-fr-count>0）时自动加载，按钮再点即收缩。
 * 展开/分页/回复/删除全部由 htmx 声明式接管，提交不整页跳转。
 */
(function () {
    'use strict';

    function init() {
        // pid → {loaded, opened}，每楼独立记录加载/展开态
        var stateMap = {};
        document.querySelectorAll('.fr-anchor').forEach(function (anchor) {
            // 楼层结构：<div class="mn-border-bottom"> 外层 = 楼层容器，内含
            //   <div class="thread-post-user-bar"> 用户栏（回复按钮与锚点初始在此）</div>
            //   <div class="mn-p-20"> 内容/投票栏 </div>
            // 注意：用户栏自身也带 mn-border-bottom 类，不能用 closest('.mn-border-bottom')（会命中用户栏）
            var bar = anchor.closest('.thread-post-user-bar');
            var floor = bar ? bar.parentElement : null;
            if (!floor) return;

            var pid = anchor.getAttribute('data-fr-post');
            var state = stateMap[pid] = { loaded: false, opened: false };
            var btn = bar.querySelector('.fr-reply-btn');

            // 1) 回复按钮 → 投票操作栏「引用」链接之后
            if (btn) {
                var voteActions = floor.querySelector('.thread-vote-actions');
                var quote = voteActions ? voteActions.querySelector('[data-action="quote"]') : null;
                if (quote && quote.parentNode) {
                    quote.insertAdjacentElement('afterend', btn);
                } else if (voteActions) {
                    voteActions.appendChild(btn);
                }
                bindToggle(btn, pid, state);
            }

            // 2) 锚点挂载点 → 楼层末尾（楼层内容下方，列表展开于此）
            if (anchor.parentNode !== floor) {
                floor.appendChild(anchor);
            }

            // 3) 自动展开：楼层已有楼中楼（data-fr-count>0）时自动加载
            var count = parseInt(anchor.getAttribute('data-fr-count') || '0', 10);
            if (count > 0 && window.htmx && window.htmx.ajax) {
                loadList(anchor, pid, state, btn);
            }
        });
    }

    /** 点击按钮：已加载则切展开/收缩；未加载则首次 htmx 拉列表 */
    function bindToggle(btn, pid, state) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var box = document.getElementById('fr-anchor-' + pid);
            if (!box) return;
            if (state.loaded) {
                box.classList.toggle('fr-collapsed');
                state.opened = !box.classList.contains('fr-collapsed');
                btn.classList.toggle('fr-open', state.opened);
            } else if (window.htmx && window.htmx.ajax) {
                loadList(box, pid, state, btn);
            }
        });
    }

    /** 经 htmx.ajax 拉列表片段到锚点；成功后标记已加载 + 展开态。
     *  必须把该楼楼层的按钮作为 source 传入：否则 htmx 会把请求宿主降级到 <body>，
     *  同帧多个楼层自动展开时，后发请求会覆盖并 abort 先发请求（同元素+同事件去重），
     *  导致本帧只有最后一楼的列表能加载出来。source 每楼独立，请求互不干扰。 */
    function loadList(anchor, pid, state, btn) {
        var url = (window.BASE_PATH || '') + '/floor-reply/list/' + pid;
        var p = window.htmx.ajax('GET', url, {
            source: btn || null,
            target: anchor,
            swap: 'outerHTML'
        });
        if (p && typeof p.then === 'function') {
            p.then(function () {
                state.loaded = true;
                state.opened = true;
                if (btn) btn.classList.add('fr-open');
            }).catch(function () {
                state.loaded = true;
            });
        } else {
            // 兜底：无 promise 时视为已发起，交给后续交互
            state.opened = true;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // ===== 计数同步 =====
    // 回复成功 → 对应按钮 (N) +1；删除成功 → -1。仅当响应片段无 .fr-error（校验/权限失败）时更新。
    // 依赖片段根元素 id=fr-anchor-{pid} 取 pid；列表分页/自动展开为 GET，不在此列。
    document.addEventListener('htmx:afterSwap', function (e) {
        var detail = e.detail;
        var cfg = detail && detail.requestConfig;
        if (!cfg) return;
        var box = detail.target;
        var id = box && box.id ? box.id : '';
        if (id.indexOf('fr-anchor-') !== 0) return; // 非楼中楼片段
        var pid = id.slice('fr-anchor-'.length);
        if (!pid || box.querySelector('.fr-error')) return; // 出错不改计数

        var path = cfg.path || '';
        var delta = 0;
        if (cfg.verb === 'post' && /\/floor-reply\/reply$/.test(path)) {
            delta = 1;
        } else if (cfg.verb === 'post' && /\/floor-reply\/delete$/.test(path)) {
            delta = -1;
        }
        if (delta === 0) return;

        var btn = document.querySelector('.fr-reply-btn[data-fr-pid="' + pid + '"]');
        if (!btn) return;
        var span = btn.querySelector('.fr-reply-count');
        if (!span) return;
        var cur = parseInt((span.textContent || '').replace(/[()]/g, ''), 10) || 0;
        span.textContent = '(' + Math.max(0, cur + delta) + ')';
    });
})();