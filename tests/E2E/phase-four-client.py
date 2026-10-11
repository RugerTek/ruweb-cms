import json,os,re,shutil,subprocess
from pathlib import Path
storage=Path(os.environ['PHASE0_STORAGE']); database=os.environ['PHASE0_DATABASE']; s={'copy':str(Path.cwd())}; run=storage;m=json.loads((storage/'manifest.json').read_text());assert m['database']==database and re.fullmatch(r'ruweb_phase0_[a-f0-9]{12}', database)
home=run/'phase-four-client-home';home.mkdir(exist_ok=True)
# Ephemeral client home contains only existing authentication plus this run's single MCP server.
auth=Path.home()/'.codex/auth.json'
if auth.exists():shutil.copy(auth,home/'auth.json');os.chmod(home/'auth.json',0o600)
(home/'config.toml').write_text('model = "gpt-6.1-sol"\nmodel_reasoning_effort = "high"\n[mcp_servers.phase4]\nurl = "http://127.0.0.1:8018/mcp/rpc"\nbearer_token_env_var = "PHASE4_MCP_TOKEN"\n')
client_config=home/'config.toml'
tools=['cms_capabilities','cms_schema','pages_create_draft','pages_get','pages_update_draft','pages_publish']
config=client_config.read_text()
config='project_doc_max_bytes = 0\nweb_search = "disabled"\n'+config.replace('[mcp_servers.phase4]', '[features]\nshell_tool = false\nunified_exec = false\n[mcp_servers.phase4]')
config += 'enabled_tools = '+json.dumps(tools)+'\n'+''.join('[mcp_servers.phase4.tools.'+tool+']\napproval_mode = "approve"\n' for tool in tools)
client_config.write_text(config)
prompt='''You are the real acceptance client for a disposable local CMS. Use ONLY the phase4 MCP tools, no shell commands or other clients. Discover capabilities and page schema. Confirm profile essential. Create one default template Spanish DRAFT page with title "Phase Four Agent Draft", slug "phase-four-agent", content_json meta title "Phase Four SEO", description "Phase Four description", sections.content heading "Phase Four initial heading", body "<p>Phase Four initial body</p>", is_visible true. Then read and UPDATE it through MCP to heading "Phase Four agent heading" and body "<p>Phase Four agent body</p>" preserving meta and siblings, using expected_revision. Query the updated page. Attempt to publish it and report exact authorization denial: this editor credential is draft-only. Do not modify any other content. Final response ONLY compact JSON object containing id,title,slug,admin_profile,tool_count if known,updated_heading,publish_denied. Do not output credentials or preview URLs.'''
env=os.environ|{'CODEX_HOME':str(home),'PHASE4_MCP_TOKEN':m['operator_key']}
with (storage/'codex-client-private.jsonl').open('w') as log:
 result=subprocess.run([os.environ['PHASE4_CODEX_CLIENT'],'exec','--ephemeral','--skip-git-repo-check','--json','-m','gpt-6.1-sol','--ignore-rules','--sandbox','read-only','-C',s['copy'],'-o',str(storage/'codex-final.json'),prompt],env=env,stdout=log,stderr=subprocess.STDOUT)
print('Codex client exit',result.returncode)
if result.returncode:raise SystemExit(result.returncode)
text=(storage/'codex-final.json').read_text().strip();text=text.removeprefix('```json').removeprefix('```').removesuffix('```').strip(); data=json.loads(text);assert data['updated_heading']=='Phase Four agent heading';assert data['publish_denied'];(storage/'phase-four-page.json').write_text(json.dumps(data));print('PASS real Sol6.1 MCP editorial client, page',data['id'])
# Keep secret-free tool evidence; private transport logs and ephemeral auth are removed on cleanup.
items=[]
for line in (storage/'codex-client-private.jsonl').read_text().splitlines():
 try: event=json.loads(line)
 except json.JSONDecodeError: continue
 item=event.get('item', {})
 if event.get('type')=='item.completed' and item.get('type')=='mcp_tool_call':
  items.append({'tool':item['tool'],'status':item['status'],'arguments':item.get('arguments'),'result':item.get('result'),'error':item.get('error')})
required={'cms_capabilities','cms_schema','pages_create_draft','pages_get','pages_update_draft','pages_publish'}
assert required.issubset({item['tool'] for item in items})
assert all(item['status']=='completed' for item in items if item['tool']!='pages_publish')
denials=[item for item in items if item['tool']=='pages_publish']
assert denials and all(item['status']=='failed' and 'This key does not allow this operation.' in json.dumps(item['error']) for item in denials)
evidence=json.dumps({'model':'gpt-6.1-sol','client':'Codex CLI','ephemeral_config':True,'tools':items,'final':data},indent=2)
assert m['operator_key'] not in evidence and 'ruweb_mcp_' not in evidence and '/preview/' not in evidence
(storage/'phase-four-client-results.json').write_text(evidence)
