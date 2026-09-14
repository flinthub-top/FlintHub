/* ===== 公告系统插件脚本 ===== */
(function () {
    // 滚动模式轮播（announcementScroll 存在才启动）
    function initScroll() {
        var wrap = document.getElementById('announcementScroll');
        if (!wrap) return;
        var items = wrap.children;
        if (items.length < 2) return;
        var current = 0;
        items[0].style.display = 'flex';
        for (var i = 1; i < items.length; i++) items[i].style.display = 'none';
        setInterval(function () {
            items[current].style.display = 'none';
            current = (current + 1) % items.length;
            items[current].style.display = 'flex';
        }, 4000);
    }

    // 普通模式关闭公告
    function dismissAnnouncement(id) {
        var bar = document.getElementById('ann_' + id);
        if (bar) {
            bar.classList.add('dismissed');
            setTimeout(function () { bar.style.display = 'none'; }, 300);
        }
    }

    // 后台编辑公告弹窗填充
    function editAnnouncement(data) {
        document.getElementById('edit_id').value = data.id || 0;
        document.getElementById('edit_content').value = data.content || '';
        document.getElementById('edit_url').value = data.url || '';
        document.getElementById('edit_style').value = data.style || 'yellow';
        document.getElementById('edit_sort').value = data.sort_order || 0;
        Alpine.$data(document.getElementById('editModal')).open = true;
    }

    document.addEventListener('DOMContentLoaded', function () {
        initScroll();
        document.querySelectorAll('.announcement-close').forEach(function (btn) {
            btn.addEventListener('click', function () {
                dismissAnnouncement(parseInt(this.dataset.id));
            });
        });
        // 后台编辑按钮：数据走 data-ann（JSON），避免内联 onclick
        document.querySelectorAll('.ann-edit-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var raw = btn.getAttribute('data-ann');
                if (!raw) return;
                try { editAnnouncement(JSON.parse(raw)); } catch (e) { /* 数据异常忽略 */ }
            });
        });
    });

    window.editAnnouncement = editAnnouncement;
    window.dismissAnnouncement = dismissAnnouncement;
})();
