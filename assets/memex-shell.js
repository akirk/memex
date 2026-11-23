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
        notes.forEach(note => merged.set(String(note.postId || note.id), note));
        return Array.from(merged.values());
    }
    function decodeSlug(value) { try { return decodeURIComponent(value || ''); } catch (_) { return value; } }
    function findNote(value) { return allNotes().find(note => String(note.id) === String(value) || String(note.postId) === String(value) || (note.slug === value || decodeSlug(note.slug) === value) || note.title.toLowerCase() === String(value).toLowerCase()); }
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
        notes.slice().sort((a, b) => a.title.localeCompare(b.title)).forEach(note => { var li = node('li'); li.append(link(note.title, 'note/' + encodeURIComponent(note.id))); available.append(li); });
        root.querySelector('[data-shell-available-count]').textContent = '(' + notes.length + ')';
        var conflicts = root.querySelector('[data-shell-conflicts]'); conflicts.replaceChildren();
        entries.filter(entry => entry.state === 'conflict').forEach(entry => {
            var item = node('li'); item.append(node('strong', 'Conflict: ' + entry.title), document.createTextNode(' '), link('Review versions', 'edit/' + encodeURIComponent(entry.noteId))); conflicts.append(item);
        });
        var folders = root.querySelector('[data-shell-folders]'); folders.replaceChildren();
        var sorted = allNotes().sort((a, b) => a.title.localeCompare(b.title));
        function branch(parent, visited) {
            var ul = node('ul');
            sorted.filter(note => Number(note.parent || 0) === parent).forEach(note => {
                if (visited.has(note.id)) return;
                var seen = new Set(visited); seen.add(note.id);
                var li = node('li'); li.append(link(note.title + (note.pending ? ' •' : ''), 'note/' + encodeURIComponent(note.id)));
                var children = branch(Number(note.postId || note.id), seen); if (children.childElementCount) li.append(children); ul.append(li);
            });
            return ul;
        }
        folders.append(branch(0, new Set()));
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
        items.forEach(note => { var li = node('li', null, {class: 'memex-note-list-item'}); li.append(link(note.title, 'note/' + encodeURIComponent(note.id))); if (note.pending) li.append(node('small', ' Saved locally')); if (note.excerpt) li.append(node('p', note.excerpt)); ul.append(li); });
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
        title(note.title);
        var actions = node('p'); if (note.editable) actions.append(link('Edit note', 'edit/' + encodeURIComponent(note.id))); actions.append(document.createTextNode(' · '), link('Backlinks', 'backlinks/' + encodeURIComponent(note.id))); if(note.postId)actions.append(document.createTextNode(' · '),link('New nested note','new?parent='+note.postId)); view.append(actions);
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
        await draftWrites;
        await outbox.enqueue(operation, draftId);
        var updated = Object.assign({}, note || {id: key, postId: 0, slug: '', tags: [], links: [], editable: true, status: 'draft'}, values, {id: key, pending: true, links: outgoingLinks(values.content), lastOperation: id, version: operation.baseVersion || '', excerpt: values.content.slice(0, 160)});
        await data.put('notes', updated); await loadLocal();
        outbox.sync();
        return key;
    }
    async function editNote(value) {
        var token = generation, note;
        if (value) {
            try { note = await fetchNote((findNote(value) || {}).postId || value); }
            catch (error) { if (token === generation) unavailable(findNote(value), error.message); return; }
            if (!note) { if (token === generation) unavailable(findNote(value)); return; }
            if (!note.editable) { title('Read-only note'); return; }
        }
        if (token !== generation) return;
        title(note ? 'Edit ' + note.title : 'New note');
        var key = note ? note.id : 'new';
        var sessionKey = 'memex-editor-tab:' + config.scope, tabId;
        try { tabId = sessionStorage.getItem(sessionKey) || uuid(); sessionStorage.setItem(sessionKey,tabId); } catch (_) { tabId = uuid(); }
        var draftPrefix = 'shell-edit:' + key, draftId = draftPrefix + ':' + tabId;
        var savedDrafts = (await outbox.rows('drafts')).filter(item => item.id === draftPrefix || item.id.startsWith(draftPrefix + ':'));
        var draft = savedDrafts.find(item => item.id === draftId) || savedDrafts.find(item => item.id === draftPrefix);
        if (token !== generation) return;
        var form = node('form', null, {class: 'memex-edit-form', 'data-shell-editor': '', 'data-ai-assistant-important':''});
        var label = node('label', 'Title'), input = node('input', null, {name: 'title', required: ''}); input.value = draft ? draft.title : note ? note.title : new URL(location.href).searchParams.get('title') || ''; label.append(input); form.append(label);
        function isDescendant(item){var current=item,seen=new Set();while(current&&current.parent&&!seen.has(current.id)){seen.add(current.id);if(note&&Number(current.parent)===Number(note.postId))return true;current=findNote(current.parent);}return false;}
        var parentLabel = node('label', 'Nested under'), parent = node('select', null, {name: 'parent'}); parent.append(node('option', '— Top level —', {value: '0'}));
        allNotes().filter(item => item.postId && (!note || (item.id !== note.id && !isDescendant(item)))).forEach(item => parent.append(node('option', item.title, {value: String(item.postId)})));
        var selectedParent = String(draft ? draft.parent : note ? note.parent || 0 : Number(new URL(location.href).searchParams.get('parent') || 0));
        if(!Array.from(parent.options).some(option=>option.value===selectedParent))parent.append(node('option','Current parent',{value:selectedParent}));
        parent.value = selectedParent; parentLabel.append(parent); form.append(parentLabel);
        var editorHost = node('div', null, {class: 'memex-markdown-editor'}), textarea = node('textarea', null, {name: 'content', rows: '24', 'aria-label': 'Note Markdown'});
        textarea.value = draft ? draft.content : note ? note.content : ''; form.append(editorHost, textarea);
        var notice = node('p', draft ? 'Restored your draft from this device.' : '', {role: 'status'}); form.append(notice);
        var save = node('button', note ? 'Save note' : 'Save draft', {type: 'submit', class: 'memex-button memex-button-primary'}); form.append(save);
        if (note && note.status === 'draft') form.append(node('button', 'Publish', {type: 'submit', name: 'publish', value: '1', class: 'memex-button'}));
        form.append(document.createTextNode(' '), link('Cancel', note ? 'note/' + encodeURIComponent(note.id) : ''));
        view.append(form);
        var editor, changed = false, saving = false;
        var baseline = draft && draft.baseVersion || note && note.version || '';
        var baselineOperation = draft && draft.baseOperation || note && note.pending && note.lastOperation || undefined;
        function snapshot() { return {id: draftId, title: input.value, content: editor ? editor.getValue() : textarea.value, parent: Number(parent.value || 0), baseVersion: baseline, baseOperation: baselineOperation, updatedAt: new Date().toISOString()}; }
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
            async function discardRelated(pendingIds) {
                pendingIds = pendingIds || entries.filter(entry => related(entry, note)).map(entry => entry.id);
                await outbox.transaction(['entries'], 'readwrite', tx => pendingIds.forEach(id => tx.objectStore('entries').delete(id)));
                await loadLocal();
            }
            keepServer.addEventListener('click', async function () {
                keepServer.disabled=true;keepLocal.disabled=true;Array.from(form.elements).forEach(control=>control.disabled=true);
                try { await draftWrites; await discardRelated(); await data.put('notes', Object.assign({}, remote, {id: note.id})); await outbox.transaction(['drafts'], 'readwrite', tx => tx.objectStore('drafts').delete(draftId)); await loadLocal(); await navigate('note/' + encodeURIComponent(note.id)); }
                catch (error) { notice.textContent = error.message;keepServer.disabled=false;keepLocal.disabled=false;Array.from(form.elements).forEach(control=>control.disabled=false); }
            });
            keepLocal.addEventListener('click', async function () {
                keepLocal.disabled = true;keepServer.disabled=true;Array.from(form.elements).forEach(control=>control.disabled=true);
                try {
                    var reviewed = snapshot(); reviewed.baseVersion = remote.version; delete reviewed.baseOperation;
                    // Persist the reviewed replacement before removing any older pending work.
                    var replacedIds = entries.filter(entry => related(entry, note)).map(entry => entry.id);
                    var opId = uuid();
                    await draftWrites;
                    await outbox.enqueue(Object.assign({}, reviewed, {id: opId, kind: 'edit', noteId: String(remote.postId), localId: note.id, userId: config.userId, createdAt: new Date().toISOString()}),draftId);
                    await discardRelated(replacedIds);
                    await data.put('notes', Object.assign({}, note, reviewed, {id:note.id,pending: true, lastOperation: opId, version: remote.version}));
                    await loadLocal(); outbox.sync(); await navigate('note/' + encodeURIComponent(note.id));
                } catch (error) { notice.textContent = error.message; keepLocal.disabled = false;keepServer.disabled=false;Array.from(form.elements).forEach(control=>control.disabled=false); }
            });
        }
    }
    function dailyDate() {
        var now = new Date();
        if (/^[+-]\d{2}:\d{2}$/.test(config.timezone)) { var sign = config.timezone[0] === '-' ? -1 : 1; now = new Date(now.getTime() + sign * (Number(config.timezone.slice(1, 3)) * 60 + Number(config.timezone.slice(4))) * 60000); return now.toISOString().slice(0, 10); }
        var parts = new Intl.DateTimeFormat('en-CA', {timeZone: config.timezone, year: 'numeric', month: '2-digit', day: '2-digit'}).formatToParts(now), values = {}; parts.forEach(part => values[part.type] = part.value); return values.year + '-' + values.month + '-' + values.day;
    }
    function dailyCapture(date) {
        title(date);
        var form = node('form'), area = node('textarea', null, {rows: '8', required: '', 'aria-label': 'Daily entry'}), save = node('button', 'Append to daily note', {type: 'submit'}), message = node('p', '', {role: 'status'}); form.append(area, save, message); view.append(form);
        var tabKey = 'memex-editor-tab:' + config.scope, tab;
        try { tab = sessionStorage.getItem(tabKey) || uuid(); sessionStorage.setItem(tabKey,tab); } catch (_) { tab=uuid(); }
        var draftId = 'shell-daily:' + date + ':' + tab;
        activeEditor = {form:form,clean:function(){return !area.value.trim() && document.activeElement !== area;}};
        outbox.rows('drafts').then(rows => { var draft = rows.find(row => row.id === draftId); if (draft && !area.value) area.value = draft.content; });
        area.addEventListener('input', () => { var text = area.value; saveDraftRecord({id:draftId,content:text}); });
        form.addEventListener('submit', async function (event) {
            event.preventDefault(); if(!area.value.trim())return; save.disabled = true; area.disabled = true; var text = area.value;
            try {
                var clockParts = new Intl.DateTimeFormat('en-GB', {timeZone: /^[+-]/.test(config.timezone) ? 'UTC' : config.timezone, hour: '2-digit', minute: '2-digit', hourCycle: 'h23'}).format(new Date());
                if (/^[+-]/.test(config.timezone)) { var sign = config.timezone[0] === '-' ? -1 : 1; clockParts = new Date(Date.now() + sign * (Number(config.timezone.slice(1,3)) * 60 + Number(config.timezone.slice(4))) * 60000).toISOString().slice(11,16); }
                await draftWrites; await outbox.enqueue({id: uuid(), kind: 'daily', date: date, clock: clockParts, title: '', content: text, userId: config.userId, createdAt: new Date().toISOString()}, draftId);
                if (area.value === text) area.value = ''; message.textContent = 'Saved on this device.'; await loadLocal(); await render(); outbox.sync();
            } catch (error) { message.textContent = error.message; } finally { save.disabled = false; area.disabled = false; }
        });
        var pending = entries.filter(entry => entry.kind === 'daily' && entry.date === date);
        pending.forEach(entry => view.append(node('pre', entry.clock + ' ' + entry.content)));
    }
    async function render() {
        var token = ++generation; await draftWrites.catch(() => {}); activeEditor = null; view.replaceChildren(); status(lastMessage || (!offline && navigator.onLine ? '' : 'Offline — using notes saved on this device.'));
        var path = decodeURIComponent(location.pathname.slice(new URL(config.scope).pathname.length)).replace(/\/$/, ''), parts = path.split('/'), kind = parts[0], id = parts.slice(1).join('/');
        if (['import','export','reminders'].includes(kind)) { title(kind.charAt(0).toUpperCase() + kind.slice(1)); view.append(node('p', 'This tool needs a connection. Your saved notes and editor remain available offline.')); return; }
        if (kind === 'note') return showNote(id);
        if (kind === 'edit') return editNote(id);
        if (kind === 'new') return editNote();
        if (kind === 'daily' || kind === 'capture' || kind === 'quick-capture') {
            var date = id || dailyDate();
            if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || !Number.isFinite(Date.parse(date + 'T12:00:00Z')) || new Date(date + 'T12:00:00Z').toISOString().slice(0,10) !== date || date > dailyDate()) { title('Invalid daily date'); return; }
            var daily = allNotes().find(note => note.daily === date);
            if (daily) { await showNote(daily.id); if (token === generation) { view.append(node('h2', 'Add an entry')); dailyCaptureForm(date); } }
            else dailyCapture(date);
            return;
        }
        if (kind === 'search') {
            title('Search'); var input = node('input', null, {type: 'search', 'aria-label': 'Search your notes', placeholder: 'Search notes…'}), results = node('section'); view.append(input, results);
            function filter() { var query = input.value.toLowerCase(); results.replaceChildren(); allNotes().filter(note => (note.title + ' ' + (note.content || note.excerpt || '')).toLowerCase().includes(query)).forEach(note => { var p = node('p'); p.append(link(note.title, 'note/' + encodeURIComponent(note.id))); results.append(p); }); }
            input.addEventListener('input', filter); filter(); input.focus(); return;
        }
        if (kind === 'tags') {
            title('Tags'); var tags = new Map(); allNotes().forEach(note => (note.tags || []).forEach(tag => tags.set(tag.slug, tag.name))); tags.forEach((name, slug) => { var p = node('p'); p.append(link(name, 'tag/' + slug)); view.append(p); }); return;
        }
        if (kind === 'tag') { title('Tag: ' + id); return list(allNotes().filter(note => (note.tags || []).some(tag => tag.slug === id))); }
        if (kind === 'backlinks') { var target = findNote(id); title('Backlinks' + (target ? ': ' + target.title : '')); return list(target ? allNotes().filter(note => (note.links || []).some(id => String(id) === String(target.postId || target.id))) : []); }
        if (kind === 'graph') {
            title('Graph'); var graphNotes = allNotes(), edges = graphNotes.flatMap(note => (note.links || []).map(target => [note, findNote(target)]).filter(edge => edge[1]));
            var graph = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); graph.setAttribute('viewBox', '0 0 800 600'); graph.setAttribute('role', 'img'); graph.setAttribute('aria-label', 'Links between notes');
            var linked = Array.from(new Map(edges.flat().map(note => [note.id, note])).values()), positions = new Map(linked.map((note, i) => [note.id, {x: 400 + 240 * Math.cos(i * Math.PI * 2 / linked.length), y: 300 + 220 * Math.sin(i * Math.PI * 2 / linked.length)}]));
            edges.forEach(edge => { var line = document.createElementNS(graph.namespaceURI, 'line'), a = positions.get(edge[0].id), b = positions.get(edge[1].id); Object.entries({x1:a.x,y1:a.y,x2:b.x,y2:b.y,stroke:'currentColor',opacity:'.25'}).forEach(([key,value]) => line.setAttribute(key,value)); graph.append(line); });
            linked.forEach(note => { var anchor = document.createElementNS(graph.namespaceURI, 'a'); anchor.setAttribute('href', url('note/' + note.id)); var point = positions.get(note.id), text = document.createElementNS(graph.namespaceURI, 'text'); text.setAttribute('x', point.x); text.setAttribute('y', point.y); text.setAttribute('text-anchor', 'middle'); text.textContent = note.title; anchor.append(text); graph.append(anchor); }); view.append(graph); if (!edges.length) view.append(node('p', 'No links between notes yet.')); return;
        }
        var items = allNotes();
        if (kind === 'orphans') { var targets = new Set(items.flatMap(note => (note.links || []).map(String))); title('Unlinked notes'); items = items.filter(note => !(note.links || []).length && !targets.has(String(note.postId || note.id))); }
        else if (kind === 'broken') { title('Unresolved notes'); items = items.filter(note => note.stub); }
        else title('All notes');
        list(items.sort((a,b) => String(b.modified || '').localeCompare(String(a.modified || ''))));
    }
    // Reuse the same daily composer below an existing daily without replacing its view.
    function dailyCaptureForm(date) { var oldChildren = Array.from(view.childNodes); view.replaceChildren(); dailyCapture(date); var composer = Array.from(view.childNodes).slice(1); view.replaceChildren.apply(view, oldChildren.concat(composer)); }
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
    }
    async function boot() {
        try {
            var boot = await window.wpAppBootstrap; if (!boot) return; config = boot.data; offline = boot.offline;
            outbox = new window.WpAppOutbox(Object.assign({}, config, {
                databaseName: 'memex-capture:' + config.scope + ':' + config.userId,
                sessionAction: 'memex_capture_session', syncAction: 'memex_capture_sync', payloadField: 'capture',
                acknowledge: (receipt, entry) => receipt.id === entry.id && !!receipt.postId,
                onAcknowledged: acknowledged
            }));
            data = new window.WpAppLocalData(outbox);
            await loadLocal();
            var oldDrafts = (await outbox.rows('drafts')).filter(draft => !draft.id.startsWith('shell-') && draft.content);
            if (oldDrafts.length) {
                root.querySelector('[data-shell-old-drafts]').hidden = false;
                var oldList = root.querySelector('[data-shell-old-draft-list]');
                oldDrafts.forEach(draft => { var details = node('details'); details.append(node('summary', draft.title || 'Daily capture draft'), node('pre', draft.content)); oldList.append(details); });
            }
            await render();
            outbox.subscribe(async function () { try { await loadLocal(); if (!outbox.status().running && canRefreshView() && !/\/(edit|new)(\/|$)/.test(location.pathname)) await render(); } catch(error){status(error.message);} });
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
