const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_PACKAGE);
const storage = process.env.PHASE0_STORAGE;
const stage = process.env.PHASE4_STAGE || 'identity';
const base = 'http://127.0.0.1:8018';
const manifestPath = path.join(storage, 'manifest.json');
const manifest = JSON.parse(fs.readFileSync(manifestPath));
assert.equal(manifest.database, process.env.PHASE0_DATABASE);
assert(/^ruweb_phase0_[a-f0-9]{12}$/.test(manifest.database));
assert(!fs.existsSync('public/hot'));
(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  const scripts = new Set(), errors = [], checks = [], network = [];
  page.on('response', response => { const url = new URL(response.url()); network.push({ path: url.pathname, status: response.status(), method: response.request().method() }); });
  page.on('request', request => { const url = new URL(request.url()); if (url.pathname.endsWith('.js')) scripts.add(url.pathname); });
  page.on('pageerror', error => errors.push(error.message));
  await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
  async function login(email, password) {
    await page.goto(base + '/login');
    await page.locator('[name=login]').fill(email);
    await page.locator('[name=password]').fill(password);
    await Promise.all([page.waitForURL('**/admin'), page.locator('button[type=submit]').click()]);
  }
  async function capture(name) {
    await page.screenshot({ path: path.join(storage, name + '-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    // Let responsive navigation finish its real transition before capturing.
    await page.waitForTimeout(400);
    const fits = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
    await page.screenshot({ path: path.join(storage, name + '-mobile.png'), fullPage: true });
    await page.setViewportSize({ width: 1440, height: 1000 });
    assert(fits, name + ' mobile overflow');
  }
  try {
    await login('phase4-admin@example.test', 'Phase4-Admin-Only-2026!');
    if (stage === 'identity') {
      await page.goto(base + '/admin/users');
      if (!(await page.content()).includes('Phase Four Operator')) {
      await page.goto(base + '/admin/users/create');
      for (const [name, value] of Object.entries({ name: 'Phase Four Operator', username: 'phase4operator', email: 'phase4-operator@example.test', password: 'Phase4-Operator-Only-2026!', password_confirmation: 'Phase4-Operator-Only-2026!' })) await page.locator('[name=' + name + ']').fill(value);
      await page.locator('[name=role]').selectOption('editor');
      await Promise.all([page.waitForURL('**/admin/users/*/edit'), page.locator('form[action$="/users"] button[type=submit]').click()]);
      assert((await page.content()).includes('Phase Four Operator'));
      }
      checks.push('First installed administrator logs in and creates an editor through UI');
      await context.clearCookies();
      await login('phase4-operator@example.test', 'Phase4-Operator-Only-2026!');
      await page.goto(base + '/admin/claude-mcp');
      await page.locator('#key-name').fill('Phase Four CLI');
      await page.locator('#key-scope').selectOption('draft-only');
      await Promise.all([page.waitForNavigation(), page.locator('form[action$="/api-key"] button[type=submit]').click()]);
      const key = (await page.locator('[role=status] code').textContent()).trim();
      assert(key.startsWith('ruweb_mcp_'));
      manifest.operator_key = key;
      fs.writeFileSync(manifestPath, JSON.stringify(manifest), { mode: 0o600 });
      checks.push('Editor logs in and issues own draft-only credential through UI');
      // Do not capture credential screens or record traces containing bearer/preview tokens.
    } else if (stage === 'essential-bar') {
      const response = await page.goto(base + '/'); assert.equal(response.status(), 200);
      const bar = page.locator('#ruweb-admin-bar'); assert(await bar.count());
      assert.equal(await bar.locator('a[href*="/admin/menus"], a[href*="/admin/header"], a[href*="/admin/footer"], a[href$="/edit"]').count(), 0);
      await capture('phase-four-essential-public-bar');
      checks.push('Authenticated public bar excludes unavailable editorial links in essential');
    } else {
      const fixture = JSON.parse(fs.readFileSync(path.join(storage, 'phase-four-page.json')));
      if (stage === 'essential') {
        for (const route of ['/admin', '/admin/profile', '/admin/users', '/admin/claude-mcp', '/admin/editorial-control', '/admin/submissions/contact', '/admin/posts']) { const response = await page.goto(base + route); assert.equal(response.status(), 200, route); }
        checks.push('Preserved operational screens and integration configuration load');
        await page.goto(base + '/admin/pages?search=Phase%20Four');
        assert((await page.content()).includes(fixture.title));
        assert.equal(await page.locator('[data-content-list]').count(), 1);
        assert.equal(await page.locator('form[method=POST]').filter({ has: page.locator('textarea[name*=content]') }).count(), 0);
        await capture('phase-four-essential-pages');
        const row = page.locator('tr').filter({ hasText: fixture.title });
        const previewLink = row.locator('a[href*="preview"]').first();
        assert(await previewLink.count(), 'Operational listing must expose preview');
        await page.goto(new URL(await previewLink.getAttribute('href'), base).href);
        assert((await page.content()).includes('Phase Four agent heading'));
        assert.equal((await context.request.get(base + '/' + fixture.slug)).status(), 404);
        checks.push('Agent draft appears in listing and authenticated preview; anonymous public URL is 404');
        await page.goto(base + '/admin/editorial/pages/' + fixture.id);
        assert.equal(await page.locator('[data-content-status]').count(), 1);
        const backLink = page.locator('[data-content-status] nav a').first();
        assert.equal((await backLink.textContent()).trim(), 'Volver al listado');
        assert.equal(new URL(await backLink.getAttribute('href'), base).pathname, '/admin/content/pages');
        assert.equal(await page.locator('[data-content-status] form').count(), 0);
        await capture('phase-four-essential-status');
        await page.goto(base + '/admin/media?search=placeholder');
        assert(await page.locator('[data-filename]').count());
        await capture('phase-four-essential-media');
        await page.goto(base + '/admin/settings');
        const title = page.locator('[name="settings[site_name]"]');
        await title.fill('Phase Four Browser Site');
        await Promise.all([page.waitForNavigation(), page.locator('form[action$="/settings"] button[type=submit]').last().click()]);
        assert.equal(await page.locator('[name="settings[site_name]"]').inputValue(), 'Phase Four Browser Site');
        assert((await (await context.request.get(base + '/')).text()).includes('Phase Four Browser Site'));
        await capture('phase-four-essential-settings');
        checks.push('Real media search and canonical setting edit affect public output');
        const direct = await page.goto(base + '/admin/pages/' + fixture.id + '/edit');
        assert.equal(direct.status(), 404);
        assert.equal(await page.evaluate(() => typeof window.initEditorEngine), 'undefined');
        const build = JSON.parse(fs.readFileSync('public/build/manifest.json'));
        assert(scripts.has('/build/' + build['resources/js/admin-essential.js'].file));
        assert(!scripts.has('/build/' + build['resources/js/admin.js'].file));
        assert(![...scripts].some(url => /rich-text|tinymce|admin-editor-runtime|editor-engine|editor-recovery/.test(url)));
        checks.push('Direct editor URL returns 404; no editor runtime or TinyMCE downloaded');
      } else {
        await page.goto(base + '/admin/pages/' + fixture.id + '/edit');
        await page.waitForFunction(() => typeof window.initEditorEngine === 'function' && window.editorFeedback); console.log('Full editor initialized');
        assert.equal(await page.locator('#title').inputValue(), fixture.title);
        await page.locator('[data-tab=content]').click();
        await page.locator('[data-accordion-toggle][aria-controls=section-body-content]').click();
        const heading = page.locator('[name="content_json[sections][content][heading]"]');
        assert.equal(await heading.inputValue(), 'Phase Four agent heading');
        await page.locator('#section-body-content [data-title-visual]').fill('Phase Four visual heading'); console.log('Full heading changed');
        const saved = page.waitForResponse(response => response.url() === base + '/admin/pages/' + fixture.id && response.request().method() === 'POST' && response.status() === 200);
        await page.locator('#save-btn').click(); await saved; console.log('Full saved');
        await page.reload();
        assert.equal(await heading.inputValue(), 'Phase Four visual heading');
        await capture('phase-four-full-editor');
        checks.push('Profile switch retains MCP content and restores compatible visual edit/save');
      }
    }
    assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(storage, 'phase-four-browser-' + stage + '-results.json'), JSON.stringify({ stage, checks, script_requests: [...scripts], page_errors: errors, network_trace: network }, null, 2));
    console.log('PASS E2E04 browser ' + stage);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
