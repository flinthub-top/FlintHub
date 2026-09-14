/* ===== 邀请注册插件脚本（零内联）=====
 * 注册页注入邀请码输入框：文案与表单 action 由 #inviteConfig 的 data-* 传入
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var cfg = document.getElementById('inviteConfig');
        if (!cfg) return;
        var form = document.querySelector('form[action="' + (cfg.getAttribute('data-action') || '') + '"]');
        if (!form) return;
        var wrapper = document.createElement('div');
        wrapper.className = 'invite-field';
        wrapper.innerHTML = '<label for="invite_code">' + (cfg.getAttribute('data-label') || '') + ' <span class="invite-required">*</span></label>'
            + '<input type="text" name="invite_code" id="invite_code" placeholder="' + (cfg.getAttribute('data-ph') || '') + '" required autocomplete="off">';
        var emailField = form.querySelector('input[name="email"]');
        if (emailField && emailField.parentNode) {
            emailField.parentNode.parentNode.insertBefore(wrapper, emailField.parentNode.nextSibling);
        }
    });
})();
