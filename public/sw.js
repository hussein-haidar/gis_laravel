const CACHE_NAME = 'gis-pwa-v1';
const APP_SHELL = [
    '/',
    '/manifest.webmanifest',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
];

const TILE_HOSTS = [
    'tile.openstreetmap.org',
    'server.arcgisonline.com',
    'tile.opentopomap.org',
    'unpkg.com',
    'cdn.jsdelivr.net',
];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            return cache.addAll(APP_SHELL);
        }).then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.map(function (key) {
                if (key !== CACHE_NAME) return caches.delete(key);
            }));
        }).then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function (event) {
    const url = new URL(event.request.url);

    // Khusus offline: layani GeoJSON/API dari cache saja (network-first gagal → cache)
    const isSameOrigin = url.origin === self.location.origin;
    const isTile = TILE_HOSTS.some(function (host) { return url.hostname === host; });

    if (event.request.method !== 'GET') return;

    // Halaman navigasi: network-first, fallback ke cache app shell.
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .then(function (resp) {
                    const clone = resp.clone();
                    caches.open(CACHE_NAME).then(function (cache) { cache.put(event.request, clone); });
                    return resp;
                })
                .catch(function () {
                    return caches.match('/');
                })
        );
        return;
    }

    if (isTile) {
        // Strategi cache-first untuk tile peta (hemat bandwidth, offline-ready).
        event.respondWith(
            caches.match(event.request).then(function (cached) {
                if (cached) return cached;
                return fetch(event.request).then(function (resp) {
                    if (resp && resp.status === 200) {
                        const clone = resp.clone();
                        caches.open(CACHE_NAME + '-tiles').then(function (cache) {
                            cache.put(event.request, clone);
                        });
                    }
                    return resp;
                }).catch(function () { return cached; });
            })
        );
        return;
    }

    if (isSameOrigin) {
        // Asset lokal: stale-while-revalidate.
        event.respondWith(
            caches.match(event.request).then(function (cached) {
                const network = fetch(event.request).then(function (resp) {
                    if (resp && resp.status === 200) {
                        const clone = resp.clone();
                        caches.open(CACHE_NAME).then(function (cache) { cache.put(event.request, clone); });
                    }
                    return resp;
                }).catch(function () { return cached; });
                return cached || network;
            })
        );
    }
});