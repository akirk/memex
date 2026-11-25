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
    function editor(host, value, save) {
        var settings=normalize(value), form=node('form',null,{'data-sidebar-settings':'',class:'memex-sidebar-settings'});
        var message=node('p',null,{role:'status','aria-live':'polite'}), saveButton=node('button','Save settings',{type:'submit',class:'memex-button'});
        host.append(node('p','Choose what appears in your sidebar. Move items up or down to change their order. These settings are yours for this site.'));
        function group(name, title, labels) {
            var fieldset=node('fieldset'), list=node('ol',null,{class:'memex-settings-list'});
            fieldset.append(node('legend',title),list);
            function draw(focusId, direction) {
                list.replaceChildren();
                settings[name].forEach(function(item,index){
                    var row=node('li',null,{'data-setting-id':item.id}), label=node('label'), checkbox=node('input',null,{type:'checkbox'});
                    checkbox.checked=item.visible;
                    checkbox.addEventListener('change',()=>{item.visible=checkbox.checked;message.textContent='Unsaved changes.';});
                    label.append(checkbox,document.createTextNode(' '+labels[item.id]));row.append(label);
                    var controls=node('span',null,{class:'memex-settings-move'});
                    [-1,1].forEach(function(delta){
                        var word=delta<0?'up':'down',button=node('button',delta<0?'↑':'↓',{type:'button','aria-label':'Move '+labels[item.id]+' '+word});
                        button.disabled=index+delta<0||index+delta>=settings[name].length;
                        button.addEventListener('click',function(){
                            settings[name].splice(index,1);settings[name].splice(index+delta,0,item);
                            message.textContent='Unsaved changes.';draw(item.id,word);
                        });
                        controls.append(button);
                        if(focusId===item.id && direction===word)requestAnimationFrame(()=>button.focus());
                    });
                    row.append(controls);list.append(row);
                });
            }
            draw();fieldset.redraw=draw;form.append(fieldset);
        }
        group('sections','Sidebar sections',sections);
        group('navigation','Navigation links',navigation);
        var reset=node('button','Restore defaults',{type:'button',class:'memex-button-secondary'});
        reset.addEventListener('click',function(){settings=normalize({});form.querySelectorAll('fieldset').forEach(fieldset=>fieldset.redraw());message.textContent='Defaults restored. Save settings to apply them.';});
        var actions=node('p',null,{class:'memex-settings-actions'});actions.append(saveButton,reset);form.append(actions,message);host.append(form);
        form.addEventListener('submit',async function(event){
            event.preventDefault();saveButton.disabled=true;reset.disabled=true;
            form.querySelectorAll('fieldset').forEach(fieldset=>fieldset.disabled=true);
            try{message.textContent=await save(normalize(settings));}
            catch(error){message.textContent=error.message;}
            finally{saveButton.disabled=false;reset.disabled=false;form.querySelectorAll('fieldset').forEach(fieldset=>fieldset.disabled=false);}
        });
    }
    window.MemexSidebar={normalize:normalize,apply:apply,editor:editor};
    document.querySelectorAll('[data-memex-sidebar-settings]').forEach(function(sidebar){
        try{apply(sidebar,JSON.parse(sidebar.dataset.memexSidebarSettings));}catch(_){}
    });
})();
