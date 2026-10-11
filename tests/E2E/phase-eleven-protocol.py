"""Actual HTTP diagnostics and independent account/scope permissions in both clean fixtures."""
import json,subprocess,urllib.request,urllib.error
from pathlib import Path
s=json.loads(Path('/tmp/ruweb-phase11-state.json').read_text())
for profile,a in s['apps'].items():
 manifest=json.loads((Path(a['storage'])/'manifest.json').read_text());checks=[]
 def rpc(name,args,key='full'):
  r=urllib.request.Request('http://127.0.0.1:'+str(a['port'])+'/mcp/rpc',data=json.dumps({'jsonrpc':'2.0','id':'selector','method':'tools/call','params':{'name':name,'arguments':args}}).encode(),headers={'Authorization':'Bearer '+manifest['keys'][key],'Content-Type':'application/json'})
  try:
   with urllib.request.urlopen(r)as x:return x.status,json.load(x)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 for tool,field in [('pages_get','page_id'),('posts_get','post_id'),('media_get','media_id'),('settings_get','key'),('menus_get','menu_id')]:
  for args in [{},{field:[]},{field:True}]:
   status,body=rpc(tool,args);assert status==200 and body['error']['code']==-32602 and field in body['error']['data'];checks.append({'tool':tool,'input':args,'diagnostic':body['error']})
 assert rpc('custom_code_list',{})[1]['error']['code']==-32003
 subprocess.run(['python3','tests/E2E/phase-eleven-run.py','revoke-code-grant',profile],check=True,capture_output=True)
 assert rpc('custom_code_list',{},'custom-code')[1]['error']['code']==-32003
 subprocess.run(['python3','tests/E2E/phase-eleven-run.py','grant',profile],check=True,capture_output=True)
 status,body=rpc('custom_code_list',{},'custom-code');assert status==200 and 'error' not in body
 out=Path(s['source'])/'docs/plans/phase-11-evidence'/profile/'protocol-permissions.json';out.write_text(json.dumps({'profile':profile,'field_diagnostics':checks,'editorial_scope_denied':True,'custom_scope_without_account_permission_denied':True,'separate_account_permission_required':True},indent=2));print('PASS final protocol and scope/account separation '+profile)
