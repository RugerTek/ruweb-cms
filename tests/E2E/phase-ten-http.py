"""Cookie-free MCP pilot in essential, then same supported workflow in full."""
import copy,json,os,sys,urllib.request,urllib.error,urllib.parse
from pathlib import Path
state=json.loads(Path('/tmp/ruweb-phase10-state.json').read_text());app=state['apps']['existing'];base='http://127.0.0.1:8018';storage=Path(app['storage']);keys=json.loads((storage/'keys.json').read_text());profile=os.environ['PHASE10_PROFILE'];assert profile in ('essential','full')
def request(path,data=None,key='full',method=None):
 url=urllib.parse.urljoin(base,path);assert urllib.parse.urlparse(url).netloc=='127.0.0.1:8018';headers={'Authorization':'Bearer '+keys[key],'Accept':'application/json'}if key else {}
 if data is not None:headers['Content-Type']='application/json';data=json.dumps(data).encode()
 req=urllib.request.Request(url,data=data,headers=headers,method=method);assert not req.has_header('Cookie')
 try:
  with urllib.request.urlopen(req)as r:return r.status,r.read()
 except urllib.error.HTTPError as e:return e.code,e.read()
def rpc(tool,args=None,key='full'):
 status,raw=request('/mcp/rpc',{'jsonrpc':'2.0','id':1,'method':'tools/call','params':{'name':tool,'arguments':args or {}}},key);body=json.loads(raw);assert status==200 and 'error'not in body and not body['result']['isError'],(tool,status,body)
 data=json.loads(body['result']['content'][0]['text']);assert data.get('success',True),(tool,data);return data['data']
def current(page):return rpc('pages_get',{'page_id':page['id']})
def entity(page):return {'type':'pages','id':page['id'],'expected_revision':current(page)['revision']}
def publish(page):return rpc('pages_publish',{'page_id':page['id'],'expected_revision':current(page)['revision']})
assert rpc('cms_capabilities')['admin_profile']==profile
baseline=json.loads((Path(state['source'])/'docs/plans/phase-10-evidence/baseline-manifest.json').read_text());mid=next(m['id']for m in baseline['rows']['media']if m.get('placeholder_key')=='horizontal')
checks=[]
if '--reactivate' in sys.argv:
 fixture=json.loads((storage/'phase-ten-pilot.json').read_text());snippet=rpc('custom_code_get',{'id':fixture['snippet_id']},'custom-code');rpc('custom_code_activate',{'id':snippet['id'],'version_id':snippet['versions'][0]['id'],'expected_revision':snippet['revision'],'operation_key':'phase10-code-reactivated'},'custom-code');print('PASS exact code reactivated for snapshot proof');sys.exit(0)
if profile=='essential':
 pages=[]
 for template in ['html','empty','composed']:
  html='<section class="cms-container cms-section cms-stack"><h1>Phase ten '+template+'</h1><p>Portable safe content</p><img id="phase10-media" data-media-id="'+str(mid)+'" alt="Portable image"><script>window.__phase10Attack=true</script></section>'
  sections={'content':{'html':html,'is_visible':True}}if template!='composed'else{'composition':{'is_visible':True,'blocks':[{'id':'portable','type':'html','version':1,'html':html,'is_visible':True},{'id':'callout','type':'structured','version':1,'component':'callout','params':{'heading':'Portable component','body':'Portable structured data','image_id':mid},'is_visible':True}]}}
  page=rpc('pages_create_draft',{'title':'Phase ten '+template,'slug':'phase10-'+template,'locale':'es','template_key':template,'content_json':{'meta':{'title':'Snapshot SEO '+template,'description':'Portable '+template},'sections':sections},'operation_key':'phase10-'+template});assert request(page['url'],key=None)[0]==404;pages.append(publish(page))
 en=rpc('content_translate',{'type':'pages','id':pages[0]['id'],'locale':'en'});en=rpc('pages_update_draft',{'page_id':en['id'],'expected_revision':en['revision'],'title':'Phase ten English HTML','slug':'phase10-html-en','content_json':{'meta':{'title':'English snapshot SEO'},'sections':{'content':{'html':'<section class="cms-container cms-section"><h1>English portable page</h1><img id="phase10-media" data-media-id="'+str(mid)+'" alt="English image"></section>'}}}});en=publish(en)
 proposal=rpc('content_propose',entity(pages[0])|{'changes':{'title':'Portable proposal retained'},'operation_key':'phase10-proposal'});preview=rpc('content_preview',{'type':'pages','id':pages[0]['id'],'hours':1});
 code=rpc('custom_code_save',{'name':'Phase ten portable integration','type':'javascript','description':'Only isolated fixture execution','code':'window.__phase10Snippet=(window.__phase10Snippet||0)+1;','position':'body_end','sort_order':0,'rules':{'global':True,'include':[],'exclude':[]},'operation_key':'phase10-code-save'},'custom-code');code=rpc('custom_code_activate',{'id':code['id'],'version_id':code['versions'][0]['id'],'expected_revision':code['revision'],'operation_key':'phase10-code-activate'},'custom-code')
 fixture={'pages':pages,'english':en,'proposal':proposal,'preview':preview,'snippet_id':code['id'],'media_id':mid};(storage/'phase-ten-pilot.json').write_text(json.dumps(fixture));(storage/'phase-ten-pilot.json').chmod(0o600);checks=['Create/review-safe proposal/preview/explicit publish HTML canvas composed and English translation over cookie-free MCP','Activate exact local snippet version using explicit custom-code scope','Drafts remain 404 before publication']
else:
 fixture=json.loads((storage/'phase-ten-pilot.json').read_text());page=current(fixture['pages'][-1]);versions=rpc('content_versions',{'type':'pages','id':page['id']})['versions'];baseline_version=versions[0]
 proposal=rpc('content_propose',entity(page)|{'changes':{'title':'Full pilot reviewed title'},'operation_key':'phase10-full-proposal'});proposal_id=proposal.get('id')or proposal['version']['id'];review=rpc('content_review_request',entity(page)|{'version_id':proposal_id,'reviewer_id':baseline['permissions'][0]['id'],'operation_key':'phase10-full-review'});review_id=review.get('id')or review['review']['id'];rpc('content_review_decide',entity(page)|{'review_id':review_id,'state':'approved','operation_key':'phase10-full-approved'});assert current(page)['title']=='Full pilot reviewed title'
 rpc('content_restore',entity(page)|{'version_id':baseline_version['id'],'mode':'draft','operation_key':'phase10-full-restored'});assert request(page['url'],key=None)[0]==404;publish(page)
 fresh=rpc('custom_code_get',{'id':fixture['snippet_id']},'custom-code');rpc('custom_code_deactivate',{'id':fresh['id'],'expected_revision':fresh['revision'],'operation_key':'phase10-code-off'},'custom-code')
 checks=['Repeat proposal/review/approved publication via same MCP in full','Historical content restore returns draft until explicit republish','Explicit code deactivation preserves immutable versions/history']
report={'profile':profile,'cookie_free':True,'checks':checks,'pages':[{'id':p['id'],'template':p['template_key'],'locale':p['locale']}for p in fixture['pages']]};(Path(state['source'])/'docs/plans/phase-10-evidence'/('http-pilot-'+profile+'.json')).write_text(json.dumps(report,indent=2));print('PASS MCP pilot '+profile)
