// Browser integration checks: requires Playwright and its Chromium browser.
// Run: node --test tests/OfflineCaptureTest.cjs
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {chromium} = require('playwright');
const asset = name => fs.readFileSync(path.join(__dirname, '..', 'assets', name));

async function fixture(t) {
    let account = 7;
    let mode = 'ok';
    let posts = [];
    const server = http.createServer(async (req, res) => {
        const origin = `http://127.0.0.1:${server.address().port}`;
        const config = {ajaxUrl: origin + '/ajax', scope: origin + '/memex/', workerUrl: origin + '/memex/offline-worker', captureUrl: origin + '/memex/capture', userId: 7, timezone: 'Pacific/Kiritimati'};
        if (req.url === '/memex/offline-worker') {
            res.setHeader('Content-Type', 'application/javascript');
            return res.end('self.memexWorker = ' + JSON.stringify({cacheName: 'memex-capture-test', captureUrl: config.captureUrl, assets: [origin + '/assets/memex-offline.js']}) + ';\n' + asset('memex-worker.js'));
        }
        if (req.url === '/assets/memex-offline.js') {
            res.setHeader('Content-Type', 'application/javascript');
            return res.end(asset('memex-offline.js'));
        }
        if (req.url === '/ajax') {
            let body = '';
            for await (const chunk of req) body += chunk;
            const data = new URLSearchParams(body);
            res.setHeader('Content-Type', 'application/json');
            if (data.get('action') === 'memex_capture_session') return res.end(JSON.stringify({success: true, data: {userId: account, nonce: 'fresh-nonce'}}));
            const entry = JSON.parse(data.get('capture'));
            posts.push(entry);
            if (mode === 'login') return res.end('<html>Log in</html>');
            if (mode === 'fail') { res.statusCode = 503; return res.end(JSON.stringify({success: false, data: {message: 'Temporary failure'}})); }
            return res.end(JSON.stringify({success: true, data: {id: mode === 'wrong-id' ? 'wrong-id' : entry.id, postId: 42, url: origin + '/memex/note/42'}}));
        }
        if (req.url !== '/memex/capture') { res.statusCode = 404; return res.end('Private page not cached'); }
        res.setHeader('Content-Type', 'text/html');
        res.end(`<!doctype html><title>Capture test</title>
            <form data-memex-capture="choice"><select name="kind"><option value="daily">Daily</option><option value="note">Note</option></select>
            <label data-capture-title hidden><input name="title"></label><textarea name="content" required></textarea>
            <button type="submit">Save capture</button><p data-capture-status></p></form>
            <details data-memex-offline-panel open><span data-offline-ready></span><span data-offline-count></span><span data-offline-connection></span>
            <span data-offline-pending></span><span data-offline-last></span><p data-offline-status></p><a data-offline-login hidden>Login</a><a data-offline-saved hidden></a>
            <button data-offline-sync>Sync now</button><ul data-offline-entries></ul></details>
            <script>window.memexOffline=${JSON.stringify(config)};</script><script src="/assets/memex-offline.js"></script>`);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const browser = await chromium.launch({headless: true});
    const context = await browser.newContext();
    const page = await context.newPage();
    t.after(async () => { await browser.close(); await new Promise(resolve => server.close(resolve)); });
    await page.goto(`http://127.0.0.1:${server.address().port}/memex/capture`);
    await page.waitForFunction(() => document.querySelector('[data-offline-ready]').textContent.startsWith('Ready'));
    const pending = count => page.waitForFunction(n => document.querySelector('[data-offline-pending]').textContent === String(n), count);
    return {page, context, posts, pending, account(value) {account = value;}, mode(value) {mode = value;}};
}

test('offline drafts survive reload; queued notes survive closing and sync on reconnect', async t => {
    const f = await fixture(t);
    await f.context.setOffline(true);
    await f.page.selectOption('[name=kind]', 'note');
    await f.page.fill('[name=title]', 'Offline title');
    await f.page.fill('[name=content]', '**Durable** note');
    await f.page.waitForFunction(() => document.querySelector('[data-capture-status]').textContent === 'Draft saved on this device.');
    await f.page.reload();
    await f.page.waitForFunction(() => document.querySelector('[name=content]').value === '**Durable** note');
    assert.equal(await f.page.inputValue('[name=title]'), 'Offline title');
    assert.equal(await f.page.inputValue('[name=kind]'), 'note');
    await f.page.click('button[type=submit]');
    await f.pending(1);
    assert.equal(await f.page.inputValue('[name=content]'), '');
    const url = f.page.url();
    await f.page.close();
    f.page = await f.context.newPage();
    await f.page.goto(url);
    await f.page.waitForFunction(() => document.querySelector('[data-offline-pending]').textContent === '1');
    assert.match(await f.page.textContent('[data-offline-entries]'), /Durable/);
    await f.context.setOffline(false);
    await f.page.waitForFunction(() => document.querySelector('[data-offline-pending]').textContent === '0');
    assert.equal(f.posts.length, 1);
    assert.equal(f.posts[0].kind, 'note');
    assert.equal(f.posts[0].title, 'Offline title');
});

test('failed responses, login HTML, and wrong acknowledgments retain the same operation for retry', async t => {
    const f = await fixture(t);
    for (const failure of ['fail', 'login', 'wrong-id']) {
        f.mode(failure);
        await f.page.fill('[name=content]', failure);
        await f.page.click('button[type=submit]');
        await f.page.waitForFunction(() => document.querySelector('[data-offline-login]').hidden === false);
        await f.pending(1);
        const id = f.posts.at(-1).id;
        f.mode('ok');
        await f.page.click('[data-offline-sync]');
        await f.pending(0);
        assert.equal(f.posts.at(-1).id, id);
    }
});

test('different logged-in account never receives queued content', async t => {
    const f = await fixture(t);
    f.account(99);
    await f.page.fill('[name=content]', 'Account seven private entry');
    await f.page.click('button[type=submit]');
    await f.page.waitForFunction(() => document.querySelector('[data-offline-status]').textContent.includes('account that saved'));
    await f.pending(1);
    assert.equal(f.posts.length, 0);
    f.account(7);
    await f.page.click('[data-offline-sync]');
    await f.pending(0);
    assert.equal(f.posts.length, 1);
});

test('capture uses the site timezone at submission and survives offline fallback from an uncached daily', async t => {
    const f = await fixture(t);
    await f.context.setOffline(true);
    await f.page.goto(new URL('/memex/daily/2020-01-01', f.page.url()).href);
    await f.page.fill('[name=content]', 'Site date');
    const expected = await f.page.evaluate(() => {
        const now = new Date();
        const values = {};
        new Intl.DateTimeFormat('en-CA', {timeZone: 'Pacific/Kiritimati', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23'}).formatToParts(now).forEach(part => values[part.type] = part.value);
        return {date: `${values.year}-${values.month}-${values.day}`, clock: `${values.hour}:${values.minute}`};
    });
    await f.page.click('button[type=submit]');
    await f.pending(1);
    await f.context.setOffline(false);
    await f.pending(0);
    assert.equal(f.posts[0].date, expected.date);
    assert.equal(f.posts[0].clock, expected.clock);
});


test('simultaneous tabs serialize replay and private navigation responses are never cached', async t => {
    const f = await fixture(t);
    await f.context.setOffline(true);
    await f.page.fill('[name=content]', 'One queued entry');
    await f.page.click('button[type=submit]');
    await f.pending(1);
    const second = await f.context.newPage();
    await second.goto(f.page.url());
    await second.waitForFunction(() => document.querySelector('[data-offline-pending]').textContent === '1');
    await f.context.setOffline(false);
    await Promise.all([f.page.click('[data-offline-sync]'), second.click('[data-offline-sync]')]);
    await f.pending(0);
    assert.equal(f.posts.length, 1);
    await f.page.goto(new URL('/memex/note/private', f.page.url()).href);
    const urls = await f.page.evaluate(async () => {
        const result = [];
        for (const name of await caches.keys()) for (const request of await (await caches.open(name)).keys()) result.push(request.url);
        return result;
    });
    assert.equal(urls.some(url => url.includes('/note/private')), false);
});
