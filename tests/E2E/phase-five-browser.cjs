// Anonymous real-browser acceptance for E2E05; no admin session or bearer headers.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_PACKAGE);
const storage = process.env.PHASE0_STORAGE;
const profile = process.env.PHASE5_PROFILE;
const stage = process.env.PHASE5_STAGE || 'proposal';
const base = 'http://127.0.0.1:8018';
assert(['proposal', 'restored'].includes(stage));
const manifest = JSON.parse(fs.readFileSync(path.join(storage, 'manifest.json')));
assert.equal(manifest.database, process.env.PHASE0_DATABASE);
assert(/^ruweb_phase0_[a-f0-9]{12}$/.test(manifest.database));
const fixture = JSON.parse(fs.readFileSync(path.join(storage, 'phase-five-fixture.json')));
assert.equal(fixture.profile, profile);
assert(!fs.existsSync('public/hot'));
(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
  try {
    assert.equal((await context.cookies()).length, 0);
    for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
      await page.setViewportSize(viewport);
      const publicResponse = await page.goto(new URL(fixture.page.url, base).href);
      assert.equal(publicResponse.status(), 200);
      assert((await page.content()).includes('E2E05 original public'));
      assert(!(await page.content()).includes('E2E05 exact proposal'));
      const image = page.locator('img[src*="/storage/media/"]').first();
      await image.waitFor({ state: 'visible' });
      await page.waitForFunction(() => [...document.images].filter(img => img.src.includes('/storage/media/')).every(img => img.complete && img.naturalWidth > 0));
      assert(await image.getAttribute('srcset'));
      assert.equal(await page.locator('link[rel=canonical]').getAttribute('href'), new URL(fixture.page.url, base).href);
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
      await page.screenshot({ path: path.join(storage, `phase-five-${profile}-${stage}-public-${viewport.width}.png`), fullPage: true });
      const previewResponse = await page.goto(fixture.preview.url);
      if (stage === 'proposal') {
        assert.equal(previewResponse.status(), 200);
        assert((await page.content()).includes('E2E05 exact proposal'));
        assert(!(await page.content()).includes('E2E05 original public'));
        assert.equal(previewResponse.headers()['x-robots-tag'], 'noindex, nofollow, noarchive');
        assert.equal(previewResponse.headers()['cache-control'], 'no-store, private');
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        await page.screenshot({ path: path.join(storage, `phase-five-${profile}-${stage}-preview-${viewport.width}.png`), fullPage: true });
      } else {
        assert.equal(previewResponse.status(), 404);
        assert(!(await page.content()).includes('E2E05 exact proposal'));
      }
    }
    assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(storage, `phase-five-browser-${stage}-results.json`), JSON.stringify({ profile, stage, anonymous: true, checks: ['Original public content and canonical', 'Desktop and mobile rendering', stage === 'proposal' ? 'Exact pinned proposal preview before approval' : 'Revoked proposal preview inaccessible after draft restore and republish'], page_errors: errors }, null, 2));
    console.log('PASS E2E05 browser ' + profile + ' ' + stage);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
