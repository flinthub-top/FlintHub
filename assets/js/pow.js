/**
 * FlintHub — PoW 工作量证明前端（优化修复版）
 * 自适应验证码防线第一道：表单提交前后台计算 nonce，无感完成
 * 依赖服务的 GET /api/pow 返回 { challenge, difficulty, mode }
 *
 * 优化点（对齐安全审计建议）：
 * 1) 异步获取 challenge，避免同步 XHR 阻塞主线程
 * 2) SHA-256 补齐 UTF-8 编码，保证与 PHP hash('sha256') 一致
 * 3) 提交期间加防重复锁，避免用户连点重复计算
 * 4) CAPTCHA 模式回显逻辑修正，避免验证码填写后的死循环
 */
(function () {
    'use strict';

    // 缓存当前挑战（含过期时间），避免每帧请求
    var powCache = null;

    /**
     * 异步获取挑战
     */
    function fetchChallengeAsync(callback) {
        if (powCache && powCache.expire > Date.now()) {
            return callback(null, powCache);
        }
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/api/pow', true); // 异步，避免阻塞主线程
        xhr.timeout = 8000;
        xhr.onload = function () {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    powCache = {
                        mode: data.mode || 'normal',
                        challenge: data.challenge || '',
                        difficulty: parseInt(data.difficulty, 10) || 4,
                        expire: Date.now() + 240000
                    };
                    callback(null, powCache);
                } catch (e) {
                    callback(e);
                }
            } else {
                callback(new Error('PoW fetch failed'));
            }
        };
        xhr.onerror = function () { callback(new Error('Network error')); };
        xhr.ontimeout = function () { callback(new Error('PoW fetch timeout')); };
        xhr.send();
    }

    /**
     * 计算满足难度要求的 nonce：hash(challenge + nonce) 前导 difficulty 个十六进制为 0
     */
    function solveNonce(challenge, difficulty) {
        if (!challenge) return '';
        var target = '0'.repeat(difficulty);
        for (var i = 0; i < 1e7; i++) {
            if (sha256Hex(challenge + i).slice(0, difficulty) === target) {
                return String(i);
            }
        }
        return '';
    }

    // ---- 最小的 SHA-256 实现（避免依赖 crypto.subtle 在非安全上下文不可用） ----
    var K = [
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
        0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
        0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
        0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
        0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
        0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
        0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
    ];
    function rotr(x, n) { return (x >>> n) | (x << (32 - n)); }
    function sha256Hex(msg) {
        var H = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
                 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19];
        // UTF-8 编码字符串为字节序列（与 PHP hash('sha256', string) 的字节语义一致）
        var bytes = [];
        for (var i = 0; i < msg.length; i++) {
            var c = msg.charCodeAt(i);
            if (c < 0x80) {
                bytes.push(c);
            } else if (c < 0x800) {
                bytes.push(0xc0 | (c >> 6), 0x80 | (c & 0x3f));
            } else if (c >= 0xd800 && c <= 0xdbff && i + 1 < msg.length) {
                // 代理对（补充平面字符）
                var lo = msg.charCodeAt(++i);
                var cp = 0x10000 + ((c - 0xd800) << 10) + (lo - 0xdc00);
                bytes.push(0xf0 | (cp >> 18), 0x80 | ((cp >> 12) & 0x3f), 0x80 | ((cp >> 6) & 0x3f), 0x80 | (cp & 0x3f));
            } else {
                bytes.push(0xe0 | (c >> 12), 0x80 | ((c >> 6) & 0x3f), 0x80 | (c & 0x3f));
            }
        }
        var l = bytes.length;
        var bitLen = l * 8;
        bytes.push(0x80);
        while (bytes.length % 64 !== 56) bytes.push(0);
        // SHA-256 长度字段为 64 位大端：短消息高 32 位为 0，低 32 位为 bitLen（共 8 字节）
        bytes.push(0, 0, 0, 0, (bitLen >>> 24) & 0xff, (bitLen >>> 16) & 0xff, (bitLen >>> 8) & 0xff, bitLen & 0xff);
        for (var j = 0; j < bytes.length; j += 64) {
            var w = [];
            for (var t = 0; t < 64; t++) {
                var idx = j + t * 4;
                w[t] = t < 16 ? (((bytes[idx] << 24) | (bytes[idx + 1] << 16)) | (bytes[idx + 2] << 8)) | bytes[idx + 3]
                              : ((rotr(w[t - 15], 7) ^ rotr(w[t - 15], 18) ^ (w[t - 15] >>> 3)) + w[t - 7]
                                 + (rotr(w[t - 2], 17) ^ rotr(w[t - 2], 19) ^ (w[t - 2] >>> 10)) + w[t - 16]) | 0;
            }
            var a = H[0], b = H[1], c2 = H[2], d = H[3], e = H[4], f = H[5], g = H[6], h = H[7];
            for (var t = 0; t < 64; t++) {
                var S1 = rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25);
                var ch = (e & f) ^ ((~e) & g);
                var temp1 = (h + S1 + ch + K[t] + w[t]) | 0;
                var S0 = rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22);
                var maj = (a & b) ^ (a & c2) ^ (b & c2);
                var temp2 = (S0 + maj) | 0;
                h = g; g = f; f = e; e = (d + temp1) | 0;
                d = c2; c2 = b; b = a; a = (temp1 + temp2) | 0;
            }
            H[0] = (H[0] + a) | 0; H[1] = (H[1] + b) | 0; H[2] = (H[2] + c2) | 0; H[3] = (H[3] + d) | 0;
            H[4] = (H[4] + e) | 0; H[5] = (H[5] + f) | 0; H[6] = (H[6] + g) | 0; H[7] = (H[7] + h) | 0;
        }
        var out = '';
        for (var i = 0; i < 8; i++) {
            out += ('00000000' + (H[i] >>> 0).toString(16)).slice(-8);
        }
        return out;
    }

    /**
     * 绑定到需要自适应验证的表单
     *
     * 采用单一 submit 监听器 + 状态标志区分：
     *  - powBusy：PoW 计算/异步获取中（再次提交忽略，防重复）
     *  - powDone：PoW 已算好，本次是 requestSubmit 触发的“放行提交”（不拦截）
     */
    function bind(formEl) {
        if (!formEl || formEl.dataset.powBound) return;
        formEl.dataset.powBound = '1';

        // 注入 nonce 隐藏域
        if (!formEl.querySelector('input[name="pow_nonce"]')) {
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'pow_nonce';
            formEl.appendChild(hidden);
        }

        formEl.addEventListener('submit', function (e) {
            // 1) 计算/异步进行中：忽略连点，避免重复计算
            if (formEl.dataset.powBusy === '1') {
                e.preventDefault();
                return;
            }
            // 2) PoW 完成后的自动提交：放行原生提交
            if (formEl.dataset.powDone === '1') {
                delete formEl.dataset.powDone;
                return;
            }

            // 3) 用户发起的提交：先拦截，进入 PoW 流程
            e.preventDefault();

            formEl.dataset.powBusy = '1';
            var submitBtn = formEl.querySelector('button[type="submit"]');
            if (submitBtn && !submitBtn.disabled) submitBtn.disabled = true;

            fetchChallengeAsync(function (err, challenge) {
                var fallback = { mode: 'normal', challenge: '', difficulty: 4 };
                var rc = err ? fallback : challenge;

                var capBlock = formEl.querySelector('[data-pow-captcha]');
                var capInput = formEl.querySelector('input[name="captcha"]');

                if (rc.mode === 'captcha') {
                    if (capBlock) capBlock.style.display = '';
                    if (!capInput || !capInput.value) {
                        // 需图片验证码且尚未填写：解锁，等用户输入后再次提交
                        if (submitBtn) submitBtn.disabled = false;
                        formEl.dataset.powBusy = '';
                        if (capInput) capInput.focus();
                        return;
                    }
                } else if (capBlock) {
                    capBlock.style.display = 'none';
                }

                // 延迟一帧计算，避免提交瞬间 UI 卡顿感知
                setTimeout(function () {
                    var nonce = solveNonce(rc.challenge, rc.difficulty);
                    formEl.querySelector('input[name="pow_nonce"]').value = nonce;
                    powCache = null; // 一次性挑战用后即失效，下次重新获取

                    if (submitBtn) submitBtn.disabled = false;
                    formEl.dataset.powBusy = '';
                    formEl.dataset.powDone = '1';

                    // 触发真正的提交（requestSubmit 会复核约束并触发 submit 事件）
                    formEl.requestSubmit ? formEl.requestSubmit() : formEl.submit();
                }, 20);
            });
        });
    }

    window.PowGuard = { bind: bind };

    // 脚本加载完成后自动绑定：login/register 表单此前在内联脚本中绑定，
    // 而本脚本位于 main.php 底部（content 之后），时序错位会导致绑定被跳过；
    // 现在由本脚本在自身就绪后主动查找并绑定登录/注册表单，消除时序依赖。
    function autoBind() {
        var ids = ['loginForm', 'registerForm', 'regForm'];
        for (var i = 0; i < ids.length; i++) {
            var el = document.getElementById(ids[i]);
            if (el) bind(el);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoBind);
    } else {
        autoBind();
    }
})();