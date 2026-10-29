(function () {
    'use strict';
    var config = window.memexOffline;
    if (!config || !config.userId) return;
    var dbPromise;
    var running = false;
    var lastError = '';
    var ready = 'Checking…';
    var channel;
    var dbName = 'memex-capture:' + config.scope + ':' + config.userId;

    function openDB() {
        if (!dbPromise) dbPromise = new Promise(function (resolve, reject) {
            if (!window.indexedDB) return reject(new Error('Local storage unavailable. Keep your text here and save online.'));
            var request = indexedDB.open(dbName, 1);
            request.onupgradeneeded = function () {
                ['entries', 'drafts', 'meta'].forEach(function (name) { request.result.createObjectStore(name, {keyPath: 'id'}); });
            };
            request.onsuccess = function () { resolve(request.result); };
            request.onerror = function () { reject(request.error); };
            request.onblocked = function () { reject(new Error('Local storage is blocked. Close other Memex tabs and retry.')); };
        });
        return dbPromise;
    }
    function transaction(stores, mode, work) {
        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(stores, mode);
                var value;
                tx.oncomplete = function () { resolve(value); };
                tx.onerror = tx.onabort = function () { reject(tx.error || new Error('Local save failed. Your text has not been cleared.')); };
                work(tx, function (result) { value = result; });
            });
        });
    }
    function rows(store) {
        return transaction([store], 'readonly', function (tx, done) {
            tx.objectStore(store).getAll().onsuccess = function (event) { done(event.target.result); };
        });
    }
    function setText(selector, text) {
        document.querySelectorAll(selector).forEach(function (node) { node.textContent = text; });
    }
    async function refresh() {
        setText('[data-offline-connection]', navigator.onLine ? 'Online' : 'Offline');
        setText('[data-offline-ready]', ready);
        try {
            var entries = await rows('entries');
            var meta = await rows('meta');
            var last = meta.find(function (item) { return item.id === 'lastSync'; });
            setText('[data-offline-count]', entries.length ? '(' + entries.length + ')' : '');
            setText('[data-offline-pending]', String(entries.length));
            setText('[data-offline-last]', last ? new Date(last.value).toLocaleString() : 'None yet');
            document.querySelectorAll('[data-offline-saved]').forEach(function (node) {
                node.hidden = !last || !last.url;
                if (last && last.url) node.href = last.url;
            });
            setText('[data-offline-status]', lastError || (running ? 'Syncing…' : entries.length ? 'Saved on this device. Waiting to sync.' : 'No pending entries.'));
            document.querySelectorAll('[data-offline-entries]').forEach(function (list) {
                list.replaceChildren();
                entries.forEach(function (entry) {
                    var li = document.createElement('li');
                    var details = document.createElement('details');
                    var summary = document.createElement('summary');
                    summary.textContent = (entry.kind === 'daily' ? entry.date + ' ' + entry.clock : entry.title) + ' — ' + new Date(entry.createdAt).toLocaleString();
                    var text = document.createElement('pre');
                    text.textContent = entry.content;
                    details.append(summary, text);
                    li.append(details);
                    list.append(li);
                });
            });
        } catch (error) {
            setText('[data-offline-pending]', 'Unavailable');
            setText('[data-offline-status]', error.message);
        }
    }
    function changed() {
        refresh();
        if (channel) channel.postMessage('changed');
    }
    async function request(data) {
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, 20000);
        try {
            var response = await fetch(config.ajaxUrl, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: new URLSearchParams(data), signal: controller.signal
            });
            var result;
            try { result = await response.json(); } catch (_) { throw new Error('Could not verify saving. Entry kept for retry; log in if your session expired.'); }
            if (!response.ok || !result.success) throw new Error(result.data && result.data.message || 'Could not sync. Your entry remains on this device.');
            return result.data;
        } finally { clearTimeout(timer); }
    }
    async function replay() {
        var entries = await rows('entries');
        if (!entries.length) return;
        var session = await request({action: 'memex_capture_session'});
        if (Number(session.userId) !== Number(config.userId)) throw new Error('Sign in to the account that saved these entries. They have not been sent.');
        for (var entry of entries.sort(function (a, b) { return a.createdAt.localeCompare(b.createdAt); })) {
            var receipt = await request({action: 'memex_capture_sync', nonce: session.nonce, capture: JSON.stringify(entry)});
            if (receipt.id !== entry.id || !receipt.postId) throw new Error('Server did not acknowledge this entry. Kept for retry.');
            await transaction(['entries', 'meta'], 'readwrite', function (tx) {
                tx.objectStore('entries').delete(entry.id);
                tx.objectStore('meta').put({id: 'lastSync', value: new Date().toISOString(), url: receipt.url});
            });
            changed();
        }
    }
    async function sync() {
        if (running || !navigator.onLine) { refresh(); return; }
        running = true;
        lastError = '';
        refresh();
        try {
            if (navigator.locks) await navigator.locks.request(dbName + ':sync', replay);
            else await replay(); // Server receipts also protect browsers without Web Locks.
            document.querySelectorAll('[data-offline-login]').forEach(function (node) { node.hidden = true; });
        } catch (error) {
            lastError = error.message;
            document.querySelectorAll('[data-offline-login]').forEach(function (node) { node.hidden = false; });
        } finally { running = false; changed(); }
    }
    function uuid() {
        if (crypto.randomUUID) return crypto.randomUUID();
        return '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, function (digit) {
            return (digit ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> digit / 4).toString(16);
        });
    }
    function captureTime(now, timezone) {
        var parts;
        // WordPress supports fixed offsets as well as IANA zone names.
        if (/^[+-]\d{2}:\d{2}$/.test(timezone)) {
            var sign = timezone[0] === '-' ? -1 : 1;
            var shifted = new Date(now.getTime() + sign * (Number(timezone.slice(1, 3)) * 60 + Number(timezone.slice(4))) * 60000);
            return {date: shifted.toISOString().slice(0, 10), clock: shifted.toISOString().slice(11, 16)};
        }
        parts = new Intl.DateTimeFormat('en-CA', {timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23'}).formatToParts(now);
        var values = {};
        parts.forEach(function (part) { values[part.type] = part.value; });
        return {date: values.year + '-' + values.month + '-' + values.day, clock: values.hour + ':' + values.minute};
    }
    function bindForm(form, index) {
        var content = form.elements.content;
        var title = form.elements.title;
        var kind = form.elements.kind;
        var status = form.querySelector('[data-capture-status]');
        if (!status) {
            status = document.createElement('p');
            status.setAttribute('role', 'status');
            form.append(status);
        }
        var draftId = new URL(location.href).pathname + ':' + index;
        var draftWrites = Promise.resolve();
        var edited = false;
        var saving = false;
        function snapshot() { return {id: draftId, content: content.value, title: title ? title.value : '', kind: kind ? kind.value : 'daily'}; }
        function choice() {
            var label = form.querySelector('[data-capture-title]');
            if (label) { label.hidden = kind.value !== 'note'; title.required = kind.value === 'note'; }
        }
        function draft() {
            edited = true;
            var value = snapshot();
            draftWrites = draftWrites.catch(function () {}).then(function () {
                return transaction(['drafts'], 'readwrite', function (tx) { tx.objectStore('drafts').put(value); });
            });
            draftWrites.then(function () { if (!saving) status.textContent = 'Draft saved on this device.'; }, function () { status.textContent = 'Could not save draft locally. Keep this screen open and copy your text.'; });
        }
        form.addEventListener('input', draft);
        form.addEventListener('change', function () { choice(); draft(); });
        choice();
        rows('drafts').then(function (drafts) {
            var saved = drafts.find(function (item) { return item.id === draftId; });
            if (saved && !edited) {
                content.value = saved.content;
                if (title) title.value = saved.title;
                if (kind) kind.value = saved.kind;
                choice();
                if (saved.content) status.textContent = 'Restored draft from this device.';
            }
        }).catch(function () { status.textContent = 'Local saving unavailable. Keep your text until it is saved online.'; });
        form.addEventListener('submit', async function (event) {
            // Existing quick-capture forms retain their online PHP fallback.
            if (!window.indexedDB && form.hasAttribute('action') && navigator.onLine) return;
            event.preventDefault();
            if (saving || !content.value.trim()) return;
            saving = true;
            var before = snapshot();
            var button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            try {
                var time = captureTime(new Date(), config.timezone);
                var entry = Object.assign({}, before, time, {id: uuid(), userId: config.userId, createdAt: new Date().toISOString()});
                await draftWrites;
                await transaction(['entries', 'drafts'], 'readwrite', function (tx) {
                    tx.objectStore('entries').put(entry);
                    if (JSON.stringify(snapshot()) === JSON.stringify(before)) tx.objectStore('drafts').delete(draftId);
                });
                if (JSON.stringify(snapshot()) === JSON.stringify(before)) {
                    content.value = '';
                    if (title) title.value = '';
                }
                status.textContent = 'Saved on this device. Check Offline & sync for server confirmation.';
                if (navigator.storage && navigator.storage.persist) navigator.storage.persist().catch(function () {});
                changed();
                sync();
                if (navigator.serviceWorker && window.SyncManager) {
                    navigator.serviceWorker.getRegistration(config.scope).then(function (registration) {
                        if (registration && registration.sync) return registration.sync.register('memex-capture-sync');
                    }).catch(function () {});
                }
            } catch (error) { status.textContent = 'Not saved: ' + error.message; }
            finally { saving = false; button.disabled = false; }
        });
    }
    async function worker() {
        if (!window.isSecureContext || !navigator.serviceWorker) { ready = 'Unavailable (requires HTTPS and service worker support)'; refresh(); return; }
        try {
            navigator.serviceWorker.addEventListener('message', function (event) {
                if (!event.data) return;
                if (event.data.type === 'memex-capture-sync') sync();
                if (event.data.type === 'memex-capture-status') { ready = event.data.ready ? 'Ready — capture screen cached' : 'Not ready — open online first'; refresh(); }
            });
            var registration = await navigator.serviceWorker.getRegistration(config.scope);
            if (navigator.onLine || !registration) registration = await navigator.serviceWorker.register(config.workerUrl, {scope: config.scope});
            function check() {
                if (registration.active) registration.active.postMessage({type: 'memex-capture-status'});
            }
            navigator.serviceWorker.addEventListener('controllerchange', check);
            check();
            if (!registration.active) { ready = 'Preparing offline capture…'; refresh(); }
        } catch (_) { ready = 'Unavailable — open Memex online to prepare'; refresh(); }
    }
    function init() {
        if (window.BroadcastChannel) { channel = new BroadcastChannel(dbName); channel.onmessage = refresh; }
        document.querySelectorAll('[data-memex-capture]').forEach(bindForm);
        document.querySelectorAll('[data-offline-sync]').forEach(function (button) { button.addEventListener('click', sync); });
        window.addEventListener('online', sync);
        window.addEventListener('offline', refresh);
        window.addEventListener('focus', sync);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) sync(); });
        setInterval(function () { if (!document.hidden) sync(); }, 5000);
        worker();
        sync();
        refresh();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
