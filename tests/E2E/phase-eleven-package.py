"""Local deterministic candidate artifact from verified product/build; no secrets or deployment."""
import hashlib,io,json,os,tarfile,tempfile
from pathlib import Path
root=Path.cwd().resolve();proof=root/'docs/plans/phase-11-evidence/candidate-fingerprints.json';m=json.loads(proof.read_text())
for path,sha in m['product_sha256'].items():assert hashlib.sha256((root/path).read_bytes()).hexdigest()==sha,'Candidate source drift: '+path
for path,sha in m['build_sha256'].items():assert hashlib.sha256((root/path).read_bytes()).hexdigest()==sha,'Candidate build drift: '+path
mapping=m['product_sha256']|m['build_sha256'];identity=hashlib.sha256(json.dumps(mapping,sort_keys=True,separators=(',',':')).encode()).hexdigest();destination=Path(tempfile.gettempdir())/('ruweb-cms-candidate-'+m['candidate_version']+'-'+identity[:12]+'.tar')
# Track source, contracts and durable tests; exclude ignored credentials, snapshots and all evidence bundles.
paths=set(mapping)
for directory in ['docs','.agents/skills','tests/E2E','tests/Feature','tests/JavaScript','tests/Fixtures']:
 for p in (root/directory).rglob('*'):
  if p.is_file() and not p.is_symlink() and '-evidence/'not in str(p) and '__pycache__'not in str(p):paths.add(str(p.relative_to(root)))
for p in ['artisan','AGENTS.md','README.md','CHANGELOG.md','.env.example','vite.config.js','phpunit.xml','phpunit.xml.dist']:
 if (root/p).is_file():paths.add(p)
paths.update(str(p.relative_to(root))for p in (root/'public').rglob('*')if p.is_file() and not p.is_symlink() and p.name!='hot' and '/storage/'not in str(p))
with tarfile.open(destination,'w',format=tarfile.PAX_FORMAT)as archive:
 for path in sorted(paths):
  p=root/path;data=p.read_bytes();info=tarfile.TarInfo('ruweb-cms/'+path);info.size=len(data);info.mtime=0;info.mode=0o755 if os.access(p,os.X_OK)else 0o644;info.uid=info.gid=0;info.uname=info.gname='';archive.addfile(info,io.BytesIO(data))
report={'candidate_version':m['candidate_version'],'baseline_ref':m['baseline_ref'],'product_and_build_identity_sha256':identity,'local_artifact_path':str(destination),'artifact_sha256':hashlib.sha256(destination.read_bytes()).hexdigest(),'files':len(paths),'dependencies':'Install from included composer/package lockfiles; vendor/node_modules are not included','normalized_tar_headers':True,'includes_environment_example_only':True,'production_credentials_or_snapshots_included':False,'published_remotely':False}
(root/'docs/plans/phase-11-evidence/local-artifact.json').write_text(json.dumps(report,indent=2)+'\n');print('PASS local candidate artifact prepared; immutable identity '+identity[:12])
