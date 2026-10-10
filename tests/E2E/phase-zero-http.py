"""Real HTTP acceptance against the dedicated fixture; never accepts an arbitrary target."""
import http.cookiejar
import json
import os
import re
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

storage = Path(os.environ['PHASE0_STORAGE'])
assert re.fullmatch(r'ruweb_phase0_[a-f0-9]{12}', os.environ['PHASE0_DATABASE'])
manifest = json.loads((storage / 'manifest.json').read_text())
base = 'http://127.0.0.1:8018'
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
results = []

def request(path, data=None, token=None, method=None):
    headers = {}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    if data is not None:
        headers['Content-Type'] = 'application/json'
        headers['Accept'] = 'application/json'
        data = json.dumps(data).encode()
    req = urllib.request.Request(base + path, data=data, headers=headers, method=method)
    try:
        with client.open(req) as r:
            return r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()

def rpc(name, args=None, key='full', fail=False):
    status, raw = request('/mcp/rpc', {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': name, 'arguments': args or {}}}, manifest['keys'][key])
    body = json.loads(raw)
    if fail:
        assert 'error' in body or body.get('result', {}).get('isError') is True, (name, status, body)
        return body
    assert status == 200 and 'error' not in body and body.get('result', {}).get('isError') is not True, (name, status, body)
    return json.loads(body['result']['content'][0]['text'])

def passed(name):
    results.append(name)
    print('PASS ' + name, flush=True)

def token_from(html):
    found = re.search(r'name="_token"[^>]*value="([^"]+)"', html)
    assert found, 'Missing CSRF field'
    return found.group(1)

status, raw = request('/mcp/rpc', {'jsonrpc':'2.0','id':1,'method':'initialize','params':{'protocolVersion':'2024-11-05','capabilities':{},'clientInfo':{'name':'phase0-e2e','version':'1'}}}, manifest['keys']['full'])
assert status == 200 and 'result' in json.loads(raw)
status, raw = request('/mcp/rpc', {'jsonrpc':'2.0','id':2,'method':'tools/list'}, manifest['keys']['full'])
tools = json.loads(raw)['result']['tools']
assert len(tools) == 23 and all(isinstance(t['inputSchema']['properties'], dict) for t in tools)
menus = rpc('menus_list')['data']
assert menus and rpc('menus_get', {'menu_id': menus[0]['id']})['data']['id'] == menus[0]['id']
assert rpc('settings_list')['data']
secret = rpc('settings_get', {'key':'recaptcha_secret_key'})['data']
assert secret['value'] is None and secret['redacted'] is True
passed('Discovery: 23 valid schemas; menus/settings callable')

before = rpc('settings_get', {'key':'recaptcha_min_score'})['data']
status, _ = request('/mcp/settings/recaptcha_min_score', {'value':0}, manifest['keys']['full'], method='PATCH')
assert status == 422
rejection = rpc('settings_update', {'key':'recaptcha_min_score','value':0}, fail=True)
assert rejection['result']['isError'] is True
assert 'read-only' in json.loads(rejection['result']['content'][0]['text'])['message']
assert rpc('settings_get', {'key':'recaptcha_min_score'})['data'] == before
assert rpc('settings_get', {'key':'phase0_nonexistent'}, fail=True)['result']['isError'] is True
passed('Controller 422/404 become MCP tool errors without changing settings')

for plural in ['pages', 'posts', 'media']:
    seen = []
    page = 1
    while True:
        payload = rpc(plural + '_list', {'page': page, 'per_page': 50})
        assert payload['meta']['current_page'] == page
        seen.extend(x['id'] for x in payload['data'])
        if page == payload['meta']['last_page']:
            break
        page += 1
    assert len(seen) == len(set(seen)) == payload['meta']['total'] and len(seen) > 100
    assert not rpc(plural + '_list', {'page':page+1,'per_page':50})['data']
    rpc(plural + '_list', {'per_page':0}, fail=True)
passed('Pagination and invalid page-size rejection across all three inventories')

content = {'meta':{'title':'Preserved SEO','description':'Before'},'sections':{'content':{'heading':'Preserved heading','body':'<p>Preserved body</p>','is_visible':True},'faq':{'items':[{'question':'A'},{'question':'B'}]}}}
draft = rpc('pages_create_draft', {'title':'HTTP draft','slug':'http-draft','template_key':'default','locale':'es','content_json':content})['data']
draft_id = draft['id']
updated = rpc('pages_update_draft', {'page_id':draft_id,'content_json':{'meta':{'description':'After'}}})['data']
content['meta']['description'] = 'After'
assert updated['content_json'] == content
updated = rpc('pages_update_draft', {'page_id':draft_id,'content_json':{'meta':{'description':None},'sections':{'faq':{'items':[{'question':'C'}]}}}})['data']
del content['meta']['description']
content['sections']['faq']['items'] = [{'question':'C'}]
assert updated['content_json'] == content
updated = rpc('pages_update_draft', {'page_id':draft_id,'content_json':{'sections':{'faq':{'items':[]}}}})['data']
assert updated['content_json']['sections']['faq']['items'] == []
passed('Partial content preserves siblings; lists replace/clear; null removes keys')

for plural in ['pages', 'posts']:
    args = {'title':'Locale test','slug':'http-language', 'locale':'es'}
    if plural == 'pages': args['template_key'] = 'default'
    first = rpc(plural+'_create_draft', args)['data']
    english = rpc(plural+'_create_draft', dict(args, locale='en'))['data']
    assert first['locale']=='es' and english['locale']=='en' and english['translation_group_id']
    rpc(plural+'_create_draft', dict(args, locale='en'), fail=True)
    rpc(plural+'_create_draft', dict(args, locale='zz'), fail=True)
base_page = rpc('pages_get', {'page_id':manifest['public_page']})['data']
translation = rpc('pages_get', {'page_id':manifest['translated_page']})['data']
assert base_page['translation_group_id'] == translation['translation_group_id']
passed('Explicit locales, linked translation identity and localized slug uniqueness')

post = rpc('posts_create_draft', {'title':'Public post','slug':'http-public-post','content':'Keep public body'})['data']
rpc('posts_publish', {'post_id':post['id']})
public_expectations = {
    '/baseline-public': ['Baseline SEO', 'Baseline description', 'Baseline heading', 'Baseline public body'],
    '/blog/http-public-post': ['Public post', 'Keep public body'],
}
def verify_public_content():
    for path, expected in public_expectations.items():
        # A fresh opener carries neither the admin cookie jar nor a Bearer token.
        with urllib.request.urlopen(base + path) as response:
            assert response.status == 200
            html = response.read().decode()
        assert all(value in html for value in expected), (path, expected)
        assert 'Must not change' not in html, path
        if path == '/baseline-public':
            assert re.search(r'<title>[^<]*Baseline SEO[^<]*</title>', html)
            assert re.search(r'<meta\s+name="description"\s+content="Baseline description"', html)
verify_public_content()
for plural, entity_id in [('pages', manifest['public_page']), ('posts', post['id'])]:
    arg = {plural[:-1]+'_id': entity_id}
    before = rpc(plural+'_get', arg)['data']
    for key in ['full','editor','draft']:
        rpc(plural+'_update_draft', dict(arg, title='Must not change'), key=key, fail=True)
    assert rpc(plural+'_get', arg)['data'] == before
verify_public_content()
passed('Public page content/SEO and post title/body preserved without session before/after rejected edits')
rpc('pages_update_draft', {'page_id':draft_id,'title':'Denied'}, key='read', fail=True)
for key in ['editor','draft']:
    rpc('pages_publish', {'page_id':draft_id}, key=key, fail=True)
assert rpc('pages_get', {'page_id':draft_id}, key='read')['data']['status']=='draft'
assert request('/mcp/pages',token='invalid-token')[0] == 401
passed('Published pages/posts unchanged; read-only/draft/editor restrictions; invalid credential')

status, html = request('/login')
assert status == 200
csrf = token_from(html)
status, raw = request('/login', {'_token':csrf, 'email':'phase0@example.test', 'password':'Phase0-Test-Only-2026!'})
assert status == 200, ('login',status,raw[:200])
status, html = request('/admin/pages/create')
assert status == 200 and 'template_key' in html
csrf = token_from(html)
status, raw = request('/admin/pages', {'_token':csrf,'title':'Panel baseline','slug':'panel-baseline','locale':'es','template_key':'home','status':'published','featured_image':manifest['media_id'],'content_json':{'meta':{'title':'Panel baseline SEO','description':'Panel baseline description'},'sections':{'hero':{'heading':'Panel baseline hero','body':'Panel baseline body','image_id':manifest['media_id'],'is_visible':True}}}})
assert status == 200, ('admin create',status,raw[:300])
with urllib.request.urlopen(base + '/panel-baseline') as response:
    status, html = response.status, response.read().decode()
assert status == 200 and all(x in html for x in ['Panel baseline hero','Panel baseline body','Panel baseline SEO','Panel baseline description','<img'])
image_url = re.search(r'<img[^>]*src="([^"]+)"', html).group(1)
assert urllib.parse.urlparse(image_url).hostname == '127.0.0.1'
with urllib.request.urlopen(image_url) as response:
    assert response.status == 200 and response.headers['Content-Type'].startswith('image/') and len(response.read()) > 0
assert rpc('pages_list', {'search':'Panel baseline'})['data'][0]['status']=='published'
status, html = request('/admin/pages/'+str(draft_id)+'/edit')
assert status == 200 and 'Preserved heading' in html and 'Preserved body' in html
passed('Admin login/create/public render/SEO/image and MCP-to-panel content parity')

status, html = request('/contacto')
assert status == 200
csrf = token_from(html)
form_token = re.search(r'name="_form_token"[^>]*value="([^"]+)"',html).group(1)
action = re.search(r'<form[^>]*action="([^"]*/forms/contact)"',html).group(1)
assert urllib.parse.urlparse(action).hostname in ['127.0.0.1','localhost']
time.sleep(4)
status, raw = request(urllib.parse.urlparse(action).path, {'_token':csrf,'_form_token':form_token,'page_id':manifest['contact_page'],'name':'Phase Zero Contact','email':'visitor@example.test','phone':'','subject':'Consulta general','message':'Phase zero delivery verification','website':'','recaptcha_token':'test-token'})
assert status == 200, ('contact',status,raw[:400])
status, html = request('/admin/submissions/contact')
assert status == 200 and 'Phase Zero Contact' in html
passed('Public form with CSRF/signed token/anti-spam reaches admin inbox')
(storage/'http-results.json').write_text(json.dumps({'passed':results,'draft_id':draft_id},indent=2))
