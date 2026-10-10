"""E2E07 composition: guarded MySQL, cookie-free existing MCP tools, no asset rebuild.
Run initial, browser initial, --mutate, browser mutated, --restore, browser restored.
"""
import copy, hashlib, json, os, re, sys, urllib.request, urllib.error, urllib.parse
from pathlib import Path
storage=Path(os.environ['PHASE0_STORAGE']); profile=os.environ['PHASE7_PROFILE']; base='http://127.0.0.1:8018'
manifest=json.loads((storage/'manifest.json').read_text())
assert re.fullmatch(r'ruweb_phase0_[a-f0-9]{12}', os.environ['PHASE0_DATABASE']) and manifest['database']==os.environ['PHASE0_DATABASE']
assert profile in ('full','essential') and not Path('public/hot').exists()
fixture_path=storage/'phase-seven-fixture.json'; results_path=storage/'phase-seven-results.json'
def fingerprint():
    return hashlib.sha256(b''.join(p.read_bytes() for p in sorted(Path('public/build').rglob('*')) if p.is_file())).hexdigest()
def request(path,data=None,key='full',method=None):
    url=urllib.parse.urljoin(base,path); assert urllib.parse.urlparse(url).netloc=='127.0.0.1:8018'
    headers={'Authorization':'Bearer '+manifest['keys'][key], 'Accept':'application/json'} if key else {}
    if data is not None: headers['Content-Type']='application/json'; data=json.dumps(data).encode()
    req=urllib.request.Request(url,data=data,headers=headers,method=method); assert not req.has_header('Cookie')
    try:
        with urllib.request.urlopen(req) as r:return r.status,r.read()
    except urllib.error.HTTPError as e:return e.code,e.read()
def rpc(name,args=None,key='full'):
    status,raw=request('/mcp/rpc',{'jsonrpc':'2.0','id':1,'method':'tools/call','params':{'name':name,'arguments':args or {}}},key)
    body=json.loads(raw); assert status==200 and 'error' not in body and not body['result']['isError'],(name,status,body)
    data=json.loads(body['result']['content'][0]['text'])['data']
    for field in ('version','review','link'):
        if field in data:return data[field] | data
    return data
def entity(page):return {'type':'pages','id':page['id'],'expected_revision':rpc('pages_get',{'page_id':page['id']})['revision']}
def publish(page):return rpc('pages_publish',{'page_id':page['id'],'expected_revision':rpc('pages_get',{'page_id':page['id']})['revision']})
def save(fixture,checks):
    fixture_path.write_text(json.dumps(fixture,indent=2)); fixture_path.chmod(0o600)
    previous=json.loads(results_path.read_text())['checks'] if results_path.exists() else []
    results_path.write_text(json.dumps({'profile':profile,'checks':previous+checks,'build_sha256':fixture['build'],'cookie_free_mcp':True,'page_ids':[p['id'] for p in fixture['pages']]},indent=2))
    for check in checks:print('PASS '+check,flush=True)
if '--mutate' not in sys.argv and '--restore' not in sys.argv:
    assert rpc('cms_capabilities')['admin_profile']==profile
    status,raw=request('/mcp/rpc',{'jsonrpc':'2.0','id':2,'method':'tools/list'}); assert status==200 and len(json.loads(raw)['result']['tools'])==52
    for template in ('html','empty','composed'):
        schema=rpc('cms_schema',{'type':'pages','template_key':template}); assert schema['schema']
    mid=manifest['media_id']
    attack='''<script>window.e2eAttack=true</script><p id="admitted" class="cms-lead" onclick="window.e2eAttack=true" style="background:url(javascript:window.e2eAttack=true)">Admitted safe fragment</p><svg onload="window.e2eAttack=true"><script>window.e2eAttack=true</script></svg><iframe srcdoc="<script>parent.e2eAttack=true</script>"></iframe><img src=x onerror="window.e2eAttack=true"><a id="bad-protocol" href="javascript:window.e2eAttack=true">Unsafe URL</a><a id="encoded-protocol" href="java&#x73;cript:window.e2eAttack=true">Encoded URL</a><a id="data-protocol" href="data:text/html,attack">Data URL</a><style>body{background:url(javascript:window.e2eAttack=true)}</style>'''
    html='<section class="cms-container cms-section cms-stack"><h1>Composition initial</h1><div id="grid-proof" class="cms-grid-2"><p>Column one</p><p>Column two</p></div>'+attack+f'<img data-media-id="{mid}" id="composition-image" title="Admitted image title" alt="Portable > composition image"><a href="/contacto" class="cms-button">Contact link</a></section>'
    pages=[]
    for template in ('html','empty','composed'):
        sections={'content':{'html':html,'is_visible':True}}
        if template=='composed':sections={'composition':{'is_visible':True,'blocks':[
            {'id':'fragment','type':'html','version':1,'is_visible':True,'html':html},
            {'id':'callout','type':'structured','version':1,'is_visible':True,'component':'callout','params':{'heading':'Structured initial','body':'Structured data preserved','image_id':mid}},
            {'id':'accordion','type':'interactive','version':1,'is_visible':True,'component':'accordion','params':{'heading':'Interactive initial','items':[{'question':'First question','answer':'First answer'},{'question':'Second question','answer':'Second answer'}],'initially_open':0}},
            {'id':'hidden','type':'html','html':'<p>Hidden block should not render</p>','is_visible':False}]}}
        page=rpc('pages_create_draft',{'title':'E2E07 '+template,'slug':'phase7-'+template,'template_key':template,'locale':'es','content_json':{'meta':{'title':'Composition SEO '+template,'description':'Portable composition description'},'sections':sections},'operation_key':'phase7-'+template})
        assert request(page['url'],key=None)[0]==404; pages.append(publish(page))
    composed=pages[-1]; baseline=rpc('content_versions',{'type':'pages','id':composed['id']})['versions'][0]
    en=rpc('content_translate',{'type':'pages','id':composed['id'],'locale':'en'})
    assert en['translation_group_id']==composed['translation_group_id']
    assert [b['id'] for b in en['content_json']['sections']['composition']['blocks']]==['fragment','callout','accordion','hidden']
    assert en['content_json']['sections']['composition']['blocks'][1]['params']['image_id']==mid
    translated_blocks=copy.deepcopy(en['content_json']['sections']['composition']['blocks'])
    translated_blocks[0]['html']=translated_blocks[0]['html'].replace('Composition initial','English composition').replace('Admitted safe fragment','Admitted English fragment')
    translated_blocks[1]['params']['heading']='Structured English'; translated_blocks[1]['params']['body']='Distinct English structured copy'
    translated_blocks[2]['params']['heading']='Interactive English'; translated_blocks[2]['params']['items'][0]={'question':'English first question','answer':'English first answer'}
    en=rpc('pages_update_draft',{'page_id':en['id'],'expected_revision':en['revision'],'title':'E2E07 English composed','content_json':{'meta':{'title':'Composition SEO English','description':'Distinct English composition description'},'sections':{'composition':{'blocks':translated_blocks}}}})
    assert en['content_json']['sections']['composition']['blocks'][1]['params']['image_id']==mid
    assert rpc('pages_get',{'page_id':composed['id']})['content_json']==composed['content_json']
    en=publish(en)
    preview=rpc('content_preview',{'type':'pages','id':composed['id'],'version_id':baseline['id'],'hours':1})
    fixture={'profile':profile,'pages':pages,'en':en,'baseline':baseline,'preview':preview,'build':fingerprint(),'stage':'initial'}
    save(fixture,['52 existing tools discover canonical schemas in selected profile','HTML, empty and composed published through existing MCP','Distinct English HTML/component/interactive/SEO copy preserves block IDs, order and media; Spanish source unchanged'])
elif '--mutate' in sys.argv:
    fixture=json.loads(fixture_path.read_text()); page=rpc('pages_get',{'page_id':fixture['pages'][-1]['id']})
    blocks=copy.deepcopy(page['content_json']['sections']['composition']['blocks']); blocks[0]['html']=blocks[0]['html'].replace('Composition initial','Composition changed'); blocks[1]['params']['heading']='Structured changed'; blocks[2]['params']['heading']='Interactive changed'; blocks[2]['params']['initially_open']=1
    blocks=[blocks[2],blocks[0],blocks[1],blocks[3]]
    proposal=rpc('content_propose',entity(page)|{'changes':{'content_json':{'sections':{'composition':{'blocks':blocks}}}},'operation_key':'phase7-proposal'},'editor')
    review=rpc('content_review_request',entity(page)|{'version_id':proposal['id'],'reviewer_id':manifest['reviewer_id'],'operation_key':'phase7-review'},'editor')
    assert b'Composition initial' in request(page['url'],key=None)[1] and b'Composition changed' not in request(page['url'],key=None)[1]
    rpc('content_review_decide',entity(page)|{'review_id':review['id'],'state':'approved','operation_key':'phase7-approve'})
    page=rpc('pages_get',{'page_id':page['id']}); assert [b['id'] for b in page['content_json']['sections']['composition']['blocks']]==['accordion','fragment','callout','hidden']
    assert fingerprint()==fixture['build']; fixture['pages'][-1]=page; fixture['stage']='mutated'
    save(fixture,['Reviewed exact composition proposal changes content, block order and interactive parameters without rebuild'])
else:
    fixture=json.loads(fixture_path.read_text()); page=fixture['pages'][-1]
    rpc('content_restore',entity(page)|{'version_id':fixture['baseline']['id'],'mode':'draft','operation_key':'phase7-restore'})
    page=rpc('pages_get',{'page_id':page['id']}); assert page['status']=='draft' and request(page['url'],key=None)[0]==404
    page=publish(page); blocks=page['content_json']['sections']['composition']['blocks']; assert [b['id'] for b in blocks]==['fragment','callout','accordion','hidden'] and blocks[1]['params']['image_id']==manifest['media_id']
    assert blocks[2]['params']['initially_open']==0 and fingerprint()==fixture['build']
    status,raw=request('/mcp/media/'+str(manifest['media_id']),key='full',method='DELETE'); assert status==422,(status,raw[:300])
    fixture['pages'][-1]=page; fixture['stage']='restored'
    save(fixture,['Historical restore and explicit republish recover identity, order, media and interactive parameters with unchanged assets'])
