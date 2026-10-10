// Browser verification uses a caller-supplied Playwright package, no installed production dependency.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_PACKAGE);
const storage = process.env.PHASE0_STORAGE;
const profile = process.env.PHASE2_PROFILE;
const manifest = JSON.parse(fs.readFileSync(path.join(storage, 'manifest.json')));
assert.equal(manifest.database, process.env.PHASE0_DATABASE);
const fixture = JSON.parse(fs.readFileSync(path.join(storage, 'phase-two-page.json')));
assert(!fs.existsSync('public/hot'), 'Disposable copies must not inherit a development Vite server');
const build = JSON.parse(fs.readFileSync('public/build/manifest.json'));
const fullAsset = '/build/' + build['resources/js/admin.js'].file;
const essentialAsset = '/build/' + build['resources/js/admin-essential.js'].file;
(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  const scripts = new Set(), errors = [];
  page.on('request', request => { if (new URL(request.url()).pathname.endsWith('.js')) scripts.add(new URL(request.url()).pathname); });
  page.on('pageerror', error => errors.push(error.message));
  await page.route('**/*', route => new URL(route.request().url()).origin === 'http://127.0.0.1:8018' ? route.continue() : route.abort());
  const base = 'http://127.0.0.1:8018';
  try {
    await page.goto(base + '/login');
    await page.locator('[name=login]').fill('phase0@example.test');
    await page.locator('[name=password]').fill('Phase0-Test-Only-2026!');
    await Promise.all([page.waitForURL('**/admin'), page.locator('button[type=submit]').click()]);
    if (profile === 'full') {
      await page.goto(base + '/admin/pages/' + fixture.id + '/edit');
      await page.waitForFunction(() => typeof window.initEditorEngine === 'function' && window.editorFeedback);
      assert(scripts.has(fullAsset), JSON.stringify([...scripts]));
      assert(!scripts.has(essentialAsset));
      const section = page.locator('[data-accordion-toggle][aria-controls=section-body-testimonials]');
      await page.locator('[data-tab=content]').click();
      await section.click();
      const repeater = page.locator('#section-body-testimonials [data-repeater]');
      await repeater.locator('[data-repeater-add]').click();
      assert.equal(await repeater.locator('[data-repeater-item]').count(), 2);
      // The full runtime recovery listener must still rebuild repeaters synchronously.
      await repeater.evaluate(node => node.dispatchEvent(new CustomEvent('editor:recover-rows', { detail: { entries: [['content_json[sections][testimonials][items][0][author]', 'Ana Torres']] } })));
      assert.equal(await repeater.locator('[data-repeater-item]').count(), 1);
      // Restore the original persisted content before exercising normal add/reorder/delete/save.
      await page.reload();
      await page.waitForFunction(() => typeof window.initEditorEngine === 'function' && window.editorFeedback);
      await page.locator('[data-tab=content]').click();
      await section.click();
      await repeater.locator('[data-repeater-add]').click();
      const rows = repeater.locator('[data-repeater-item]');
      const second = rows.nth(1);
      if (!await second.locator('[name$="[author]"]').isVisible()) await second.locator('[data-repeater-toggle]').click();
      await second.locator('[name$="[author]"]').fill('Segundo temporal');
      await second.locator('[name$="[quote]"]').fill('Temporal para orden');
      await second.locator('[data-repeater-move-up]').click();
      assert.equal(await rows.nth(0).locator('[name$="[author]"]').inputValue(), 'Segundo temporal');
      await rows.nth(0).locator('[data-repeater-remove]').click();
      await page.locator('#confirm-modal-accept').click();
      assert.equal(await rows.count(), 1);
      const first = rows.nth(0);
      if (!await first.locator('[name$="[quote]"]').isVisible()) await first.locator('[data-repeater-toggle]').click();
      await first.locator('[name$="[quote]"]').fill('El equipo cumplió cada detalle. Navegador verificado.');
      const saved = page.waitForResponse(response => response.url() === base + '/admin/pages/' + fixture.id && response.request().method() === 'POST' && response.status() === 200);
      await page.locator('#save-btn').click();
      await saved;
      await page.waitForFunction(() => document.getElementById('save-btn').disabled);
      const publicPage = await browser.newPage();
      await publicPage.goto(base + '/phase2-testimonials');
      assert((await publicPage.content()).includes('Navegador verificado.'));
      await page.screenshot({ path: path.join(storage, 'full-editor-desktop.png'), fullPage: false });
      await publicPage.setViewportSize({ width: 390, height: 844 });
      await publicPage.screenshot({ path: path.join(storage, 'full-public-mobile.png'), fullPage: true });
      await publicPage.close();
    } else {
      await page.goto(base + '/admin/pages');
      assert.equal(await page.locator('a[href$="/create"]').filter({ hasText: /página/i }).count(), 0);
      assert.equal(await page.evaluate(() => typeof window.initEditorEngine), 'undefined');
      assert(scripts.has(essentialAsset), JSON.stringify([...scripts]));
      assert(!scripts.has(fullAsset));
      assert(![...scripts].some(url => /rich-text|tinymce|admin-editor-runtime|editor-engine|editor-recovery/.test(url)));
      // General media upload controls remain active in essential.
      await page.goto(base + '/admin/media');
      await page.locator('#upload-btn').click();
      assert(await page.locator('#upload-modal').isVisible());
      await page.screenshot({ path: path.join(storage, 'essential-media-desktop.png'), fullPage: false });
      await page.goto(base + '/admin/pages/' + fixture.id + '/edit');
      assert((await page.content()).includes('404'));
    }
    assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(storage, 'phase-two-browser-results.json'), JSON.stringify({ profile, checks: profile === 'full' ? ['Synchronous editor initialization', 'Recovery rebuild', 'Add/reorder/confirmed delete', 'Browser form save and public output', 'Desktop/mobile captures'] : ['Essential entrypoint network', 'No editor/TinyMCE requests', 'Operational media upload modal', 'Editor direct URL denied'], script_requests: [...scripts], page_errors: errors }, null, 2));
    console.log('PASS browser ' + profile);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
