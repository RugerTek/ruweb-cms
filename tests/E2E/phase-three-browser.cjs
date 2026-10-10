// Actual browser rendering of bearer-created content; this context never logs in.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_PACKAGE);
const storage = process.env.PHASE0_STORAGE;
const profile = process.env.PHASE3_PROFILE;
const stage = process.env.PHASE3_STAGE || 'draft';
const base = 'http://127.0.0.1:8018';
assert(/^(draft|public)$/.test(stage));
const manifest = JSON.parse(fs.readFileSync(path.join(storage, 'manifest.json')));
assert.equal(manifest.database, process.env.PHASE0_DATABASE);
assert(/^ruweb_phase0_[a-f0-9]{12}$/.test(manifest.database));
const fixture = JSON.parse(fs.readFileSync(path.join(storage, 'phase-three-page.json')));
assert.equal(fixture.profile, profile);
assert(!fs.existsSync('public/hot'), 'Isolated copies must use their production Vite build');
(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  const errors = [], imageResponses = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('response', response => {
    if (response.url().includes('/storage/media/')) imageResponses.push({ url: response.url(), status: response.status() });
  });
  await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
  try {
    assert.equal((await context.cookies()).length, 0);
    for (const locale of ['es', 'en']) {
      const record = fixture[locale];
      const publicUrl = new URL(record.url, base).href;
      const response = await page.goto(stage === 'draft' ? fixture.previews[locale] : publicUrl);
      assert.equal(response.status(), 200);
      const heading = locale === 'es' ? 'Equipo actualizado' : 'Acceptance team';
      assert((await page.locator('h1').allTextContents()).some(text => text.includes(heading)));
      if (locale === 'en') {
        assert((await page.content()).includes('Preserved values'));
        assert(!(await page.content()).includes('Valores preservados'));
      }
      const heroImage = page.locator('img[src*="/storage/media/"]').first();
      await heroImage.waitFor({ state: 'visible' });
      await page.waitForFunction(() => [...document.images].filter(image => image.src.includes('/storage/media/')).every(image => image.complete && image.naturalWidth > 0));
      assert.equal(await heroImage.getAttribute('alt'), locale === 'es' ? 'Paisaje de prueba' : 'Acceptance landscape');
      assert(await heroImage.getAttribute('srcset'));
      assert.equal(await heroImage.getAttribute('width'), '1600');
      assert.equal(await heroImage.getAttribute('height'), '900');
      if (stage === 'public') {
        assert.equal(await page.locator('link[rel=canonical]').getAttribute('href'), publicUrl);
        assert.equal(await page.locator('link[rel=alternate][hreflang=es]').getAttribute('href'), new URL(fixture.es.url, base).href);
        assert.equal(await page.locator('link[rel=alternate][hreflang=en]').getAttribute('href'), new URL(fixture.en.url, base).href);
        const menuLink = page.locator('a').filter({ hasText: locale === 'es' ? 'Nuestro equipo' : 'Our team' }).first();
        assert(await menuLink.count());
        assert.equal(new URL(await menuLink.getAttribute('href'), base).href, publicUrl);
        const cta = page.locator('[data-header-cta]');
        assert.equal(await cta.textContent().then(text => text.trim()), 'E2E03 CTA');
        assert.equal(new URL(await cta.getAttribute('href'), base).href, publicUrl);
        if (locale === 'en') {
          assert((await page.content()).includes('All rights reserved.'));
          assert(!(await page.content()).includes('Todos los derechos reservados.'));
        }
      } else {
        const hidden = await context.request.get(publicUrl);
        assert.equal(hidden.status(), 404);
      }
      await page.screenshot({ path: path.join(storage, `phase-three-${profile}-${stage}-${locale}-desktop.png`), fullPage: true });
      await page.setViewportSize({ width: 390, height: 844 });
      await page.reload();
      await page.waitForFunction(() => [...document.images].filter(image => image.src.includes('/storage/media/')).every(image => image.complete && image.naturalWidth > 0));
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
      if (stage === 'public') {
        await page.locator('#mobile-menu-toggle').click();
        const mobileLink = page.locator('#mobile-menu a').filter({ hasText: locale === 'es' ? 'Nuestro equipo' : 'Our team' }).first();
        assert(await mobileLink.isVisible());
        assert.equal(new URL(await mobileLink.getAttribute('href'), base).href, publicUrl);
        const mobileCta = page.locator('#mobile-menu a').filter({ hasText: 'E2E03 CTA' });
        assert(await mobileCta.isVisible());
        assert.equal(new URL(await mobileCta.getAttribute('href'), base).href, publicUrl);
      }
      await page.screenshot({ path: path.join(storage, `phase-three-${profile}-${stage}-${locale}-mobile.png`), fullPage: true });
      await page.setViewportSize({ width: 1440, height: 1000 });
    }
    assert(imageResponses.length && imageResponses.every(response => response.status === 200));
    assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(storage, `phase-three-browser-${stage}-results.json`), JSON.stringify({ profile, stage, checks: ['Anonymous browser rendering ES/EN', 'Decoded responsive image bytes and localized alt', 'Desktop/mobile rendering', stage === 'draft' ? 'Preview visible while public URL stays 404' : 'Canonical/hreflang and localized menu links'], image_statuses: imageResponses.map(({ status }) => status), page_errors: errors }, null, 2));
    console.log('PASS E2E03 browser ' + profile + ' ' + stage);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
