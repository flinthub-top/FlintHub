/**
 * SoSite — 密码强度检测
 * 用于注册和重置密码页面的实时强度反馈
 * @file assets/js/auth.js
 */
function checkPasswordStrength(pw) {
    var bar = document.getElementById('strengthBar');
    var text = document.getElementById('strengthText');
    var wrap = document.getElementById('passwordStrength');
    if (!bar || !text || !wrap) return;
    if (pw.length < 4) { wrap.style.display = 'none'; return; }
    wrap.style.display = '';
    var hasDigit = /\d/.test(pw);
    var hasLower = /[a-z]/.test(pw);
    var hasUpper = /[A-Z]/.test(pw);
    var hasSpecial = /[^a-zA-Z0-9]/.test(pw);
    var score = 0;
    if (hasDigit) score++;
    if (hasLower) score++;
    if (hasUpper) score++;
    if (hasSpecial) score++;
    if (pw.length >= 8) score++;
    if (pw.length >= 12) score++;
    var pct = 0, label = '', color = '';
    var _t = (typeof window.__t === 'function') ? window.__t : function(k) { return k; };
    if (score <= 2) { pct = 33; label = _t('js.pwd_weak'); color = '#e74c3c'; }
    else if (score <= 3) { pct = 66; label = _t('js.pwd_mid'); color = '#f39c12'; }
    else { pct = 100; label = _t('js.pwd_strong'); color = '#27ae60'; }
    bar.style.width = pct + '%';
    bar.style.background = color;
    text.innerHTML = _t('js.pwd_strength') + '<span style="color:' + color + ';font-weight:600;">' + label + '</span>';
}