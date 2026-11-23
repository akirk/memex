/* Memex renders from local records; wp-app owns shell boot, storage, and replay. */
(function () {
    'use strict';
    var root = document.querySelector('[data-memex-shell]');
    if (!root) return;
    document.documentElement.classList.add('memex-js');
    window.addEventListener('wp-app:offline-status', function(event){var message=root.querySelector('[data-shell-cache-status]');if(message)message.textContent=event.detail.ready?'App shell is available offline.':'Offline app is not ready yet. Open Memex online to prepare it.';});
    var config, outbox, data, notes = [], index = [], entries = [], activeEditor, generation = 0;
    var offline = false;
    var draftWrites = Promise.resolve(), pendingDraftWrites = 0, draftFailure = false, lastPath = location.href, lastMessage = '';
    var view = root.querySelector('[data-shell-view]');
    function node(tag, text, attrs) {
        var el = document.createElement(tag);
        if (text !== undefined && text !== null) el.textContent = text;
        Object.keys(attrs || {}).forEach(key => el.setAttribute(key, attrs[key]));
        return el;
    }
    function status(text) { root.querySelector('[data-shell-status]').textContent = text || ''; }
    function uuid() { return crypto.randomUUID ? crypto.randomUUID() : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, digit => (digit ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> digit / 4).toString(16)); }
    function canRefreshView() { return !activeEditor || (activeEditor.clean && activeEditor.clean()); }
    function saveDraftRecord(value) {
        pendingDraftWrites++;
        draftWrites = draftWrites.catch(function(){}).then(function(){return outbox.transaction(['drafts'],'readwrite',tx=>tx.objectStore('drafts').put(value));}).then(function(){pendingDraftWrites--;draftFailure=false;},function(error){pendingDraftWrites--;draftFailure=true;status('Local saving failed. Keep this page open and copy your text.');throw error;});
        draftWrites.catch(function(){});
        return draftWrites;
    }
    function url(path) { return new URL(path || '', config.scope).href; }
    function link(text, path) { return node('a', text, {href: url(path), 'data-route': path}); }
    function allNotes() {
        var merged = new Map(index.map(note => [String(note.postId || note.id), note]));
        notes.forEach(function (note) {
            var key = String(note.postId || note.id), metadata = merged.get(key);
            // Refresh previews without changing the cached body's edit baseline.
            merged.set(key, !note.pending && metadata ? Object.assign({}, note, {excerpt: metadata.excerpt}) : note);
        });
        return Array.from(merged.values());
    }
    function decodeSlug(value) { try { return decodeURIComponent(value || ''); } catch (_) { return value; } }
    function findNote(value) { return allNotes().find(note => String(note.id) === String(value) || String(note.postId) === String(value) || (note.slug === value || decodeSlug(note.slug) === value) || note.title.toLowerCase() === String(value).toLowerCase()); }
    function routeId(note) { return String(note.postId || note.id); }
    function related(entry, note) { return entry.noteId === note.id || entry.localId === note.id || (note.postId && String(entry.noteId) === String(note.postId)); }
    function localNote(value) { var note = findNote(value); return note && notes.find(local => local.id === note.id || (note.postId && local.postId === note.postId)); }
    async function request(action, params) {
        var address = new URL(config.ajaxUrl);
        address.searchParams.set('action', action);
        Object.keys(params || {}).forEach(key => address.searchParams.set(key, params[key]));
        var response = await fetch(address, {credentials: 'same-origin', cache: 'no-store'});
        var result = await response.json();
        if (!response.ok || !result.success) { var error = new Error(result.data && result.data.message || 'Could not load your notes.'); error.status = response.status; throw error; }
        offline = false;
        if (Number(result.data.userId) !== Number(config.userId)) { var error = new Error('The signed-in account changed. Your local notes have not been sent.'); error.accountMismatch = true; throw error; }
        return result.data;
    }
    async function loadLocal() {
        notes = await data.all('notes'); index = await data.all('index'); entries = await outbox.rows('entries');
        // Recover the optimistic view even if the tab closed just after the queue committed.
        for (var entry of entries) {
            var key = entry.localId || entry.noteId;
            if (!key || !['note', 'edit'].includes(entry.kind)) continue;
            var existing = localNote(key) || {id: String(key), postId: Number(entry.noteId) || 0, slug: '', tags: [], links: [], editable: true, status: 'draft'};
            if (!existing.pending || existing.lastOperation !== entry.id) {
                var overlay = Object.assign({}, existing, {title: entry.title, content: entry.content, parent: entry.parent || 0, version: entry.baseVersion || existing.version || '', pending: true, lastOperation: entry.id});
                notes = notes.filter(note => note.id !== overlay.id); notes.push(overlay);
            }
        }
        chrome();
    }
    function chrome() {
        var state = outbox.status();
        root.querySelector('[data-shell-pending]').textContent = entries.length ? '(' + entries.length + ')' : '';
        var syncError = state.error && /Failed to fetch|NetworkError|Load failed/.test(state.error) ? 'Waiting for a connection. Your changes remain on this device.' : state.error;
        root.querySelector('[data-shell-sync-status]').textContent = syncError || (state.running ? 'Syncing…' : entries.length ? entries.length + ' changes saved on this device.' : 'All changes synced.') + (!offline && navigator.onLine ? '' : ' Offline.');
        var available = root.querySelector('[data-shell-available]'); available.replaceChildren();
        notes.slice().sort((a, b) => a.title.localeCompare(b.title)).forEach(note => { var li = node('li'); li.append(link(note.title, 'note/' + encodeURIComponent(routeId(note)))); available.append(li); });
        root.querySelector('[data-shell-available-count]').textContent = '(' + notes.length + ')';
        var conflicts = root.querySelector('[data-shell-conflicts]'); conflicts.replaceChildren();
        entries.filter(entry => entry.state === 'conflict').forEach(entry => {
            var item = node('li'); item.append(node('strong', 'Conflict: ' + entry.title), document.createTextNode(' '), link('Review versions', 'edit/' + encodeURIComponent(entry.noteId))); conflicts.append(item);
        });
        var folders = root.querySelector('[data-shell-folders]'); folders.replaceChildren();
        var all = allNotes(), parentIds = new Set(all.map(note => Number(note.parent || 0)).filter(Boolean));
        var sorted = all.filter(note => parentIds.has(Number(note.postId || note.id))).sort((a, b) => a.title.localeCompare(b.title));
        var folderIds = new Set(sorted.map(note => Number(note.postId || note.id)));
        var currentPath = decodeURIComponent(location.pathname.split('/').pop() || ''), openIds = new Set();
        var current = all.find(note => String(routeId(note)) === currentPath || note.slug === currentPath);
        while (current && !openIds.has(current.id)) { openIds.add(current.id); current = all.find(note => Number(note.postId || note.id) === Number(current.parent) && Number(current.parent) > 0); }
        function branch(parent, visited) {
            var ul = node('ul');
            sorted.filter(note => parent === 0 ? !folderIds.has(Number(note.parent || 0)) : Number(note.parent || 0) === parent).forEach(note => {
                if (visited.has(note.id)) return;
                var seen = new Set(visited); seen.add(note.id);
                var li = node('li'); li.append(link(note.title + (note.pending ? ' •' : ''), 'note/' + encodeURIComponent(routeId(note))));
                var children = branch(Number(note.postId || note.id), seen);
                if (children.childElementCount) { var details = node('details'), summary = node('summary'); summary.append(li.firstChild); details.open = openIds.has(note.id); details.append(summary, children); li.append(details); }
                ul.append(li);
            });
            return ul;
        }
        folders.hidden = !sorted.length;
        if (sorted.length) folders.append(node('h2', 'Folders'), branch(0, new Set()));
    }
    async function refreshIndex() {
        var page = 1, fresh = [];
        do { var result = await request('memex_shell_notes', {page: page}); fresh.push.apply(fresh, result.notes); page = result.next; } while (page);
        await data.replace('index', fresh); index = fresh;
        // Drop clean local bodies no longer readable; pending work is retained for review/export.
        var ids = new Set(fresh.map(note => String(note.id))), drafts = await outbox.rows('drafts');
        for (var note of notes.slice()) {
            if (note.postId && !ids.has(String(note.postId)) && !entries.some(entry => related(entry, note)) && !drafts.some(draft => draft.id.startsWith('shell-edit:' + note.id + ':'))) { await data.remove('notes', note.id); notes = notes.filter(item => item.id !== note.id); }
        }
        chrome();
    }
    async function fetchNote(value) {
        var cached = localNote(value);
        if (!navigator.onLine || (cached && cached.pending)) return cached;
        try {
            var result = await request('memex_shell_notes', {id: value});
            var note = Object.assign({}, result.note, {id: cached ? cached.id : String(result.note.id)});
            await data.put('notes', note); notes = notes.filter(item => item.id !== note.id); notes.push(note); chrome(); return note;
        } catch (error) { if (error.accountMismatch || [401,403,404].includes(error.status)) throw error; if (cached) { status('Showing the saved copy. ' + error.message); return cached; } throw error; }
    }
    function safeHTML(html) {
        var template = document.createElement('template'); template.innerHTML = html;
        var allowed = new Set('P DIV SPAN BR HR H1 H2 H3 H4 H5 H6 UL OL LI STRONG EM B I DEL S CODE PRE BLOCKQUOTE A IMG FIGURE FIGCAPTION LABEL MARK SUP SUB DL DT DD SECTION TIME ABBR TABLE TBODY THEAD TR TH TD DETAILS SUMMARY INPUT VIDEO AUDIO SOURCE'.split(' '));
        template.content.querySelectorAll('*').forEach(el => {
            if (!allowed.has(el.tagName)) { if (['SCRIPT','STYLE','IFRAME','OBJECT','EMBED','SVG','MATH','TEMPLATE','LINK','META'].includes(el.tagName)) el.remove(); else el.replaceWith.apply(el, Array.from(el.childNodes)); return; }
            Array.from(el.attributes).forEach(attr => {
                if (!['class', 'href', 'src', 'alt', 'title', 'id', 'width', 'height', 'checked', 'type', 'controls', 'poster'].includes(attr.name)) el.removeAttribute(attr.name);
                else if (['href', 'src', 'poster'].includes(attr.name)) {
                    try { var address = new URL(attr.value, config.scope); if (!['http:', 'https:'].includes(address.protocol)) el.removeAttribute(attr.name); }
                    catch (_) { el.removeAttribute(attr.name); }
                }
            });
            if (el.tagName === 'INPUT') { if (el.type !== 'checkbox') el.remove(); else el.disabled = true; }
        });
        return template.content;
    }
    function preview(text) {
        var markdown = text.replace(/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/g, function (_, target, label) {
            var match = findNote(target.split('#')[0]);
            return '[' + (label || target).replace(/\]/g, '\\]') + '](' + url(match ? 'note/' + encodeURIComponent(match.id) : 'new?title=' + encodeURIComponent(target)) + ')';
        });
        if (window.OverType && window.OverType.MarkdownParser) return window.OverType.MarkdownParser.parse(markdown);
        var pre = node('pre', text); return pre.outerHTML;
    }
    function title(text) { view.append(node('h1', text)); document.title = text + ' — Memex'; }
    function list(items) {
        var ul = node('ul', null, {class: 'memex-note-list'});
        items.forEach(note => { var li = node('li', null, {class: 'memex-note-list-item'}); li.append(link(note.title, 'note/' + encodeURIComponent(routeId(note)))); if (note.pending) li.append(node('small', ' Saved locally')); if (note.excerpt) li.append(node('p', note.excerpt)); ul.append(li); });
        view.append(ul); if (!items.length) view.append(node('p', 'No matching notes.'));
    }
    async function showNote(value) {
        var token = generation, note = localNote(value) || findNote(value);
        if (note && localNote(note.id)) { note = localNote(note.id); drawNote(note); }
        try { var fresh = await fetchNote(note && note.postId || value); if (token === generation && fresh) { view.replaceChildren(); drawNote(fresh); } else if (token === generation && !fresh) unavailable(note); }
        catch (error) { if (token === generation && (error.accountMismatch || [401,403,404].includes(error.status) || !localNote(value))) unavailable(note, error.message); }
    }
    function unavailable(note, message) { view.replaceChildren(); title(note ? note.title : 'Note unavailable'); view.append(node('p', message || 'This note has not been downloaded. Open it online once to make its content and editor available offline.')); }
    function drawNote(note) {
        if(note.postId&&location.pathname.endsWith('/note/'+encodeURIComponent(note.id))&&note.id!==String(note.postId)){var address=new URL(url('note/'+note.postId));address.search=location.search;address.hash=location.hash;history.replaceState(null,'',address.href);lastPath=address.href;}
        title(note.title);
        if(note.daily){view.classList.add('memex-note-with-calendar');view.prepend(calendar(note.daily));var badge=link('daily','tag/daily');badge.className='memex-badge memex-badge-daily';view.querySelector('h1').append(document.createTextNode(' '),badge);}
        var actions = node('p'); if (note.editable) actions.append(link('Edit note', 'edit/' + encodeURIComponent(routeId(note)))); actions.append(document.createTextNode(' · '), link('Backlinks', 'backlinks/' + encodeURIComponent(routeId(note)))); if(note.postId)actions.append(document.createTextNode(' · '),link('New nested note','new?parent='+note.postId)); view.append(actions);
        if (note.pending) view.append(node('p', 'Local changes — waiting for server confirmation.', {role: 'status'}));
        var article = node('article', null, {class: 'memex-note-body', id:'selected-note-content', 'data-ai-assistant-important':''}); article.append(safeHTML(note.pending ? preview(note.content) : note.html)); view.append(article);
        var tasks = Array.from(note.content.matchAll(/^(\s*(?:[-*+] |\d+[.)] )\[)([ xX])(\])/gm));
        article.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox, taskIndex) {
            if (!tasks[taskIndex] || !note.editable) return;
            checkbox.disabled = false;
            checkbox.addEventListener('change', async function () {
                var task = tasks[taskIndex], offset = task.index + task[1].length;
                var content = note.content.slice(0,offset) + (checkbox.checked ? 'x' : ' ') + note.content.slice(offset + 1);
                article.querySelectorAll('input[type=checkbox]').forEach(input=>input.disabled=true);
                try { await queueNote(note, {title:note.title, content:content, parent:note.parent, baseVersion:note.version}); await render(); }
                catch (error) { status(error.message); checkbox.checked = !checkbox.checked; checkbox.disabled = false; }
            });
        });
        if(note.daily){pendingDaily(article,note.daily);if(note.daily===dailyDate()&&note.editable)placeCapture(article,true);}
        var children = allNotes().filter(item => Number(item.parent) === Number(note.postId || note.id));
        if (children.length) { view.append(node('h2', 'Nested notes')); list(children); }
        var backlinks = allNotes().filter(item => (item.links || []).some(target => String(target) === String(note.postId || note.id)));
        if (backlinks.length) { view.append(node('h2', 'Backlinks')); list(backlinks); }
    }
    function outgoingLinks(content) {
        var targets = [];
        content.replace(/\[\[([^\]|#]+)(?:[^\]]*)\]\]/g, function (_, title) { var note=findNote(title); if(note)targets.push(note.postId||note.id);return _; });
        content.replace(/\]\(([^)]+)\)/g, function (_, href) { try { var address=new URL(href,config.scope),prefix=new URL('note/',config.scope);if(address.origin===prefix.origin&&address.pathname.startsWith(prefix.pathname)){var note=findNote(decodeSlug(address.pathname.slice(prefix.pathname.length)));if(note)targets.push(note.postId||note.id);} } catch(error){}return _; });
        return Array.from(new Set(targets));
    }
    async function queueNote(note, values, draftId, publish) {
        var pending = entries.filter(entry => note && related(entry, note));
        var previous = pending[pending.length - 1];
        var id = uuid(), key = note ? note.id : 'local-' + id;
        var operation = Object.assign({}, values, {id: id, userId: config.userId, createdAt: new Date().toISOString(), kind: note ? 'edit' : 'note', title: values.title, content: values.content, parent: Number(values.parent || 0), publish: !!publish});
        if (note) { operation.noteId = note.postId ? String(note.postId) : key; operation.localId = key; operation.baseVersion = values.baseVersion || note.version || ''; if (previous) operation.baseOperation = previous.id; else if(values.baseOperation || (note.pending && note.lastOperation)) operation.baseOperation = values.baseOperation || note.lastOperation; }
        else operation.localId = key;
        var updated = Object.assign({}, (note && localNote(note.id)) || note || {id: key, postId: 0, slug: '', tags: [], links: [], editable: true, status: 'draft'}, values, {id: key, pending: true, links: outgoingLinks(values.content), lastOperation: id, version: operation.baseVersion || '', excerpt: values.content.slice(0, 160)});
        await draftWrites;
        await outbox.enqueue(operation, draftId, undefined, tx=>data.write(tx,'notes',updated));
        await loadLocal();
        outbox.sync();
        return key;
    }
    async function editNote(value, daily) {
        if(daily&&(!validDate(daily)||daily>dailyDate())){title('Invalid daily note date.');return;}
        if(daily){var existing=allNotes().find(note=>note.daily===daily);if(existing){value=existing.id;daily=undefined;}}
        var token = generation, note;
        if (value) {
            try { note = await fetchNote((findNote(value) || {}).postId || value); }
            catch (error) { if (token === generation) unavailable(findNote(value), error.message); return; }
            if (!note) { if (token === generation) unavailable(findNote(value)); return; }
            if (!note.editable) { title('Read-only note'); return; }
        }
        if (token !== generation) return;
        title(note ? 'Edit ' + note.title : daily ? 'Edit '+daily : 'New note');
        if(daily)view.append(node('p','Unsaved daily draft. Save to keep it unpublished, or publish when ready.',{class:'memex-muted'}));
        var key = note ? note.id : daily ? 'daily-'+daily : 'new';
        var sessionKey = 'memex-editor-tab:' + config.scope, tabId;
        try { tabId = sessionStorage.getItem(sessionKey) || uuid(); sessionStorage.setItem(sessionKey,tabId); } catch (_) { tabId = uuid(); }
        var draftPrefix = 'shell-edit:' + key, draftId = draftPrefix + ':' + tabId;
        var savedDrafts = (await outbox.rows('drafts')).filter(item => item.id === draftPrefix || item.id.startsWith(draftPrefix + ':'));
        var draft = savedDrafts.find(item => item.id === draftId) || savedDrafts.find(item => item.id === draftPrefix);
        if (token !== generation) return;
        var form = node('form', null, {class: 'memex-edit-form', 'data-shell-editor': '', 'data-ai-assistant-important':''});
        var label = node('label', 'Title'), input = node('input', null, {type: 'text', name: 'title', required: ''}); input.value = draft ? draft.title : note ? note.title : new URL(location.href).searchParams.get('title') || daily || ''; label.append(input); form.append(label);
        function isDescendant(item){var current=item,seen=new Set();while(current&&current.parent&&!seen.has(current.id)){seen.add(current.id);if(note&&Number(current.parent)===Number(note.postId))return true;current=findNote(current.parent);}return false;}
        var parentLabel = node('label', 'Nested under'), parent = node('select', null, {name: 'parent'}); parent.append(node('option', '— Top level —', {value: '0'}));
        allNotes().filter(item => item.postId && (!note || (item.id !== note.id && !isDescendant(item)))).forEach(item => parent.append(node('option', item.title, {value: String(item.postId)})));
        var selectedParent = String(draft ? draft.parent : note ? note.parent || 0 : Number(new URL(location.href).searchParams.get('parent') || 0));
        if(!Array.from(parent.options).some(option=>option.value===selectedParent))parent.append(node('option','Current parent',{value:selectedParent}));
        parent.value = selectedParent; parentLabel.append(parent); form.append(parentLabel);
        var editorHost = node('div', null, {class: 'memex-markdown-editor'}), textarea = node('textarea', null, {name: 'content', rows: '24', 'aria-label': 'Note Markdown'});
        textarea.value = draft ? draft.content : note ? note.content : ''; form.append(editorHost, textarea);
        var notice = node('p', draft ? 'Restored your draft from this device.' : '', {role: 'status'}); form.append(notice);
        var save = node('button', note && !(note.daily && note.status === 'draft') ? 'Save note' : 'Save draft', {type: 'submit', class: 'memex-button memex-button-primary'}); form.append(save);
        if (daily || (note && note.status === 'draft')) form.append(node('button', 'Publish', {type: 'submit', name: 'publish', value: '1', class: 'memex-button'}));
        form.append(document.createTextNode(' '), link('Cancel', note ? 'note/' + encodeURIComponent(routeId(note)) : ''));
        view.append(form);
        var editor, changed = false, saving = false;
        var baseline = draft && draft.baseVersion || note && note.version || '';
        var baselineOperation = draft && draft.baseOperation || note && note.pending && note.lastOperation || undefined;
        function snapshot() { return {id: draftId, title: input.value, content: editor ? editor.getValue() : textarea.value, parent: Number(parent.value || 0), daily: daily || note && note.daily || '', baseVersion: baseline, baseOperation: baselineOperation, updatedAt: new Date().toISOString()}; }
        function persist() {
            changed = true;
            var value = snapshot();
            saveDraftRecord(value);
            draftWrites.then(() => { if (!saving) notice.textContent = 'Draft saved on this device.'; }, () => { notice.textContent = 'Could not save locally. Keep this page open and copy your text.'; });
        }
        form.addEventListener('input', persist); form.addEventListener('change', persist);
        if (window.OverType) {
            editor = new window.OverType(editorHost, {value: textarea.value, toolbar: true, showStats: true, minHeight: '28rem', onChange: function () { if (editor) persist(); }})[0];
            if (editor) { textarea.hidden = true; editorHost.classList.add('is-ready'); }
        }
        if (note && note.revisions && note.revisions.length) {
            var revisions = node('details'); revisions.append(node('summary','Revisions'));
            note.revisions.forEach(function (revision) {
                var details = node('details'), load = node('button','Load into editor',{type:'button'});
                details.append(node('summary',revision.modified || revision.title),node('pre',revision.content),load);
                load.addEventListener('click',function(){input.value=revision.title;if(editor)editor.setValue(revision.content);else textarea.value=revision.content;persist();});
                revisions.append(details);
            }); view.append(revisions);
        }
        var otherDrafts = savedDrafts.filter(item => item.id !== draftId && item !== draft);
        if (otherDrafts.length) {
            var recovery = node('details'); recovery.append(node('summary','Other saved drafts'));
            otherDrafts.forEach(function(saved){
                var details=node('details'), load=node('button','Load this draft',{type:'button'});
                details.append(node('summary',saved.title || 'Untitled draft'),node('pre',saved.content),load);
                load.addEventListener('click',function(){input.value=saved.title;parent.value=String(saved.parent||0);baseline=saved.baseVersion||'';if(editor)editor.setValue(saved.content);else textarea.value=saved.content;persist();});recovery.append(details);
            });view.append(recovery);
        }
        var wikiInput = editor && editor.textarea || textarea;
        var suggestions = node('div', null, {class:'memex-shell-suggestions'}); suggestions.hidden = true; form.insertBefore(suggestions, notice);
        wikiInput.addEventListener('input', function () {
            var before = wikiInput.value.slice(0,wikiInput.selectionStart), match = before.match(/\[\[([^\]\n]*)$/);
            suggestions.replaceChildren(); suggestions.hidden = !match; if(!match)return;
            allNotes().filter(item=>item.title.toLowerCase().includes(match[1].toLowerCase())).slice(0,10).forEach(function(item){
                var button=node('button',item.title,{type:'button'});suggestions.append(button);
                button.addEventListener('click',function(){var cursor=wikiInput.selectionStart,text=wikiInput.value.slice(0,cursor-match[0].length)+'[['+item.title+']]'+wikiInput.value.slice(cursor);if(editor)editor.setValue(text);else textarea.value=text;persist();suggestions.hidden=true;wikiInput.focus();});
            });
            if(!suggestions.childElementCount)suggestions.hidden=true;
        });
        activeEditor = {form: form, persist: persist};
        form.addEventListener('submit', async function (event) {
            event.preventDefault(); if (saving) return;
            var before = snapshot(); if (!before.title.trim()) { notice.textContent='Enter a title.'; return; }
            saving = true; Array.from(form.elements).forEach(control=>control.disabled=true);
            try { var target = await queueNote(note, before, draftId, event.submitter && event.submitter.name === 'publish'); changed = false; await navigate('note/' + encodeURIComponent(target)); }
            catch (error) { notice.textContent = 'Not saved: ' + error.message; }
            finally { saving = false; Array.from(form.elements).forEach(control=>control.disabled=false); }
        });
        var conflict = note && entries.find(entry => related(entry, note) && entry.state === 'conflict');
        if (conflict && conflict.conflict.note) {
            var remote = conflict.conflict.note, box = node('section', null, {class: 'memex-notice', 'data-shell-conflict': ''});
            box.append(node('h2', 'This note changed on the server'), node('p', 'Your local edits are retained. Compare the server copy before choosing what to save.'), node('h3', remote.title), node('pre', remote.content));
            var keepServer = node('button', 'Use server version', {type: 'button'}), keepLocal = node('button', 'Save my reviewed version', {type: 'button'}); box.append(keepServer, keepLocal); view.prepend(box);
            keepServer.addEventListener('click', async function () {
                keepServer.disabled=true;keepLocal.disabled=true;Array.from(form.elements).forEach(control=>control.disabled=true);
                try { await draftWrites; var oldIds=entries.filter(entry=>related(entry,note)).map(entry=>entry.id); await outbox.transaction(['entries','drafts','records'],'readwrite',function(tx){oldIds.forEach(id=>tx.objectStore('entries').delete(id));tx.objectStore('drafts').delete(draftId);data.write(tx,'notes',Object.assign({},remote,{id:note.id}));});outbox.changed();await loadLocal(); await navigate('note/' + encodeURIComponent(routeId(note))); }
                catch (error) { notice.textContent = error.message;keepServer.disabled=false;keepLocal.disabled=false;Array.from(form.elements).forEach(control=>control.disabled=false); }
            });
            keepLocal.addEventListener('click', async function () {
                keepLocal.disabled = true;keepServer.disabled=true;Array.from(form.elements).forEach(control=>control.disabled=true);
                try {
                    var reviewed = snapshot(); reviewed.baseVersion = remote.version; delete reviewed.baseOperation;
                    // Replace queued edits and their local view in one durable commit.
                    var replacedIds = entries.filter(entry => related(entry, note)).map(entry => entry.id);
                    var opId = uuid();
                    await draftWrites;
                    await outbox.enqueue(Object.assign({}, reviewed, {id: opId, kind: 'edit', noteId: String(remote.postId), localId: note.id, userId: config.userId, createdAt: new Date().toISOString()}),draftId,undefined,function(tx){replacedIds.forEach(id=>tx.objectStore('entries').delete(id));data.write(tx,'notes',Object.assign({},note,reviewed,{id:note.id,pending:true,lastOperation:opId,version:remote.version}));});
                    await loadLocal(); outbox.sync(); await navigate('note/' + encodeURIComponent(routeId(note)));
                } catch (error) { notice.textContent = error.message; keepLocal.disabled = false;keepServer.disabled=false;Array.from(form.elements).forEach(control=>control.disabled=false); }
            });
        }
    }
    function dailyDate() {
        var now = siteNow();
        if (/^[+-]\d{2}:\d{2}$/.test(config.timezone)) { var sign = config.timezone[0] === '-' ? -1 : 1; now = new Date(now.getTime() + sign * (Number(config.timezone.slice(1, 3)) * 60 + Number(config.timezone.slice(4))) * 60000); return now.toISOString().slice(0, 10); }
        var parts = new Intl.DateTimeFormat('en-CA', {timeZone: config.timezone, year: 'numeric', month: '2-digit', day: '2-digit'}).formatToParts(now), values = {}; parts.forEach(part => values[part.type] = part.value); return values.year + '-' + values.month + '-' + values.day;
    }
    var calendarMonths = {};
    var captureForm, captureInput, captureTextarea, captureMessage, captureDraftId;
    function captureValue() { return captureInput.hidden ? captureTextarea : captureInput; }
    function placeCapture(container, inline) {
        if (!captureForm) return;
        var previous = captureValue(), value = previous.value;
        captureInput.hidden = !inline; captureTextarea.hidden = inline;
        captureInput.disabled = !inline; captureTextarea.disabled = inline;
        captureValue().value = value;
        captureForm.className = 'memex-quick-capture' + (inline ? ' memex-quick-capture-inline' : '');
        container.append(captureForm);
    }
    function setupCapture() {
        captureForm = node('form', null, {'aria-label':'Quick capture', 'data-memex-capture':'daily'});
        captureInput = node('input', null, {type:'text',name:'content','aria-label':'Quick capture text',placeholder:'Add an entry',autocomplete:'off',required:''});
        captureInput.hidden = true; captureInput.disabled = true;
        captureTextarea = node('textarea', null, {name:'content',rows:'2','aria-label':'Quick capture text',placeholder:'Quick capture — appends to today'});
        var actions = node('div', null, {class:'memex-quick-capture-actions'}), clock = node('div', null, {class:'memex-server-time'});
        var settings = node('a', null, {href:config.settingsUrl || new URL('/wp-admin/options-general.php',config.scope).href,'aria-label':'Site time. Open timezone settings.'});
        settings.append(node('time', '', {'data-memex-server-time':''}));
        var task = node('a','[ ]',{href:'#',role:'button','data-memex-capture-task':'','aria-pressed':'false','aria-label':'Toggle checkbox at start of entry'});
        clock.append(settings, task); var save = node('button','Capture',{type:'submit'}); actions.append(clock,save);
        captureMessage = node('p','',{role:'status'}); captureForm.append(captureTextarea,captureInput,actions,captureMessage);
        var tabKey='memex-editor-tab:'+config.scope,tab;try{tab=sessionStorage.getItem(tabKey)||uuid();sessionStorage.setItem(tabKey,tab);}catch(_){tab=uuid();}
        captureDraftId='shell-sidebar:'+tab;
        var marker=/^\[[ xX]\](?:[\t ]+|$)/;
        function persist() { var value=captureValue().value; task.setAttribute('aria-pressed',String(marker.test(value))); saveDraftRecord({id:captureDraftId,content:value}); }
        captureForm.addEventListener('input',persist);
        task.addEventListener('keydown',function(event){if(event.key===' '){event.preventDefault();task.click();}});
        task.addEventListener('click',function(event){event.preventDefault();var input=captureValue(),start=input.selectionStart,end=input.selectionEnd,match=input.value.match(marker),shift=match?-match[0].length:4;input.value=match?input.value.slice(match[0].length):'[ ] '+input.value;input.focus();input.setSelectionRange(Math.max(0,start+shift),Math.max(0,end+shift));persist();});
        outbox.rows('drafts').then(function(rows){var draft=rows.find(row=>row.id===captureDraftId);if(draft&&!captureValue().value){captureValue().value=draft.content;task.setAttribute('aria-pressed',String(marker.test(draft.content)));}});
        captureForm.addEventListener('submit',async function(event){
            event.preventDefault();var input=captureValue(),text=input.value.trim();if(!text||save.disabled)return;
            var date=dailyDate(),parts=getTimezoneParts(siteNow(),config.timezone),clock=pad(parts.hour,2)+':'+pad(parts.minute,2);
            input.disabled=true;save.disabled=true;
            try{
                await draftWrites;
                await outbox.enqueue({id:uuid(),kind:'daily',date:date,clock:clock,title:'',content:text,userId:config.userId,createdAt:new Date().toISOString()},captureDraftId);
                input.value='';task.setAttribute('aria-pressed','false');captureMessage.textContent='Saved on this device.';
                await loadLocal(); await navigate('daily/'+date); outbox.sync();
            }catch(error){captureMessage.textContent=error.message;}finally{save.disabled=false;captureValue().disabled=false;}
        });
        placeCapture(root.querySelector('[data-shell-quick-capture]'),false); updateClocks();
    }
    function siteNow() { return new Date(Date.now()+(config.clockOffset||0)); }
    function updateClocks() {
        if(!config)return;var parts=getTimezoneParts(siteNow(),config.timezone);if(!parts)return;
        root.querySelectorAll('[data-memex-server-time]').forEach(function(clock){clock.textContent=formatWpDate(config.timeFormat||'H:i',parts);clock.setAttribute('datetime',parts.iso);});
    }
    function validDate(date) { return /^\d{4}-\d{2}-\d{2}$/.test(date)&&Number.isFinite(Date.parse(date+'T12:00:00Z'))&&new Date(date+'T12:00:00Z').toISOString().slice(0,10)===date; }
    function calendar(selected) {
        var month=calendarMonths[selected]||new URL(location.href).searchParams.get('month')||selected.slice(0,7),today=dailyDate();
        if(!validDate(month+'-01')||month>today.slice(0,7))month=today.slice(0,7);
        var section=node('section',null,{class:'memex-daily-calendar','aria-label':'Daily notes calendar','data-daily-calendar':'','data-selected':selected});
        function draw(month) {
            section.replaceChildren();section.dataset.month=month;calendarMonths[selected]=month;
            var first=new Date(month+'-01T12:00:00Z'),last=new Date(first);last.setUTCMonth(last.getUTCMonth()+1,0);
            var heading=node('div',null,{class:'memex-calendar-heading'});
            [-1,1].forEach(function(delta){var date=new Date(first);date.setUTCMonth(date.getUTCMonth()+delta);var target=date.toISOString().slice(0,7),arrow=node('a',delta<0?'←':'→',{'aria-label':delta<0?'Previous month':'Next month','data-calendar-month':target});
                if(target>today.slice(0,7)){arrow.setAttribute('aria-disabled','true');arrow.tabIndex=-1;}else{var address=new URL(location.href);address.searchParams.set('month',target);arrow.href=address.href;arrow.addEventListener('click',function(event){event.preventDefault();event.stopPropagation();draw(target);});}
                if(delta<0)heading.append(arrow,node('strong',(config.monthNames||[])[first.getUTCMonth()]||new Intl.DateTimeFormat(undefined,{month:'long',year:'numeric',timeZone:'UTC'}).format(first),{'aria-live':'polite'}));else heading.append(arrow);
            });
            if(config.monthNames)heading.querySelector('strong').textContent=config.monthNames[first.getUTCMonth()]+' '+first.getUTCFullYear();
            section.append(heading);var table=node('table'),head=node('thead'),row=node('tr'),start=Number(config.startOfWeek||0);
            for(var i=0;i<7;i++){var weekday=(start+i)%7,name=(config.weekdayNames||[])[weekday]||new Intl.DateTimeFormat(undefined,{weekday:'long',timeZone:'UTC'}).format(new Date(Date.UTC(2026,10,22+weekday))),th=node('th',null,{scope:'col'});th.append(node('abbr',name.slice(0,1),{title:name}));row.append(th);}head.append(row);table.append(head);
            var body=node('tbody'),offset=(first.getUTCDay()-start+7)%7,count=last.getUTCDate();
            for(var cell=0;cell<Math.ceil((offset+count)/7)*7;cell++){if(cell%7===0){row=node('tr');body.append(row);}var td=node('td'),day=cell-offset+1;
                if(day>0&&day<=count){var date=month+'-'+pad(day,2),note=allNotes().find(item=>item.daily===date);td.dataset.calendarDate=date;if(date===today)td.className='is-today';
                    if(date>today)td.append(node('span',String(day)));else{var anchor=link(String(day),note?'note/'+encodeURIComponent(routeId(note)):'new?daily='+date);anchor.setAttribute('aria-label',(note?'Open daily note for ':'Write a draft daily note for ')+date);if(note){anchor.className='has-daily';if(date===selected)anchor.setAttribute('aria-current','date');}td.append(anchor);}}
                row.append(td);
            }table.append(body);section.append(table,node('p','Highlighted days have daily notes.',{class:'screen-reader-text'}));
        }draw(month);return section;
    }
    function pendingDaily(container,date) { entries.filter(entry=>entry.kind==='daily'&&entry.date===date).forEach(function(entry){var p=node('p');p.append(node('strong',entry.clock),document.createTextNode(' · '+entry.content));container.append(p);}); }
    function emptyToday() {
        view.classList.add('memex-note-with-calendar');title(dailyDate());view.prepend(calendar(dailyDate()));var badge=link('daily','tag/daily');badge.className='memex-badge memex-badge-daily';view.querySelector('h1').append(document.createTextNode(' '),badge);
        var body=node('section',null,{id:'selected-note-content',class:'memex-note-body'});view.append(body);pendingDaily(body,dailyDate());placeCapture(body,true);
    }
    function dailyCapture(date, quick) {
        title('Quick capture');view.append(node('p',"Anything you type here is appended, with a timestamp, to today's daily note."));
        placeCapture(view,false);captureForm.classList.add('memex-quick-capture-full');captureTextarea.rows=10;captureTextarea.placeholder="What's on your mind?";captureForm.querySelector('button[type=submit]').textContent='Append to today';captureTextarea.focus();
    }
	function getTimezoneParts(date, timezone) {
		var offsetMatch = timezone.match(/^([+-])(\d{2}):(\d{2})$/);
		if (offsetMatch) {
			var direction = offsetMatch[1] === '-' ? -1 : 1;
			var offsetMinutes = direction * (parseInt(offsetMatch[2], 10) * 60 + parseInt(offsetMatch[3], 10));
			return getUtcParts(new Date(date.getTime() + offsetMinutes * 60000), timezone, offsetMinutes, 'UTC' + timezone);
		}

		try {
			var formatter = new Intl.DateTimeFormat('en-US', {
				timeZone: timezone,
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
				hour: '2-digit',
				minute: '2-digit',
				second: '2-digit',
				hour12: false,
			});
			var values = {};
			formatter.formatToParts(date).forEach(function (part) {
				values[part.type] = part.value;
			});
			var hour = parseInt(values.hour, 10);
			if (hour === 24) hour = 0;
			var minute = parseInt(values.minute, 10);
			var second = parseInt(values.second, 10);
			var year = parseInt(values.year, 10);
			var month = parseInt(values.month, 10);
			var day = parseInt(values.day, 10);
			var offsetMinutesNamed = Math.round((Date.UTC(year, month - 1, day, hour, minute, second) - date.getTime()) / 60000);
			return {
				year: year,
				month: month,
				day: day,
				hour: hour,
				minute: minute,
				second: second,
				timezone: timezone,
				timezoneName: getTimezoneName(date, timezone),
				offsetMinutes: offsetMinutesNamed,
				iso: pad(year, 4) + '-' + pad(month, 2) + '-' + pad(day, 2) + 'T' + pad(hour, 2) + ':' + pad(minute, 2) + ':' + pad(second, 2),
			};
		} catch (e) {
			return null;
		}
	}

	function getUtcParts(date, timezone, offsetMinutes, timezoneName) {
		var year = date.getUTCFullYear();
		var month = date.getUTCMonth() + 1;
		var day = date.getUTCDate();
		var hour = date.getUTCHours();
		var minute = date.getUTCMinutes();
		var second = date.getUTCSeconds();
		return {
			year: year,
			month: month,
			day: day,
			hour: hour,
			minute: minute,
			second: second,
			timezone: timezone,
			timezoneName: timezoneName,
			offsetMinutes: offsetMinutes,
			iso: pad(year, 4) + '-' + pad(month, 2) + '-' + pad(day, 2) + 'T' + pad(hour, 2) + ':' + pad(minute, 2) + ':' + pad(second, 2),
		};
	}

	function getTimezoneName(date, timezone) {
		try {
			var formatter = new Intl.DateTimeFormat('en-US', {
				timeZone: timezone,
				timeZoneName: 'short',
			});
			var parts = formatter.formatToParts(date);
			for (var i = 0; i < parts.length; i++) {
				if (parts[i].type === 'timeZoneName') return parts[i].value;
			}
		} catch (e) {}
		return timezone;
	}

	function formatWpDate(format, parts) {
		var replacements = {
			H: pad(parts.hour, 2),
			G: String(parts.hour),
			h: pad(toTwelveHour(parts.hour), 2),
			g: String(toTwelveHour(parts.hour)),
			i: pad(parts.minute, 2),
			s: pad(parts.second, 2),
			a: parts.hour < 12 ? 'am' : 'pm',
			A: parts.hour < 12 ? 'AM' : 'PM',
			O: formatTimezoneOffset(parts.offsetMinutes, false),
			P: formatTimezoneOffset(parts.offsetMinutes, true),
			T: parts.timezoneName || '',
			e: parts.timezone || '',
		};
		var output = '';
		var escaped = false;
		for (var i = 0; i < format.length; i++) {
			var character = format.charAt(i);
			if (escaped) {
				output += character;
				escaped = false;
				continue;
			}
			if (character === '\\') {
				escaped = true;
				continue;
			}
			output += Object.prototype.hasOwnProperty.call(replacements, character) ? replacements[character] : character;
		}
		return output;
	}

	function formatTimezoneOffset(offsetMinutes, includeColon) {
		if (typeof offsetMinutes !== 'number' || isNaN(offsetMinutes)) return '';
		var sign = offsetMinutes < 0 ? '-' : '+';
		var absolute = Math.abs(offsetMinutes);
		var hours = pad(Math.floor(absolute / 60), 2);
		var minutes = pad(absolute % 60, 2);
		return sign + hours + (includeColon ? ':' : '') + minutes;
	}

	function toTwelveHour(hour) {
		var value = hour % 12;
		return value || 12;
	}

	function pad(value, length) {
		var output = String(value);
		while (output.length < length) output = '0' + output;
		return output;
	}

	function renderGraph(host) {
		var dataAttr = host.getAttribute('data-graph');
		if (!dataAttr) return;
		var data;
		try {
			data = JSON.parse(dataAttr);
		} catch (e) {
			return;
		}
		if (!data.nodes || !data.nodes.length) {
			host.innerHTML = '<p class="memex-muted" style="padding:1rem;">No notes to graph yet.</p>';
			return;
		}

		var width = host.clientWidth;
		var height = host.clientHeight;
		var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
		svg.setAttribute('role', 'img');
		svg.setAttribute('aria-label', host.getAttribute('aria-label') || 'Note graph');
		host.innerHTML = '';
		host.appendChild(svg);

		var idIndex = {};
		data.nodes.forEach(function (n, i) {
			n.x = width / 2 + (Math.cos((i / data.nodes.length) * Math.PI * 2) * Math.min(width, height)) / 3;
			n.y = height / 2 + (Math.sin((i / data.nodes.length) * Math.PI * 2) * Math.min(width, height)) / 3;
			n.vx = 0;
			n.vy = 0;
			idIndex[n.id] = n;
		});

		var edges = (data.edges || []).filter(function (e) { return idIndex[e.from] && idIndex[e.to]; });

		// Fruchterman–Reingold: k is the ideal edge length for this many
		// nodes in this much area, repulsion is k²/d, attraction d²/k, and a
		// cooling temperature caps how far a node may move per step so the
		// layout settles instead of bouncing off the walls. Scales from a
		// handful of nodes to a few hundred without retuning.
		var count = data.nodes.length;
		var k = Math.sqrt((width * height) / count) * 0.5;
		var iterations = count > 300 ? 100 : 200;
		var temp = Math.max(width, height) / 8;
		var cool = Math.pow(0.5 / temp, 1 / iterations);

		for (var step = 0; step < iterations; step++) {
			data.nodes.forEach(function (n) { n.vx = 0; n.vy = 0; });
			// Repulsion.
			for (var i = 0; i < count; i++) {
				var a = data.nodes[i];
				for (var j = i + 1; j < count; j++) {
					var b = data.nodes[j];
					var dx = a.x - b.x;
					var dy = a.y - b.y;
					var d = Math.sqrt(dx * dx + dy * dy) + 0.01;
					var f = (k * k) / d / d;
					a.vx += dx * f; a.vy += dy * f;
					b.vx -= dx * f; b.vy -= dy * f;
				}
			}
			// Attraction along edges.
			edges.forEach(function (e) {
				var a = idIndex[e.from], b = idIndex[e.to];
				var dx = b.x - a.x, dy = b.y - a.y;
				var d = Math.sqrt(dx * dx + dy * dy) + 0.01;
				var f = d / k;
				a.vx += dx * f; a.vy += dy * f;
				b.vx -= dx * f; b.vy -= dy * f;
			});
			// Gentle pull to the centre, then move by at most `temp`.
			data.nodes.forEach(function (n) {
				n.vx += (width / 2 - n.x) * 0.05;
				n.vy += (height / 2 - n.y) * 0.05;
				var len = Math.sqrt(n.vx * n.vx + n.vy * n.vy) + 0.01;
				var move = Math.min(len, temp) / len;
				n.x += n.vx * move;
				n.y += n.vy * move;
				n.x = Math.max(10, Math.min(width - 10, n.x));
				n.y = Math.max(10, Math.min(height - 10, n.y));
			});
			temp *= cool;
		}

		// Edges first so they render beneath nodes.
		edges.forEach(function (e) {
			var a = idIndex[e.from], b = idIndex[e.to];
			var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
			line.setAttribute('class', 'memex-graph-edge');
			line.setAttribute('x1', a.x); line.setAttribute('y1', a.y);
			line.setAttribute('x2', b.x); line.setAttribute('y2', b.y);
			svg.appendChild(line);
		});

		data.nodes.forEach(function (n) {
			var g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
			g.setAttribute('class', 'memex-graph-node' + (n.stub ? ' is-stub' : ''));
			g.setAttribute('transform', 'translate(' + n.x + ',' + n.y + ')');
			g.setAttribute('tabindex', '0');
			g.setAttribute('role', 'link');
			g.setAttribute('aria-label', n.title);

			var degree = 0;
			edges.forEach(function (e) {
				if (e.from === n.id || e.to === n.id) degree++;
			});
			var r = 4 + Math.min(10, degree);

			var circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
			circle.setAttribute('r', r);
			g.appendChild(circle);

			var text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
			text.setAttribute('x', r + 3);
			text.setAttribute('y', 3);
			text.textContent = n.title.length > 30 ? n.title.slice(0, 30) + '…' : n.title;
			g.appendChild(text);

			g.addEventListener('click', function () { window.location.href = n.url; });
			g.addEventListener('keydown', function (ev) {
				if (ev.key === 'Enter' || ev.key === ' ') {
					ev.preventDefault();
					window.location.href = n.url;
				}
			});
			var title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
			title.textContent = n.title;
			g.appendChild(title);

			svg.appendChild(g);
		});
	}

    async function render() {
        var token = ++generation; await draftWrites.catch(() => {}); activeEditor = null; placeCapture(root.querySelector('[data-shell-quick-capture]'),false); captureTextarea.rows=2;captureTextarea.placeholder='Quick capture — appends to today';captureForm.querySelector('button[type=submit]').textContent='Capture';view.replaceChildren();view.classList.remove('memex-note-with-calendar'); status(lastMessage || (!offline && navigator.onLine ? '' : 'Offline — using notes saved on this device.'));
        var path = decodeURIComponent(location.pathname.slice(new URL(config.scope).pathname.length)).replace(/\/$/, ''), parts = path.split('/'), kind = parts[0], id = parts.slice(1).join('/');
        if (['import','export','reminders'].includes(kind)) { title(kind.charAt(0).toUpperCase() + kind.slice(1)); view.append(node('p', 'This tool needs a connection. Your saved notes and editor remain available offline.')); return; }
        if (kind === 'note') return showNote(id);
        if (kind === 'edit') return editNote(id);
        if (kind === 'new') return editNote(undefined,new URL(location.href).searchParams.get('daily')||undefined);
        if (kind === 'capture' || kind === 'quick-capture') { dailyCapture(dailyDate(),true); return; }
        if (kind === 'daily') {
            var date=id||dailyDate();if(!validDate(date)){title('Invalid date');view.append(node('p','Daily notes use YYYY-MM-DD.'));return;}
            var daily=allNotes().find(note=>note.daily===date);
            if(daily){history.replaceState(null,'',url('note/'+encodeURIComponent(daily.slug||routeId(daily)))+location.search);lastPath=location.href;await showNote(daily.id);}
            else if(date===dailyDate())emptyToday();
            else await editNote(undefined,date);
            return;
        }
        if (kind === 'search') {
            title('Search'); var input = node('input', null, {type: 'search', 'aria-label': 'Search your notes', placeholder: 'Search notes…'}), results = node('section'); view.append(input, results);
            function filter() { var query = input.value.toLowerCase(); results.replaceChildren(); allNotes().filter(note => (note.title + ' ' + (note.content || note.excerpt || '')).toLowerCase().includes(query)).forEach(note => { var p = node('p'); p.append(link(note.title, 'note/' + encodeURIComponent(routeId(note)))); results.append(p); }); }
            input.addEventListener('input', filter); filter(); input.focus(); return;
        }
        if (kind === 'tags') {
            title('Tags'); var tags = new Map(); allNotes().forEach(note => (note.tags || []).forEach(tag => tags.set(tag.slug, tag.name))); tags.forEach((name, slug) => { var p = node('p'); p.append(link(name, 'tag/' + slug)); view.append(p); }); return;
        }
        if (kind === 'tag') { title('Tag: ' + id);if(id==='daily'){view.classList.add('memex-note-with-calendar');view.prepend(calendar(dailyDate()));} return list(allNotes().filter(note => (note.tags || []).some(tag => tag.slug === id))); }
        if (kind === 'backlinks') { var target = findNote(id); title('Backlinks' + (target ? ': ' + target.title : '')); return list(target ? allNotes().filter(note => (note.links || []).some(id => String(id) === String(target.postId || target.id))) : []); }
        if (kind === 'graph') {
            title('Graph');var graphNotes=allNotes(),seenEdges=new Set(),edges=[];
            graphNotes.forEach(note=>(note.links||[]).forEach(function(id){var target=findNote(id),from=routeId(note);if(!target||routeId(target)===from||seenEdges.has(from+'>'+id))return;seenEdges.add(from+'>'+id);edges.push({from:from,to:routeId(target)});}));
            var linkedIds=new Set(edges.flatMap(edge=>[edge.from,edge.to])),linked=graphNotes.filter(note=>linkedIds.has(routeId(note))),unlinked=graphNotes.filter(note=>!linkedIds.has(routeId(note))).sort((a,b)=>String(b.created||b.modified||'').localeCompare(String(a.created||a.modified||'')));
            view.append(node('p',linked.length+' linked notes, '+edges.length+' links; '+unlinked.length+' notes without links are not shown.',{class:'memex-muted'}));
            if(linked.length){var host=node('div',null,{id:'memex-graph',role:'img','aria-label':'Note graph','data-graph':JSON.stringify({nodes:linked.map(note=>({id:routeId(note),title:note.title,url:url('note/'+encodeURIComponent(note.slug||routeId(note))),stub:note.stub})),edges:edges})});host.style.height='70vh';view.append(host);renderGraph(host);}
            else if(graphNotes.length){var message=node('p','None of your notes link to another note yet, so there is no graph to draw. Link notes to each other with [[ in the editor, or browse them ');message.append(link('by tag','tags'),document.createTextNode('.'));view.append(message);}else view.append(node('p','No notes to graph yet.'));
            if(unlinked.length){var section=node('section',null,{id:'note-graph-unlinked'});section.append(node('h2',unlinked.length>10?'Latest 10 of '+unlinked.length+' notes without links':unlinked.length+' '+(unlinked.length===1?'note without links':'notes without links')));var graphList=node('ul',null,{class:'memex-graph-unlinked'});unlinked.slice(0,10).forEach(note=>{var li=node('li');li.append(link(note.title||'(untitled)','note/'+encodeURIComponent(routeId(note))));graphList.append(li);});section.append(graphList);view.append(section);}return;
        }
        var items = allNotes();
        if (kind === 'orphans') { var targets = new Set(items.flatMap(note => (note.links || []).map(String))); title('Orphan notes');view.append(node('p','Notes that no other note links to. Good candidates to connect or archive.',{class:'memex-muted'})); items = items.filter(note => !targets.has(String(note.postId || note.id))); }
        else if (kind === 'broken') { title('Broken links'); items = items.filter(note => note.stub); }
        else title('All notes');
        list(items.sort((a,b) => String(b.modified || '').localeCompare(String(a.modified || ''))));
    }
    async function navigate(path) { await draftWrites; history.pushState(null, '', url(path)); lastPath=location.href; await render(); if(location.hash){var heading=document.getElementById(decodeSlug(location.hash.slice(1)));if(heading)heading.scrollIntoView();} root.querySelector('.memex-sidebar').classList.remove('is-open'); root.querySelector('[data-shell-menu]').setAttribute('aria-expanded','false'); }
    async function acknowledged(receipt, entry) {
        offline = false;
        if (!receipt.note) return;
        var remote = receipt.note, key = entry.localId || entry.noteId || String(receipt.postId), existing = localNote(key) || localNote(String(receipt.postId));
        var pending = (await outbox.rows('entries')).filter(item => item.id !== entry.id && existing && related(item, existing));
        var saved = Object.assign({}, remote, {id: existing ? existing.id : String(remote.id), pending: !!pending.length});
        if (pending.length && existing) Object.assign(saved, {content: existing.content, title: existing.title, parent: existing.parent, lastOperation: existing.lastOperation});
        await data.put('notes', saved);
        await data.put('index', remote);
        if(entry.kind==='daily'&&remote.daily&&location.pathname.endsWith('/daily/'+remote.daily)){history.replaceState(null,'',url('note/'+encodeURIComponent(remote.slug||String(remote.postId))));lastPath=location.href;}
        if(existing && !activeEditor && location.pathname.endsWith('/note/' + encodeURIComponent(existing.id)) && existing.id !== String(remote.postId)){var address=new URL(url('note/'+remote.postId));address.search=location.search;address.hash=location.hash;history.replaceState(null,'',address.href);lastPath=address.href;}
    }
    async function boot() {
        try {
            var boot = await window.wpAppBootstrap; if (!boot) return; config = boot.data; offline = boot.offline;
            if (!offline && config.serverTime) config.clockOffset=config.serverTime*1000-Date.now();
            outbox = new window.WpAppOutbox(Object.assign({}, config, {
                databaseName: 'memex-capture:' + config.scope + ':' + config.userId,
                sessionAction: 'memex_capture_session', syncAction: 'memex_capture_sync', payloadField: 'capture',
                acknowledge: (receipt, entry) => receipt.id === entry.id && !!receipt.postId,
                onAcknowledged: acknowledged
            }));
            data = new window.WpAppLocalData(outbox);
            setupCapture();
            window.setInterval(updateClocks,1000);
            await loadLocal();
            var oldDrafts = (await outbox.rows('drafts')).filter(draft => (!draft.id.startsWith('shell-') || draft.id.startsWith('shell-daily:')) && draft.content);
            if (oldDrafts.length) {
                root.querySelector('[data-shell-old-drafts]').hidden = false;
                var oldList = root.querySelector('[data-shell-old-draft-list]');
                oldDrafts.forEach(draft => { var details = node('details'); details.append(node('summary', draft.title || 'Daily capture draft'), node('pre', draft.content)); oldList.append(details); });
            }
            await render();
            outbox.subscribe(async function () { try { await loadLocal(); if (!outbox.status().running && canRefreshView() && !/\/(edit|new)(\/|$)/.test(location.pathname)) await render(); } catch(error){status(error.message);} });
            root.querySelector('[data-shell-create]').addEventListener('submit',async function(event){
                event.preventDefault();var form=this,input=form.elements.title,button=form.querySelector('button'),message=form.querySelector('[role=status]');
                if(!input.value.trim()||button.disabled)return;button.disabled=true;input.disabled=true;
                try{var key=await queueNote(undefined,{title:input.value.trim(),content:'',parent:0});input.value='';await navigate('edit/'+encodeURIComponent(key));}
                catch(error){message.textContent=error.message;}finally{button.disabled=false;input.disabled=false;}
            });
            root.querySelector('[data-shell-sync]').addEventListener('click', async () => { await outbox.sync(); if (navigator.onLine) await refreshIndex().catch(error => status(error.message)); if (canRefreshView()) await render(); });
            root.querySelector('[data-shell-menu]').addEventListener('click', function () { var sidebar = root.querySelector('.memex-sidebar'), opened = sidebar.classList.toggle('is-open'); this.setAttribute('aria-expanded', String(opened)); });
            document.addEventListener('click', function (event) {
                var anchor = event.target.closest('a[href]'); if (!anchor || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || anchor.getAttribute('target') || anchor.hasAttribute('download')) return;
                var address = new URL(anchor.getAttribute('href'),location.href); if (address.origin !== location.origin || !address.pathname.startsWith(new URL(config.scope).pathname)) return;
                var path = address.pathname.slice(new URL(config.scope).pathname.length); if (['import','export','reminders'].includes(path.replace(/\/$/,''))) return;
                event.preventDefault(); navigate(path + address.search + address.hash).catch(error => status(error.message));
            });
            if (window.aiAssistant && typeof window.aiAssistant.onToolCall === 'function') window.aiAssistant.onToolCall(function(context){return !!(context&&context.success);},async function(){if(!activeEditor){await refreshIndex();await render();}});
            window.addEventListener('popstate', async function(){try{await draftWrites;await render();lastPath=location.href;}catch(error){history.pushState(null,'',lastPath);status('Could not save your draft. Keep this page open and copy your text.');}});
            window.addEventListener('beforeunload',function(event){if(pendingDraftWrites||draftFailure){event.preventDefault();event.returnValue='';}});
            window.addEventListener('offline', () => { offline = true; status('Offline — your drafts and changes are saved on this device.'); chrome(); });
            window.addEventListener('online', async () => { await outbox.sync(); await refreshIndex().catch(error => status(error.message)); if (canRefreshView()) await render(); });
            if (navigator.onLine) { try { await refreshIndex(); if (canRefreshView()) await render(); } catch (error) { status('Using local notes. ' + error.message); } outbox.sync(); }

        } catch (error) { status(error.message); view.replaceChildren(node('p', 'The app could not open its local data or verify the session. Your saved work has not been cleared. Reopen Memex online with the same account.')); }
    }
    boot();
})();
