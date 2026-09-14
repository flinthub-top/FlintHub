/**
 * FlintHub — 精简版 PWA Service Worker
 * 策略（极简，避免污染登录态）：
 *   1. 静态资源（css/js/fonts/images 等）→ Cache-First + 版本控制
 *   2. HTML 页面一律 Network-First，绝不缓存（登录用户页面含用户态，离线缓存会串号）
 *   3. API 请求不缓存（JSON/图片验证码等动态内容）
 * 版本号规则（semver 式进位，避免过早破百）：
 *   从 v1.0.0 起步，每次改动静态资源递增次版本号；次版本到 99 后进主版本：
 *   v1.0.0 → v1.0.1 → … → v1.0.99 → v1.1.0 → … → v1.99.99 → v2.0.0
 * 升级静态资源时递增 CACHE_VERSION，activate 自动清理旧缓存。
 * @file sw.js （必须位于站点根目录：scope 自动覆盖全站，无需 Service-Worker-Allowed 头）
 */
const CACHE_VERSION = 'flinthub-static-v1.0.0';

self.addEventListener('install', (event) => {
    // 立即接管页面，不等旧 SW 关闭
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;

    // 仅处理同源 GET
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    // HTML 页面（导航请求）与 API：一律走网络，绝不缓存
    if (req.mode === 'navigate' || url.pathname.startsWith('/api/')) return;
    // ★ [2026-08-22] manifest 不缓存：名称/图标调整后需立即生效（曾被 SW 缓存导致改名字不刷新）
    if (url.pathname.endsWith('/manifest.webmanifest')) return;
    // ★ [2026-08-23] sw.js 自身不缓存：防止 SW 更新检查被旧缓存拦截，导致 CACHE_VERSION 递增不生效
    if (url.pathname.endsWith('/sw.js')) return;
    // ★ [2026-08-30] htmx 局部片段（HX-Request）与 /floor-reply/ 动态接口：
    //   Accept 为 */* 会被误当静态资源走 Cache-First，缓存到"未登录"旧片段 →
    //   登录后 SW 直接命中缓存返回旧态（绕过服务器与 no-store 头）。一律走网络、绝不缓存。
    if (req.headers.get('HX-Request')) return;
    if (url.pathname.startsWith('/floor-reply/')) return;
    const accept = req.headers.get('Accept') || '';
    if (accept.includes('text/html')) return;

    // 静态资源：Cache-First
    event.respondWith(
        caches.match(req).then((hit) => {
            if (hit) return hit;
            return fetch(req).then((res) => {
                if (res && res.ok) {
                    const clone = res.clone();
                    caches.open(CACHE_VERSION).then((cache) => cache.put(req, clone));
                }
                return res;
            });
        })
    );
});
