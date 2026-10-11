"""Preserved observer used for early final-product fixtures before the runner captured hashes.
It writes into each active copy's own storage, which that run retains before guarded cleanup.
New runs use the integrated runner fingerprint and do not require this observer.
"""
import pathlib,hashlib,json,time
root=pathlib.Path('/private/var/folders/88/zkqjgk4x2911mq910dsjlzg40000gn/T');source=pathlib.Path('/Users/alemanydev/Developer/ruweb-cms');seen=set();end=time.time()+900
while time.time()<end:
 for app in root.glob('ruweb-phase11-accum-*/app'):
  if app in seen or not(app/'.env').exists():continue
  files={str(p.relative_to(app)):hashlib.sha256(p.read_bytes()).hexdigest() for d in ['app','config','resources','routes','database','lang','public/build']for p in (app/d).rglob('*')if p.is_file()}
  diffs=[]
  for f,h in files.items():
   if (source/f).exists()and hashlib.sha256((source/f).read_bytes()).hexdigest()!=h:diffs.append(f)
  storage=app.parent/'storage';target=storage/'phase-eleven-fingerprint-results.json'
  if storage.exists():target.write_text(json.dumps({'runtime':'8.3.35','captured_at_unix':time.time(),'file_sha256':files,'differences_from_source':diffs},indent=2)+'\n');seen.add(app)
 time.sleep(.25)
