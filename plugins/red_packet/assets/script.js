/* ===== 红包插件脚本 ===== */
(function() {
    var btn = document.getElementById('rpGrabBtn');
    var form = document.getElementById('rpGrabForm');
    var envelope = document.getElementById('rpEnvelope');

    if (btn && form) {
        btn.addEventListener('click', function(e) {
            btn.style.transition = 'all .15s cubic-bezier(.4,0,.2,1)';
            btn.style.transform = 'scale(0.85)';
            setTimeout(function() {
                btn.style.transform = 'scale(1.2)';
                setTimeout(function() {
                    form.submit();
                }, 150);
            }, 150);
            e.preventDefault();
        });
    }

    /* ===== 帖子内嵌红包卡片：AJAX 抢红包（不跳页，成功后刷新卡片状态） ===== */
    document.addEventListener('click', function(e) {
        var grabBtn = e.target.closest('[data-rp-grab]');
        if (!grabBtn) return;
        e.preventDefault();

        var card = grabBtn.closest('[data-rp-card]');
        var packetId = grabBtn.getAttribute('data-rp-id');
        var csrf = grabBtn.getAttribute('data-rp-csrf') || '';
        var msgEl = card ? card.querySelector('[data-rp-msg]') : null;

        var fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('ajax', '1');

        fetch((window.BASE_PATH || '') + '/redpacket/' + packetId + '/grab', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json().catch(function() { return null; }); })
        .then(function(data) {
            if (!data) { showRpMsg(msgEl, '请求失败，请重试', false); return; }
            if (data.success) {
                showRpMsg(msgEl, data.message || ('恭喜！抢到 ' + data.points + ' 积分 🧧'), true);
                grabBtn.disabled = true;
                // 按钮只显示"已抢"（后端本地化；积分提示走下方 message，不重复显示）
                grabBtn.textContent = data.claimed_btn_text || '已抢';
            } else {
                showRpMsg(msgEl, data.message || '抢红包失败', false);
                if (card) {
                    // 已抢/已抢完/已过期：刷新为只读状态
                    var statusWrap = card.querySelector('.rp-thread-action');
                    if (statusWrap) {
                        statusWrap.innerHTML = '<span class="rp-thread-status rp-thread-status-off">' + (data.message || '') + '</span>';
                    }
                }
            }
        })
        .catch(function() {
            showRpMsg(msgEl, '网络异常，请重试', false);
        });
    });

    function showRpMsg(el, text, isOk) {
        if (!el) return;
        el.textContent = text;
        el.className = 'rp-thread-msg ' + (isOk ? 'rp-thread-msg-ok' : 'rp-thread-msg-err');
    }
})();
