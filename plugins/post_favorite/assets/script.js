/* ===== 收藏按钮（帖子详情页自动注入） ===== */
(function() {
    document.addEventListener('DOMContentLoaded', function() {
        var isThreadPage = /\/thread\/\d+/.test(window.location.pathname);
        if (!isThreadPage) return;

        var threadId = null;
        // 详情页存在两个 .thread-info-bar（用户信息栏 + 统计操作栏），querySelector 会取到第一个；
        // 改为直接定位唯一的 .vote-buttons[data-type="thread"]，再 closest 回统计操作栏
        var voteBtns = document.querySelector('.vote-buttons[data-type="thread"]');
        if (!voteBtns || !voteBtns.dataset.id) return;
        threadId = parseInt(voteBtns.dataset.id);
        if (!threadId) return;

        var el = voteBtns.closest('.thread-info-bar') || document;

        var csrfInput = document.querySelector('input[name="csrf"]');
        var csrfToken = csrfInput ? csrfInput.value : '';

        var favBtn = document.createElement('button');
        favBtn.className = 'fav-btn';
        favBtn.title = '收藏';
        favBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg><span class="fav-label">收藏</span>';

        fetch('/favorite/check?thread_id=' + threadId, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.favorited) {
                    favBtn.classList.add('fav-active');
                    favBtn.title = '已收藏';
                    var lbl = favBtn.querySelector('.fav-label');
                    if (lbl) lbl.textContent = '已收藏';
                }
            })
            .catch(function() {});

        favBtn.addEventListener('click', function() {
            if (!csrfToken) return;
            var formData = new FormData();
            formData.append('csrf', csrfToken);
            formData.append('thread_id', threadId);

            fetch('/favorite/toggle', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.favorited) {
                    favBtn.classList.add('fav-active');
                    favBtn.title = '已收藏';
                    var lbl = favBtn.querySelector('.fav-label');
                    if (lbl) lbl.textContent = '已收藏';
                } else {
                    favBtn.classList.remove('fav-active');
                    favBtn.title = '收藏';
                    var lbl2 = favBtn.querySelector('.fav-label');
                    if (lbl2) lbl2.textContent = '收藏';
                }
            })
            .catch(function() {});
        });

        // 收藏按钮注入到右侧留位容器（thread-info-bar-right，靠右对齐）；
        // 兼容旧结构（无右侧容器时回退到左侧操作组）
        var rightGroup = el.querySelector('.thread-info-bar-right');
        if (rightGroup) {
            rightGroup.appendChild(favBtn);
        } else {
            var group = voteBtns.parentNode;
            if (group) {
                group.appendChild(favBtn);
            } else {
                el.appendChild(favBtn);
            }
        }
    });
})();
