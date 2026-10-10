"""E2E05: cookie-free editorial review on guarded real MySQL, in either profile.
Run initial stage, browser proposal stage, --complete, browser restored stage.
Uses phase-zero fixture/bootstrap and phase-three router's isolated clock.
"""
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone
from pathlib import Path

storage = Path(os.environ['PHASE0_STORAGE'])
manifest = json.loads((storage / 'manifest.json').read_text())
assert re.fullmatch(r'ruweb_phase0_[a-f0-9]{12}', os.environ['PHASE0_DATABASE'])
assert manifest['database'] == os.environ['PHASE0_DATABASE']
profile = os.environ['PHASE5_PROFILE']
assert profile in ('full', 'essential')
base = 'http://127.0.0.1:8018'
api = urllib.request.build_opener()
checks = []
fixture_path = storage / 'phase-five-fixture.json'
results_path = storage / 'phase-five-results.json'


def request(path, data=None, key='full', method=None):
    target = urllib.parse.urljoin(base, path)
    assert urllib.parse.urlparse(target).netloc == '127.0.0.1:8018'
    headers = {'Accept': 'application/json'} if key else {}
    if key:
        headers['Authorization'] = 'Bearer ' + manifest['keys'][key]
    if data is not None:
        headers['Content-Type'] = 'application/json'
        data = json.dumps(data).encode()
    req = urllib.request.Request(target, data=data, headers=headers, method=method)
    assert not req.has_header('Cookie')
    try:
        with api.open(req) as response:
            return response.status, response.read(), dict(response.headers)
    except urllib.error.HTTPError as error:
        return error.code, error.read(), dict(error.headers)


def rpc(name, args=None, key='full'):
    status, raw, _ = request('/mcp/rpc', {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': name, 'arguments': args or {}}}, key)
    body = json.loads(raw)
    assert status == 200 and 'error' not in body and not body['result']['isError'], (name, status, body)
    data = json.loads(body['result']['content'][0]['text'])['data']
    for field in ('version', 'review', 'comment', 'link'):
        if field in data:
            return data[field] | data
    return data


def entity(page):
    current = rpc('pages_get', {'page_id': page['id']})
    return {'type': 'pages', 'id': page['id'], 'expected_revision': current['revision']}


def endpoint(page, action):
    return '/mcp/content/pages/' + str(page['id']) + '/' + action


def passed(label):
    checks.append(label)
    print('PASS ' + label, flush=True)


def visible(url, heading, absent=None):
    status, raw, headers = request(url, key=None)
    assert status == 200 and heading.encode() in raw and (absent is None or absent.encode() not in raw), (status, raw[:300])
    return headers


if '--complete' not in sys.argv:
    assert rpc('cms_capabilities')['admin_profile'] == profile
    page = rpc('pages_create_draft', {'title': 'E2E05 original', 'slug': 'phase5-review', 'template_key': 'home', 'locale': 'es', 'featured_image': manifest['media_id'], 'content_json': {'sections': {'hero': {'heading': 'E2E05 original public', 'image_id': manifest['media_id'], 'body': '<p>Original editorial body</p>', 'is_visible': True}}}, 'operation_key': 'e2e05-create'})
    page = rpc('pages_get', {'page_id': page['id']})
    page = rpc('pages_publish', {'page_id': page['id'], 'expected_revision': page['revision']})
    versions = rpc('content_versions', {'type': 'pages', 'id': page['id']})
    # Read the complete exact published baseline; versions are newest-first.
    rows = versions.get('versions', versions.get('data', [])) if isinstance(versions, dict) else versions
    baseline = next(row for row in rows if not row['is_proposal'])
    proposal_args = entity(page) | {'changes': {'title': 'E2E05 approved title', 'content_json': {'sections': {'hero': {'heading': 'E2E05 exact proposal'}}}}, 'operation_key': 'e2e05-propose'}
    proposal = rpc('content_propose', proposal_args, 'editor')
    assert proposal['user_id'] == manifest['author_id']
    assert rpc('content_propose', proposal_args, 'editor')['id'] == proposal['id']
    assert rpc('content_propose', dict(reversed(list(proposal_args.items()))), 'editor')['id'] == proposal['id']
    preview = rpc('content_preview', {'type': 'pages', 'id': page['id'], 'version_id': proposal['id'], 'hours': 1}, 'editor')
    headers = visible(preview['url'], 'E2E05 exact proposal', 'E2E05 original public')
    assert headers.get('X-Robots-Tag') == 'noindex, nofollow, noarchive'
    assert headers.get('Cache-Control') == 'no-store, private'
    visible(page['url'], 'E2E05 original public', 'E2E05 exact proposal')
    review = rpc('content_review_request', entity(page) | {'version_id': proposal['id'], 'reviewer_id': manifest['reviewer_id'], 'operation_key': 'e2e05-request'}, 'editor')
    comment = rpc('content_review_comment', entity(page) | {'review_id': review['id'], 'body': 'Exact anonymous preview checked', 'operation_key': 'e2e05-comment'}, 'editor')
    assert comment['user_id'] == manifest['author_id'] and review['requested_by'] == manifest['author_id']
    read_reviews = rpc('content_reviews', {'type': 'pages', 'id': page['id']}, 'read')
    assert read_reviews['reviews'][0]['comments'][0]['body'] == 'Exact anonymous preview checked'
    delta = rpc('content_diff', {'type': 'pages', 'id': page['id'], 'version_id': proposal['id']}, 'read')
    assert delta['diff']['attributes.title']['after'] == 'E2E05 approved title'
    for key in ('other', 'editor', 'draft'):
        status, raw, _ = request(endpoint(page, 'review-decide'), entity(page) | {'review_id': review['id'], 'state': 'approved', 'operation_key': 'e2e05-denied-' + key}, key)
        assert status == 403, (key, status, raw[:300])
    status, raw, _ = request(endpoint(page, 'propose'), entity(page) | {'changes': {'title': 'Forbidden'}, 'operation_key': 'e2e05-read-write'}, 'read')
    assert status == 403
    passed('Bearer proposal retry, public unchanged, exact pinned anonymous preview, comments and reviewer/scope restrictions')
    fixture = {'profile': profile, 'page': page, 'baseline': baseline, 'proposal': proposal, 'review': review, 'preview': preview}
    fixture_path.write_text(json.dumps(fixture, indent=2)); fixture_path.chmod(0o600)
    results_path.write_text(json.dumps({'profile': profile, 'checks': checks, 'cookie_free_mcp': True}, indent=2))
else:
    fixture = json.loads(fixture_path.read_text()); assert fixture['profile'] == profile
    page = fixture['page']
    rpc('content_review_decide', entity(page) | {'review_id': fixture['review']['id'], 'state': 'approved', 'operation_key': 'e2e05-approve'})
    visible(page['url'], 'E2E05 exact proposal', 'E2E05 original public')
    passed('Assigned reviewer approval applies exact proposal publicly')
    stale_proposal = rpc('content_propose', entity(page) | {'changes': {'title': 'Stale proposal'}, 'operation_key': 'e2e05-stale-propose'}, 'editor')
    stale_review = rpc('content_review_request', entity(page) | {'version_id': stale_proposal['id'], 'reviewer_id': manifest['reviewer_id'], 'operation_key': 'e2e05-stale-request'}, 'editor')
    pre_request_stale = rpc('content_propose', entity(page) | {'changes': {'title': 'Stale before request'}, 'operation_key': 'e2e05-before-request'}, 'editor')
    intervening = rpc('content_propose', entity(page) | {'changes': {'title': 'New base title'}, 'operation_key': 'e2e05-new-propose'}, 'editor')
    newer_review = rpc('content_review_request', entity(page) | {'version_id': intervening['id'], 'reviewer_id': manifest['reviewer_id'], 'operation_key': 'e2e05-new-request'}, 'editor')
    rpc('content_review_decide', entity(page) | {'review_id': newer_review['id'], 'state': 'approved', 'operation_key': 'e2e05-new-approve'})
    status, raw, _ = request(endpoint(page, 'review-request'), entity(page) | {'version_id': pre_request_stale['id'], 'reviewer_id': manifest['reviewer_id'], 'operation_key': 'e2e05-stale-before-request'}, 'editor')
    assert status == 409, (status, raw[:300])
    assert not any(row['version_id'] == pre_request_stale['id'] for row in rpc('content_reviews', {'type': 'pages', 'id': page['id']})['reviews'])
    passed('Proposal created on older base cannot be submitted using refreshed current revision')
    status, raw, _ = request(endpoint(page, 'review-decide'), entity(page) | {'review_id': stale_review['id'], 'state': 'approved', 'operation_key': 'e2e05-stale-approve'})
    assert status == 409 and rpc('pages_get', {'page_id': page['id']})['title'] == 'New base title', (status, raw[:300])
    passed('Stale review cannot overwrite a newer approved base')
    restored = rpc('content_restore', entity(page) | {'version_id': fixture['baseline']['id'], 'mode': 'draft', 'operation_key': 'e2e05-restore'})
    page = rpc('pages_get', {'page_id': page['id']})
    assert page['status'] == 'draft' and page['title'] == 'E2E05 original'
    assert request(page['url'], key=None)[0] == 404
    restored_version = rpc('content_propose', entity(page) | {'changes': {'title': 'E2E05 original'}, 'operation_key': 'e2e05-restored-propose'}, 'editor')
    restored_review = rpc('content_review_request', entity(page) | {'version_id': restored_version['id'], 'reviewer_id': manifest['reviewer_id'], 'operation_key': 'e2e05-restored-review'}, 'editor')
    rpc('content_review_decide', entity(page) | {'review_id': restored_review['id'], 'state': 'approved', 'operation_key': 'e2e05-restored-approve'})
    page = rpc('pages_get', {'page_id': page['id']})
    assert page['status'] == 'draft'
    page = rpc('pages_publish', {'page_id': page['id'], 'expected_revision': page['revision']})
    visible(page['url'], 'E2E05 original public', 'E2E05 exact proposal')
    original_media = rpc('content_version', {'type': 'pages', 'id': page['id'], 'version_id': fixture['baseline']['id']})['payload']['media']
    latest = rpc('content_versions', {'type': 'pages', 'id': page['id'], 'per_page': 1})['versions'][0]
    current_media = rpc('content_version', {'type': 'pages', 'id': page['id'], 'version_id': latest['id']})['payload']['media']
    assert current_media == original_media and any(row['id'] == manifest['media_id'] for row in current_media)
    public_html = request(page['url'], key=None)[1].decode()
    image_urls = re.findall(r'src="([^"]*/storage/media/[^"]+)"', public_html)
    assert image_urls, 'Restored public image must render'
    for image_url in image_urls:
        status, image_bytes, image_headers = request(image_url, key=None)
        assert status == 200 and len(image_bytes) > 100 and image_headers.get('Content-Type', '').startswith('image/')
    rpc('content_preview_revoke', entity(page) | {'link_id': fixture['preview']['id'], 'operation_key': 'e2e05-revoke'}, 'editor')
    assert request(fixture['preview']['url'], key=None)[0] == 404
    passed('Historical draft restore, review without publication, explicit republish and preview revocation')
    expiry = rpc('content_preview', {'type': 'pages', 'id': page['id'], 'hours': 1})
    expiry_time = datetime.fromisoformat(expiry['expires_at'].replace('Z', '+00:00')) + timedelta(seconds=1)
    subprocess.check_call(['php', 'tests/E2E/phase-five-control.php', 'clock', expiry_time.isoformat()])
    try:
        assert request(expiry['url'], key=None)[0] == 404
    finally:
        subprocess.check_call(['php', 'tests/E2E/phase-five-control.php', 'reset-clock'])
    passed('Anonymous preview expires under isolated controlled clock')
    withdrawal = datetime.now(timezone.utc) + timedelta(minutes=5)
    rpc('content_publication', entity(page) | {'action': 'expire', 'expires_at': withdrawal.isoformat()})
    subprocess.check_call(['php', 'tests/E2E/phase-five-control.php', 'clock', (withdrawal + timedelta(seconds=1)).isoformat()])
    try:
        assert request(page['url'], key=None)[0] == 404
        assert page['url'].encode() not in request('/sitemap.xml', key=None)[1]
    finally:
        subprocess.check_call(['php', 'tests/E2E/phase-five-control.php', 'reset-clock'])
    rpc('content_publication', entity(page) | {'action': 'expire', 'expires_at': None})
    passed('Scheduled withdrawal hides public URL and sitemap at controlled clock boundary')
    fixture['page'] = page
    fixture_path.write_text(json.dumps(fixture, indent=2))
    result = json.loads(results_path.read_text()); result['checks'].extend(checks)
    results_path.write_text(json.dumps(result, indent=2))
