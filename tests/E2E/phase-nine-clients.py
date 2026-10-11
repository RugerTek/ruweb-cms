"""Real CLI MCP acceptance, isolated configuration and secret-free evidence.

PHASE0_STORAGE and PHASE0_DATABASE guard the disposable fixture. A private
phase-nine-client-plan.json contains cases with name, key (manifest.keys),
instructions, required_tools, and optional expected_final (exact JSON subset), expected_errors (tool: denial text).
Run with codex or claude to select one client; default runs both sequentially.
This verifies fixed bearer interoperability..
"""
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path
from urllib.parse import urlparse

CODEX = '/Applications/ChatGPT.app/Contents/Resources/codex-cli/CodexCLI.app/Contents/MacOS/codex'
CLAUDE = '/Users/alemanydev/.local/bin/claude'


def private_write(path, text):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, 'w') as stream:
        stream.write(text)


def sanitize(value, secrets):
    if isinstance(value, dict):
        return {key: ('[redacted]' if re.search(r'token|secret|authorization|password|cookie|preview_url', key, re.I) and not ('/' in key and re.search(r'\.(?:php|js|mjs|cjs|py|json|css|blade\.php)$', key))
                      else sanitize(item, secrets)) for key, item in value.items()}
    if isinstance(value, list):
        return [sanitize(item, secrets) for item in value]
    if isinstance(value, str):
        # Tool content is itself a JSON string: redact its keys and URLs after
        # decoding too, including PHP's escaped slashes and preview link tokens.
        try:
            embedded = json.loads(value)
        except (json.JSONDecodeError, TypeError):
            embedded = None
        if isinstance(embedded, (dict, list)):
            return json.dumps(sanitize(embedded, secrets), ensure_ascii=False)
        for secret in secrets:
            if secret:
                value = value.replace(secret, '[redacted]')
        value = re.sub(r'ruweb_mcp_[A-Za-z0-9_-]+', '[redacted]', value)
        value = re.sub(r'(?:https?://[^/\s"<>]+)?/(?:draft-preview|preview)/[A-Za-z0-9_-]+', '[redacted preview]', value)
        return value
    return value


def parse_evidence(client, output):
    calls, final, pending = [], None, {}
    for line in output.splitlines():
        try:
            event = json.loads(line)
        except json.JSONDecodeError:
            continue
        if client == 'codex':
            item = event.get('item', {})
            if event.get('type') == 'item.completed' and item.get('type') == 'mcp_tool_call':
                calls.append({'tool': item.get('tool'), 'status': item.get('status'),
                              'arguments': item.get('arguments'), 'result': item.get('result'),
                              'error': item.get('error')})
            if event.get('type') == 'item.completed' and item.get('type') == 'agent_message':
                final = item.get('text')
        elif event.get('type') in ('assistant', 'user'):
            for block in event.get('message', {}).get('content', []):
                if not isinstance(block, dict):
                    continue
                if block.get('type') == 'tool_use':
                    name = block.get('name', '')
                    if name.startswith('mcp__phase9__'):
                        pending[block['id']] = {'tool': name.removeprefix('mcp__phase9__'),
                                                'arguments': block.get('input')}
                    else:
                        raise AssertionError('Claude used a tool outside the MCP allowlist')
                if block.get('type') == 'tool_result' and block.get('tool_use_id') in pending:
                    content = block.get('content')
                    normalized = content if isinstance(content, dict) else {
                        'isError': bool(block.get('is_error')),
                        'content': content if isinstance(content, list) else [{'type': 'text', 'text': content}]}
                    calls.append(pending.pop(block['tool_use_id']) | {
                        'status': 'failed' if block.get('is_error') else 'completed',
                        'result': normalized})
        elif client == 'claude' and event.get('type') == 'result':
            final = event.get('result')
    assert not pending, 'Incomplete MCP tool calls'
    assert final, 'Client did not return final evidence'
    text = final.strip().removeprefix('```json').removeprefix('```').removesuffix('```').strip()
    return calls, json.loads(text)


def validate_successful_call(call):
    assert call['status'] == 'completed' and not call.get('error'), 'Unexpected MCP failure'
    result = call.get('result')
    assert isinstance(result, dict), 'Missing normalized MCP result'
    # MCP isError is optional; absent means false. Codex omits false in its
    # normalized JSONL result, so also validate the inner CMS response.
    assert result.get('isError', result.get('is_error', False)) is False, 'MCP error result'
    blocks = result.get('content', [])
    assert blocks, 'Missing CMS response content'
    for block in blocks:
        if block.get('type') == 'text':
            payload = json.loads(block['text'])
            assert isinstance(payload, dict) and 'data' in payload, 'Missing CMS data response'
            assert not payload.get('error') and not payload.get('errors'), 'CMS business error response'


def run_case(client, case, root, endpoint, manifest, profile):
    tools = case.get('required_tools', [])
    assert (tools or case.get('expected_startup_error')) and all(re.fullmatch(r'[a-z][a-z0-9_]+', tool) for tool in tools)
    key = manifest['keys'][case['key']]
    prompt = ('Act only as an MCP acceptance client for a disposable CMS; do not perform coding work. '
              'Use only the phase9 MCP tools. Do not use shell, files, web, other servers or subagents. '
              'Never output credentials or preview URLs. ' + case['instructions'] +
              ' Return only a compact JSON object with the requested evidence, no Markdown.')
    with tempfile.TemporaryDirectory(prefix='phase9-client-', dir=root) as work:
        work = Path(work)
        os.chmod(work, 0o700)
        env = os.environ.copy()
        env.pop('CODEX_THREAD_ID', None)
        if client == 'codex':
            home = work / 'codex-home'
            home.mkdir(mode=0o700)
            auth = Path.home() / '.codex/auth.json'
            assert auth.exists(), 'Codex login unavailable'
            shutil.copyfile(auth, home / 'auth.json')
            os.chmod(home / 'auth.json', 0o600)
            env['CODEX_HOME'] = str(home)
            env['PHASE9_MCP_TOKEN'] = key
            config = ('model = "gpt-6.1-sol"\nmodel_reasoning_effort = "high"\n'
                      'cli_auth_credentials_store = "file"\nweb_search = "disabled"\n'
                      'project_doc_max_bytes = 0\n[features]\nshell_tool = false\nunified_exec = false\n'
                      '[mcp_servers.phase9]\nurl = ' + json.dumps(endpoint) + '\n'
                      'bearer_token_env_var = "PHASE9_MCP_TOKEN"\nrequired = true\n'
                      'enabled_tools = ' + json.dumps(tools) + '\n' + ''.join(
                          '[mcp_servers.phase9.tools.' + tool + ']\napproval_mode = \"approve\"\n'
                          for tool in tools))
            private_write(home / 'config.toml', config)
            command = [os.environ.get('PHASE9_CODEX_CLIENT', CODEX), '--ask-for-approval', 'never', 'exec', '--ephemeral',
                       '--ignore-rules', '--skip-git-repo-check', '--json', '--color', 'never',
                       '--sandbox', 'read-only', '-C', str(work), prompt]
            secrets = [key]
        else:
            # Native existing login owns its normal token renewal. Only MCP/settings
            # are overridden for this invocation; never extract or copy refresh secrets.
            config = {'mcpServers': {'phase9': {'type': 'http', 'url': endpoint,
                                              'headers': {'Authorization': 'Bearer ' + key}}}}
            path = work / 'mcp.json'
            private_write(path, json.dumps(config))
            settings = {'permissions': {'allow': ['mcp__phase9__' + tool for tool in tools]},
                        'disableAllHooks': True, 'enableAllProjectMcpServers': False}
            command = [os.environ.get('PHASE9_CLAUDE_CLIENT', CLAUDE), '-p', '--verbose',
                       '--output-format', 'stream-json', '--no-session-persistence', '--tools', '',
                       '--permission-mode', 'dontAsk', '--allowedTools', ','.join('mcp__phase9__' + tool for tool in tools),
                       '--setting-sources', '', '--settings', json.dumps(settings), '--disable-slash-commands',
                       '--strict-mcp-config', '--mcp-config', str(path), prompt]
            secrets = [key]
        try:
            proc = subprocess.run(command, env=env, cwd=work, capture_output=True, text=True,
                                  timeout=int(os.environ.get('PHASE9_CLIENT_TIMEOUT', '600')))
        except subprocess.TimeoutExpired:
            raise AssertionError(client + ' timed out; no acceptance evidence') from None
        if case.get('expected_startup_error'):
            diagnostic = sanitize(proc.stdout + proc.stderr, secrets)
            private_write(root / ('phase-nine-' + client + '-' + case['name'] + '-startup.json'),
                          json.dumps({'exit_code': proc.returncode, 'diagnostic': diagnostic[-5000:]}, indent=2))
            assert proc.returncode != 0, 'Revoked credential unexpectedly started'
            assert case['expected_startup_error'] in diagnostic, 'Expected startup authorization error missing'
            calls = []
            for line in proc.stdout.splitlines():
                try:
                    event = json.loads(line)
                except json.JSONDecodeError:
                    continue
                item = event.get('item', {})
                if item.get('type') == 'mcp_tool_call':
                    calls.append(item)
            assert not calls, 'Revoked credential reached MCP tools'
            evidence = {'client': client, 'case': case['name'], 'profile': profile,
                        'authentication': 'revoked-named-api-key', 'isolated_config': True,
                        'exit_code': proc.returncode, 'expected_startup_error': case['expected_startup_error'],
                        'startup_denied': True, 'mcp_calls': 0}
            private_write(root / ('phase-nine-' + client + '-' + case['name'] + '-results.json'),
                          json.dumps(evidence, indent=2))
            print('PASS real ' + client + ' rejects revoked MCP credential at startup', flush=True)
            return
        if proc.returncode:
            # Raw client logs can contain transport secrets; never persist or print them.
            diagnostic = sanitize((proc.stdout + proc.stderr)[-1800:], secrets)
            raise AssertionError(client + ' failed: ' + diagnostic)
        calls, final = parse_evidence(client, proc.stdout)
        evidence = sanitize({'client': client, 'case': case['name'], 'profile': profile,
                             'authentication': 'named-api-key', 'isolated_config': True,
                             'exit_code': proc.returncode, 'validated': False, 'calls': calls, 'final': final}, secrets)
        serialized = json.dumps(evidence, indent=2)
        assert key not in serialized and 'ruweb_mcp_' not in serialized
        private_write(root / ('phase-nine-' + client + '-' + case['name'] + '-results.json'), serialized)
        assert set(tools).issubset({call['tool'] for call in calls}), 'Required MCP calls missing'
        assert all(call['tool'] in tools for call in calls), 'Unexpected MCP call'
        expected_errors = case.get('expected_errors', {})
        for call in calls:
            if call['tool'] in expected_errors:
                result = call.get('result') or {}
                assert call['status'] == 'failed' or result.get('isError') is True, 'Denial was not an error'
                assert expected_errors[call['tool']] in json.dumps(call), 'Expected denial missing'
            else:
                validate_successful_call(call)
        for field, expected in case.get('expected_final', {}).items():
            assert final.get(field) == expected, 'Final evidence mismatch for ' + field
        evidence['validated'] = True
        private_write(root / ('phase-nine-' + client + '-' + case['name'] + '-results.json'),
                      json.dumps(evidence, indent=2))
        print('PASS real ' + client + ' MCP case ' + case['name'], flush=True)


def main():
    storage = Path(os.environ['PHASE0_STORAGE']).resolve()
    database = os.environ['PHASE0_DATABASE']
    manifest = json.loads((storage / 'manifest.json').read_text())
    assert re.fullmatch(r'ruweb_phase0_[a-f0-9]{12}', database) and manifest['database'] == database
    profile = os.environ['PHASE9_PROFILE']
    assert profile in ('full', 'essential')
    base = os.environ.get('PHASE9_BASE', 'http://127.0.0.1:8018')
    parsed = urlparse(base)
    assert parsed.scheme == 'http' and parsed.hostname in ('127.0.0.1', 'localhost')
    assert not parsed.username and not parsed.password and not parsed.query and not parsed.fragment
    endpoint = base.rstrip('/') + '/mcp/rpc'
    plan = json.loads((storage / 'phase-nine-client-plan.json').read_text())
    assert plan['profile'] == profile
    clients = sys.argv[1:] or ['codex', 'claude']
    assert clients and all(client in ('codex', 'claude') for client in clients)
    for client in clients:
        for case in plan['cases']:
            assert re.fullmatch(r'[a-z0-9-]+', case['name'])
            run_case(client, case, storage, endpoint, manifest, profile)


if __name__ == '__main__':
    main()
