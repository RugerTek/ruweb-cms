"""Read-only final candidate gate plus digests of retained, sanitized evidence."""
import hashlib,json,re
from pathlib import Path
root=Path.cwd().resolve();evidence=root/'docs/plans/phase-11-evidence'
manifest=json.loads((evidence/'candidate-fingerprints.json').read_text())
def digest(p):return hashlib.sha256(p.read_bytes()).hexdigest()
for group in ('product_sha256','harness_sha256','build_sha256'):
 for path,expected in manifest[group].items():
  assert digest(root/path)==expected, 'Drift in '+group+': '+path
summary=json.loads((evidence/'runtime-accumulated/accumulated-summary.json').read_text())
expected={(phase,profile)for phase in (0,2,3,5,6,7,8,9)for profile in ('full','essential')}|{(4,'essential')}
assert {(row['phase'],row['profile'])for row in summary}==expected,'Accumulated coverage incomplete'
assert all(row['status']=='passed'for row in summary),'Unresolved accumulated execution'
provenance={}
for row in summary:
 phase,profile=row['phase'],row['profile'];name=f'phase-{phase}-{profile}'
 execution=json.loads((evidence/'runtime-accumulated'/f'{name}-execution.json').read_text())
 observed=execution.get('fixture_product_sha256')
 method='integrated runner capture before application boot'
 if observed is None:
  inner=evidence/'runtime-accumulated'/name/'phase-eleven-fingerprint-results.json'
  captured=json.loads(inner.read_text());observed=captured['file_sha256']
  method='active private-copy observer capture retained by that execution from its own storage'
 captured_required={path for path in (manifest['product_sha256']|manifest['build_sha256'])if path.split('/')[0]in ('app','config','resources','routes','database','lang')or path.startswith('public/build/')}
 assert captured_required<=set(observed),name+' missing captured product paths'
 assert observed['app/Mcp/ToolArguments.php']==manifest['product_sha256']['app/Mcp/ToolArguments.php'],name+' stale validator'
 assert observed['app/Http/Controllers/McpServerController.php']==manifest['product_sha256']['app/Http/Controllers/McpServerController.php'],name+' stale dispatcher'
 differences=[]
 for path,sha in observed.items():
  if path in manifest['product_sha256']or path in manifest['build_sha256']:
   if sha!=(manifest['product_sha256']|manifest['build_sha256'])[path]:differences.append(path)
 config_text=(root/'config/cms.php').read_text()
 config_text=re.sub(r"'admin_profile' => '(full|essential)'","'admin_profile' => '"+profile+"'",config_text,count=1)
 if phase==2:
  config_text=config_text.replace('return [', '$phase2FixtureConfig = [',1)+"\n$phase2FixtureConfig['templates']['default'] = require dirname(__DIR__).'/tests/Fixtures/admin-profiles/template.php';\nreturn $phase2FixtureConfig;\n"
 assert observed['config/cms.php']==hashlib.sha256(config_text.encode()).hexdigest(),name+' unrecognized profile/fixture configuration'
 allowed={'config/cms.php'}
 if phase==2:allowed|={'resources/views/templates/default.blade.php','lang/es/admin.php','lang/en/admin.php'}
 assert set(differences)<=allowed,name+' unexplained product drift: '+str(differences)
 provenance[name]={'method':method,'captured_files':len(observed),'observed_differences_from_final':differences}

for profile in ('full','essential'):
 for name in ('http-results.json','phase-one-results.json'):
  report=json.loads((evidence/f'runtime-accumulated/phase-0-{profile}'/name).read_text())
  assert report['passed'], 'Missing E2E00/01 core proof'
runtime=json.loads((evidence/'runtime-accumulated/runtime-artifacts.json').read_text())
assert runtime['php_runtime'].startswith('8.3.')and runtime['phpunit_suite']['passed']
assert runtime['composer_lock_sha256']==digest(root/'composer.lock')
assert 'En curso:'not in(root/'docs/plans/phase-11-results.md').read_text()
# Decode every nested JSON tool-content string: raw regex alone misses PHP slash escaping.
private_url=re.compile(r'/(?:draft-preview|preview)/[A-Za-z0-9_-]{20,}')
bearer=re.compile(r'ruweb_mcp_[A-Za-z0-9_-]+')
def scan(value,path):
 if isinstance(value,dict):
  for item in value.values():scan(item,path)
 elif isinstance(value,list):
  for item in value:scan(item,path)
 elif isinstance(value,str):
  assert not bearer.search(value), 'Bearer retained: '+str(path)
  assert not private_url.search(value.replace('\\/','/')), 'Preview authority retained: '+str(path)
  try:nested=json.loads(value)
  except(ValueError,TypeError):return
  if isinstance(nested,(dict,list)):scan(nested,path)
files={}
for p in sorted(evidence.rglob('*')):
 if not p.is_file()or p.name=='evidence-digests.json':continue
 if p.suffix=='.json':scan(json.loads(p.read_text()),p)
 elif p.suffix in('.txt','.log','.md'):scan(p.read_text(),p)
 files[str(p.relative_to(evidence))]=digest(p)
report={'product_files':len(manifest['product_sha256']),'harness_files':len(manifest['harness_sha256']),'build_files':len(manifest['build_sha256']),'accumulated_accepted_executions':len(summary),'php_suite':runtime['phpunit_suite'],'fixture_provenance':provenance,'recursive_authority_scan':'passed','evidence_sha256':files}
(evidence/'evidence-digests.json').write_text(json.dumps(report,indent=2)+'\n')
print('PASS candidate identity, accumulated coverage, runtime, recursive authority scan and evidence digests')
