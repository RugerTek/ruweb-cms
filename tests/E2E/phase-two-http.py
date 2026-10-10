"""E2E02 behavior gate, run on each real-agent delivery with its own isolated fixture."""
import http.cookiejar
import json
import os
import re
import subprocess
import urllib.parse
import urllib.request
import urllib.error
from html.parser import HTMLParser
from pathlib import Path

storage = Path(os.environ['PHASE0_STORAGE'])
assert re.fullmatch(r'ruweb_phase0_[a-f0-9]{12}', os.environ['PHASE0_DATABASE'])
manifest = json.loads((storage / 'manifest.json').read_text())
assert manifest['database'] == os.environ['PHASE0_DATABASE']
base = 'http://127.0.0.1:8018'
profile = os.environ['PHASE2_PROFILE']
admin = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
api = urllib.request.build_opener()
checks = []


def request(path, data=None, key=None, panel=False, method=None, form=False):
    headers = {'Accept': 'application/json'} if key else {}
    if key:
        headers['Authorization'] = 'Bearer ' + manifest['keys'][key]
    if data is not None:
        if form:
            fields = {}
            def flatten(value, prefix):
                if isinstance(value, dict):
                    for k, v in value.items(): flatten(v, prefix + '[' + str(k) + ']' if prefix else str(k))
                elif isinstance(value, list):
                    for k, v in enumerate(value): flatten(v, prefix + '[' + str(k) + ']')
                else: fields[prefix] = int(value) if isinstance(value, bool) else value
            flatten(data, '')
            headers['Content-Type'] = 'application/x-www-form-urlencoded'
            data = urllib.parse.urlencode(fields).encode()
        else:
            headers['Content-Type'] = 'application/json'
            data = json.dumps(data).encode()
    try:
        with (admin if panel else api).open(urllib.request.Request(base + path, data=data, headers=headers, method=method)) as response:
            return response.status, response.read().decode()
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode()


def rpc(name, args=None, key='full'):
    status, raw = request('/mcp/rpc', {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': name, 'arguments': args or {}}}, key=key)
    body = json.loads(raw)
    assert status == 200 and 'error' not in body and not body['result']['isError'], (name, status, body)
    return json.loads(body['result']['content'][0]['text'])['data']


class Fields(HTMLParser):
    def __init__(self, html):
        super().__init__(convert_charrefs=True)
        self.values = {}
        self.textarea = None
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
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


def passed(label):
    checks.append(label)
    print('PASS ' + label, flush=True)


local = json.loads(subprocess.check_output(['php', 'tests/E2E/phase-two-control.php', 'discovery'], text=True))
assert local['admin_profile'] == profile
assert rpc('cms_capabilities', key='read') == local
status, raw = request('/mcp/capabilities', key='draft')
assert status == 200 and json.loads(raw)['data'] == local
passed('Local, REST and JSON-RPC profile/capabilities match with limited scopes')
media = int(subprocess.check_output(['php', 'tests/E2E/phase-two-control.php', 'media'], text=True))
content = {'meta': {'title': 'Testimonios SEO'}, 'sections': {'content': {'heading': 'Hermano conservado', 'body': '<p>Contenido previo</p>', 'is_visible': True}, 'testimonials': {'heading': 'Clientes que confían', 'is_visible': True, 'items': [{'quote': 'El equipo cumplió cada detalle.', 'author': 'Ana Torres', 'role': 'Directora', 'portrait_id': media}]}}}
status, html = request('/login', panel=True)
assert status == 200
status, html = request('/login', {'_token': Fields(html).values['_token'], 'email': 'phase0@example.test', 'password': 'Phase0-Test-Only-2026!'}, panel=True)
assert status == 200
partial = Path('resources/views/admin/pages/sections/default_testimonials.blade.php')
if profile == 'full':
    assert partial.is_file()
    status, html = request('/admin/pages/create', panel=True)
    assert status == 200 and 'testimonials' in html and 'portrait_id' in html
    status, html = request('/admin/pages', {'_token': Fields(html).values['_token'], 'title': 'E2E02 Testimonios', 'slug': 'phase2-testimonials', 'locale': 'es', 'status': 'draft', 'template_key': 'default', 'content_json': content}, panel=True, form=True)
    assert status == 200, (status, html[:500])
    status, raw = request('/mcp/pages?search=E2E02%20Testimonios', key='full')
    rows = json.loads(raw)['data']
    assert status == 200 and len(rows) == 1
    page = rpc('pages_get', {'page_id': rows[0]['id']})
    saved = json.loads(json.dumps(page['content_json']['sections']['testimonials']))
    saved['is_visible'] = bool(int(saved['is_visible']))
    saved['items'][0]['portrait_id'] = int(saved['items'][0]['portrait_id'])
    assert saved == content['sections']['testimonials']
    passed('Full agent delivery: specialized editor saves custom testimony fields')
else:
    assert not partial.exists()
    assert request('/admin/pages/create', panel=True)[0] == 404
    page = rpc('pages_create_draft', {'title': 'E2E02 Testimonios', 'slug': 'phase2-testimonials', 'template_key': 'default', 'content_json': content})
    assert page['content_json'] == content
    assert request('/admin/pages/' + str(page['id']) + '/edit', panel=True)[0] == 404
    passed('Essential agent delivery: custom testimony operates without editorial partial')
assert request('/phase2-testimonials')[0] == 404
# Media ids remain validated through the same operation in both deployments.
status, raw = request('/mcp/pages/' + str(page['id']) + '/draft', {'content_json': {'sections': {'testimonials': {'items': [{'portrait_id': 'https://example.test/portrait.jpg'}]}}}}, key='full', method='PATCH')
assert status == 422 and rpc('pages_get', {'page_id': page['id']})['content_json'] == page['content_json']
page = rpc('pages_publish', {'page_id': page['id']})
status, html = request('/phase2-testimonials')
assert status == 200
for value in ['Clientes que confían', 'El equipo cumplió cada detalle.', 'Ana Torres', 'Directora', 'Contenido previo']:
    assert value in html, value
assert '<img' in html and 'editor-engine' not in html
subprocess.check_call(['php', 'tests/E2E/phase-two-control.php', 'future'])
assert request('/phase2-future')[0] == 404
if profile == 'full':
    status, form = request('/admin/pages/' + str(page['id']) + '/edit', panel=True)
    assert status == 200
    fields = Fields(form).values
    for field in ['author', 'role', 'portrait_id']:
        assert str(content['sections']['testimonials']['items'][0][field]) == fields['content_json[sections][testimonials][items][0][' + field + ']']
    assert content['sections']['testimonials']['items'][0]['quote'] == fields['content_json[sections][testimonials][items][0][quote]']
passed('Public renderer, persisted custom fields, media validation and draft/future publication guards')
(storage / 'phase-two-page.json').write_text(json.dumps(page, ensure_ascii=False, indent=2))
(storage / 'phase-two-results.json').write_text(json.dumps({'profile': profile, 'checks': checks, 'page_id': page['id']}, ensure_ascii=False, indent=2))
