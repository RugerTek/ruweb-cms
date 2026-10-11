"""Real HTTP/MySQL retry after a socket loss AFTER upstream committed the write."""
import http.cookiejar, http.client, http.server, json, os, re, socket, threading, time, urllib.error, urllib.parse, urllib.request
from pathlib import Path
storage=Path(os.environ['PHASE0_STORAGE']); profile=os.environ['PHASE9_PROFILE']
manifest=json.loads((storage/'manifest.json').read_text())
assert manifest['database']==os.environ['PHASE0_DATABASE'] and re.fullmatch(r'ruweb_phase0_[a-f0-9]{12}',manifest['database'])
assert profile in ['full','essential']
base='http://127.0.0.1:8018'; checks=[]
api=urllib.request.build_opener(); browser=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def request(path,data=None,key=None,opener=api):
 headers={'Accept':'application/json'}
 if key: headers['Authorization']='Bearer '+manifest['keys'][key]
 if data is not None: headers['Content-Type']='application/json';data=json.dumps(data).encode()
 try:
  with opener.open(urllib.request.Request(base+path,data=data,headers=headers),timeout=20) as r:return r.status,r.read()
 except urllib.error.HTTPError as e:return e.code,e.read()
def rpc(name,args=None,key='full'):
 status,raw=request('/mcp/rpc',{'jsonrpc':'2.0','id':1,'method':'tools/call','params':{'name':name,'arguments':args or {}}},key)
 data=json.loads(raw);assert status==200 and 'error' not in data and not data['result']['isError'],(name,status,data)
 return json.loads(data['result']['content'][0]['text'])['data']
def passed(label):checks.append(label);print('PASS '+label,flush=True)
if '--complete' in __import__('sys').argv:
 result_path=storage/('phase-nine-http-'+profile+'-results.json')
 result=json.loads(result_path.read_text())
 final=rpc('submissions_get',{'form':'contact','id':result['submission_id']})
 assert final['notification_status']=='sent' and final['notification_attempts']==1 and final['notified_at']
 assert len([n for n in final['notes'] if n['body']=='Phase nine interrupted response'])==1
 result['async_final']='sent';result['delivery_attempts']=1
 result['checks'].append('MCP polls completed asynchronous delivery after real queue worker; one delivery attempt and one note')
 result_path.write_text(json.dumps(result,indent=2));print('PASS MCP completed asynchronous status')
 raise SystemExit(0)
# Real public form retains CSRF, signed honeypot and reCAPTCHA boundary from guarded router.
status,raw=request('/contacto',opener=browser);assert status==200
html=raw.decode();fields=dict(re.findall(r'<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"',html))
assert '_token' in fields and '_form_token' in fields
fields.update(name='Phase Nine Visitor',email='phase9-'+profile+'@visitor.test',message='Phase nine asynchronous state',recaptcha_token='phase9-google-boundary')
time.sleep(4.1)
form=urllib.request.Request(base+'/forms/contact',data=urllib.parse.urlencode(fields).encode(),headers={'Content-Type':'application/x-www-form-urlencoded'})
with browser.open(form) as r:assert r.status==200
rows=rpc('submissions_list',{'form':'contact','search':fields['email']})['submissions'];assert rows
identity={'form':'contact','id':rows[0]['id']}
current=rpc('submissions_get',identity);assert current['notification_status']=='queued' and current['notification_attempts']==0
passed('Real public form queues mail; MCP reads asynchronous queued status without changing it')
# Forward exactly once to actual server, drain success, then close client socket before a response.
# This controlled transport boundary affects no application/database behavior.
committed=[]
class LostResponse(http.server.BaseHTTPRequestHandler):
 def log_message(self,*args):pass
 def do_POST(self):
  raw=self.rfile.read(int(self.headers['Content-Length']))
  upstream=http.client.HTTPConnection('127.0.0.1',8018,timeout=20)
  upstream.request('POST','/mcp/rpc',raw,{'Authorization':self.headers['Authorization'],'Content-Type':'application/json'})
  response=upstream.getresponse();body=json.loads(response.read());assert response.status==200 and not body.get('error') and not body['result']['isError']
  committed.append(json.loads(body['result']['content'][0]['text'])['data']);upstream.close()
  self.connection.shutdown(socket.SHUT_RDWR);self.connection.close()
proxy=http.server.HTTPServer(('127.0.0.1',0),LostResponse);thread=threading.Thread(target=proxy.handle_request);thread.start()
args=identity|{'body':'Phase nine interrupted response','expected_revision':current['revision'],'operation_key':'phase9-'+profile+'-lost-note'}
body=json.dumps({'jsonrpc':'2.0','id':4,'method':'tools/call','params':{'name':'submissions_add_note','arguments':args}}).encode()
try:
 urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:'+str(proxy.server_port),data=body,headers={'Authorization':'Bearer '+manifest['keys']['full'],'Content-Type':'application/json'}),timeout=20)
 raise AssertionError('Expected interrupted response')
except (http.client.RemoteDisconnected,urllib.error.URLError,ConnectionResetError):pass
thread.join(20);proxy.server_close();assert len(committed)==1
retry=rpc('submissions_add_note',args);assert retry==committed[0]
after=rpc('submissions_get',identity);assert len([n for n in after['notes'] if n['body']==args['body']])==1
passed('Socket closed after committed note; exact stale revision/key retry returns original result and one note')
changed=args|{'body':'Different retry must fail'}
status,raw=request('/mcp/rpc',{'jsonrpc':'2.0','id':5,'method':'tools/call','params':{'name':'submissions_add_note','arguments':changed}},'full')
assert status==200 and json.loads(raw)['error']['code']==-32009
for key in ['read','draft']:
 before=rpc('submissions_get',identity)
 status,raw=request('/mcp/rpc',{'jsonrpc':'2.0','id':6,'method':'tools/call','params':{'name':'submissions_add_note','arguments':args|{'operation_key':'denied-'+key}}},key)
 assert status==403 and json.loads(raw)['error']['code']==-32603
 assert rpc('submissions_get',identity)==before
passed('Changed retry input conflicts; read and draft scopes reject without mutating')
(storage/('phase-nine-http-'+profile+'-results.json')).write_text(json.dumps({'profile':profile,'checks':checks,'interrupted_response':{'boundary':'TCP close after upstream success fully drained','committed_effect_count':1,'identical_receipt':True},'async_initial':'queued','submission_id':identity['id']},indent=2))
