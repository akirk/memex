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
                const id=String(records.size+1), note={...records.get('1'),id,postId:Number(id),slug:entry.daily?'daily-'+entry.daily:'note-'+id,daily:entry.daily||'',title:entry.title,content:entry.content,html:'<p>'+entry.content+'</p>',version:'v1',status:entry.publish?'publish':'draft'};records.set(id,note);
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
    await f.page.locator('[data-shell-view] input[name=title]').fill(title);
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
    const f=await fixture(t);await f.context.setOffline(true);await f.go('new');await f.page.locator('[data-shell-view] input[name=title]').fill('Local creation');await f.page.locator('.overtype-input').fill('Initial local content');await f.page.getByRole('button',{name:'Save draft',exact:true}).click();await f.page.getByRole('link',{name:'Edit note',exact:true}).click();await edit(f,'Local creation','Updated before upload');
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

test('sidebar lists only folders and collapses nested branches', {timeout:15000}, async t => {
    const f = await fixture(t);
    const base = f.records.get('1');
    f.records.set('2', {...base,id:'2',postId:2,slug:'subfolder',title:'Subfolder',parent:1});
    f.records.set('3', {...base,id:'3',postId:3,slug:'leaf',title:'Leaf note',parent:2});
    await f.page.reload();
    const folders = f.page.locator('[data-shell-folders]');
    await folders.getByRole('link', {name:'Subfolder',exact:true,includeHidden:true}).waitFor({state:'attached'});
    assert.equal(await folders.getByRole('link', {name:'Leaf note',exact:true,includeHidden:true}).count(), 0);
    assert.equal(await folders.locator('details').count(), 1);
    assert.equal(await folders.locator('details').evaluate(el => el.open), false);
});


test('capture matches main: sidebar textarea, clock, plain task marker, then inline input on today', {timeout:20000}, async t => {
    const f=await fixture(t);
    await f.context.setOffline(true);
    const sidebar=f.page.locator('[data-shell-quick-capture]');
    const area=sidebar.locator('textarea[name=content]');
    await area.fill('Remember this');
    assert.equal(await area.getAttribute('rows'),'2');
    await sidebar.getByRole('button',{name:'Toggle checkbox at start of entry'}).click();
    assert.equal(await area.inputValue(),'[ ] Remember this');
    assert.match(await sidebar.locator('time').textContent(),/^\d{2}:\d{2}$/);
    await f.page.getByRole('link',{name:'Today',exact:true}).click();
    const input=f.page.locator('#selected-note-content input[name=content]');
    await input.waitFor();
    assert.equal(await input.inputValue(),'[ ] Remember this');
    assert.equal(await sidebar.locator('form').count(),0);
    await f.page.reload();
    await f.page.waitForFunction(()=>document.querySelector('#selected-note-content input[name=content]')?.value==='[ ] Remember this');
    await f.page.getByRole('button',{name:'Capture',exact:true}).click();
    await f.page.locator('#selected-note-content p').filter({hasText:'[ ] Remember this'}).waitFor();
    assert.equal(f.sent.length,0);
    await f.page.getByRole('link',{name:'All notes',exact:true}).click();
    await sidebar.locator('textarea').waitFor();
    assert.equal(await sidebar.locator('textarea').inputValue(),'');
});

test('daily notes retain calendar, existing-day links, backfill drafts, and no future links', {timeout:20000}, async t => {
    const f=await fixture(t),today=new Date().toISOString().slice(0,10),month=today.slice(0,7),past=month+'-01';
    f.records.set('2',{...f.records.get('1'),id:'2',postId:2,slug:'daily-'+today,title:today,daily:today,content:'Existing daily entry',html:'<p>Existing daily entry</p>'});
    await f.page.reload();await f.page.getByRole('link',{name:'Today',exact:true}).click();
    await f.page.locator('#selected-note-content').filter({hasText:'Existing daily entry'}).waitFor();
    assert.match(new URL(f.page.url()).pathname,/note\/daily-/);
    await f.page.locator('#selected-note-content input[name=content]').waitFor();
    const calendar=f.page.getByRole('region',{name:'Daily notes calendar'});
    await calendar.getByRole('link',{name:'Open daily note for '+today,exact:true}).waitFor();
    assert.equal(await calendar.locator('[aria-label="Next month"]').getAttribute('aria-disabled'),'true');
    await calendar.getByRole('link',{name:'Write a draft daily note for '+past,exact:true}).click();
    await f.page.getByText('Unsaved daily draft. Save to keep it unpublished, or publish when ready.',{exact:true}).waitFor();
    assert.equal(await f.page.locator('[data-shell-view] input[name=title]').inputValue(),past);
    assert.equal(f.records.size,2);assert.equal(f.sent.length,0);
    await f.page.getByRole('button',{name:'Publish',exact:true}).waitFor();
    await f.context.setOffline(true);
    await f.page.locator('.overtype-input').fill('Backfilled daily');
    await f.page.getByRole('button',{name:'Save draft',exact:true}).click();
    await f.page.waitForFunction(()=>!document.querySelector('[data-shell-editor]'));
    await f.context.setOffline(false);
    await f.page.waitForFunction(()=>document.querySelector('[data-shell-pending]').textContent==='');
    assert.equal(f.sent[0].daily,past);assert.equal(f.sent[0].publish,false);
});

test('Orphans retains the shared navigation and includes notes with outgoing but no incoming links', {timeout:20000}, async t => {
    const f=await fixture(t),base=f.records.get('1');
    f.records.set('1',{...base,links:[2]});f.records.set('2',{...base,id:'2',postId:2,slug:'target',title:'Target',links:[]});
    await f.page.reload();
    const before=await f.page.locator('.memex-nav a').allTextContents();
    assert.deepEqual(before,['All notes','Today','Reminders','Search','Graph','Tags','Orphans','Broken links','Import','Export']);
    await f.page.getByRole('link',{name:'Orphans',exact:true}).click();
    await f.page.getByRole('heading',{name:'Orphan notes',exact:true}).waitFor();
    await f.page.locator('[data-shell-view]').getByRole('link',{name:'Project',exact:true}).waitFor();
    assert.equal(await f.page.locator('[data-shell-view]').getByRole('link',{name:'Target',exact:true}).count(),0);
    assert.deepEqual(await f.page.locator('.memex-nav a').allTextContents(),before);
});

test('graph preserves main linked-note filtering, counts, and unlinked list', {timeout:20000}, async t => {
    const f=await fixture(t),base=f.records.get('1');
    f.records.set('1',{...base,links:[1,2,2,999]});f.records.set('2',{...base,id:'2',postId:2,title:'Beta',links:[]});f.records.set('3',{...base,id:'3',postId:3,title:'Lonely',links:[]});
    await f.go('graph');
    await f.page.getByText('2 linked notes, 1 links; 1 notes without links are not shown.',{exact:true}).waitFor();
    const graph=JSON.parse(await f.page.locator('[data-graph]').getAttribute('data-graph'));
    assert.deepEqual(graph.nodes.map(note=>note.id),['1','2']);assert.deepEqual(graph.edges,[{from:'1',to:'2'}]);
    await f.page.locator('.memex-graph-unlinked').getByRole('link',{name:'Lonely',exact:true}).waitFor();
    f.records.delete('2');await f.page.reload();
    await f.page.getByText('0 linked notes, 0 links; 2 notes without links are not shown.',{exact:true}).waitFor();
    assert.equal(await f.page.locator('[data-graph]').count(),0);
    await f.page.getByRole('link',{name:'by tag',exact:true}).waitFor();
});

test('graph shows the latest ten unlinked notes and its original empty state', {timeout:20000}, async t => {
    const f=await fixture(t),base=f.records.get('1');f.records.clear();
    for(let i=1;i<=12;i++)f.records.set(String(i),{...base,id:String(i),postId:i,title:'Note '+i,links:[],created:'2026-01-'+String(i).padStart(2,'0')+' 12:00:00'});
    await f.go('graph');await f.page.getByRole('heading',{name:'Latest 10 of 12 notes without links',exact:true}).waitFor();
    assert.equal(await f.page.locator('.memex-graph-unlinked li').count(),10);
    assert.equal(await f.page.locator('.memex-graph-unlinked li').first().textContent(),'Note 12');
    assert.equal(await f.page.locator('.memex-graph-unlinked').getByRole('link',{name:'Note 2',exact:true}).count(),0);
    f.records.clear();await f.page.reload();await f.page.getByText('No notes to graph yet.',{exact:true}).waitFor();
});

test('older dailies retain sidebar capture and the calendar follows leap years and month selection', {timeout:20000}, async t => {
    const f=await fixture(t),base=f.records.get('1');
    f.records.set('2',{...base,id:'2',postId:2,slug:'daily-2024-02-29',daily:'2024-02-29',title:'February day'});
    await f.go('note/2?month=2024-02');await f.page.getByRole('heading',{name:'February day daily',exact:true}).waitFor();
    const calendar=f.page.locator('[data-daily-calendar]');
    await calendar.getByRole('link',{name:'Open daily note for 2024-02-29',exact:true}).waitFor();
    assert.equal(await calendar.locator('[data-calendar-date="2024-02-30"]').count(),0);
    await f.page.locator('[data-shell-quick-capture] textarea').waitFor();
    assert.equal(await f.page.locator('#selected-note-content form').count(),0);
    await calendar.getByRole('link',{name:'Previous month',exact:true}).click();
    assert.equal(await calendar.getAttribute('data-month'),'2024-01');
    await calendar.getByRole('link',{name:'Next month',exact:true}).click();
    assert.equal(await calendar.getAttribute('data-month'),'2024-02');
});
