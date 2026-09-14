/* ===== 勋章中心插件脚本（零内联）=====
 * 后台：编辑弹窗填充（data-medal 传参）+ 条件类型联动（condType change）
 * 前台：佩戴勾选自动保存（数据经 #medalWearMsg 的 data-* 传入）
 */
(function () {
    'use strict';

    /* ---------- 后台 ---------- */
    function editMedal(data) {
        document.getElementById('edit_id').value = data.id || 0;
        document.getElementById('edit_name').value = data.name || '';
        document.getElementById('edit_icon').value = data.icon || 'award';
        document.getElementById('edit_color').value = data.color || '#f59e0b';
        document.getElementById('edit_sort').value = data.sort || 0;
        document.getElementById('edit_description').value = data.description || '';
        document.getElementById('edit_condition_type').value = data.condition_type || 'manual';
        document.getElementById('edit_condition_value').value = data.condition_value || 0;
        document.getElementById('edit_image').value = data.image || '';
        document.getElementById('edit_status').checked = data.status ? true : false;
        var modal = document.getElementById('editModal');
        if (modal && window.Alpine) Alpine.$data(modal).open = true;
    }

    function initAdmin() {
        // 条件类型联动：manual 时禁用并清零数值输入
        var condSel = document.getElementById('condType');
        var condInput = document.getElementById('condValue');
        if (condSel && condInput) {
            var sync = function () {
                condInput.disabled = condSel.value === 'manual';
                if (condSel.value === 'manual') condInput.value = 0;
            };
            condSel.addEventListener('change', sync);
            sync();
        }
        // 编辑按钮：数据走 data-medal（JSON），避免内联 onclick
        document.querySelectorAll('.medal-edit-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var raw = btn.getAttribute('data-medal');
                if (!raw) return;
                try { editMedal(JSON.parse(raw)); } catch (e) { /* 数据异常忽略 */ }
            });
        });
    }

    /* ---------- 前台：佩戴勾选自动保存 ---------- */
    function initFront() {
        var msgEl = document.getElementById('medalWearMsg');
        if (!msgEl) return;
        var limit = parseInt(msgEl.getAttribute('data-limit'), 10) || 0;
        var limitMsg = msgEl.getAttribute('data-msg-limit') || '';
        var saving = false;

        function t(key, fb) {
            var v = window.__t ? window.__t(key) : '';
            return v || fb;
        }

        // 同步勾选高亮
        function syncPicks() {
            document.querySelectorAll('.medal-pick').forEach(function (label) {
                label.classList.toggle('medal-pick-on', label.querySelector('.medal-pick-input').checked);
            });
        }

        // 保存当前勾选（空勾选 = 清空佩戴 = 摘下全部）
        function doSave() {
            if (saving) return;
            var checked = document.querySelectorAll('.medal-pick-input:checked');
            var ids = [];
            checked.forEach(function (el) { ids.push(el.value); });
            var body = new URLSearchParams();
            body.append('csrf', window.CSRF_TOKEN || '');
            ids.forEach(function (v) { body.append('medal_ids[]', v); });

            saving = true;
            fetch((window.BASE_PATH || '') + '/medal/wear', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) { return r.json(); }).then(function (res) {
                saving = false;
                if (res.ok) {
                    msgEl.textContent = '';
                    syncPicks();
                } else {
                    msgEl.textContent = res.msg || t('plugin.medal.save_fail', '\u4fdd\u5b58\u5931\u8d25\uff0c\u8bf7\u91cd\u8bd5');
                    msgEl.style.color = 'var(--mn-error)';
                }
            }).catch(function () {
                saving = false;
                msgEl.textContent = t('plugin.medal.network_err', '\u7f51\u7edc\u9519\u8bef\uff0c\u8bf7\u91cd\u8bd5');
                msgEl.style.color = 'var(--mn-error)';
            });
        }

        // 点击勋章：即时切换佩戴并自动保存（再点已佩戴的 = 摘下）
        document.querySelectorAll('.medal-pick-input').forEach(function (el) {
            el.addEventListener('change', function () {
                var checked = document.querySelectorAll('.medal-pick-input:checked');
                if (checked.length > limit) {
                    // 超限：回滚本次勾选
                    this.checked = false;
                    syncPicks();
                    msgEl.textContent = limitMsg;
                    msgEl.style.color = 'var(--mn-error)';
                    return;
                }
                msgEl.textContent = '';
                syncPicks();
                doSave();
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initAdmin();
        initFront();
    });
})();
