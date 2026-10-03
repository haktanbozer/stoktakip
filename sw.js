// sw.js - Stok Takip PWA Service Worker v11
const CACHE_NAME = 'stok-takip-v11';
const STATIC_ASSETS = [
    '/stok-takip/offline.html',
    '/stok-takip/manifest.json',
    '/stok-takip/icons/icon-192x192.png',
    '/stok-takip/icons/icon-512x512.png'
];

self.addEventListener('install', event => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(STATIC_ASSETS).catch(() => {});
        })
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => {
            return Promise.all(
                keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))
            );
        }).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET') return;
    
    // Sayfa gezintilerinde: Once agi dene, baglanti yoksa offline.html goster
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(() => {
                return caches.match('/stok-takip/offline.html');
            })
        );
        return;
    }

    // Statik dosyalarda: Once ag, yoksa onbellek
    event.respondWith(
        fetch(event.request).catch(() => {
            return caches.match(event.request);
        })
    );
});