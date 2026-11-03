/* Only the empty capture screen and explicitly listed assets enter this cache. */
(function (config) {
    'use strict';
    self.addEventListener('install', function (event) {
        event.waitUntil(caches.open(config.cacheName).then(async function (cache) {
            await cache.addAll(config.assets);
            var response = await fetch(config.captureUrl, {credentials: 'same-origin', cache: 'no-store'});
            if (!response.ok || response.redirected) throw new Error('Capture screen requires login.');
            await cache.put(config.captureUrl, response);
            await self.skipWaiting();
        }));
    });
    self.addEventListener('activate', function (event) {
        event.waitUntil(caches.keys().then(function (keys) {
            return Promise.all(keys.filter(function (key) {
                return key.indexOf('memex-capture-') === 0 && key !== config.cacheName;
            }).map(function (key) { return caches.delete(key); }));
        }).then(function () { return self.clients.claim(); }));
    });
    self.addEventListener('fetch', function (event) {
        var request = event.request;
        var url = new URL(request.url);
        if (request.method !== 'GET') return;
        var isAsset = config.assets.indexOf(url.href) !== -1;
        var isCapture = url.origin === new URL(config.captureUrl).origin &&
            url.pathname.replace(/\/$/, '') === new URL(config.captureUrl).pathname.replace(/\/$/, '');
        if (!isAsset && request.mode !== 'navigate') return;
        event.respondWith(fetch(request).then(async function (response) {
            if ((isAsset || isCapture) && response.ok && !response.redirected) {
                var cache = await caches.open(config.cacheName);
                await cache.put(isCapture ? config.captureUrl : request, response.clone());
            }
            return response;
        }).catch(async function () {
            var cache = await caches.open(config.cacheName);
            return (isAsset ? await cache.match(request) : await cache.match(config.captureUrl)) || Response.error();
        }));
    });
    // Background Sync wakes open clients. Opening/focusing Memex also retries;
    // no claim that a closed app can always sync on every browser.
    self.addEventListener('sync', function (event) {
        if (event.tag !== 'memex-capture-sync') return;
        event.waitUntil(self.clients.matchAll({type: 'window'}).then(function (clients) {
            clients.forEach(function (client) { client.postMessage({type: 'memex-capture-sync'}); });
        }));
    });
    self.addEventListener('message', function (event) {
        if (event.data && event.data.type === 'memex-capture-status') {
            event.waitUntil(caches.open(config.cacheName).then(async function (cache) {
                var ready = !!(await cache.match(config.captureUrl));
                for (var asset of config.assets) ready = ready && !!(await cache.match(asset));
                if (event.source) event.source.postMessage({type: 'memex-capture-status', ready: ready});
            }));
        }
    });
})(self.memexWorker);
