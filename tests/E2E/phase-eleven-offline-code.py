"""Operational custom-code kill switch must work with an unreachable database, both profiles."""
import base64,json,os,secrets,shutil,subprocess,tempfile
from pathlib import Path
source=Path.cwd().resolve();out=source/'docs/plans/phase-11-evidence/offline-code.json';reports=[]
for profile in ['full','essential']:
 root=Path(tempfile.mkdtemp(prefix='ruweb-phase11-offline-'));root.chmod(0o700)
 try:
  subprocess.run(['rsync','-a','--exclude=.git','--exclude=.env','--exclude=node_modules','--exclude=vendor','--exclude=storage','--exclude=public/storage','--exclude=public/hot','--exclude=bootstrap/cache/*.php','--exclude=docs/plans/*-evidence',str(source)+'/',str(root)+'/'],check=True)
  (root/'vendor').symlink_to(source/'vendor');storage=root/'private-storage'
  for part in ['app/private','framework/cache/data','framework/sessions','framework/views','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
  p=root/'config/cms.php';p.write_text(p.read_text().replace("'admin_profile' => 'full'","'admin_profile' => '"+profile+"'",1))
  env=os.environ|{'APP_ENV':'local','APP_KEY':'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),'DB_CONNECTION':'mysql','DB_HOST':'127.0.0.1','DB_PORT':'1','DB_DATABASE':'ruweb_phase0_'+secrets.token_hex(6),'DB_USERNAME':'fixture','DB_PASSWORD':'fixture','LARAVEL_STORAGE_PATH':str(storage),'APP_CONFIG_CACHE':str(storage/'config.php'),'CACHE_STORE':'file'}
  checks=[]
  for action in ['disable','status','enable','status']:
   r=subprocess.run(['php','artisan','cms:custom-code',action],cwd=root,env=env,capture_output=True,text=True,timeout=15)
   assert r.returncode==0,r.stderr[-300:];checks.append({'action':action,'exit_code':0,'output':r.stdout.strip()})
  assert 'disabled'in checks[1]['output'].lower() and 'enabled'in checks[3]['output'].lower()
  reports.append({'profile':profile,'database_unreachable':'127.0.0.1:1','checks':checks,'cleanup':True})
 finally:shutil.rmtree(root)
out.write_text(json.dumps(reports,indent=2)+'\n');print('PASS Custom Code operational recovery without database in both profiles')
