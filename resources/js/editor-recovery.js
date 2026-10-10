export function recoverRepeaterRows(list, template, prefix, entries, refresh) {
    const indexes = new Set();
    for (const [name] of entries) {
        if (!name.startsWith(prefix+'[')) continue;
        const match=name.slice(prefix.length).match(/^\[(\d+)\]/);
        if (match) indexes.add(Number(match[1]));
    }
    list.replaceChildren();
    for (const index of [...indexes].sort((a,b)=>a-b)) {
        const row=template.content.firstElementChild.cloneNode(true);
        for (const element of row.querySelectorAll('[name]')) element.name=element.name.replace('[__INDEX__]',`[${index}]`);
        list.appendChild(row);
    }
    refresh();

}

export function installRepeaterRecovery(repeater,list,template,refresh) {
    const prototype=template.content.firstElementChild.querySelector('[name]');
    const prefix=prototype?.name.split('[__INDEX__]')[0];
    if (!prefix) return;
    repeater.addEventListener('editor:recover-rows',event=>recoverRepeaterRows(list,template,prefix,event.detail.entries,refresh));
}

export function chooseRecovery(local,server,now=Date.now()) {
    const candidates=[local,server].filter(x=>x?.payload && (!x.expires || x.expires>now));
    return candidates.sort((a,b)=>(b.updated_at||0)-(a.updated_at||0))[0]||null;
}

export { refreshRecoveryMedia } from './media-selection-preview.js';

export function applyRecoveryFields(form, entries) {
    const blocked=new Set(['_token','_method','_editor_lock','_editor_base','status','return']);
    const grouped=new Map();
    for (const [name,value] of entries) {
        if(blocked.has(name))continue;
        if(!grouped.has(name))grouped.set(name,[]);
        grouped.get(name).push(value);
    }
    for (const element of form.elements) {
        const values=grouped.get(element.name);
        if(blocked.has(element.name)||['file','submit','button'].includes(element.type))continue;
        if(element.type==='checkbox'||element.type==='radio')element.checked=values?.includes(element.value)||false;
        else if(element.tagName==='SELECT' && element.multiple)for(const option of element.options)option.selected=values?.includes(option.value)||false;
        else if(values)element.value=values[values.length-1];
        if(element.id && window.tinymce?.get(element.id))window.tinymce.get(element.id).setContent(element.value);
    }
    window.refreshMediaSelectorsWithin?.(form);
}

/** Recovery never changes publication status or submits the normal save endpoint. */
export function initEditorRecovery(form, config, csrfToken) {
    if (!config) return;
    const storageKey = `ruweb:draft:${config.user}:${config.url}`;
    let base = config.fingerprint;
    let timer;
    let last = '';
    let lastRemote = '';
    let lastUpdated = 0;
    let remoteQueue=Promise.resolve();
    const baseInput = document.createElement('input');
    baseInput.type = 'hidden'; baseInput.name = '_editor_base'; baseInput.value = base;
    form.append(baseInput);
    const blocked = new Set(['_token','_method','_editor_lock','_editor_base','status','return']);
    const snapshot = () => [...new FormData(form).entries()].filter(([name,value]) => !blocked.has(name) && typeof value === 'string');
    last=JSON.stringify(snapshot()); lastRemote=last;
    const headers = {'X-CSRF-TOKEN':csrfToken,'Content-Type':'application/json',Accept:'application/json'};
    async function persist() {
        const payload = snapshot();
        const serialized = JSON.stringify(payload);
        if(serialized===last && serialized===lastRemote)return;
        if (serialized !== last) lastUpdated=Date.now();
        last = serialized;
        const record = {payload,base_fingerprint:base,updated_at:lastUpdated,expires:Date.now()+14*86400000};
        try {localStorage.setItem(storageKey,JSON.stringify(record));} catch (_) {}
        if (serialized === lastRemote) return;
        remoteQueue=remoteQueue.catch(()=>{}).then(async()=> {
            if(serialized===lastRemote)return;
            try {const response=await fetch(config.url,{method:'POST',headers,body:JSON.stringify(record),keepalive:true});if(response.ok)lastRemote=serialized;} catch (_) {}
        });
        await remoteQueue;
    }
    function deleteRemoteDraft() {
        const discardedSnapshot=JSON.stringify(snapshot());
        remoteQueue=remoteQueue.catch(()=>{}).then(async()=> {
            try {await fetch(config.url,{method:'DELETE',headers});lastRemote=discardedSnapshot;} catch (_) {}
        });
    }
    function offer(record) {
        if (!record?.payload || (record.expires && record.expires <= Date.now())) return;
        if (JSON.stringify(record.payload) === JSON.stringify(snapshot())) return;
        const notice = document.createElement('div');
        notice.className='p-3 border border-amber-400 rounded space-x-3';
        notice.setAttribute('role','status');
        const text = document.createElement('p');
        text.textContent = record.base_fingerprint !== base ? config.conflict : config.message;
        notice.append(text);
        const recover = document.createElement('button'); recover.type='button'; recover.textContent=config.recover;
        recover.addEventListener('click',() => {
            for(const repeater of form.querySelectorAll('[data-repeater], [data-logo-repeater]')) repeater.dispatchEvent(new CustomEvent('editor:recover-rows',{detail:{entries:record.payload}}));
            applyRecoveryFields(form,record.payload);
            // Keep current base as an explicit merge: recovered edits are reviewed and then saved by the user.
            form.dispatchEvent(new Event('change',{bubbles:true})); notice.remove();
        });
        const discard = document.createElement('button'); discard.type='button'; discard.textContent=config.discard;
        discard.addEventListener('click',() => {clearTimeout(timer);last=JSON.stringify(snapshot());lastRemote=last;try {localStorage.removeItem(storageKey);} catch (_) {} deleteRemoteDraft(); notice.remove();});
        notice.append(recover,discard); form.prepend(notice);
    }
    let local;
    try {local=JSON.parse(localStorage.getItem(storageKey));} catch (_) {}
    fetch(config.url,{headers}).then(r=>r.ok?r.json():null).then(data=> {
        const server = data?.draft ? {payload:data.draft,base_fingerprint:data.base_fingerprint,updated_at:data.updated_at} : null;
        offer(chooseRecovery(local,server));
    }).catch(()=>offer(chooseRecovery(local,null)));
    window.addEventListener('online',persist);
    const queue=()=> {clearTimeout(timer);timer=setTimeout(persist,1200);};
    form.addEventListener('input',queue); form.addEventListener('change',queue);
    window.addEventListener('pagehide',persist);
    form.addEventListener('editor:saved', event=> {
        base=event.detail?.fingerprint || base; baseInput.value=base;
        const submitted=event.detail?.submittedSnapshot ? JSON.parse(event.detail.submittedSnapshot).filter(([name])=>!blocked.has(name)) : snapshot();
        if (JSON.stringify(submitted) !== JSON.stringify(snapshot())) { last=''; persist(); return; }
        last=JSON.stringify(snapshot()); lastRemote=last;
        try {localStorage.removeItem(storageKey);} catch (_) {}
        deleteRemoteDraft();
    });
}
