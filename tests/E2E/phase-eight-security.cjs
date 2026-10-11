// Real browser session checks panel/MCP custom code parity.
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
  fs.writeFileSync(path.join(storage,'phase-eight-security-results.json'),JSON.stringify({profile:process.env.PHASE8_PROFILE,checks:['Panel-authored CSS read byte-for-byte through exact MCP version', 'Panel and MCP evaluate identical destination rules and diagnostics']},null,2));
  console.log('PASS E2E08 panel/MCP security parity');
 }finally{await browser.close()}
})().catch(e=>{console.error(e.message);process.exitCode=1});
