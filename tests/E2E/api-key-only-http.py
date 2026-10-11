#!/usr/bin/env python3
"""Real isolated HTTP acceptance, including admin CSRF/login and key lifecycle, both profiles."""
import base64, http.cookiejar, json, os, pathlib, re, secrets, socket, subprocess, tempfile, time
import urllib.error, urllib.parse, urllib.request

root = pathlib.Path(__file__).resolve().parents[2]
for profile in ['full', 'essential']:
    with tempfile.TemporaryDirectory(prefix='ruweb-key-only-') as temp:
        storage = pathlib.Path(temp)
        for directory in ['framework/sessions', 'framework/views', 'framework/cache/data', 'logs', 'app/public', 'app/private']:
            (storage / directory).mkdir(parents=True)
        (storage / 'database.sqlite').touch()
        with socket.socket() as socket_:
            socket_.bind(('127.0.0.1', 0)); port = socket_.getsockname()[1]
        base = f'http://127.0.0.1:{port}'
        env = os.environ | {'KEY_ONLY_STORAGE': temp, 'KEY_ONLY_PROFILE': profile, 'APP_URL': base,
                            'APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode()}
        subprocess.run(['php', 'tests/E2E/api-key-only-fixture.php'], cwd=root, env=env, check=True, capture_output=True)
        keys = json.loads((storage / 'manifest.json').read_text())
        client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        def request(path, data=None, key=None, form=False, method=None):
            headers = {'Accept': 'application/json' if not form else 'text/html'}
            if key: headers['Authorization'] = 'Bearer ' + key
            if data is not None:
                headers['Content-Type'] = 'application/x-www-form-urlencoded' if form else 'application/json'
                data = (urllib.parse.urlencode(data) if form else json.dumps(data)).encode()
            try:
                response = client.open(urllib.request.Request(base + path, data, headers, method=method))
            except urllib.error.HTTPError as error: response = error
            return response.status, response.read().decode(), response.geturl()
        def rpc(key, name, arguments=None):
            status, body, _ = request('/mcp/rpc', {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call',
                 'params': {'name': name, 'arguments': arguments or {}}}, key)
            return status, json.loads(body)
        def csrf(body): return re.search(r'name="_token" value="([^"]+)"', body).group(1)
        with (storage / 'server.log').open('w') as log:
            server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', 'public', 'tests/E2E/api-key-only-router.php'], cwd=root, env=env, stdout=log, stderr=log)
            try:
                for attempt in range(100):
                    try:
                        if request('/login', form=True)[0] == 200: break
                    except urllib.error.URLError: time.sleep(.05)
                else: raise AssertionError('Server unavailable')
                for key in [None, 'invalid', keys['expired'], keys['revoked']]:
                    assert request('/mcp/capabilities', key=key)[0] == 401
                    assert request('/mcp/rpc', {'jsonrpc': '2.0', 'id': 1, 'method': 'ping'}, key)[0] == 401
                for scope in ['full', 'read-only', 'draft-only']:
                    status, result = rpc(keys[scope], 'cms_capabilities'); assert status == 200 and 'result' in result
                    assert request('/mcp/pages', key=keys[scope])[0] == 200
                for scope in ['read-only', 'draft-only']:
                    status, result = rpc(keys[scope], 'menus_create', {'name': 'Denied', 'slug': 'denied'})
                    assert status == 200 and result['error']['code'] == -32003 and result['error']['data']['reason']
                    assert request('/mcp/menus', {'name': 'Denied', 'slug': 'denied'}, keys[scope])[0] == 403
                for path in ['/.well-known/oauth-authorization-server', '/authorize', '/token']:
                    assert request(path)[0] == 404
                assert request('/token', {'grant_type': 'authorization_code'})[0] == 405
                assert request('/authorize', {})[0] == 405
                _, login, _ = request('/login', form=True)
                status, panel, url = request('/login', {'_token': csrf(login), 'login': 'key-only@example.test', 'password': 'Key-Only-Test-2026!'}, form=True)
                assert status == 200 and urllib.parse.urlparse(url).path == '/admin'
                assert request('/admin/users', form=True)[0] == 200
                assert request('/mcp/capabilities')[0] == 401  # cookie session is not a credential
                _, panel, _ = request('/admin/claude-mcp', form=True)
                assert 'mcp-client-id' not in panel and 'mcp-secret' not in panel
                status, panel, _ = request('/admin/claude-mcp/api-key', {'_token': csrf(panel), 'name': 'HTTP key', 'scope': 'read-only'}, form=True)
                assert status == 200
                key = re.search(r'<code[^>]*>(ruweb_mcp_[^<]+)</code>', panel).group(1).strip()
                assert request('/mcp/capabilities', key=key)[0] == 200
                assert key not in request('/admin/claude-mcp', form=True)[1]
                revoke = re.search(r'action="([^"]+/api-keys/\d+)"', panel.split('HTTP key')[1].split('</li>')[0]).group(1)
                status, _, _ = request(urllib.parse.urlparse(revoke).path, {'_token': csrf(panel), '_method': 'DELETE'}, form=True)
                assert status == 200 and request('/mcp/capabilities', key=key)[0] == 401
                _, security, _ = request('/admin/profile?tab=security', form=True)
                status, setup, _ = request('/admin/profile/two-factor/enable', {'_token': csrf(security), 'current_password': 'Key-Only-Test-2026!'}, form=True)
                assert status == 200 and 'id="setup-key"' in setup
                code = subprocess.run(['php', 'tests/E2E/api-key-only-fixture.php', 'totp'], cwd=root, env=env, check=True, capture_output=True, text=True).stdout.strip()
                status, enrolled, _ = request('/admin/profile/two-factor/confirm', {'_token': csrf(setup), 'code': code}, form=True)
                assert status == 200
                recovery = re.findall(r'<li>([a-z0-9-]+)</li>', enrolled)
                assert len(recovery) == 8
                request('/admin/logout', {'_token': csrf(enrolled)}, form=True)
                _, login, _ = request('/login', form=True)
                status, challenge, url = request('/login', {'_token': csrf(login), 'login': 'key-only@example.test', 'password': 'Key-Only-Test-2026!'}, form=True)
                assert urllib.parse.urlparse(url).path == '/admin/two-factor'
                status, dashboard, url = request('/admin/two-factor', {'_token': csrf(challenge), 'code': recovery[0]}, form=True)
                assert status == 200 and urllib.parse.urlparse(url).path == '/admin'
                subprocess.run(['php', 'tests/E2E/api-key-only-fixture.php', 'recover'], cwd=root, env=env, check=True, capture_output=True)
                for key in keys.values(): assert request('/mcp/capabilities', key=key)[0] == 401
                assert urllib.parse.urlparse(request('/admin/profile', form=True)[2]).path == '/login'
                print(f'PASS {profile}: real HTTP key auth, scopes/RPC refusal, missing/invalid/expired/revoked keys, removed OAuth, admin login/users, key issuance/revocation, 2FA enrollment/challenge/recovery code, CLI recovery')
            finally:
                server.terminate(); server.wait(timeout=5)
