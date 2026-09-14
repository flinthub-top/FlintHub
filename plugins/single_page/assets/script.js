/* ===== 单页/链接管理插件（single_page）外部脚本 =====
   零内联：类型切换 / 删除确认逻辑收敛于此，视图内不写任何内联 JS */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var typeSelect = document.getElementById('sp-type');
        if (typeSelect) {
            var fields = document.querySelectorAll('[data-sp-field]');
            var sync = function () {
                var isLink = typeSelect.value === 'link';
                fields.forEach(function (el) {
                    el.style.display = (el.getAttribute('data-sp-field') === 'link') === isLink ? '' : 'none';
                });
            };
            typeSelect.addEventListener('change', sync);
            sync();
        }

        var deleteForms = document.querySelectorAll('.sp-delete-form');
        deleteForms.forEach(function (form) {
            form.addEventListener('submit', function (e) {
                var msg = form.getAttribute('data-confirm');
                if (msg && !window.confirm(msg)) {
                    e.preventDefault();
                }
            });
        });
    });
})();
