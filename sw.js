// Temel Service Worker
const CACHE_NAME = 'stok-takip-v1';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(clients.claim());
});

self.addEventListener('fetch', (event) => {
    // Online-first stratejisi. Çevrimdışı ise PWA gereksinimi için temel yanıt döner.
    event.respondWith(
        fetch(event.request).catch(() => {
            return new Response("Çevrimdışı moddasınız. Lütfen internet bağlantınızı kontrol edin.", {
                status: 503,
                statusText: "Service Unavailable"
            });
        })
    );
});
