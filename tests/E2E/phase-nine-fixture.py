"""Create a private disposable copy/schema/server; never boots the live installation.
Run from the product root. Writes only /tmp plus the guarded ruweb_phase0_* schema.
Cleanup stops the fixture process group, invokes guarded schema removal and destroys secrets.
"""
import base64,hashlib,json,os,secrets,shutil,signal,socket,subprocess,sys,tempfile
from pathlib import Path
STATE=Path('/tmp/ruweb-phase9-state.json')
source=Path.cwd().resolve();action=sys.argv[1] if len(sys.argv)>1 else 'init'
def private(path,value):path.write_text(value);path.chmod(0o600)
if action=='init':
 assert not STATE.exists(),'Existing phase-nine fixture requires guarded cleanup first'
 with socket.socket() as probe:assert probe.connect_ex(('127.0.0.1',8018))!=0,'Fixture port 8018 already in use'
 assert (source/'tests/E2E/phase-zero-fixture.php').is_file() and (source/'.env').is_file()
 run=Path(tempfile.mkdtemp(prefix='ruweb-phase9-'));run.chmod(0o700);copy=run/'app';storage=run/'storage';copy.mkdir();storage.mkdir()
 for part in ['app/public','framework/cache/data','framework/sessions','framework/views','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
 subprocess.run(['rsync','-a','--exclude=.git','--exclude=.env','--exclude=node_modules','--exclude=vendor','--exclude=storage','--exclude=public/hot','--exclude=bootstrap/cache/*.php','--exclude=docs/plans/*-evidence',str(source)+'/',str(copy)+'/'],check=True)
 (copy/'vendor').symlink_to(source/'vendor');(copy/'node_modules').symlink_to(source/'node_modules')
 entries={}
 for line in (source/'.env').read_text().splitlines():
  if '=' in line and not line.startswith('#'):
   key,value=line.split('=',1)
   if key in ['DB_HOST','DB_PORT','DB_USERNAME','DB_PASSWORD']:entries[key]=value
 entries.update(APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),APP_ENV='local',APP_DEBUG='false',APP_URL='http://127.0.0.1:8018',SESSION_DRIVER='file',CACHE_STORE='file',QUEUE_CONNECTION='database',MAIL_MAILER='log')
 private(copy/'.env','\n'.join(key+'='+value for key,value in entries.items())+'\n')
 database='ruweb_phase0_'+secrets.token_hex(6)
 state={'run':str(run),'copy':str(copy),'storage':str(storage),'database':database,'source':str(source),'original_env_sha256':hashlib.sha256((source/'.env').read_bytes()).hexdigest()};private(STATE,json.dumps(state))
 env=os.environ|{'PHASE0_DATABASE':database,'PHASE0_STORAGE':str(storage)}
 subprocess.run(['php','tests/E2E/phase-zero-fixture.php'],cwd=copy,env=env,check=True)
 subprocess.run(['npm','run','build'],cwd=copy,env=env,check=True)
 log=(run/'server-private.log').open('w')
 process=subprocess.Popen(['php','-S','127.0.0.1:8018','-t','public','tests/E2E/phase-zero-router.php'],cwd=copy,env=env|{'PHP_CLI_SERVER_WORKERS':'4'},stdout=log,stderr=log,start_new_session=True)
 state['pid']=process.pid;private(STATE,json.dumps(state));print('Private fixture ready; paths saved in guarded state file')
elif action=='cleanup':
 state=json.loads(STATE.read_text());run=Path(state['run']).resolve();copy=Path(state['copy']).resolve();storage=Path(state['storage']).resolve()
 assert run.parent in [Path('/tmp').resolve(),Path('/private/tmp')] and run.name.startswith('ruweb-phase9-') and copy.parent==run and storage.parent==run
 manifest=json.loads((storage/'manifest.json').read_text());assert manifest['database']==state['database']
 if state.get('pid'):
  try:
   assert os.getpgid(state['pid'])==state['pid'],'Unexpected process group'
   command=subprocess.run(['ps','-p',str(state['pid']),'-o','command='],capture_output=True,text=True,check=True).stdout
   assert 'php -S 127.0.0.1:8018 -t public tests/E2E/phase-zero-router.php' in command,'Unexpected fixture process'
   os.killpg(state['pid'],signal.SIGTERM)
  except ProcessLookupError:pass
 env=os.environ|{'PHASE0_DATABASE':state['database'],'PHASE0_STORAGE':str(storage)}
 subprocess.run(['php','tests/E2E/phase-zero-cleanup.php'],cwd=copy,env=env,check=True)
 if state.get('original_env_sha256'):assert hashlib.sha256((Path(state['source'])/'.env').read_bytes()).hexdigest()==state['original_env_sha256'],'Original .env changed during fixture'
 shutil.rmtree(run);STATE.unlink();print('PASS fixture process, schema, private credentials/configuration/logs removed')
else:raise AssertionError('Expected init or cleanup')
