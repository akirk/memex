(function () {
    'use strict';
    var config = window.memexOffline;
    if (!config || !config.userId) return;
    var ready = 'Checking…';
    var outbox = new window.WpAppOutbox(Object.assign({}, config, {
        // Preserve queues and drafts saved before the framework migration.
        databaseName: 'memex-capture:' + config.scope + ':' + config.userId,
        sessionAction: 'memex_capture_session', syncAction: 'memex_capture_sync', payloadField: 'capture',
        acknowledge: function (receipt, entry) { return receipt.id === entry.id && !!receipt.postId; }
    }));
    var rows = outbox.rows, transaction = outbox.transaction, sync = outbox.sync;
    function setText(selector, text) {
        document.querySelectorAll(selector).forEach(function (node) { node.textContent = text; });
    }
    async function refresh() {
        setText('[data-offline-connection]', navigator.onLine ? 'Online' : 'Offline');
        document.querySelectorAll('[data-offline-page-notice]').forEach(function (node) { node.hidden = navigator.onLine; });
        setText('[data-offline-ready]', ready);
        try {
            var entries = await rows('entries');
            var meta = await rows('meta');
            var last = meta.find(function (item) { return item.id === 'lastSync'; });
            document.querySelectorAll('[data-offline-login]').forEach(function (node) { node.hidden = !outbox.status().error; });
            setText('[data-offline-count]', entries.length ? '(' + entries.length + ')' : '');
            setText('[data-offline-pending]', String(entries.length));
            setText('[data-offline-last]', last ? new Date(last.value).toLocaleString() : 'None yet');
            document.querySelectorAll('[data-offline-saved]').forEach(function (node) {
                node.hidden = !last || !last.url;
                if (last && last.url) node.href = last.url;
            });
            setText('[data-offline-status]', outbox.status().error || (outbox.status().running ? 'Syncing…' : entries.length ? 'Saved on this device. Waiting to sync.' : 'No pending entries.'));
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
    function changed() { outbox.changed(); }
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
                await outbox.enqueue(entry, draftId, function () { return JSON.stringify(snapshot()) === JSON.stringify(before); });
                if (JSON.stringify(snapshot()) === JSON.stringify(before)) {
                    content.value = '';
                    if (title) title.value = '';
                }
                status.textContent = 'Saved on this device. Check Offline & sync for server confirmation.';
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
    var pageRegistration;
    async function refreshPages(remember) {
        if (!pageRegistration || !window.wpAppOfflinePages) return;
        try {
            var warning = '';
            if (remember && navigator.onLine) {
                try { await window.wpAppOfflinePages.remember(pageRegistration, config.userId); }
                catch (error) { warning = 'Could not refresh this page for offline use: ' + error.message; }
            }
            var pages = await window.wpAppOfflinePages.list(pageRegistration, config.userId);
            setText('[data-offline-pages-count]', '(' + pages.length + ')');
            setText('[data-offline-pages-status]', warning || (pages.length ? 'Saved pages are available on this device.' : 'No pages saved yet. Open a page online to prepare it.'));
            document.querySelectorAll('[data-offline-pages]').forEach(function (list) {
                list.replaceChildren();
                pages.forEach(function (page) {
                    var item = document.createElement('li');
                    var link = document.createElement('a');
                    link.href = page.url;
                    link.textContent = page.title;
                    item.append(link);
                    list.append(item);
                });
            });
        } catch (error) {
            setText('[data-offline-pages-count]', '');
            setText('[data-offline-pages-status]', error.message);
            document.querySelectorAll('[data-offline-pages]').forEach(function (list) { list.replaceChildren(); });
        }
    }
    async function worker() {
        if (config.workerSupported === false) {
            ready = 'Unavailable — wp-app update required';
            setText('[data-offline-pages-status]', ready);
            refresh();
            return;
        }
        if (!window.isSecureContext || !navigator.serviceWorker) { ready = 'Unavailable (requires HTTPS and service worker support)'; refresh(); return; }
        try {
            navigator.serviceWorker.addEventListener('message', function (event) {
                if (!event.data) return;
                if (event.data.type === 'memex-capture-sync') sync();
                if (event.data.type === 'memex-capture-status') { ready = event.data.ok ? 'Ready — capture screen cached' : 'Not ready — open online first'; refresh(); }
            });
            var registration = await navigator.serviceWorker.getRegistration(config.scope);
            if (navigator.onLine || !registration) registration = await navigator.serviceWorker.register(config.workerUrl, {scope: config.scope});
            pageRegistration = registration;
            function check() {
                refreshPages(true);
                if (registration.active) registration.active.postMessage({type: 'memex-capture-cache', urls: config.requiredUrls, requiredUrls: config.requiredUrls});
            }
            navigator.serviceWorker.addEventListener('controllerchange', check);
            check();
            if (!registration.active) { ready = 'Preparing offline capture…'; refresh(); }
        } catch (_) { ready = 'Unavailable — open Memex online to prepare'; refresh(); }
    }
    function init() {
        outbox.subscribe(refresh);
        document.addEventListener('submit', function (event) {
            if (navigator.onLine || event.target.matches('[data-memex-capture]')) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            var notice = event.target.querySelector('[data-offline-form-notice]');
            if (!notice) {
                notice = document.createElement('p');
                notice.setAttribute('data-offline-form-notice', '');
                notice.setAttribute('role', 'status');
                event.target.append(notice);
            }
            notice.textContent = 'This action needs a connection. Keep this page open to preserve your changes.';
        }, true);
        document.querySelectorAll('[data-memex-capture]').forEach(bindForm);
        document.querySelectorAll('[data-offline-sync]').forEach(function (button) { button.addEventListener('click', sync); });
        window.addEventListener('offline', refresh);
        window.addEventListener('online', function () { refreshPages(true); });
        window.addEventListener('focus', function () { refreshPages(false); });
        document.querySelectorAll('[data-offline-pages-panel]').forEach(function (panel) { panel.addEventListener('toggle', function () { if (panel.open) refreshPages(false); }); });
        worker();
        sync();
        refresh();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
