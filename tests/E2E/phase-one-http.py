"""E2E-01: panel, cookie-free MCP and REST against the phase-zero isolated MySQL fixture."""
import copy
import http.cookiejar
import json
import os
import re
import urllib.error
import urllib.request
from html.parser import HTMLParser
from pathlib import Path

storage = Path(os.environ['PHASE0_STORAGE'])
assert re.fullmatch(r'ruweb_phase0_[a-f0-9]{12}', os.environ['PHASE0_DATABASE'])
manifest = json.loads((storage / 'manifest.json').read_text())
assert manifest['database'] == os.environ['PHASE0_DATABASE']
base = 'http://127.0.0.1:8018'
admin = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
# A separate opener deliberately has no cookie processor: MCP/REST must authenticate independently.
api = urllib.request.build_opener()
results = []
profile = os.environ.get('PHASE0_PROFILE', 'full')
assert profile in ('full', 'essential')


def request(path, data=None, key=None, method=None, panel=False):
    headers = {'Accept': 'application/json'} if key else {}
    if key:
        headers['Authorization'] = 'Bearer ' + manifest['keys'][key]
    if data is not None:
        headers['Content-Type'] = 'application/json'
        data = json.dumps(data).encode()
    req = urllib.request.Request(base + path, data=data, headers=headers, method=method)
    try:
        with (admin if panel else api).open(req) as response:
            return response.status, response.read().decode()
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode()


def rpc(name, args, key='full', fail=False):
    status, raw = request('/mcp/rpc', {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call',
                                     'params': {'name': name, 'arguments': args}}, key=key)
    body = json.loads(raw)
    error = 'error' in body or body.get('result', {}).get('isError') is True
    assert error == fail, (name, status, body)
    if fail:
        # Scope and role denials are JSON-RPC answers with a dedicated code, never an internal error.
        assert status == 200, (name, status, body)
        assert body.get('error', {}).get('code') == -32003, (name, status, body)
        messages = ({'Esta clave no permite esta operación.', 'This key does not allow this operation.'}
                    if key == 'read' else {'Solo un administrador puede publicar contenido.',
                                           'Only an administrator can publish content.'})
        assert body['error'].get('message') in messages, (name, status, body)
        return body
    assert status == 200, (name, status, body)
    return json.loads(body['result']['content'][0]['text'])['data']


class Fields(HTMLParser):
    def __init__(self, html, form_id=None):
        super().__init__(convert_charrefs=True)
        self.values = {}
        self.textarea = None
        self.form_id = form_id
        self.in_form = form_id is None
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'form' and self.form_id:
            self.in_form = attrs.get('id') == self.form_id
        if not self.in_form:
            return
        name = attrs.get('name')
        if tag == 'input' and name:
            self.values[name] = attrs.get('value', '')
        if tag == 'textarea' and name:
            self.textarea = name
            self.values[name] = ''

    def handle_data(self, data):
        if self.textarea:
            self.values[self.textarea] += data

    def handle_endtag(self, tag):
        if tag == 'textarea':
            self.textarea = None
        if tag == 'form' and self.form_id:
            self.in_form = False


def passed(label):
    results.append(label)
    print('PASS ' + label, flush=True)


status, html = request('/login', panel=True)
assert status == 200
status, html = request('/login', {'_token': Fields(html).values['_token'],
                                'email': 'phase0@example.test', 'password': 'Phase0-Test-Only-2026!'}, panel=True)
assert status == 200
status, html = request('/admin/pages/create', panel=True)
assert status == (200 if profile == 'full' else 404)
initial = {'meta': {'title': 'E2E01 SEO preserved', 'description': 'E2E01 initial description'},
           'sections': {'content': {'heading': 'E2E01 heading preserved',
                                    'body': '<p>E2E01 initial body</p>', 'is_visible': True}}}
if profile == 'full':
    status, html = request('/admin/pages', {'_token': Fields(html).values['_token'], 'title': 'E2E01 panel draft',
                                          'slug': 'e2e01-panel', 'locale': 'es', 'template_key': 'default',
                                          'status': 'draft', 'content_json': initial}, panel=True)
    assert status == 200, ('panel create', status, html[:300])
else:
    created = rpc('pages_create_draft', {'title': 'E2E01 panel draft', 'slug': 'e2e01-panel',
                  'locale': 'es', 'template_key': 'default', 'content_json': initial})
    assert created['status'] == 'draft'
status, raw = request('/mcp/pages?search=E2E01%20panel%20draft', key='full')
rows = json.loads(raw)['data']
assert status == 200 and len(rows) == 1, (status, rows)
page_id = rows[0]['id']
before = rpc('pages_get', {'page_id': page_id})
assert before['status'] == 'draft' and before['content_json'] == initial
assert request('/e2e01-panel')[0] == 404
passed(('Full panel' if profile == 'full' else 'Essential cookie-free MCP') + ' creates draft; MCP reads exact data; draft absent publicly')

patch = {'title': 'E2E01 edited title', 'content_json': {'meta': {'description': 'E2E01 edited description'},
          'sections': {'content': {'body': '<p>E2E01 edited body</p>'}}}}
expected = copy.deepcopy(initial)
expected['meta']['description'] = patch['content_json']['meta']['description']
expected['sections']['content']['body'] = patch['content_json']['sections']['content']['body']
updated = rpc('pages_update_draft', dict(patch, page_id=page_id))
assert updated['title'] == patch['title'] and updated['content_json'] == expected
if profile == 'full':
    status, html = request('/admin/pages/' + str(page_id) + '/edit', panel=True)
    assert status == 200
    fields = Fields(html, form_id='editor-form').values
    for name, value in {'title': patch['title'], 'slug': 'e2e01-panel',
                        'content_json[meta][title]': expected['meta']['title'],
                        'content_json[meta][description]': expected['meta']['description'],
                        'content_json[sections][content][heading]': expected['sections']['content']['heading'],
                        'content_json[sections][content][body]': expected['sections']['content']['body']}.items():
        assert fields.get(name) == value, (name, fields.get(name), value)
    passed('MCP partial edit preserves siblings; reopened panel fields equal saved values exactly')
else:
    assert request('/admin/pages/' + str(page_id) + '/edit', panel=True)[0] == 404
    status, html = request('/admin/content/pages/' + str(page_id), panel=True)
    assert status == 200 and patch['title'] in html
    status, html = request('/admin/content/pages/' + str(page_id) + '/preview', panel=True)
    assert status == 200 and all(value in html for value in ['E2E01 SEO preserved',
        'E2E01 edited description', 'E2E01 heading preserved', 'E2E01 edited body'])
    passed('MCP partial edit preserves siblings; Essential operational panel and authorized preview show stored values')

stable = rpc('pages_get', {'page_id': page_id})
denied_patch = {'title': 'E2E01 forbidden title', 'content_json': {'meta': {'description': 'E2E01 forbidden SEO'}}}
rpc('pages_update_draft', dict(denied_patch, page_id=page_id), key='read', fail=True)
rpc('pages_publish', {'page_id': page_id}, key='editor', fail=True)
assert rpc('pages_get', {'page_id': page_id}) == stable
assert request('/e2e01-panel')[0] == 404
passed('Read-only write and editor publish rejected without record/publication changes')

published = rpc('pages_publish', {'page_id': page_id})
assert published['status'] == 'published' and published['published_at']
status, html = request('/e2e01-panel')
assert status == 200 and all(value in html for value in ['E2E01 SEO preserved', 'E2E01 edited description',
                                                        'E2E01 heading preserved', 'E2E01 edited body'])
assert re.search(r'<title>[^<]*E2E01 SEO preserved[^<]*</title>', html)
assert re.search(r'<meta\s+name="description"\s+content="E2E01 edited description"', html)
passed('Authorized cookie-free MCP publication renders content and SEO without session')

status, raw = request('/mcp/pages/drafts', {'title': 'E2E01 REST draft', 'slug': 'e2e01-rest',
                                        'locale': 'es', 'template_key': 'default', 'content_json': initial}, key='full')
assert status == 201, (status, raw)
rest_id = json.loads(raw)['data']['id']
assert rest_id != page_id
status, raw = request('/mcp/pages/' + str(rest_id) + '/draft', patch, key='full', method='PATCH')
assert status == 200, (status, raw)
rest_updated = json.loads(raw)['data']
for field in ['title', 'locale', 'template_key', 'status', 'content_json']:
    assert rest_updated[field] == updated[field], (field, rest_updated[field], updated[field])
for suffix, data, key, method in [('draft', denied_patch, 'read', 'PATCH'), ('publish', {}, 'editor', 'POST')]:
    assert request('/mcp/pages/' + str(rest_id) + '/' + suffix, data, key=key, method=method)[0] == 403
assert rpc('pages_get', {'page_id': rest_id}) == rest_updated
assert request('/e2e01-rest')[0] == 404
status, raw = request('/mcp/pages/' + str(rest_id) + '/publish', {}, key='full')
assert status == 200, (status, raw)
rest_published = json.loads(raw)['data']
for field in ['title', 'locale', 'template_key', 'status', 'content_json']:
    assert rest_published[field] == published[field], field
assert rest_published['published_at']
status, rest_html = request('/e2e01-rest')
assert status == 200 and all(value in rest_html for value in ['E2E01 SEO preserved', 'E2E01 edited description',
                                                             'E2E01 heading preserved', 'E2E01 edited body'])
passed('Independent REST fixture matches MCP effects, authorization rejections and public rendering')

(storage / 'phase-one-results.json').write_text(json.dumps({'passed': results, 'panel_page_id': page_id,
                                                           'rest_page_id': rest_id}, indent=2))
