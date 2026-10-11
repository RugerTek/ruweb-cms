"""E2E06 cookie-free MCP follow-up, authorization, recovery and duplicate suppression.
Browser submit precedes initial stage; fail-worker precedes --recover; deliver-worker precedes --complete.
"""
import concurrent.futures
import threading
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
manifest = json.loads((storage / 'manifest.json').read_text())
fixture = json.loads((storage / 'phase-six-fixture.json').read_text())
profile = os.environ['PHASE6_PROFILE']
assert re.fullmatch(r'ruweb_phase0_[a-f0-9]{12}', os.environ['PHASE0_DATABASE'])
assert manifest['database'] == os.environ['PHASE0_DATABASE'] and fixture['profile'] == profile
base = 'http://127.0.0.1:8018'
api = urllib.request.build_opener()
checks = []
result_path = storage / 'phase-six-results.json'
identity = {'form': 'contact', 'id': fixture['id']}
endpoint = '/mcp/submissions/contact/' + str(fixture['id'])


def request(path, data=None, key='full', method=None, opener=api):
    target = urllib.parse.urljoin(base, path)
    assert urllib.parse.urlparse(target).netloc == '127.0.0.1:8018'
    headers = {'Accept': 'application/json'}
    if key:
        headers['Authorization'] = 'Bearer ' + manifest['keys'].get(key, key)
    if data is not None:
        headers['Content-Type'] = 'application/json'
        data = json.dumps(data).encode()
    req = urllib.request.Request(target, data=data, headers=headers, method=method)
    if opener is api:
        assert not req.has_header('Cookie')
    try:
        with opener.open(req) as response:
            return response.status, response.read()
    except urllib.error.HTTPError as error:
        return error.code, error.read()


def rpc(name, args=None, key='full', denied=None):
    status, raw = request('/mcp/rpc', {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': name, 'arguments': args or {}}}, key)
    body = json.loads(raw)
    if denied:
        assert status == denied, (name, status, body)
        assert 'error' in body or body.get('result', {}).get('isError') is True
        return body
    assert status == 200 and 'error' not in body and not body['result']['isError'], (name, status, body)
    return json.loads(body['result']['content'][0]['text'])['data']


def concurrent_rpc(name, args):
    # Requires PHP_CLI_SERVER_WORKERS >= 4: independent HTTP handlers share the real MySQL fixture.
    assert int(os.environ.get('PHP_CLI_SERVER_WORKERS', '0')) >= 4
    barrier = threading.Barrier(8)
    def call(_):
        barrier.wait()
        return rpc(name, args)
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        return list(pool.map(call, range(8)))


def current():
    return rpc('submissions_get', identity)


def write(name, payload, operation, revision=None):
    return rpc('submissions_' + name, identity | payload | {'expected_revision': revision or current()['revision'], 'operation_key': operation})


def passed(label):
    checks.append(label)
    print('PASS ' + label, flush=True)


if '--recover' in __import__('sys').argv:
    failed = current()
    assert failed['notification_status'] == 'failed' and failed['notification_attempts'] == 4
    assert failed['notification_failed_at'] and not failed['notified_at']
    assert failed['notification_diagnostic']['cause'] == 'delivery_failed'
    assert 'notification_error' not in failed and 'notification_token' not in failed
    assert failed['email'] == 'e2e06@example.test' and failed['message'] == 'E2E06 delivery body'
    passed('Four exhausted worker attempts expose observable failure while preserving the visitor submission')
    args = identity | {'expected_revision': failed['revision'], 'operation_key': 'e2e06-recover'}
    outcomes = concurrent_rpc('submissions_retry_notification', args)
    recovered = outcomes[0]
    assert all(outcome == recovered for outcome in outcomes)
    assert recovered['queued'] is True and recovered['notification_status'] == 'queued'
    assert rpc('submissions_retry_notification', args) == recovered
    assert rpc('submissions_retry_notification', dict(reversed(list(args.items())))) == recovered
    duplicate = write('retry_notification', {}, 'e2e06-active-repeat')
    assert duplicate['queued'] is False and duplicate['notification_status'] == 'queued'
    passed('Eight concurrent real MySQL retry receipts and repeated active retry reserve only one recovered delivery')
elif '--complete' in __import__('sys').argv:
    sent = current()
    assert sent['notification_status'] == 'sent' and sent['notification_attempts'] == 1 and sent['notified_at']
    assert 'notification_error' not in sent and 'notification_token' not in sent
    repeated = write('retry_notification', {}, 'e2e06-after-sent')
    assert repeated['queued'] is False and repeated['notification_status'] == 'sent'
    resolved = write('set_status', {'workflow_status': 'resolved'}, 'e2e06-resolved')
    assert resolved['workflow_status'] == 'resolved' and resolved['notification_status'] == 'sent'
    passed('Recovered delivery metadata and final resolved state; sent submission cannot queue another mail')
else:
    assert rpc('cms_capabilities')['admin_profile'] == profile
    listing = rpc('submissions_list', {'form': 'contact', 'search': 'e2e06@example.test'})
    assert listing['total'] == 1 and listing['submissions'][0]['id'] == fixture['id']
    original = current()
    assert original['email'] == 'e2e06@example.test' and original['notification_status'] == 'queued'
    assert original['workflow_status'] == 'new'
    for key in ('viewer', 'expired', 'invalid-token'):
        status, raw = request(endpoint, key=key)
        assert status == (401 if key in ('expired', 'invalid-token') else 403), (key, status, raw[:400])
    assert request(endpoint, key=None)[0] == 401
    for key in ('read', 'draft', 'editor'):
        assert rpc('submissions_get', identity, key)['id'] == fixture['id']
    for key in ('read', 'draft', 'viewer'):
        rejected = rpc('submissions_assign', identity | {'assigned_to': manifest['admin_id'], 'expected_revision': original['revision'], 'operation_key': 'e2e06-denied-' + key}, key, denied=200)
        assert rejected['error']['code'] == -32003
        if key == 'viewer':
            assert rejected['error']['message'] == 'Unauthorized role for submission operation.'
        else:
            assert rejected['error']['message'] in {'Esta clave no permite esta operación.', 'This key does not allow this operation.'}
    assert current() == original
    passed('Cookie-free discovery/read with explicit private-inbox role/scope and expired/invalid credential denials')
    assigned = write('assign', {'assigned_to': manifest['admin_id']}, 'e2e06-assign')
    assert assigned['assigned_to'] == manifest['admin_id']
    note_args = identity | {'body': 'E2E06 MCP follow-up note', 'expected_revision': assigned['revision'], 'operation_key': 'e2e06-note'}
    outcomes = concurrent_rpc('submissions_add_note', note_args)
    note = outcomes[0]
    assert all(outcome == note for outcome in outcomes)
    assert rpc('submissions_add_note', note_args) == note
    assert len(note['notes']) == 1 and note['notes'][0]['author']['id'] == manifest['admin_id']
    progress = write('set_status', {'workflow_status': 'in_progress'}, 'e2e06-progress')
    assert progress['workflow_status'] == 'in_progress'
    status, raw = request(endpoint + '/set-status', {'workflow_status': 'resolved', 'expected_revision': original['revision'], 'operation_key': 'e2e06-stale'})
    assert status == 409, (status, raw[:300])
    assert current()['workflow_status'] == 'in_progress'
    # Invalid public capability and unpublished originating page must never store another lead.
    public = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    status, raw = request('/contacto', key=None, opener=public)
    html = raw.decode()
    csrf = re.search(r'name="_token"[^>]*value="([^"]+)"', html).group(1)
    signed = re.search(r'name="_form_token"[^>]*value="([^"]+)"', html).group(1)
    draft = rpc('pages_create_draft', {'title': 'E2E06 unpublished', 'slug': 'e2e06-unpublished', 'template_key': 'contact', 'locale': 'es', 'operation_key': 'e2e06-draft'})
    assert request(draft['url'], key=None)[0] == 404
    payload = {'_token': csrf, '_form_token': signed, 'page_id': manifest['contact_page'], 'name': 'Rejected E2E06', 'email': 'rejected-e2e06@example.test', 'message': 'Must not persist', 'subject': 'Consulta general', 'website': '', 'recaptcha_token': 'e2e06-google-token'}
    time.sleep(4)
    status, raw = request('/forms/contact', payload | {'_form_token': 'invalid'}, key=None, opener=public)
    assert status == 422, (status, raw[:300])
    status, raw = request('/forms/contact', payload | {'page_id': draft['id']}, key=None, opener=public)
    assert status == 422, (status, raw[:300])
    status, raw = request('/forms/contact', payload | {'_token': 'invalid'}, key=None, opener=public)
    assert status == 419, (status, raw[:300])
    assert rpc('submissions_list', {'form': 'contact'})['total'] == 1
    passed('MCP assignment/note/status, eight concurrent idempotent notes, stale conflict, invalid CSRF/form token and unpublished page rejection')

result = json.loads(result_path.read_text()) if result_path.exists() else {'profile': profile, 'cookie_free_mcp': True, 'checks': []}
result['checks'].extend(checks)
result_path.write_text(json.dumps(result, indent=2))
