const {test} = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const worker = execFileSync('php', [path.join(__dirname, 'offline-worker-fixture.php')], {encoding: 'utf8', env: {...process.env, TEST_APP_SHELL: '1'}});
const shellContent=execFileSync('php',[path.join(__dirname,'shell-content-fixture.php')],{encoding:'utf8'});
async function fixture(t) {
    const records = new Map([['1', {id:'1', postId:1, slug:'project', title:'Project', content:'Original note', html:'<p>Original note</p>', parent:0, status:'publish', version:'v1', modified:'2026-01-01', tags:[], links:[], editable:true}]]);
    let account = 7; const receipts = new Map(), sent = [];
    const server = http.createServer(async (req, res) => {
        const origin = `http://127.0.0.1:${server.address().port}`, address = new URL(req.url, origin);
        const config = {userId:7, scope:origin+'/memex/', ajaxUrl:origin+'/ajax', timezone:'UTC', workerUrl:origin+'/memex/offline-worker'};
        if (address.pathname === '/memex/offline-worker') { res.setHeader('Content-Type','application/javascript'); return res.end(worker.replaceAll('http://placeholder',origin)); }
        if (address.pathname.startsWith('/assets/')) {
            res.setHeader('Content-Type',address.pathname.endsWith('.css')?'text/css':'application/javascript');
            const name = path.basename(address.pathname);
            const file = ['memex-shell.js','memex.css'].includes(name) ? path.join(__dirname,'../assets',name) : name === 'overtype.js' ? path.join(__dirname,'../assets/vendor/overtype/overtype.min.js') : path.join(__dirname,'../vendor/akirk/wp-app/assets',address.pathname.slice('/assets/'.length));
            return res.end(fs.readFileSync(file));
        }
        if (address.pathname === '/bootstrap') { res.setHeader('Content-Type','application/json'); return res.end(JSON.stringify({...config,userId:account})); }
        if (address.pathname === '/ajax') {
            res.setHeader('Content-Type','application/json');
            let body=''; for await (const chunk of req) body += chunk;
            const params = req.method === 'POST' ? new URLSearchParams(body) : address.searchParams;
            const action = params.get('action');
            const success = data => res.end(JSON.stringify({success:true,data}));
            if (action === 'memex_capture_session') return success({userId:account,nonce:'fresh'});
            if (action === 'memex_shell_notes') {
                const id=params.get('id');
                if (id) { const note=records.get(id)||Array.from(records.values()).find(n=>n.slug===id); if(note) return success({userId:account,note}); res.statusCode=404; return res.end(JSON.stringify({success:false,data:{message:'Not found'}})); }
                return success({userId:account,notes:Array.from(records.values()).map(({content,html,...note})=>note),next:null});
            }
            const entry=JSON.parse(params.get('capture')); sent.push(entry);
            if (receipts.has(entry.id)) return success(receipts.get(entry.id));
            if (entry.kind==='note') {
                const id=String(records.size+1), note={...records.get('1'),id,postId:Number(id),title:entry.title,content:entry.content,html:'<p>'+entry.content+'</p>',version:'v1',status:'draft'};records.set(id,note);
                const receipt={id:entry.id,postId:Number(id),note};receipts.set(entry.id,receipt);return success(receipt);
            }
            const prior=receipts.get(entry.baseOperation), target=String(Number(entry.noteId)||prior&&prior.postId), note=records.get(target);
            const baseline=prior?prior.note.version:entry.baseVersion;
            if (!note || baseline!==note.version) {res.statusCode=409;return res.end(JSON.stringify({success:false,data:{code:'conflict',message:'Server changed; review both versions.',note}}));}
            const saved={...note,title:entry.title,content:entry.content,html:'<p>'+entry.content+'</p>',version:'v'+(Number(note.version.slice(1))+1)};records.set(target,saved);
            const receipt={id:entry.id,postId:Number(target),note:saved};receipts.set(entry.id,receipt);return success(receipt);
        }
        const settings={url:origin+'/bootstrap',offline:config,worker:{url:config.workerUrl,scope:config.scope}};
        const generated=worker.replaceAll('http://placeholder',origin), workerConfig=JSON.parse(generated.split('\n')[0].slice('self.wpAppWorker = '.length,-1));
        Object.assign(settings.worker,{cacheMessageType:workerConfig.cacheMessageType,cacheStatusMessageType:workerConfig.cacheStatusMessageType,cacheName:workerConfig.cacheName,offlineUrl:workerConfig.offlineUrl,requiredUrls:workerConfig.precache});
        const runtimes=workerConfig.precache.filter(url=>url.includes('/assets/offline/'));
        res.setHeader('Content-Type','text/html');res.setHeader('X-Wp-App-Offline','7');
        res.end(`<!doctype html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/memex.css">${runtimes.map(url=>'<script src="'+url+'"></script>').join('')}</head><body class="memex"><div data-wp-app-bootstrap='${JSON.stringify(settings)}'>${shellContent.replaceAll('http://placeholder',origin).replace('data-shell-offline>', 'data-shell-offline open>')}</div><script src="/assets/overtype.js"></script><script src="/assets/memex-shell.js"></script></body>`);
    });
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
    const browser=await chromium.launch(), context=await browser.newContext(), page=await context.newPage();
    t.after(async()=>{await browser.close();await new Promise(resolve=>server.close(resolve));});
    await page.goto(`http://127.0.0.1:${server.address().port}/memex/`);
    await page.waitForFunction(()=>document.querySelector('[data-shell-view]').textContent.includes('Project'));
    await page.waitForFunction(()=>navigator.serviceWorker.controller);
    await page.waitForFunction(()=>document.querySelector('[data-shell-cache-status]').textContent==='App shell is available offline.');
    return {page,context,records,sent,account(value){account=value;},async go(route){await page.goto(new URL('/memex/'+route,page.url()).href);}};
}
async function edit(f,title,content) {
    await f.page.locator('input[name=title]').fill(title);
    await f.page.locator('.overtype-input').fill(content);
    await f.page.getByRole('button',{name:'Save note',exact:true}).click();
    await f.page.waitForFunction(()=>!document.querySelector('[data-shell-editor]'));
}
test('opened notes have an editor offline without caching the editor page; edits survive a cold reload and sync',async t=>{
    const f=await fixture(t);await f.go('note/1');await f.page.getByRole('heading',{name:'Project',exact:true}).waitFor();
    await f.context.setOffline(true);await f.go('edit/1');await f.page.locator('.overtype-input').waitFor();
    await edit(f,'Offline project','Written offline');await f.page.reload();
    await f.page.waitForFunction(()=>document.querySelector('[data-shell-view]').textContent.includes('Written offline'));
    assert.match(await f.page.locator('[data-shell-pending]').innerText(),/1/);
    if(process.env.SHELL_SCREENSHOTS){await f.page.screenshot({path:'/tmp/memex-shell-desktop.png',fullPage:true});await f.page.setViewportSize({width:390,height:844});await f.page.screenshot({path:'/tmp/memex-shell-mobile.png',fullPage:true});}
    await f.context.setOffline(false);await f.page.getByRole('button',{name:'Sync now'}).click();
    await f.page.waitForFunction(()=>document.querySelector('[data-shell-pending]').textContent==='');
    assert.equal(f.records.get('1').content,'Written offline');
});
test('conflicting edits retain local content and require explicit review before overwriting the server',async t=>{
    const f=await fixture(t);await f.go('note/1');await f.page.getByRole('heading',{name:'Project',exact:true}).waitFor();await f.context.setOffline(true);await f.go('edit/1');await edit(f,'My title','My local edit');
    f.records.set('1',{...f.records.get('1'),content:'Other device edit',html:'<p>Other device edit</p>',version:'v2'});
    await f.context.setOffline(false);await f.page.getByRole('button',{name:'Sync now'}).click();await f.page.getByRole('link',{name:'Review versions'}).waitFor();
    assert.equal(f.records.get('1').content,'Other device edit');await f.page.getByRole('link',{name:'Review versions'}).click();await f.page.locator('[data-shell-conflict]').waitFor();
    assert.match(await f.page.locator('[data-shell-conflict]').innerText(),/Other device edit/);
    assert.equal(await f.page.locator('.overtype-input').inputValue(),'My local edit');
    await f.page.getByRole('button',{name:'Save my reviewed version'}).click();
    await f.page.waitForFunction(()=>document.querySelector('[data-shell-pending]').textContent==='');
    assert.equal(f.records.get('1').content,'My local edit');
    assert.equal(await f.page.locator('[data-shell-available-count]').textContent(),'(1)');
});
test('multiple offline edits replay in order using the preceding acknowledgment as the baseline',async t=>{
    const f=await fixture(t);await f.go('note/1');await f.page.getByRole('heading',{name:'Project',exact:true}).waitFor();await f.context.setOffline(true);await f.go('edit/1');await edit(f,'First edit','First edit');await f.go('edit/1');await edit(f,'Second edit','Second edit');
    await f.context.setOffline(false);await f.page.getByRole('button',{name:'Sync now'}).click();await f.page.waitForFunction(()=>document.querySelector('[data-shell-pending]').textContent==='');assert.equal(f.records.get('1').content,'Second edit');assert.ok(f.sent[1].baseOperation);
});
test('offline drafts restore and a changed signed-in account cannot open another account local data',async t=>{
    const f=await fixture(t);await f.go('note/1');await f.page.getByRole('heading',{name:'Project',exact:true}).waitFor();await f.context.setOffline(true);await f.go('edit/1');await f.page.locator('.overtype-input').fill('Unsubmitted draft');await f.page.getByText('Draft saved on this device.',{exact:true}).waitFor();await f.page.reload();await f.page.locator('.overtype-input').waitFor();assert.equal(await f.page.locator('.overtype-input').inputValue(),'Unsubmitted draft');
    f.account(8);await f.context.setOffline(false);await f.page.reload();await f.page.waitForFunction(()=>document.querySelector('[data-shell-status]').textContent.includes('account changed'));assert.doesNotMatch(await f.page.locator('[data-shell-view]').innerText(),/Unsubmitted draft/);
});


test('a new offline note can be edited again before its server ID exists',async t=>{
    const f=await fixture(t);await f.context.setOffline(true);await f.go('new');await f.page.locator('input[name=title]').fill('Local creation');await f.page.locator('.overtype-input').fill('Initial local content');await f.page.getByRole('button',{name:'Save draft',exact:true}).click();await f.page.getByRole('link',{name:'Edit note',exact:true}).click();await edit(f,'Local creation','Updated before upload');
    await f.context.setOffline(false);await f.page.getByRole('button',{name:'Sync now'}).click();await f.page.waitForFunction(()=>document.querySelector('[data-shell-pending]').textContent==='');assert.equal(f.records.get('2').content,'Updated before upload');assert.equal(f.records.size,2);assert.equal(new URL(f.page.url()).pathname,'/memex/note/2');
});

test('separate tabs preserve independent drafts for the same note',async t=>{
    const f=await fixture(t);await f.go('edit/1');await f.page.locator('.overtype-input').fill('First tab draft');await f.page.getByText('Draft saved on this device.',{exact:true}).waitFor();
    const second=await f.context.newPage();await second.goto(new URL('/memex/edit/1',f.page.url()).href);await second.locator('.overtype-input').fill('Second tab draft');await second.getByText('Draft saved on this device.',{exact:true}).waitFor();
    await f.page.reload();await f.page.locator('.overtype-input').waitFor();assert.equal(await f.page.locator('.overtype-input').inputValue(),'First tab draft');assert.equal(await second.locator('.overtype-input').inputValue(),'Second tab draft');
});

test('locally rendered Markdown cannot execute scripts or javascript links',async t=>{
    const f=await fixture(t);await f.go('note/1');await f.page.getByRole('heading',{name:'Project',exact:true}).waitFor();await f.context.setOffline(true);await f.go('edit/1');await edit(f,'Unsafe markup','<script>window.memexInjected=true</script>\n\n[bad](javascript:alert(1))');await f.page.waitForFunction(()=>document.querySelector('[data-shell-view] h1').textContent==='Unsafe markup');
    assert.equal(await f.page.evaluate(()=>!!window.memexInjected),false);assert.equal(await f.page.locator('[data-shell-view] script, [data-shell-view] a[href^="javascript:"]').count(),0);
});


test('an editor opened before a prior save is acknowledged retains the operation baseline',async t=>{
    const f=await fixture(t);await f.go('note/1');await f.page.getByRole('heading',{name:'Project',exact:true}).waitFor();await f.context.setOffline(true);await f.go('edit/1');await edit(f,'First edit','First edit');await f.go('edit/1');await f.page.locator('.overtype-input').fill('Second edit while first syncs');await f.page.getByText('Draft saved on this device.',{exact:true}).waitFor();
    await f.context.setOffline(false);await f.page.waitForFunction(()=>document.querySelector('[data-shell-pending]').textContent==='');
    await f.page.getByRole('button',{name:'Save note',exact:true}).click();await f.page.waitForFunction(()=>!document.querySelector('[data-shell-editor]'));await f.page.waitForFunction(()=>document.querySelector('[data-shell-pending]').textContent==='');assert.equal(f.records.get('1').content,'Second edit while first syncs');assert.equal(await f.page.locator('[data-shell-conflicts] li').count(),0);
});

test('failed local draft writes keep the editor open when navigating within the app',async t=>{
    const f=await fixture(t);await f.go('edit/1');await f.page.locator('.overtype-input').waitFor();
    await f.page.evaluate(()=>{const original=IDBObjectStore.prototype.put;IDBObjectStore.prototype.put=function(){if(this.name==='drafts')throw new DOMException('Storage quota exceeded','QuotaExceededError');return original.apply(this,arguments);};});
    await f.page.locator('.overtype-input').fill('Keep this unsaved text');await f.page.getByText('Could not save locally. Keep this page open and copy your text.',{exact:true}).waitFor();await f.page.getByRole('link',{name:'All notes',exact:true}).click();
    assert.equal(new URL(f.page.url()).pathname,'/memex/edit/1');assert.equal(await f.page.locator('.overtype-input').inputValue(),'Keep this unsaved text');
});
