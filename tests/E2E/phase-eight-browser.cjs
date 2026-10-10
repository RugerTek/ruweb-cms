// E2E08 real panel authoring, public DOM execution and desktop/mobile recovery proof.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_PACKAGE);
const storage=process.env.PHASE0_STORAGE,base=process.env.PHASE8_BASE||'http://127.0.0.1:8018';
const fixture=JSON.parse(fs.readFileSync(path.join(storage,'phase-eight-fixture.json')));
const manifest=JSON.parse(fs.readFileSync(path.join(storage,'manifest.json')));
const stage=process.env.PHASE8_STAGE||fixture.stage;
assert.equal(manifest.database,process.env.PHASE0_DATABASE);assert(/^ruweb_phase0_[a-f0-9]{12}$/.test(manifest.database));assert(!fs.existsSync('public/hot'));assert(['localhost','127.0.0.1'].includes(new URL(base).hostname));
(async()=>{
 const browser=await chromium.launch({headless:true});const context=await browser.newContext();const page=await context.newPage();const errors=[],external=[];
 await context.route('**/*',r=>{if(new URL(r.request().url()).origin===base)return r.continue();external.push(new URL(r.request().url()).origin);return r.abort()});
 page.on('pageerror',e=>errors.push(e.message));
 const login=async()=>{await page.goto(base+'/login');await page.locator('input[name=login]').fill('phase0@example.test');await page.locator('input[name=password]').fill('Phase0-Test-Only-2026!');await page.locator('button[type=submit]').click();await page.waitForURL('**/admin**')};
 const screenshot=async(label,width)=>{
  if(width===390 && await page.locator('#sidebar').count()){
   if(await page.locator('#sidebar').evaluate(el=>!el.classList.contains('-translate-x-full')))await page.locator('#sidebar-toggle').click();
   await page.waitForFunction(()=>document.getElementById('sidebar').getBoundingClientRect().right<=1);
  }
  return page.screenshot({path:path.join(storage,`phase-eight-${fixture.profile}-${stage}-${label}-${width}.png`),fullPage:true});
 };
 const absent=async()=>{assert.equal(await page.locator('[data-custom-code]').count(),0);assert.equal(await page.evaluate(()=>window.__phase8),undefined);assert.equal(await page.evaluate(()=>window.__phase8Pending),undefined)};
 try{
  if(stage==='capture-test'){
   await login();await page.goto(base+'/admin/custom-code/'+fixture.snippets.head.id+'/edit');
   const experiment=page.locator('form[action$="/test-preview"]');
   await experiment.locator('select[name=version_id]').selectOption(String(fixture.snippets.head.active_version_id));
   const item=fixture.pages[1];await experiment.locator('select[name=target_ref]').selectOption(JSON.stringify({type:'pages',id:item.id,locale:item.locale}));
   await experiment.locator('input[name=acknowledgement]').check();await experiment.locator('button').click();await page.waitForLoadState('networkidle');
   assert.deepEqual(await page.frameLocator('iframe').locator('body').evaluate(()=>window.__phase8Sequence),['head']);
   assert.equal(await page.frameLocator('iframe').locator('body').evaluate(()=>{try{window.parent.document.body;return false}catch{return true}}),true);
   await absent();assert.equal(await page.locator('iframe').getAttribute('sandbox'),'allow-scripts');
   for(const width of [1440,390]){await page.setViewportSize({width,height:width===390?844:1000});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await screenshot('explicit-code-test',width)}
  }else if(stage==='capture-panel'){
   await login();await page.goto(base+'/admin/custom-code/'+fixture.panel_css_id+'/edit');
   for(const width of [1440,390]){await page.setViewportSize({width,height:width===390?844:1000});await absent();assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await screenshot('panel-css',width)}
  }else if(stage==='create'){
   await login();await page.goto(base+'/admin/custom-code/create');
   await page.locator('input[name=name]').fill('E2E08 panel CSS');await page.locator('textarea[name=description]').fill('Local CSS created through the actual panel');
   await page.locator('select[name=type]').selectOption('css');await page.locator('select[name=position]').selectOption('head_end');await page.locator('input[name=sort_order]').fill('-10');
   await page.locator('textarea[name=code]').fill(':root{--phase8-css:applied}#phase8-proof{outline:3px solid rgb(14,165,233)}');await page.locator('input[type=checkbox][name=global]').check();
   await page.locator('form').filter({has:page.locator('textarea[name=code]')}).locator('button').click();await page.waitForURL('**/admin/custom-code/*/edit');
   const cssId=Number(page.url().match(/custom-code\/(\d+)\/edit/)[1]);fixture.panel_css_id=cssId;fs.writeFileSync(path.join(storage,'phase-eight-fixture.json'),JSON.stringify(fixture,null,2));fs.chmodSync(path.join(storage,'phase-eight-fixture.json'),0o600);
   const versionForm=page.locator('form[action$="/activate"]').first();await versionForm.locator('..').locator('summary').click();await versionForm.locator('button').click();await page.waitForLoadState('networkidle');
   for(const width of [1440,390]){await page.setViewportSize({width,height:width===390?844:1000});await absent();assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await screenshot('panel-css',width)}
  }else{
   assert.equal((await context.cookies()).length,0);
   const disabled=['killed','harmful-killed'].includes(stage);
   for(const width of [1440,390]){
    await page.setViewportSize({width,height:width===390?844:1000});
    for(let i=0;i<fixture.pages.length;i++){
     const item=fixture.pages[i];assert.equal((await page.goto(new URL(item.url,base).href)).status(),200);
     const scope=stage==='global'?i!==4:i===1;
     const marker=scope&&!disabled&&stage!=='deactivated';
     assert.equal(await page.evaluate(()=>window.__phase8||0),marker?1:0);
     assert.equal(await page.evaluate(()=>window.__phase8Pending),undefined);
     assert.equal(await page.evaluate(()=>getComputedStyle(document.documentElement).getPropertyValue('--phase8-css').trim()),disabled?'':'applied');
     if(scope&&!disabled){assert.deepEqual(await page.evaluate(()=>window.__phase8Sequence),stage==='deactivated'?['head','start','end-b']:['head','start','end-a','end-b']);
      assert.equal(await page.locator('head script[data-custom-code]').count(),1);assert.equal(await page.locator('body > script[data-custom-code]').count(),stage==='deactivated'?2:3);
      assert.equal(await page.locator(`script[data-custom-code="${fixture.snippets.start.id}"]`).evaluate(el=>el.parentElement.tagName),'BODY');
      assert.equal(await page.locator(`script[data-custom-code="${fixture.snippets.head.id}"]`).evaluate(el=>el.parentElement.tagName),'HEAD');
     }
     if(disabled)await absent();
     const harmful=stage==='harmful'&&i===0;
     if(item.template_key!=='empty')assert.equal(await page.locator('header').isVisible(),!harmful);
     assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await screenshot(`${item.locale}-${item.template_key}`,width);
    }
    assert.equal((await page.goto(fixture.preview.url)).status(),200);await absent();await screenshot('preview',width);
    assert.equal((await page.goto(base+'/login')).status(),200);await absent();await screenshot('login',width);
    const oauth=new URL('/authorize',base);oauth.search=new URLSearchParams({response_type:'code',client_id:process.env.PHASE8_OAUTH_CLIENT||'e2e-phase8',redirect_uri:process.env.PHASE8_OAUTH_REDIRECT||base+'/oauth-return',code_challenge:'a'.repeat(43),code_challenge_method:'S256',scope:'custom-code'}).toString();
    assert.equal((await page.goto(oauth.href)).status(),200);assert.equal(await page.locator('input[name=email]').count(),1);await absent();await screenshot('oauth-consent',width);
   }
   await login();await page.goto(base+'/admin/custom-code');await absent();await screenshot('admin',390);
   if(stage==='global'){
    await page.goto(base+'/admin/custom-code/'+fixture.snippets.head.id+'/edit');
    const experiment=page.locator('form[action$="/test-preview"]');
    await experiment.locator('select[name=version_id]').selectOption(String(fixture.snippets.head.active_version_id));
    await experiment.locator('input[name=acknowledgement]').check();
    await experiment.locator('button').click();await page.waitForLoadState('networkidle');
    assert.deepEqual(await page.frameLocator('iframe').locator('body').evaluate(()=>window.__phase8Sequence),['head']);
    assert.equal(await page.frameLocator('iframe').locator('body').evaluate(()=>{try{window.parent.document.body;return false}catch{return true}}),true);
    await absent();
    assert.equal(await page.locator('iframe').getAttribute('sandbox'),'allow-scripts');
    for(const width of [1440,390]){await page.setViewportSize({width,height:width===390?844:1000});await screenshot('explicit-code-test',width)}
   }

  }
  assert.deepEqual(errors,[]);assert(external.every(origin=>origin==='https://fonts.googleapis.com')); 
  fs.writeFileSync(path.join(storage,`phase-eight-browser-${stage}-results.json`),JSON.stringify({profile:fixture.profile,stage,checks:stage==='capture-test'?['Exact approved test executes in the separate opaque-origin frame, parent code remains inert, no horizontal overflow at desktop/mobile widths']:stage==='capture-panel'?['Read-only panel inspected at desktop and mobile widths, closed sidebar and no horizontal overflow']:stage==='create'?['CSS authored and exact version activated through the real panel','Desktop and mobile panel with no executable custom code']:['Six destination/locale documents show exact marker count and CSS state','Ordered head, body-start and body-end insertion in real DOM','Pending version stays inert; excludes and exact language identities prevail','Ordinary preview/login/OAuth consent/admin have no custom code','Desktop/mobile screenshots and no horizontal overflow','Local-only browser requests; no page errors'],blocked_external_assets:external, external_origins:[],page_errors:errors},null,2));
  console.log('PASS E2E08 browser '+fixture.profile+' '+stage);
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
