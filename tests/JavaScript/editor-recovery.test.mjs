import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chooseRecovery, recoverRepeaterRows } from '../../resources/js/editor-recovery.js';

test('recovery picks newest retained local or server draft and ignores expired browser copies',()=> {
    const old={payload:[['title','Old']],updated_at:10,expires:100};
    const fresh={payload:[['title','Fresh']],updated_at:20};
    assert.equal(chooseRecovery(old,fresh,30),fresh);
    assert.equal(chooseRecovery({...old,updated_at:50,expires:25},fresh,30),fresh);
    assert.equal(chooseRecovery({...old,updated_at:50},fresh,30).updated_at,50);
});

test('recovery rebuilds added deleted and reordered repeater rows from structured field names',()=> {
    globalThis.window={};
    const makeRow=()=> {
        const fields=[{name:'content_json[sections][features][logos][__INDEX__][label]'},{name:'content_json[sections][features][logos][__INDEX__][image_id]'}];
        return {fields,querySelectorAll:()=>fields};
    };
    const template={content:{firstElementChild:{cloneNode:makeRow}}};
    const list={children:[makeRow(),makeRow(),makeRow()],replaceChildren(){this.children=[];},appendChild(row){this.children.push(row);}};
    let refreshes=0;
    const prefix='content_json[sections][features][logos]';
    recoverRepeaterRows(list,template,prefix,[[`${prefix}[1][label]`,'Second'],[`${prefix}[0][label]`,'First'],[`${prefix}[0][image_id]`,'4']],()=>refreshes++);
    assert.equal(list.children.length,2);
    assert.match(list.children[0].fields[0].name,/\[0\]\[label\]$/);
    assert.match(list.children[1].fields[0].name,/\[1\]\[label\]$/);
    recoverRepeaterRows(list,template,prefix,[[prefix,'']],()=>refreshes++);
    assert.equal(list.children.length,0);
    recoverRepeaterRows(list,template,prefix,[...Array(4)].map((_,i)=>[`${prefix}[${i}][label]`,String(i)]),()=>refreshes++);
    assert.equal(list.children.length,4);
    assert.equal(refreshes,3);
    delete globalThis.window;
});

const {applyRecoveryFields,refreshRecoveryMedia}=await import('../../resources/js/editor-recovery.js');

test('recovery clears absent selections preserves publication and refreshes media after assigning recovered ids',()=> {
    const image={name:'featured_image',type:'hidden',value:'1'};
    const check={name:'enabled',type:'checkbox',value:'1',checked:true};
    const multi={name:'categories[]',tagName:'SELECT',multiple:true,options:[{value:'3',selected:true},{value:'4',selected:true}]};
    const status={name:'status',value:'published',type:'hidden'};
    const form={elements:[image,check,multi,status]};let refreshed;
    globalThis.window={refreshMediaSelectorsWithin:()=>{refreshed=image.value;}};
    applyRecoveryFields(form,[['featured_image','2'],['categories[]','4'],['status','draft']]);
    assert.equal(image.value,'2');assert.equal(refreshed,'2');assert.equal(check.checked,false);
    assert.equal(multi.options[0].selected,false);assert.equal(multi.options[1].selected,true);assert.equal(status.value,'published');
    applyRecoveryFields(form,[['featured_image','2']]);assert.equal(multi.options[1].selected,false);
    delete globalThis.window;
});

test('late media lookup cannot overwrite a recovered id and failed lookups preserve recovery references',async()=> {
    const selector={dataset:{}};const input={value:'1'};const pending=new Map();const renders=[];
    const fetchItem=id=>new Promise(resolve=>pending.set(id,resolve));
    const render=(_selector,media)=>{input.value=media?String(media.id):'';renders.push(media?.id||null);};
    const original=refreshRecoveryMedia(selector,input,render,fetchItem);
    input.value='2';const recovered=refreshRecoveryMedia(selector,input,render,fetchItem);
    pending.get('1')({id:1});await original;assert.equal(input.value,'2');
    pending.get('2')({id:2});await recovered;assert.equal(input.value,'2');assert.equal(renders.at(-1),2);
    await refreshRecoveryMedia(selector,input,render,async()=>{throw new Error('offline');});assert.equal(input.value,'2');
});
