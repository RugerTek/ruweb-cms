"""Replay the durable E2E10 with independent state, ports and evidence for exact release candidate.
Original phase10 evidence is never overwritten. Bootstrap stays guarded and only its private copy
admits the replay ports. browser/http read the same durable recipes with these bounded substitutions.
"""
import json,os,subprocess,sys,tempfile
from pathlib import Path
root=Path.cwd().resolve();out=root/'docs/plans/phase-11-evidence/accumulated10';out.mkdir(parents=True,exist_ok=True)
def adapted(text):
 return text.replace('/tmp/ruweb-phase10-state.json','/tmp/ruweb-phase11-replay10-state.json').replace('docs/plans/phase-10-evidence','docs/plans/phase-11-evidence/accumulated10').replace('8018','8034').replace('8019','8035')
action=sys.argv[1]
if action in ['browser','http']:
 name='phase-ten-browser.cjs' if action=='browser' else 'phase-ten-http.py';suffix='.cjs'if action=='browser'else'.py'
 with tempfile.NamedTemporaryFile(suffix=suffix,mode='w',delete=False)as f:f.write(adapted((root/'tests/E2E'/name).read_text()));file=Path(f.name)
 try:subprocess.run(['node'if action=='browser'else'python3',str(file),*sys.argv[2:]],check=True)
 finally:file.unlink()
else:
 code=adapted((root/'tests/E2E/phase-ten-run.py').read_text())
 before="def control(app,action,*args):return run(['php','tests/E2E/phase-ten-control.php',action,*args],app)"
 after="""def control(app,action,*args):
 p=Path(app['copy'])/'tests/E2E/phase-ten-bootstrap.php'
 p.write_text(p.read_text().replace("['8018', '8019']", "['8034', '8035']"))
 return run(['php','tests/E2E/phase-ten-control.php',action,*args],app)"""
 # Adapted text does not change the referenced source bootstrap; patch only the fixture file.
 assert before in code;code=code.replace(before,after)
 exec(compile(code,str(root/'tests/E2E/phase-ten-run.py'),'exec'),{'__name__':'__main__'})
