"""Collect only sanitized phase-nine results/captures and exact runtime/build/harness hashes."""
import hashlib,json,os,re,shutil
from pathlib import Path
storage=Path(os.environ['PHASE0_STORAGE']);profile=os.environ['PHASE9_PROFILE'];source=Path(os.environ['PHASE9_SOURCE']).resolve();fixture=Path.cwd().resolve()
manifest=json.loads((storage/'manifest.json').read_text())
assert manifest['database']==os.environ['PHASE0_DATABASE'] and re.fullmatch(r'ruweb_phase0_[a-f0-9]{12}',manifest['database'])
assert profile in ['full','essential'] and fixture!=source and not (fixture/'public/hot').exists()
secrets=list(manifest['keys'].values());out=source/'docs/plans/phase-9-evidence'/profile;out.mkdir(parents=True,exist_ok=True)
files=[]
for path in sorted(storage.glob('phase-nine-*-results.json')):
 data=json.loads(path.read_text())
 if data.get('profile')!=profile and not (profile=='full' and 'claude-availability' in path.name):continue
 if data.get('validated') is False:continue
 raw=path.read_text()
 assert all(secret not in raw for secret in secrets if secret),path.name
 assert 'ruweb_mcp_' not in raw and '/preview/' not in raw,path.name
 shutil.copy(path,out/path.name);files.append(path.name)
for path in sorted(storage.glob('phase-nine-'+profile+'-*.png')):
 shutil.copy(path,out/path.name);files.append(path.name)
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
paths=[]
for directory in ['app','bootstrap','config','database','lang','resources','routes','tests/E2E']:
 paths.extend(p for p in (fixture/directory).rglob('*') if p.is_file() and '__pycache__' not in p.parts and not (directory=='bootstrap' and 'cache' in p.parts))
paths.extend(fixture/name for name in ['VERSION','composer.json','composer.lock','package.json','package-lock.json','vite.config.js'] if (fixture/name).is_file())
paths.extend(p for p in (fixture/'public/build').rglob('*') if p.is_file())
hashes={str(p.relative_to(fixture)):sha(p) for p in sorted(paths)}
# Profile is the only permitted difference from the product source for runtime inputs.
differences=[]
for path,digest in hashes.items():
 if path.startswith('public/build/'):continue
 original=source/path
 assert original.is_file(),path
 if sha(original)!=digest:
  assert path=='config/cms.php' and profile=='essential',path
  actual=(fixture/path).read_text();expected=original.read_text().replace("'admin_profile' => 'full'","'admin_profile' => 'essential'")
  assert actual==expected,'Unexpected config difference'
  differences.append({'path':path,'override':'admin_profile=essential'})
result={'profile':profile,'runtime':'isolated MySQL + PHP HTTP workers + local mail log + Chromium','protocol_version':'2024-11-05','product_version':(fixture/'VERSION').read_text().strip(),'source_build_harness_sha256':hashes,'profile_overrides':differences,'evidence_sha256':{name:sha(out/name) for name in files},'excluded':['.env','manifest credentials','identity/recovery codes','cookies','private client configurations/authentication','private server/mail/client logs','preview URLs'],'gate':'pending Claude license authentication'}
(out/'source-build-sha256.json').write_text(json.dumps(result,indent=2)+'\n')
print('PASS sanitized '+profile+' evidence and '+str(len(hashes))+' exact hashes')
