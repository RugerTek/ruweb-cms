"""Accumulated exact-candidate regressions in disposable PHP 8.3/MySQL copies.
No source application boot; each phase/profile gets a never-used guarded schema.
PHP83_BIN must name a private wrapper/runtime directory containing php.
"""
import base64,hashlib,json,os,re,secrets,shutil,signal,socket,subprocess,tempfile,time,urllib.request
from pathlib import Path
source=Path.cwd().resolve();out=source/'docs/plans/phase-11-evidence/runtime-accumulated';out.mkdir(parents=True,exist_ok=True)
assert os.environ.get('PHP83_BIN');assert os.environ.get('PHP83_VENDOR') and Path(os.environ['PHP83_VENDOR']).is_dir();assert Path(os.environ['PHP83_VENDOR']).resolve()!=(source/'vendor').resolve();envbase=os.environ|{'PATH':os.environ['PHP83_BIN']+':'+os.environ['PATH'],'PLAYWRIGHT_PACKAGE':os.environ.get('PLAYWRIGHT_PACKAGE','/Users/alemanydev/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright')}
php_version=subprocess.check_output(['php','-r','echo PHP_VERSION;'],env=envbase,text=True);assert php_version.startswith('8.3.')
creds={}
for line in (source/'.env').read_text().splitlines():
 if '=' in line:
  k,v=line.split('=',1)
  if k in ['DB_HOST','DB_PORT','DB_USERNAME','DB_PASSWORD']:creds[k]=v
original_env=hashlib.sha256((source/'.env').read_bytes()).hexdigest();results=json.loads((out/'accumulated-summary.json').read_text())if(out/'accumulated-summary.json').exists()else[]
phases=[int(v) for v in os.environ.get('PHASE11_PHASES','0,2,3,4,5,6,7,8,9').split(',')]
profiles=os.environ.get('PHASE11_PROFILES','full,essential').split(',');assert set(profiles)<=set(['full','essential']);assert set(phases)<=set([0,2,3,4,5,6,7,8,9])
for phase in phases:
 for profile in profiles:
  if phase == 4 and profile != 'essential': continue
  root=Path(tempfile.mkdtemp(prefix='ruweb-phase11-accum-'));root.chmod(0o700);copy=root/'app';storage=root/'storage';copy.mkdir();storage.mkdir()
  for part in ['app/public','framework/cache/data','framework/sessions','framework/views','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
  subprocess.run(['rsync','-a','--exclude=.git','--exclude=.env*','--exclude=vendor','--exclude=node_modules','--exclude=storage','--exclude=public/storage','--exclude=public/hot','--exclude=bootstrap/cache/*.php','--exclude=docs/plans/*-evidence',str(source)+'/',str(copy)+'/'],check=True)
  shutil.copytree(Path(os.environ['PHP83_VENDOR']),copy/'vendor');(copy/'node_modules').symlink_to(source/'node_modules')
  p=copy/'config/cms.php';p.write_text(re.sub(r"'admin_profile' => '(full|essential)'","'admin_profile' => '"+profile+"'",p.read_text(),count=1))
  database='ruweb_phase0_'+secrets.token_hex(6);env=envbase|{'PHASE0_DATABASE':database,'PHASE0_STORAGE':str(storage),'PHP_CLI_SERVER_WORKERS':'4',f'PHASE{phase}_PROFILE':profile}
  entries=creds|{'APP_KEY':'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),'APP_ENV':'local','APP_DEBUG':'false','DB_CONNECTION':'mysql','DB_DATABASE':database,'APP_URL':'http://127.0.0.1:8018','SESSION_DRIVER':'file','CACHE_STORE':'file','QUEUE_CONNECTION':'database','MAIL_MAILER':'log'}
  shutil.copy2(source/'.env.example',copy/'.env.example')
  if phase==2:
   fixture=copy/'tests/Fixtures/admin-profiles'
   config=copy/'config/cms.php';text=config.read_text().replace('return [', '$phase2FixtureConfig = [',1);text += "\n$phase2FixtureConfig['templates']['default'] = require dirname(__DIR__).'/tests/Fixtures/admin-profiles/template.php';\nreturn $phase2FixtureConfig;\n";config.write_text(text)
   shutil.copy2(fixture/profile/'views/templates/profile-fixture.blade.php',copy/'resources/views/templates/default.blade.php')
   if profile=='full':
    shutil.copy2(fixture/'full/views/admin/pages/sections/profile-fixture_testimonials.blade.php',copy/'resources/views/admin/pages/sections/default_testimonials.blade.php');shutil.copy2(fixture/'full/views/admin/pages/sections/_testimonial-item.blade.php',copy/'resources/views/admin/pages/sections/_testimonial-item.blade.php')
   for locale in ['es','en']:
    lang=copy/f'lang/{locale}/admin.php';text=lang.read_text().replace('return [','$phase2FixtureLabels = [',1);text += "\nreturn $phase2FixtureLabels + ['section_testimonials'=>'Testimonios','testimonial_item'=>'Testimonio :position','testimonial_empty'=>'Sin testimonios','testimonial_add'=>'Añadir testimonio','testimonial_remove'=>'Eliminar testimonio','testimonial_author'=>'Autor','testimonial_role'=>'Cargo','testimonial_quote'=>'Testimonio','testimonial_portrait'=>'Retrato','testimonial_confirm_remove'=>'Se eliminará este testimonio.'];\n";lang.write_text(text)
  p=copy/'.env';p.write_text('\n'.join(k+'='+v for k,v in entries.items())+'\n');p.chmod(0o600)
  hashes={str(p.relative_to(copy)):hashlib.sha256(p.read_bytes()).hexdigest()for d in ['app','config','resources','routes','database','lang','public/build']for p in sorted((copy/d).rglob('*'))if p.is_file()}
  differences=[name for name,digest in hashes.items()if(source/name).exists()and hashlib.sha256((source/name).read_bytes()).hexdigest()!=digest]
  report={'phase':phase,'profile':profile,'runtime':'PHP '+php_version,'fixture_product_sha256':hashes,'differences_from_source':differences,'stages':[]};server=None
  def run(cmd,extra=None):
   r=subprocess.run(cmd,cwd=copy,env=env|(extra or {}),capture_output=True,text=True,timeout=300)
   text=r.stdout+r.stderr
   for value in creds.values():
    if value and len(value)>3:text=text.replace(value,'[redacted]')
   text=re.sub(r'ruweb_mcp_[A-Za-z0-9_-]+','[redacted]',text);text=re.sub(r'https?://[^\s"<>]+/preview/[^\s"<>]+','[redacted preview]',text)
   report['stages'].append({'command':cmd,'exit_code':r.returncode,'environment_overrides':{k:v for k,v in (extra or {}).items()if re.fullmatch(r'PHASE[0-9]+_(STAGE|PROFILE)',k)},'output':text[-6000:]})
   (out/f'phase-{phase}-{profile}-execution.json').write_text(json.dumps(report,indent=2)+'\n')
   if r.returncode:raise RuntimeError(str(cmd)+' failed: '+text[-1200:])
  def php(name,*args):run(['php','tests/E2E/'+name+'.php',*args])
  def http(name,*args):run(['python3','tests/E2E/'+name+'-http.py',*args])
  def browser(name,stage=None):run(['node','tests/E2E/'+name+'-browser.cjs'],{f'PHASE{phase}_STAGE':stage}if stage else None)
  try:
   with socket.socket()as s:assert s.connect_ex(('127.0.0.1',8018))!=0,'8018 occupied'
   php('phase-four-install' if phase==4 else 'phase-zero-fixture')
   router='phase-three-router.php'if phase in [3,5,7]else'phase-zero-router.php'
   if phase in [5,7]:php('phase-five-control','identity')
   if phase==6:php('phase-six-control','identity')
   if phase==8:php('phase-eight-control','identity')
   log=(root/'server-private.log').open('w');server=subprocess.Popen(['php','-S','127.0.0.1:8018','-t','public','tests/E2E/'+router],cwd=copy,env=env,stdout=log,stderr=log,start_new_session=True)
   time.sleep(.5)
   if phase==0:
    http('phase-zero');php('phase-zero-worker');http('phase-one')
   if phase==2:
    report['fixture_kind']='Deterministic historical E2E02 delivery, no new agent authoring claimed'
    http('phase-two');php('phase-two-control','unlock');browser('phase-two');os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10);server=None;run(['python3','tests/E2E/phase-two-transitions.py'],{'PHP_CLI_SERVER_WORKERS':'1'})
   if phase==4:
    browser('phase-four','identity');run(['python3','tests/E2E/phase-four-client.py'],{'PHASE4_CODEX_CLIENT':os.environ.get('PHASE4_CODEX_CLIENT','/Applications/ChatGPT.app/Contents/Resources/codex-cli/CodexCLI.app/Contents/MacOS/codex')});browser('phase-four','essential');browser('phase-four','essential-bar');os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10);server=None
    p=copy/'config/cms.php';p.write_text(p.read_text().replace("'admin_profile' => 'essential'","'admin_profile' => 'full'"));php('phase-two-control','cache');php('phase-two-control','unlock');server=subprocess.Popen(['php','-S','127.0.0.1:8018','-t','public','tests/E2E/phase-zero-router.php'],cwd=copy,env=env,stdout=log,stderr=log,start_new_session=True);time.sleep(.5);browser('phase-four','full')
   if phase==3:
    http('phase-three');browser('phase-three','draft');http('phase-three','--publish');browser('phase-three','public')
   if phase==5:
    http('phase-five');browser('phase-five','proposal');http('phase-five','--complete');browser('phase-five','restored')
   if phase==6:
    browser('phase-six','submit');http('phase-six');browser('phase-six','tracked');php('phase-six-control','fail-worker');browser('phase-six','failed');http('phase-six','--recover');php('phase-six-control','deliver-worker');http('phase-six','--complete');browser('phase-six','sent');php('phase-six-control','deliver-worker')
   if phase==7:
    http('phase-seven');browser('phase-seven');http('phase-seven','--mutate');browser('phase-seven');http('phase-seven','--restore');browser('phase-seven')
    if profile=='essential':
     os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10);server=None;p=copy/'config/cms.php';p.write_text(p.read_text().replace("'admin_profile' => 'essential'","'admin_profile' => 'full'"));php('phase-two-control','cache');php('phase-two-control','unlock');server=subprocess.Popen(['php','-S','127.0.0.1:8018','-t','public','tests/E2E/phase-three-router.php'],cwd=copy,env=env,stdout=log,stderr=log,start_new_session=True);time.sleep(.5);browser('phase-seven','full-ui')
   if phase==8:
    http('phase-eight','initial');browser('phase-eight','create')
    for stage in ['global','exact','pending','deactivated','reactivated']:http('phase-eight',stage);browser('phase-eight')
    php('phase-eight-control','disable');browser('phase-eight','killed');php('phase-eight-control','enable');browser('phase-eight','recovered');http('phase-eight','harmful');browser('phase-eight');php('phase-eight-control','disable');browser('phase-eight','harmful-killed');run(['node','tests/E2E/phase-eight-security.cjs'])
   if phase==9:
    state=Path('/tmp/ruweb-phase9-state.json');assert not state.exists(),'Existing phase9 state'
    state.write_text(json.dumps({'copy':str(copy),'storage':str(storage),'database':database}));state.chmod(0o600)
    if profile=='full':
     plan={'profile':profile,'cases':[{'name':'accumulated-draft','key':'full','required_tools':['cms_capabilities','cms_schema','pages_create_draft','pages_get','pages_update_draft'],'instructions':'Discover capabilities and default schema. Create one Spanish default DRAFT titled Phase Nine Codex initial, slug phase-nine-codex. Set sections.content.heading to Phase Nine Codex initial, body <p>Phase Nine initial body</p>, is_visible true. Read it and update with expected_revision to title and heading Phase Nine Codex updated, body <p>Phase Nine updated body</p>. Read updated page. Return JSON id,title,updated_heading,admin_profile. No publish.','expected_final':{'id':109,'title':'Phase Nine Codex updated','updated_heading':'Phase Nine Codex updated','admin_profile':profile}}]}
     (storage/'phase-nine-client-plan.json').write_text(json.dumps(plan));run(['python3','tests/E2E/phase-nine-clients.py','codex'])
    browser('phase-nine');php('phase-nine-control','recover');browser('phase-nine','recover');state.unlink()
    http('phase-nine');php('phase-nine-control','worker');http('phase-nine','--complete');php('phase-nine-control','verify')
   report['status']='passed'
  except Exception as exc:
   report['status']='failed';report['failure']=str(exc)
   logfile=storage/'logs/laravel.log'
   if logfile.exists():
    errors=re.findall(r'local.ERROR: ([^\n]+)',logfile.read_text())
    report['application_errors']=[v.split(' {')[0] for v in errors[-3:]]
   print('FAIL',phase,profile,str(exc),report.get('application_errors',[]),flush=True)
  finally:
   if phase==9 and Path('/tmp/ruweb-phase9-state.json').exists():
    state=json.loads(Path('/tmp/ruweb-phase9-state.json').read_text());
    if state.get('database')==database:Path('/tmp/ruweb-phase9-state.json').unlink()
   if server:
    try:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
    except ProcessLookupError:pass
   target=out/f'phase-{phase}-{profile}';target.mkdir(exist_ok=True)
   for p in storage.glob('*'):
    if (p.name.endswith('-results.json')or p.suffix=='.png')and p.is_file():shutil.copy2(p,target/p.name)
   if (storage/'manifest.json').exists():
    try:php('phase-zero-cleanup');report['cleanup']='guarded schema dropped, process stopped, private copy removed'
    except Exception as exc:report['cleanup_failure']=str(exc)
   if 'cleanup_failure' not in report:shutil.rmtree(root)
   assert hashlib.sha256((source/'.env').read_bytes()).hexdigest()==original_env
   report['original_env_unchanged']=True
   (out/f'phase-{phase}-{profile}-execution.json').write_text(json.dumps(report,indent=2)+'\n');results=[r for r in results if (r['phase'],r['profile'])!=(phase,profile)];results.append({'phase':phase,'profile':profile,'status':report['status'],'cleanup':report.get('cleanup'),'failure':report.get('failure')});(out/'accumulated-summary.json').write_text(json.dumps(results,indent=2)+'\n');print('DONE',phase,profile,report['status'],flush=True)

raise SystemExit(1 if any(r['status']=='failed' for r in results if r['phase'] in phases and r['profile'] in profiles) else 0)
