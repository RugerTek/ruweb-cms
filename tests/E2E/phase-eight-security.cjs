// Real browser session + HTTP rejects conflicting consent/issuance parameters.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_PACKAGE);
const base='http://127.0.0.1:8018',storage=process.env.PHASE0_STORAGE;
const manifest=JSON.parse(fs.readFileSync(path.join(storage,'manifest.json')));
assert.equal(manifest.database,process.env.PHASE0_DATABASE);assert(/^ruweb_phase0_[a-f0-9]{12}$/.test(manifest.database));
(async()=>{
 const browser=await chromium.launch({headless:true});const context=await browser.newContext();
 await context.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());
 try{
  const page=await context.newPage();await page.goto(base+'/login');
  await page.locator('input[name=login]').fill('phase0@example.test');await page.locator('input[name=password]').fill('Phase0-Test-Only-2026!');await page.locator('button[type=submit]').click();await page.waitForURL('**/admin**');
  const token=await page.locator('meta[name="csrf-token"]').getAttribute('content');
  const input={response_type:'code',client_id:'e2e-phase8',redirect_uri:base+'/oauth-return',code_challenge:'a'.repeat(43),code_challenge_method:'S256',scope:'custom-code',state:'phase8-original'};
  for(const [key,value] of Object.entries({scope:'full',client_id:'other-client',redirect_uri:base+'/other-return',code_challenge:'b'.repeat(43),code_challenge_method:'plain',response_type:'token',state:'phase8-changed'})){
   const response=await context.request.post(base+'/authorize?'+new URLSearchParams(input),{form:{...input,[key]:value,email:key==='scope'?'phase0@example.test':'phase8-conflict-'+key+'@example.test',password:'Phase0-Test-Only-2026!',_token:token},headers:{Accept:'application/json','X-Forwarded-For':'127.0.0.'+(Object.keys(input).indexOf(key)+20)}});
   assert.equal(response.status(),400);assert.equal((await response.json()).error,'invalid_request');
  }

  const rpc=async(name,args={})=>{
   const response=await fetch(base+'/mcp/rpc',{method:'POST',headers:{Authorization:'Bearer '+manifest.keys['custom-code'],'Content-Type':'application/json'},body:JSON.stringify({jsonrpc:'2.0',id:1,method:'tools/call',params:{name,arguments:args}})});
   assert.equal(response.status,200);const body=await response.json();assert(!body.error);return JSON.parse(body.result.content[0].text).data;
  };
  const listing=await rpc('custom_code_list');const css=listing.snippets.find(s=>s.name==='E2E08 panel CSS');assert(css);
  const exact=await rpc('custom_code_get',{id:css.id,version_id:css.active_version_id});assert.equal(exact.versions[0].payload.code,':root{--phase8-css:applied}#phase8-proof{outline:3px solid rgb(14,165,233)}');
  await page.goto(base+'/admin/custom-code/'+css.id+'/edit');const evaluation=page.locator('form[action$="/evaluate"]');
  const selected=await evaluation.locator('select[name=target_ref]').inputValue();const version=String(css.active_version_id);
  await evaluation.locator('select[name=version_id]').selectOption(version);await evaluation.locator('button').click();await page.waitForLoadState('networkidle');
  const panel=JSON.parse(await page.locator('pre').filter({hasText:'"target"'}).last().textContent());
  const remote=await rpc('custom_code_evaluate',{target:JSON.parse(selected),version_id:Number(version)});assert.deepEqual(panel,remote);
  fs.writeFileSync(path.join(storage,'phase-eight-security-results.json'),JSON.stringify({profile:process.env.PHASE8_PROFILE,checks:['Panel-authored CSS read byte-for-byte through exact MCP version', 'Panel and MCP evaluate identical destination rules and diagnostics', 'Authenticated real HTTP rejects all seven conflicting query/body OAuth parameters before code issuance, including custom-code consent bypass'],conflicts_rejected:7},null,2));
  console.log('PASS E2E08 real HTTP OAuth conflicting parameters');
 }finally{await browser.close()}
})().catch(e=>{console.error(e.message);process.exitCode=1});
