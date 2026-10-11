"""Guarded clean-install E2E11 fixture; only temporary copies and new MySQL schemas."""
import base64,hashlib,json,os,secrets,shutil,signal,socket,subprocess,sys,tempfile,time,urllib.request
from pathlib import Path
STATE=Path('/tmp/ruweb-phase11-state.json');source=Path.cwd().resolve();action=sys.argv[1] if len(sys.argv)>1 else 'init'
def private(p,v):p.write_text(v);p.chmod(0o600)
def run(app,args):
 p=subprocess.run(args,cwd=app['copy'],env=os.environ|app['env'],capture_output=True,text=True)
 if p.returncode:raise RuntimeError(str(args[:3])+': '+p.stdout[-900:]+p.stderr[-900:])
 return p.stdout
def ctl(app,action,*args):return run(app,['php','tests/E2E/phase-ten-control.php',action,*args])
if action=='init':
 assert not STATE.exists(),'Cleanup previous fixture first'
 for port in [8022,8023]:
  with socket.socket()as s:assert s.connect_ex(('127.0.0.1',port))!=0,'Occupied fixture port'
 root=Path(tempfile.mkdtemp(prefix='ruweb-phase11-'));root.chmod(0o700)
 state={'run':str(root),'source':str(source),'source_ref':subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip(),'original_env_sha256':hashlib.sha256((source/'.env').read_bytes()).hexdigest(),'apps':{}};private(STATE,json.dumps(state))
 creds={}
 for line in (source/'.env').read_text().splitlines():
  if '='in line:
   k,v=line.split('=',1)
   if k in ['DB_HOST','DB_PORT','DB_USERNAME','DB_PASSWORD']:creds[k]=v
 for profile,port in [('full',8022),('essential',8023)]:
  copy=root/profile;storage=root/(profile+'-storage');copy.mkdir();storage.mkdir()
  for part in ['app/public','framework/cache/data','framework/sessions','framework/views','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
  subprocess.run(['rsync','-a','--exclude=.git','--exclude=.env','--exclude=node_modules','--exclude=vendor','--exclude=storage','--exclude=public/hot','--exclude=bootstrap/cache/*.php','--exclude=docs/plans/*-evidence',str(source)+'/',str(copy)+'/'],check=True)
  for n in ['vendor','node_modules']:(copy/n).symlink_to(source/n)
  (copy/'public/storage').unlink(missing_ok=True)
  p=copy/'config/cms.php';p.write_text(p.read_text().replace("'admin_profile' => 'full'","'admin_profile' => '"+profile+"'",1))
  p=copy/'tests/E2E/phase-ten-bootstrap.php';p.write_text(p.read_text().replace("['8018', '8019']","['8022', '8023']").replace('/ruweb-phase10-','/ruweb-phase11-'))
  db='ruweb_phase0_'+secrets.token_hex(6);app={'copy':str(copy),'storage':str(storage),'database':db,'port':port,'profile':profile,'env':{'PHASE0_DATABASE':db,'PHASE0_STORAGE':str(storage),'PHASE10_PORT':str(port)}};state['apps'][profile]=app;private(STATE,json.dumps(state))
  entries=creds|{'APP_KEY':'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),'DB_CONNECTION':'mysql','DB_DATABASE':db,'APP_ENV':'local','APP_DEBUG':'false','APP_URL':'http://127.0.0.1:'+str(port),'SESSION_DRIVER':'file','CACHE_STORE':'file','QUEUE_CONNECTION':'database','MAIL_MAILER':'log','LOG_CHANNEL':'single','CMS_SNAPSHOTS_MAINTENANCE_MODE':'false'}
  private(copy/'.env','\n'.join(k+'='+v for k,v in entries.items())+'\n');print(ctl(app,'install'));print(ctl(app,'baseline'));print(run(app,['php','tests/E2E/phase-eleven-control.php','grant']));print(ctl(app,'cache'))
  log=(storage/'server-private.log').open('w');proc=subprocess.Popen(['php','-S','127.0.0.1:'+str(port),'-t','public','tests/E2E/phase-ten-router.php'],cwd=copy,env=os.environ|app['env'],stdout=log,stderr=log,start_new_session=True);app['pid']=proc.pid;private(STATE,json.dumps(state))
  for attempt in range(50):
   try:
    with urllib.request.urlopen('http://127.0.0.1:'+str(port)+'/login')as r:assert r.status==200;break
   except Exception:time.sleep(.1)
  else:raise RuntimeError('Fixture server failed')
 print('PASS clean candidate install full and essential; private state saved')
else:
 state=json.loads(STATE.read_text());assert Path(state['source'])==source and Path(state['run']).name.startswith('ruweb-phase11-')
 if action=='cleanup':
  for app in state['apps'].values():
   try:
    cmd=subprocess.check_output(['ps','-p',str(app['pid']),'-o','command='],text=True);assert 'php -S 127.0.0.1:'+str(app['port']) in cmd;os.killpg(app['pid'],signal.SIGTERM)
   except (ProcessLookupError,subprocess.CalledProcessError):pass
   print(ctl(app,'cleanup'))
  assert hashlib.sha256((source/'.env').read_bytes()).hexdigest()==state['original_env_sha256'];shutil.rmtree(state['run']);STATE.unlink();print('PASS E2E11 cleanup; original environment unchanged')
 elif action in ['grant','revoke-code-grant','clear-submission','recover','snapshot-roundtrip','mail']:
  app=state['apps'][sys.argv[2]];shutil.copy2(source/'tests/E2E/phase-eleven-control.php',Path(app['copy'])/'tests/E2E/phase-eleven-control.php');print(run(app,['php','tests/E2E/phase-eleven-control.php',action,*sys.argv[3:]]))
 elif action in ['worker','snapshot','manifest','inspect','restore']:
  app=state['apps'][sys.argv[2]];print(ctl(app,action,*sys.argv[3:]))
 else:raise RuntimeError('Unknown action')
