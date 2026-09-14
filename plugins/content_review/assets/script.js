/* ===== 内容审核插件脚本（零内联）=====
 * 待审列表：通过/驳回/删除按钮（data-action 绑定）+ 批量驳回（Alpine 调用）
 * 函数挂全局（batchReject 被视图 Alpine x-on:click 直接调用）
 */
(function () {
    'use strict';

    // i18n 兜底：__t() 由核心布局注入（main.php）
    function t(key, fb) {
        var v = window.__t ? window.__t(key) : '';
        return v || fb;
    }

    function showReject(id) {
        document.getElementById('reject_id').value = id;
        var modal = document.getElementById('rejectModal');
        if (modal && window.Alpine) Alpine.$data(modal).open = true;
    }

    // 动态表单提交（避免与批量外层 form 嵌套导致失效）
    function submitForm(action, id) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = (window.BASE_PATH || '') + action;
        var csrf = document.querySelector('input[name="csrf"]');
        form.innerHTML = '<input name="csrf" value="' + (csrf ? csrf.value : '') + '"><input name="id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }

    function approveOne(id) {
        if (!confirm(t('plugin.content_review.approve_confirm', '\u786e\u5b9a\u901a\u8fc7\u6b64\u6761\u5ba1\u6838\uff1f'))) return;
        submitForm('/admin/content-review/approve', id);
    }

    // 单条删除审核记录
    function deleteOne(id) {
        if (!confirm(t('plugin.content_review.delete_confirm', '\u786e\u5b9a\u5220\u9664\u8fd9\u6761\u5ba1\u6838\u8bb0\u5f55\uff1f'))) return;
        submitForm('/admin/content-review/delete', id);
    }

    function batchReject() {
        var boxes = document.querySelectorAll('#pendingForm input[name="ids[]"]:checked');
        if (boxes.length === 0) { alert('\u8bf7\u5148\u9009\u62e9\u8981\u9a73\u56de\u7684\u8bb0\u5f55'); return; }
        showReject(0);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-action="approve"]').forEach(function (btn) {
            btn.addEventListener('click', function () { approveOne(parseInt(this.dataset.id)); });
        });
        document.querySelectorAll('[data-action="reject"]').forEach(function (btn) {
            btn.addEventListener('click', function () { showReject(parseInt(this.dataset.id)); });
        });
        document.querySelectorAll('[data-action="delete"]').forEach(function (btn) {
            btn.addEventListener('click', function () { deleteOne(parseInt(this.dataset.id)); });
        });
    });

    // 视图 Alpine x-on:click="batchReject()" 需要全局可见
    window.batchReject = batchReject;
})();
