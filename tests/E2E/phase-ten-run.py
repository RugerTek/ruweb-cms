"""Actual 0.47.0 artifact -> candidate -> old artifact recovery, isolated MySQL only.
Private state/dumps/backups stay in guarded /tmp. No .env/DB/server of the source is booted.
Actions: init, upgrade, transitions, import-current, restore-old, rollback, cleanup.
"""
import base64,hashlib,io,json,os,re,secrets,shutil,signal,socket,subprocess,sys,tarfile,tempfile,time,urllib.request,urllib.error,zipfile
from pathlib import Path
STATE=Path('/tmp/ruweb-phase10-state.json');source=Path.cwd().resolve();action=sys.argv[1] if len(sys.argv)>1 else 'init'
def private(path,value):path.write_text(value);path.chmod(0o600)
def run(cmd,app,env=None):
 r=subprocess.run(cmd,cwd=app['copy'],env=os.environ|app['env']|(env or {}),capture_output=True,text=True)
 if r.returncode:raise RuntimeError('Failed '+str(cmd[:3])+': '+r.stdout[-1200:]+r.stderr[-1200:])
 return r.stdout
def control(app,action,*args):return run(['php','tests/E2E/phase-ten-control.php',action,*args],app)
def stop(app):
 if app.get('pid'):
  try:
   cmd=subprocess.run(['ps','-p',str(app['pid']),'-o','command='],capture_output=True,text=True).stdout
   assert 'php -S 127.0.0.1:'+str(app['port']) in cmd,'Unexpected process'
   os.killpg(app['pid'],signal.SIGTERM)
  except ProcessLookupError:pass
  app.pop('pid',None);private(STATE,json.dumps(state));time.sleep(.2)
def start(app):
 with socket.socket() as probe:assert probe.connect_ex(('127.0.0.1',app['port']))!=0,'Occupied fixture port'
 log=(Path(app['storage'])/'server-private.log').open('a');p=subprocess.Popen(['php','-S','127.0.0.1:'+str(app['port']),'-t','public','tests/E2E/phase-ten-router.php'],cwd=app['copy'],env=os.environ|app['env'],stdout=log,stderr=log,start_new_session=True);app['pid']=p.pid;private(STATE,json.dumps(state))
 for i in range(40):
  try:
   with urllib.request.urlopen('http://127.0.0.1:'+str(app['port'])+'/login') as r:assert r.status==200;return
  except (urllib.error.URLError,AssertionError):time.sleep(.1)
 raise RuntimeError('Fixture server failed to start')
def copy_candidate(app):
 run(['rsync','-a','--exclude=.git','--exclude=.env','--exclude=node_modules','--exclude=vendor','--exclude=storage','--exclude=public/hot','--exclude=bootstrap/cache/*.php','--exclude=docs/plans/*-evidence',str(source)+'/',app['copy']+'/'],app)
 for name in ['phase-ten-bootstrap.php','phase-ten-control.php','phase-ten-router.php']:shutil.copy2(source/'tests/E2E'/name,Path(app['copy'])/'tests/E2E'/name)
def profile(app,name):
 p=Path(app['copy'])/'config/cms.php';s=p.read_text();s,n=re.subn(r"'admin_profile' => '(full|essential)'","'admin_profile' => '"+name+"'",s,count=1);assert n==1;p.write_text(s)
def record(state,name,data):
 out=source/'docs/plans/phase-10-evidence';out.mkdir(exist_ok=True);(out/(name+'.json')).write_text(json.dumps(data,indent=2,ensure_ascii=False)+'\n');state['checks'].append(name);private(STATE,json.dumps(state))
def manifest(app):return json.loads(control(app,'manifest'))
def sql_backup(app,state):
 env={}
 for line in (Path(app['copy'])/'.env').read_text().splitlines():
  if '=' in line:k,v=line.split('=',1);env[k]=v.strip('"\'')
 conf=Path(state['run'])/'mysql-private.cnf';private(conf,'[client]\nhost='+env['DB_HOST']+'\nport='+env['DB_PORT']+'\nuser='+env['DB_USERNAME']+'\npassword='+env['DB_PASSWORD']+'\n')
 dump=Path(state['run'])/'rollback-private.sql'
 with dump.open('w') as output:subprocess.run(['mysqldump','--defaults-extra-file='+str(conf),'--single-transaction','--set-gtid-purged=OFF','--no-tablespaces',app['database']],stdout=output,stderr=subprocess.PIPE,check=True)
 dump.chmod(0o600);state['dump_sha256']=hashlib.sha256(dump.read_bytes()).hexdigest()
if action=='init':
 assert not STATE.exists(),'Existing phase10 fixture requires cleanup'
 for port in [8018,8019]:
  with socket.socket() as probe:assert probe.connect_ex(('127.0.0.1',port))!=0,'Fixture port occupied'
 assert subprocess.check_output(['git','show','f337e7d:VERSION'],text=True).strip()=='0.47.0'
 root=Path(tempfile.mkdtemp(prefix='ruweb-phase10-'));root.chmod(0o700);state={'run':str(root),'source':str(source),'old_ref':subprocess.check_output(['git','rev-parse','f337e7d'],text=True).strip(),'candidate_ref':subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip(),'original_env_sha256':hashlib.sha256((source/'.env').read_bytes()).hexdigest(),'apps':{},'checks':[]}
 private(STATE,json.dumps(state))
 creds={}
 for line in (source/'.env').read_text().splitlines():
  if '=' in line and not line.startswith('#'):
   k,v=line.split('=',1)
   if k in ['DB_HOST','DB_PORT','DB_USERNAME','DB_PASSWORD']:creds[k]=v
 for name,port in [('existing',8018),('fresh',8019)]:
  copy=root/name;storage=root/(name+'-storage');copy.mkdir();storage.mkdir()
  for part in ['app/public','framework/cache/data','framework/sessions','framework/views','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
  app={'copy':str(copy),'storage':str(storage),'port':port,'database':'ruweb_phase0_'+secrets.token_hex(6)};app['env']={'PHASE0_DATABASE':app['database'],'PHASE0_STORAGE':str(storage),'PHASE10_PORT':str(port)};state['apps'][name]=app;private(STATE,json.dumps(state))
  if name=='existing':
   data=subprocess.check_output(['git','archive',state['old_ref']]);tarfile.open(fileobj=io.BytesIO(data)).extractall(copy);(copy/'tests/E2E').mkdir(parents=True,exist_ok=True)
  else:copy_candidate(app)
  for item in ['vendor','node_modules']:(copy/item).symlink_to(source/item)
  for item in ['phase-ten-bootstrap.php','phase-ten-control.php','phase-ten-router.php']:shutil.copy2(source/'tests/E2E'/item,copy/'tests/E2E'/item)
  entries=creds|{'APP_KEY':'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),'DB_CONNECTION':'mysql','DB_DATABASE':app['database'],'APP_ENV':'local','APP_DEBUG':'false','APP_URL':'http://127.0.0.1:'+str(port),'SESSION_DRIVER':'file','CACHE_STORE':'file','QUEUE_CONNECTION':'database','MAIL_MAILER':'log','LOG_CHANNEL':'single','CMS_SNAPSHOTS_MAINTENANCE_MODE':'false'}
  private(copy/'.env','\n'.join(k+'='+v for k,v in entries.items())+'\n');print(control(app,'install'),flush=True);print(run(['npm','run','build'],app)[-100:],flush=True)
 old=state['apps']['existing'];print(control(old,'baseline'));baseline=manifest(old);record(state,'baseline-manifest',baseline)
 archive=control(old,'snapshot','old-047').strip();state['old_archive']=archive;sql_backup(old,state)
 shutil.copytree(Path(old['storage'])/'app/public',root/'rollback-uploads');print(control(old,'keys'));start(old);start(state['apps']['fresh']);private(STATE,json.dumps(state))
 record(state,'initial-artifacts',{'old_ref':state['old_ref'],'candidate_ref':state['candidate_ref'],'old_snapshot_sha256':hashlib.sha256(Path(archive).read_bytes()).hexdigest(),'rollback_db_sha256':state['dump_sha256'],'original_env_unchanged':True,'fresh_install_real':True});print('PASS old artifact and clean candidate installation started')
else:
 state=json.loads(STATE.read_text());assert Path(state['source'])==source;assert re.fullmatch(r'ruweb-phase10-[\w-]+',Path(state['run']).name);old=state['apps']['existing'];fresh=state['apps']['fresh']
 if action=='upgrade':
  stop(old);copy_candidate(old);before=manifest(old);print(control(old,'migrate'));after=manifest(old);assert before==after,'Migration changed portable baseline';print(control(old,'migrate'));assert manifest(old)==after
  run(['npm','run','build'],old);print(control(old,'cache'));print(control(old,'keys'));start(old);record(state,'upgrade-manifest',after);print('PASS actual existing database migrated twice, baseline identical')
 elif action=='refresh':
  for app in [old,fresh]:
   stop(app);current_profile='essential' if "'admin_profile' => 'essential'" in (Path(app['copy'])/'config/cms.php').read_text() else 'full';copy_candidate(app);profile(app,current_profile);print(control(app,'cache'));run(['npm','run','build'],app);start(app)
  private(STATE,json.dumps(state));print('PASS fixtures refreshed to current product changes')
 elif action in ['pilot-essential','pilot-full']:
  stop(old);profile(old,action.split('-')[1]);print(control(old,'cache'));start(old);private(STATE,json.dumps(state));print('PASS isolated profile selected')
 elif action=='transitions':
  before=manifest(old);reports=[]
  for target in ['full','essential','full']:
   stop(old);profile(old,target);print(control(old,'cache'));print(control(old,'worker'));start(old);assert manifest(old)==before
   key=json.loads((Path(old['storage'])/'keys.json').read_text())['full'];r=urllib.request.Request('http://127.0.0.1:8018/mcp/capabilities',headers={'Authorization':'Bearer '+key})
   with urllib.request.urlopen(r)as response:data=json.load(response)['data'];assert data['admin_profile']==target
   reports.append({'profile':target,'config_cached':(Path(old['storage'])/'config.php').exists(),'routes_cached':(Path(old['storage'])/'routes.php').exists(),'manifest_identical':True,'server_restarted':True,'queue_restart_and_worker':True})
  record(state,'profile-transitions',reports);print('PASS cached full-essential-full transitions')
 elif action=='import-current':
  print(control(old,'lifecycle'));current=control(old,'snapshot','current-0541').strip();state['current_archive']=current
  profile(fresh,'essential');print(control(fresh,'cache'));stop(fresh);start(fresh)
  report=json.loads(control(fresh,'inspect',current));assert not [r for r in report['risks']if r['severity']=='blocking'];accepted=[r['key']for r in report['risks']if r['severity']=='warning'];restore_result=json.loads(control(fresh,'restore',current,json.dumps(accepted)));print(control(fresh,'verify-import'));assert manifest(old)==manifest(fresh),'Current restore portable data mismatch'
  source_history=json.loads(control(old,'portable-history'));destination_history=json.loads(control(fresh,'portable-history'));assert source_history==destination_history,'Portable history mismatch'
  archive=zipfile.ZipFile(current);names=json.loads(archive.read('manifest.json'))['database']['tables'];excluded=['mcp_operation_receipts','editorial_operation_receipts','submission_operation_receipts','custom_code_receipts'];assert not set(excluded)&{t['name']for t in names}
  record(state,'current-import',{'report':report,'accepted_warning_keys':accepted,'same_portable_manifest':True,'operational_authority_not_restored':True,'destination_profile':'essential','portable_history':source_history,'snapshot_sha256':hashlib.sha256(Path(current).read_bytes()).hexdigest()});print(control(fresh,'keys'));start_if=False
  stop(fresh);print(control(fresh,'cache'));start(fresh);private(STATE,json.dumps(state));print('PASS new snapshot import in distinct compatible essential installation')
 elif action=='restore-old':
  before=manifest(fresh);report=json.loads(control(fresh,'inspect',state['old_archive']));assert not [r for r in report['risks']if r['severity']=='blocking'];accepted=[r['key']for r in report['risks']if r['severity']=='warning'];assert 'older_snapshot'in accepted
  restore_result=json.loads(control(fresh,'restore',state['old_archive'],json.dumps(accepted)));baseline=json.loads((source/'docs/plans/phase-10-evidence/baseline-manifest.json').read_text());assert manifest(fresh)==baseline
  bad=Path(state['run'])/'unsupported.appbackup'
  with zipfile.ZipFile(state['old_archive'])as z:files={n:z.read(n)for n in z.namelist()}
  m=json.loads(files['manifest.json']);m['version']=999;files['manifest.json']=json.dumps(m).encode();files['checksums.json']=json.dumps({n:hashlib.sha256(v).hexdigest()for n,v in files.items()if n!='checksums.json'}).encode()
  with zipfile.ZipFile(bad,'w')as z:
   for n,v in files.items():z.writestr(n,v)
  result=subprocess.run(['php','tests/E2E/phase-ten-control.php','restore',str(bad),'[]'],cwd=fresh['copy'],env=os.environ|fresh['env'],capture_output=True,text=True);assert result.returncode!=0 and 'Unsupported snapshot format version'in result.stderr+result.stdout;assert manifest(fresh)==baseline
  record(state,'old-import-and-rejection',{'report':report,'accepted_warning_keys':accepted,'baseline_identical':True,'incompatible_format':999,'diagnostic':'Unsupported snapshot format version.','no_mutation':True});print('PASS old archive risks explicitly accepted and incompatible archive rejected before mutation')
 elif action=='rollback':
  stop(old);previous=Path(old['copy']);backup=Path(state['run'])/'candidate-discarded';shutil.rmtree(backup) if backup.exists() else None;previous.rename(backup);previous.mkdir();data=subprocess.check_output(['git','archive',state['old_ref']]);tarfile.open(fileobj=io.BytesIO(data)).extractall(previous);(previous/'tests/E2E').mkdir(parents=True,exist_ok=True)
  for item in ['vendor','node_modules']:(previous/item).symlink_to(source/item)
  shutil.copy2(backup/'.env',previous/'.env')
  for item in ['phase-ten-bootstrap.php','phase-ten-control.php','phase-ten-router.php']:shutil.copy2(source/'tests/E2E'/item,previous/'tests/E2E'/item)
  for f in ['config.php','routes.php']:(Path(old['storage'])/f).unlink(missing_ok=True)
  print(control(old,'cleanup'))
  config=Path(state['run'])/'mysql-private.cnf';create='CREATE DATABASE `'+old['database']+'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
  subprocess.run(['mysql','--defaults-extra-file='+str(config)],input=create,text=True,capture_output=True,check=True)
  with (Path(state['run'])/'rollback-private.sql').open()as dump:subprocess.run(['mysql','--defaults-extra-file='+str(config),old['database']],stdin=dump,stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=True)
  shutil.rmtree(Path(old['storage'])/'app/public');shutil.copytree(Path(state['run'])/'rollback-uploads',Path(old['storage'])/'app/public');run(['npm','run','build'],old);assert manifest(old)==json.loads((source/'docs/plans/phase-10-evidence/baseline-manifest.json').read_text());assert (previous/'VERSION').read_text().strip()=='0.47.0';start(old)
  record(state,'rollback',{'code_ref':state['old_ref'],'version':'0.47.0','baseline_identical':True,'db_dump_sha256':state['dump_sha256'],'inverse_migrations_used':False,'artifacts':'exact git artifact + matching native SQL backup + public disk backup'});print('PASS REAL old code/database/files rollback')
 elif action=='cleanup':
  for app in state['apps'].values():stop(app);print(control(app,'cleanup'))
  assert hashlib.sha256((source/'.env').read_bytes()).hexdigest()==state['original_env_sha256'];shutil.rmtree(state['run']);STATE.unlink();print('PASS fixture servers, schemas, snapshots, SQL backups, keys, private logs removed; original .env unchanged')
 else:raise RuntimeError('Unknown phase-ten action')
 private(STATE,json.dumps(state)) if action!='cleanup' else None
