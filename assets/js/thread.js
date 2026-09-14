/* ===== 帖子详情页脚本 ===== */

/* 图片灯箱：PC 滚轮缩放 + 拖拽平移，移动端双指捏合 + 单指平移，点击图片关闭 */
var lbScale = 1;           // 当前缩放倍数
var lbTx = 0, lbTy = 0;    // 当前平移偏移（屏幕像素）
var lbTouch = null;        // 移动端手势状态 { mode:'pinch'|'pan', ... }
var lbDrag = null;         // PC 拖拽状态 { px, py, moved }
var lbJustDragged = false; // 刚结束一次拖拽，用于抑制随之而来的点击关闭

function lbApply() {
    var lbImg = document.getElementById('lightboxImg');
    lbImg.style.transform = 'translate(' + lbTx + 'px, ' + lbTy + 'px) scale(' + lbScale + ')';
    lbImg.classList.toggle('lb-zoomed', lbScale > 1);
}

// 把平移限制在视口内，避免放大后把图拖到完全看不到
function lbClamp() {
    var lbImg = document.getElementById('lightboxImg');
    var w = lbImg.offsetWidth * lbScale;
    var h = lbImg.offsetHeight * lbScale;
    var minW = Math.min(w, 60), minH = Math.min(h, 60);
    lbTx = Math.max(-(w - minW) / 2, Math.min((w - minW) / 2, lbTx));
    lbTy = Math.max(-(h - minH) / 2, Math.min((h - minH) / 2, lbTy));
}

function lbRender() {
    lbClamp();
    lbApply();
}

function viewImage(el) {
    var img = el.tagName === 'IMG' ? el : el.querySelector('img');
    if (!img) return;
    var fullUrl = img.getAttribute('data-fullurl') || img.src;
    var lbImg = document.getElementById('lightboxImg');
    lbImg.src = fullUrl;
    lbScale = 1; lbTx = 0; lbTy = 0; // 每次打开重置缩放与平移
    lbJustDragged = false;
    lbApply();
    document.getElementById('imageLightbox').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeLightbox(e) {
    // 拖拽刚结束触发的点击，忽略本次（避免拖动后误关）
    if (e && e.type === 'click' && lbJustDragged) { lbJustDragged = false; return; }
    if (e && e.target && e.target.id !== 'imageLightbox' && e.target.className !== 'lb-close' && e.target.id !== 'lightboxImg') return;
    document.getElementById('imageLightbox').classList.remove('show');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeLightbox();
});

// 灯箱打开时：滚轮缩放（PC）
document.addEventListener('wheel', function(e) {
    var lb = document.getElementById('imageLightbox');
    if (!lb || !lb.classList.contains('show')) return;
    e.preventDefault();
    var delta = e.deltaY < 0 ? 1.15 : 0.85;
    lbScale = Math.min(6, Math.max(0.4, lbScale * delta));
    lbRender();
}, { passive: false });

// PC 拖拽平移（放大后启用）
document.addEventListener('mousedown', function(e) {
    var lb = document.getElementById('imageLightbox');
    if (!lb || !lb.classList.contains('show') || lbScale <= 1) return;
    var t = e.target;
    if (t.id !== 'lightboxImg' && t.id !== 'imageLightbox') return;
    e.preventDefault();
    lbJustDragged = false;
    lbDrag = { px: e.clientX, py: e.clientY, moved: false };
    document.getElementById('lightboxImg').classList.add('lb-dragging');
});

document.addEventListener('mousemove', function(e) {
    if (!lbDrag) return;
    e.preventDefault();
    var dx = e.clientX - lbDrag.px;
    var dy = e.clientY - lbDrag.py;
    lbDrag.px = e.clientX;
    lbDrag.py = e.clientY;
    if (dx || dy) lbDrag.moved = true;
    lbTx += dx;
    lbTy += dy;
    lbRender();
});

document.addEventListener('mouseup', function() {
    if (!lbDrag) return;
    var moved = lbDrag.moved;
    lbDrag = null;
    document.getElementById('lightboxImg').classList.remove('lb-dragging');
    if (moved) lbJustDragged = true;
});

// 移动端：双指捏合缩放 + 单指平移（放大后）
document.addEventListener('touchstart', function(e) {
    var lb = document.getElementById('imageLightbox');
    if (!lb || !lb.classList.contains('show')) return;
    lbJustDragged = false;
    if (e.touches.length === 2) {
        e.preventDefault();
        var t = e.touches;
        lbTouch = {
            mode: 'pinch',
            dist: Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY),
            px: (t[0].clientX + t[1].clientX) / 2,
            py: (t[0].clientY + t[1].clientY) / 2
        };
        document.getElementById('lightboxImg').classList.add('lb-dragging');
    } else if (e.touches.length === 1 && lbScale > 1) {
        lbTouch = { mode: 'pan', px: e.touches[0].clientX, py: e.touches[0].clientY, moved: false };
        document.getElementById('lightboxImg').classList.add('lb-dragging');
    }
}, { passive: false });

document.addEventListener('touchmove', function(e) {
    var lb = document.getElementById('imageLightbox');
    if (!lb || !lb.classList.contains('show') || !lbTouch) return;
    e.preventDefault();
    if (lbTouch.mode === 'pinch' && e.touches.length === 2) {
        var t = e.touches;
        var dist = Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY);
        if (dist > 0) {
            lbScale = Math.min(6, Math.max(0.4, lbScale * (dist / lbTouch.dist)));
            lbTouch.dist = dist;
        }
        // 双指整体移动同步平移
        var mx = (t[0].clientX + t[1].clientX) / 2;
        var my = (t[0].clientY + t[1].clientY) / 2;
        lbTx += mx - lbTouch.px;
        lbTy += my - lbTouch.py;
        lbTouch.px = mx;
        lbTouch.py = my;
        lbTouch.moved = true;
        lbRender();
    } else if (lbTouch.mode === 'pan' && e.touches.length === 1) {
        var tx = e.touches[0].clientX;
        var ty = e.touches[0].clientY;
        lbTx += tx - lbTouch.px;
        lbTy += ty - lbTouch.py;
        lbTouch.px = tx;
        lbTouch.py = ty;
        lbTouch.moved = true;
        lbRender();
    }
}, { passive: false });

document.addEventListener('touchend', function() {
    if (!lbTouch) return;
    var moved = lbTouch.moved;
    lbTouch = null;
    document.getElementById('lightboxImg').classList.remove('lb-dragging');
    if (moved) lbJustDragged = true;
});

// 详情页交互使用事件委托，兼容 htmx 仅替换 #replies 后新增的节点。
document.addEventListener('click', function(e) {
    var imageLink = e.target.closest('[data-action="view-image"]');
    if (imageLink) {
        e.preventDefault();
        viewImage(imageLink);
        return;
    }

    var quoteLink = e.target.closest('[data-action="quote"]');
    if (quoteLink) {
        e.preventDefault();
        if (typeof window.quotePost === 'function') window.quotePost(quoteLink);
        return;
    }

    var scrollLink = e.target.closest('[data-action="scroll-reply"]');
    if (scrollLink) {
        e.preventDefault();
        var replyForm = document.getElementById('replyForm');
        if (replyForm) replyForm.scrollIntoView({ behavior: 'smooth' });
        return;
    }

    var closeLink = e.target.closest('[data-action="close-lightbox"]');
    if (closeLink) {
        e.preventDefault();
        closeLightbox(e);
        return;
    }

    var lightbox = document.getElementById('imageLightbox');
    if (lightbox && e.target === lightbox) closeLightbox(e);
});

document.addEventListener('click', function(e) {
    var img = e.target.closest('.post-text-content img');
    if (!img || e.target.closest('[data-action="view-image"]')) return;
    e.preventDefault();
    viewImage(img);
});

document.addEventListener('change', function(e) {
    var input = e.target.closest('[data-action="reply-attachments"]');
    if (!input) return;
    var output = document.getElementById('reply-file-name');
    if (!output) return;
    var prefix = input.getAttribute('data-label-prefix') || '';
    var names = Array.prototype.map.call(input.files || [], function(file) { return file.name; });
    output.textContent = names.length ? prefix + ' ' + names.join(', ') : '';
});

// htmx 翻页只替换 #replies；主帖不重刷，翻页后仍保持默认折叠。
function keepThreadCollapsed() {
    var root = document.querySelector('[data-thread-collapse]');
    if (!root || !window.Alpine || typeof window.Alpine.$data !== 'function') return;
    var data = window.Alpine.$data(root);
    if (!data || typeof data.expanded === 'undefined') return;
    data.expanded = false;
}

document.body.addEventListener('htmx:pushedIntoHistory', function() {
    keepThreadCollapsed();
});
