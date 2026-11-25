/* Shared sidebar layout for the local app and online tools. */
(function () {
    'use strict';
    var sections = {
        'quick-capture':'Quick capture', search:'Search form', navigation:'Navigation',
        folders:'Folders', create:'New note', sync:'Offline & sync'
    };
    var navigation = {
        notes:'All notes', daily:'Today', reminders:'Reminders', search:'Search', graph:'Graph',
        tags:'Tags', orphans:'Orphans', broken:'Broken links', import:'Import', export:'Export'
    };
    function normalize(input) {
        var result = {};
        Object.entries({sections:sections,navigation:navigation}).forEach(function (group) {
            var name=group[0], catalog=group[1], seen=new Set();
            result[name]=[];
            (Array.isArray(input && input[name]) ? input[name] : []).forEach(function (item) {
                if (!item || !Object.hasOwn(catalog,item.id) || seen.has(item.id)) return;
                seen.add(item.id);
                result[name].push({id:item.id,visible:typeof item.visible==='boolean' ? item.visible : !(name==='sections' && item.id==='search')});
            });
            Object.keys(catalog).filter(id=>!seen.has(id)).forEach(id=>result[name].push({id:id,visible:!(name==='sections' && id==='search')}));
        });
        return result;
    }
    function apply(sidebar, value) {
        var settings=normalize(value), body=sidebar.querySelector('.memex-sidebar-body');
        if (!body) return;
        var index=0, visible=[];
        settings.sections.forEach(function (item) {
            var element=sidebar.querySelector('[data-sidebar-section="'+item.id+'"]');
            if (!element) return;
            if (body.children[index]!==element) body.insertBefore(element,body.children[index]||null);
            index++;
            element.hidden=!item.visible || (item.id==='folders' && !element.childElementCount);
            if (!element.hidden) visible.push(element);
        });
        var sync=sidebar.querySelector('[data-sidebar-section="sync"]');
        if(sync)sync.classList.toggle('is-last-section',visible[visible.length-1]===sync);
        var nav=sidebar.querySelector('[data-sidebar-section="navigation"]');
        if(nav)settings.navigation.forEach(function(item,index){
            var anchor=nav.querySelector('[data-sidebar-link="'+item.id+'"]');
            if(!anchor)return;
            if(nav.children[index]!==anchor)nav.insertBefore(anchor,nav.children[index]||null);
            anchor.hidden=!item.visible;
        });
    }
    function node(tag, text, attributes) {
        var element=document.createElement(tag);
        if(text)element.textContent=text;
        Object.entries(attributes||{}).forEach(([key,value])=>element.setAttribute(key,value));
        return element;
    }
    function editor(host, value, save, preview) {
        var settings=normalize(value), revision=0;
        var form=node('form',null,{'data-sidebar-settings':'',class:'memex-sidebar-settings'});
        var message=node('span','Changes save automatically.',{role:'status','aria-live':'polite',class:'memex-muted'});
        host.append(node('p','Choose what appears in your sidebar. Changes apply and save automatically for your account on this site.'));
        var instructions=node('p','Drag the handles to reorder items. With a handle focused, use the Up and Down arrow keys.',{id:'memex-sidebar-drag-help',class:'memex-muted'});
        form.append(instructions);
        function changed() {
            var current=++revision;
            message.textContent='Saving…';
            Promise.resolve(save(normalize(settings))).then(function(text){
                if(current===revision)message.textContent=text;
            }).catch(function(error){if(current===revision)message.textContent='Could not save settings. '+error.message;});
        }
        function group(name, title, labels) {
            var fieldset=node('fieldset'), list=node('ol',null,{class:'memex-settings-list'});
            fieldset.append(node('legend',title),list);
            function draw(focusId) {
                list.replaceChildren();
                settings[name].forEach(function(item){
                    var row=node('li',null,{'data-setting-id':item.id}), label=node('label'), checkbox=node('input',null,{type:'checkbox'});
                    checkbox.checked=item.visible;
                    checkbox.addEventListener('change',()=>{item.visible=checkbox.checked;changed();});
                    label.append(checkbox,document.createTextNode(' '+labels[item.id]));
                    var handle=node('button','⠿',{type:'button',class:'memex-settings-drag','aria-label':'Move '+labels[item.id],'aria-describedby':'memex-sidebar-drag-help'});
                    var drag=null;
                    function move(target) {
                        var from=settings[name].findIndex(entry=>entry.id===item.id);
                        if(from===target || target<0 || target>=settings[name].length)return false;
                        settings[name].splice(from,1);settings[name].splice(target,0,item);
                        list.insertBefore(row,list.children[from<target ? target+1 : target]||null);
                        if(drag)handle.setPointerCapture(drag.pointerId);
                        return true;
                    }
                    function finish(commit) {
                        if(!drag)return;
                        var active=drag;drag=null;row.classList.remove('is-dragging');
                        if(handle.hasPointerCapture(active.pointerId))handle.releasePointerCapture(active.pointerId);
                        if(!commit){settings[name]=active.original;if(preview)preview(null);draw(item.id);return;}
                        if(active.changed)changed();
                        handle.focus();
                    }
                    handle.addEventListener('pointerdown',function(event){
                        if(event.button!==0 || drag)return;
                        event.preventDefault();handle.focus();
                        drag={pointerId:event.pointerId,original:settings[name].slice(),changed:false};
                        handle.setPointerCapture(event.pointerId);row.classList.add('is-dragging');
                    });
                    handle.addEventListener('pointermove',function(event){
                        if(!drag || drag.pointerId!==event.pointerId)return;
                        if(event.clientY<48)window.scrollBy(0,-24);
                        else if(event.clientY>window.innerHeight-48)window.scrollBy(0,24);
                        var hit=document.elementFromPoint(event.clientX,event.clientY), target=hit && hit.closest('li[data-setting-id]');
                        if(!target || target.parentElement!==list)return;
                        var index=settings[name].findIndex(entry=>entry.id===target.dataset.settingId);
                        if(move(index)){drag.changed=true;if(preview)preview(normalize(settings));}
                    });
                    handle.addEventListener('pointerup',event=>{if(drag && drag.pointerId===event.pointerId)finish(true);});
                    handle.addEventListener('pointercancel',()=>finish(false));
                    handle.addEventListener('lostpointercapture',()=>finish(false));
                    handle.addEventListener('keydown',function(event){
                        if(event.key==='Escape' && drag){event.preventDefault();finish(false);return;}
                        if(event.key!=='ArrowUp' && event.key!=='ArrowDown')return;
                        event.preventDefault();
                        var index=settings[name].findIndex(entry=>entry.id===item.id);
                        if(move(index+(event.key==='ArrowUp'?-1:1))){changed();handle.focus();}
                    });
                    row.append(handle,label);list.append(row);
                    if(focusId===item.id)requestAnimationFrame(()=>handle.focus());
                });
            }
            draw();fieldset.redraw=draw;form.append(fieldset);
        }
        group('sections','Sidebar sections',sections);
        group('navigation','Navigation links',navigation);
        var reset=node('button','Restore defaults',{type:'button',class:'memex-button'});
        reset.addEventListener('click',function(){settings=normalize({});form.querySelectorAll('fieldset').forEach(fieldset=>fieldset.redraw());changed();});
        var actions=node('p',null,{class:'memex-settings-actions'});actions.append(reset,message);form.append(actions);host.append(form);
        form.addEventListener('submit',event=>event.preventDefault());
    }
    window.MemexSidebar={normalize:normalize,apply:apply,editor:editor};
    document.querySelectorAll('[data-memex-sidebar-settings]').forEach(function(sidebar){
        try{apply(sidebar,JSON.parse(sidebar.dataset.memexSidebarSettings));}catch(_){}
    });
})();
