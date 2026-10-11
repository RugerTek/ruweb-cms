"""Compare same-identity HTTP discovery; profile changes are fixture config only."""
import copy,hashlib,json,os,re,urllib.request
from pathlib import Path
storage=Path(os.environ['PHASE0_STORAGE']);profile=os.environ['PHASE9_PROFILE'];m=json.loads((storage/'manifest.json').read_text())
assert m['database']==os.environ['PHASE0_DATABASE'] and re.fullmatch(r'ruweb_phase0_[a-f0-9]{12}',m['database'])
assert profile in ['full','essential']
def read(path,body=None):
 headers={'Authorization':'Bearer '+m['keys']['full'],'Content-Type':'application/json'}
 with urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:8018'+path,data=json.dumps(body).encode() if body else None,headers=headers)) as r:assert r.status==200;return json.load(r)
cap=read('/mcp/capabilities')['data'];assert cap['admin_profile']==profile
catalog=read('/mcp/rpc',{'jsonrpc':'2.0','id':1,'method':'tools/list'})['result']['tools']
normalized=copy.deepcopy(cap);normalized.pop('admin_profile');normalized['capabilities'].pop('live_editor');normalized['capabilities'].pop('specialized_editor_delivery')
for template in normalized['templates'].values():
 for section in template['sections'].values():
  section.pop('specialized_editor_required');section.pop('visual_editor_available')
snapshot={'profile':profile,'identity':'same fixture administrator/full-scope key','normalized_capabilities':normalized,'catalog':catalog,'allowed_profile_differences':['admin_profile','capabilities.live_editor','capabilities.specialized_editor_delivery','templates.*.sections.*.specialized_editor_required','templates.*.sections.*.visual_editor_available']}
(storage/('phase-nine-parity-'+profile+'.json')).write_text(json.dumps(snapshot,indent=2))
other=storage/('phase-nine-parity-'+('essential' if profile=='full' else 'full')+'.json')
if other.exists():
 previous=json.loads(other.read_text());assert previous['normalized_capabilities']==normalized and previous['catalog']==catalog
 result={'profile':profile,'same_identity':True,'identical_authorization_schemas_rules_and_modules':True,'catalog_count':len(catalog),'identical_catalog':True,'catalog_sha256':hashlib.sha256(json.dumps(catalog,sort_keys=True).encode()).hexdigest(),'allowed_profile_differences':snapshot['allowed_profile_differences']}
 (storage/('phase-nine-parity-'+profile+'-results.json')).write_text(json.dumps(result,indent=2));print('PASS same-identity full/essential discovery and '+str(len(catalog))+' identical tools')
else:print('PASS first same-identity discovery snapshot '+profile)
