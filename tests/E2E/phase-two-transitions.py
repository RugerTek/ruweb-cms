"""E2E02 cached deployment transitions; execute after HTTP/browser acceptance and stop its server."""
import json
import os
import re
import subprocess
import time
import urllib.request
import urllib.error
from pathlib import Path

storage = Path(os.environ['PHASE0_STORAGE'])
manifest = json.loads((storage / 'manifest.json').read_text())
assert re.fullmatch(r'ruweb_phase0_[a-f0-9]{12}', os.environ['PHASE0_DATABASE'])
assert manifest['database'] == os.environ['PHASE0_DATABASE']
initial = os.environ['PHASE2_PROFILE']
page_id = json.loads((storage / 'phase-two-page.json').read_text())['id']
config = Path('config/cms.php')
original = config.read_text()
renderer = Path('resources/views/templates/default.blade.php')
original_renderer = renderer.read_text()
partial = Path('resources/views/admin/pages/sections/default_testimonials.blade.php')
original_partial = partial.read_bytes() if partial.exists() else None
checks = []
process_ids = []


def control(action, expect=0):
    result = subprocess.run(['php', 'tests/E2E/phase-two-control.php', action], text=True, capture_output=True)
    assert result.returncode == expect, (action, result.returncode, result.stdout, result.stderr)
    return result.stdout


def set_profile(profile):
    config.write_text(re.sub(r"'admin_profile' => '(full|essential)'", "'admin_profile' => '" + profile + "'", original, count=1))


def request(path, key='full'):
    headers = {'Accept': 'application/json', 'Authorization': 'Bearer ' + manifest['keys'][key]} if key else {}
    with urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:8018' + path, headers=headers)) as response:
        return response.status, response.read().decode()


def start_server():
    log = (storage / 'transition-server.log').open('a')
    server = subprocess.Popen(['php', '-S', '127.0.0.1:8018', '-t', 'public', 'tests/E2E/phase-zero-router.php'], stdout=log, stderr=subprocess.STDOUT)
    time.sleep(.25)
    assert server.poll() is None
    process_ids.append(server.pid)
    return server, log


baseline = None
try:
    for profile in [initial, 'essential' if initial == 'full' else 'full', initial]:
        set_profile(profile)
        control('cache')
        assert (storage / 'config.php').is_file() and Path('bootstrap/cache/routes-v7.php').is_file()
        local = json.loads(control('discovery'))
        assert local['admin_profile'] == profile
        server, log = start_server()
        try:
            status, raw = request('/mcp/capabilities', key='read')
            assert status == 200 and json.loads(raw)['data'] == local
            status, raw = request('/mcp/pages/' + str(page_id))
            page = json.loads(raw)['data']
            if baseline is None:
                baseline = page
            assert page == baseline
            assert request('/phase2-testimonials', key=None)[0] == 200
        finally:
            server.terminate()
            server.wait()
            log.close()
        if profile == 'full' and original_partial is None:
            output = control('conventions', expect=1)
            assert 'section-editor-missing' in output
            assert not local['templates']['default']['sections']['testimonials']['visual_editor_available']
        else:
            control('conventions')
        checks.append(profile + ': cached CLI/MCP discovery and content preserved after server restart')
        renderer.write_text(original_renderer + '\n<img src="/forbidden-fixture.jpg">\n')
        try:
            output = control('conventions', expect=1)
            assert 'template-hardcoded-image' in output
        finally:
            renderer.write_text(original_renderer)
        checks.append(profile + ': content-managed literal image still fails conventions')
    # Missing specialized editor must fail full and pass essential in exactly the same delivery.
    if original_partial is not None:
        partial.unlink()
    set_profile('full')
    control('cache')
    assert 'section-editor-missing' in control('conventions', expect=1)
    set_profile('essential')
    control('cache')
    control('conventions')
    checks.append('Identical missing editor fails full and passes essential')
    # Remove only this run's cached config before testing startup with an invalid versioned value.
    (storage / 'config.php').unlink()
    config.write_text(re.sub(r"'admin_profile' => '(full|essential)'", "'admin_profile' => 'agent-only'", original, count=1))
    invalid_env = os.environ | {'DB_CONNECTION': 'mysql', 'DB_DATABASE': manifest['database'], 'DB_URL': '', 'LARAVEL_STORAGE_PATH': str(storage), 'APP_CONFIG_CACHE': str(storage / 'config.php'), 'APP_URL': 'http://127.0.0.1:8018', 'APP_ENV': 'local'}
    result = subprocess.run(['php', 'artisan', 'cms:capabilities'], text=True, capture_output=True, env=invalid_env)
    assert result.returncode != 0 and 'Invalid cms.admin_profile' in result.stdout + result.stderr
    checks.append('Invalid deployment profile fails explicitly at startup')
finally:
    config.write_text(original)
    renderer.write_text(original_renderer)
    if original_partial is not None:
        partial.write_bytes(original_partial)
    elif partial.exists():
        partial.unlink()
    if (storage / 'config.php').exists():
        (storage / 'config.php').unlink()
    control('cache')

(storage / 'phase-two-transition-results.json').write_text(json.dumps({'initial_profile': initial, 'checks': checks, 'server_process_ids': process_ids, 'page_id': page_id}, ensure_ascii=False, indent=2))
print('PASS cached profile transitions, data preservation, conventions and invalid diagnostic: ' + initial)
