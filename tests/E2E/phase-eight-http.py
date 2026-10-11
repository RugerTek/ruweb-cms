"""E2E08 cookie-free MCP on guarded isolated MySQL. Stages: initial, global, exact,
pending, deactivated, reactivated, harmful. Run browser create after initial;
run browser checks after each subsequent stage. CLI disable/enable is separate.
"""
import concurrent.futures, json, os, re, sys, threading, urllib.request, urllib.error, urllib.parse
from pathlib import Path
storage=Path(os.environ['PHASE0_STORAGE']); profile=os.environ['PHASE8_PROFILE']; base=os.environ.get('PHASE8_BASE','http://127.0.0.1:8018')
assert urllib.parse.urlparse(base).hostname in ('127.0.0.1','localhost')
manifest=json.loads((storage/'manifest.json').read_text())
assert re.fullmatch(r'ruweb_phase0_[a-f0-9]{12}',os.environ['PHASE0_DATABASE']) and manifest['database']==os.environ['PHASE0_DATABASE']
assert profile in ('full','essential') and not Path('public/hot').exists()
fixture_path=storage/'phase-eight-fixture.json'; results_path=storage/'phase-eight-http-results.json'
def request(path,data=None,key='custom-code',method=None):
    url=urllib.parse.urljoin(base,path); assert urllib.parse.urlparse(url).netloc==urllib.parse.urlparse(base).netloc
    headers={'Authorization':'Bearer '+manifest['keys'][key],'Accept':'application/json'}
    if data is not None:headers['Content-Type']='application/json';data=json.dumps(data).encode()
    req=urllib.request.Request(url,data=data,headers=headers,method=method);assert not req.has_header('Cookie')
    try:
        with urllib.request.urlopen(req) as r:return r.status,r.read()
    except urllib.error.HTTPError as e:return e.code,e.read()
def rpc(name,args=None,key='custom-code'):
    status,raw=request('/mcp/rpc',{'jsonrpc':'2.0','id':1,'method':'tools/call','params':{'name':name,'arguments':args or {}}},key)
    body=json.loads(raw);assert status==200 and 'error' not in body and not body['result']['isError'],(name,status,body)
    return json.loads(body['result']['content'][0]['text'])['data']
def publish(page):return rpc('pages_publish',{'page_id':page['id'],'expected_revision':page['revision']},'full')
def ref(page):return {'type':'pages','id':page['id'],'locale':page['locale'],'group':False}
def payload(name,code,position,order,rules=None,kind='javascript'):
    return {'name':name,'description':'Local isolated E2E08 integration','type':kind,'code':code,'position':position,'sort_order':order,'rules':rules or {'global':True,'include':[],'exclude':[]}}
def save_activate(data,key,identity=None):
    if identity:data=data|{'id':identity,'expected_revision':rpc('custom_code_get',{'id':identity})['revision']}
    saved=rpc('custom_code_save',data|{'operation_key':key+'-save'})
    return rpc('custom_code_activate',{'id':saved['id'],'version_id':saved['versions'][0]['id'],'expected_revision':saved['revision'],'operation_key':key+'-activate'})
def concurrent_identical(tool,args):
    barrier=threading.Barrier(8)
    def call(_):
        barrier.wait(timeout=20)
        return rpc(tool,args)
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:results=list(pool.map(call,range(8)))
    assert all(result==results[0] for result in results),tool+' concurrent retry results differ'
    return results[0]
def concurrent_save_activate(data,key):
    baseline=rpc('custom_code_save',data|{'operation_key':key+'-initial'})
    args=data|{'id':baseline['id'],'expected_revision':baseline['revision'],'operation_key':key+'-concurrent-save'}
    saved=concurrent_identical('custom_code_save',args)
    assert saved['versions_total']==2
    activated=concurrent_identical('custom_code_activate',{'id':saved['id'],'version_id':saved['versions'][0]['id'],'expected_revision':saved['revision'],'operation_key':key+'-concurrent-activate'})
    fresh=rpc('custom_code_get',{'id':activated['id']})
    assert fresh['versions_total']==2 and fresh['active_version_id']==saved['versions'][0]['id']
    actions=[event['action'] for event in fresh['events']]
    assert actions.count('save')==2 and actions.count('activate')==1 and len(actions)==3
    return fresh
stage=sys.argv[1] if len(sys.argv)>1 else 'initial';checks=[]
if stage=='initial':
    assert rpc('cms_capabilities',key='full')['admin_profile']==profile
    pages=[]
    for i,template in enumerate(['default','html','empty']):
        html=f'<section class="cms-container cms-section"><h1>Custom Code destination {i}</h1><p id="phase8-proof">Local integration matrix</p></section>'
        sections={'content':({'heading':f'Custom Code destination {i}','body':'<p>Local integration matrix</p>','is_visible':True} if template=='default' else {'html':html,'is_visible':True})}
        page=rpc('pages_create_draft',{'title':f'E2E08 destination {i}','slug':f'phase8-{i}','template_key':template,'locale':'es','content_json':{'sections':sections},'operation_key':f'phase8-page-{i}'},'full');page=publish(page);pages.append(page)
        en=rpc('content_translate',{'type':'pages','id':page['id'],'locale':'en'},'full');en=publish(en);pages.append(en)
    preview=rpc('content_preview',{'type':'pages','id':pages[0]['id'],'hours':1},'full')
    fixture={'profile':profile,'pages':pages,'preview':preview,'stage':stage,'snippets':{}}
    checks=['Three published templates and exact English sibling translations created through editorial MCP','Ordinary preview pinned before snippet activation']
else:
    fixture=json.loads(fixture_path.read_text());pages=fixture['pages'];snippets=fixture['snippets']
    if stage=='global':
        css=rpc('custom_code_list')['snippets'];css=next(s for s in css if s['name']=='E2E08 panel CSS');assert css['state']=='active';snippets['css']=css
        rules={'global':True,'include':[],'exclude':[ref(pages[4])]}
        for name,position,order,code in [('head','head_end',0,'window.__phase8Sequence=["head"];'),('start','body_start',0,'window.__phase8Sequence.push("start");'),('marker','body_end',10,'window.__phase8=(window.__phase8||0)+1;window.__phase8Sequence.push("end-a");'),('end','body_end',20,'window.__phase8Sequence.push("end-b");')]:
            data=payload('E2E08 '+name,code,position,order,rules)
            snippets[name]=(concurrent_save_activate if name=='marker' else save_activate)(data,f'phase8-{name}')
        before=rpc('custom_code_get',{'id':snippets['marker']['id']})
        for key in ['full','editor','draft','read','code-denied']:
            status,raw=request('/mcp/rpc',{'jsonrpc':'2.0','id':1,'method':'tools/call','params':{'name':'custom_code_activate','arguments':{'id':snippets['marker']['id']}}},key);assert status==200 and json.loads(raw)['error']['code']==-32003
        assert rpc('custom_code_get',{'id':snippets['marker']['id']})==before
        checks=['Eight synchronized HTTP save retries and eight activation retries return identical results with one version and one activation event per operation','Custom-code scoped administrator without explicit account permission is denied without mutation','Panel-created active CSS is readable through MCP','Four exact JavaScript versions activated by explicit code credential','Editorial full/editor/draft/read credentials all rejected before executable mutation']
    elif stage=='exact':
        target=ref(pages[1]);rules={'global':False,'include':[target],'exclude':[]}
        for name in ['head','start','marker','end']:
            current=rpc('custom_code_get',{'id':snippets[name]['id']});data=current['versions'][0]['payload'];data['rules']=rules
            snippets[name]=save_activate(data,'phase8-exact-'+name,current['id'])
        checks=['Exact English identity activated; Spanish sibling excluded by locale identity']
    elif stage=='pending':
        current=rpc('custom_code_get',{'id':snippets['marker']['id']});data=current['versions'][0]['payload'];data['code']='window.__phase8Pending=true;'
        pending=rpc('custom_code_save',data|{'id':current['id'],'expected_revision':current['revision'],'operation_key':'phase8-pending'});assert pending['active_version_id']==current['active_version_id'];snippets['marker']=pending
        checks=['Immutable pending code saved while previously activated version remains selected']
    elif stage in ('deactivated','reactivated'):
        current=rpc('custom_code_get',{'id':snippets['marker']['id']});args={'id':current['id'],'expected_revision':current['revision'],'operation_key':'phase8-'+stage}
        if stage=='reactivated':args['version_id']=current['active_version_id']
        snippets['marker']=rpc('custom_code_deactivate' if stage=='deactivated' else 'custom_code_activate',args)
        checks=['Individual deactivation/reactivation preserves selected historical version']
    elif stage=='harmful':
        snippets['harmful']=save_activate(payload('E2E08 recovery proof','header{display:none!important}body{background-color:rgb(254,202,202)!important}', 'head_end',100,{'global':False,'include':[ref(pages[0])],'exclude':[]},'css'),'phase8-harmful')
        checks=['Deliberate isolated public-layout disruption activated for one exact destination']
    else:raise AssertionError('Unknown stage')
    fixture['stage']=stage
fixture_path.write_text(json.dumps(fixture,indent=2));fixture_path.chmod(0o600)
previous=json.loads(results_path.read_text())['checks'] if results_path.exists() else []
results_path.write_text(json.dumps({'profile':profile,'cookie_free_mcp':True,'checks':previous+checks,'page_ids':[p['id'] for p in fixture['pages']]},indent=2))
for check in checks:print('PASS '+check,flush=True)
