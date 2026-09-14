/**
 * 任务中心插件脚本（前台 + 后台）
 * 1. 进度条填充：读取 .tc-progress-fill[data-progress]（0-100）设置宽度
 * 2. 后台类型联动：type 切换显隐 sign_in_mode / profile_mode 字段行
 */
(function () {
    'use strict';

    // 1. 进度条填充
    document.querySelectorAll('.tc-progress-fill[data-progress]').forEach(function (el) {
        var p = parseInt(el.getAttribute('data-progress'), 10);
        if (isNaN(p)) p = 0;
        p = Math.max(0, Math.min(100, p));
        el.style.width = p + '%';
    });

    // 2. 后台表单类型联动
    var typeSel = document.getElementById('tc-type');
    if (typeSel) {
        var fieldRows = Array.prototype.slice.call(document.querySelectorAll('.tc-field[data-tc-field]'));
        var showFields = function () {
            var t = typeSel.value;
            fieldRows.forEach(function (row) {
                var f = row.getAttribute('data-tc-field');
                var visible = (f === 'sign_in_mode' && t === 'sign_in') || (f === 'profile_mode' && t === 'profile');
                row.style.display = visible ? '' : 'none';
            });
        };
        typeSel.addEventListener('change', showFields);
        showFields();
    }
})();
