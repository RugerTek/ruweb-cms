"""Secret-free release candidate source/harness/asset and active pilot identity manifest."""
import hashlib,json,subprocess
from pathlib import Path
root=Path.cwd().resolve();out=root/'docs/plans/phase-11-evidence'
def digest(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def files(parts):
 result={}
 for part in parts:
  p=root/part
  for file in ([p]if p.is_file()else p.rglob('*')):
   if file.is_file() and '/__pycache__/'not in str(file) and '/bootstrap/cache/'not in str(file):result[str(file.relative_to(root))]=digest(file)
 return dict(sorted(result.items()))
product=files(['app','config','routes','resources','database','lang','bootstrap/app.php','bootstrap/providers.php','composer.json','composer.lock','package.json','package-lock.json','VERSION']);harness=files(['tests/E2E','tests/Feature','tests/JavaScript']);assets=files(['public/build']);state_path=Path('/tmp/ruweb-phase11-state.json');fixtures={}
if state_path.exists():
 state=json.loads(state_path.read_text())
else:
 previous=json.loads((out/'candidate-fingerprints.json').read_text())
 assert previous['product_sha256']==product,'Product changed after pilot cleanup; repeat affected gates'
 assert previous['build_sha256']==assets,'Build changed after pilot cleanup'
 fixtures=previous['pilot_fixtures'];state={'source_ref':previous['baseline_ref'],'apps':{}}
for profile,a in state['apps'].items():
 differences={}
 for path,sha in product.items():
  file=Path(a['copy'])/path
  if not file.is_file()or digest(file)!=sha:differences[path]=digest(file)if file.is_file()else None
 assert set(differences).issubset({'config/cms.php'}),differences
 fixture_assets={p:digest(Path(a['copy'])/p)for p in assets};assert fixture_assets==assets,'Pilot build differs'
 fixtures[profile]={'product_differences':differences,'difference_reason':'Only versioned admin_profile selects essential in its private copy'if differences else None,'assets_identical':True,'real_install':True,'port':a['port']}
result={'baseline_ref':state['source_ref'],'candidate_version':(root/'VERSION').read_text().strip(),'product_sha256':product,'harness_sha256':harness,'build_sha256':assets,'pilot_fixtures':fixtures,'functional_change_since_baseline':['app/Mcp/ToolArguments.php','app/Http/Controllers/McpServerController.php'],'secrets_included':False}
(out/'candidate-fingerprints.json').write_text(json.dumps(result,indent=2)+'\n');print('PASS final source/harness/build identity and pilot copies')
