/* ===== 友情链接插件脚本 ===== */
function editLink(link) {
    document.getElementById('edit_id').value = link.id;
    document.getElementById('edit_name').value = link.name;
    document.getElementById('edit_url').value = link.url;
    document.getElementById('edit_desc').value = link.description || '';
    document.getElementById('edit_logo').value = link.logo || '';
    document.getElementById('edit_sort').value = link.sort_order || 0;
    document.getElementById('edit_visible').value = link.is_visible ? '1' : '0';
    document.getElementById('editModal').style.display = 'flex';
}
function closeModal(e) {
    if (e && e.target !== e.currentTarget) return;
    document.getElementById('editModal').style.display = 'none';
}

/* 弹窗外层点击 / 关闭按钮 / 取消按钮绑定（收敛自 views/admin.php 内联脚本） */
document.addEventListener('DOMContentLoaded', function() {
    var modal = document.getElementById('editModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === e.currentTarget) closeModal();
        });
        modal.querySelector('.fl-modal')?.addEventListener('click', function(e) { e.stopPropagation(); });
        modal.querySelector('.fl-modal-close')?.addEventListener('click', closeModal);
        modal.querySelector('.btn-secondary')?.addEventListener('click', closeModal);
    }
    // 编辑按钮：数据走 data-link（JSON），避免内联 onclick
    document.querySelectorAll('.fl-edit-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var raw = btn.getAttribute('data-link');
            if (!raw) return;
            try { editLink(JSON.parse(raw)); } catch (e) { /* 数据异常忽略 */ }
        });
    });
});
