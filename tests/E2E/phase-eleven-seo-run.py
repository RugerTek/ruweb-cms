"""Comparable 0.47/candidate public SEO laboratory; private isolated MySQL only."""
import base64,hashlib,io,json,os,re,secrets,shutil,signal,socket,subprocess,sys,tarfile,tempfile,time,urllib.request
from pathlib import Path
SOURCE=Path.cwd().resolve();STATE=Path('/tmp/ruweb-phase11-seo-state.json');OUT=SOURCE/'docs/plans/phase-11-evidence/seo-performance'
def private(p,v):p.write_text(v);p.chmod(0o600)
def run(cmd,app):
 p=subprocess.run(cmd,cwd=app['copy'],env=os.environ|app['env'],capture_output=True,text=True)
 if p.returncode:raise RuntimeError(str(cmd[:3])+': '+p.stdout[-1000:]+p.stderr[-1000:])
 return p.stdout
def control(app,action):return run(['php','tests/E2E/phase-ten-control.php',action],app)
def stop(app):
 if app.get('pid'):
  cmd=subprocess.check_output(['ps','-p',str(app['pid']),'-o','command='],text=True)
  assert 'php -S 127.0.0.1:'+str(app['port']) in cmd
  os.killpg(app['pid'],signal.SIGTERM);app.pop('pid');time.sleep(.2)
def start(app):
 with socket.socket() as s:assert s.connect_ex(('127.0.0.1',app['port']))!=0
 p=subprocess.Popen(['php','-S','127.0.0.1:'+str(app['port']),'-t','public','tests/E2E/phase-ten-router.php'],cwd=app['copy'],env=os.environ|app['env'],stdout=(Path(app['storage'])/'server-private.log').open('a'),stderr=subprocess.STDOUT,start_new_session=True);app['pid']=p.pid;private(STATE,json.dumps(state))
 for _ in range(50):
  try:urllib.request.urlopen('http://127.0.0.1:'+str(app['port'])+'/upgrade-baseline');return
  except Exception:time.sleep(.1)
 raise RuntimeError('Server did not start')
def copy_harness(app):
 for name in ['phase-ten-bootstrap.php','phase-ten-control.php','phase-ten-router.php']:
  p=Path(app['copy'])/'tests/E2E'/name;p.parent.mkdir(parents=True,exist_ok=True);s=(SOURCE/'tests/E2E'/name).read_text()
  if name=='phase-ten-bootstrap.php':s=s.replace("['8018', '8019']","['8030', '8031', '8032', '8033']").replace('/ruweb-phase10-','/ruweb-phase11-seo-')
  p.write_text(s)
def fingerprint(app):
 files={}
 for folder in ['app','config','routes','resources','database','public/build']:
  for p in sorted((Path(app['copy'])/folder).rglob('*')):
   if p.is_file():files[str(p.relative_to(app['copy']))]=hashlib.sha256(p.read_bytes()).hexdigest()
 return files
if len(sys.argv)>1 and sys.argv[1]=='cleanup':
 state=json.loads(STATE.read_text());assert state['source']==str(SOURCE)
 for app in state['apps'].values():stop(app);control(app,'cleanup')
 assert hashlib.sha256((SOURCE/'.env').read_bytes()).hexdigest()==state['env_sha256']
 shutil.rmtree(state['run']);STATE.unlink();OUT.mkdir(parents=True,exist_ok=True);(OUT/'cleanup.json').write_text(json.dumps({'schemas_removed':True,'processes_stopped':True,'private_artifacts_removed':True,'source_env_unchanged':True},indent=2)+'\n');print('PASS cleanup');sys.exit()
assert not STATE.exists(),'Cleanup previous fixture first'
assert not subprocess.check_output(['git','diff','f337e7d','--','composer.lock','package-lock.json'],text=True),'Dependency locks changed: install each lock independently'
(OUT/'cleanup.json').unlink(missing_ok=True) if OUT.exists() else None
OUT.mkdir(parents=True,exist_ok=True);root=Path(tempfile.mkdtemp(prefix='ruweb-phase11-seo-'));root.chmod(0o700)
state={'source':str(SOURCE),'run':str(root),'apps':{},'env_sha256':hashlib.sha256((SOURCE/'.env').read_bytes()).hexdigest(),'candidate_ref':subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip(),'baseline_ref':subprocess.check_output(['git','rev-parse','f337e7d'],text=True).strip()};private(STATE,json.dumps(state))
creds={}
for line in (SOURCE/'.env').read_text().splitlines():
 if '=' in line and not line.startswith('#'):
  k,v=line.split('=',1)
  if k in ['DB_HOST','DB_PORT','DB_USERNAME','DB_PASSWORD']:creds[k]=v
app={'copy':str(root/'site'),'storage':str(root/'storage'),'port':8030,'database':'ruweb_phase0_'+secrets.token_hex(6)};app['env']={'PHASE0_DATABASE':app['database'],'PHASE0_STORAGE':app['storage'],'PHASE10_PORT':str(app['port'])};state['apps']['site']=app;private(STATE,json.dumps(state))
Path(app['copy']).mkdir();Path(app['storage']).mkdir()
for part in ['app/public','framework/cache/data','framework/sessions','framework/views','logs']:(Path(app['storage'])/part).mkdir(parents=True,exist_ok=True)
tarfile.open(fileobj=io.BytesIO(subprocess.check_output(['git','archive',state['baseline_ref']]))).extractall(app['copy'])
for item in ['vendor','node_modules']:(Path(app['copy'])/item).symlink_to(SOURCE/item)
entries=creds|{'APP_KEY':'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),'DB_CONNECTION':'mysql','DB_DATABASE':app['database'],'APP_ENV':'local','APP_DEBUG':'false','APP_URL':'http://127.0.0.1:8030','SESSION_DRIVER':'file','CACHE_STORE':'file','QUEUE_CONNECTION':'database','MAIL_MAILER':'log','LOG_CHANNEL':'single','CMS_SNAPSHOTS_MAINTENANCE_MODE':'false'}
private(Path(app['copy'])/'.env','\n'.join(k+'='+v for k,v in entries.items())+'\n');copy_harness(app)
print(control(app,'install'));print(control(app,'baseline'));run(['npm','run','build'],app);control(app,'cache');baseline=json.loads(control(app,'manifest'))
reports=[]
for stage in ['baseline-047-full','candidate-full','candidate-essential']:
 if stage=='candidate-full':
  run(['rsync','-a','--exclude=.git','--exclude=.env','--exclude=node_modules','--exclude=vendor','--exclude=storage','--exclude=public/storage','--exclude=public/hot','--exclude=bootstrap/cache/*.php','--exclude=docs/plans/*-evidence',str(SOURCE)+'/',app['copy']+'/'],app);copy_harness(app);print(control(app,'migrate'));run(['npm','run','build'],app)
 if stage=='candidate-essential':
  p=Path(app['copy'])/'config/cms.php';s,n=re.subn(r"'admin_profile' => '(full|essential)'","'admin_profile' => 'essential'",p.read_text(),count=1);assert n==1;p.write_text(s)
 control(app,'cache');assert json.loads(control(app,'manifest'))==baseline,'Portable fixture data changed';start(app);state['stage']=stage;private(STATE,json.dumps(state));print(subprocess.check_output(['node',str(SOURCE/'tests/E2E/phase-eleven-seo-browser.cjs')],text=True));stop(app);private(STATE,json.dumps(state));(OUT/(stage+'-fingerprints.json')).write_text(json.dumps(fingerprint(app),indent=2)+'\n');reports.append({'stage':stage,'portable_manifest_identical':True})
(OUT/'context.json').write_text(json.dumps({'baseline_ref':state['baseline_ref'],'candidate_ref':state['candidate_ref'],'php':subprocess.check_output(['php','-r','echo PHP_VERSION;'],text=True),'node':subprocess.check_output(['node','--version'],text=True).strip(),'profiles':reports,'build_mode':'npm run build; no Vite dev server','server':'PHP CLI loopback 8030; same MySQL schema; sequential','timing':'7 warmed navigations in fresh contexts; cold browser cache; no throttling; no external traffic','baseline_content':'phase-ten-control baseline bilingual published ES/EN plus draft/future; same installed media bytes','dependencies':'shared source vendor/node_modules; locked dependency files unchanged from baseline'},indent=2)+'\n');subprocess.run(['python3',str(SOURCE/'tests/E2E/phase-eleven-seo-compare.py')],cwd=SOURCE,check=True);print('PASS comparable stages; cleanup required')
