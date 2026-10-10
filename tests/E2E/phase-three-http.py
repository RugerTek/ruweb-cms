"""E2E03: real isolated MySQL, bearer-only editorial APIs, both deployment profiles.
Run default stage, browser draft stage, --publish stage, then browser public stage.
"""
import json
import os
import re
import subprocess
import sys
import uuid
import urllib.request
import urllib.error
import urllib.parse
from datetime import datetime, timedelta, timezone
from pathlib import Path

storage = Path(os.environ['PHASE0_STORAGE'])
assert re.fullmatch(r'ruweb_phase0_[a-f0-9]{12}', os.environ['PHASE0_DATABASE'])
manifest = json.loads((storage / 'manifest.json').read_text())
assert manifest['database'] == os.environ['PHASE0_DATABASE']
profile = os.environ['PHASE3_PROFILE']
base = 'http://127.0.0.1:8018'
# This opener has no cookie jar: MCP identity comes only from the fixture bearer.
api = urllib.request.build_opener()
checks = []

def request(path, data=None, method=None, key='full', raw=False, content_type=None):
    headers = {'Accept': 'application/json'} if key else {}
    if key:
        headers['Authorization'] = 'Bearer ' + manifest['keys'][key]
    if data is not None:
        headers['Content-Type'] = content_type or 'application/json'
        if not raw:
            data = json.dumps(data).encode()
    target = path if path.startswith(base + '/') else base + path
    assert urllib.parse.urlparse(target).netloc == '127.0.0.1:8018'
    req = urllib.request.Request(target, data=data, headers=headers, method=method)
    assert not req.has_header('Cookie')
    try:
        with api.open(req) as response:
            return response.status, response.read(), dict(response.headers)
    except urllib.error.HTTPError as error:
        return error.code, error.read(), dict(error.headers)


def rest(path, data=None, method=None, expected=200, key='full'):
    status, raw, _ = request(path, data, method, key)
    body = json.loads(raw)
    assert status == expected, (path, status, body)
    return body.get('data', body)


def rpc(name, args=None, key='full'):
    status, raw, _ = request('/mcp/rpc', {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': name, 'arguments': args or {}}}, key=key)
    body = json.loads(raw)
    assert status == 200 and 'error' not in body and not body['result']['isError'], (name, status, body)
    return json.loads(body['result']['content'][0]['text']).get('data')


def passed(label):
    checks.append(label)
    print('PASS ' + label, flush=True)


def multipart(ticket, image):
    boundary = 'phase3-' + uuid.uuid4().hex
    fields = {'ticket': ticket, 'alt': 'Paisaje de prueba', 'title': 'Imagen de aceptación'}
    parts = []
    for name, value in fields.items():
        parts.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="' + name + '"\r\n\r\n' + value + '\r\n').encode())
    parts.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="file"; filename="phase-three.png"\r\nContent-Type: image/png\r\n\r\n').encode() + image + b'\r\n')
    parts.append(('--' + boundary + '--\r\n').encode())
    return b''.join(parts), 'multipart/form-data; boundary=' + boundary


def assert_hidden(page):
    assert request(urllib.parse.urlparse(page['url']).path, key=None)[0] == 404
    status, sitemap, _ = request('/sitemap.xml', key=None)
    assert status == 200 and page['url'].encode() not in sitemap


def verify_public(page, heading, alt):
    status, raw, _ = request(page['url'], key=None)
    html = raw.decode()
    assert status == 200 and heading in html and alt in html, (status, html[:400])
    assert re.search(r'<link[^>]+rel="canonical"[^>]+href="' + re.escape(urllib.parse.urljoin(base, page['url'])) + '"', html)
    assert 'hreflang="es"' in html and 'hreflang="en"' in html
    assert 'srcset=' in html and 'width="1600"' in html and 'height="900"' in html
    urls = re.findall(r'(?:src|srcset)="([^"]+)"', html)
    images = [value.split(',')[0].strip().split(' ')[0] for value in urls if '/storage/media/' in value]
    assert images
    for url in images:
        status, body, headers = request(url, key=None)
        assert status == 200 and len(body) > 100 and headers.get('Content-Type', '').startswith('image/'), (url, status, len(body))


fixture_path = storage / 'phase-three-page.json'
results_path = storage / 'phase-three-results.json'
if '--publish' not in sys.argv:
    capabilities = rpc('cms_capabilities')
    assert capabilities['admin_profile'] == profile
    assert rest('/mcp/capabilities', key='read') == capabilities
    schema = rest('/mcp/schema?type=pages&template_key=home', key='read')
    assert schema == rpc('cms_schema', {'type': 'pages', 'template_key': 'home'}, key='read')
    assert schema['schema']['sections.hero.image_id']['localized_media']
    passed('Cookie-free REST/RPC discovery and configured template schema')
    locales = rpc('locales_list', key='read')
    assert {'es', 'en'}.issubset({row['code'] for row in locales['installed']})
    rest('/mcp/locales', {'locales': {'en': {'is_active': True, 'is_public': True}}}, 'POST')
    assert next(row for row in rpc('locales_list')['installed'] if row['code'] == 'en')['is_public']
    passed('Locale configuration uses real authorized endpoint')
    image_path = subprocess.check_output(['php', 'tests/E2E/phase-three-control.php', 'image'], text=True)
    upload = rpc('media_authorize_upload')
    body, mime = multipart(upload['ticket'], Path(image_path).read_bytes())
    status, raw, _ = request(upload['upload_url'], body, 'POST', raw=True, content_type=mime)
    assert status == 201, (status, raw[:400])
    media = json.loads(raw)['data'][0]
    assert media['width'] == 1600 and media['height'] == 900 and media['variants']
    status, raw, _ = request(upload['upload_url'], body, 'POST', raw=True, content_type=mime)
    assert status == 201 and json.loads(raw)['data'][0]['id'] == media['id']
    rpc('media_update_metadata', {'media_id': media['id'], 'locale': 'en', 'alt': 'Acceptance landscape', 'title': 'Acceptance image'})
    assert rpc('media_get', {'media_id': media['id'], 'locale': 'en'})['alt'] == 'Acceptance landscape'
    passed('Binary multipart upload, actual optimized variants, retry receipt and localized metadata')
    content = {'meta': {'title': 'Equipo SEO ES', 'description': 'Descripción de aceptación', 'featured_image': media['id']}, 'sections': {'hero': {'heading': 'Equipo de aceptación', 'body': '<p>Contenido español verificable</p>', 'image_id': media['id'], 'is_visible': True}, 'features': {'heading': 'Valores preservados', 'body': '<p>Un hermano del contenido</p>', 'is_visible': True}, 'cta': {'is_visible': False}}}
    create = {'title': 'E2E03 Equipo', 'slug': 'phase3-equipo', 'locale': 'es', 'template_key': 'home', 'content_json': content, 'operation_key': 'phase3-page-es'}
    es = rpc('pages_create_draft', create)
    assert rpc('pages_create_draft', create)['id'] == es['id']
    en = rpc('content_translate', {'type': 'pages', 'id': es['id'], 'locale': 'en'})
    assert en['translation_group_id'] == es['translation_group_id'] and en['status'] == 'draft'
    assert en['content_json']['sections']['features'] == content['sections']['features']
    en = rest('/mcp/pages/' + str(en['id']) + '/draft', {'slug': 'phase3-team', 'title': 'E2E03 Team', 'expected_revision': en['revision'], 'content_json': {'meta': {'title': 'Team SEO EN', 'description': 'Acceptance description'}, 'sections': {'hero': {'heading': 'Acceptance team', 'body': '<p>Verified English content</p>'}, 'features': {'heading': 'Preserved values', 'body': '<p>Content sibling</p>'}}}}, 'PATCH')
    stale = es['revision']
    es = rpc('pages_update_draft', {'page_id': es['id'], 'expected_revision': stale, 'content_json': {'sections': {'hero': {'heading': 'Equipo actualizado'}}}})
    assert es['content_json']['sections']['features'] == content['sections']['features']
    status, raw, _ = request('/mcp/pages/' + str(es['id']) + '/draft', {'expected_revision': stale, 'title': 'Stale overwrite'}, 'PATCH')
    assert status == 409 and rpc('pages_get', {'page_id': es['id']})['title'] == 'E2E03 Equipo'
    assert rpc('content_translation_changes', {'type': 'pages', 'id': en['id']})['stale_fields']
    passed('Draft creation retry, translation group, partial edits and stale revision rejection')
    rest('/mcp/settings', {'settings': {'header_cta_enabled': True, 'header_cta_label': 'E2E03 CTA', 'header_cta_page': 'custom', 'header_cta_url': base + '/phase3-team'}}, 'PATCH')
    assert rpc('settings_get', {'key': 'header_cta_url'})['value'] == '/phase3-team'
    assert rpc('settings_get', {'key': 'header_cta_page'})['value'] is None
    rpc('settings_update_batch', {'locale': 'en', 'settings': {'footer_copyright': '© {year} {site}. All rights reserved.'}})
    passed('Batch layout settings normalize own-site URLs and localize shared footer copy')
    for locale, page in [('es', es), ('en', en)]:
        menu = rpc('menus_create', {'name': 'E2E03 ' + locale, 'slug': 'phase3-' + locale, 'locale': locale, 'location': 'header'})
        rpc('menus_sync_items', {'menu_id': menu['id'], 'items': [{'label': 'Nuestro equipo' if locale == 'es' else 'Our team', 'type': 'page', 'linkable_id': page['id'], 'target': '_self', 'order': 0}]})
        before_menu = rpc('menus_get', {'menu_id': menu['id']})
        status, raw, _ = request('/mcp/menus/' + str(menu['id']), {'name': 'Rejected menu', 'expected_revision': []}, 'PATCH')
        assert status == 422 and 'expected_revision' in json.loads(raw)['errors']
        status, raw, _ = request('/mcp/rpc', {'jsonrpc': '2.0', 'id': 2, 'method': 'tools/call', 'params': {'name': 'menus_update', 'arguments': {'menu_id': menu['id'], 'name': 'Rejected menu', 'expected_revision': []}}})
        invalid = json.loads(raw)
        assert status == 200 and invalid['error']['code'] == -32602 and 'expected_revision' in invalid['error']['data']
        assert rpc('menus_get', {'menu_id': menu['id']}) == before_menu
        assert_hidden(page)
    assert rpc('media_usages', {'media_id': media['id']})
    status, raw, _ = request('/mcp/media/' + str(media['id']) + '/usages?per_page=1&page=1')
    first_usage = json.loads(raw)
    assert status == 200 and len(first_usage['data']) == 1 and first_usage['meta']['total'] >= 2
    status, raw, _ = request('/mcp/media/' + str(media['id']) + '/usages?per_page=1&page=2')
    next_usage = json.loads(raw)
    assert status == 200 and len(next_usage['data']) == 1 and next_usage['meta']['current_page'] == 2
    assert first_usage['data'] != next_usage['data']
    status, raw, _ = request('/mcp/rpc', {'jsonrpc': '2.0', 'id': 3, 'method': 'tools/call', 'params': {'name': 'media_usages', 'arguments': {'media_id': media['id'], 'page': 0, 'per_page': 1}}})
    invalid_usage = json.loads(raw)
    assert status == 200 and invalid_usage['error']['code'] == -32602 and 'page' in invalid_usage['error']['data']
    status, raw, _ = request('/mcp/media/' + str(media['id']), method='DELETE')
    assert status == 422
    previews = {locale: rpc('content_preview', {'type': 'pages', 'id': page['id'], 'hours': 1})['url'] for locale, page in [('es', es), ('en', en)]}
    for locale, url in previews.items():
        status, raw, _ = request(url, key=None)
        assert status == 200 and (b'Equipo actualizado' if locale == 'es' else b'Acceptance team') in raw
    passed('Localized menus, public draft/sitemap exclusion, preview access and protected media deletion')
    fixture_path.write_text(json.dumps({'profile': profile, 'es': es, 'en': en, 'media': media, 'previews': previews}, ensure_ascii=False, indent=2))
    fixture_path.chmod(0o600)
    results_path.write_text(json.dumps({'profile': profile, 'checks': checks, 'cookie_free_mcp': True}, ensure_ascii=False, indent=2))
else:
    fixture = json.loads(fixture_path.read_text())
    es = rpc('pages_get', {'page_id': fixture['es']['id']})
    en = rpc('pages_get', {'page_id': fixture['en']['id']})
    es = rpc('pages_publish', {'page_id': es['id'], 'expected_revision': es['revision']})
    en = rpc('pages_publish', {'page_id': en['id'], 'expected_revision': en['revision']})
    # Internal CTA references require a published page; resolve its translation per locale.
    rpc('settings_update_batch', {'settings': {'header_cta_page': es['translation_group_id'], 'header_cta_url': None}})
    verify_public(es, 'Equipo actualizado', 'Paisaje de prueba')
    verify_public(en, 'Acceptance team', 'Acceptance landscape')
    assert b'Preserved values' in request(en['url'], key=None)[1]
    assert b'Valores preservados' not in request(en['url'], key=None)[1]
    status, sitemap, _ = request('/sitemap.xml', key=None)
    assert status == 200 and es['url'].encode() in sitemap and en['url'].encode() in sitemap
    passed('Published ES/EN URLs, SEO canonical/hreflang, sitemap and real responsive image bytes')
    future = rpc('pages_create_draft', {'title': 'E2E03 Scheduled', 'slug': 'phase3-scheduled', 'template_key': 'default', 'locale': 'es', 'content_json': {'sections': {'content': {'heading': 'Programación verificada', 'body': '<p>Clock controlled</p>'}}}, 'operation_key': 'phase3-scheduled'})
    publication_time = datetime.now(timezone.utc) + timedelta(seconds=90)
    future = rpc('content_publication', {'type': 'pages', 'id': future['id'], 'action': 'schedule', 'published_at': publication_time.isoformat(), 'expected_revision': future['revision']})
    assert_hidden(future)
    subprocess.check_call(['php', 'tests/E2E/phase-three-control.php', 'clock', (publication_time + timedelta(seconds=5)).isoformat()])
    try:
        assert request(future['url'], key=None)[0] == 200
        status, sitemap, _ = request('/sitemap.xml', key=None)
        assert status == 200 and future['url'].encode() in sitemap
        passed('Scheduled public visibility and sitemap cross the injected clock boundary')
    finally:
        subprocess.check_call(['php', 'tests/E2E/phase-three-control.php', 'reset-clock'])
    fixture.update({'es': es, 'en': en, 'scheduled': future})
    fixture_path.write_text(json.dumps(fixture, ensure_ascii=False, indent=2))
    result = json.loads(results_path.read_text())
    result['checks'].extend(checks)
    results_path.write_text(json.dumps(result, ensure_ascii=False, indent=2))
