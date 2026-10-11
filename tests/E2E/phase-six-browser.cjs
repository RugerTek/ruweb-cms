// Real browser form submission and admin parity, restricted to guarded loopback fixture.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_PACKAGE);
const storage = process.env.PHASE0_STORAGE;
const profile = process.env.PHASE6_PROFILE;
const stage = process.env.PHASE6_STAGE || 'submit';
const base = 'http://127.0.0.1:8018';
const manifest = JSON.parse(fs.readFileSync(path.join(storage, 'manifest.json')));
assert.equal(manifest.database, process.env.PHASE0_DATABASE);
assert(/^ruweb_phase0_[a-f0-9]{12}$/.test(manifest.database));
assert(['full', 'essential'].includes(profile));
assert(['submit', 'tracked', 'failed', 'sent'].includes(stage));
assert(!fs.existsSync('public/hot'));
(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
  try {
    if (stage === 'submit') {
      assert.equal((await page.goto(base + '/contacto')).status(), 200);
      const form = page.locator('form[action$="/forms/contact"]');
      assert(await form.locator('[name=_token]').inputValue());
      assert(await form.locator('[name=_form_token]').inputValue());
      await form.locator('[name=name]').fill('E2E06 Browser Visitor');
      await form.locator('[name=email]').fill('e2e06@example.test');
      await form.locator('[name=message]').fill('E2E06 delivery body');
      // Google's network verification is stubbed in the guarded router only. Supply its opaque input.
      await form.locator('[name=recaptcha_token]').evaluate(input => { input.value = 'e2e06-google-token'; });
      await page.waitForTimeout(4100);
      const responsePromise = page.waitForResponse(response => response.url() === base + '/forms/contact' && response.request().method() === 'POST');
      await form.locator('[type=submit]').click();
      const response = await responsePromise;
      assert.equal(response.status(), 302);
      await page.waitForLoadState('networkidle');
      assert((await page.content()).includes('Recibimos tu mensaje'));
      await page.screenshot({ path: path.join(storage, `phase-six-${profile}-visitor.png`), fullPage: true });
    }
    await page.goto(base + '/login');
    await page.locator('[name=login]').fill('phase0@example.test');
    await page.locator('[name=password]').fill('Phase0-Test-Only-2026!');
    await Promise.all([page.waitForURL('**/admin'), page.locator('button[type=submit]').click()]);
    await page.goto(base + '/admin/submissions/contact');
    const row = page.locator('tr').filter({ hasText: 'E2E06 Browser Visitor' });
    assert.equal(await row.count(), 1);
    await row.locator('a[href*="/admin/submissions/contact/"]').first().click();
    await page.waitForURL('**/admin/submissions/contact/*');
    await page.waitForLoadState('networkidle');
    assert((await page.content()).includes('E2E06 delivery body'));
    if (stage === 'submit') {
      const id = Number(new URL(page.url()).pathname.split('/').pop());
      assert(id > 0);
      fs.writeFileSync(path.join(storage, 'phase-six-fixture.json'), JSON.stringify({ profile, id }, null, 2), { mode: 0o600 });
    } else {
      const fixture = JSON.parse(fs.readFileSync(path.join(storage, 'phase-six-fixture.json')));
      assert.equal(Number(new URL(page.url()).pathname.split('/').pop()), fixture.id);
      assert.equal(await page.locator('[name=assigned_to]').inputValue(), String(manifest.admin_id));
      assert.equal(await page.locator('[name=workflow_status]').inputValue(), stage === 'sent' ? 'resolved' : 'in_progress');
      assert((await page.content()).includes('E2E06 MCP follow-up note'));
      if (stage === 'failed') {
        assert((await page.content()).includes('Fallida'));
        assert((await page.locator('[role=alert]').allTextContents()).join(' ').length > 0);
      }
      if (stage === 'sent') assert((await page.content()).includes('Enviada'));
    }
    for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
      await page.setViewportSize(viewport);
      if (viewport.width < 1024) {
        // Let responsive sidebar transition finish before inspecting whether it needs closing.
        await page.waitForTimeout(500);
        const sidebar = page.locator('#sidebar');
        if ((await sidebar.boundingBox()).x + (await sidebar.boundingBox()).width > 1) {
          const backdrop = page.locator('#sidebar-backdrop');
          if (await backdrop.isVisible()) await backdrop.click({ position: { x: 350, y: 200 } });
          else await page.locator('#sidebar-toggle').click();
        }
        await page.waitForFunction(() => document.querySelector('#sidebar').getBoundingClientRect().right <= 1);
      }
      for (const selector of ['[name=workflow_status]', '[name=assigned_to]', '#submission-note']) {
        const control = page.locator(selector);
        await control.scrollIntoViewIfNeeded();
        assert(await control.isVisible());
        assert(await control.evaluate(element => {
          const rect = element.getBoundingClientRect();
          const hit = document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2);
          return hit === element || element.contains(hit);
        }), 'Workflow/note control covered at ' + viewport.width + ': ' + selector);
      }
      await page.evaluate(() => scrollTo(0, 0));
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
      await page.screenshot({ path: path.join(storage, `phase-six-${profile}-${stage}-admin-${viewport.width}.png`), fullPage: true });
    }
    assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(storage, `phase-six-browser-${stage}-results.json`), JSON.stringify({ profile, stage, checks: ['Real browser visitor CSRF/signed token/elapsed fill/recaptcha submission', 'Same persisted submission visible to authenticated admin', 'Desktop/mobile admin rendering', ...(stage === 'submit' ? [] : ['MCP assignment, workflow and note reflected in admin'])], page_errors: errors }, null, 2));
    console.log('PASS E2E06 browser ' + profile + ' ' + stage);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
