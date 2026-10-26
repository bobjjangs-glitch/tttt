/* sw.js — TIRETOP PWA 서비스 워커 (경량 캐시 셸) */
const CACHE_NAME = 'tiretop-v1';
const PRECACHE = [
  '/tire/manifest.json',
  '/tire/img/app-icon-192.png',
  '/tire/img/favicon-48.png',
];

/* 설치: 핵심 리소스 선캐시 */
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(PRECACHE).catch(() => null))
      .then(() => self.skipWaiting())
  );
});

/* 활성화: 구 캐시 정리 */
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

/* fetch: 앱 셸(아이콘/manifest)만 캐시, 나머지는 항상 네트워크 우선
   — 쇼핑몰은 가격/재고가 실시간이므로 HTML/DB 페이지를 캐시하지 않는다. */
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET') return;
  if (url.origin !== location.origin) return;

  const isAppShell =
    url.pathname === '/tire/manifest.json' ||
    url.pathname.startsWith('/tire/img/app-icon') ||
    url.pathname.startsWith('/tire/img/favicon');

  if (!isAppShell) return; // 일반 요청은 네트워크 그대로

  event.respondWith(
    caches.match(event.request).then((cached) => {
      return cached || fetch(event.request).then((res) => {
        const copy = res.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy)).catch(() => null);
        return res;
      }).catch(() => cached);
    })
  );
});
