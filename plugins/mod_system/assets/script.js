/* ===== 社区治理插件（mod_system）前端脚本 ===== */
(function () {
    "use strict";

    /* ---------- 前台举报弹窗（Alpine 组件，经 alpine:init 注册一次） ---------- */
    document.addEventListener('alpine:init', () => {
        if (window.__modReportFormDefined) return;
        window.__modReportFormDefined = true;
        Alpine.data('modReportForm', (cfg) => ({
            type: cfg.type, id: cfg.id, uid: cfg.uid,
            busy: false, msg: '',
            async submit() {
                this.msg = ''; this.busy = true;
                const form = document.getElementById('modReportForm_' + this.type + '_' + this.id);
                if (!form) { this.busy = false; return; }
                const overlay = form.closest('.mn-report-overlay');
                const endpoint = overlay ? (overlay.dataset.endpoint || '/mod-system/report') : '/mod-system/report';
                const fd = new FormData(form);
                try {
                    const resp = await fetch(endpoint, { method: 'POST', body: fd, headers: { 'HX-Request': 'true' } });
                    const text = await resp.text();
                    this.msg = text; this.busy = false;
                } catch (e) { this.busy = false; this.msg = 'error'; }
            }
        }));
    });

    /* ---------- 后台「举报中心」：批量处理 + 单条封禁 ---------- */
    function hiddenForm(name, value) {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = name; i.value = value;
        return i;
    }

    function postForm(url, fields) {
        const form = document.createElement('form');
        form.method = 'POST'; form.action = url;
        for (const k in fields) form.appendChild(hiddenForm(k, fields[k]));
        document.body.appendChild(form); form.submit();
    }

    function updateBatchBtn() {
        const boxes = document.querySelectorAll('.report-checkbox:checked');
        const btn = document.getElementById('batch-handle-btn');
        if (btn) btn.disabled = boxes.length === 0;
    }

    function initAdminReports() {
        const root = document.getElementById('modAdminReports');
        if (!root || root.dataset.inited) return;
        root.dataset.inited = '1';

        const csrf = root.dataset.csrf || '';
        const needSelect = root.dataset.needSelect || '';
        const confirmMsg = root.dataset.confirm || '';
        const batchUrl = root.dataset.batchUrl || '';
        const handlePrefix = root.dataset.handlePrefix || '';

        // 勾选框（含「全选」）变更联动
        document.addEventListener('change', function (e) {
            const t = e.target;
            if (!t) return;
            if (t.id === 'select-all') {
                document.querySelectorAll('.report-checkbox').forEach(function (c) { c.checked = t.checked; });
                updateBatchBtn();
            } else if (t.classList && t.classList.contains('report-checkbox')) {
                updateBatchBtn();
            }
        });

        // 批量处理
        const batchBtn = document.getElementById('batch-handle-btn');
        if (batchBtn) batchBtn.addEventListener('click', function () {
            const boxes = document.querySelectorAll('.report-checkbox:checked');
            if (!boxes.length) { if (needSelect) alert(needSelect); return; }
            const days = document.getElementById('batch-ban-days').value;
            const reason = document.getElementById('batch-reason').value;
            const action = document.getElementById('batch-action').value;
            if (confirmMsg && !confirm(confirmMsg)) return;
            const fields = { csrf: csrf, action: action, ban_days: Math.max(0, parseInt(days, 10) || 0), reason: reason };
            for (let i = 0; i < boxes.length; i++) fields['reports[' + i + ']'] = boxes[i].value;
            postForm(batchUrl, fields);
        });

        // 单条封禁（prompt 天数 + 原因）
        root.addEventListener('click', function (e) {
            const btn = e.target.closest('.report-ban-btn');
            if (!btn) return;
            const days = prompt(btn.dataset.daysLabel || '', btn.dataset.defaultDays || '');
            if (days === null) return;
            const reason = prompt(btn.dataset.reasonLabel || '', btn.dataset.reasonPh || '');
            if (reason === null) return;
            postForm(handlePrefix + btn.dataset.id + '/handle', {
                csrf: csrf,
                action: 'ban',
                ban_days: Math.max(0, parseInt(days, 10) || 0),
                reason: reason
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAdminReports);
    } else {
        initAdminReports();
    }
})();